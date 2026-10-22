<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-card-container">
      <div class="orangehrm-dashboard-header">
        <oxd-text type="card-title">Dashboard Divisi</oxd-text>
        <oxd-text type="subtitle-2">
          Ringkasan kinerja KPI per divisi. Klik sebuah divisi untuk melihat
          detail indikatornya.
        </oxd-text>
      </div>

      <oxd-divider />

      <div class="orangehrm-dp-filter">
        <oxd-form @submit-valid="onSearch" @reset="onReset">
          <oxd-form-row>
            <oxd-grid :cols="2" class="orangehrm-full-width-grid">
              <oxd-grid-item>
                <oxd-input-field
                  v-model="draftDivisi"
                  type="select"
                  :label="'Divisi'"
                  :options="divisionOptions"
                  :disabled="!isAdmin"
                />
              </oxd-grid-item>
            </oxd-grid>
          </oxd-form-row>

          <oxd-divider v-if="isAdmin" />

          <oxd-form-actions v-if="isAdmin">
            <oxd-button display-type="ghost" label="Reset" type="reset" />
            <oxd-button
              class="orangehrm-left-space"
              display-type="secondary"
              label="Search"
              type="submit"
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
            Rata-rata seluruh divisi (semua periode)
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
              {{ totalSkorDisplay }}
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
            Rata-rata Skor KPI per Bulan (rata-rata per divisi,
            {{ chartTahun }})
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

      <div v-else-if="!summaryRows.length" class="orangehrm-dp-empty">
        <oxd-text type="subtitle-2">
          Tidak ada data KPI untuk periode ini.
        </oxd-text>
      </div>

      <div v-else>
        <!-- Export PDF: baris tombol sendiri supaya terlihat ADMIN maupun ESS -->
        <div class="orangehrm-export-bar">
          <oxd-button
            display-type="secondary"
            :label="'Export PDF'"
            type="button"
            @click="onClickExportPdf"
          />
        </div>

        <div class="orangehrm-table-wrap">
          <table class="oxd-table-data orangehrm-kpi-table">
            <thead>
              <tr>
                <th style="width: 28%">Divisi</th>
                <th style="width: 24%">Skor Semua Periode</th>
                <th style="width: 28%">Status</th>
                <th style="width: 20%">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in summaryRows" :key="s.divisi">
                <td>
                  <span class="orangehrm-divisi">{{
                    divisiName(s.divisi)
                  }}</span>
                </td>
                <td class="orangehrm-num">
                  <span :class="skorClass(s.avgSkor)">{{
                    skorDisplay(s)
                  }}</span>
                </td>
                <td>
                  <span :class="statusClass(s.avgSkor)">{{
                    statusTextFor(s.avgSkor)
                  }}</span>
                </td>
                <td>
                  <oxd-button
                    display-type="secondary"
                    :label="'Lihat Detail'"
                    type="button"
                    @click="openDetail(s.divisi)"
                  />
                </td>
              </tr>
              <tr class="orangehrm-total-row">
                <td class="orangehrm-bold">Total Skor Rata-rata</td>
                <td class="orangehrm-num orangehrm-bold">
                  <span :class="skorClass(totalSkorRataRata)">{{
                    totalSkorDisplay
                  }}</span>
                </td>
                <td>
                  <span :class="statusClass(totalSkorRataRata)">{{
                    statusTextFor(totalSkorRataRata)
                  }}</span>
                </td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <oxd-dialog
        v-if="showDetail && detailRows.length"
        :style="{width: '90%', maxWidth: '900px'}"
        @update:show="closeDetail"
      >
        <div class="orangehrm-modal-header">
          <oxd-text type="card-title">
            Detail Indikator — {{ detailDivisi }} ({{ periodeLabel }})
          </oxd-text>
        </div>
        <oxd-divider />
        <div class="orangehrm-table-wrap">
          <table class="oxd-table-data orangehrm-kpi-table">
            <thead>
              <tr>
                <th style="width: 13%">Periode</th>
                <th style="width: 22%">Indikator</th>
                <th style="width: 10%">Cabang</th>
                <th style="width: 7%">Bobot</th>
                <th style="width: 8%">Target</th>
                <th style="width: 9%">Realisasi</th>
                <th style="width: 8%">Skor</th>
                <th style="width: 14%">Status</th>
                <th style="width: 12%">Sumber Data</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in detailRows" :key="`${r.setup_id}-${r.periode}`">
                <td>
                  <span class="orangehrm-periode">{{
                    formatPeriode(r.periode)
                  }}</span>
                </td>
                <td>
                  <div class="orangehrm-indikator">{{ r.indikator }}</div>
                </td>
                <td>
                  <span class="orangehrm-cabang">{{ r.cabang || 'HO' }}</span>
                </td>
                <td class="orangehrm-num">{{ r.bobot }}%</td>
                <td class="orangehrm-num">{{ r.target }}{{ r.satuan }}</td>
                <td class="orangehrm-num">
                  {{ r.realisasi !== null ? r.realisasi : '—' }}
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
                <td>
                  <a
                    v-if="r.bukti"
                    :href="r.bukti"
                    target="_blank"
                    rel="noopener"
                    class="orangehrm-sumber-link"
                    >Buka</a
                  >
                  <span v-else>—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <oxd-divider />
        <div class="orangehrm-form-action">
          <oxd-button display-type="ghost" label="Tutup" @click="closeDetail" />
        </div>
      </oxd-dialog>
    </div>
  </div>
</template>

<script>
import {OxdDialog} from '@ohrm/oxd';
import {buildGroupedTablePdf} from '../utils/kpiReportPdf';
import {divisiName as divisiNameOf} from '../utils/divisi';
import {OxdBarChart} from '@ohrm/oxd';
import Swal from 'sweetalert2';

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
  name: 'DashboardDivisi',
  components: {
    'oxd-dialog': OxdDialog,
    'oxd-bar-chart': OxdBarChart,
  },
  props: {
    empNumber: {type: Number, default: 0},
  },
  data() {
    return {
      isLoading: true,
      rows: [],
      isAdmin: false,
      currentDivision: '',
      divisions: [],
      selectedDivisi: null,
      draftDivisi: null,
      showDetail: false,
      detailDivisi: '',
      detailRows: [],
    };
  },
  computed: {
    statusColors() {
      return STATUS_COLOR;
    },
    chartTahun() {
      let tahun = null;
      this.divisiRows.forEach((r) => {
        if (r.periode) {
          const y = parseInt(String(r.periode).slice(0, 4), 10);
          if (!Number.isNaN(y) && (tahun === null || y > tahun)) tahun = y;
        }
      });
      return tahun;
    },
    // Nama "ytd*" bersifat HISTORIS (dulu Year-to-Date). Sejak 2026-10-07 gauge
    // memakai rata-rata PER DIVISI dari SEMUA periode = SUMBER YANG SAMA dengan
    // baris "Total Skor Rata-rata" di tabel, jadi angkanya dijamin identik.
    // Nilai dibulatkan 2 desimal utk geometri jarum & penentuan status; teks yang
    // ditampilkan memakai totalSkorDisplay (satu formatter dgn baris total).
    ytdValue() {
      if (this.totalSkorRataRata === null) return null;
      return Math.round(this.totalSkorRataRata * 100) / 100;
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
        .map((d) => ({id: d, label: this.divisiName(d)}));
      // ESS (non-admin): dropdown HANYA divisi mapping user, tanpa "Semua Divisi".
      if (!this.isAdmin) {
        const target = divisiList.find((o) => o.id === this.currentDivision) ||
          divisiList[0] || {
            id: this.currentDivision,
            label: this.divisiName(this.currentDivision),
          };
        return [target];
      }
      // Admin: opsi "Semua Divisi" pertama → jadi default saat halaman dibuka.
      return [{id: 'all', label: 'Semua Divisi'}, ...divisiList];
    },
    divisiRows() {
      const d = this.selectedDivisi;
      if (!d) return [];
      const id = d.id ?? d; // oxd select: v-model = object opsi {id,label}
      if (id === 'all') return this.rows;
      return this.rows.filter((r) => r.divisi === id);
    },
    summaryRows() {
      // Filter kini berdasarkan DIVISI (bukan periode). Tiap divisi di-agregasi
      // lintas SEMUA periode, dengan dedup per setup_id supaya satu indikator
      // tidak dihitung berulang (per periode) yang menggelembungkan count/bobot.
      const map = new Map();
      for (const r of this.divisiRows) {
        const s = this.periodScalar(r);
        if (s === null) continue;
        if (!map.has(s.divisi)) {
          map.set(s.divisi, {
            divisi: s.divisi,
            count: 0,
            totalBobot: 0,
            filled: 0,
            skorSum: 0,
            skorCount: 0,
            unique: new Map(), // setup_id -> { bobot, hasRealisasi }
          });
        }
        const g = map.get(s.divisi);
        if (!g.unique.has(r.setup_id)) {
          g.unique.set(r.setup_id, {
            bobot: s.bobot,
            hasRealisasi: s.skor !== null,
          });
          g.count += 1;
          g.totalBobot += s.bobot;
        } else {
          const u = g.unique.get(r.setup_id);
          if (s.skor !== null) u.hasRealisasi = true;
        }
        if (s.skor !== null) {
          g.filled += 1;
          g.skorSum += s.skor;
          g.skorCount += 1;
        }
      }
      return Array.from(map.values()).map((g) => {
        const filledInd = Array.from(g.unique.values()).filter(
          (u) => u.hasRealisasi,
        ).length;
        return {
          divisi: g.divisi,
          count: g.count,
          totalBobot: g.totalBobot,
          filled: filledInd,
          avgSkor: g.skorCount ? g.skorSum / g.skorCount : null,
        };
      });
    },
    isAllDivisi() {
      const d = this.selectedDivisi;
      const id = d?.id ?? d;
      return !id || id === 'all';
    },
    divisiLabel() {
      const d = this.selectedDivisi;
      const id = d?.id ?? d;
      if (!id || id === 'all') return 'Semua Divisi';
      return d?.label ?? id;
    },
    periodeLabel() {
      // Halaman kini difilter per DIVISI (agregasi lintas semua periode),
      // sehingga label periode di tabel/modal selalu "Semua Periode".
      return 'Semua Periode';
    },
    isAllPeriods() {
      return true;
    },
    // Label waktu cetak — dipakai header PDF (Export PDF).
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
    // Total Skor Rata-rata = rata-rata PER DIVISI dari angka "Skor Semua
    // Periode" (summaryRows[].avgSkor): setiap divisi dianggap berbobot sama,
    // dihitung lintas semua periode. Divisi tanpa skor tidak ikut dihitung.
    //
    // KEPUTUSAN FINAL Pak (2026-10-07): pakai rumus per-divisi ini — angka
    // acuannya 91.06%. Rumus alternatif "per baris indikator" (= angka gauge
    // YTD, 89.18%) sempat dipakai lalu dikembalikan ke per-divisi ini.
    totalSkorRataRata() {
      const values = this.summaryRows
        .map((s) => s.avgSkor)
        .filter((v) => v !== null && v !== undefined && !Number.isNaN(v));
      if (!values.length) return null;
      return values.reduce((a, b) => a + b, 0) / values.length;
    },
    totalSkorDisplay() {
      // 2 desimal supaya persis angka acuan Pak (91.06%) dan seragam dengan
      // teks 2-desimal di gauge. Ubah ke toFixed(1) kalau mau 91.1%.
      return this.totalSkorRataRata === null
        ? '—'
        : this.totalSkorRataRata.toFixed(2) + '%';
    },
    chartData() {
      const BULAN = [
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
      if (!this.divisiRows.length) return [];
      // Tahun data terbaru dari semua baris (format periode YYYY-MM)
      let tahun = null;
      this.divisiRows.forEach((r) => {
        if (r.periode) {
          const y = parseInt(String(r.periode).slice(0, 4), 10);
          if (!Number.isNaN(y) && (tahun === null || y > tahun)) tahun = y;
        }
      });
      if (tahun === null) return [];
      // Rata-rata PER DIVISI tiap bulan: tiap divisi dihitung rata-rata dulu,
      // lalu dirata-rata antar divisi (tiap divisi berbobot sama) — SEJALAN dengan
      // gauge "Pencapaian Divisi" dan baris "Total Skor Rata-rata".
      // Struktur sementara: [bulan] -> Map(divisi -> {sum, count}).
      // (Keputusan Pak 2026-10-07; sebelumnya rata-rata per BARIS indikator.)
      const perBulanDivisi = Array.from({length: 12}, () => new Map());
      this.divisiRows.forEach((r) => {
        const mP = String(r.periode || '').match(/^(\d{4})-(\d{2})$/);
        if (!mP || parseInt(mP[1], 10) !== tahun) return;
        const m = parseInt(mP[2], 10) - 1;
        if (m < 0 || m > 11) return;
        const skor = this.computeScoreOf(r);
        if (skor === null) return;
        const map = perBulanDivisi[m];
        if (!map.has(r.divisi)) map.set(r.divisi, {sum: 0, count: 0});
        const g = map.get(r.divisi);
        g.sum += skor;
        g.count += 1;
      });
      return perBulanDivisi.map((map, i) => {
        if (!map.size) {
          return {value: 0, label: BULAN[i], color: statusColor(null)};
        }
        const avgPerDivisi = Array.from(map.values()).map(
          (g) => g.sum / g.count,
        );
        const avg =
          avgPerDivisi.reduce((a, b) => a + b, 0) / avgPerDivisi.length;
        return {
          value: Math.round(avg * 100) / 100,
          label: BULAN[i],
          color: statusColor(avg),
        };
      });
    },
  },
  mounted() {
    this.fetchData();
  },
  methods: {
    // Nama tampilan divisi (nilai DB tetap kode: FNA, PM, SAD, ...).
    divisiName(code) {
      return divisiNameOf(code);
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
    // Export PDF: bangun file PDF dari DATA tabel ringkasan (summaryRows) lalu
    // auto-download (tanpa dialog print). Isi = tabelnya saja (Divisi, Skor
    // Semua Periode, Status) — gauge & bar chart TIDAK ikut.
    async onClickExportPdf() {
      try {
        const rows = this.summaryRows.map((s) => [
          this.divisiName(s.divisi),
          this.skorDisplay(s),
          this.statusTextFor(s.avgSkor),
        ]);

        if (!rows.length) {
          this.$toast.error('Tidak ada data untuk di-export.');
          return;
        }

        const bytes = buildGroupedTablePdf({
          title: 'Dashboard Divisi - Laporan KPI',
          info: [
            `Divisi: ${this.divisiLabel}    Periode: ${this.periodeLabel}`,
            `Dicetak: ${this.exportDateLabel}`,
          ],
          columns: [
            {label: 'Divisi', width: 300},
            {label: 'Skor Semua Periode', width: 160, align: 'right'},
            {label: 'Status', width: 334},
          ],
          groups: [
            {
              label: 'Ringkasan Skor KPI per Divisi',
              rows,
              // Baris Total Skor Rata-rata ikut ke PDF (samakan dengan tampilan).
              total:
                this.totalSkorRataRata === null
                  ? null
                  : [
                      'Total Skor Rata-rata',
                      this.totalSkorDisplay,
                      this.statusTextFor(this.totalSkorRataRata),
                    ],
            },
          ],
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
    buildExportFileName() {
      const slug = (value) =>
        String(value || '')
          .replace(/[^A-Za-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '');
      const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
      return `Dashboard-Divisi-KPI-${slug(this.divisiLabel)}-${stamp}.pdf`;
    },
    getBaseUrl() {
      const base = window.appGlobal?.baseUrl || '';
      return base.replace('/index.php', '') || '';
    },
    periodScalar(r) {
      if (r.divisi === undefined || r.divisi === null || r.divisi === '')
        return null;
      return {
        divisi: r.divisi,
        bobot: Number(r.bobot) || 0,
        skor: r.realisasi !== null ? this.computeScore(r) : null,
      };
    },
    computeScore(r) {
      const lower = !!r.lower;
      const target = Number(r.target) || 0;
      const real = Number(r.realisasi);
      if (target <= 0 || real > target * 1000000) return null;
      const s = lower ? (target / (real || 1)) * 100 : (real / target) * 100;
      return Math.min(100, Math.round(s * 100) / 100);
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
    skorDisplay(g) {
      return g.avgSkor === null ? '—' : g.avgSkor.toFixed(1) + '%';
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
        if (json.status === 'success') {
          const d = json.data;
          this.isAdmin = !!d.is_admin;
          this.currentDivision = d.current_division || '';
          this.divisions = d.divisions || [];
          this.rows = d.rows || [];
          // Default divisi:
          //  - Admin: "Semua Divisi" (draft + applied), simpan object opsi.
          //  - ESS (non-admin): otomatis PADA divisi mapping user sbg default.
          if (this.divisionOptions.length) {
            const target = this.isAdmin
              ? this.divisionOptions[0]
              : this.divisionOptions.find(
                  (o) => o.id === this.currentDivision,
                ) || this.divisionOptions[0];
            this.draftDivisi = target;
            this.selectedDivisi = target;
          }
        } else {
          this.rows = [];
        }
      } catch (e) {
        this.$toast.error('Gagal memuat data: ' + e.message);
        this.rows = [];
      } finally {
        this.isLoading = false;
      }
    },
    openDetail(divisi) {
      this.detailDivisi = divisi;
      this.detailRows = this.divisiRows.filter((r) => r.divisi === divisi);
      this.showDetail = true;
    },
    closeDetail() {
      this.showDetail = false;
      this.detailDivisi = '';
      this.detailRows = [];
    },
    onSearch() {
      // Terapkan pilihan divisi dari dropdown ke tampilan.
      this.selectedDivisi = this.draftDivisi || this.divisionOptions[0];
    },
    onReset() {
      const semua = this.divisionOptions[0] || {
        id: 'all',
        label: 'Semua Divisi',
      };
      this.draftDivisi = semua;
      this.selectedDivisi = semua;
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
.orangehrm-export-bar {
  display: flex;
  justify-content: flex-end;
  padding: 0 0 0.75rem;
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
</style>
