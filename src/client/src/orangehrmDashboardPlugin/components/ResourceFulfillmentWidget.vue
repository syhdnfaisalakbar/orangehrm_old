<template>
  <base-widget
    icon="performance"
    :empty="isEmpty"
    :loading="isLoading"
    title="Resource Fulfillments"
  >
    <div class="orangehrm-rf-widget">
      <div class="orangehrm-rf-head">
        <span class="orangehrm-rf-year">
          <span class="tf-dot"></span>
          <oxd-text tag="p" class="tf-year-text">
            {{
              selectedYear ? 'Tahun ' + selectedYear : 'Resource Fulfillments'
            }}
          </oxd-text>
        </span>
        <button
          class="orangehrm-rf-link"
          title="Buka Resource Fulfillments"
          @click="goToPage"
        >
          Lihat Semua
        </button>
      </div>

      <div
        v-for="row in displayRows"
        :key="row.dept_name + row.year"
        class="orangehrm-rf-card"
        :class="'rf-card--' + row.cardTone"
      >
        <div class="orangehrm-rf-card-head">
          <oxd-text tag="p" class="orangehrm-rf-dept">
            {{ row.dept_name }}
          </oxd-text>
          <span class="rf-badge" :class="'rf-badge--' + row.cardTone">
            {{ row.statusLabel }}
          </span>
        </div>

        <div class="orangehrm-rf-metrics">
          <div class="orangehrm-rf-metric">
            <oxd-text tag="p" class="rf-metric-num">{{
              row.terpenuhi
            }}</oxd-text>
            <oxd-text tag="p" class="rf-metric-label">Terisi</oxd-text>
          </div>
          <span class="rf-metric-sep">/</span>
          <div class="orangehrm-rf-metric">
            <oxd-text tag="p" class="rf-metric-num">{{
              row.quota_akhir
            }}</oxd-text>
            <oxd-text tag="p" class="rf-metric-label">Kuota</oxd-text>
          </div>
          <oxd-text tag="p" class="rf-metric-psn">
            {{
              row.pemenuhan_persen !== null ? row.pemenuhan_persen + '%' : 'N/A'
            }}
          </oxd-text>
        </div>

        <fulfillment-progress :percent="row.pemenuhan_persen" />
      </div>
    </div>
  </base-widget>
</template>

<script>
import BaseWidget from '@/orangehrmDashboardPlugin/components/BaseWidget.vue';
import FulfillmentProgress from '@/orangehrmAdminPlugin/components/FulfillmentProgress.vue';

export default {
  name: 'ResourceFulfillmentWidget',

  components: {
    'base-widget': BaseWidget,
    'fulfillment-progress': FulfillmentProgress,
  },

  data() {
    return {
      isLoading: false,
      allRows: [],
    };
  },

  computed: {
    isEmpty() {
      return this.displayRows.length === 0;
    },
    // Tampilkan tahun terbaru yang ada di data
    selectedYear() {
      if (!this.allRows.length) return null;
      const years = [...new Set(this.allRows.map((r) => Number(r.year)))].sort(
        (a, b) => b - a,
      );
      return years[0];
    },
    displayRows() {
      const y = this.selectedYear;
      if (y === null) return [];
      return this.allRows.filter((r) => Number(r.year) === y);
    },
  },

  mounted() {
    this.fetchData();
  },

  methods: {
    getBaseUrl() {
      const base = window.appGlobal?.baseUrl || '';
      return base.replace('/index.php', '') || '';
    },

    statusLabelFor(tone) {
      return {
        full: 'Terpenuhi',
        over: 'Lebih',
        progress: 'In Progress',
        low: 'Kurang',
      }[tone];
    },

    async fetchData() {
      this.isLoading = true;
      try {
        const response = await fetch(
          this.getBaseUrl() + '/proxy/get_resource_fulfillment.php',
        );
        const result = await response.json();

        if (result.status === 'success') {
          this.allRows = (result.data || []).map((item) => {
            const quotaAkhir = Number(item.quota_akhir) || 0;
            const terpenuhi = Number(item.terpenuhi) || 0;
            const lowongan = Number(item.lowongan_berjalan) || 0;
            const pemenuhan =
              item.pemenuhan_persen !== null &&
              item.pemenuhan_persen !== undefined
                ? Number(item.pemenuhan_persen)
                : null;
            const tone = (() => {
              if (lowongan > 0) return 'progress';
              const tersedia = quotaAkhir - terpenuhi - lowongan;
              if (tersedia === 0) return 'full';
              if (tersedia < 0) return 'over';
              return 'low';
            })();
            return {
              dept_name: item.dept_name,
              year: Number(item.year),
              quota_akhir: quotaAkhir,
              terpenuhi: terpenuhi,
              lowongan_berjalan: lowongan,
              pemenuhan_persen: pemenuhan,
              cardTone: tone,
              statusLabel: this.statusLabelFor(tone),
            };
          });
        } else {
          console.error('Error dari server:', result.message);
        }
      } catch (error) {
        console.error('Gagal mengambil data Resource Fulfillments:', error);
      } finally {
        this.isLoading = false;
      }
    },

    goToPage() {
      const base = this.getBaseUrl();
      window.location.href =
        base + '/admin/viewSystemUsers?page=resource-fulfillment';
    },
  },
};
</script>

<style lang="scss" scoped>
.orangehrm-rf-widget {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.orangehrm-rf-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2px;
}

.orangehrm-rf-year {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.oxd-text.tf-year-text {
  font-family: 'Nunito Sans', sans-serif;
  font-size: 14px;
  font-weight: 400;
  color: #64728c;
}

.tf-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: linear-gradient(135deg, #ff7b1c, #f0852e);
}

.orangehrm-rf-link {
  background: none;
  border: none;
  color: #ff7b1c;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
}

.orangehrm-rf-link:hover {
  text-decoration: underline;
}

/* ---- Kartu per Departemen ---- */
.orangehrm-rf-card {
  border-radius: 10px;
  padding: 10px 12px;
  border: 1px solid #eef0f2;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.orangehrm-rf-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(15, 23, 42, 0.1);
}

.orangehrm-rf-card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.oxd-text.orangehrm-rf-dept {
  font-family: 'Nunito Sans', sans-serif;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding-right: 6px;
}

/* ---- Warna tone per status ---- */
.rf-card--full {
  background: linear-gradient(135deg, #e9f7ee, #d3f1dd);
  border-color: #b7e5c6;
}
.rf-card--low {
  background: linear-gradient(135deg, #fdece9, #fadbd5);
  border-color: #f3c1b8;
}
.rf-card--over {
  background: linear-gradient(135deg, #e9f0fe, #d6e4fb);
  border-color: #bcd3f6;
}
.rf-card--progress {
  background: linear-gradient(135deg, #fdf6e3, #fbeecb);
  border-color: #f3df9f;
}

/* ---- Badge status ---- */
.rf-badge {
  flex-shrink: 0;
  font-family: 'Nunito Sans', sans-serif;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 12px;
  white-space: nowrap;
}
.rf-badge--full {
  background: #1e8e3e;
  color: #fff;
}
.rf-badge--low {
  background: #c5221f;
  color: #fff;
}
.rf-badge--over {
  background: #1a73e8;
  color: #fff;
}
.rf-badge--progress {
  background: #f9ab00;
  color: #3d2e00;
}

/* ---- Metrics ---- */
.orangehrm-rf-metrics {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
}
.orangehrm-rf-metric {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}
.oxd-text.rf-metric-num {
  font-family: 'Nunito Sans', sans-serif;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
}
.oxd-text.rf-metric-label {
  font-family: 'Nunito Sans', sans-serif;
  font-size: 11px;
  color: #64728c;
}
.rf-metric-sep {
  font-size: 15px;
  font-weight: 600;
  color: #94a3b8;
}
.oxd-text.rf-metric-psn {
  margin-left: auto;
  font-family: 'Nunito Sans', sans-serif;
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
}
</style>
