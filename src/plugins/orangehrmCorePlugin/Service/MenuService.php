<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA  02110-1301, USA
 */

namespace OrangeHRM\Core\Service;

use LogicException;
use OrangeHRM\Core\Authorization\Service\ScreenPermissionService;
use OrangeHRM\Core\Dao\MenuDao;
use OrangeHRM\Core\Dto\ModuleScreen;
use OrangeHRM\Core\Helper\ModuleScreenHelper;
use OrangeHRM\Core\Menu\DetailedMenuItem;
use OrangeHRM\Core\Menu\MenuConfigurator;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ModuleScreenHelperTrait;
use OrangeHRM\Core\Traits\UserRoleManagerTrait;
use OrangeHRM\Entity\MenuItem;
use OrangeHRM\Entity\Screen;
use OrangeHRM\I18N\Traits\Service\I18NHelperTrait;

class MenuService
{
    use UserRoleManagerTrait;
    use ModuleScreenHelperTrait;
    use AuthUserTrait;
    use I18NHelperTrait;

    // Versi dasar cache menu (dipakai kalau file versi belum ada).
    public const CORE_MENU_CACHE_VERSION = 'v2';

    /**
     * Versi cache menu yang dipakai saat ini. Dibaca dari file
     * src/cache/orangehrm/.menu_version supaya bisa diubah saat runtime:
     * setiap kali menu / hak akses role disimpan, versi dinaikkan sehingga
     * semua sesi login lama membangun ulang menu tanpa perlu logout.
     *
     * @var string|null
     */
    private static ?string $menuCacheVersion = null;

    public function getSidePanelCacheKey(): string
    {
        return 'core.menu.side_panel.' . $this->getMenuCacheVersion();
    }

    public function getTopRibbonKeysCacheKey(): string
    {
        return 'core.menu.top_ribbon_keys.' . $this->getMenuCacheVersion();
    }

    public function getMenuCacheVersion(): string
    {
        if (self::$menuCacheVersion === null) {
            $file = dirname(__DIR__, 4) . '/src/cache/orangehrm/.menu_version';
            $version = is_file($file) ? trim((string)@file_get_contents($file)) : '';
            self::$menuCacheVersion = $version !== '' ? $version : self::CORE_MENU_CACHE_VERSION;
        }

        return self::$menuCacheVersion;
    }

    /**
     * Naikkan versi cache menu (dipanggil setelah menu / hak akses role berubah).
     */
    public static function bumpMenuCacheVersion(): string
    {
        $dir = dirname(__DIR__, 4) . '/src/cache/orangehrm';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $version = (string)time();
        @file_put_contents($dir . '/.menu_version', $version);
        self::$menuCacheVersion = $version;

        return $version;
    }

    /**
     * Fork ASG — menu samping KHUSUS per role.
     *
     * Menu di tabel ohrm_menu_item bersifat global (satu susunan untuk semua role),
     * jadi kebutuhan "untuk ESS hanya Dashboard dan Performance" tidak bisa diatur
     * lewat data. Di sini role tertentu punya daftar putih (whitelist) judul menu:
     * menu yang tidak terdaftar DISEMBUNYIKAN dari sidebar, dan urutannya mengikuti
     * urutan di daftar. Role lain tidak terpengaruh.
     *
     * Permintaan Pak (2026-11-13): role ESS → hanya Dashboard lalu Performance.
     */
    private const ROLE_SIDE_PANEL_MENU = [
        'ESS' => ['Dashboard', 'Performance'],
    ];

    /**
     * Tab (top ribbon) yang DISEMBUNYIKAN untuk role tertentu.
     *
     * Format: role => [judul menu induk (level 1) => [judul tab yang disembunyikan]].
     * Menu induk dipakai sebagai pembatas supaya judul tab yang sama di modul lain
     * (mis. "Configure" di PIM) tidak ikut hilang.
     *
     * Permintaan Pak (2026-11-13): role ESS di modul Performance cukup melihat tab
     * Input Realisasi KPI (+ Approval); Configure, Manage Reviews, My Trackers dan
     * Employee Trackers disembunyikan. Permintaan berikutnya: role BOD diperlakukan
     * sama di modul yang sama.
     */
    private const ROLE_HIDDEN_TOP_MENU = [
        'ESS' => [
            'Performance' => ['Configure', 'Manage Reviews', 'My Trackers', 'Employee Trackers'],
        ],
        'BOD' => [
            'Performance' => ['Configure', 'Manage Reviews', 'My Trackers', 'Employee Trackers'],
        ],
    ];

    /**
     * Saring tab (top ribbon) sesuai role user yang login.
     *
     * @param DetailedMenuItem[] $topMenuItems
     * @return DetailedMenuItem[]
     */
    private function applyRoleHiddenTopMenu(array $topMenuItems, int $sidePanelMenuItemId): array
    {
        $roleName = strtoupper((string) $this->getAuthUser()->getUserRoleName());
        $hiddenByParent = self::ROLE_HIDDEN_TOP_MENU[$roleName] ?? [];
        if (empty($hiddenByParent)) {
            return $topMenuItems;
        }

        foreach ($hiddenByParent as $parentTitle => $hiddenTitles) {
            $parent = $this->getMenuDao()->getMenuItemByTitle($parentTitle, 1);
            if (!$parent instanceof MenuItem || $parent->getId() !== $sidePanelMenuItemId) {
                continue;
            }
            $filtered = array_values(array_filter(
                $topMenuItems,
                fn (DetailedMenuItem $item) => !in_array($item->getMenuTitle(), $hiddenTitles, true)
            ));
            // Pengaman: kalau semua tab tersaring (salah tulis judul), kembalikan asli.
            return empty($filtered) ? $topMenuItems : $filtered;
        }

        return $topMenuItems;
    }

    /**
     * Susun ulang menu samping sesuai prioritas role user yang login.
     *
     * @param DetailedMenuItem[] $items
     * @return DetailedMenuItem[]
     */
    private function applyRoleSidePanelPriority(array $items): array
    {
        // PENTING: pakai ROLE UTAMA user (session `user.user_role_name`), bukan
        // getUserRolesForAuthUser() — fungsi itu juga mengembalikan role ESS untuk
        // Admin/Supervisor, sehingga urutan menu Admin ikut berubah.
        $roleName = strtoupper((string) $this->getAuthUser()->getUserRoleName());
        $allowedTitles = self::ROLE_SIDE_PANEL_MENU[$roleName] ?? [];
        if (empty($allowedTitles)) {
            return $items;
        }

        $allowedItems = [];
        foreach ($items as $item) {
            $position = array_search($item->getMenuTitle(), $allowedTitles, true);
            if ($position !== false) {
                $allowedItems[$position] = $item;
            }
        }

        // Jangan sampai sidebar kosong gara-gara salah tulis judul menu:
        // kalau tidak ada satu pun yang cocok, kembalikan menu asli.
        if (empty($allowedItems)) {
            return $items;
        }

        ksort($allowedItems);

        return array_values($allowedItems);
    }

    /**
     * @var MenuDao|null
     */
    protected ?MenuDao $menuDao = null;
    /**
     * @var ScreenPermissionService|null
     */
    protected ?ScreenPermissionService $screenPermissionService = null;

    /**
     * @return MenuDao
     */
    public function getMenuDao(): MenuDao
    {
        if (is_null($this->menuDao)) {
            $this->menuDao = new MenuDao();
        }

        return $this->menuDao;
    }

    /**
     * @return ScreenPermissionService
     */
    public function getScreenPermissionService(): ScreenPermissionService
    {
        if (!$this->screenPermissionService instanceof ScreenPermissionService) {
            $this->screenPermissionService = new ScreenPermissionService();
        }
        return $this->screenPermissionService;
    }

    /**
     * @param string $moduleName
     * @param array $menuTitles
     * @return int
     */
    public function enableModuleMenuItems(string $moduleName, array $menuTitles = []): int
    {
        $this->getAuthUser()->removeAttribute($this->getSidePanelCacheKey());
        foreach ($this->getAuthUser()->getAttribute($this->getTopRibbonKeysCacheKey(), []) as $topRibbonKey) {
            $cacheKey = $this->generateCacheKeyForTopMenuItem($topRibbonKey);
            $this->getAuthUser()->removeAttribute($cacheKey);
        }
        $this->getAuthUser()->removeAttribute($this->getTopRibbonKeysCacheKey());

        return $this->getMenuDao()->enableModuleMenuItems($moduleName, $menuTitles);
    }

    public function invalidateCachedMenuItems(): void
    {
        $this->getAuthUser()->removeAttribute($this->getSidePanelCacheKey());
        foreach ($this->getAuthUser()->getAttribute($this->getTopRibbonKeysCacheKey(), []) as $topRibbonKey) {
            $cacheKey = $this->generateCacheKeyForTopMenuItem($topRibbonKey);
            $this->getAuthUser()->removeAttribute($cacheKey);
        }
        $this->getAuthUser()->removeAttribute($this->getTopRibbonKeysCacheKey());
    }

    /**
     * @return DetailedMenuItem[]
     */
    private function getDetailedSidePanelMenuItemsAlongWithCache(): array
    {
        if (!$this->getAuthUser()->hasAttribute($this->getSidePanelCacheKey())) {
            $userRoles = $this->getUserRoleManager()->getUserRolesForAuthUser();
            $sidePanelMenuItems = $this->getMenuDao()->getSidePanelMenuItems($userRoles);

            $detailedSidePanelMenuItems = [];
            foreach ($sidePanelMenuItems as $sidePanelMenuItem) {
                $screen = $sidePanelMenuItem->getScreen();
                if (is_null($screen)) {
                    throw new LogicException('Side panel menu item should have screen assigned');
                }
                $detailedSidePanelMenuItems[] = DetailedMenuItem::createFromMenuItem($sidePanelMenuItem);
            }
            $this->getAuthUser()->setAttribute($this->getSidePanelCacheKey(), $detailedSidePanelMenuItems);
        }

        return $this->getAuthUser()->getAttribute($this->getSidePanelCacheKey());
    }

    /**
     * @param int $sidePanelMenuItemId
     * @return DetailedMenuItem[]
     */
    private function getTopMenuItemsAlongWithCache(int $sidePanelMenuItemId): array
    {
        $cacheKey = $this->generateCacheKeyForTopMenuItem($sidePanelMenuItemId);
        $userRoles = $this->getUserRoleManager()->getUserRolesForAuthUser();
        if (!$this->getAuthUser()->hasAttribute($cacheKey)) {
            $topMenuItems = $this->getMenuDao()->getTopMenuItems($userRoles, $sidePanelMenuItemId);
            $this->getAuthUser()->setAttribute($cacheKey, $topMenuItems);

            $topRibbonKeys = $this->getAuthUser()->getAttribute($this->getTopRibbonKeysCacheKey(), []);
            $topRibbonKeys[] = $sidePanelMenuItemId;
            $this->getAuthUser()->setAttribute($this->getTopRibbonKeysCacheKey(), $topRibbonKeys);
        }

        return $this->applyRoleHiddenTopMenu(
            $this->getAuthUser()->getAttribute($cacheKey),
            $sidePanelMenuItemId
        );
    }

    /**
     * @param int $sidePanelMenuItemId
     * @return string
     */
    private function generateCacheKeyForTopMenuItem(int $sidePanelMenuItemId): string
    {
        return 'core.menu.top_ribbon.' . $this->getMenuCacheVersion() . ".$sidePanelMenuItemId";
    }

    /**
     * @param string $baseUrl
     * @return array
     */
    public function getMenuItems(string $baseUrl): array
    {
        $currentModuleAndScreen = $this->getCurrentModuleAndScreen();

        $configuratorMenuItems = [];
        $screen = $this->getScreenPermissionService()
            ->getScreenDao()
            ->getScreen($currentModuleAndScreen->getModule(), $currentModuleAndScreen->getScreen());
        if ($screen instanceof Screen && !is_null($screen->getMenuConfigurator())) {
            $configuratorClass = $screen->getMenuConfigurator();
            $configurator = new $configuratorClass();
            if (!$configurator instanceof MenuConfigurator) {
                throw new LogicException("Invalid configurator class: $configuratorClass");
            }
            $configuratorMenuItems = $this->getMenuItemChainForMenuItem($configurator->configure($screen));
        }

        $detailedSidePanelMenuItems = $this->applyRoleSidePanelPriority(
            $this->getDetailedSidePanelMenuItemsAlongWithCache()
        );
        $normalizedSidePanelMenuItems = [];
        $selectedSidePanelMenuId = null;

        foreach ($detailedSidePanelMenuItems as $detailedSidePanelMenuItem) {
            $active = false;
            if (is_null($selectedSidePanelMenuId) && $active = $this->isActiveSidePanelMenuItem(
                $detailedSidePanelMenuItem,
                $currentModuleAndScreen,
                $configuratorMenuItems
            )) {
                $selectedSidePanelMenuId = $detailedSidePanelMenuItem->getId();
            }
            $normalizedSidePanelMenuItems[] = $this->normalizeMenuItem($detailedSidePanelMenuItem, $baseUrl, $active);
        }

        $normalizedTopMenuItems = [];
        if (!is_null($selectedSidePanelMenuId)) {
            $topMenuItems = $this->getTopMenuItemsAlongWithCache($selectedSidePanelMenuId);
            foreach ($topMenuItems as $topMenuItem) {
                $normalizedTopMenuItem = $this->normalizeTopMenuItem(
                    $topMenuItem,
                    $baseUrl,
                    $configuratorMenuItems,
                    $currentModuleAndScreen
                );
                is_null($normalizedTopMenuItem) ?: $normalizedTopMenuItems[] = $normalizedTopMenuItem;
            }
        }

        return [
            $normalizedSidePanelMenuItems,
            $normalizedTopMenuItems,
        ];
    }

    /**
     * @param MenuItem|null $menuItem
     * @return array<int, MenuItem>
     */
    private function getMenuItemChainForMenuItem(?MenuItem $menuItem): array
    {
        if (is_null($menuItem)) {
            return [];
        }
        $chain[$menuItem->getId()] = $menuItem;
        while (!is_null($menuItem->getParent())) {
            $menuItem = $menuItem->getParent();
            $chain[$menuItem->getId()] = $menuItem;
        }
        return $chain;
    }

    /**
     * @param DetailedMenuItem $sidePanelMenuItem
     * @param ModuleScreen $currentModuleScreen
     * @param array<int, MenuItem> $configuratorMenuItems
     * @return bool
     */
    private function isActiveSidePanelMenuItem(
        DetailedMenuItem $sidePanelMenuItem,
        ModuleScreen $currentModuleScreen,
        array $configuratorMenuItems = []
    ): bool {
        if (!empty($configuratorMenuItems)) {
            return isset($configuratorMenuItems[$sidePanelMenuItem->getId()]);
        }

        // Menu custom fork (Quota Request, History Quota, Manpower Request, Resource
        // Fulfillments, Unplanned Report) semuanya ber-modul 'admin' dan sebagian
        // memakai screen yang sama (viewSystemUsers); pembedanya hanya ?page=... di
        // additional_params. Kalau yang dibandingkan cuma modul, item level-1 pertama
        // yang modulnya cocok selalu dianggap aktif — akibatnya halaman custom ikut
        // menampilkan tab milik Admin (User Role, User Management, Job, ...).
        $currentPage = $this->getCurrentPageParam();
        $additionalParams = $sidePanelMenuItem->getAdditionalParams();
        if (is_array($additionalParams) && !empty($additionalParams['page'])) {
            return $currentPage === (string) $additionalParams['page'];
        }
        if (!is_null($currentPage)) {
            // Sedang di halaman custom (?page=...): hanya item yang mendeklarasikan
            // page tersebut yang boleh aktif, item modul lain tidak.
            return false;
        }

        return $sidePanelMenuItem->getModule() === $currentModuleScreen->getOverriddenModule();
    }

    /**
     * Nilai ?page= pada request saat ini (dipakai menu custom fork).
     */
    private function getCurrentPageParam(): ?string
    {
        $request = ModuleScreenHelper::getCurrentRequest();
        if (is_null($request)) {
            return null;
        }
        $page = $request->query->get('page');
        if (!is_string($page) || $page === '') {
            return null;
        }
        return $page;
    }

    /**
     * @param DetailedMenuItem $menuItem
     * @param ModuleScreen $currentModuleScreen
     * @param array<int, MenuItem> $configuratorMenuItems
     * @return bool
     */
    private function isActiveTopMenuItem(
        DetailedMenuItem $menuItem,
        ModuleScreen $currentModuleScreen,
        array $configuratorMenuItems = []
    ): bool {
        if (!empty($configuratorMenuItems)) {
            return isset($configuratorMenuItems[$menuItem->getId()]);
        }
        return $menuItem->getScreen() === $currentModuleScreen->getOverriddenScreen();
    }

    /**
     * @param DetailedMenuItem $detailedMenuItem
     * @param string $baseUrl
     * @param bool $active
     * @return array
     */
    private function normalizeMenuItem(
        DetailedMenuItem $detailedMenuItem,
        string $baseUrl,
        bool $active = false
    ): array {
        $url = '#';
        if (!empty($detailedMenuItem->getScreen()) && !empty($detailedMenuItem->getModule())) {
            $url = $baseUrl . '/' . $detailedMenuItem->getModule() . '/' . $detailedMenuItem->getScreen();
        }
        $menuItem = [
            'id' => $detailedMenuItem->getId(),
            'name' => $this->getI18NHelper()->transBySource($detailedMenuItem->getMenuTitle()),
            'url' => $url,
        ];

        if (!is_null($detailedMenuItem->getAdditionalParams()) &&
            isset($detailedMenuItem->getAdditionalParams()['icon'])) {
            $menuItem = array_merge($menuItem, $detailedMenuItem->getAdditionalParams());
        }

        $additional = $detailedMenuItem->getAdditionalParams();
        if (!is_null($additional) && isset($additional['page']) && $url !== '#') {
            $menuItem['url'] = $baseUrl . '/admin/viewSystemUsers?page=' . urlencode($additional['page']);
        }

        if ($active) {
            $menuItem['active'] = true;
        }
        return $menuItem;
    }

    /**
     * @param DetailedMenuItem $detailedMenuItem
     * @param string $baseUrl
     * @param array<int, MenuItem> $configuratorMenuItems
     * @param ModuleScreen|null $currentModuleAndScreen
     * @return array|null
     */
    private function normalizeTopMenuItem(
        DetailedMenuItem $detailedMenuItem,
        string $baseUrl,
        array $configuratorMenuItems = [],
        ?ModuleScreen $currentModuleAndScreen = null,
        bool $leaf = false
    ): ?array {
        $active = $this->isActiveTopMenuItem($detailedMenuItem, $currentModuleAndScreen, $configuratorMenuItems);
        $menuItem = $this->normalizeMenuItem($detailedMenuItem, $baseUrl, $active);
        $leaf ?: $menuItem['children'] = [];

        // if sub menu item exists
        if (!empty($detailedMenuItem->getChildMenuItems())) {
            foreach ($detailedMenuItem->getChildMenuItems() as $subItem) {
                $active = $this->isActiveTopMenuItem(
                    $subItem,
                    $currentModuleAndScreen,
                    $configuratorMenuItems
                );
                if ($active) {
                    $menuItem['active'] = true;
                }
                $normalizedTopMenuItem = $this->normalizeTopMenuItem(
                    $subItem,
                    $baseUrl,
                    $configuratorMenuItems,
                    $currentModuleAndScreen,
                    true
                );
                is_null($normalizedTopMenuItem) ?: $menuItem['children'][] = $normalizedTopMenuItem;
            }
        }

        /**
         * This is to hide second level menu items which don't have assigned screen &
         * no child menu items assigned. When there is no screen assigned to a menu item
         * no way to derive module or user permission
         */
        if (!$leaf && empty($menuItem['children']) && $menuItem['url'] === '#') {
            return null;
        }
        return $menuItem;
    }
}
