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
  <div class="orangehrm-candidate-page">
    <oxd-table-filter :filter-title="$t('general.candidates')">
      <oxd-form @submit-valid="filterItems" @reset="onReset">
        <oxd-form-row>
          <oxd-grid :cols="4" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <jobtitle-dropdown v-model="filters.jobTitle"></jobtitle-dropdown>
            </oxd-grid-item>
            <oxd-grid-item>
              <vacancy-dropdown v-model="filters.vacancy"></vacancy-dropdown>
            </oxd-grid-item>
            <oxd-grid-item>
              <hiring-manager-dropdown
                v-model="filters.hiringManager"
              ></hiring-manager-dropdown>
            </oxd-grid-item>
            <oxd-grid-item>
              <candidate-status-dropdown v-model="filters.status" />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>
        <oxd-form-row>
          <oxd-grid :cols="4" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <candidate-autocomplete
                v-model="filters.candidate"
                :rules="rules.candidate"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.keywords"
                :label="$t('recruitment.keywords')"
                :placeholder="`${$t(
                  'recruitment.enter_comma_seperated_words',
                )}...`"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <date-input
                v-model="filters.fromDate"
                :label="$t('recruitment.date_of_application')"
                :placeholder="$t('general.from')"
                :rules="rules.fromDate"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <date-input
                v-model="filters.toDate"
                label="&nbsp"
                :placeholder="$t('general.to')"
                :rules="rules.toDate"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>
        <oxd-form-row>
          <oxd-grid :cols="4" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <acumatica-task-status-dropdown
                v-model="filters.acumaticaTaskStatus"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>
        <oxd-divider />

        <oxd-form-actions>
          <oxd-button
            type="reset"
            display-type="ghost"
            :label="$t('general.reset')"
          />
          <submit-button :label="$t('general.search')" />
        </oxd-form-actions>
      </oxd-form>
    </oxd-table-filter>
    <br />
    <div class="orangehrm-paper-container">
      <div class="orangehrm-header-container">
        <oxd-button
          v-if="$can.create('recruitment_candidates')"
          :label="$t('general.add')"
          icon-name="plus"
          display-type="secondary"
          @click="onClickAdd"
        />
        <!-- Tarik status Task Acumatica untuk semua kandidat di tabel (1 login) -->
        <oxd-button
          :label="batchSyncing ? 'Syncing...' : 'Sync Acumatica'"
          icon-name="cloud-upload"
          display-type="secondary"
          :disabled="batchSyncing"
          @click="onSyncAllAcumatica"
        />
      </div>
      <table-header
        :total="total"
        :loading="isLoading"
        :show-divider="true"
      ></table-header>
      <div class="orangehrm-container">
        <oxd-card-table
          v-model:order="sortDefinition"
          :headers="headers"
          :items="items?.data"
          :clickable="false"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
        />
      </div>
      <div class="orangehrm-bottom-container">
        <div
          style="
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
          "
        >
          <div style="display: flex; align-items: center; gap: 8px">
            <span class="oxd-text oxd-text--span">Show</span>
            <select
              v-model="pageSize"
              style="
                height: 36px;
                min-width: 80px;
                padding: 0 8px;
                border: 1px solid #ced4da;
                border-radius: 4px;
                font-size: 14px;
                background-color: #fff;
              "
            >
              <option :value="10">10</option>
              <option :value="25">25</option>
              <option :value="50">50</option>
            </select>
            <span class="oxd-text oxd-text--span">entries</span>
          </div>
          <oxd-pagination
            v-if="showPaginator"
            v-model:current="currentPage"
            :length="pages"
          />
        </div>
      </div>
    </div>
    <acumatica-sync-result-dialog ref="syncDialog" />
  </div>
</template>
<script>
import {computed, ref, onMounted} from 'vue';
import {
  validSelection,
  validDateFormat,
  endDateShouldBeAfterStartDate,
  startDateShouldBeBeforeEndDate,
} from '@/core/util/validation/rules';
import usei18n from '@/core/util/composable/usei18n';
import useSort from '@ohrm/core/util/composable/useSort';
import useLocale from '@/core/util/composable/useLocale';
import {navigate} from '@ohrm/core/util/helper/navigation';
import {APIService} from '@/core/util/services/api.service';
import useDateFormat from '@/core/util/composable/useDateFormat';
import useToast from '@/core/util/composable/useToast';
import usePaginate from '@ohrm/core/util/composable/usePaginate';
import {formatDate, parseDate} from '@ohrm/core/util/helper/datefns';
import JobtitleDropdown from '@/orangehrmPimPlugin/components/JobtitleDropdown';
import VacancyDropdown from '@/orangehrmRecruitmentPlugin/components/VacancyDropdown';
import useEmployeeNameTranslate from '@/core/util/composable/useEmployeeNameTranslate';
import CandidateAutocomplete from '@/orangehrmRecruitmentPlugin/components/CandidateAutocomplete';
import HiringManagerDropdown from '@/orangehrmRecruitmentPlugin/components/HiringManagerDropdown';
import CandidateStatusDropdown from '@/orangehrmRecruitmentPlugin/components/CandidateStatusDropdown';
import CandidateStatusBadge from '@/orangehrmRecruitmentPlugin/components/CandidateStatusBadge';
import AcumaticaTaskStatusCell from '@/orangehrmRecruitmentPlugin/components/AcumaticaTaskStatusCell';
import AcumaticaTaskStatusDropdown from '@/orangehrmRecruitmentPlugin/components/AcumaticaTaskStatusDropdown';
import AcumaticaSyncResultDialog from '@/orangehrmRecruitmentPlugin/components/AcumaticaSyncResultDialog';

const defaultFilters = {
  jobTitle: null,
  vacancy: null,
  hiringManager: null,
  status: null,
  keywords: null,
  candidate: null,
  fromDate: null,
  toDate: null,
  acumaticaTaskStatus: null,
};

const defaultSortOrder = {
  'vacancy.name': 'DEFAULT',
  'candidate.lastName': 'DEFAULT',
  'hiringManager.lastName': 'DEFAULT',
  'candidate.dateOfApplication': 'DESC',
  'candidateVacancy.status': 'DEFAULT',
};

export default {
  components: {
    'vacancy-dropdown': VacancyDropdown,
    'jobtitle-dropdown': JobtitleDropdown,
    'candidate-autocomplete': CandidateAutocomplete,
    'hiring-manager-dropdown': HiringManagerDropdown,
    'candidate-status-dropdown': CandidateStatusDropdown,
    'acumatica-sync-result-dialog': AcumaticaSyncResultDialog,
    'acumatica-task-status-dropdown': AcumaticaTaskStatusDropdown,
  },

  props: {
    status: {
      type: Object,
      required: false,
      default: null,
    },
  },

  setup(props) {
    const {$t} = usei18n();
    const {locale} = useLocale();
    const {jsDateFormat, userDateFormat} = useDateFormat();
    const {$tEmpName} = useEmployeeNameTranslate();
    // candidate_id -> status Task Acumatica. Dimuat sekali saat halaman dibuka,
    // lalu disisipkan ke tiap baris oleh normalizer supaya kolomnya langsung terisi.
    const acumaticaTaskMap = ref({});
    const statuses = [
      {id: 1, label: $t('recruitment.application_initiated')},
      {id: 2, label: $t('recruitment.shortlisted')},
      {id: 3, label: $t('leave.rejected')},
      {id: 4, label: $t('recruitment.interview_scheduled')},
      {id: 5, label: $t('recruitment.interview_passed')},
      {id: 6, label: $t('recruitment.interview_failed')},
      {id: 7, label: $t('recruitment.job_offered')},
      {id: 8, label: $t('recruitment.offer_declined')},
      {id: 9, label: $t('recruitment.hired')},
    ];
    const candidateDataNormalizer = (data) => {
      return data.map((item) => {
        return {
          id: item.id,
          vacancy:
            item.vacancy?.status === false
              ? `${item.vacancy?.name} (${$t('general.closed')})`
              : item.vacancy?.name,
          candidate: `${item.firstName} ${item.middleName || ''} ${
            item.lastName
          }`,
          manager: item?.vacancy?.hiringManager?.id
            ? $tEmpName(item.vacancy.hiringManager, {
                includeMiddle: true,
                excludePastEmpTag: false,
              })
            : $t('general.deleted'),
          dateOfApplication: formatDate(
            parseDate(item.dateOfApplication),
            jsDateFormat,
            {locale},
          ),
          status:
            statuses.find((status) => status.id === item.status?.id)?.label ||
            '',
          statusId: item.status?.id,
          // Kolom "Acumatica Task Status" (diisi saat Sync Application / Refresh)
          acumaticaTaskStatus: acumaticaTaskMap.value[String(item.id)] || '',
          resume: item.hasAttachment,
          isSelectable: item.deletable,
        };
      });
    };
    const filters = ref({
      ...defaultFilters,
      ...(props.status && {status: props.status}),
    });
    const rules = {
      candidate: [validSelection],
      fromDate: [
        validDateFormat(userDateFormat),
        startDateShouldBeBeforeEndDate(
          () => filters.value.toDate,
          $t('general.from_date_should_be_before_to_date'),
          {allowSameDate: true},
        ),
      ],
      toDate: [
        validDateFormat(userDateFormat),
        endDateShouldBeAfterStartDate(
          () => filters.value.fromDate,
          $t('general.to_date_should_be_after_from_date'),
          {allowSameDate: true},
        ),
      ],
    };
    const {sortDefinition, sortField, sortOrder, onSort} = useSort({
      sortDefinition: defaultSortOrder,
    });
    const serializedFilters = computed(() => {
      return {
        jobTitleId: filters.value.jobTitle?.id,
        vacancyId: filters.value.vacancy?.id,
        hiringManagerId: filters.value.hiringManager?.id,
        keywords: filters.value.keywords,
        candidateId: filters.value.candidate?.id,
        fromDate: filters.value.fromDate,
        toDate: filters.value.toDate,
        status: filters.value.status?.id,
        // filter tambahan fork ASG: status Task Acumatica (Open/Processing/Canceled)
        acumaticaTaskStatus: filters.value.acumaticaTaskStatus?.id,
        model: 'list',
        sortField: sortField.value,
        sortOrder: sortOrder.value,
      };
    });

    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/recruitment/candidates',
    );

    const {
      showPaginator,
      currentPage,
      total,
      pages,
      pageSize,
      response,
      isLoading,
      execQuery,
    } = usePaginate(http, {
      query: serializedFilters,
      normalizer: candidateDataNormalizer,
      pageSize: 10,
    });

    onSort(execQuery);

    // Muat peta status Task Acumatica sekali, lalu jalankan ulang query supaya
    // baris tabel langsung membawa kolom "Acumatica Task Status" (tanpa login Acumatica).
    const loadAcumaticaTaskStatuses = async () => {
      try {
        const rawBase = String(window.appGlobal?.baseUrl || '');
        const baseUrl = rawBase
          .replace(/\/index\.php.*$/i, '')
          .replace(/\/$/, '');
        const res = await fetch(
          `${baseUrl}/proxy/get_candidates_task_status.php`,
          {headers: {Accept: 'application/json'}},
        );
        const data = await res.json();
        if (data && data.status === 'ok') {
          acumaticaTaskMap.value = data.data || {};
          execQuery();
        }
      } catch (e) {
        // diamkan: kolom tampil '-' kalau endpoint tidak bisa dihubungi
      }
    };
    // Tarik status Task Acumatica otomatis saat halaman dibuka/di-refresh,
    // tanpa perlu klik tombol. Backend membatasi maksimal 1x per 60 detik dan
    // memakai sesi bersama, jadi tidak membanjiri Acumatica.
    const autoSyncAcumatica = async () => {
      const rows = (response.value && response.value.data) || [];
      const ids = rows.map((row) => row && row.id).filter(Boolean);
      try {
        const rawBase = String(window.appGlobal?.baseUrl || '');
        const baseUrl = rawBase
          .replace(/\/index\.php.*$/i, '')
          .replace(/\/$/, '');
        const idParam = ids.length ? `&candidate_ids=${ids.join(',')}` : '';
        const res = await fetch(
          `${baseUrl}/proxy/sync_candidates_task_status.php?auto=1${idParam}`,
          {headers: {Accept: 'application/json'}},
        );
        const data = await res.json();
        // Bantuan diagnosa: buka DevTools -> Console
        console.info(
          '[Acumatica] auto-sync:',
          (data && data.status) || 'no-response',
          (data && data.reason) ||
            `${((data && data.updated) || []).length} kandidat`,
        );
        if (!data || data.status !== 'ok') return;

        const merged = {...acumaticaTaskMap.value};
        (data.updated || []).forEach((item) => {
          merged[String(item.candidate_id)] = item.task_status || '';
        });
        acumaticaTaskMap.value = merged;
        execQuery();

        const changed = (data.updated || []).filter(
          (item) => item.cv_action && item.cv_action !== 'none',
        );
        if (changed.length) {
          const rejected = changed.filter(
            (i) => i.cv_action === 'rejected',
          ).length;
          const restored = changed.filter(
            (i) => i.cv_action === 'restored',
          ).length;
          const parts = [];
          if (rejected) parts.push(`${rejected} kandidat di-Reject`);
          if (restored) parts.push(`${restored} kandidat dipulihkan`);
          const toast = useToast();
          toast.success({
            title: 'Status kandidat diperbarui',
            message: `${parts.join(', ')} (Task Acumatica).`,
          });
        }
      } catch (e) {
        // diamkan: kolom tetap menampilkan data terakhir dari DB
      }
    };
    onMounted(async () => {
      // Penting: tunggu query tabel selesai dulu, kalau tidak `rows` masih kosong
      // dan auto-sync tidak tahu kandidat mana yang harus ditarik.
      await execQuery();
      await loadAcumaticaTaskStatuses();
      autoSyncAcumatica();
    });

    return {
      http,
      showPaginator,
      currentPage,
      isLoading,
      total,
      pages,
      pageSize,
      execQuery,
      // dipakai juga oleh tombol Sync Acumatica per baris (update peta status)
      acumaticaTaskMap,
      items: response,
      filters,
      sortDefinition,
      rules,
    };
  },
  data() {
    return {
      // true saat tombol toolbar "Sync Acumatica" sedang berjalan
      batchSyncing: false,
      headers: [
        {
          name: 'vacancy',
          title: this.$t('recruitment.vacancy'),
          sortField: 'vacancy.name',
          style: {flex: 1},
        },
        {
          name: 'candidate',
          slot: 'title',
          title: this.$t('recruitment.candidate'),
          sortField: 'candidate.lastName',
          style: {flex: 1},
        },
        {
          name: 'manager',
          title: this.$t('recruitment.hiring_manager'),
          sortField: 'hiringManager.lastName',
          style: {flex: 1},
        },
        {
          name: 'dateOfApplication',
          title: this.$t('recruitment.date_of_application'),
          sortField: 'candidate.dateOfApplication',
          style: {flex: 1},
        },
        {
          name: 'status',
          title: this.$t('general.status'),
          sortField: 'candidateVacancy.status',
          style: {flex: 1},
          cellRenderer: this.cellRendererStatus,
        },
        {
          // Status Task Acumatica (tab Activities). Sumber: DB lokal, diisi saat
          // Sync Application to Acumatica atau tombol Refresh di profil kandidat.
          name: 'acumaticaTaskStatus',
          title: 'Acumatica Task Status',
          style: {flex: 1},
          cellRenderer: this.cellRendererAcumaticaTask,
        },
        {
          name: 'actions',
          slot: 'action',
          title: this.$t('general.actions'),
          style: {flex: 1},
          cellType: 'oxd-table-cell-actions',
          cellRenderer: this.cellRenderer,
        },
      ],
    };
  },
  methods: {
    cellRendererAcumaticaTask(...[, , , row]) {
      return {
        component: AcumaticaTaskStatusCell,
        props: {label: (row && row.acumaticaTaskStatus) || ''},
      };
    },
    cellRendererStatus(...[, , , row]) {
      return {
        component: CandidateStatusBadge,
        props: {label: row.status, statusId: row.statusId},
      };
    },
    cellRenderer(...[, , , row]) {
      const cellConfig = {
        view: {
          onClick: this.onClickEdit,
          props: {
            name: 'eye-fill',
          },
        },
      };
      if (row.resume) {
        cellConfig.download = {
          onClick: this.onDownload,
          props: {
            name: 'download',
          },
        };
      }
      return {
        props: {
          header: {
            cellConfig,
          },
        },
      };
    },
    onClickAdd() {
      navigate('/recruitment/addCandidate');
    },
    onClickEdit(item) {
      navigate('/recruitment/addCandidate/{id}', {id: item.id});
    },
    onDownload(item) {
      if (!item?.id) return;
      const fileUrl = 'recruitment/viewCandidateAttachment/candidateId';
      const downUrl = `${window.appGlobal.baseUrl}/${fileUrl}/${item.id}`;
      window.open(downUrl, '_blank');
    },
    // Tombol toolbar "Sync Acumatica": tarik status Task semua kandidat di tabel
    // dengan SATU login Acumatica (endpoint batch), lalu refresh tabel.
    async onSyncAllAcumatica() {
      if (this.batchSyncing) return;
      // items di halaman ini berbentuk {data: [...], meta: {...}} (lihat usePaginate),
      // bukan array langsung -> jangan pakai Array.isArray(this.items).
      const rows = (this.items && this.items.data) || [];
      const ids = rows.map((r) => r && r.id).filter(Boolean);
      if (!ids.length) {
        if (this.$refs.syncDialog) {
          this.$refs.syncDialog.showDialog({
            ok: false,
            message: 'Tidak ada kandidat di halaman ini.',
          });
        }
        return;
      }

      this.batchSyncing = true;
      try {
        const rawBase = String(window.appGlobal?.baseUrl || '');
        const baseUrl = rawBase
          .replace(/\/index\.php.*$/i, '')
          .replace(/\/$/, '');
        const res = await fetch(
          `${baseUrl}/proxy/sync_candidates_task_status.php?candidate_ids=${ids.join(
            ',',
          )}`,
          {headers: {Accept: 'application/json'}},
        );
        const data = await res.json();
        const nameById = {};
        rows.forEach((r) => {
          nameById[String(r.id)] = r.candidate || '';
        });

        if (data && data.status === 'ok') {
          const merged = {...this.acumaticaTaskMap};
          (data.updated || []).forEach((item) => {
            merged[String(item.candidate_id)] = item.task_status || '';
          });
          this.acumaticaTaskMap = merged;
          await this.execQuery();

          const dialogRows = [
            ...(data.updated || []).map((item) => ({
              name:
                item.summary ||
                nameById[String(item.candidate_id)] ||
                `#${item.candidate_id}`,
              status: item.task_status || '-',
              ok: true,
              cvAction: item.cv_action || '',
              cvFrom: item.cv_from || '',
              cvTo: item.cv_to || '',
            })),
            ...(data.failed || []).map((item) => ({
              name:
                nameById[String(item.candidate_id)] || `#${item.candidate_id}`,
              message: item.message || 'Dilewati',
              ok: false,
            })),
          ];
          if (this.$refs.syncDialog) {
            this.$refs.syncDialog.showDialog({ok: true, rows: dialogRows});
          }
        } else {
          const message =
            (data && data.message) || 'Tidak ada respons dari server.';
          if (this.$refs.syncDialog) {
            this.$refs.syncDialog.showDialog({ok: false, message});
          } else if (this.$toast) {
            this.$toast.error({title: 'Sync Acumatica gagal', message});
          }
        }
      } catch (e) {
        const message = 'Gagal menghubungi server.';
        if (this.$refs.syncDialog) {
          this.$refs.syncDialog.showDialog({ok: false, message});
        } else if (this.$toast) {
          this.$toast.error({title: 'Sync Acumatica gagal', message});
        }
      } finally {
        this.batchSyncing = false;
      }
    },
    async filterItems() {
      await this.execQuery();
    },
    onReset() {
      this.filters = {...defaultFilters};
      this.filterItems();
    },
  },
};
</script>
