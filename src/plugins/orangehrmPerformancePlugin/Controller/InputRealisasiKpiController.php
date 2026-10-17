<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * Halaman: Input Realisasi KPI (PMS Antar-Departemen)
 * Route:   /performance/inputRealisasiKpi
 */

namespace OrangeHRM\Performance\Controller;

use OrangeHRM\Core\Controller\AbstractVueController;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Vue\Component;
use OrangeHRM\Core\Vue\Prop;
use OrangeHRM\Framework\Http\Request;

class InputRealisasiKpiController extends AbstractVueController
{
    use AuthUserTrait;

    /**
     * @inheritDoc
     */
    public function preRender(Request $request): void
    {
        $component = new Component('input-realisasi-kpi');
        // Emp number user yang login — dipakai proxy untuk scoping divisi (ESS vs Admin).
        $component->addProp(new Prop('emp-number', Prop::TYPE_NUMBER, $this->getAuthUser()->getEmpNumber()));
        $this->setComponent($component);
    }
}