<template>
  <div class="orangehrm-background-container">
    <!-- Form Pencarian (Department & Year) -->
    <oxd-table-filter :filter-title="'History Quota Department'">
      <oxd-form @submit-valid="filterItems">
        <oxd-form-row>
          <oxd-grid :cols="2" class="orangehrm-full-width-grid">
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
      <!-- Header Tabel -->
      <table-header
        header-title="History Quota Department"
        :selected="checkedItems.length"
        :total="totalData"
        :loading="isLoading"
        @delete="onClickDeleteSelected"
      ></table-header>

      <!-- Tabel Utama -->
      <div class="orangehrm-container">
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
    <!-- MODAL POPUP DETAIL CASE BERDASARKAN DEPARTEMEN -->
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
            <span style="color: #ff7b1c; font-weight: bold">{{
              selectedDept?.dept_name
            }}</span>
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
              padding: 0;
            "
            @click="closeModal"
          >
            &times;
          </button>
        </div>

        <!-- Body Modal -->
        <div style="overflow-x: auto; width: 100%">
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
              table-layout: fixed; /* KUNCI RAPI: Memaksa kolom mengikuti width % yang kita set */
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
                    width: 25%;
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
                    width: 35%;
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
                <!-- Case ID -->
                <td
                  style="
                    padding: 12px 16px;
                    text-align: center;
                    font-weight: 600;
                    color: #004e8c;
                    word-wrap: break-word; /* Mencegah teks panjang merusak tabel */
                  "
                >
                  {{ c.CaseID?.value || '-' }}
                </td>

                <!-- Subject -->
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

                <!-- Status -->
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
                      display: inline-block;
                    "
                  >
                    {{ c.Status?.value || '-' }}
                  </span>
                </td>

                <!-- TotPerson -->
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
export default {
  name: 'ViewAllQuotaDepartment',
  data() {
    return {
      isLoading: false,
      checkedItems: [], // Ditambahkan agar tidak undefined di table-header
      totalData: 0, // Ditambahkan untuk menampung total record
      items: [], // Data yang akan dimasukkan ke tabel
      allItems: [], // Master data dari proxy (penuh), dipakai sebagai sumber filter
      filters: {
        department: null,
        year: null,
      },
      departmentOptions: [],
      yearOptions: [],

      // --- STATE UNTUK MODAL DETAIL ---
      showModal: false,
      isLoadingDetails: false,
      selectedDept: null,
      deptCases: [], // Menyimpan rincian case dari Acumatica

      // Definisi Kolom (Header) Tabel
      headers: [
        {name: 'dept_name', title: 'Department', style: {flex: 1.5}},
        {name: 'year', title: 'Year', style: {flex: 1}},
        {name: 'branch', title: 'Branch', style: {flex: 1}},
        {
          name: 'planned',
          title: 'Planned',
          style: {flex: 2},
        },
        {name: 'replacement', title: 'Replacement', style: {flex: 1}},
        {name: 'reduction', title: 'Reduction', style: {flex: 1}},
        {name: 'unplanned', title: 'Unplanned', style: {flex: 1}},
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
              props: {
                name: 'eye', // Menggunakan ikon mata
              },
            },
          },
        },
      ],
    };
  },
  mounted() {
    // Panggil data dari database/API seperti biasa
    this.fetchQuotaData();

    // --- TRIK MENGUBAH NAMA HEADER TOPBAR ---
    setTimeout(() => {
      const breadcrumb = document.querySelector(
        '.oxd-topbar-header-breadcrumb',
      );
      if (breadcrumb) {
        breadcrumb.innerHTML = `
          <h4 class="oxd-text oxd-text--h6 oxd-topbar-header-breadcrumb-level">
            History Quota Department
          </h4>
        `;
      }

      document.title = 'History Quota Department';
      const allMenus = document.querySelectorAll('.oxd-main-menu-item');
      allMenus.forEach((menu) => {
        menu.classList.remove('active');
      });

      allMenus.forEach((menu) => {
        if (menu.textContent.trim() === 'History Quota Department') {
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

    async fetchQuotaData() {
      this.isLoading = true;
      try {
        const baseUrl = this.getBaseUrl();
        const response = await fetch(baseUrl + '/proxy/get_all_quota.php');
        const result = await response.json();

        if (result.status === 'success') {
          this.allItems = result.data.map((item, index) => ({
            id: item.id || index + 1,
            dept_name: item.dept_name,
            year: item.year,
            branch: item.branch || '-',
            planned: item.planned,
            total_additional: item.total_additional,
            replacement: item.replacement,
            reduction: item.reduction,
            unplanned: item.unplanned,
          }));

          // Bangun opsi dropdown dari data (distinct, terurut A-Z)
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

          const years = [
            ...new Set(
              this.allItems
                .map((r) => String(r.year ?? '').trim())
                .filter((y) => y !== ''),
            ),
          ].sort((a, b) => a.localeCompare(b));
          this.yearOptions = [
            {id: 'all', label: 'All Years'},
            ...years.map((y) => ({id: y, label: y})),
          ];

          this.applyFilters();
        } else {
          console.error('Error dari server:', result.message);
        }
      } catch (error) {
        console.error('Gagal mengambil data kuota:', error);
      } finally {
        this.isLoading = false;
      }
    },

    // ==========================================
    // FILTER PENCARIAN: Department (exact) + Year (exact)
    // id 'all' = tampilkan semua (bukan filter literal)
    // ==========================================
    applyFilters() {
      const dept = this.filters.department?.id || null;
      const year = this.filters.year?.id || null;

      let filtered = this.allItems;
      if (dept && dept !== 'all') {
        filtered = filtered.filter(
          (r) => String(r.dept_name || '').trim() === dept,
        );
      }
      if (year && year !== 'all') {
        filtered = filtered.filter(
          (r) => String(r.year ?? '').trim() === String(year),
        );
      }

      this.items = filtered;
      this.totalData = filtered.length;
    },

    filterItems() {
      this.applyFilters();
    },

    onClickReset() {
      this.filters = {department: null, year: null};
      this.applyFilters();
    },

    // ==========================================
    // FUNGSI LOGIN & LOGOUT ACUMATICA
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

    // ==========================================
    // FUNGSI SAAT ICON MATA DIKLIK
    // ==========================================
    async onClickDetail(item) {
      this.selectedDept = item;
      this.showModal = true;
      this.isLoadingDetails = true;
      this.deptCases = []; // Kosongkan data sebelumnya

      try {
        await this.loginAcumatica();

        const baseUrl = this.getBaseUrl();
        // Tarik data Case HRPLAN (pastikan endpoint case di Acumatica terkonfigurasi dengan baik)
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

        // Filter: Cocokkan attribute 'department' di Acumatica dengan dept_name pada baris yang diklik
        const filteredCases = rawCases.filter((c) => {
          const attributes = c.Attributes || [];
          const deptAttr = attributes.find(
            (a) => a.AttributeID?.value?.toLowerCase() === 'department',
          );

          const caseDeptName = deptAttr
            ? deptAttr.ValueDescription?.value || deptAttr.Value?.value
            : c.Department?.value || 'Unknown';

          return caseDeptName === item.dept_name;
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
