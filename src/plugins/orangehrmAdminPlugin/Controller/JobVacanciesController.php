<?php

namespace OrangeHRM\Admin\Controller;

use OrangeHRM\Core\Controller\AbstractVueController;
use OrangeHRM\Core\Vue\Component;
use OrangeHRM\Framework\Http\Request;

class JobVacanciesController extends AbstractVueController
{
    public function preRender(Request $request): void
    {
        $component = new Component('view-job-vacancies');
        $this->setComponent($component);
    }
}
