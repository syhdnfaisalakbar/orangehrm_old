<template>
  <div class="orangehrm-background-container">
    <!-- Form Pencarian (Case ID & Status) -->
    <oxd-table-filter :filter-title="'Manpower Request (FPTK)'">
      <oxd-form @submit-valid="filterItems">
        <oxd-form-row>
          <oxd-grid :cols="5" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.caseId"
                :label="'Case ID'"
                placeholder="Ex: 0000003655"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.approvalStatus"
                type="select"
                :label="'Status Approval'"
                :clear="false"
                :options="approvalStatusOptions"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.reqType"
                type="select"
                :label="'Request Type'"
                :clear="false"
                :options="reqTypeOptions"
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
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.branch"
                type="select"
                :label="'Branch'"
                :clear="false"
                :options="branchOptions"
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

    <!-- Area Konten Utama -->
    <div class="orangehrm-paper-container">
      <!-- Tombol Sync Acumatica (posisi seperti Recruitment → Vacancies) -->
      <div class="orangehrm-header-container">
        <oxd-button
          class="oxd-btn--loading"
          display-type="secondary"
          :label="isLoading ? 'Syncing...' : 'Sync Acumatica'"
          :icon-name="isLoading ? 'bi-arrow-repeat' : null"
          :disabled="isLoading"
          @click="syncAcumatica"
        />
      </div>

      <!-- Komponen Table Header -->
      <table-header
        :selected="checkedItems.length"
        :total="totalData"
        :loading="isLoading"
        @delete="onClickDeleteSelected"
      ></table-header>

      <!-- Komponen Tabel Utama -->
      <div class="orangehrm-container">
        <oxd-card-table
          v-model:selected="checkedItems"
          :headers="headers"
          :items="items"
          :selectable="true"
          :clickable="false"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
        />
      </div>

      <!-- Area Bawah Tabel: Pagination (kiri) + Sync Acumatica (kanan) -->
      <div
        class="orangehrm-bottom-container"
        style="
          padding: 1.5rem 1rem;
          display: flex;
          justify-content: space-between;
          align-items: center;
        "
      >
        <oxd-pagination
          v-if="pages > 1"
          v-model:current="currentPage"
          :length="pages"
        />
      </div>
    </div>

    <!-- ============================================== -->
    <!-- MODAL POPUP DETAIL CASE & APPROVAL             -->
    <!-- ============================================== -->
    <div
      v-if="showModal"
      style="
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.6);
        z-index: 9999;
        display: flex;
        justify-content: center;
        align-items: center;
      "
    >
      <!-- Kotak Modal (Warna Putih Solid) -->
      <div
        style="
          background-color: #ffffff;
          width: 650px;
          max-width: 95%;
          max-height: 90vh;
          border-radius: 8px;
          box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
          display: flex;
          flex-direction: column;
          overflow: hidden;
          position: relative;
        "
      >
        <!-- Header Modal -->
        <div
          style="
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background-color: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
          "
        >
          <h6 class="oxd-text oxd-text--h6" style="margin: 0">
            Detail Case:
            <span style="color: #ff7b1c; font-weight: bold">{{
              selectedCase?.caseId
            }}</span>
          </h6>
          <!-- Tombol Close (X) -->
          <button
            style="
              background: none;
              border: none;
              cursor: pointer;
              font-size: 1.5rem;
              color: #64748b;
              line-height: 1;
              padding: 0;
            "
            @click="closeModal"
          >
            &times;
          </button>
        </div>

        <!-- Body Modal (Bisa di-scroll jika panjang) -->
        <div style="padding: 1.5rem; overflow-y: auto; flex-grow: 1">
          <!-- Bagian 1: Attributes -->
          <h6
            class="oxd-text oxd-text--h6"
            style="margin-bottom: 10px; font-size: 14px"
          >
            Case Attributes
          </h6>
          <table
            class="oxd-table"
            style="
              width: 100%;
              margin-bottom: 25px;
              border: 1px solid #e2e8f0;
              border-radius: 4px;
            "
          >
            <tbody v-if="selectedCase?.attributes?.length > 0">
              <tr
                v-for="(attr, index) in selectedCase.attributes"
                :key="index"
                style="border-bottom: 1px solid #e2e8f0"
              >
                <td
                  style="
                    padding: 10px;
                    font-weight: bold;
                    width: 40%;
                    background-color: #f8fafc;
                  "
                >
                  {{ attr.AttributeID?.value || '-' }}
                </td>
                <td style="padding: 10px">
                  {{ attr.ValueDescription?.value || attr.Value?.value || '-' }}
                </td>
              </tr>
            </tbody>
            <tbody v-else>
              <tr>
                <td style="padding: 10px; text-align: center; color: #64748b">
                  Tidak ada atribut untuk Case ini.
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Bagian 2: Approval Tasks -->
          <h6
            class="oxd-text oxd-text--h6"
            style="margin-bottom: 10px; font-size: 14px"
          >
            Approval Tasks
          </h6>

          <div
            v-if="isLoadingTasks"
            style="text-align: center; padding: 20px; color: #64748b"
          >
            Memuat data Approval...
          </div>

          <template v-if="approvalTasks.length > 0">
            <div
              v-for="(task, index) in approvalTasks"
              :key="index"
              style="margin-bottom: 15px"
            >
              <div
                v-if="approvalTasks.length > 1"
                style="
                  font-size: 12px;
                  color: #64748b;
                  margin-bottom: 5px;
                  font-weight: bold;
                "
              >
                Task {{ index + 1 }}
              </div>
              <table
                class="oxd-table"
                style="
                  width: 100%;
                  border: 1px solid #e2e8f0;
                  border-radius: 4px;
                "
              >
                <tbody>
                  <tr style="border-bottom: 1px solid #e2e8f0">
                    <td
                      style="
                        padding: 10px;
                        font-weight: bold;
                        width: 40%;
                        background-color: #f8fafc;
                      "
                    >
                      Summary
                    </td>
                    <td style="padding: 10px">
                      {{ task.Summary?.value || '-' }}
                    </td>
                  </tr>
                  <tr style="border-bottom: 1px solid #e2e8f0">
                    <td
                      style="
                        padding: 10px;
                        font-weight: bold;
                        width: 40%;
                        background-color: #f8fafc;
                      "
                    >
                      Owner
                    </td>
                    <td style="padding: 10px">
                      {{ getTaskOwnerName(task) }}
                    </td>
                  </tr>
                  <tr style="border-bottom: 1px solid #e2e8f0">
                    <td
                      style="
                        padding: 10px;
                        font-weight: bold;
                        width: 40%;
                        background-color: #f8fafc;
                      "
                    >
                      Priority
                    </td>
                    <td style="padding: 10px">
                      {{ task.Priority?.value || '-' }}
                    </td>
                  </tr>
                  <tr style="border-bottom: 1px solid #e2e8f0">
                    <td
                      style="
                        padding: 10px;
                        font-weight: bold;
                        width: 40%;
                        background-color: #f8fafc;
                      "
                    >
                      Status
                    </td>
                    <td style="padding: 10px">
                      <span
                        style="
                          background-color: #e6f4ea;
                          color: #1e8e3e;
                          padding: 2px 8px;
                          border-radius: 12px;
                          font-size: 12px;
                          font-weight: bold;
                        "
                      >
                        {{ task.Status?.value || '-' }}
                      </span>
                    </td>
                  </tr>
                  <tr>
                    <td
                      style="
                        padding: 10px;
                        font-weight: bold;
                        width: 40%;
                        background-color: #f8fafc;
                      "
                    >
                      Completed On
                    </td>
                    <td style="padding: 10px">
                      {{ formatApprovalDate(task) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
          <div v-else style="padding: 10px; text-align: center; color: #64748b">
            Tidak ada Approval Task yang Open.
          </div>
        </div>

        <!-- Footer Modal -->
        <div
          style="
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            background-color: #f8fafc;
            text-align: right;
          "
        >
          <oxd-button display-type="ghost" label="Tutup" @click="closeModal" />
        </div>
      </div>
    </div>

    <!-- ============================================== -->
    <!-- MODAL POPUP HISTORY APPROVAL TASK              -->
    <!-- ============================================== -->
    <div
      v-if="showHistoryModal"
      style="
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.6);
        z-index: 10000;
        display: flex;
        justify-content: center;
        align-items: center;
      "
    >
      <div
        style="
          background-color: #ffffff;
          width: 680px;
          max-width: 95%;
          max-height: 88vh;
          border-radius: 8px;
          box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
          display: flex;
          flex-direction: column;
          overflow: hidden;
          position: relative;
        "
      >
        <div
          style="
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background-color: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
          "
        >
          <h6 class="oxd-text oxd-text--h6" style="margin: 0">
            History Approval Task:
            <span style="color: #ff7b1c; font-weight: bold">{{
              historyCase?.caseId
            }}</span>
          </h6>
          <button
            style="
              background: none;
              border: none;
              cursor: pointer;
              font-size: 1.5rem;
              color: #64748b;
              line-height: 1;
              padding: 0;
            "
            @click="closeHistoryModal"
          >
            &times;
          </button>
        </div>

        <div style="padding: 1.5rem; overflow-y: auto; flex-grow: 1">
          <div
            v-if="historyLoading"
            style="text-align: center; padding: 20px; color: #64748b"
          >
            Memuat riwayat...
          </div>
          <template v-else>
            <table
              v-if="historyList.length > 0"
              class="oxd-table"
              style="
                width: 100%;
                border: 1px solid #e2e8f0;
                border-radius: 4px;
                table-layout: fixed;
              "
            >
              <thead>
                <tr style="background-color: #f8fafc">
                  <th style="padding: 10px; text-align: left; width: 20%">
                    Waktu
                  </th>
                  <th style="padding: 10px; text-align: left; width: 14%">
                    Status
                  </th>
                  <th style="padding: 10px; text-align: left; width: 26%">
                    Owner
                  </th>
                  <th style="padding: 10px; text-align: left; width: 40%">
                    Summary
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="h in historyList"
                  :key="h.id"
                  style="border-bottom: 1px solid #e2e8f0"
                >
                  <td style="padding: 10px; white-space: nowrap; width: 20%">
                    {{ formatHistoryTime(h) }}
                  </td>
                  <td style="padding: 10px; width: 14%">
                    <span :style="statusBadgeStyle(h.status)">{{
                      h.status || '-'
                    }}</span>
                  </td>
                  <td style="padding: 10px; width: 26%">
                    {{
                      h.owner && h.owner !== '-'
                        ? h.owner
                        : 'Approver Not Found'
                    }}
                  </td>
                  <td style="padding: 10px; width: 40%">
                    {{ h.summary || '-' }}
                  </td>
                </tr>
              </tbody>
            </table>
            <div
              v-else
              style="padding: 10px; text-align: center; color: #64748b"
            >
              Belum ada riwayat perubahan approval task.
            </div>
          </template>
        </div>

        <div
          style="
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            background-color: #f8fafc;
            text-align: right;
          "
        >
          <oxd-button
            display-type="ghost"
            label="Tutup"
            @click="closeHistoryModal"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import VacancyStatusBadge from '@/orangehrmRecruitmentPlugin/components/VacancyStatusBadge.vue';
import RequestTypeBadge from '@/orangehrmAdminPlugin/components/RequestTypeBadge.vue';
import ApprovalTaskBadge from '@/orangehrmAdminPlugin/components/ApprovalTaskBadge.vue';
import Swal from 'sweetalert2';

export default {
  name: 'ViewManpowerRequest',
  data() {
    return {
      isSyncing: false,
      isLoading: false,
      searchQuery: '',
      checkedItems: [],
      totalData: 0,

      // --- STATE BARU UNTUK MODAL DETAIL ---
      showModal: false, // Untuk mengatur modal tampil/sembunyi
      isLoadingTasks: false, // Efek loading saat tarik data Approval
      selectedCase: null, // Menyimpan data case yang sedang di-klik
      approvalTasks: [], // Menyimpan daftar approval dari Acumatica
      // --- STATE UNTUK MODAL HISTORY APPROVAL TASK ---
      showHistoryModal: false,
      historyCase: null,
      historyList: [],
      historyLoading: false,

      // --- STATE FILTER PENCARIAN ---
      filters: {
        caseId: '',
        approvalStatus: null,
        reqType: null,
        department: null,
        branch: null,
      },
      reqTypeOptions: [
        {id: 'Planned', label: 'Planned'},
        {id: 'Replacement', label: 'Replacement'},
        {id: 'Unplanned', label: 'Unplanned'},
      ],
      departmentOptions: [], // Diisi dari data: All Departments + dept unik
      branchOptions: [], // Diisi dari data: All Branches + branch unik

      // Items dikosongkan secara default (menunggu disinkronisasi)
      allItems: [], // Master data dari DB (dipakai untuk filter)
      items: [], // Data yang tampil setelah difilter & dipaginasi
      filteredAll: [], // Hasil filter penuh (sebelum di-paginasi)
      currentPage: 1, // Halaman aktif (default 1)
      pageSize: 10, // Default 10 data per halaman

      headers: [
        {name: 'caseId', title: 'Case ID', style: {flex: 1}},
        {name: 'caseClass', title: 'Case Class', style: {flex: 1}},
        {
          name: 'status',
          title: 'Status',
          style: {flex: 1},
          cellRenderer: this.cellRenderer,
        },
        {name: 'reason', title: 'Reason', style: {flex: 1.5}},
        {
          name: 'reqType',
          title: 'Request Type',
          style: {flex: 1},
          cellRenderer: this.cellRendererReqType,
        },
        {name: 'owner', title: 'Owner', style: {flex: 1}},
        {
          name: 'approvalTask',
          title: 'Approval Task',
          style: {flex: 1.5},
          cellRenderer: this.cellRendererApproval,
        },
        // --- TAMBAHAN KOLOM ACTION ---
        {
          name: 'actions',
          slot: 'action',
          title: 'Actions',
          style: {flex: 0.5},
          cellType: 'oxd-table-cell-actions',
          cellConfig: {
            viewDetail: {
              onClick: this.onClickDetail,
              component: 'oxd-icon-button',
              props: {
                name: 'eye',
              },
            },
            history: {
              onClick: this.onOpenApprovalHistory,
              component: 'oxd-icon-button',
              props: {
                name: 'clock-history',
              },
            },
          },
        },
      ],
    };
  },
  computed: {
    // Jumlah halaman berdasarkan total data (filtered) / pageSize
    pages() {
      return Math.max(1, Math.ceil(this.totalData / this.pageSize));
    },
    // Opsi filter "Status Approval" dibangun DINAMIS dari data —
    // nilai unik status approval task TERAKHIR (row.lastApproval.status),
    // mengikuti pola Department/Branch. Robust terhadap status Acumatica
    // apa pun (Open, Completed, Canceled, Rejected, dll).
    approvalStatusOptions() {
      const set = new Set();
      this.allItems.forEach((r) => set.add(r.lastApproval?.status || '-'));
      return [...set]
        .sort((a, b) => a.localeCompare(b))
        .map((s) => ({
          id: s,
          label: s,
        }));
    },
  },
  watch: {
    // Begitu halaman diganti via oxd-pagination, potong ulang items
    currentPage() {
      this.applyPage();
    },
  },

  mounted() {
    this.fetchLocalData();
    setTimeout(() => {
      const breadcrumb = document.querySelector(
        '.oxd-topbar-header-breadcrumb',
      );
      if (breadcrumb) {
        breadcrumb.innerHTML = `
          <h4 class="oxd-text oxd-text--h6 oxd-topbar-header-breadcrumb-level">
            Manpower Request (FPTK)
          </h4>
        `;
      }

      document.title = 'Manpower Request (FPTK)';
      const allMenus = document.querySelectorAll('.oxd-main-menu-item');
      allMenus.forEach((menu) => {
        menu.classList.remove('active');
      });

      allMenus.forEach((menu) => {
        if (menu.textContent.trim() === 'Manpower Request (FPTK)') {
          menu.classList.add('active');
        }
      });
    }, 100);
  },
  methods: {
    // Render badge warna untuk kolom status (Open = biru, Closed = hijau)
    cellRenderer(...args) {
      const row = args[3];
      return {
        component: VacancyStatusBadge,
        props: {
          status: row.status,
        },
      };
    },

    // Render badge warna untuk kolom Request Type
    // (Planned = biru, Replacement = kuning, Unplanned = merah)
    cellRendererReqType(...args) {
      const row = args[3];
      return {
        component: RequestTypeBadge,
        props: {
          reqType: row.reqType,
        },
      };
    },

    // Render Approval Task TERAKHIR (badge status saja) — data dari
    // get_manpower_requests.php kolom lastApproval (task id terbesar).
    cellRendererApproval(...args) {
      const row = args[3];
      const la = row.lastApproval || {};
      return {
        component: ApprovalTaskBadge,
        props: {
          status: la.status || '-',
        },
      };
    },

    // Ambil nilai departemen dari attributes (get_manpower_requests.php tidak
    // mengirim kolom department di top-level — hanya di dalam attributes).
    // True kalau approval task TERAKHIR baris ini berstatus Open → baris boleh
    // di-delete-selected (hanya yg Open yang muncul checkbox-nya).
    isOpenApproval(row) {
      return (
        String(row?.lastApproval?.status || '')
          .trim()
          .toLowerCase() === 'open'
      );
    },

    getDeptFromItem(item) {
      const attrs = item.attributes || [];
      const found = attrs.find(
        (a) => (a.AttributeID?.value || '').toUpperCase() === 'DEPARTMENT',
      );
      return found
        ? (found.ValueDescription?.value || found.Value?.value || '').trim()
        : '';
    },

    getBranchFromItem(item) {
      const attrs = item.attributes || [];
      const found = attrs.find(
        (a) => (a.AttributeID?.value || '').toUpperCase() === 'BRANCH',
      );
      return found
        ? (found.ValueDescription?.value || found.Value?.value || '').trim()
        : '';
    },

    // Filter pencarian: Case ID (contains), Status (exact), Request Type (exact)
    applyFilters() {
      const q = String(this.filters.caseId || '')
        .trim()
        .toLowerCase();
      const approvalStatus = this.filters.approvalStatus?.id || null;
      const reqType = this.filters.reqType?.id || null;
      const dept = this.filters.department?.id || null;
      const branch = this.filters.branch?.id || null;

      let filtered = this.allItems;
      if (q) {
        filtered = filtered.filter((r) =>
          String(r.caseId || '')
            .toLowerCase()
            .includes(q),
        );
      }
      if (approvalStatus) {
        filtered = filtered.filter(
          (r) => String(r.lastApproval?.status || '-') === approvalStatus,
        );
      }
      if (reqType) {
        filtered = filtered.filter((r) => String(r.reqType || '') === reqType);
      }
      if (dept) {
        filtered = filtered.filter((r) => this.getDeptFromItem(r) === dept);
      }
      if (branch) {
        filtered = filtered.filter((r) => this.getBranchFromItem(r) === branch);
      }

      this.filteredAll = filtered;
      this.totalData = filtered.length;
      // Reset ke halaman 1 setiap filter berubah
      this.currentPage = 1;
      this.applyPage();
    },

    // Potong filteredAll sesuai halaman aktif (default 10 per halaman)
    applyPage() {
      const start = (this.currentPage - 1) * this.pageSize;
      this.items = this.filteredAll.slice(start, start + this.pageSize);
      // Delete-selected HANYA utk approval task yang masih Open (2026-09-14):
      // baris non-Open (Completed/Canceled/dll) di-set isSelectable=false →
      // OXD tidak menampilkan checkbox-nya (dibaca per-baris).
      this.items.forEach((r) => {
        r.isSelectable = this.isOpenApproval(r);
      });
    },

    filterItems() {
      this.applyFilters();
    },

    onClickReset() {
      this.filters = {
        caseId: '',
        approvalStatus: null,
        reqType: null,
        department: null,
        branch: null,
      };
      this.applyFilters();
    },

    async fetchLocalData() {
      this.isLoading = true;
      try {
        const baseUrl =
          window.appGlobal?.baseUrl?.replace('/index.php', '') || '';
        const response = await fetch(
          baseUrl + '/proxy/get_manpower_requests.php',
          {
            method: 'GET',
          },
        );

        const result = await response.json();

        if (result.status === 'success') {
          this.allItems = result.data;

          // Bangun opsi departemen dari data (dept unik, terurut A-Z) —
          // diambil dari attributes DEPARTMENT (bukan kolom top-level).
          const depts = [
            ...new Set(
              this.allItems
                .map((r) => this.getDeptFromItem(r))
                .filter((d) => d !== ''),
            ),
          ].sort((a, b) => a.localeCompare(b));
          this.departmentOptions = [
            {id: 'all', label: 'All Departments'},
            ...depts.map((d) => ({id: d, label: d})),
          ];

          // Bangun opsi branch dari data (branch unik, terurut A-Z)
          const branches = [
            ...new Set(
              this.allItems
                .map((r) => this.getBranchFromItem(r))
                .filter((b) => b !== ''),
            ),
          ].sort((a, b) => a.localeCompare(b));
          this.branchOptions = [
            {id: 'all', label: 'All Branches'},
            ...branches.map((b) => ({id: b, label: b})),
          ];

          this.applyFilters();
        } else {
          console.error('Gagal load data lokal:', result.message);
        }
      } catch (error) {
        console.error('Error saat fetch data lokal:', error);
      } finally {
        this.isLoading = false;
      }
    },
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

    async syncAcumatica() {
      this.isLoading = true;
      let skippedMessage = '';
      let overQuotaMessage = '';

      try {
        // Pastikan data lokal termuat SEBELUM menghitung window sync.
        // Tanpa ini (race: tombol diklik sebelum fetchLocalData() di mounted
        // selesai) allItems masih kosong → max=0 → filter CaseID gt tidak
        // dibuat → SEMUA case ditarik dari Acumatica.
        await this.fetchLocalData();
        // fetchLocalData() mereset isLoading ke false di finally-nya —
        // set ulang supaya tombol tetap menampilkan "Syncing..." + spinner.
        this.isLoading = true;

        await this.loginAcumatica();

        const baseUrl = this.getBaseUrl();

        // FULL SYNC (2026-09-14): tarik SEMUA case REQFPTK (New/Open/Closed) tiap
        // klik. Sebelumnya inkremental ('CaseID gt max lokal') → case yang
        // dihapus lokal (Delete Selected) tidak pernah kembali. Sekarang
        // dijamin ditarik lagi. Trade-off: tiap klik menarik semua case +
        // task approval-nya (bisa lambat & menambah beban Acumatica kalau
        // case sangat banyak).
        const gtFilter = '';

        const proxyUrl =
          baseUrl +
          "/proxy/acumatica.php?action=query&endpoint=Case&$filter=(Status eq 'New' or Status eq 'Open' or Status eq 'Closed' or Status eq 'Canceled') and ClassID eq 'REQFPTK'" +
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

        // --- 1. MAPPING DATA CASE & ATRIBUT ---
        const mappedItems = rawData.map((item, index) => {
          const attributes = item.Attributes || [];

          const getAttrValue = (attrId) => {
            const searchId = attrId.toLowerCase();
            const found = attributes.find(
              (a) => a.AttributeID?.value?.toLowerCase() === searchId,
            );
            return found
              ? found.ValueDescription?.value || found.Value?.value
              : null;
          };

          const department =
            getAttrValue('department') || item.Department?.value || '-';
          const position = getAttrValue('position') || '-';
          const reqType = getAttrValue('reqtype') || '-';
          const totPerson = parseInt(
            getAttrValue('totperson') || item.TotPerson?.value || 0,
            10,
          );
          const gender = getAttrValue('gender') || '-';
          const emplStatus = getAttrValue('empstatus') || '-';
          const skill = getAttrValue('skill') || '-';
          const targetDate = getAttrValue('targetdate') || '-';
          const jobDesc = getAttrValue('jobdesc') || '-';
          const reasonAttr =
            getAttrValue('reason') || item.Reason?.value || '-';

          return {
            id: item.id || index + 1,
            caseId: item.CaseID?.value || '-',
            caseClass: item.ClassID?.value || '-',
            status: item.Status?.value || '-',
            subject: item.Subject?.value || '-',
            owner: this.formatName
              ? this.formatName(
                  item.OwnerEmployeeName?.value || item.Owner?.value || '-',
                )
              : item.OwnerEmployeeName?.value || item.Owner?.value || '-',
            noteId: item.NoteID?.value || item.id || '-',

            department: department,
            position: position,
            reqType: reqType,
            totPerson: isNaN(totPerson) ? 0 : totPerson,
            gender: gender,
            emplStatus: emplStatus,
            skill: skill,
            targetDate: targetDate,
            jobDesc: jobDesc,
            reason: reasonAttr,

            attributes: attributes,
          };
        });

        // --- 2. TARIK DATA APPROVAL (TASK) SECARA PARALEL ---
        // Semua status task ditarik (Open/Completed/Rejected/dll) — status
        // apa pun tetap disimpan ke DB (instruksi user 2026-08-11).
        const taskPromises = mappedItems.map(async (caseItem) => {
          const filterString = `RelatedEntityNoteID eq guid'${caseItem.noteId}'`;
          const encodedFilter = encodeURIComponent(filterString);
          const taskUrl =
            baseUrl +
            `/proxy/acumatica.php?action=query&endpoint=Task&$filter=${encodedFilter}`;

          try {
            const taskRes = await fetch(taskUrl, {
              method: 'GET',
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
              },
            });
            const rawText = await taskRes.text();
            const taskResult = rawText ? JSON.parse(rawText) : [];

            caseItem.approvals = Array.isArray(taskResult)
              ? taskResult
              : taskResult.value || [];
          } catch (err) {
            console.error(
              `Gagal tarik Task untuk Case ${caseItem.caseId}`,
              err,
            );
            caseItem.approvals = [];
          }
          return caseItem;
        });

        const casesWithApprovals = await Promise.all(taskPromises);

        // --- 2b. CEK STATUS + OWNER + SUBJECT CASE LAMA (yang TIDAK ditarik) ---
        // Sync incremental hanya menarik case baru — header (status, owner,
        // subject) case lama bisa basi. Fetch dari Acumatica dan kirim
        // sebagai statusUpdates supaya DB selalu sinkron.
        const drawnCaseIds = new Set(casesWithApprovals.map((c) => c.caseId));
        const oldCases = this.allItems.filter(
          (c) => !drawnCaseIds.has(c.caseId),
        );

        // statusUpdates: header case lama (status, owner, subject)
        const statusUpdatePromises = oldCases.map(async (caseItem) => {
          const filterString = `CaseID eq '${caseItem.caseId}'`;
          const encodedFilter = encodeURIComponent(filterString);
          const caseUrl =
            baseUrl +
            `/proxy/acumatica.php?action=query&endpoint=Case&$filter=${encodedFilter}`;
          try {
            const caseRes = await fetch(caseUrl, {
              method: 'GET',
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
              },
            });
            const rawText = await caseRes.text();
            const caseResult = rawText ? JSON.parse(rawText) : [];
            const list = Array.isArray(caseResult)
              ? caseResult
              : caseResult.value || [];
            if (list.length > 0) {
              const c = list[0];
              const rawOwner =
                c.OwnerEmployeeName?.value || c.Owner?.value || null;
              return {
                caseId: caseItem.caseId,
                status: c.Status?.value || null,
                owner: rawOwner ? this.formatName(rawOwner) : null,
                subject: c.Subject?.value || null,
              };
            }
            return null;
          } catch (err) {
            console.error(
              `Gagal tarik Status header case lama ${caseItem.caseId}`,
              err,
            );
            return null;
          }
        });
        const statusUpdates = (await Promise.all(statusUpdatePromises)).filter(
          Boolean,
        );

        // approvalUpdates: task approval per case lama
        const approvalUpdatePromises = oldCases.map(async (caseItem) => {
          // Semua status task ditarik (Open/Completed/Rejected/dll)
          const filterString = `RelatedEntityNoteID eq guid'${caseItem.noteId}'`;
          const encodedFilter = encodeURIComponent(filterString);
          const taskUrl =
            baseUrl +
            `/proxy/acumatica.php?action=query&endpoint=Task&$filter=${encodedFilter}`;
          try {
            const taskRes = await fetch(taskUrl, {
              method: 'GET',
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
              },
            });
            const rawText = await taskRes.text();
            const taskResult = rawText ? JSON.parse(rawText) : [];
            return {
              caseId: caseItem.caseId,
              approvals: Array.isArray(taskResult)
                ? taskResult
                : taskResult.value || [],
            };
          } catch (err) {
            console.error(
              `Gagal tarik Task approval case lama ${caseItem.caseId}`,
              err,
            );
            return null;
          }
        });
        const approvalUpdates = (
          await Promise.all(approvalUpdatePromises)
        ).filter(Boolean);

        // Merge header (status/owner) dari statusUpdates ke dalam approvalUpdates.
        // Backend save_manpower.php membaca status & owner dari entri
        // approvalUpdates. Tanpa merge ini, perubahan status case lama
        // (mis. New → Open di Acumatica) TIDAK pernah ter-update di DB
        // lewat tombol Sync — status case cuma ke-update saat ada case baru.
        const statusByCaseId = new Map(statusUpdates.map((s) => [s.caseId, s]));
        const mergedApprovalUpdates = approvalUpdates.map((a) => ({
          ...(statusByCaseId.get(a.caseId) || {}),
          ...a,
        }));

        // --- 2c. RESOLUSI NAMA OWNER TASK (2026-09-14) ---
        // Acumatica Task API TIDAK mengirim nama owner (OwnerEmployeeName/OwnerName
        // kosong), cuma ID numerik (mis. 316044). Resolusi: cari Case dengan
        // Owner eq '<id>' → baca OwnerEmployeeName → simpan nama asli ke task,
        // supaya kolom Owner tampil benar (bukan fallback owner case).
        const ownerIdMap = new Map();
        const collectOwners = (arr) =>
          (arr || []).forEach((c) =>
            (c.approvals || []).forEach((t) => {
              const raw = t.OwnerEmployeeName?.value || t.Owner?.value;
              if (/^\d+$/.test(String(raw || '')))
                ownerIdMap.set(String(raw), null);
            }),
          );
        collectOwners(casesWithApprovals);
        collectOwners(mergedApprovalUpdates);

        await Promise.all(
          [...ownerIdMap.keys()].map(async (oid) => {
            try {
              const res = await fetch(
                baseUrl +
                  '/proxy/acumatica.php?action=query&endpoint=Case&$filter=' +
                  encodeURIComponent(`Owner eq '${oid}'`),
              );
              const data = await res.json();
              const list = Array.isArray(data) ? data : data.value || [];
              const c =
                list.find((x) => String(x.Owner?.value) === oid) || list[0];
              const nm = c && c.OwnerEmployeeName?.value;
              ownerIdMap.set(oid, nm ? this.formatName(nm) : null);
            } catch (err) {
              console.warn('Gagal resolve owner task', oid, err);
              ownerIdMap.set(oid, null);
            }
          }),
        );

        const injectOwners = (arr) =>
          (arr || []).forEach((c) =>
            (c.approvals || []).forEach((t) => {
              const raw = t.OwnerEmployeeName?.value || t.Owner?.value;
              if (/^\d+$/.test(String(raw || ''))) {
                const nm = ownerIdMap.get(String(raw));
                if (nm) t.OwnerEmployeeName = {value: nm};
              }
            }),
          );
        injectOwners(casesWithApprovals);
        injectOwners(mergedApprovalUpdates);

        // --- 2d. DETEKSI APPROVER NOT FOUND (2026-09-14) ---
        // Kalau approval task casenya KOSONG, atau owner task-nya kosong/'-' →
        // munculkan pesan "Approver Not Found" (beserta case-nya) di hasil Sync.
        const approverNotFound = [];
        const scanApprovers = (arr) =>
          (arr || []).forEach((c) => {
            const apprs = c.approvals || [];
            const missing = apprs.some((t) => {
              const o = t.OwnerEmployeeName?.value || t.Owner?.value || '';
              return !o || o === '-';
            });
            if (
              (apprs.length === 0 || missing) &&
              c.caseId &&
              c.caseId !== '-'
            ) {
              approverNotFound.push(c.caseId);
            }
          });
        scanApprovers(casesWithApprovals);
        scanApprovers(mergedApprovalUpdates);
        const approverNotFoundMessage =
          approverNotFound.length > 0
            ? `\n\n⚠ Approver Not Found: ${approverNotFound.join(', ')}`
            : '';

        this.items = casesWithApprovals;
        this.totalData = casesWithApprovals.length;

        // Catat jumlah case yang DITARIK dari Acumatica SEBELUM refresh —
        // fetchLocalData() di bawah menimpa totalData jadi jumlah data lokal.
        const fetchedCount = casesWithApprovals.length;

        // --- 3. SIMPAN KE DATABASE LOKAL ---
        const payloadToSave = {
          casesDetail: casesWithApprovals,
          approvalUpdates: mergedApprovalUpdates,
        };

        // Save WAJIB jalan juga walau TIDAK ada case baru, selama ada
        // approvalUpdates (perubahan status/owner/approval case lama).
        // Tanpa ini, status case lama (mis. New → Open) tidak pernah disimpan.
        if (
          payloadToSave.casesDetail.length > 0 ||
          payloadToSave.approvalUpdates.length > 0
        ) {
          try {
            const saveResponse = await fetch(
              baseUrl + '/proxy/save_manpower.php',
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

            // Case yang di-skip karena validasi Planned (kuota tahunan belum
            // completed) — tampilkan supaya tidak terkesan hilang diam-diam.
            const skipped = Array.isArray(saveResult.skipped)
              ? saveResult.skipped
              : [];
            if (skipped.length > 0) {
              skippedMessage = `\n\n⚠ ${
                skipped.length
              } case TIDAK disimpan (Planned tanpa kuota tahunan completed):\n- ${skipped.join(
                '\n- ',
              )}\n\nSelesaikan quota HRPLAN departemen tsb, lalu Sync lagi.`;
            }

            // Case Planned yang SUM tot_person-nya melebihi kuota planned
            // (total_additional) di All Quota Department — tampilkan alert.
            const overQuota = Array.isArray(saveResult.overQuota)
              ? saveResult.overQuota
              : [];
            if (overQuota.length > 0) {
              overQuotaMessage = `\n\n⚠ ${
                overQuota.length
              } case TIDAK disimpan (melebihi kuota planned):\n- ${overQuota.join(
                '\n- ',
              )}`;
            }
          } catch (dbError) {
            console.error('Gagal menyimpan ke database lokal:', dbError);
          }
        }

        // Penting: refresh dari database lokal, bukan data mentah Acumatica.
        // Status di DB bisa beda dari Acumatica — auto-link memaksa MPR ke
        // 'Closed' jika SEMUA vacancy yang ter-link sudah closed.
        await this.fetchLocalData();

        // Trigger refresh bell notification
        window.dispatchEvent(new CustomEvent('acumatica-sync-complete'));

        Swal.fire({
          title: 'Sync Berhasil',
          icon: 'success',
          html: `Mendapatkan ${fetchedCount} data Manpower Request beserta Approval-nya.${skippedMessage}${overQuotaMessage}${approverNotFoundMessage}${
            approvalUpdates.length > 0
              ? `\n\nℹ Approval ${approvalUpdates.length} case lama turut dicek/diperbarui.`
              : ''
          }`.replace(/\n/g, '<br>'),
        });
      } catch (error) {
        console.error('Gagal melakukan sync:', error);
        Swal.fire({
          title: 'Gagal',
          icon: 'error',
          html: `Gagal mengambil data: ${error.message}. Silakan periksa console browser.`,
        });
      } finally {
        await this.logoutAcumatica();
        this.isLoading = false;
        this.checkedItems = [];
      }
    },

    onClickDetail(item) {
      console.log('Membuka detail untuk:', item);

      this.selectedCase = item;
      this.showModal = true;

      // Ambil approval dengan ApprovalDate terbaru (paling akhir Completed).
      // Jika ada beberapa approval task, tampilkan hanya satu yang terakhir.
      const allApprovals = (item && item.approvals) || [];
      if (allApprovals.length > 0) {
        const sorted = [...allApprovals].sort((a, b) => {
          const da = a.ApprovalDate?.value || a.CompletedAt?.value || '';
          const db = b.ApprovalDate?.value || b.CompletedAt?.value || '';
          if (!da && !db) return 0;
          if (!da) return 1;
          if (!db) return -1;
          return String(db).localeCompare(String(da)); // desc
        });
        this.approvalTasks = [sorted[0]]; // hanya 1 task terakhir
      } else {
        this.approvalTasks = [];
      }
    },

    closeModal() {
      this.showModal = false;
      this.selectedCase = null;
      this.approvalTasks = [];
    },

    async onOpenApprovalHistory(item) {
      this.historyCase = item;
      this.historyList = [];
      this.historyLoading = true;
      this.showHistoryModal = true;
      const baseUrl = this.getBaseUrl();
      try {
        const res = await fetch(
          baseUrl +
            '/proxy/get_manpower_approval_history.php?case_id=' +
            encodeURIComponent(String(item.caseId || '')),
        );
        const result = await res.json();
        this.historyList = (result && result.data) || [];
      } catch (err) {
        console.error('Gagal ambil history approval:', err);
        this.historyList = [];
      } finally {
        this.historyLoading = false;
      }
    },

    closeHistoryModal() {
      this.showHistoryModal = false;
      this.historyCase = null;
      this.historyList = [];
    },

    formatHistoryTime(h) {
      const raw = h.changed_at;
      if (!raw || raw === '0000-00-00 00:00:00') return '-';
      const s = String(raw);
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2})/);
      if (m) return `${m[3]}-${m[2]}-${m[1]} ${m[4]}:${m[5]}`;
      return s;
    },

    statusBadgeStyle(st) {
      const v = String(st || '').toLowerCase();
      let bg = '#e2e8f0';
      let col = '#475569';
      if (v === 'completed' || v === 'approved') {
        bg = '#e6f4ea';
        col = '#1e8e3e';
      } else if (v === 'open') {
        bg = '#e8f0fe';
        col = '#1a73e8';
      } else if (v === 'canceled' || v === 'cancelled' || v === 'rejected') {
        bg = '#fdeaea';
        col = '#d93025';
      }
      return `background-color:${bg};color:${col};padding:2px 8px;border-radius:12px;font-size:12px;font-weight:bold;display:inline-block`;
    },

    getTaskOwnerName(task) {
      if (!task) return '-';

      const ownerValue =
        task.OwnerEmployeeName?.value ||
        task.OwnerName?.value ||
        task.Owner?.value ||
        '-';

      if (ownerValue === '164' || ownerValue === 164) {
        return 'Andrew Colin Chandra';
      }

      // Jika Owner berupa ID numerik (Acumatica Task tidak mengirim nama),
      // gunakan nama owner dari Case sebagai fallback (pola sama dgn QuotaRequest)
      if (/^\d+$/.test(String(ownerValue))) {
        return this.selectedCase?.owner || String(ownerValue);
      }

      return this.formatName(ownerValue);
    },

    formatApprovalDate(task) {
      if (!task) return '-';
      const raw =
        task.ApprovalDate?.value !== undefined &&
        task.ApprovalDate?.value !== null
          ? task.ApprovalDate.value
          : task.approval_date;
      if (!raw || raw === '-' || raw === '0000-00-00 00:00:00') return '-';
      // Format: DD-MM-YYYY HH:MM (supaya jam ikut tampil, seperti Quota Request)
      const s = String(raw);
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2})/);
      if (m) return `${m[3]}-${m[2]}-${m[1]} ${m[4]}:${m[5]}`;
      const d = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
      return d ? `${d[3]}-${d[2]}-${d[1]}` : s;
    },

    formatName(fullName) {
      if (!fullName || fullName === '-') return '-';

      if (fullName.includes(' - ')) {
        return fullName.split(' - ')[1].trim();
      }

      return fullName;
    },

    async onClickDeleteSelected() {
      const selected = (this.checkedItems || []).slice();
      if (selected.length === 0) {
        Swal.fire({
          title: 'Info',
          icon: 'info',
          text: 'Tidak ada case yang dipilih.',
        });
        return;
      }

      // checkedItems = INDEKS baris (angka), bukan objek row — petakan ke baris
      // via this.items (pola sama dgn halaman lain yg delete-selected-nya jalan).
      const caseIds = selected
        .map((idx) => {
          const row = this.items && this.items[idx];
          return row ? row.caseId : null;
        })
        .filter(
          (c) => c !== undefined && c !== null && String(c).trim() !== '',
        );

      if (caseIds.length === 0) {
        Swal.fire({
          title: 'Info',
          icon: 'info',
          text: 'Tidak ada Case ID valid pada pilihan.',
        });
        return;
      }

      const confirmed = await Swal.fire({
        title: 'Konfirmasi Hapus Case',
        icon: 'warning',
        html: `Hapus <b>${caseIds.length}</b> case Manpower Request (FPTK) beserta seluruh approval task & link vacancy-nya dari data lokal?<br><br><span style="font-size: 12px; color: #64748b;">Catatan: sync Acumatica bersifat inkremental, jadi case ini UMUMNYA <b>TIDAK akan kembali</b> otomatis (praktis hapus permanen dari sisi lokal OH). Data di Acumatica tetap ada.</span>`,
        showCancelButton: true,
        confirmButtonText: 'Ya, hapus',
        cancelButtonText: 'Batal',
      });
      if (!confirmed.isConfirmed) return;

      const baseUrl = this.getBaseUrl();
      try {
        const resp = await fetch(baseUrl + '/proxy/delete_manpower_case.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({caseIds}),
        });
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        const result = await resp.json();

        if (result.status === 'success') {
          // Refresh dari DB supaya baris case terhapus hilang dari tabel
          await this.fetchLocalData();
          this.checkedItems = [];
          Swal.fire({
            title: 'Selesai',
            icon: 'success',
            html: `${result.deleted ?? 0} case dihapus (lokal).`,
          });
        } else {
          Swal.fire({
            title: 'Gagal',
            icon: 'error',
            text: result.message || 'Gagal menghapus.',
          });
        }
      } catch (err) {
        console.error('Gagal hapus case:', err);
        Swal.fire({
          title: 'Gagal',
          icon: 'error',
          text: String(err.message || err),
        });
      }
    },
  },
};
</script>

<style>
/* Spinner di tombol Sync Acumatica saat isLoading.
   Non-scoped: .oxd-icon berada di dalam child component oxd-button. */
@keyframes oxd-icon-spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
.oxd-btn--loading .oxd-icon.bi-arrow-repeat {
  animation: oxd-icon-spin 0.8s linear infinite;
}
</style>
