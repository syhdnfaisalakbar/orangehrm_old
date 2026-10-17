<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 */

namespace OrangeHRM\Recruitment\Api;

use OrangeHRM\Entity\WorkflowStateMachine;

/**
 * Aksi "Cancel Hire" — untuk kandidat yang sudah diklik Hire (status HIRED)
 * tetapi ternyata batal / tidak jadi bergabung.
 *
 * Jalur workflow yang sudah terlanjur terminal (HIRED -> tidak ada transisi
 * keluar di OrangeHRM standar) diputus dengan aksi ini: HIRED -> REJECTED.
 * Efek sampingnya (hapus employee otomatis yang dibuat saat hire, buka kembali
 * vacancy/MPR/quota, dan history) ditangani di AbstractCandidateActionAPI.
 */
class CandidateHireCancellationAPI extends AbstractCandidateActionAPI
{
    /**
     * @return int
     */
    public function getResultingState(): int
    {
        return WorkflowStateMachine::RECRUITMENT_APPLICATION_ACTION_HIRE_CANCELLED;
    }
}