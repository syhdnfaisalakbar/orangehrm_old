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
  <div class="orangehrm-background-container">
    <oxd-table-filter :filter-title="$t('general.vacancies')">
      <oxd-form @submit-valid="filterItems">
        <oxd-form-row>
          <oxd-grid :cols="3" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <jobtitle-dropdown v-model="filters.jobTitleId" />
            </oxd-grid-item>
            <oxd-grid-item>
              <vacancy-dropdown
                v-model="filters.vacancyId"
                :label="$t('recruitment.vacancy')"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <hiring-manager-dropdown v-model="filters.hiringManagerId" />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.status"
                type="select"
                :label="$t('general.status')"
                :clear="false"
                :options="statusOptions"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.department"
                type="select"
                :label="'Department'"
                :clear="false"
                :options="departmentOptions"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>

        <oxd-divider />

        <oxd-form-actions>
          <oxd-button
            display-type="ghost"
            :label="$t('general.reset')"
            @click="onClickReset"
          />
          <oxd-button
            class="orangehrm-left-space"
            display-type="secondary"
            :label="$t('general.search')"
            type="submit"
          />
        </oxd-form-actions>
      </oxd-form>
    </oxd-table-filter>
    <br />
    <div class="orangehrm-paper-container">
      <div class="orangehrm-header-container">
        <!-- 👇 TAMBAHAN TOMBOL SYNC ACUMATICA 👇 -->
        <oxd-button
          class="orangehrm-left-space"
          :label="isSyncing ? 'Syncing...' : 'Sync Acumatica'"
          display-type="secondary"
          :disabled="isSyncing"
          style="
            background-color: #ffd22c;
            color: #333;
            border: none;
            font-weight: bold;
          "
          @click="syncAcumatica"
        />
        <!-- ==================================== -->
      </div>
      <table-header :loading="isLoading" :total="total"></table-header>
      <div class="orangehrm-container">
        <oxd-card-table
          v-model:order="sortDefinition"
          :headers="headers"
          :items="items?.data"
          :clickable="false"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
          class="orangehrm-vacancy-list"
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
    <confirmation-dialog
      ref="rejectDialog"
      :title="'Konfirmasi Reject'"
      :subtitle="'Vacancy ini akan di-Reject. Status vacancy & Manpower Request terkait akan berubah menjadi Rejected, dan jumlah posisi / total person menjadi 0.'"
      :cancel-label="'Batal'"
      :confirm-label="'Reject'"
      confirm-button-type="label-danger"
      icon="x-circle"
    ></confirmation-dialog>
  </div>
</template>

<script>
import {computed, ref, watch, onMounted} from 'vue';
import usePaginate from '@ohrm/core/util/composable/usePaginate';
import {navigate} from '@ohrm/core/util/helper/navigation';
import {APIService} from '@/core/util/services/api.service';
import useSort from '@ohrm/core/util/composable/useSort';
import usei18n from '@/core/util/composable/usei18n';
import useEmployeeNameTranslate from '@/core/util/composable/useEmployeeNameTranslate';
import JobtitleDropdown from '@/orangehrmPimPlugin/components/JobtitleDropdown';
import ConfirmationDialog from '@/core/components/dialogs/ConfirmationDialog';
import VacancyDropdown from '@/orangehrmRecruitmentPlugin/components/VacancyDropdown.vue';
import HiringManagerDropdown from '@/orangehrmRecruitmentPlugin/components/HiringManagerDropdown';
import VacancyStatusBadge from '@/orangehrmRecruitmentPlugin/components/VacancyStatusBadge.vue';
import RequestTypeBadge from '@/orangehrmAdminPlugin/components/RequestTypeBadge.vue';
import VacancyActionCell from '@/orangehrmRecruitmentPlugin/components/VacancyActionCell.vue';
import Swal from 'sweetalert2';

const defaultFilters = {
  jobTitleId: null,
  hiringManagerId: null,
  vacancyId: null,
  status: null,
  department: null,
};
const defaultSortOrder = {
  'vacancy.name': 'ASC',
  'vacancy.status': 'DEFAULT',
  'jobTitle.jobTitleName': 'DEFAULT',
  'hiringManager.lastName': 'DEFAULT',
};
export default {
  name: 'ViewJobVacancy',
  components: {
    'confirmation-dialog': ConfirmationDialog,
    'jobtitle-dropdown': JobtitleDropdown,
    'vacancy-dropdown': VacancyDropdown,
    'hiring-manager-dropdown': HiringManagerDropdown,
  },

  setup() {
    const {$t} = usei18n();
    const {$tEmpName} = useEmployeeNameTranslate();
    const filters = ref({...defaultFilters});
    const {sortDefinition, sortField, sortOrder, onSort} = useSort({
      sortDefinition: defaultSortOrder,
    });

    const serializedFilters = computed(() => {
      const statusId = filters.value.status?.id;
      let status = null;
      let rejected = null;
      if (statusId === 'rejected') {
        // Rejected eksklusif: cuma yang di-reject
        rejected = true;
      } else if (typeof statusId === 'boolean') {
        // Active / Closed — Closed eksklusif: exclude yang di-reject
        status = statusId;
        rejected = false;
      }
      return {
        vacancyId: filters.value.vacancyId?.id,
        jobTitleId: filters.value.jobTitleId?.id,
        hiringManagerId: filters.value.hiringManagerId?.id,
        status,
        rejected,
        department: filters.value.department?.id,
        sortField: sortField.value,
        sortOrder: sortOrder.value,
        model: 'detailed',
      };
    });

    const requestTypeMap = ref({});

    const loadRequestTypes = async () => {
      if (!response.value?.data || response.value.data.length === 0) return;
      const ids = response.value.data.map((item) => item.id);
      try {
        const baseUrl =
          window.appGlobal?.baseUrl?.replace('/index.php', '') || '';
        const res = await fetch(
          baseUrl +
            '/proxy/get_request_type.php?vacancy_ids[]=' +
            ids.join('&vacancy_ids[]='),
        );
        const result = await res.json();
        if (result && result.status === 'ok' && result.data) {
          const map = result.data || {};
          if (response.value?.data) {
            response.value.data = response.value.data.map((item) => ({
              ...item,
              requestType: map[item.id] || '-',
            }));
          }
        }
      } catch (e) {
        // silent
      }
    };

    const unwatch = watch(
      () => response.value?.data,
      () => {
        loadRequestTypes();
      },
    );

    // Panggil loadRequestTypes setiap kali mounted
    onMounted(() => {
      setTimeout(() => {
        loadRequestTypes();
      }, 500);
    });

    const userdataNormalizer = (data) => {
      return data.map((item) => {
        return {
          id: item.id,
          caseId: item.case_id || '-',
          vacancy: item.name,
          jobTitle: item.jobTitle?.isDeleted
            ? item.jobTitle.title + $t('general.deleted')
            : item.jobTitle?.title,

          hiringManager: item.hiringManager?.id
            ? $tEmpName(item.hiringManager)
            : $t('general.deleted'),
          // rejected dibaca langsung dari API (VacancyDetailedModel expose
          // kolom rejected) — bukan dari proxy terpisah, jadi tidak ada race.
          status: item.rejected
            ? 'Rejected'
            : item.status
            ? $t('general.active')
            : $t('general.closed'),
          statusRaw: item.rejected ? false : !!item.status,
          requestType: item.reqType || '-',
        };
      });
    };

    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/recruitment/vacancies',
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
      normalizer: userdataNormalizer,
      pageSize: 10,
    });
    onSort(execQuery);

    return {
      http,
      showPaginator,
      currentPage,
      isLoading,
      total,
      pages,
      pageSize,
      execQuery,
      items: response,
      filters,
      sortDefinition,
      requestTypeMap,
      loadRequestTypes,
    };
  },

  data() {
    return {
      // 👇 Tambahkan baris ini 👇
      isSyncing: false,
      headers: [
        {
          name: 'vacancy',
          slot: 'title',
          title: this.$t('recruitment.vacancy'),
          sortField: 'vacancy.name',
          style: {flex: 3},
        },
        {
          name: 'jobTitle',
          title: this.$t('general.job_title'),
          sortField: 'jobTitle.jobTitleName',
          style: {flex: 3},
        },
        {
          name: 'requestType',
          title: 'Request Type',
          style: {flex: 1.5},
          cellRenderer: this.cellRendererReqType,
        },
        {
          name: 'hiringManager',
          title: this.$t('recruitment.hiring_manager'),
          sortField: 'hiringManager.lastName',
          style: {flex: 3},
        },
        {
          name: 'status',
          title: this.$t('general.status'),
          sortField: 'vacancy.status',
          style: {flex: 2},
          cellRenderer: this.cellRenderer,
        },
        {
          name: 'actions',
          title: this.$t('general.actions'),
          style: {flex: 2},
          cellRenderer: this.cellRendererActions,
        },
      ],
      statusOptions: [
        {id: true, param: 'active', label: this.$t('general.active')},
        {id: false, param: 'closed', label: this.$t('general.closed')},
        {id: 'rejected', param: 'rejected', label: 'Rejected'},
      ],
      departmentOptions: [],
      vacancies: [],
    };
  },

  mounted() {
    this.loadDepartments();
  },

  methods: {
    // Load daftar department untuk dropdown filter (dari proxy lokal)
    async loadDepartments() {
      try {
        const res = await fetch(
          this.getBaseUrl() + '/proxy/get_vacancy_departments.php',
        );
        const result = await res.json();
        if (result && result.status === 'ok' && Array.isArray(result.data)) {
          this.departmentOptions = result.data.map((dept) => ({
            id: dept,
            label: dept,
          }));
        }
      } catch (e) {
        console.warn('Gagal load departments:', e);
      }
    },

    // Render badge warna untuk kolom status (Active = biru, Closed = hijau)
    cellRenderer(...args) {
      const row = args[3];
      return {
        component: VacancyStatusBadge,
        props: {
          status: row.status,
          active: row.statusRaw,
        },
      };
    },

    // Render badge abu-abu untuk kolom Request Type (Planned/Replacement/Unplanned)
    cellRendererReqType(...args) {
      const row = args[3];
      return {
        component: RequestTypeBadge,
        props: {
          reqType: row.requestType,
        },
      };
    },

    // Kolom action: Reject hanya tampil kalau vacancy masih Active
    cellRendererActions(...args) {
      const row = args[3];
      return {
        component: VacancyActionCell,
        props: {
          row,
          onReject: this.onClickReject,
          onEdit: this.onClickEdit,
        },
      };
    },

    // ==========================================
    // 1. FUNGSI HELPER & LOGIN/LOGOUT ACUMATICA
    // ==========================================
    getBaseUrl() {
      const base = window.appGlobal?.baseUrl || '';
      return base.replace('/index.php', '') || '';
    },

    async loginAcumatica() {
      const proxyUrl = this.getBaseUrl() + '/proxy/acumatica.php?action=login';
      const payload = {}; // kredensial Acumatica diambil dari web/proxy/config.php (server-side)

      const response = await fetch(proxyUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        throw new Error(`Login Acumatica Gagal! Status: ${response.status}`);
      }
    },

    async logoutAcumatica() {
      const proxyUrl = this.getBaseUrl() + '/proxy/acumatica.php?action=logout';
      try {
        await fetch(proxyUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
        });
      } catch (err) {
        console.warn('Gagal melakukan logout otomatis:', err);
      }
    },

    // ==========================================
    // 2. FUNGSI UTAMA SYNC ACUMATICA
    // ==========================================
    async syncAcumatica() {
      this.isSyncing = true;

      try {
        // 1. Login ke Acumatica
        await this.loginAcumatica();

        const baseUrl = this.getBaseUrl();

        // FULL SYNC (2026-09-14): tarik SEMUA case REQFPTK (Open/Closed) tiap klik.
        // Sebelumnya inkremental + window mundur ('CaseID gt max-5') → case
        // dengan CaseID pas di/persis di ambang window (mis. 0000008626)
        // tidak pernah ditarik → tidak jadi vacancy. Sekarang dijamin ditarik.
        // Aman dr duplikat: save_job_vacancy.php deteksi by name + gate approval
        // (hanya approval Completed/Approved yang jadi vacancy). Trade-off: tiap
        // klik tarik semua case (bisa lambat kalau case sangat banyak).
        let gtFilter = '';

        // 2. Tarik data Case REQFPTK (Manpower Request) dari Acumatica
        const proxyUrl =
          baseUrl +
          "/proxy/acumatica.php?action=query&endpoint=Case&$filter=(Status eq 'Open' or Status eq 'Closed') and ClassID eq 'REQFPTK'" +
          gtFilter +
          '&$expand=Attributes';

        const response = await fetch(proxyUrl, {
          method: 'GET',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
        });

        if (!response.ok) {
          throw new Error(`Terjadi kesalahan HTTP! status: ${response.status}`);
        }

        const result = await response.json();
        const rawData = Array.isArray(result) ? result : result.value || [];

        // 3. Mapping data dari struktur Acumatica ke struktur OrangeHRM
        const mappedItems = rawData.map((item) => {
          const attributes = item.Attributes || [];

          // Helper untuk mengambil value atribut
          const getAttrValue = (attrId) => {
            const searchId = attrId.toLowerCase();
            const found = attributes.find(
              (a) => a.AttributeID?.value?.toLowerCase() === searchId,
            );
            return found
              ? found.ValueDescription?.value || found.Value?.value
              : null;
          };

          // Mapping Attributes
          const positionAttr =
            getAttrValue('position') || getAttrValue('positiontitle') || '';
          const vacancyName = positionAttr || 'Unknown Vacancy';
          const positionsCount = parseInt(getAttrValue('totperson') || 1, 10);
          const description = getAttrValue('jobdesc') || '-';
          const reqType = getAttrValue('reqtype') || '-';
          const statusVal =
            item.Status?.value?.toLowerCase() === 'open' ? 1 : 2;

          // Job Title: ambil dari attribute POSITION → PHP cek/auto-insert ohrm_job_title
          const jobTitleText = positionAttr || '-';
          // Hiring Manager: ambil dari Owner Acumatica → PHP cek hs_hr_employee,
          // kalau nggak ketemu → fallback karyawan pertama (DAVID UTOMO)
          const managerText =
            item.OwnerEmployeeName?.value || item.Owner?.value || '-';

          const rawCaseId = item.CaseID?.value || '-';

          return {
            caseId: rawCaseId,
            name: `${rawCaseId} - ${vacancyName}`,
            description: description,
            no_of_positions: isNaN(positionsCount) ? 1 : positionsCount,
            status: statusVal,
            reqType: reqType,
            jobTitleText: jobTitleText, // <- Ini wajib agar PHP bisa Lookup/Auto-Create
            managerText: managerText, // <- Ini wajib agar PHP bisa Lookup Hiring Manager
          };
        });

        // 4. Siapkan payload untuk disimpan ke database lokal
        // 4. Siapkan payload untuk disimpan ke database lokal
        const payloadToSave = {
          casesDetail: mappedItems,
        };

        if (payloadToSave.casesDetail.length > 0) {
          // 5. Kirim data ke backend PHP lokal
          const saveResponse = await fetch(
            baseUrl + '/proxy/save_job_vacancy.php',
            {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
              },
              body: JSON.stringify(payloadToSave),
            },
          );

          const saveResult = await saveResponse.json();
          console.log('Status Simpan DB Lokal:', saveResult);

          if (saveResult.status === 'error') {
            Swal.fire({
              title: 'Gagal',
              icon: 'error',
              text: 'Gagal simpan ke database lokal: ' + saveResult.message,
            });
          }

          // Notif kalau ada case yang approval manpower request-nya masih Open
          if (saveResult.open_approval && saveResult.open_approval.length > 0) {
            Swal.fire({
              title: 'Perhatian',
              icon: 'warning',
              html: `Approval manpower request masih Open untuk: ${saveResult.open_approval.join(
                ', ',
              )}.<br>Vacancy TIDAK disimpan.`,
            });
          }
        }

        Swal.fire({
          title: 'Sync Berhasil',
          icon: 'success',
          html: `Mendapatkan ${mappedItems.length} data Job Vacancy.`,
        });

        // 6. Refresh tabel Vacancy di layar (Cara yang benar untuk Vue.js)
        await this.resetDataTable();
      } catch (error) {
        console.error('Gagal melakukan sync:', error);
        Swal.fire({
          title: 'Gagal',
          icon: 'error',
          html: `Gagal mengambil data: ${error.message}. Silakan periksa console browser.`,
        });
      } finally {
        // 7. Selalu lakukan logout di akhir proses.
        // Ini dijamin akan tereksekusi dengan baik jika tidak ada window.location.reload()
        await this.logoutAcumatica();
        this.isSyncing = false;
      }
    },

    // ===================================
    // 3. FUNGSI BAWAAN ORANGEHRM
    // ===================================
    onClickAdd() {
      navigate('/recruitment/addJobVacancy');
    },
    onClickEdit(item) {
      navigate('/recruitment/addJobVacancy/{id}', {id: item.id});
    },
    onClickReject(item) {
      this.$refs.rejectDialog.showDialog().then((confirmation) => {
        if (confirmation === 'ok') {
          this.rejectVacancy(item.id);
        }
      });
    },
    async rejectVacancy(id) {
      this.isLoading = true;
      try {
        const baseUrl = this.getBaseUrl();
        const res = await fetch(baseUrl + '/proxy/reject_vacancy.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({vacancy_id: id}),
        });
        const result = await res.json();
        if (result.status === 'ok') {
          if (this.$toast) {
            this.$toast.success({
              title: 'Reject Success',
              message: result.message,
            });
          } else {
            Swal.fire({
              title: 'Reject Success',
              icon: 'success',
              text: result.message,
            });
          }
        } else {
          if (this.$toast) {
            this.$toast.error({
              title: 'Reject Gagal',
              message: result.message || 'Unknown error',
            });
          } else {
            Swal.fire({
              title: 'Reject Gagal',
              icon: 'error',
              text: result.message || 'Unknown error',
            });
          }
        }
        await this.resetDataTable();
      } catch (error) {
        console.error('Gagal reject vacancy:', error);
        Swal.fire({
          title: 'Reject Error',
          icon: 'error',
          text: error.message,
        });
      } finally {
        this.isLoading = false;
      }
    },
    async resetDataTable() {
      await this.execQuery();
      setTimeout(() => {
        if (this.loadRequestTypes) this.loadRequestTypes();
        if (this.loadDepartments) this.loadDepartments();
      }, 300);
    },
    async filterItems() {
      await this.execQuery();
    },
    onClickReset() {
      this.filters = {...defaultFilters};
      this.filterItems();
    },
  },
};
</script>

<style src="./vacancy.scss" lang="scss" scoped></style>
