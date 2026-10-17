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

namespace OrangeHRM\Admin\Controller;

use OrangeHRM\Admin\Traits\Service\UserServiceTrait;
use OrangeHRM\Core\Controller\AbstractVueController;
use OrangeHRM\Core\Vue\Component;
use OrangeHRM\Core\Vue\Prop;
use OrangeHRM\Framework\Http\Request;

class SystemUserController extends AbstractVueController
{
    use UserServiceTrait;

    /**
     * @inheritDoc
     */
    public function preRender(Request $request): void
    {
        $page = $request->query->get('page');

        if ($page === 'quota-request') {
            $this->renderQuotaRequest($request);
            return;
        }

        if ($page === 'all-quota-department') {
            $this->renderAllQuotaDepartment($request);
            return;
        }

        if ($page === 'history-quota-request') {
            $this->renderHistoryQuota($request);
            return;
        }

        if ($page === 'manpower-request') {
            $this->renderManpowerRequest($request);
            return;
        }

        // 👇 TAMBAHKAN BLOK INI UNTUK HALAMAN CREATE 👇
        if ($page === 'create-manpower-request') {
            $this->renderCreateManpowerRequest($request);
            return;
        }
        // 👆 ========================================= 👆

        if ($page === 'resource-fulfillment') {
            $this->renderResourceFulfillment($request);
            return;
        }

        if ($page === 'unplanned-request-report') {
            $this->renderUnplannedRequestReport($request);
            return;
        }

        // Fallback: original system user list
        $component = new Component('system-user-list');
        $component->addProp(new Prop('unselectable-ids', Prop::TYPE_ARRAY, $this->getUserService()->getUndeletableUserIds()));
        $this->setComponent($component);
    }

    private function renderQuotaRequest(Request $request): void
    {
        $component = new Component('view-quota-request');
        $this->setComponent($component);
    }

    private function renderAllQuotaDepartment(Request $request): void
    {
        $component = new Component('view-all-quota-department');
        $this->setComponent($component);
    }

    private function renderHistoryQuota(Request $request): void
    {
        $component = new Component('view-history-quota');
        $this->setComponent($component);
    }

    private function renderManpowerRequest(Request $request): void
    {
        $component = new Component('view-manpower-request');
        $this->setComponent($component);
    }

    // 👇 TAMBAHKAN FUNGSI INI 👇
    private function renderCreateManpowerRequest(Request $request): void
    {
        $component = new Component('create-manpower-request');
        $this->setComponent($component);
    }

    private function renderResourceFulfillment(Request $request): void
    {
        $component = new Component('view-resource-fulfillment');
        $this->setComponent($component);
    }

    private function renderUnplannedRequestReport(Request $request): void
    {
        $component = new Component('view-unplanned-request-report');
        $this->setComponent($component);
    }
}
