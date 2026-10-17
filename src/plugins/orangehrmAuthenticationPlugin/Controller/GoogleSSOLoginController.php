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

use OrangeHRM\Authentication\Service\GoogleSSOService;
use OrangeHRM\Authentication\Traits\CsrfTokenManagerTrait;
use OrangeHRM\Core\Controller\AbstractController;
use OrangeHRM\Core\Controller\PublicControllerInterface;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ServiceContainerTrait;
use OrangeHRM\Framework\Http\RedirectResponse;
use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Framework\Routing\UrlGenerator;
use OrangeHRM\Framework\Services;

/**
 * Langkah 1 SSO Google: generate state anti-CSRF, simpan di session,
 * lalu redirect user ke Google Authorization URL.
 *
 * Route: GET /auth/google/social-login
 */
class GoogleSSOLoginController extends AbstractController implements PublicControllerInterface
{
    use AuthUserTrait;
    use ServiceContainerTrait;
    use CsrfTokenManagerTrait;

    public function handle(Request $request): RedirectResponse
    {
        $loginUrl = $this->getUrlGenerator()->generate('auth_login', [], UrlGenerator::ABSOLUTE_URL);
        $ssoService = new GoogleSSOService();

        if (!$ssoService->isEnabled()) {
            return new RedirectResponse($loginUrl);
        }

        $state = bin2hex(random_bytes(16));

        /** @var Session $session */
        $session = $this->getContainer()->get(Services::SESSION);
        $session->set('google_sso_state', $state);

        $authUrl = $ssoService->buildAuthorizationUrl($state, $request);
        return new RedirectResponse($authUrl);
    }

    /**
     * @return UrlGenerator
     */
    private function getUrlGenerator(): UrlGenerator
    {
        return $this->getContainer()->get(Services::URL_GENERATOR);
    }
}