<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-paper-container">
      <!-- Filter Tahun -->
      <oxd-table-filter :filter-title="'Resource Fulfillments'">
        <oxd-form @submit-valid="filterItems">
          <oxd-form-row>
            <oxd-grid :cols="3" class="orangehrm-full-width-grid">
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
                  v-model="filters.year"
                  type="select"
                  :label="'Year'"
                  :clear="false"
                  :options="yearOptions"
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
              display-type="secondary"
              :label="$t('general.reset')"
              @click="onClickReset"
            />
            <oxd-button
              class="orangehrm-left-space"
              display-type="secondary"
              :label="$t('general.search')"
              type="submit"
            />
            <oxd-button
              class="orangehrm-left-space"
              display-type="secondary"
              :label="'Export PDF'"
              @click="onClickExportPdf"
            />
            <oxd-button
              class="orangehrm-left-space"
              display-type="success"
              :label="'Export Excel'"
              :disabled="isLoading || items.length === 0"
              @click="exportExcel"
            />
          </oxd-form-actions>
        </oxd-form>
      </oxd-table-filter>

      <!-- Header Tabel -->
      <table-header
        header-title="Resource Fulfillments"
        :selected="checkedItems.length"
        :total="totalData"
        :loading="isLoading"
        @delete="onClickDeleteSelected"
      ></table-header>

      <!-- Tabel Utama (area yang dicetak saat Export PDF) -->
      <div id="section-to-print" class="orangehrm-container">
        <div class="print-header print-only">
          <h2>Resource Fulfillments</h2>
          <div class="print-meta">
            Department: {{ filters.department?.label || 'All' }} &nbsp;•&nbsp;
            Year: {{ filters.year?.label || 'All' }} &nbsp;•&nbsp; Status:
            {{ filters.status?.label || 'Semua' }}
          </div>
          <div class="print-date">Export: {{ exportDateLabel }}</div>
        </div>
        <oxd-card-table
          :headers="headers"
          :items="items"
          :selectable="false"
          :clickable="false"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
        />
      </div>
    </div>

    <!-- ============================================== -->
    <!-- MODAL POPUP DETAIL KUOTA PER DEPARTEMEN -->
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
      <div
        style="
          background-color: #ffffff;
          width: 800px;
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
            Detail Quota:
            <span style="color: #ff7b1c; font-weight: bold">
              {{ selectedDept?.dept_name }}
            </span>
            ({{ selectedDept?.year }})
          </h6>
          <button
            style="
              background: none;
              border: none;
              cursor: pointer;
              font-size: 1.5rem;
              color: #64748b;
              line-height: 1;
            "
            @click="closeModal"
          >
            &times;
          </button>
        </div>

        <!-- Body Modal -->
        <div style="overflow-x: auto; width: 100%">
          <!-- Ringkasan kuota dari baris yang diklik -->
          <div
            v-if="selectedDept"
            style="
              display: flex;
              flex-wrap: wrap;
              gap: 10px 18px;
              padding: 14px 16px;
            "
          >
            <div>
              <span style="color: #64748b; font-size: 12px">Planned</span><br />
              <b>{{ selectedDept.planned }}</b>
            </div>
            <div>
              <span style="color: #64748b; font-size: 12px">Replacement</span
              ><br />
              <b>{{ selectedDept.replacement }}</b>
            </div>
            <div>
              <span style="color: #64748b; font-size: 12px">Quota Tersedia</span
              ><br />
              <b>{{ selectedDept.quota_tersedia }}</b>
            </div>
          </div>

          <hr style="margin: 0; border: 0; border-top: 1px solid #e2e8f0" />

          <div
            v-if="isLoadingDetails"
            style="text-align: center; padding: 20px; color: #64748b"
          >
            Menarik data rincian dari Acumatica...
          </div>

          <table
            class="oxd-table"
            style="
              width: 100%;
              table-layout: fixed;
              border-collapse: collapse;
              border: 1px solid #ced4da;
              border-radius: 6px;
              overflow: hidden;
            "
          >
            <thead
              style="
                background-color: #f4f6f8;
                border-bottom: 2px solid #ced4da;
              "
            >
              <tr>
                <th
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    font-weight: bold;
                    color: #4b5563;
                    width: 22%;
                  "
                >
                  Case ID
                </th>
                <th
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    font-weight: bold;
                    color: #4b5563;
                    width: 38%;
                  "
                >
                  Subject
                </th>
                <th
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    font-weight: bold;
                    color: #4b5563;
                    width: 20%;
                  "
                >
                  Status
                </th>
                <th
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    font-weight: bold;
                    color: #4b5563;
                    width: 20%;
                  "
                >
                  TotPerson
                </th>
              </tr>
            </thead>
            <tbody v-if="deptCases.length > 0">
              <tr
                v-for="(c, idx) in deptCases"
                :key="idx"
                style="border-bottom: 1px solid #e2e8f0"
              >
                <td
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    font-weight: 600;
                    color: #004e8c;
                    word-wrap: break-word;
                  "
                >
                  {{ c.CaseID?.value || '-' }}
                </td>
                <td
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    color: #334155;
                    word-wrap: break-word;
                  "
                >
                  {{ c.Subject?.value || '-' }}
                </td>
                <td
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    vertical-align: middle;
                  "
                >
                  <span
                    style="
                      background-color: #e6f4ea;
                      color: #1e8e3e;
                      padding: 4px 12px;
                      border-radius: 20px;
                      font-size: 12px;
                      font-weight: bold;
                    "
                  >
                    {{ c.Status?.value || '-' }}
                  </span>
                </td>
                <td
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    font-weight: bold;
                    color: #ff7b1c;
                    font-size: 15px;
                  "
                >
                  {{ getTotPerson(c.Attributes) }}
                </td>
              </tr>
            </tbody>
            <tbody v-else>
              <tr>
                <td
                  colspan="4"
                  style="
                    padding: 24px;
                    text-align: center;
                    color: #64748b;
                    font-style: italic;
                  "
                >
                  Tidak ada rincian Case untuk departemen ini.
                </td>
              </tr>
            </tbody>
          </table>
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
  </div>
</template>

<script>
import ResourceFulfillmentStatusBadge from '@/orangehrmAdminPlugin/components/ResourceFulfillmentStatusBadge.vue';
import FulfillmentProgress from '@/orangehrmAdminPlugin/components/FulfillmentProgress.vue';

export default {
  name: 'ViewResourceFulfillment',
  data() {
    return {
      isLoading: false,
      checkedItems: [],
      totalData: 0,
      allItems: [],
      items: [],

      filters: {
        department: {id: 'all', label: 'All Departments'},
        year: {id: 'all', label: 'All Years'},
        status: null,
      },
      yearOptions: [{id: 'all', label: 'All Years'}],
      departmentOptions: [{id: 'all', label: 'All Departments'}],
      statusOptions: [
        {id: 'Terpenuhi', label: 'Terpenuhi'},
        {id: 'In Progress', label: 'In Progress'},
        {id: 'Kurang', label: 'Kurang'},
      ],

      // --- STATE MODAL DETAIL KUOTA ---
      showModal: false,
      isLoadingDetails: false,
      selectedDept: null,
      deptCases: [],

      headers: [
        {name: 'dept_name', title: 'Department', style: {flex: 1.5}},
        {name: 'year', title: 'Year', style: {flex: 0.7}},
        {name: 'quota_akhir', title: 'Quota Akhir', style: {flex: 1}},
        {name: 'terpenuhi', title: 'Terpenuhi', style: {flex: 1}},
        {
          name: 'lowongan_berjalan',
          title: 'Lowongan Berjalan',
          style: {flex: 1.2},
        },
        {name: 'quota_tersedia', title: 'Quota Tersedia', style: {flex: 1}},
        {
          name: 'pemenuhan_persen',
          title: 'Pemenuhan',
          style: {flex: 1.2},
          cellRenderer: this.cellRendererPersen,
        },
        {
          name: 'status',
          title: 'Status',
          style: {flex: 1},
          cellRenderer: this.cellRenderer,
        },
        // --- TAMBAHAN KOLOM ACTION (IKON MATA) ---
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
              props: {name: 'eye'},
            },
          },
        },
      ],
    };
  },
  computed: {
    exportDateLabel() {
      const d = new Date();
      return (
        d.toLocaleDateString('id-ID', {
          day: '2-digit',
          month: 'long',
          year: 'numeric',
        }) +
        ' ' +
        d.toLocaleTimeString('id-ID', {
          hour: '2-digit',
          minute: '2-digit',
        })
      );
    },
  },
  mounted() {
    this.fetchLocalData();

    // Ubah nama header topbar + highlight menu aktif
    setTimeout(() => {
      const breadcrumb = document.querySelector(
        '.oxd-topbar-header-breadcrumb',
      );
      if (breadcrumb) {
        breadcrumb.innerHTML = `
          <h4 class="oxd-text oxd-text--h6 oxd-topbar-header-breadcrumb-level">
            Resource Fulfillments
          </h4>
        `;
      }

      document.title = 'Resource Fulfillments';
      const allMenus = document.querySelectorAll('.oxd-main-menu-item');
      allMenus.forEach((menu) => {
        menu.classList.remove('active');
      });

      allMenus.forEach((menu) => {
        if (menu.textContent.trim() === 'Resource Fulfillments') {
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

    onClickExportPdf() {
      window.print();
    },

    exportExcel() {
      const headers = [
        'Department',
        'Year',
        'Quota Akhir',
        'Terpenuhi',
        'Lowongan Berjalan',
        'Quota Tersedia',
        'Pemenuhan (%)',
        'Status',
      ];
      const rows = this.items.map((r) => [
        r.dept_name,
        r.year,
        r.quota_akhir,
        r.terpenuhi,
        r.lowongan_berjalan,
        r.quota_tersedia,
        r.pemenuhan_persen === null || r.pemenuhan_persen === undefined
          ? 'N/A'
          : Math.round(r.pemenuhan_persen * 10) / 10,
        r.status,
      ]);

      const escape = (v) => {
        const s = String(v ?? '');
        return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
      };

      const csv = [headers, ...rows]
        .map((r) => r.map(escape).join(','))
        .join('\n');

      const blob = new Blob(['\ufeff' + csv], {
        type: 'text/csv;charset=utf-8;',
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `Resource_Fulfillments_${new Date()
        .toISOString()
        .slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    },

    onClickDeleteSelected() {
      // Stub method agar event @delete tidak melempar error
    },

    async fetchLocalData() {
      this.isLoading = true;
      try {
        const baseUrl = this.getBaseUrl();
        const response = await fetch(
          baseUrl + '/proxy/get_resource_fulfillment.php',
        );
        const result = await response.json();

        if (result.status === 'success') {
          this.allItems = result.data.map((item, index) => ({
            id: item.id || index + 1,
            dept_name: item.dept_name,
            year: item.year,
            planned: item.planned,
            replacement: item.replacement,
            reduction: item.reduction,
            unplanned: item.unplanned,
            quota_akhir: item.quota_akhir,
            terpenuhi: item.terpenuhi,
            lowongan_berjalan: item.lowongan_berjalan,
            quota_tersedia: item.quota_tersedia,
            pemenuhan_persen:
              item.pemenuhan_persen !== null &&
              item.pemenuhan_persen !== undefined
                ? Number(item.pemenuhan_persen)
                : null,
            status: item.status,
          }));

          // Bangun opsi tahun dari data (hanya tahun unik, terbaru duluan)
          const years = [
            ...new Set(this.allItems.map((r) => Number(r.year))),
          ].sort((a, b) => b - a);
          this.yearOptions = [
            {id: 'all', label: 'All Years'},
            ...years.map((y) => ({id: y, label: String(y)})),
          ];

          // Bangun opsi departemen dari data (distinct, terurut A-Z)
          const depts = [
            ...new Set(
              this.allItems
                .map((r) => String(r.dept_name || '').trim())
                .filter((d) => d !== ''),
            ),
          ].sort((a, b) => a.localeCompare(b));
          this.departmentOptions = [
            {id: 'all', label: 'All Departments'},
            ...depts.map((d) => ({id: d, label: d})),
          ];

          this.applyFilters();
        } else {
          console.error('Error dari server:', result.message);
        }
      } catch (error) {
        console.error('Gagal mengambil data Resource Fulfillments:', error);
      } finally {
        this.isLoading = false;
      }
    },

    applyFilters() {
      const dept = this.filters.department?.id || null;
      const yearId = this.filters.year?.id;
      const status = this.filters.status?.id || null;
      let filtered = this.allItems;
      if (dept && dept !== 'all') {
        filtered = filtered.filter(
          (r) => String(r.dept_name || '').trim() === dept,
        );
      }
      if (yearId && yearId !== 'all') {
        filtered = filtered.filter((r) => Number(r.year) === Number(yearId));
      }
      if (status) {
        filtered = filtered.filter((r) => String(r.status || '') === status);
      }
      this.items = filtered;
      this.totalData = filtered.length;
    },

    filterItems() {
      this.applyFilters();
    },

    onClickReset() {
      this.filters = {
        department: {id: 'all', label: 'All Departments'},
        year: {id: 'all', label: 'All Years'},
        status: null,
      };
      this.applyFilters();
    },

    cellRenderer(...args) {
      const row = args[3];
      return {
        component: ResourceFulfillmentStatusBadge,
        props: {status: row.status},
      };
    },

    cellRendererPersen(...args) {
      const row = args[3];
      return {
        component: FulfillmentProgress,
        props: {percent: row.pemenuhan_persen},
      };
    },

    // ==========================================
    // MODAL DETAIL KUOTA (pola AllQuotaDepartment)
    // ==========================================
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

    // Klik ikon mata → tampilkan detail kuota + case HRPLAN dept+tahun
    async onClickDetail(item) {
      this.selectedDept = item;
      this.showModal = true;
      this.isLoadingDetails = true;
      this.deptCases = [];

      try {
        await this.loginAcumatica();

        const baseUrl = this.getBaseUrl();
        // Tarik data Case HRPLAN (planned) dari Acumatica
        const proxyUrl =
          baseUrl +
          "/proxy/acumatica.php?action=query&endpoint=Case&$filter=(Status eq 'Open' or Status eq 'Closed') and ClassID eq 'HRPLAN'&$expand=Attributes";

        const response = await fetch(proxyUrl, {
          method: 'GET',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
        });

        const result = await response.json();
        const rawCases = Array.isArray(result) ? result : result.value || [];

        const deptName = String(item.dept_name || '').trim();
        const yearStr = String(item.year ?? '');

        // Filter: department cocok DAN tahun (YEAR attribute) cocok dengan baris
        const filteredCases = rawCases.filter((c) => {
          const attributes = c.Attributes || [];
          const getAttr = (id) => {
            const found = attributes.find(
              (a) => a.AttributeID?.value?.toLowerCase() === id.toLowerCase(),
            );
            return found
              ? found.ValueDescription?.value || found.Value?.value
              : null;
          };
          return (
            String(getAttr('department') || '').trim() === deptName &&
            String(getAttr('year') ?? '') === yearStr
          );
        });

        this.deptCases = filteredCases;
      } catch (error) {
        console.error('Gagal menarik rincian case dari Acumatica:', error);
        alert('Gagal menarik rincian data dari Acumatica. Periksa console.');
      } finally {
        await this.logoutAcumatica();
        this.isLoadingDetails = false;
      }
    },

    // Fungsi bantu untuk membaca value attribute TotPerson dari Acumatica
    getTotPerson(attributes) {
      if (!attributes) return 0;
      const attr = attributes.find(
        (a) => a.AttributeID?.value?.toLowerCase() === 'totperson',
      );
      return attr ? attr.ValueDescription?.value || attr.Value?.value || 0 : 0;
    },

    closeModal() {
      this.showModal = false;
      this.selectedDept = null;
      this.deptCases = [];
    },
  },
};
</script>

<style>
/* Export PDF (window.print) — hanya area #section-to-print yang dicetak */
.print-only {
  display: none;
}

@media print {
  @page {
    size: A4 landscape;
    margin: 10mm;
  }

  body * {
    visibility: hidden;
  }

  #section-to-print,
  #section-to-print * {
    visibility: visible;
  }

  #section-to-print {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    padding: 0 !important;
    box-shadow: none !important;
    background: #fff;
  }

  .print-only {
    display: block !important;
  }

  .print-header h2 {
    margin: 0 0 4px;
    font-size: 18px;
    color: #1a1a1a;
  }

  .print-meta {
    font-size: 12px;
    color: #444;
    margin-bottom: 2px;
  }

  .print-date {
    font-size: 11px;
    color: #666;
    margin-bottom: 12px;
  }

  .oxd-card-table,
  .oxd-table,
  .oxd-table-header,
  .oxd-table-body {
    overflow: visible !important;
  }
}
</style>
