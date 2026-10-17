<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * Halaman: Dashboard Divisi (ringkasan kinerja KPI per divisi + drill-down).
 * Route:   /performance/dashboardDivisi
 */
namespace OrangeHRM\Performance\Controller;

use OrangeHRM\Core\Controller\AbstractVueController;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Vue\Component;
use OrangeHRM\Core\Vue\Prop;
use OrangeHRM\Framework\Http\Request;

class DashboardDivisiController extends AbstractVueController
{
    use AuthUserTrait;

    public function preRender(Request $request): void
    {
        $component = new Component('dashboard-divisi');
        // emp-number user login utk scoping divisi (non-admin dibatasi divisi mapping).
        $component->addProp(
            new Prop('emp-number', Prop::TYPE_NUMBER, $this->getAuthUser()->getEmpNumber())
        );
        $this->setComponent($component);
    }
}