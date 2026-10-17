<template>
  <div class="orangehrm-background-container">
    <!-- Form Pencarian: Case ID + Approval Task (Open/Completed) -->
    <oxd-table-filter :filter-title="'Quota Request'">
      <oxd-form @submit-valid="filterItems">
        <oxd-form-row>
          <oxd-grid :cols="3" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.caseId"
                :label="'Case ID'"
                placeholder="Ex: 0000003680"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.approvalStatus"
                type="select"
                :label="'Approval Task'"
                :clear="false"
                :options="approvalStatusOptions"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.status"
                type="select"
                :label="'Status'"
                :clear="false"
                :options="statusOptions"
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

      <!-- Area Bawah Tabel -->
      <div class="orangehrm-bottom-container" style="padding: 1.5rem 1rem">
        <oxd-button
          class="oxd-btn--loading"
          display-type="secondary"
          :label="isLoading ? 'Syncing...' : 'Sync Acumatica'"
          :icon-name="isLoading ? 'bi-arrow-repeat' : null"
          :disabled="isLoading"
          @click="syncAcumatica"
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
                      {{ formatApprovalDate(task.ApprovalDate?.value) }}
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
                  <th style="padding: 10px; text-align: left; width: 22%">
                    Waktu
                  </th>
                  <th style="padding: 10px; text-align: left; width: 16%">
                    Status
                  </th>
                  <th style="padding: 10px; text-align: left; width: 26%">
                    Owner
                  </th>
                  <th style="padding: 10px; text-align: left; width: 36%">
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
                  <td style="padding: 10px; white-space: nowrap; width: 22%">
                    {{ formatHistoryTime(h) }}
                  </td>
                  <td style="padding: 10px; width: 16%">
                    <span :style="statusBadgeStyle(h.status)">{{
                      h.status || '-'
                    }}</span>
                  </td>
                  <td style="padding: 10px; width: 26%">
                    {{ h.owner && h.owner !== '-' ? h.owner : '-' }}
                  </td>
                  <td style="padding: 10px; width: 36%">
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
import Swal from 'sweetalert2';
import VacancyStatusBadge from '@/orangehrmRecruitmentPlugin/components/VacancyStatusBadge.vue';
import ApprovalTaskBadge from '@/orangehrmAdminPlugin/components/ApprovalTaskBadge.vue';

export default {
  name: 'ViewQuotaRequest',
  data() {
    return {
      isLoading: false,
      searchQuery: '',
      checkedItems: [],
      totalData: 0,

      // --- STATE FILTER PENCARIAN (Case ID + Approval Task + Status) ---
      filters: {
        caseId: '',
        approvalStatus: null,
        status: null,
      },
      approvalStatusOptions: [
        {id: 'Open', label: 'Open'},
        {id: 'Completed', label: 'Completed'},
        {id: 'Rejected', label: 'Rejected'},
      ],
      statusOptions: [
        {id: 'Open', label: 'Open'},
        {id: 'Closed', label: 'Closed'},
      ],

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

      // Items dikosongkan secara default (menunggu disinkronisasi)
      items: [],
      // Master data dari DB (dipakai untuk window sync + hitung summary kuota)
      allItems: [],

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
        {name: 'subject', title: 'Subject', style: {flex: 1.5}},
        {name: 'owner', title: 'Owner', style: {flex: 1}},
        {
          name: 'approvalTask',
          title: 'Approval Task',
          style: {flex: 1},
          cellRenderer: this.cellRendererApproval,
        },
        // --- TAMBAHAN KOLOM ACTION ---
        {
          name: 'actions',
          slot: 'action',
          title: 'Actions', // Judul kolom (opsional bisa dikosongkan)
          style: {flex: 0.5},
          cellType: 'oxd-table-cell-actions',
          cellConfig: {
            viewDetail: {
              onClick: this.onClickDetail, // Memanggil fungsi onClickDetail saat diklik
              component: 'oxd-icon-button',
              props: {
                name: 'eye', // Menggunakan ikon mata untuk "View"
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

  mounted() {
    // Tampilkan data dari database lokal langsung (tanpa Sync Acumatica)
    this.fetchLocalData();

    setTimeout(() => {
      // Mencari elemen Header/Breadcrumb bawaan OrangeHRM
      const breadcrumb = document.querySelector(
        '.oxd-topbar-header-breadcrumb',
      );
      if (breadcrumb) {
        // Ganti teksnya sesuai nama menu
        breadcrumb.innerHTML = `
          <h4 class="oxd-text oxd-text--h6 oxd-topbar-header-breadcrumb-level">
            Quota Request
          </h4>
        `;
      }

      // Mengubah judul tab di browser
      document.title = 'Quota Request';
      const allMenus = document.querySelectorAll('.oxd-main-menu-item');
      allMenus.forEach((menu) => {
        menu.classList.remove('active');
      });

      // Cari menu yang tulisannya "All Quota Department" dan beri warna aktif
      allMenus.forEach((menu) => {
        if (menu.textContent.trim() === 'Quota Request') {
          menu.classList.add('active');
        }
      });
    }, 100);
  },
  methods: {
    getBaseUrl() {
      const base = window.appGlobal?.baseUrl || '';
      return base.replace('/index.php', '') || '';
    },

    // Render badge warna untuk kolom status (Closed = hijau, Open = biru)
    cellRenderer(...args) {
      const row = args[3];
      return {
        component: VacancyStatusBadge,
        props: {
          status: row.status,
        },
      };
    },

    // Render Approval Task TERAKHIR (badge status) — data dari
    // get_quota_requests.php field lastApproval (task approval_id terbesar).
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

    // True kalau approval task TERAKHIR baris ini berstatus Open → baris boleh
    // dicentang & di-delete-selected (hanya yg Open yg muncul checkbox-nya).
    isOpenApproval(row) {
      return (
        String(row?.lastApproval?.status || '')
          .trim()
          .toLowerCase() === 'open'
      );
    },

    // Tandai selectable per baris sesuai isOpenApproval() — pola Manpower
    // Request (FPTK). Dipanggil setiap items di-refresh / difilter.
    applySelectable() {
      (this.items || []).forEach((r) => {
        r.isSelectable = this.isOpenApproval(r);
      });
    },

    // Filter pencarian: Case ID (contains) + Approval Task + Status
    filterItems() {
      const q = String(this.filters.caseId || '')
        .trim()
        .toLowerCase();
      const ap = this.filters.approvalStatus?.id || null;
      const status = this.filters.status?.id || null;

      let filtered = this.allItems;
      if (q) {
        filtered = filtered.filter((r) =>
          String(r.caseId || '')
            .toLowerCase()
            .includes(q),
        );
      }
      if (ap) {
        filtered = filtered.filter((r) => {
          const approvals = r.approvals || [];
          if (approvals.length === 0) return false;
          // Ambil approval terbaru (by ApprovalDate desc)
          const sorted = [...approvals].sort((a, b) => {
            const da = a.ApprovalDate?.value || '';
            const db = b.ApprovalDate?.value || '';
            if (!da && !db) return 0;
            if (!da) return 1;
            if (!db) return -1;
            return String(db).localeCompare(String(da));
          });
          const latest = sorted[0];
          return (
            String(latest?.Status?.value || '').toUpperCase() ===
            ap.toUpperCase()
          );
        });
      }
      if (status) {
        filtered = filtered.filter((r) => String(r.status || '') === status);
      }

      this.items = filtered;
      this.totalData = filtered.length;
      this.applySelectable();
    },

    onClickReset() {
      this.filters.caseId = '';
      this.filters.approvalStatus = null;
      this.filters.status = null;
      this.items = this.allItems;
      this.totalData = this.allItems.length;
      this.applySelectable();
    },

    // Tampilkan data quota request dari database lokal (ohrm_quota_requests
    // + ohrm_case_attributes + ohrm_approval_history) — tanpa Sync Acumatica.
    async fetchLocalData() {
      this.isLoading = true;
      try {
        const baseUrl = this.getBaseUrl();
        const response = await fetch(baseUrl + '/proxy/get_quota_requests.php');
        const result = await response.json();

        if (result.status === 'success') {
          this.items = result.data;
          this.allItems = result.data;
          this.totalData = result.data.length;
          this.applySelectable();
        } else {
          console.error('Gagal load data lokal:', result.message);
        }
      } catch (error) {
        console.error('Error saat fetch data lokal:', error);
      } finally {
        this.isLoading = false;
      }
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

      try {
        // Pastikan data lokal termuat SEBELUM menghitung window sync —
        // kalau allItems kosong, filter CaseID gt tidak dibuat → semua ditarik.
        await this.fetchLocalData();
        // fetchLocalData() mereset isLoading ke false di finally-nya —
        // set ulang supaya tombol tetap menampilkan "Syncing..." + spinner.
        this.isLoading = true;

        await this.loginAcumatica();

        const baseUrl = this.getBaseUrl();

        // Incremental: hanya tarik case HRPLAN yang BELUM ada di DB
        // (CaseID > max case_id lokal). Data yang sudah tersync tidak
        // ditarik ulang.
        let gtFilter = '';
        const localMax = this.allItems.reduce((max, r) => {
          const n = parseInt(String(r.caseId || '').replace(/\D/g, ''), 10);
          return Number.isNaN(n) ? max : Math.max(max, n);
        }, 0);
        if (localMax > 0) {
          gtFilter = ` and CaseID gt '${String(localMax).padStart(10, '0')}'`;
        }

        const proxyUrl =
          baseUrl +
          "/proxy/acumatica.php?action=query&endpoint=Case&$filter=(Status eq 'Open' or Status eq 'Closed' or Status eq 'New') and ClassID eq 'HRPLAN'" +
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

        // --- VARIABEL UNTUK GROUPING QUOTA ---
        const groupedData = {};
        const currentYear = new Date().getFullYear();

        // 1a. Bangun summary dari data LOKAL (semua HRPLAN yang sudah ada di
        //     DB) supaya total_additional TIDAK mengecil saat hanya case baru
        //     yang ditarik (save_quota.php overwrite total_additional penuh).
        const localAttr = (item, attrId) => {
          const searchId = attrId.toLowerCase();
          const found = (item.attributes || []).find(
            (a) => a.AttributeID?.value?.toLowerCase() === searchId,
          );
          return found
            ? found.ValueDescription?.value || found.Value?.value || null
            : null;
        };
        this.allItems.forEach((item) => {
          const deptName = localAttr(item, 'department') || '-';
          const year = localAttr(item, 'year') || currentYear;
          const tot = parseInt(localAttr(item, 'totperson') || 0, 10);
          // Group per (dept + tahun) — case tahun 2026 dan 2027 di dept yang
          // sama TIDAK boleh dicampur (All Quota Department per baris dept+tahun).
          const key = `${deptName}|${year}`;
          if (!groupedData[key]) {
            groupedData[key] = {
              dept_name: deptName,
              year: year,
              total_additional: 0,
              replacement: 0,
              reduction: 0,
              unplanned: 0,
            };
          }
          groupedData[key].total_additional += isNaN(tot) ? 0 : tot;
        });

        // 2. Transform Data & Hitung Total
        const mappedItems = rawData.map((item, index) => {
          const attributes = item.Attributes || [];

          // Fungsi bantu untuk mengambil nilai dari Attributes
          const getAttrValue = (attrId) => {
            const searchId = attrId.toLowerCase();
            const found = attributes.find(
              (a) => a.AttributeID?.value?.toLowerCase() === searchId,
            );
            return found
              ? found.ValueDescription?.value || found.Value?.value
              : null;
          };

          // (Opsional) Tampilkan ke Console untuk melihat nama Attribute ID aslinya
          if (index === 0) {
            console.log(
              'Daftar Attributes dari Acumatica untuk baris pertama:',
              attributes,
            );
          }
          // --------------------------

          // ----------------------------------------------------------------------
          // MAPPING FIELD: Menyesuaikan Attribute Acumatica ke Kolom Database
          // ----------------------------------------------------------------------
          const deptName =
            getAttrValue('department') || item.Department?.value || 'Unknown';
          const year = getAttrValue('year') || item.Year?.value || currentYear;

          // Ambil nilai 'totperson' dari Acumatica
          const totPerson = parseInt(
            getAttrValue('totperson') || item.TotPerson?.value || 0,
            10,
          );

          // Grouping berdasarkan Departemen + Tahun (format kolom database lama Bapak).
          // Key per (dept + tahun) — case tahun 2026/2027 di dept sama TIDAK dicampur.
          const grpKey = `${deptName}|${year}`;
          if (!groupedData[grpKey]) {
            groupedData[grpKey] = {
              dept_name: deptName,
              year: year,
              total_additional: 0, // Kita siapkan kolom lamanya
              replacement: 0, // Default 0
              reduction: 0, // Default 0
              unplanned: 0, // Default 0 — diisi lewat trigger close vacancy Unplanned
            };
          }

          // Masukkan nilai totperson dari Acumatica ke dalam total_additional
          groupedData[grpKey].total_additional += isNaN(totPerson)
            ? 0
            : totPerson;

          // Return data asli untuk Tabel UI Bapak
          return {
            id: item.id || index + 1,
            caseId: item.CaseID?.value || '-',
            caseClass: item.ClassID?.value || '-',
            status: item.Status?.value || '-',
            reason: item.Reason?.value || '-',
            subject: item.Subject?.value || '-',
            owner: this.formatName
              ? this.formatName(
                  item.OwnerEmployeeName?.value || item.Owner?.value || '-',
                )
              : item.OwnerEmployeeName?.value || item.Owner?.value || '-',
            noteId: item.NoteID?.value || item.id || '-',
            attributes: attributes,
          };
        });

        // Tampilkan ke tabel Vue (data mentah dari Acumatica, sementara)
        this.items = mappedItems;
        this.totalData = mappedItems.length;

        // Catat jumlah case yang DITARIK dari Acumatica SEBELUM refresh —
        // fetchLocalData() di bawah menimpa totalData jadi jumlah data lokal.
        const fetchedCount = mappedItems.length;

        // --- 2b. TARIK TASK/APPROVAL PER CASE (untuk disimpan ke lokal) ---
        const taskPromises = mappedItems.map(async (caseItem) => {
          const filterString = `(Status eq 'Open' or Status eq 'Completed' or Status eq 'Rejected') and RelatedEntityNoteID eq guid'${caseItem.noteId}'`;
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

        const mappedWithApprovals = await Promise.all(taskPromises);

        // --- 2c. CEK STATUS APPROVAL CASE LAMA (yang TIDAK ditarik ulang) ---
        // Sync incremental hanya menarik case baru — approval task case lama
        // tidak ikut di-refresh. Tarik task approval case lama dari Acumatica
        // dan kirim sebagai approvalUpdates supaya status Open/Completed di DB
        // selalu sesuai Acumatica.
        const drawnCaseIds = new Set(mappedItems.map((c) => c.caseId));
        const oldCases = this.allItems.filter(
          (c) => !drawnCaseIds.has(c.caseId),
        );
        const approvalUpdatePromises = oldCases.map(async (caseItem) => {
          const filterString = `(Status eq 'Open' or Status eq 'Completed' or Status eq 'Rejected') and RelatedEntityNoteID eq guid'${caseItem.noteId}'`;
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
              noteId: caseItem.noteId,
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

        // --- 2d. CEK STATUS + OWNER HEADER CASE LAMA ---
        // Sync incremental hanya menarik case baru — header (status, owner,
        // subject) case lama bisa basi. Fetch dari Acumatica dan kirim
        // sebagai statusUpdates supaya DB selalu sinkron.
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

        // --- 2e. TUTUP CASE DI ACUMATICA JIKA ADA APPROVAL REJECTED ---
        // Hanya tutup case jika ada approval yang Rejected.
        // Jika SEMUA Completed, JANGAN tutup — biarkan status di Acumatica.
        const allWithApprovals = [
          ...mappedWithApprovals.map((c) => ({
            caseId: c.caseId,
            approvals: c.approvals || [],
          })),
          ...approvalUpdates.filter((u) => u && u.caseId),
        ];
        const closeCaseIds = allWithApprovals
          .filter((c) => {
            if (c.approvals.length === 0) {
              return false;
            }
            const statuses = c.approvals.map((a) =>
              (a.Status?.value || '').toUpperCase(),
            );
            // TIDAK push Closed ke Acumatica — biarkan status asli
            return false;
          })
          .map((c) => c.caseId);

        // --- 3. PROSES SIMPAN KE DATABASE LOKAL LALU KE PHP ---
        const payloadToSave = {
          quotaSummary: Object.values(groupedData),
          casesDetail: mappedWithApprovals,
          approvalUpdates,
          statusUpdates,
          closeCaseIds,
        };

        if (payloadToSave.quotaSummary.length > 0) {
          try {
            const saveResponse = await fetch(
              baseUrl + '/proxy/save_quota.php',
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
          } catch (dbError) {
            console.error('Gagal menyimpan ke database lokal:', dbError);
          }
        }

        // Refresh dari DATABASE LOKAL (bukan data mentah Acumatica)
        await this.fetchLocalData();

        // Trigger refresh bell notification
        window.dispatchEvent(new CustomEvent('acumatica-sync-complete'));

        Swal.fire({
          title: 'Sync Berhasil',
          icon: 'success',
          html: `Mendapatkan ${fetchedCount} data Case dan Kuota Departemen telah diupdate.<br>Approval ${approvalUpdates.length} case lama turut diperbarui, status ${statusUpdates.length} case lama disinkronkan, ${closeCaseIds.length} case di-close di Acumatica (approval Completed/Rejected).`,
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

    // 3. UPDATE FUNGSI DETAIL — tampilkan HANYA approval terakhir
    async onClickDetail(item) {
      console.log('Membuka detail untuk:', item);

      this.selectedCase = item;
      this.showModal = true;

      // Ambil approval dengan ApprovalDate terbaru (paling akhir Completed).
      // Jika ada beberapa approval task, tampilkan hanya satu yang terakhir.
      const allApprovals = (item && item.approvals) || [];
      if (allApprovals.length > 0) {
        // Sort by ApprovalDate descending (null/empty di belakang)
        const sorted = [...allApprovals].sort((a, b) => {
          const da = a.ApprovalDate?.value || '';
          const db = b.ApprovalDate?.value || '';
          if (!da && !db) return 0;
          if (!da) return 1;
          if (!db) return -1;
          return String(db).localeCompare(String(da)); // desc
        });
        this.approvalTasks = [sorted[0]]; // hanya 1 task terakhir
      } else {
        this.approvalTasks = [];
      }
      this.isLoadingTasks = false;
    },

    closeModal() {
      this.showModal = false;
      this.selectedCase = null;
      this.approvalTasks = [];
    },

    // Buka modal History Approval Task — SEMUA approval dari item.approvals
    // (data dari ohrm_approval_history via get_quota_requests.php).
    async onOpenApprovalHistory(item) {
      this.historyCase = item;
      this.showHistoryModal = true;
      this.historyLoading = true;
      this.historyList = [];
      try {
        const approvals = (item && item.approvals) || [];
        this.historyList = approvals.map((a, i) => ({
          id: i,
          changed_at: a.ApprovalDate?.value || null,
          status: a.Status?.value || '-',
          owner: a.OwnerEmployeeName?.value || '-',
          summary: a.Summary?.value || '-',
        }));
      } finally {
        this.historyLoading = false;
      }
    },

    closeHistoryModal() {
      this.showHistoryModal = false;
      this.historyCase = null;
      this.historyList = [];
    },

    // Format tanggal history approval: DD-MM-YYYY HH:MM (zero date -> '-')
    formatHistoryTime(h) {
      const raw = h.changed_at;
      if (!raw || /^0{4}-0{2}-0{2}/.test(String(raw))) return '-';
      const s = String(raw);
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2})/);
      if (m) return `${m[3]}-${m[2]}-${m[1]} ${m[4]}:${m[5]}`;
      return s;
    },

    // Warna badge status approval (Completed = hijau, Open = biru,
    // Canceled/Rejected = merah, default = abu).
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

    // --- FUNGSI BARU UNTUK APPROVAL TASKS ---
    // --- FUNGSI BARU UNTUK APPROVAL TASKS ---
    getTaskOwnerName(task) {
      if (!task) return '-';

      // Ambil nilai dari field yang dikirim Acumatica
      const ownerValue =
        task.OwnerEmployeeName?.value ||
        task.OwnerName?.value ||
        task.Owner?.value ||
        '-';

      // === KONDISI KHUSUS ===
      // Jika nilainya 164 (baik berbentuk teks maupun angka murni)
      if (ownerValue === '164' || ownerValue === 164) {
        return 'Andrew Colin Chandra';
      }

      // Jika Owner berupa ID numerik (Acumatica Task tidak mengirim nama),
      // gunakan nama owner dari Case sebagai fallback
      if (/^\d+$/.test(String(ownerValue))) {
        return this.selectedCase?.owner || String(ownerValue);
      }

      // Jika bukan angka, jalankan formatName untuk buang "JKT - "
      return this.formatName(ownerValue);
    },

    // Format tanggal approval: tampilkan DD-MM-YYYY HH:MM
    formatApprovalDate(value) {
      if (!value) return '-';
      const s = String(value);
      // zero date MySQL '0000-00-00 ...'
      if (/^0{4}-0{2}-0{2}/.test(s)) return '-';
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2})/);
      if (m) return `${m[3]}-${m[2]}-${m[1]} ${m[4]}:${m[5]}`;
      // fallback tanpa jam
      const d = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (d) return `${d[3]}-${d[2]}-${d[1]}`;
      return s;
    },

    // --- FUNGSI FORMAT NAME (Sedikit dirapikan) ---
    formatName(fullName) {
      if (!fullName || fullName === '-') return '-';

      // Jika ada teks " - " (Contoh: "JKT - Syahdan"), ambil bagian setelah " - "
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
          text: 'Tidak ada case yang dipilih. Hanya case dengan Approval Task masih Open yang dapat dihapus.',
        });
        return;
      }

      // checkedItems = INDEKS baris (angka) → petakan ke row via this.items
      // (pola sama dgn halaman lain yg delete-selected-nya jalan).
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
        html: `Hapus <b>${caseIds.length}</b> case Quota Request beserta seluruh approval task &amp; attribute-nya dari data lokal?<br><br><span style="font-size: 12px; color: #64748b;">Catatan: sync Acumatica bersifat inkremental, jadi case ini UMUMNYA <b>TIDAK akan kembali</b> otomatis (praktis hapus permanen dari sisi lokal OH). Data di Acumatica tetap ada.</span>`,
        showCancelButton: true,
        confirmButtonText: 'Ya, hapus',
        cancelButtonText: 'Batal',
      });
      if (!confirmed.isConfirmed) return;

      const baseUrl = this.getBaseUrl();
      try {
        const resp = await fetch(baseUrl + '/proxy/delete_quota_case.php', {
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
          const skippedNote = result.skipped
            ? `<br>${result.skipped} case non-Open di-skip.`
            : '';
          Swal.fire({
            title: 'Selesai',
            icon: 'success',
            html: `${result.deleted ?? 0} case dihapus (lokal).${skippedNote}`,
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
