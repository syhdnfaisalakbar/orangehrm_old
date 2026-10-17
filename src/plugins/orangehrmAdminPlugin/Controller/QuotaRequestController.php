<?php

namespace OrangeHRM\Admin\Controller;

use OrangeHRM\Core\Controller\AbstractVueController;
use OrangeHRM\Core\Vue\Component;
use OrangeHRM\Core\Vue\Prop;
use OrangeHRM\Framework\Http\Request;

class QuotaRequestController extends AbstractVueController
{
    public function preRender(Request $request): void
    {
        $component = new Component('view-quota-request');
        // kalo ada data, kirim via prop:
        // $component->addProp(new Prop('records', Prop::TYPE_ARRAY, $data));
        $this->setComponent($component);
    }
}
