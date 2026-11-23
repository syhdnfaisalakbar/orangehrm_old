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

namespace OrangeHRM\Dashboard\Controller;

use OrangeHRM\Core\Controller\AbstractVueController;
use OrangeHRM\Core\Helper\VueControllerHelper;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ServiceContainerTrait;
use OrangeHRM\Core\Traits\UserRoleManagerTrait;
use OrangeHRM\Core\Vue\Component;
use OrangeHRM\Framework\Http\Request;

class DashboardController extends AbstractVueController
{
    use AuthUserTrait;
    use ServiceContainerTrait;
    use UserRoleManagerTrait;

    public function preRender(Request $request): void
    {
        // Fork ASG: role ESS dan BOD yang membuka menu "Dashboard" langsung
        // diarahkan ke tab "Dashboard Divisi" (permintaan Pak). Home page kedua
        // role memang menunjuk ke sana, tapi klik menu Dashboard tetap memakai URL
        // bawaan dashboard/index (screen 103) sehingga yang tampil widget biasa.
        // Role lain (Admin, MPP, ...) tidak terpengaruh.
        $roleName = strtoupper((string) $this->getAuthUser()->getUserRoleName());
        if (in_array($roleName, ['ESS', 'BOD'], true)) {
            $this->setResponse($this->redirect('dashboard/dashboardDivisi'));

            return;
        }

        $component = new Component('view-dashboard');
        $this->setComponent($component);

        $dataGroups = [
            'dashboard_subunit_widget',
            'dashboard_location_widget',
            'dashboard_leave_widget',
            'dashboard_time_widget',
            'dashboard_employees_on_leave_today_config',
            // Widget custom fork: hanya role yang diberi can_read di data group ini
            // (lihat deploy/sql/dashboard-rf-widget-role.sql → MPP + Admin).
            'dashboard_resource_fulfillment_widget',
            // Widget Quick Launch: hanya role yang diberi can_read di data group ini
            // (lihat deploy/sql/mpp-hide-quick-launch.sql → semua role KECUALI MPP).
            'dashboard_quick_launch_widget',
        ];

        $permissions = $this->getUserRoleManagerHelper()
            ->geEntityIndependentDataGroupPermissionCollection($dataGroups);

        $this->getContext()->set(
            VueControllerHelper::PERMISSIONS,
            $permissions->toArray()
        );
    }
}
