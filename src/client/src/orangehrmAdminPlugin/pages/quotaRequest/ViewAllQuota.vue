<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-paper-container">
      <!-- Header / Title -->
      <div class="orangehrm-header-container">
        <h6 class="oxd-text oxd-text--h6 orangehrm-main-title">
          All Quota Department
        </h6>
      </div>

      <!-- Tabel Utama -->
      <div class="orangehrm-container">
        <oxd-table>
          <oxd-table-header>
            <oxd-table-row>
              <oxd-table-header-cell>Department</oxd-table-header-cell>
              <oxd-table-header-cell>Year</oxd-table-header-cell>
              <oxd-table-header-cell
                >Total Additional (TotPerson)</oxd-table-header-cell
              >
              <oxd-table-header-cell>Replacement</oxd-table-header-cell>
              <oxd-table-header-cell>Reduction</oxd-table-header-cell>
            </oxd-table-row>
          </oxd-table-header>

          <oxd-table-body>
            <oxd-table-row v-for="(item, index) in quotaData" :key="index">
              <oxd-table-cell>{{ item.dept_name }}</oxd-table-cell>
              <oxd-table-cell>{{ item.year }}</oxd-table-cell>
              <oxd-table-cell>{{ item.total_additional }}</oxd-table-cell>
              <oxd-table-cell>{{ item.replacement }}</oxd-table-cell>
              <oxd-table-cell>{{ item.reduction }}</oxd-table-cell>
            </oxd-table-row>

            <oxd-table-row v-if="quotaData.length === 0 && !isLoading">
              <oxd-table-cell colspan="5" style="text-align: center">
                Tidak ada data kuota departemen.
              </oxd-table-cell>
            </oxd-table-row>
          </oxd-table-body>
        </oxd-table>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ViewAllQuota',
  data() {
    return {
      isLoading: false,
      quotaData: [],
    };
  },
  mounted() {
    this.fetchQuotaData();
  },
  methods: {
    getBaseUrl() {
      const base = window.appGlobal?.baseUrl || '';
      return base.replace('/index.php', '') || '';
    },
    async fetchQuotaData() {
      this.isLoading = true;
      try {
        const baseUrl = this.getBaseUrl();
        const response = await fetch(baseUrl + '/proxy/get_all_quota.php');
        const result = await response.json();

        if (result.status === 'success') {
          this.quotaData = result.data;
        } else {
          console.error('Error dari server:', result.message);
        }
      } catch (error) {
        console.error('Gagal mengambil data kuota:', error);
      } finally {
        this.isLoading = false;
      }
    },
  },
};
</script>
