<template>
  <div class="orangehrm-background-container">
    <!-- Filter Tahun -->
    <oxd-table-filter :filter-title="'Unplanned Request Report'">
      <oxd-form @submit-valid="applyFilters">
        <oxd-form-row>
          <oxd-grid :cols="3" class="orangehrm-full-width-grid">
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

    <div class="orangehrm-paper-container">
      <!-- ===== RINGKASAN PER DEPARTEMEN ===== -->
      <table-header
        header-title="Ringkasan per Departemen"
        :total="summaryItems.length"
        :loading="isLoading"
        @delete="onClickDeleteSelected"
      ></table-header>
      <div class="orangehrm-container">
        <oxd-card-table
          :headers="summaryHeaders"
          :items="summaryItems"
          :selectable="false"
          :clickable="false"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
        />
      </div>

      <!-- Total Ringkasan -->
      <div
        class="orangehrm-bottom-container"
        style="padding: 0.75rem 1rem; display: flex; justify-content: flex-end"
      >
        <oxd-text type="subtitle-2">
          Total: {{ totals.total_request }} request ·
          {{ totals.total_person }} person (Open {{ totals.open }} · Closed
          {{ totals.closed }} · Disetujui {{ totals.approved }})
        </oxd-text>
      </div>

      <br />

      <!-- ===== DETAIL PER REQUEST ===== -->
      <table-header
        header-title="Detail Request"
        :total="filteredDetail.length"
        :loading="isLoading"
        @delete="onClickDeleteSelected"
      ></table-header>
      <div class="orangehrm-container">
        <oxd-card-table
          :headers="detailHeaders"
          :items="detailItems"
          :selectable="false"
          :clickable="false"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
        />
      </div>

      <!-- Pagination (kiri) + Export CSV (kanan) -->
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
        <oxd-button
          display-type="secondary"
          :label="'Export CSV'"
          :disabled="isLoading || filteredDetail.length === 0"
          @click="exportCsv"
        />
      </div>
    </div>
  </div>
</template>

<script>
import ReportStatusBadge from '@/orangehrmAdminPlugin/components/ReportStatusBadge.vue';

export default {
  name: 'ViewUnplannedRequestReport',
  data() {
    return {
      isLoading: false,
      checkedItems: [],
      rawDetail: [], // Semua baris dari proxy (sebelum filter tahun)
      filteredDetail: [], // Hasil filter penuh (sebelum di-paginasi)
      detailItems: [], // Baris yang tampil (1 halaman, default 10)
      summaryItems: [], // Agregasi per dept dari filteredDetail
      currentPage: 1, // Halaman aktif detail (default 1)
      pageSize: 10, // Default 10 baris per halaman
      totals: {
        total_request: 0,
        open: 0,
        closed: 0,
        approved: 0,
        total_person: 0,
      },

      filters: {
        year: {id: 'all', label: 'All Years'},
        department: null,
      },
      yearOptions: [{id: 'all', label: 'All Years'}],
      departmentOptions: [], // Diisi dari data: All Departments + dept unik

      summaryHeaders: [
        {name: 'department', title: 'Department', style: {flex: 1.5}},
        {name: 'total_request', title: 'Jumlah Request', style: {flex: 1}},
        {name: 'open', title: 'Open', style: {flex: 0.7}},
        {name: 'closed', title: 'Closed', style: {flex: 0.7}},
        {name: 'approved', title: 'Disetujui', style: {flex: 0.9}},
        {name: 'total_person', title: 'Total Person', style: {flex: 1}},
      ],

      detailHeaders: [
        {name: 'case_id', title: 'Case ID', style: {flex: 1}},
        {name: 'case_class', title: 'Case Class', style: {flex: 0.8}},
        {name: 'department', title: 'Department', style: {flex: 1.4}},
        {name: 'position', title: 'Position', style: {flex: 1.2}},
        {name: 'subject', title: 'Subject', style: {flex: 1.5}},
        {
          name: 'req_type',
          title: 'Request Type',
          style: {flex: 1},
          cellRenderer: this.cellRendererReqType,
        },
        {
          name: 'req_status',
          title: 'Status',
          style: {flex: 0.9},
          cellRenderer: this.cellRendererStatus,
        },
        {
          name: 'approval_status',
          title: 'Approval',
          style: {flex: 0.9},
          cellRenderer: this.cellRendererApproval,
        },
        {name: 'tot_person', title: 'Person', style: {flex: 0.7}},
        {name: 'target_date', title: 'Target Date', style: {flex: 1}},
        {name: 'created_at', title: 'Created At', style: {flex: 1.1}},
        {name: 'owner', title: 'Owner', style: {flex: 1.2}},
      ],
    };
  },
  computed: {
    // Jumlah halaman detail berdasarkan total data (filtered) / pageSize
    pages() {
      return Math.max(1, Math.ceil(this.filteredDetail.length / this.pageSize));
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

    // Ubah nama header topbar + highlight menu aktif
    setTimeout(() => {
      const breadcrumb = document.querySelector(
        '.oxd-topbar-header-breadcrumb',
      );
      if (breadcrumb) {
        breadcrumb.innerHTML = `
          <h4 class="oxd-text oxd-text--h6 oxd-topbar-header-breadcrumb-level">
            Unplanned Request Report
          </h4>
        `;
      }

      document.title = 'Unplanned Request Report';
      const allMenus = document.querySelectorAll('.oxd-main-menu-item');
      allMenus.forEach((menu) => {
        menu.classList.remove('active');
      });

      allMenus.forEach((menu) => {
        if (menu.textContent.trim() === 'Unplanned Request Report') {
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

    onClickDeleteSelected() {
      // Stub method agar event @delete tidak melempar error
    },

    async fetchLocalData() {
      this.isLoading = true;
      try {
        const baseUrl = this.getBaseUrl();
        const response = await fetch(
          baseUrl + '/proxy/get_unplanned_request_report.php',
        );
        const result = await response.json();

        if (result.status === 'success') {
          this.rawDetail = (result.detail || []).map((item) => ({
            ...item,
            tot_person: Number(item.tot_person) || 0,
          }));

          // Bangun opsi tahun dari data (target_date, fallback created_at)
          const years = [
            ...new Set(
              this.rawDetail.map((r) => this.getYearOf(r)).filter((y) => y > 0),
            ),
          ].sort((a, b) => b - a);
          this.yearOptions = [
            {id: 'all', label: 'All Years'},
            ...years.map((y) => ({id: y, label: String(y)})),
          ];

          // Bangun opsi departemen dari data (dept unik, terurut A-Z)
          const depts = [
            ...new Set(
              this.rawDetail
                .map((r) => (r.department || '').trim())
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
        console.error('Gagal mengambil data Unplanned Request Report:', error);
      } finally {
        this.isLoading = false;
      }
    },

    getYearOf(row) {
      const fromTarget = parseInt(String(row.target_date || '').slice(0, 4));
      if (fromTarget > 0) return fromTarget;
      return parseInt(String(row.created_at || '').slice(0, 4)) || 0;
    },

    applyFilters() {
      const yearId = this.filters.year?.id;
      const deptId = this.filters.department?.id;
      let filtered = this.rawDetail;
      if (yearId && yearId !== 'all') {
        filtered = filtered.filter((r) => this.getYearOf(r) === Number(yearId));
      }
      if (deptId && deptId !== 'all') {
        filtered = filtered.filter(
          (r) => (r.department || '').trim() === deptId,
        );
      }

      this.filteredDetail = filtered;

      // Agregasi summary dari detail yang sudah difilter
      const map = {};
      for (const d of filtered) {
        const dept = d.department || '(tanpa departemen)';
        if (!map[dept]) {
          map[dept] = {
            department: dept,
            total_request: 0,
            open: 0,
            closed: 0,
            approved: 0,
            total_person: 0,
          };
        }
        map[dept].total_request += 1;
        map[dept].total_person += d.tot_person;
        if (String(d.req_status).toUpperCase() === 'OPEN') {
          map[dept].open += 1;
        } else if (String(d.req_status).toUpperCase() === 'CLOSED') {
          map[dept].closed += 1;
        }
        if (String(d.approval_status).toUpperCase() === 'COMPLETED') {
          map[dept].approved += 1;
        }
      }

      this.summaryItems = Object.values(map).sort(
        (a, b) =>
          b.total_request - a.total_request ||
          a.department.localeCompare(b.department),
      );

      this.totals = {
        total_request: 0,
        open: 0,
        closed: 0,
        approved: 0,
        total_person: 0,
      };
      for (const s of this.summaryItems) {
        this.totals.total_request += s.total_request;
        this.totals.open += s.open;
        this.totals.closed += s.closed;
        this.totals.approved += s.approved;
        this.totals.total_person += s.total_person;
      }

      // Reset ke halaman 1 setiap filter berubah, lalu potong halaman
      this.currentPage = 1;
      this.applyPage();
    },

    // Potong filteredDetail sesuai halaman aktif (default 10 baris)
    applyPage() {
      const start = (this.currentPage - 1) * this.pageSize;
      this.detailItems = this.filteredDetail.slice(
        start,
        start + this.pageSize,
      );
    },

    filterItems() {
      // Refetch dari server biar data terbaru muncul tanpa refresh halaman (F5)
      this.fetchLocalData();
    },

    onClickReset() {
      this.filters = {
        year: {id: 'all', label: 'All Years'},
        department: null,
      };
      this.applyFilters();
    },

    cellRendererStatus(...args) {
      const row = args[3];
      return {
        component: ReportStatusBadge,
        props: {status: row.req_status},
      };
    },

    cellRendererApproval(...args) {
      const row = args[3];
      return {
        component: ReportStatusBadge,
        props: {status: row.approval_status},
      };
    },

    cellRendererReqType(...args) {
      const row = args[3];
      return {
        component: ReportStatusBadge,
        props: {status: row.req_type},
      };
    },

    exportCsv() {
      const headers = [
        'Case ID',
        'Case Class',
        'Department',
        'Position',
        'Subject',
        'Request Type',
        'Status',
        'Approval Status',
        'Total Person',
        'Target Date',
        'Created At',
        'Owner',
      ];
      const rows = this.filteredDetail.map((d) => [
        d.case_id,
        d.case_class,
        d.department,
        d.position,
        d.subject,
        d.req_type,
        d.req_status,
        d.approval_status,
        d.tot_person,
        d.target_date,
        d.created_at,
        d.owner,
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
      const yearLabel =
        this.filters.year?.id === 'all' ? 'all' : this.filters.year?.id;
      a.href = url;
      a.download = `unplanned_request_report_${yearLabel}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    },
  },
};
</script>
