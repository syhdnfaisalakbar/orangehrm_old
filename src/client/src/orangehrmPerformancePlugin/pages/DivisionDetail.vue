<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-card-container">
      <div class="orangehrm-dashboard-header">
        <oxd-text type="card-title">Division Detail</oxd-text>
        <oxd-text type="subtitle-2">
          Ringkasan kinerja KPI per divisi. Klik sebuah divisi untuk melihat
          detail indikatornya.
        </oxd-text>
      </div>

      <oxd-divider />

      <div class="orangehrm-dp-filter">
        <oxd-form @submit-valid="onSearch" @reset="onReset">
          <oxd-form-row>
            <oxd-grid :cols="4" class="orangehrm-full-width-grid">
              <oxd-grid-item>
                <oxd-input-field
                  v-model="draftDivisi"
                  type="select"
                  :label="'Divisi'"
                  :options="divisionOptions"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="draftTahun"
                  type="select"
                  :label="'Tahun'"
                  :options="tahunOptions"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="draftBulan"
                  type="select"
                  :label="'Bulan'"
                  :options="bulanOptions"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="draftCabang"
                  type="select"
                  :label="'Cabang'"
                  :options="cabangOptions"
                />
              </oxd-grid-item>
            </oxd-grid>
          </oxd-form-row>

          <oxd-divider />

          <oxd-form-actions>
            <oxd-button display-type="ghost" label="Reset" type="reset" />
            <oxd-button
              class="orangehrm-left-space"
              display-type="secondary"
              label="Search"
              type="submit"
            />
            <oxd-button
              class="orangehrm-left-space"
              display-type="secondary"
              :label="'Export PDF'"
              type="button"
              @click="onClickExportPdf"
            />
            <!-- 2026-10-15: tombol "Export Excel" (.xlsx, zero-dep) di sebelah
                 Export PDF. Warna success (hijau) supaya beda dari Export PDF.
                 Diexport = SEMUA baris hasil filter (bukan hanya halaman
                 pagination aktif), isinya mirror tabel + baris total periode. -->
            <oxd-button
              class="orangehrm-left-space"
              display-type="success"
              :label="'Export Excel'"
              type="button"
              :disabled="isLoading || periodeRows.length === 0"
              @click="exportExcel"
            />
          </oxd-form-actions>
        </oxd-form>
      </div>

      <div v-if="ytdValue !== null" class="orangehrm-gauge-card">
        <div class="orangehrm-gauge-header">
          <oxd-text tag="p" class="orangehrm-gauge-title">
            Pencapaian Divisi
          </oxd-text>
          <oxd-text tag="p" class="orangehrm-gauge-sub">
            {{ gaugeSubtitle }}
          </oxd-text>
        </div>

        <div class="orangehrm-gauge-body">
          <svg
            class="orangehrm-gauge-svg"
            viewBox="0 0 220 130"
            xmlns="http://www.w3.org/2000/svg"
          >
            <!-- busur latar (abu) menggunakan track penuh -->
            <path
              :d="gaugeTrackPath"
              fill="none"
              stroke="#e5e7eb"
              stroke-width="16"
              stroke-linecap="round"
            ></path>
            <!-- busur nilai (warna status) -->
            <path
              :d="gaugeArcPath"
              fill="none"
              :stroke="ytdStatus"
              stroke-width="16"
              stroke-linecap="round"
            ></path>
            <!-- jarum -->
            <line
              :x1="gaugeNeedle.x1"
              :y1="gaugeNeedle.y1"
              :x2="gaugeNeedle.x2"
              :y2="gaugeNeedle.y2"
              stroke="#1e293b"
              stroke-width="3"
              stroke-linecap="round"
            ></line>
            <circle cx="110" cy="110" r="6" fill="#1e293b"></circle>
            <!-- nilai -->
            <text
              x="110"
              y="78"
              text-anchor="middle"
              class="orangehrm-gauge-value-text"
            >
              {{ ytdDisplay }}
            </text>
            <text
              x="110"
              y="97"
              text-anchor="middle"
              class="orangehrm-gauge-status-text"
            >
              {{ ytdStatusText }}
            </text>
          </svg>
        </div>

        <div class="orangehrm-gauge-legend">
          <span class="gauge-legend-item">
            <i
              class="gauge-dot"
              :style="{background: statusColors.outstanding}"
            ></i>
            Outstanding
            <em class="gauge-legend-range">≥100</em>
          </span>
          <span class="gauge-legend-item">
            <i class="gauge-dot" :style="{background: statusColors.meet}"></i>
            Meet Expectation
            <em class="gauge-legend-range">90–99</em>
          </span>
          <span class="gauge-legend-item">
            <i
              class="gauge-dot"
              :style="{background: statusColors.attention}"
            ></i>
            Attention
            <em class="gauge-legend-range">80–89</em>
          </span>
          <span class="gauge-legend-item">
            <i
              class="gauge-dot"
              :style="{background: statusColors.warning}"
            ></i>
            Warning
            <em class="gauge-legend-range">&lt;80</em>
          </span>
        </div>
      </div>

      <div v-if="chartData.length" class="orangehrm-chart-card">
        <div class="orangehrm-chart-header">
          <oxd-text tag="p" class="orangehrm-chart-title">
            {{ chartTitle }}
          </oxd-text>
          <div class="orangehrm-chart-legend">
            <span class="chart-legend-item">
              <i
                class="chart-dot"
                :style="{background: statusColors.outstanding}"
              ></i>
              Outstanding
            </span>
            <span class="chart-legend-item">
              <i class="chart-dot" :style="{background: statusColors.meet}"></i>
              Meet
            </span>
            <span class="chart-legend-item">
              <i
                class="chart-dot"
                :style="{background: statusColors.attention}"
              ></i>
              Attention
            </span>
            <span class="chart-legend-item">
              <i
                class="chart-dot"
                :style="{background: statusColors.warning}"
              ></i>
              Warning
            </span>
            <span class="chart-legend-item">
              <i class="chart-dot" :style="{background: statusColors.grey}"></i>
              Belum Diisi
            </span>
          </div>
        </div>
        <oxd-bar-chart
          :data="chartData"
          :aspect-ratio="false"
          wrapper-classes="orangehrm-kpi-chart"
        ></oxd-bar-chart>
      </div>

      <div v-if="isLoading" class="orangehrm-dp-loading">
        <oxd-text>Memuat data…</oxd-text>
      </div>

      <div v-else-if="!periodeRows.length" class="orangehrm-dp-empty">
        <oxd-text type="subtitle-2">
          Tidak ada data indikator untuk divisi/periode ini.
        </oxd-text>
      </div>

      <div v-else class="orangehrm-table-wrap">
        <table class="oxd-table-data orangehrm-kpi-table">
          <thead>
            <tr>
              <th style="width: 26%">Indikator</th>
              <th style="width: 12%">Periode</th>
              <th style="width: 10%">Cabang</th>
              <th style="width: 14%">Divisi</th>
              <th style="width: 14%">Skor</th>
              <th style="width: 24%">Status</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="g in paginatedPeriodGroups" :key="g.periode">
              <tr v-for="r in g.rows" :key="`${r.setup_id}-${r.periode}`">
                <td>
                  <div class="orangehrm-indikator">{{ r.indikator }}</div>
                </td>
                <td class="orangehrm-periode">
                  {{ formatPeriode(r.periode) }}
                </td>
                <td>
                  <span class="orangehrm-cabang">{{ r.cabang || 'HO' }}</span>
                </td>
                <td>
                  <span class="orangehrm-divisi">{{ r.divisi }}</span>
                </td>
                <td class="orangehrm-num">
                  <span :class="skorClass(r.skor)">{{
                    skorDisplayRow(r)
                  }}</span>
                </td>
                <td>
                  <span :class="statusClass(r.skor)">{{
                    statusTextFor(r.skor)
                  }}</span>
                </td>
              </tr>
              <tr class="orangehrm-total-row">
                <td class="orangehrm-bold">
                  Total Skor Rata-rata ({{ formatPeriode(g.periode) }})
                </td>
                <td class="orangehrm-periode">
                  {{ formatPeriode(g.periode) }}
                </td>
                <td></td>
                <td></td>
                <td class="orangehrm-num orangehrm-bold">
                  <span :class="groupScoreColorClass(g.full)">{{
                    groupSkorDisplay(g.full)
                  }}</span>
                </td>
                <td>
                  <span :class="groupBadge(g.full)">{{
                    groupStatus(g.full)
                  }}</span>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
        <div
          v-if="pages > 1"
          class="orangehrm-bottom-container orangehrm-pagination"
        >
          <oxd-pagination v-model:current="currentPage" :length="pages" />
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import {OxdBarChart} from '@ohrm/oxd';
import {buildGroupedTablePdf} from '../utils/kpiReportPdf';
import {
  downloadXlsx,
  statusStyleForColor,
  XLSX_STYLE,
} from '../utils/xlsxReport';
import Swal from 'sweetalert2';

// Kolom file Export Excel (.xlsx) — mirror kolom tabel Division Detail.
const EXPORT_COLUMNS = [
  {title: 'Indikator', width: 38},
  {title: 'Periode', width: 14},
  {title: 'Cabang', width: 12},
  {title: 'Divisi', width: 18},
  {title: 'Skor', width: 10},
  {title: 'Status', width: 26},
];

// Jumlah BARIS per halaman tabel Division Detail. Pagination dipotong per BARIS
// supaya halaman tetap pendek/rapi walau satu periode berisi banyak indikator
// (mis. filter "Semua Divisi"). Baris "Total Skor Rata-rata" tiap periode tetap
// ditampilkan di bawah potongan barisnya, dihitung dari SELURUH baris grup itu.
const PAGE_SIZE = 10;

const STATUS_COLOR = {
  outstanding: '#1d4ed8',
  meet: '#15803d',
  attention: '#f59e0b',
  warning: '#dc2626',
  grey: '#94a3b8',
};

const statusColor = (s) => {
  if (s === null || s === undefined) return STATUS_COLOR.grey;
  if (s >= 100) return STATUS_COLOR.outstanding;
  if (s >= 90) return STATUS_COLOR.meet;
  if (s >= 80) return STATUS_COLOR.attention;
  return STATUS_COLOR.warning;
};

const statusInfo = (s) => {
  if (s === null || s === undefined)
    return {text: 'Belum diisi', cls: 'status-grey'};
  if (s >= 100) return {text: 'Outstanding Performance', cls: 'status-blue'};
  if (s >= 90) return {text: 'Meet Expectation', cls: 'status-green'};
  if (s >= 80) return {text: 'Attention', cls: 'status-yellow'};
  return {text: 'Warning', cls: 'status-red'};
};
const BULAN = [
  'Januari',
  'Februari',
  'Maret',
  'April',
  'Mei',
  'Juni',
  'Juli',
  'Agustus',
  'September',
  'Oktober',
  'November',
  'Desember',
];

export default {
  name: 'DivisionDetail',
  components: {
    'oxd-bar-chart': OxdBarChart,
  },
  props: {
    empNumber: {type: Number, default: 0},
  },
  data() {
    return {
      isLoading: true,
      rows: [],
      selectedDivisi: null,
      draftDivisi: null,
      selectedTahun: null,
      draftTahun: null,
      selectedBulan: null,
      draftBulan: null,
      selectedCabang: null,
      draftCabang: null,
      currentPage: 1,
    };
  },
  computed: {
    statusColors() {
      return STATUS_COLOR;
    },
    // Label filter aktif — dipakai header print (Export PDF).
    divisiLabel() {
      return this.selectedDivisi?.label || 'Semua Divisi';
    },
    cabangLabel() {
      return this.selectedCabang?.label || 'Semua Cabang';
    },
    tahunLabel() {
      return this.selectedTahun?.label || 'Semua Tahun';
    },
    bulanLabel() {
      return this.selectedBulan?.label || 'Semua Bulan';
    },
    exportDateLabel() {
      const d = new Date();
      const tgl = d.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
      });
      const jam = d.toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
      });
      return `${tgl} ${jam}`;
    },
    chartTahun() {
      // Tahun yang dipakai chart/gauge: tahun terpilih, jika ada; jika belum,
      // tahun terbaru dari baris yang sudah kena filter (divisi+periode).
      const thn = this.selectedTahun?.id ?? this.selectedTahun ?? 'all';
      if (thn !== 'all') return parseInt(String(thn), 10);
      let tahun = null;
      this.periodeRows.forEach((r) => {
        if (r.periode) {
          const y = parseInt(String(r.periode).slice(0, 4), 10);
          if (!Number.isNaN(y) && (tahun === null || y > tahun)) tahun = y;
        }
      });
      return tahun;
    },
    // Bulan terpilih pada filter ("all" bila belum dipilih spesifik). Dipakai
    // utk menyempitkan chart jadi 1 bar.
    activeBulan() {
      const id = this.selectedBulan?.id ?? this.selectedBulan ?? 'all';
      return id === 'all' ? 'all' : parseInt(String(id), 10);
    },
    // Rata-rata PER DIVISI dari baris yang sedang terfilter (divisi + tahun +
    // bulan): tiap divisi dihitung rata-rata dulu, lalu dirata-rata antar divisi
    // (tiap divisi berbobot sama). SAMA dengan metode Dashboard Divisi, jadi
    // angkanya identik saat filter = Semua Divisi / Semua Tahun / Semua Bulan.
    // (Keputusan Pak 2026-10-07; sebelumnya rata-rata per BARIS + Year-to-Date.)
    ytdValue() {
      const perDivisi = new Map();
      this.periodeRows.forEach((r) => {
        if (!r.divisi) return;
        const skor = this.computeScoreOf(r);
        if (skor === null) return;
        if (!perDivisi.has(r.divisi))
          perDivisi.set(r.divisi, {sum: 0, count: 0});
        const g = perDivisi.get(r.divisi);
        g.sum += skor;
        g.count += 1;
      });
      if (!perDivisi.size) return null;
      const avgPerDivisi = Array.from(perDivisi.values()).map(
        (g) => g.sum / g.count,
      );
      const avg = avgPerDivisi.reduce((a, b) => a + b, 0) / avgPerDivisi.length;
      return Math.round(avg * 100) / 100;
    },
    // Teks angka gauge: 2 desimal, sama formatnya dengan Dashboard Divisi.
    ytdDisplay() {
      return this.ytdValue === null ? '—' : this.ytdValue.toFixed(2) + '%';
    },
    // Subjudul gauge ikut menyebut filter periode yang sedang aktif.
    gaugeSubtitle() {
      if (
        this.tahunLabel === 'Semua Tahun' &&
        this.bulanLabel === 'Semua Bulan'
      )
        return 'Rata-rata seluruh divisi (semua periode)';
      return `Rata-rata seluruh divisi (${this.bulanLabel}, ${this.tahunLabel})`;
    },
    ytdStatus() {
      return this.ytdValue === null ? null : statusColor(this.ytdValue);
    },
    ytdStatusText() {
      const s = this.ytdValue;
      if (s === null) return 'Belum ada data';
      return statusInfo(s).text;
    },
    // Nilai YTD stabil sbg angka (null/undefined → 0), utk dipakai geometri SVG.
    safeYtdValue() {
      const v = Number(this.ytdValue);
      if (Number.isNaN(v)) return 0;
      return Math.min(100, Math.max(0, v));
    },
    // Geometri gauge: busur separuh lingkaran (kiri→atas→kanan) di koordinat SVG.
    // buildArc hidup di methods (berargumen, dipanggil imperatif) — JANGAN taruh
    // di computed karena computed getter tidak boleh dipanggil dengan argumen
    // (menyebabkan "Cannot convert object to primitive value" saat render).
    gaugeArcPath() {
      const v = this.safeYtdValue;
      return this.buildArc(v, 90);
    },
    gaugeTrackPath() {
      return this.buildArc(100, 90);
    },
    gaugeNeedle() {
      const v = this.safeYtdValue;
      const cx = 110;
      const cy = 110;
      const r = 66;
      const rad = (deg) => (deg * Math.PI) / 180;
      const end = 180 - (180 * v) / 100;
      const penunjuk = {
        x: cx + r * Math.cos(rad(end)),
        y: cy - r * Math.sin(rad(end)),
      };
      return {
        x1: cx,
        y1: cy,
        x2: penunjuk.x.toFixed(2),
        y2: penunjuk.y.toFixed(2),
      };
    },
    divisionOptions() {
      const set = new Set();
      this.rows.forEach((r) => r.divisi && set.add(r.divisi));
      const divisiList = Array.from(set)
        .sort()
        .map((d) => ({id: d, label: d}));
      // Opsi "Semua Divisi" selalu pertama → juga jadi default saat halaman dibuka.
      return [{id: 'all', label: 'Semua Divisi'}, ...divisiList];
    },
    divisiRows() {
      const d = this.selectedDivisi;
      if (!d) return [];
      const id = d.id ?? d; // oxd select: v-model = object opsi {id,label}
      if (id === 'all') return this.rows;
      return this.rows.filter((r) => r.divisi === id);
    },
    tahunOptions() {
      // Ambil dari divisiRows (seluruh scope divisi), BUKAN periodeRows, supaya
      // semua tahun tetap tampil walau sudah terpilih/pilih tahun tertentu.
      const set = new Set();
      this.divisiRows.forEach((r) => {
        if (/^\d{4}-/.test(String(r.periode || '')))
          set.add(String(r.periode).slice(0, 4));
      });
      const tahunList = Array.from(set)
        .sort((a, b) => Number(b) - Number(a))
        .map((y) => ({id: y, label: y}));
      return [{id: 'all', label: 'Semua Tahun'}, ...tahunList];
    },
    bulanOptions() {
      // Selalu 12 bulan penuh (bulan tanpa data tetap tampil; nilainya 0).
      const bulanList = Array.from({length: 12}, (_, i) => ({
        id: String(i + 1),
        label: BULAN[i],
      }));
      return [{id: 'all', label: 'Semua Bulan'}, ...bulanList];
    },
    // Cabang unik dari baris divisi terpilih (NULL/'' ditampilkan sebagai 'HO').
    // Ambil dari divisiRows (bukan rows mentah) supaya saat divisi dipilih,
    // daftar cabang ikut menyempit — konsisten dgn cara tahunOptions diambil.
    cabangOptions() {
      const set = new Set();
      this.divisiRows.forEach((r) => set.add(r.cabang || 'HO'));
      const cabangList = Array.from(set)
        .sort()
        .map((c) => ({id: c, label: c}));
      return [{id: 'all', label: 'Semua Cabang'}, ...cabangList];
    },
    // Baris divisi terpilih yang juga sudah difilter tahun, bulan & cabang.
    periodeRows() {
      const thn = this.selectedTahun?.id ?? this.selectedTahun ?? 'all';
      const bln = this.selectedBulan?.id ?? this.selectedBulan ?? 'all';
      const kab = this.selectedCabang?.id ?? this.selectedCabang ?? 'all';
      return this.divisiRows.filter((r) => {
        const mP = String(r.periode || '').match(/^(\d{4})-(\d{2})$/);
        if (thn !== 'all') {
          if (!mP || mP[1] !== String(thn)) return false;
        }
        if (bln !== 'all') {
          if (!mP || mP[2] !== String(bln).padStart(2, '0')) return false;
        }
        if (kab !== 'all' && (r.cabang || 'HO') !== String(kab)) return false;
        return true;
      });
    },
    // Judul chart dinamis: menyempit jadi "Rata-rata Skor KPI — Bulan Tahun"
    // bila bulan spesifik dipilih, selain itu judul per-bulan setahun.
    chartTitle() {
      const thn = this.chartTahun;
      const bln = this.activeBulan;
      if (thn === null) return 'Rata-rata Skor KPI';
      if (bln !== 'all') {
        const ym = `${thn}-${String(bln).padStart(2, '0')}`;
        return `Rata-rata Skor KPI — ${this.formatPeriode(
          ym,
        )} (rata-rata per divisi)`;
      }
      return `Rata-rata Skor KPI per Bulan (rata-rata per divisi, ${thn})`;
    },
    periodeGroups() {
      const map = new Map();
      for (const r of this.periodeRows) {
        const key = r.periode || '';
        if (!map.has(key)) map.set(key, {periode: r.periode, rows: []});
        map.get(key).rows.push(r);
      }
      return Array.from(map.values()).sort((a, b) =>
        String(b.periode).localeCompare(String(a.periode)),
      );
    },
    // Datar baris (row) beserta grup periodenya — dasar pagination per BARIS.
    flatRows() {
      const out = [];
      this.periodeGroups.forEach((g) => {
        g.rows.forEach((r) => out.push({g, r}));
      });
      return out;
    },
    // Jumlah halaman = ceil(jumlah BARIS / PAGE_SIZE).
    pages() {
      return Math.max(1, Math.ceil(this.flatRows.length / PAGE_SIZE));
    },
    // Potong per BARIS, lalu bentuk ulang grup periode dari baris halaman ini.
    // Tiap grup membawa `full` (grup periode LENGKAP) supaya baris "Total Skor
    // Rata-rata" tetap dihitung dari seluruh baris grup, bukan cuma potongan.
    paginatedPeriodGroups() {
      const start = (this.currentPage - 1) * PAGE_SIZE;
      const slice = this.flatRows.slice(start, start + PAGE_SIZE);
      const groups = [];
      let cur = null;
      for (const item of slice) {
        if (!cur || cur.full !== item.g) {
          cur = {periode: item.g.periode, rows: [], full: item.g};
          groups.push(cur);
        }
        cur.rows.push(item.r);
      }
      return groups;
    },
    chartData() {
      const BULAN_S = [
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'Mei',
        'Jun',
        'Jul',
        'Agu',
        'Sep',
        'Okt',
        'Nov',
        'Des',
      ];
      if (!this.periodeRows.length) return [];
      // Tahun data (terpilih atau terbaru dari baris yang sudah difilter)
      const tahun = this.chartTahun;
      if (tahun === null) return [];
      // Rata-rata PER DIVISI tiap bulan: tiap divisi dirata-rata dulu, lalu
      // dirata-rata antar divisi — SEJALAN dengan gauge & baris tabel di halaman
      // ini dan dengan Dashboard Divisi. (Sebelumnya rata-rata per BARIS.)
      const perBulanDivisi = Array.from({length: 12}, () => new Map());
      this.periodeRows.forEach((r) => {
        const mP = String(r.periode || '').match(/^(\d{4})-(\d{2})$/);
        if (!mP || parseInt(mP[1], 10) !== tahun) return;
        const m = parseInt(mP[2], 10) - 1;
        if (m < 0 || m > 11) return;
        const skor = this.computeScoreOf(r);
        if (skor === null) return;
        if (!r.divisi) return;
        const map = perBulanDivisi[m];
        if (!map.has(r.divisi)) map.set(r.divisi, {sum: 0, count: 0});
        const g = map.get(r.divisi);
        g.sum += skor;
        g.count += 1;
      });
      const bar = (i) => {
        const map = perBulanDivisi[i];
        if (!map.size) {
          return {value: 0, label: BULAN_S[i], color: statusColor(null)};
        }
        const avgPerDivisi = Array.from(map.values()).map(
          (g) => g.sum / g.count,
        );
        const avg =
          avgPerDivisi.reduce((a, b) => a + b, 0) / avgPerDivisi.length;
        return {
          value: Math.round(avg * 100) / 100,
          label: BULAN_S[i],
          color: statusColor(avg),
        };
      };
      // Bulan spesifik dipilih -> tampilkan HANYA 1 bar bulan tsb.
      const bln = this.activeBulan;
      if (bln !== 'all') return [bar(bln - 1)];
      // Selain itu 12 bulan penuh (bulan kosong tampil 0).
      return perBulanDivisi.map((_, i) => bar(i));
    },
  },
  watch: {
    // Saat filter berubah (Search/Reset) atau data dimuat ulang, pastikan halaman
    // aktif tidak melebihi jumlah halaman baru.
    periodeGroups() {
      if (this.pages < 1) this.currentPage = 1;
      else if (this.currentPage > this.pages) this.currentPage = this.pages;
    },
  },
  mounted() {
    this.fetchData();
  },
  methods: {
    // Export PDF: generate file PDF langsung dari DATA (bukan dari DOM/print
    // browser) lalu auto-download. Isi PDF = tabelnya saja (Indikator, Periode,
    // Cabang, Divisi, Skor, Status + baris total per periode) — gauge & chart
    // sengaja TIDAK ikut. Zero dependency: pakai util buildGroupedTablePdf.
    async onClickExportPdf() {
      try {
        const groups = this.periodeGroups.map((g) => ({
          label: `Periode: ${this.formatPeriode(g.periode)}`,
          rows: g.rows.map((r) => [
            r.indikator,
            this.formatPeriode(r.periode),
            r.cabang || 'HO',
            r.divisi,
            this.skorDisplayRow(r),
            this.statusTextFor(r.skor),
          ]),
          total: [
            `Total Skor Rata-rata (${this.formatPeriode(g.periode)})`,
            this.formatPeriode(g.periode),
            '',
            '',
            this.groupSkorDisplay(g),
            this.groupStatus(g),
          ],
        }));

        if (!groups.length) {
          this.$toast.error('Tidak ada data untuk di-export.');
          return;
        }

        const bytes = buildGroupedTablePdf({
          title: 'Division Detail - Laporan KPI',
          info: [
            `Divisi: ${this.divisiLabel}    Tahun: ${this.tahunLabel}    Bulan: ${this.bulanLabel}    Cabang: ${this.cabangLabel}`,
            `Dicetak: ${this.exportDateLabel}`,
          ],
          columns: [
            {label: 'Indikator', width: 300},
            {label: 'Periode', width: 75},
            {label: 'Cabang', width: 65},
            {label: 'Divisi', width: 95},
            {label: 'Skor', width: 60, align: 'right'},
            {label: 'Status', width: 199},
          ],
          groups,
          footer: 'OrangeHRM - PMS KPI',
        });

        const blob = new Blob([bytes], {type: 'application/pdf'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = this.buildExportFileName();
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(() => URL.revokeObjectURL(url), 2000);
        await Swal.fire({
          icon: 'success',
          title: 'Export Berhasil',
          text: 'File PDF berhasil dibuat.',
          confirmButtonText: 'OK',
        });
      } catch (e) {
        this.$toast.error('Gagal membuat PDF: ' + e.message);
      }
    },
    buildExportFileName(ext = 'pdf') {
      const slug = (value) =>
        String(value || '')
          .replace(/[^A-Za-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '');
      const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
      return `Division-Detail-KPI-${slug(this.divisiLabel)}-${slug(
        this.tahunLabel,
      )}-${slug(this.bulanLabel)}-${slug(this.cabangLabel)}-${stamp}.${ext}`;
    },
    // --- Export Excel (.xlsx, zero-dep) ---------------------------------
    // Diexport = SEMUA baris hasil filter (bukan hanya halaman pagination
    // aktif), bentuknya mirror tabel: baris indikator + baris "Total Skor
    // Rata-rata" tiap periode. Zero dependency: pakai util xlsxReport.
    exportExcel() {
      const groups = this.periodeGroups;
      if (!groups.length) {
        this.$toast.error('Tidak ada data untuk di-export.');
        return;
      }
      const rows = [];
      rows.push([{v: 'Division Detail - Laporan KPI', s: XLSX_STYLE.TITLE}]);
      rows.push([
        {
          v: `Divisi: ${this.divisiLabel}    Tahun: ${this.tahunLabel}    Bulan: ${this.bulanLabel}    Cabang: ${this.cabangLabel}`,
          s: XLSX_STYLE.META,
        },
      ]);
      rows.push([{v: `Dicetak: ${this.exportDateLabel}`, s: XLSX_STYLE.META}]);
      rows.push([]);
      rows.push(
        EXPORT_COLUMNS.map((c) => ({v: c.title, s: XLSX_STYLE.HEADER})),
      );
      for (const g of groups) {
        for (const r of g.rows) rows.push(this.exportDataRow(r));
        rows.push(this.exportTotalRow(g));
      }
      downloadXlsx({
        fileName: this.buildExportFileName('xlsx'),
        sheetName: 'Division Detail',
        columns: EXPORT_COLUMNS,
        rows,
      });
    },
    // Baris indikator: nilai yang TAMPIL di tabel (Skor dari computeScoreOf,
    // Status dari skor server) supaya file == layar == Export PDF.
    exportDataRow(r) {
      const skor = this.computeScoreOf(r);
      return [
        {v: r.indikator || '', s: XLSX_STYLE.CELL},
        {v: this.formatPeriode(r.periode), s: XLSX_STYLE.CENTER},
        {v: r.cabang || 'HO', s: XLSX_STYLE.CENTER},
        {v: r.divisi || '', s: XLSX_STYLE.CELL},
        {v: skor === null ? '' : Math.round(skor * 10) / 10, s: XLSX_STYLE.PCT},
        {
          v: this.statusTextFor(r.skor),
          s: statusStyleForColor(statusInfo(r.skor).cls),
        },
      ];
    },
    // Baris TOTAL per periode (pita abu) + skor rata-rata & statusnya.
    exportTotalRow(g) {
      const grey = {v: '', s: XLSX_STYLE.TOTAL_LABEL};
      const skor = this.groupSkor(g);
      return [
        {
          v: `Total Skor Rata-rata (${this.formatPeriode(g.periode)})`,
          s: XLSX_STYLE.TOTAL_LABEL,
        },
        {...grey},
        {...grey},
        {...grey},
        {
          v: skor === null ? '' : Math.round(skor * 10) / 10,
          s: XLSX_STYLE.TOTAL_PCT,
        },
        {
          v: this.groupStatus(g),
          s: statusStyleForColor(statusInfo(skor).cls),
        },
      ];
    },
    // Susun busur dome kiri(180°)→atas(90°)→kanan(0°) sbg polyline (dialamatkan
    // hanya dari computed gauge — dipanggil dengan argumen eksplisit).
    buildArc(value, r) {
      const cx = 110;
      const cy = 110;
      const rad = (deg) => (deg * Math.PI) / 180;
      const end = 180 - (180 * Number(value)) / 100;
      const n = 48;
      const pts = [];
      for (let i = 0; i <= n; i++) {
        const deg = 180 - ((180 - end) * i) / n;
        const x = cx + Number(r) * Math.cos(rad(deg));
        const y = cy - Number(r) * Math.sin(rad(deg));
        pts.push(`${x.toFixed(2)} ${y.toFixed(2)}`);
      }
      return `M ${pts.join(' L ')}`;
    },
    getBaseUrl() {
      const base = window.appGlobal?.baseUrl || '';
      return base.replace('/index.php', '') || '';
    },
    skorClass(s) {
      return `orangehrm-skor ${statusInfo(s).cls}`;
    },
    statusClass(s) {
      return `orangehrm-status ${statusInfo(s).cls}`;
    },
    statusTextFor(s) {
      return statusInfo(s).text;
    },
    skorDisplayRow(r) {
      const s = this.computeScoreOf(r);
      return s === null ? '—' : s.toFixed(1) + '%';
    },
    computeScoreOf(r) {
      if (
        r.realisasi === null ||
        r.realisasi === '' ||
        r.realisasi === undefined
      ) {
        return null;
      }
      const lower = !!r.lower;
      const target = Number(r.target) || 0;
      const real = Number(r.realisasi);
      if (target <= 0) return null;
      const s = lower ? (target / (real || 1)) * 100 : (real / target) * 100;
      return Math.min(100, s);
    },
    // Rata-rata PER DIVISI untuk satu periode (grup tabel): tiap divisi
    // dirata-rata dulu, lalu dirata-rata antar divisi — jadi baris "Total Skor
    // Rata-rata (Periode X)" angkanya sama dengan batang bulan X di diagram.
    groupSkor(g) {
      const perDivisi = new Map();
      for (const r of g.rows) {
        if (!r.divisi) continue;
        const s = this.computeScoreOf(r);
        if (s === null || s === undefined) continue;
        if (!perDivisi.has(r.divisi))
          perDivisi.set(r.divisi, {sum: 0, count: 0});
        const d = perDivisi.get(r.divisi);
        d.sum += s;
        d.count += 1;
      }
      if (!perDivisi.size) return null;
      const avgPerDivisi = Array.from(perDivisi.values()).map(
        (d) => d.sum / d.count,
      );
      return avgPerDivisi.reduce((a, b) => a + b, 0) / avgPerDivisi.length;
    },
    groupSkorDisplay(g) {
      const s = this.groupSkor(g);
      return s === null ? '—' : s.toFixed(1) + '%';
    },
    groupStatus(g) {
      const s = this.groupSkor(g);
      return s === null ? 'Belum diisi' : statusInfo(s).text;
    },
    groupBadge(g) {
      const s = this.groupSkor(g);
      return `orangehrm-status ${statusInfo(s).cls}`;
    },
    groupScoreColorClass(g) {
      const map = {
        'status-blue': 'orangehrm-skor-blue',
        'status-green': 'orangehrm-skor-green',
        'status-yellow': 'orangehrm-skor-yellow',
        'status-red': 'orangehrm-skor-red',
        'status-grey': 'orangehrm-skor-grey',
      };
      const s = this.groupSkor(g);
      return map[statusInfo(s).cls] || 'orangehrm-skor-grey';
    },
    formatPeriode(ym) {
      if (!ym) return '—';
      const p = String(ym).split('-');
      if (p.length !== 2) return ym;
      const m = parseInt(p[1], 10);
      if (Number.isNaN(m) || m < 1 || m > 12) return ym;
      return `${BULAN[m - 1]} ${p[0]}`;
    },
    formatBulan(m) {
      if (!m || m < 1 || m > 12) return m || '';
      return BULAN[m - 1];
    },
    async fetchData() {
      this.isLoading = true;
      try {
        const params = new URLSearchParams({emp: String(this.empNumber)});
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/get_kpi_realisasi.php?${params}`,
        );
        const json = await res.json();
        this.rows = json.status ? json.data?.rows || [] : [];
        // default divisi = Semua Divisi (draft + applied), simpan object opsi
        if (this.divisionOptions.length) {
          const semua = this.divisionOptions[0];
          this.draftDivisi = semua;
          this.selectedDivisi = semua;
        }
        // default periode = Semua Tahun + Semua Bulan (tampilan utuh saat dibuka)
        const thnDef = this.tahunOptions[0] || {
          id: 'all',
          label: 'Semua Tahun',
        };
        const blnDef = this.bulanOptions[0] || {
          id: 'all',
          label: 'Semua Bulan',
        };
        this.draftTahun = thnDef;
        this.selectedTahun = thnDef;
        this.draftBulan = blnDef;
        this.selectedBulan = blnDef;
        // default cabang = Semua Cabang
        const kabDef = this.cabangOptions[0] || {
          id: 'all',
          label: 'Semua Cabang',
        };
        this.draftCabang = kabDef;
        this.selectedCabang = kabDef;
      } catch (e) {
        this.$toast.error('Gagal memuat data: ' + e.message);
        this.rows = [];
      } finally {
        this.isLoading = false;
      }
    },
    onSearch() {
      // Terapkan pilihan divisi & periode (tahun/bulan) & cabang dari dropdown
      // ke tampilan.
      this.currentPage = 1;
      this.selectedDivisi = this.draftDivisi || this.divisionOptions[0];
      this.selectedTahun = this.draftTahun || this.tahunOptions[0];
      this.selectedBulan = this.draftBulan || this.bulanOptions[0];
      this.selectedCabang = this.draftCabang || this.cabangOptions[0];
    },
    onReset() {
      const semua = this.divisionOptions[0] || {
        id: 'all',
        label: 'Semua Divisi',
      };
      const thnDef = this.tahunOptions[0] || {id: 'all', label: 'Semua Tahun'};
      const blnDef = this.bulanOptions[0] || {id: 'all', label: 'Semua Bulan'};
      const kabDef = this.cabangOptions[0] || {
        id: 'all',
        label: 'Semua Cabang',
      };
      this.currentPage = 1;
      this.draftDivisi = semua;
      this.selectedDivisi = semua;
      this.draftTahun = thnDef;
      this.selectedTahun = thnDef;
      this.draftBulan = blnDef;
      this.selectedBulan = blnDef;
      this.draftCabang = kabDef;
      this.selectedCabang = kabDef;
    },
  },
};
</script>

<style lang="scss" scoped>
.orangehrm-dashboard-header {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.orangehrm-dp-filter {
  margin-bottom: 1rem;
}
.orangehrm-left-space {
  margin-left: 0.5rem;
}
.orangehrm-dp-loading,
.orangehrm-dp-empty {
  padding: 2rem 0.5rem;
}
/* ---- Chart KPI ---- */
.orangehrm-gauge-card {
  background: #ffffff;
  border: 1px solid #e4e6ea;
  border-radius: 8px;
  padding: 1rem 1.25rem;
  margin-bottom: 1.25rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  display: flex;
  flex-direction: column;
}
.orangehrm-gauge-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}
.orangehrm-gauge-title {
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}
.orangehrm-gauge-sub {
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
  font-size: 12px;
  color: #64728c;
}
.orangehrm-gauge-body {
  display: flex;
  justify-content: center;
  padding-top: 0.5rem;
}
.orangehrm-gauge-svg {
  width: 100%;
  max-width: 340px;
  height: auto;
}
.orangehrm-gauge-value-text {
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
  font-size: 30px;
  font-weight: 800;
  fill: #1e293b;
}
.orangehrm-gauge-status-text {
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
  font-size: 12px;
  font-weight: 600;
  fill: #64728c;
}
.orangehrm-gauge-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  justify-content: center;
  margin-top: 0.75rem;
}
.gauge-legend-item {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
  font-size: 12px;
  font-weight: 600;
  color: #334155;
}
.gauge-dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  display: inline-block;
}
.gauge-legend-range {
  font-style: normal;
  font-weight: 500;
  font-size: 11px;
  color: #94a3b8;
}
.orangehrm-chart-card {
  background: #ffffff;
  border: 1px solid #e4e6ea;
  border-radius: 8px;
  padding: 1rem 1.25rem;
  margin-bottom: 1.25rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.orangehrm-chart-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
}
.orangehrm-chart-title {
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}
.orangehrm-chart-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}
.chart-legend-item {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 11px;
  color: #64728c;
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
}
.chart-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
}
.orangehrm-kpi-chart {
  height: 320px;
  width: 100%;
}
.orangehrm-table-wrap {
  overflow-x: auto;
  border: 1px solid #e4e6ea;
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.oxd-table-data {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
  th {
    text-align: left;
    color: #929baa;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.8rem 0.6rem;
    border-bottom: 1px solid #e4e6ea;
    background: #ffffff;
    white-space: nowrap;
  }
  td {
    padding: 0.75rem 0.6rem;
    border-bottom: 1px solid #eceef0;
    color: #64728c;
    vertical-align: middle;
  }
  tbody tr:hover {
    background: #f7f9fb;
  }
}
.orangehrm-num {
  text-align: right;
}
.orangehrm-divisi,
.orangehrm-cabang {
  display: inline-block;
  padding: 3px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  background: #f1f5f9;
  color: #64748b;
  white-space: nowrap;
}
.orangehrm-periode {
  white-space: nowrap;
}
.orangehrm-indikator {
  font-weight: 600;
  color: #303030;
}
.orangehrm-skor.status-blue {
  color: #1d4ed8;
  font-weight: 600;
}
.orangehrm-skor.status-green {
  color: #15803d;
  font-weight: 600;
}
.orangehrm-skor.status-yellow {
  color: #a16207;
  font-weight: 600;
}
.orangehrm-skor.status-red {
  color: #b91c1c;
  font-weight: 600;
}
.orangehrm-skor.status-grey {
  color: #64748b;
  font-weight: 600;
}
.orangehrm-status.status-blue {
  background: #dbeafe;
  color: #1d4ed8;
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
}
.orangehrm-status.status-green {
  background: #dcfce7;
  color: #15803d;
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
}
.orangehrm-status.status-yellow {
  background: #fef9c3;
  color: #a16207;
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
}
.orangehrm-status.status-red {
  background: #fef2f2;
  color: #b91c1c;
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
}
.orangehrm-status.status-grey {
  background: #f1f5f9;
  color: #64748b;
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
}
.orangehrm-sumber-link {
  color: #1d4ed8;
  font-weight: 600;
}
.orangehrm-modal-header {
  margin-bottom: 0.5rem;
}
.orangehrm-form-action {
  display: flex;
  justify-content: flex-end;
}
.orangehrm-total-row {
  background: #f8fafc;
  td {
    border-bottom: 2px solid #e4e6ea;
    color: #475569;
    font-size: 15px !important;
  }
}
.orangehrm-bold {
  font-weight: 700;
}
.orangehrm-skor-blue {
  color: #1d4ed8;
  font-weight: 700;
}
.orangehrm-skor-green {
  color: #15803d;
  font-weight: 700;
}
.orangehrm-skor-yellow {
  color: #a16207;
  font-weight: 700;
}
.orangehrm-skor-red {
  color: #b91c1c;
  font-weight: 700;
}
.orangehrm-skor-grey {
  color: #64748b;
  font-weight: 700;
}
.orangehrm-bottom-container {
  display: flex;
  justify-content: flex-end;
  padding: 1rem;
}
</style>
