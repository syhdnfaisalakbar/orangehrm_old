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
 * Boston, MA 02110-1301, USA
 */

namespace OrangeHRM\Authentication\Controller;

use OrangeHRM\Authentication\Auth\User as AuthUser;
use OrangeHRM\Authentication\Exception\AuthenticationException;
use OrangeHRM\Authentication\Service\AuthenticationService;
use OrangeHRM\Authentication\Service\GoogleSSOService;
use OrangeHRM\Admin\Service\UserService;
use OrangeHRM\Core\Authorization\Service\HomePageService;
use OrangeHRM\Core\Controller\AbstractController;
use OrangeHRM\Core\Controller\PublicControllerInterface;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ServiceContainerTrait;
use OrangeHRM\Entity\User;
use OrangeHRM\Framework\Http\RedirectResponse;
use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Framework\Routing\UrlGenerator;
use OrangeHRM\Framework\Services;
use Throwable;

/**
 * Langkah 2 SSO Google: callback setelah user setuju di Google.
 *
 * Alur:
 *  1. Validasi state (anti-CSRF) yang disimpan di session.
 *  2. Tukar authorization code -> access token (endpoint token Google).
 *  3. Ambil profil (email) dari userinfo endpoint.
 *  4. Cek email domain (jika di-set).
 *  5. Cocokkan email ke user OrangeHRM via work email karyawan.
 *     - ketemu & valid  -> set session login, redirect ke home.
 *     - tidak ketemu    -> flash error, kembali ke halaman login.
 *
 * Route: GET /auth/google/callback
 */
class GoogleSSOCallbackController extends AbstractController implements PublicControllerInterface
{
    use AuthUserTrait;
    use ServiceContainerTrait;

    public const PARAMETER_STATE = 'state';
    public const PARAMETER_CODE = 'code';
    public const PARAMETER_ERROR = 'error';

    /**
     * @var HomePageService|null
     */
    protected ?HomePageService $homePageService = null;

    /**
     * @return HomePageService
     */
    public function getHomePageService(): HomePageService
    {
        return $this->homePageService ??= new HomePageService();
    }

    public function handle(Request $request): RedirectResponse
    {
        /** @var UrlGenerator $urlGenerator */
        $urlGenerator = $this->getContainer()->get(Services::URL_GENERATOR);
        $loginUrl = $urlGenerator->generate('auth_login', [], UrlGenerator::ABSOLUTE_URL);

        $ssoService = new GoogleSSOService();
        if (!$ssoService->isEnabled()) {
            return new RedirectResponse($loginUrl);
        }

        /** @var Session $session */
        $session = $this->getContainer()->get(Services::SESSION);

        // 1. Validasi state anti-CSRF
        $expectedState = $session->get('google_sso_state');
        $session->remove('google_sso_state');
        if ($expectedState === null || $expectedState === '' ||
            !hash_equals((string)$expectedState, (string)$request->query->get(self::PARAMETER_STATE, ''))) {
            $this->flashLoginError(
                'Google SSO gagal: state tidak valid (kemungkinan session kadaluarsa). Coba lagi.'
            );
            return new RedirectResponse($loginUrl);
        }

        // User menolak / terjadi error di Google
        if ($request->query->has(self::PARAMETER_ERROR)) {
            $this->flashLoginError('Login Google dibatalkan.');
            return new RedirectResponse($loginUrl);
        }

        $code = $request->query->get(self::PARAMETER_CODE, '');
        if ($code === '') {
            $this->flashLoginError('Google SSO gagal: tidak ada authorization code.');
            return new RedirectResponse($loginUrl);
        }

        // 2. Tukar code -> token
        $tokens = $ssoService->exchangeCodeForToken($code, $ssoService->getRedirectUri($request));
        if ($tokens === null || empty($tokens['access_token'])) {
            $this->flashLoginError(
                'Google SSO gagal: tidak dapat menukar kode otorisasi. Periksa Client ID/Secret/redirect URI.'
            );
            return new RedirectResponse($loginUrl);
        }

        // 3. Ambil email
        $userInfo = $ssoService->fetchUserInfo($tokens['access_token']);
        $email = strtolower(trim((string)($userInfo['email'] ?? '')));
        if ($email === '' || empty($userInfo['email_verified'])) {
            $this->flashLoginError('Google SSO gagal: email Google tidak tersedia / belum diverifikasi.');
            return new RedirectResponse($loginUrl);
        }

        // 4. Validasi domain
        if (!$ssoService->isEmailAllowed($email)) {
            $this->flashLoginError('Maaf, email Anda tidak berhak login ke sistem ini.');
            return new RedirectResponse($loginUrl);
        }

        $authenticationService = new AuthenticationService();

        // 5. Cocokkan email -> user OrangeHRM (work email karyawan)
        /** @var User|null $user */
        $user = (new UserService())->getUserByWorkEmail($email);
        if ($user === null) {
            $this->flashLoginError('Email tidak terdaftar sebagai user sistem. Hubungi admin.');
            return new RedirectResponse($loginUrl);
        }

        try {
            $success = $authenticationService->setCredentialsForUser($user);
        } catch (AuthenticationException $e) {
            $this->flashLoginError($e->getMessage());
            return new RedirectResponse($loginUrl);
        } catch (Throwable $e) {
            $this->flashLoginError('Terjadi kesalahan saat login. Hubungi admin.');
            return new RedirectResponse($loginUrl);
        }

        if (!$success) {
            $this->flashLoginError('Akun Anda sudah non-aktif. Hubungi admin.');
            return new RedirectResponse($loginUrl);
        }

        $this->getAuthUser()->setIsAuthenticated(true);
        $session->migrate(true);

        // Ingat email terakhir supaya login berikutnya langsung tersorot
        // di layar pilih akun Google (login_hint).
        $ssoService->rememberLastEmail($email);

        $homePagePath = $this->getHomePageService()->getHomePagePath();
        return $this->redirect($homePagePath);
    }

    /**
     * @param string $message
     */
    private function flashLoginError(string $message): void
    {
        $this->getAuthUser()->addFlash(
            AuthUser::FLASH_LOGIN_ERROR,
            [
                'error' => 'SSOError',
                'message' => $message,
            ]
        );
    }
}