<!--
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
 -->

<template>
  <div class="orangehrm-card-container">
    <oxd-form :loading="isLoading">
      <oxd-text tag="h6" class="orangehrm-main-title">
        {{ $t('recruitment.application_stage') }}
      </oxd-text>
      <oxd-divider />
      <oxd-grid :cols="3" class="orangehrm-full-width-grid">
        <oxd-grid-item>
          <oxd-input-group :label="$t('general.name')">
            <oxd-text tag="p">
              {{ candidateName }}
            </oxd-text>
          </oxd-input-group>
        </oxd-grid-item>
        <oxd-grid-item>
          <oxd-input-group :label="$t('recruitment.vacancy')">
            <oxd-text tag="p">
              {{ vacancyName ? vacancyName : 'N/A' }}
            </oxd-text>
          </oxd-input-group>
        </oxd-grid-item>
        <oxd-grid-item>
          <oxd-input-group :label="$t('recruitment.hiring_manager')">
            <oxd-text tag="p">
              {{ hiringManagerName ? hiringManagerName : 'N/A' }}
            </oxd-text>
          </oxd-input-group>
        </oxd-grid-item>
      </oxd-grid>
      <oxd-divider />
      <div class="orangehrm-recruitment">
        <div class="orangehrm-recruitment-statuses">
          <div v-if="recruitmentStatus" class="orangehrm-recruitment-status">
            <oxd-text type="subtitle-2">
              {{ $t('general.status') }}: {{ recruitmentStatus }}
            </oxd-text>
          </div>
          <!-- Status Task di tab Activities Acumatica (2026-11-02).
               Sumber: tabel ohrm_candidate_acumatica_task (diisi saat Sync
               Application to Acumatica atau lewat tombol Refresh). -->
          <div class="orangehrm-acumatica-task">
            <oxd-text type="subtitle-2">
              Acumatica Task Status:
              <span v-if="acumaticaTaskLoading">memuat...</span>
              <span v-else :title="acumaticaTaskError || ''">{{
                acumaticaTaskStatus || '-'
              }}</span>
            </oxd-text>
            <a
              class="orangehrm-acumatica-task-refresh"
              href="#"
              title="Ambil ulang status Task dari Acumatica"
              @click.prevent="refreshAcumaticaTask"
            >
              {{ acumaticaTaskRefreshing ? 'mengambil...' : 'Refresh' }}
            </a>
            <span
              v-if="acumaticaTaskSyncedAt"
              class="orangehrm-acumatica-task-time"
            >
              ({{ acumaticaTaskSyncedAtShort }})
            </span>
          </div>
        </div>
        <div class="orangehrm-recruitment-actions">
          <oxd-button
            v-if="hasWorkflow(3)"
            :label="$t('general.reject')"
            display-type="danger"
            @click="doWorkflow(3)"
          />
          <oxd-button
            v-if="hasWorkflow(8)"
            :label="$t('recruitment.offer_declined')"
            display-type="danger"
            @click="doWorkflow(8)"
          />
          <oxd-button
            v-if="hasWorkflow(6)"
            :label="$t('recruitment.mark_interview_failed')"
            display-type="danger"
            @click="doWorkflow(6)"
          />
          <oxd-button
            v-if="hasWorkflow(2)"
            :label="$t('recruitment.shortlist')"
            display-type="success"
            @click="doWorkflow(2)"
          />
          <oxd-button
            v-if="hasWorkflow(4)"
            :label="$t('recruitment.schedule_interview')"
            display-type="success"
            @click="doWorkflow(4)"
          />
          <oxd-button
            v-if="hasWorkflow(5)"
            :label="$t('recruitment.mark_interview_passed')"
            display-type="success"
            @click="doWorkflow(5)"
          />
          <oxd-button
            v-if="hasWorkflow(7)"
            :label="$t('recruitment.offer_job')"
            display-type="success"
            @click="doWorkflow(7)"
          />
          <oxd-button
            v-if="hasWorkflow(9)"
            :label="$t('recruitment.hire')"
            display-type="success"
            @click="doWorkflow(9)"
          />
          <oxd-button
            v-if="hasWorkflow(11)"
            label="Cancel Hire"
            display-type="danger"
            :disabled="true"
            @click="doWorkflow(11)"
          />
        </div>
      </div>
    </oxd-form>
  </div>
</template>

<script>
import {navigate} from '@/core/util/helper/navigation';
import {APIService} from '@/core/util/services/api.service';
import useEmployeeNameTranslate from '@/core/util/composable/useEmployeeNameTranslate';

export default {
  name: 'RecruitmentStatus',
  props: {
    candidate: {
      type: Object,
      required: true,
    },
  },
  setup() {
    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/recruitment/candidates',
    );
    const {$tEmpName} = useEmployeeNameTranslate();

    return {
      http,
      translateEmpName: $tEmpName,
    };
  },
  data() {
    return {
      isLoading: false,
      // Status Task Acumatica untuk kandidat ini (Open/Processing/Completed/Canceled)
      acumaticaTaskStatus: null,
      acumaticaTaskSyncedAt: null,
      acumaticaTaskLoading: false,
      acumaticaTaskRefreshing: false,
      acumaticaTaskError: null,
      statuses: [
        {id: 1, label: this.$t('recruitment.application_initiated')},
        {id: 2, label: this.$t('recruitment.shortlisted')},
        {id: 3, label: this.$t('leave.rejected')},
        {id: 4, label: this.$t('recruitment.interview_scheduled')},
        {id: 5, label: this.$t('recruitment.interview_passed')},
        {id: 6, label: this.$t('recruitment.interview_failed')},
        {id: 7, label: this.$t('recruitment.job_offered')},
        {id: 8, label: this.$t('recruitment.offer_declined')},
        {id: 9, label: this.$t('recruitment.hired')},
      ],
      actions: [],
    };
  },
  computed: {
    // Jam data status Task disimpan (HH:MM) — supaya kelihatan apakah nilainya
    // baru diambil atau masih lama.
    acumaticaTaskSyncedAtShort() {
      const raw = String(this.acumaticaTaskSyncedAt || '');
      const m = raw.match(/(\d{2}):(\d{2})/);
      return m ? `${m[1]}:${m[2]}` : '';
    },
    recruitmentStatus() {
      return (
        this.statuses.find((item) => item.id === this.candidate.status?.id)
          ?.label || null
      );
    },
    candidateName() {
      return `${this.candidate.firstName} ${this.candidate?.middleName || ''} ${
        this.candidate.lastName
      }`;
    },
    vacancyName() {
      const {vacancy} = this.candidate;
      if (!vacancy) return null;
      return vacancy.status === false
        ? vacancy.name + ` (${this.$t('general.closed')})`
        : vacancy.name;
    },
    hiringManagerName() {
      return this.candidate.vacancy?.hiringManager
        ? this.translateEmpName(this.candidate.vacancy.hiringManager, {
            includeMiddle: true,
            excludePastEmpTag: false,
          })
        : undefined;
    },
  },
  watch: {
    candidate() {
      this.getAllowedActions();
      this.fetchAcumaticaTaskStatus();
    },
  },
  beforeMount() {
    this.getAllowedActions();
    this.fetchAcumaticaTaskStatus();
  },
  methods: {
    // Ambil status Task Acumatica dari DB lokal (cepat, tanpa login Acumatica).
    // refresh=true memaksa ambil ulang dari Acumatica (dipakai tombol Refresh).
    async fetchAcumaticaTaskStatus(refresh = false) {
      const candidateId = this.candidate?.id;
      if (!candidateId) return;
      if (refresh) {
        this.acumaticaTaskRefreshing = true;
      } else {
        this.acumaticaTaskLoading = true;
      }
      try {
        const rawBase = String(window.appGlobal?.baseUrl || '');
        // Di instance tertentu (mis. staging SSO :8181, docroot = root project)
        // baseUrl berisi "/web/index.php" -> harus dibuang, kalau tidak URL
        // jatuh ke router Yii (/web/index.php/proxy/...) dan balas 404.
        const baseUrl = rawBase
          .replace(/\/index\.php.*$/i, '')
          .replace(/\/$/, '');
        const url =
          `${baseUrl}/proxy/get_candidate_task_status.php?candidate_id=${candidateId}` +
          (refresh ? '&refresh=1' : '');
        const res = await fetch(url, {headers: {Accept: 'application/json'}});
        const data = await res.json();
        if (data && data.status === 'ok') {
          this.acumaticaTaskStatus =
            data.task_status || this.acumaticaTaskStatus;
          this.acumaticaTaskSyncedAt = data.synced_at || null;
          this.acumaticaTaskError = null;
          // Catatan: TIDAK auto-refresh ke Acumatica saat halaman dibuka.
          // Login Acumatica dibatasi (SM201010 concurrent API logins) dan sesi
          // bisa menggantung -> refresh hanya lewat tombol (1 login, lalu logout).
        } else if (!refresh) {
          // no_task / error saat baca DB: jangan hapus nilai yang sudah tampil
          this.acumaticaTaskError = data?.message || null;
        } else {
          this.acumaticaTaskError = data?.message || 'Refresh gagal.';
        }
      } catch (e) {
        this.acumaticaTaskError = 'Gagal menghubungi server.';
      } finally {
        this.acumaticaTaskLoading = false;
        this.acumaticaTaskRefreshing = false;
      }
    },
    refreshAcumaticaTask() {
      if (this.acumaticaTaskRefreshing) return;
      this.fetchAcumaticaTaskStatus(true);
    },
    hasWorkflow(actionId) {
      return this.actions.findIndex((actions) => actions.id == actionId) > -1;
    },
    doWorkflow(actionId) {
      navigate(
        '/recruitment/changeCandidateVacancyStatus',
        {},
        {
          candidateId: this.candidate?.id,
          selectedAction: actionId,
        },
      );
    },
    getAllowedActions() {
      this.isLoading = true;
      this.http
        .request({
          method: 'GET',
          url: `/api/v2/recruitment/candidates/${this.candidate?.id}/actions/allowed`,
        })
        .then((response) => {
          const {data} = response.data;
          this.actions = [...data];
        })
        .finally(() => {
          this.isLoading = false;
        });
    },
  },
};
</script>

<style lang="scss" scoped>
.orangehrm-recruitment {
  display: flex;
  justify-content: space-between;
  &-statuses {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }
  &-actions {
    gap: 0.4rem;
    display: flex;
    flex-wrap: wrap;
    max-width: 120px;
    margin-left: 60px;
    justify-content: flex-end;
    ::v-deep(.oxd-button--medium) {
      width: 100%;
    }
    @include oxd-respond-to('md') {
      margin-left: unset;
      max-width: unset;
      ::v-deep(.oxd-button--medium) {
        width: unset;
      }
    }
  }
}
::v-deep(.oxd-input-group) {
  margin-bottom: 1rem;
  @include oxd-respond-to('md') {
    margin-bottom: 0;
  }
}

/* Baris "Acumatica Task Status" + tautan Refresh (2026-11-02) */
.orangehrm-acumatica-task {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  &-refresh {
    font-size: 12px;
    line-height: 1.4;
    color: #1a73e8;
    text-decoration: underline;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
  }
  &-time {
    font-size: 11px;
    color: #64748b;
    white-space: nowrap;
  }
}
</style>
