<?php
/**
 * OrangeHRM — Dashboard Divisi
 *
 * Halaman "Dashboard Divisi" (sub-item di bawah menu Dashboard).
 * Route: /dashboard/dashboardDivisi
 */
namespace OrangeHRM\Dashboard\Controller;

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