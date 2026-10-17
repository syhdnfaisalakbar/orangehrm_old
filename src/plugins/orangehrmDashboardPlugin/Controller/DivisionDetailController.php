<?php
/**
 * OrangeHRM — Division Detail
 *
 * Halaman "Division Detail" (sub-item di bawah menu Dashboard).
 * Route: /dashboard/divisionDetail
 */
namespace OrangeHRM\Dashboard\Controller;

use OrangeHRM\Core\Controller\AbstractVueController;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Vue\Component;
use OrangeHRM\Core\Vue\Prop;
use OrangeHRM\Framework\Http\Request;

class DivisionDetailController extends AbstractVueController
{
    use AuthUserTrait;

    public function preRender(Request $request): void
    {
        $component = new Component('division-detail');
        // emp-number user login utk scoping divisi (non-admin dibatasi divisi mapping).
        $component->addProp(
            new Prop('emp-number', Prop::TYPE_NUMBER, $this->getAuthUser()->getEmpNumber())
        );
        $this->setComponent($component);
    }
}