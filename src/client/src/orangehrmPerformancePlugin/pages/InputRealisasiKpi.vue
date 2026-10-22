<!--
/**
 * OrangeHRM — Input Realisasi KPI (PMS Antar-Departemen)
 *
 * 2026-09-25: filter & kolom Divisi/Periode DILIHAP dari tabel (permintaan
 * user). Data dikelompokkan per Divisi × Periode dalam section header
 * (mis. "Sales & Marketing — September 2026") + subtotal capaian per grup.
 * Kolom baris: Indikator | Divisi | Periode | Cabang | Bobot | Target |
 * Schedule | Achievement | Skor | Status | Sumber Data | Aksi. Simpan jalan per baris (tiap baris masih membawa
 * divisi+periode sendiri ke save_kpi_realisasi.php).
 *
 * 2026-09-28: FILTER Search Divisi + Cabang + Periode DITAMBAHKAN (frontend-only,
 * data sudah lengkap dari get_kpi_realisasi.php). Panel filter standar
 * oxd-table-filter di atas tabel: dropdown Divisi (HANYA admin — non-admin
 * dikunci ke divisi mapping) + dropdown Cabang + tombol Search/Reset.
 * 2026-09-29: filter Periode (dropdown) DIGANTI menjadi From Date + To Date
 * (date picker OX). Rentang tahun-bulan inklusif: From Date -> To Date
 * dipetakan ke periode YYYY-MM (mis. 15 Jan–25 Mar 2026 => Jan s.d. Mar).
 * Tabel menampilkan filteredGroups; tanpa filter aktif = sama seperti
 * sebelumnya (semua baris). Save TIDAK berubah (tetap kirim semua
 * baris supaya tidak ada data yang terlewat).
 *
 * 2026-09-28 (cabang): field CABANG ditambahkan setelah Divisi — kolom tabel
 * (badge abu-abu, NULL/'' = "HO"), dropdown modal Add (opsi "HO"
 * + daftar ohrm_location), read-only modal Edit, + filter Search Cabang.
 * DB: ohrm_kpi_setup.cabang & ohrm_kpi_realisasi.cabang (varchar NULL, 2026-09-28).
 * Proxy get: return cabang (dari setup) + locations[] (dari ohrm_location).
 *
 * Kolom "Sumber Data" (d/h "Bukti") = TEXT INPUT link/URL (mis. Google Drive),
 * v-model r.bukti → disimpan ke ohrm_kpi_realisasi.bukti via save proxy.
 * Tombol "Buka" muncul kalau terisi (buka link di tab baru). upload_bukti.php
 * & web/uploads/bukti/ TIDAK dipakai lagi di frontend (2026-09-28).
 * Sumber data: web/proxy/get_kpi_realisasi.php & save_kpi_realisasi.php.
 * Scoping: Admin lihat semua divisi; user biasa dikunci ke mapping divisi.
 *
 * 2026-10-14: tombol "Export Excel" DITAMBAHKAN di baris filter (sebelah
 * Search, display-type success). Menghasilkan file .xlsx ASLI (bukan CSV)
 * via generator zero-dep src/orangehrmPerformancePlugin/utils/xlsxReport.js.
 * Yang diexport = SEMUA grup hasil filter (bukan hanya halaman pagination
 * aktif), bentuknya mirror tabel: baris per indikator + baris TOTAL BOBOT &
 * capaian/status per grup. Kolom: Indikator | Divisi | Periode | Cabang |
 * Bobot (%) | Target | Satuan | Schedule | Achievement | Skor (%) | Status |
 * Sumber Data.
 *
 * 2026-10-14 (2): label tombol "Simpan Draft" DIGANTI jadi "Simpan"
 * (fungsinya tetap menyimpan sebagai draft — saveDraft() -> save(false));
 * tombol "Ajukan Verifikasi & Approve" tidak berubah.
 */
-->

<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-card-container">
      <oxd-text class="orangehrm-main-title" tag="h6">
        Input Realisasi KPI
      </oxd-text>
      <oxd-divider />

      <oxd-table-filter :filter-title="'Filter'">
        <oxd-form @submit-valid="onSearch" @reset="onReset">
          <oxd-form-row>
            <oxd-grid :cols="isAdmin ? 4 : 3" class="orangehrm-full-width-grid">
              <oxd-grid-item v-if="isAdmin">
                <oxd-input-field
                  v-model="filters.division"
                  type="select"
                  :label="'Divisi'"
                  :options="filterDivisionOptions"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="filters.cabang"
                  type="select"
                  :label="'Cabang'"
                  :options="filterCabangOptions"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="filters.fromDate"
                  type="date"
                  label="From Date"
                  :placeholder="userDateFormat"
                  :display-format="jsDateFormat"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="filters.toDate"
                  type="date"
                  label="To Date"
                  :placeholder="userDateFormat"
                  :display-format="jsDateFormat"
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
              display-type="success"
              label="Export Excel"
              type="button"
              :disabled="isLoading || filteredRowCount === 0"
              @click="exportExcel"
            />
          </oxd-form-actions>
        </oxd-form>
      </oxd-table-filter>
      <br />

      <div class="orangehrm-header-container">
        <oxd-button
          v-if="deletedKeys.length"
          display-type="danger"
          icon-name="trash"
          :label="`Delete (${deletedKeys.length})`"
          @click="confirmDeleteSelected"
        />
        <oxd-button
          icon-name="plus"
          display-type="secondary"
          label="Add"
          @click="toggleAdd"
        />
      </div>

      <oxd-dialog
        v-if="showAdd"
        :style="{width: '90%', maxWidth: '700px'}"
        @update:show="onAddCancel"
      >
        <div class="orangehrm-modal-header">
          <oxd-text type="card-title">Add Indikator KPI</oxd-text>
        </div>
        <oxd-divider />
        <oxd-form :loading="isLoading" @submit-valid="onAddSubmit">
          <oxd-form-row>
            <oxd-input-field
              v-model.trim="addForm.indikator"
              :label="'Indikator KPI'"
              required
              :rules="rules.indikator"
              autocomplete="off"
            />
          </oxd-form-row>
          <oxd-form-row>
            <oxd-grid :cols="3" class="orangehrm-full-width-grid">
              <oxd-grid-item>
                <oxd-input-field
                  v-model="addForm.division"
                  type="select"
                  :label="'Divisi'"
                  :options="divisionOptions"
                  required
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="addForm.cabang"
                  type="select"
                  :label="'Cabang'"
                  :options="locationOptions"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model.number="addForm.target"
                  :label="'Target'"
                  required
                  :rules="rules.target"
                  autocomplete="off"
                />
              </oxd-grid-item>
            </oxd-grid>
          </oxd-form-row>
          <oxd-form-row>
            <oxd-grid :cols="3" class="orangehrm-full-width-grid">
              <oxd-grid-item>
                <oxd-input-field
                  v-model.number="addForm.bobot"
                  :label="'Bobot (%)'"
                  required
                  :rules="rules.bobot"
                  autocomplete="off"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="addForm.schedule"
                  type="select"
                  :label="'Schedule'"
                  :options="scheduleOptions"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="addForm.periode"
                  type="select"
                  :label="'Periode'"
                  :options="addPeriodeOptions"
                />
              </oxd-grid-item>
            </oxd-grid>
          </oxd-form-row>
          <oxd-divider />
          <oxd-form-actions class="orangehrm-form-action">
            <required-text />
            <oxd-button
              display-type="ghost"
              label="Batal"
              type="button"
              @click="onAddCancel"
            />
            <oxd-button display-type="secondary" label="Simpan" type="submit" />
          </oxd-form-actions>
        </oxd-form>
      </oxd-dialog>

      <oxd-dialog
        v-if="showEdit && editForm"
        :style="{width: '90%', maxWidth: '700px'}"
        @update:show="editCancel"
      >
        <div class="orangehrm-modal-header">
          <oxd-text type="card-title">Edit Indikator KPI</oxd-text>
        </div>
        <oxd-divider />
        <oxd-form :loading="isLoading" @submit-valid="onEditSubmit">
          <oxd-form-row>
            <oxd-grid :cols="3" class="orangehrm-full-width-grid">
              <oxd-grid-item>
                <label class="orangehrm-modal-label">Indikator KPI</label>
                <oxd-text type="card-body">{{ editForm.indikator }}</oxd-text>
              </oxd-grid-item>
              <oxd-grid-item>
                <template v-if="isAdmin">
                  <!-- ADMIN: divisi BISA diubah (dropdown). -->
                  <label class="orangehrm-modal-label">Divisi</label>
                  <oxd-input-field
                    v-model="editForm.division"
                    type="select"
                    :options="divisionOptions"
                  />
                </template>
                <template v-else>
                  <label class="orangehrm-modal-label">Divisi</label>
                  <oxd-text type="card-body">{{
                    divisiName(editForm.divisi)
                  }}</oxd-text>
                </template>
              </oxd-grid-item>
              <oxd-grid-item>
                <label class="orangehrm-modal-label">Cabang</label>
                <oxd-text type="card-body">{{ editForm.cabang }}</oxd-text>
              </oxd-grid-item>
            </oxd-grid>
          </oxd-form-row>
          <oxd-form-row>
            <oxd-grid :cols="3" class="orangehrm-full-width-grid">
              <oxd-grid-item>
                <oxd-input-field
                  v-model.number="editForm.target"
                  :label="'Target'"
                  required
                  :rules="rules.target"
                  autocomplete="off"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model.number="editForm.bobot"
                  :label="'Bobot (%)'"
                  required
                  :rules="rules.bobot"
                  autocomplete="off"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="editForm.schedule"
                  type="select"
                  :label="'Schedule'"
                  :options="scheduleOptions"
                />
              </oxd-grid-item>
            </oxd-grid>
          </oxd-form-row>
          <oxd-divider />
          <oxd-form-actions class="orangehrm-form-action">
            <required-text />
            <oxd-button
              display-type="ghost"
              label="Batal"
              type="button"
              @click="editCancel"
            />
            <oxd-button display-type="secondary" label="Simpan" type="submit" />
          </oxd-form-actions>
        </oxd-form>
      </oxd-dialog>

      <oxd-dialog
        v-if="showHistory"
        :style="{width: '90%', maxWidth: '760px'}"
        @update:show="closeHistory"
      >
        <div class="orangehrm-modal-header">
          <oxd-text type="card-title">
            History Achievement — {{ historyIndikator }}
          </oxd-text>
        </div>
        <oxd-text type="subtitle-2">
          {{ historyPeriodeText() }}
        </oxd-text>
        <oxd-divider />
        <div v-if="historyRows.length" class="orangehrm-table-wrap">
          <table class="oxd-table-data orangehrm-kpi-table">
            <thead>
              <tr>
                <th style="width: 22%">Waktu</th>
                <th style="width: 26%">Oleh</th>
                <th style="width: 52%">Perubahan Achievement</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="h in historyRows" :key="h.id">
                <td class="orangehrm-periode">
                  {{ formatDateTime(h.changed_at) }}
                </td>
                <td>{{ h.changer_name }}</td>
                <td class="orangehrm-change-cell">
                  {{ historyValue(h.old_value) }} →
                  {{ historyValue(h.new_value) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="orangehrm-dp-empty">
          <oxd-text type="subtitle-2">
            Belum ada perubahan nilai Achievement.
          </oxd-text>
        </div>
        <oxd-divider />
        <div class="orangehrm-form-action">
          <oxd-button
            display-type="ghost"
            label="Tutup"
            @click="closeHistory"
          />
        </div>
      </oxd-dialog>

      <oxd-form :loading="isLoading" @submit-valid="onSubmit">
        <div
          v-if="rows.length && filteredGroups.length"
          class="orangehrm-kpi-section"
        >
          <div class="orangehrm-kpi-summary">
            <oxd-text type="subtitle-2">
              {{ filteredRowCount }} baris indikator •
              {{ filteredGroups.length }} grup
            </oxd-text>
            <span
              v-if="!isAdmin && currentDivision"
              class="orangehrm-scope-tag"
            >
              Anda mengisi divisi: <b>{{ divisiName(currentDivision) }}</b>
            </span>
          </div>

          <div class="orangehrm-table-wrap">
            <table class="oxd-table-data orangehrm-kpi-table">
              <thead>
                <tr>
                  <th style="width: 4%" class="orangehrm-delete-cell"></th>
                  <th style="width: 20%">Indikator</th>
                  <th style="width: 10%">Divisi</th>
                  <th style="width: 12%">Periode</th>
                  <th style="width: 9%">Cabang</th>
                  <th style="width: 6%">Bobot</th>
                  <th style="width: 7%">Target</th>
                  <th style="width: 7%">Schedule</th>
                  <th style="width: 7%">Achievement</th>
                  <th style="width: 7%">Skor</th>
                  <th style="width: 9%">Status</th>
                  <th style="width: 16%">Sumber Data</th>
                  <th style="width: 7%">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="g in paginatedGroups" :key="g.key">
                  <tr
                    v-for="r in g.rows"
                    :key="`${r.divisi}-${r.periode}-${r.setup_id}`"
                  >
                    <td class="orangehrm-delete-cell">
                      <input
                        type="checkbox"
                        class="oxd-input"
                        :checked="
                          isRowDeletable(r) && deletedKeys.includes(rowKey(r))
                        "
                        :disabled="!isRowDeletable(r)"
                        :title="
                          locked(r)
                            ? 'Tidak bisa dihapus (periode ini sudah diajukan)'
                            : setupHasPendingEdit(r.setup_id)
                            ? 'Tidak bisa dihapus (menunggu approval edit)'
                            : hasScore(r)
                            ? 'Tidak bisa dihapus (indikator sudah terisi skor)'
                            : 'Tandai untuk hapus'
                        "
                        @change="toggleDelete(r)"
                      />
                    </td>
                    <td>
                      <div class="orangehrm-indikator">{{ r.indikator }}</div>
                    </td>
                    <td>
                      <span class="orangehrm-divisi">{{
                        divisiName(r.divisi)
                      }}</span>
                    </td>
                    <td class="orangehrm-periode">
                      {{ formatPeriode(r.periode) }}
                    </td>
                    <td>
                      <span class="orangehrm-cabang">{{
                        r.cabang || 'HO'
                      }}</span>
                    </td>
                    <td class="orangehrm-num">{{ rowBobot(r) }}%</td>
                    <td class="orangehrm-num">
                      {{ rowTarget(r) }}{{ r.satuan }}
                    </td>
                    <td>{{ rowSchedule(r) }}</td>
                    <td class="orangehrm-num">
                      <input
                        v-model.number="r.realisasi"
                        class="oxd-input orangehrm-real"
                        type="number"
                        step="0.1"
                        placeholder="—"
                        :disabled="inputLocked(r)"
                        :title="inputLockHint(r)"
                      />
                    </td>
                    <td class="orangehrm-num">
                      <span :class="scoreColorClass(r)">{{
                        displayScore(r)
                      }}</span>
                    </td>
                    <td>
                      <span :class="badgeClass(r)">{{ statusLabel(r) }}</span>
                    </td>
                    <td>
                      <div class="orangehrm-sumber">
                        <input
                          v-model="r.bukti"
                          class="oxd-input orangehrm-sumber-input"
                          placeholder="Tempel link Sumber Data"
                          autocomplete="off"
                          :disabled="inputLocked(r)"
                          :title="inputLockHint(r)"
                        />
                        <a
                          v-if="r.bukti"
                          :href="r.bukti"
                          target="_blank"
                          rel="noopener"
                          class="orangehrm-sumber-link"
                          >Buka</a
                        >
                      </div>
                    </td>
                    <td>
                      <template v-if="pendingEdit(r)">
                        <span class="orangehrm-editpending"
                          >⏳ Menunggu Disetujui</span
                        >
                      </template>
                      <template v-else-if="setupHasDraft(r.setup_id)">
                        <span class="orangehrm-editdraft">Belum Diajukan</span>
                        <oxd-icon-button
                          class="orangehrm-edit"
                          :with-container="false"
                          name="pencil-fill"
                          :title="'Edit (update draft)'"
                          @click="openEdit(r)"
                        />
                      </template>
                      <template v-else-if="editApproved(r)">
                        <span class="orangehrm-editapproved"
                          >✓ Perubahan Disetujui</span
                        >
                        <oxd-icon-button
                          class="orangehrm-edit"
                          :with-container="false"
                          name="pencil-fill"
                          :title="'Edit'"
                          @click="openEdit(r)"
                        />
                      </template>
                      <oxd-icon-button
                        v-else
                        class="orangehrm-edit"
                        :with-container="false"
                        name="pencil-fill"
                        :title="'Edit'"
                        @click="openEdit(r)"
                      />
                      <!-- History Achievement: ikon jam utk baris yang sudah terisi nilai -->
                      <oxd-icon-button
                        v-if="hasScore(r)"
                        class="orangehrm-hist"
                        :with-container="false"
                        name="clock-fill"
                        :title="'History Achievement'"
                        @click="openHistory(r)"
                      />
                    </td>
                  </tr>
                  <tr class="orangehrm-total-row">
                    <td></td>
                    <td class="orangehrm-bold">TOTAL BOBOT</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="orangehrm-num orangehrm-bold">
                      {{ groupBobot(g) }}%
                    </td>
                    <td colspan="3"></td>
                    <td class="orangehrm-num orangehrm-bold">
                      <span :class="groupScoreColorClass(g)">{{
                        groupSkorDisplay(g)
                      }}</span>
                    </td>
                    <td>
                      <span :class="groupBadge(g)">{{ groupStatus(g) }}</span>
                    </td>
                    <td colspan="2"></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
          <div
            v-if="pages > 1"
            class="orangehrm-bottom-container orangehrm-pagination"
          >
            <oxd-pagination v-model:current="currentPage" :length="pages" />
          </div>
        </div>

        <div
          v-else-if="rows.length && !filteredGroups.length"
          class="orangehrm-empty-filter"
        >
          <oxd-text type="subtitle-2">
            Tidak ada data untuk filter ini. Ubah filter atau klik Reset.
          </oxd-text>
        </div>

        <oxd-divider v-if="filteredGroups.length" />

        <oxd-form-actions v-if="filteredGroups.length">
          <required-text />
          <oxd-button
            display-type="warning"
            :label="'Simpan'"
            type="button"
            @click="saveDraft"
          />
          <oxd-button
            class="orangehrm-left-space"
            display-type="warning"
            :label="'Ajukan Verifikasi & Approve'"
            type="submit"
          />
        </oxd-form-actions>
      </oxd-form>
    </div>
  </div>
</template>

<script>
import {
  required,
  shouldNotExceedCharLength,
  numberShouldBeBetweenMinAndMaxValue,
} from '@ohrm/core/util/validation/rules';
import {OxdDialog} from '@ohrm/oxd';
import Swal from 'sweetalert2';
import useDateFormat from '@/core/util/composable/useDateFormat';
import {
  downloadXlsx,
  XLSX_STYLE,
  statusStyleForColor,
} from '../utils/xlsxReport';
import {
  divisiCode as divisiCodeOf,
  divisiName as divisiNameOf,
} from '../utils/divisi';

// Validasi angka desimal untuk Target — oxd bawaan (numberShouldBeBetweenMinAndMaxValue)
// pakai digitsOnly (/^\d+$/) sehingga menolak desimal (0.025 gagal). Rule lokal ini
// menerima desimal, pesan tetap sama: "Should be a number between 0.01-1000000".
const numberBetweenMinMax = (min, max) => (value) => {
  const v =
    value === '' || value === null || value === undefined ? NaN : Number(value);
  if (Number.isNaN(v)) return `Should be a number between ${min}-${max}`;
  return (v >= min && v <= max) || `Should be a number between ${min}-${max}`;
};

// Jumlah GRUP (divisi × periode) per halaman pada tabel Input Realisasi KPI.
// Pagination dipotong per grup supaya baris "TOTAL BOBOT" tidak terpecah.
const PAGE_SIZE = 4;

// Nama bulan Indonesia (dipakai format periode & label jadwal penginputan).
const MONTH_NAMES = [
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

// Kolom file Export Excel (.xlsx) — mirror kolom tabel + kolom Satuan
// (Target dipisah dari satuannya supaya di Excel tetap angka & bisa dijumlah).
const EXPORT_COLUMNS = [
  {title: 'Indikator', width: 38},
  {title: 'Divisi', width: 16},
  {title: 'Periode', width: 16},
  {title: 'Cabang', width: 14},
  {title: 'Bobot (%)', width: 10},
  {title: 'Target', width: 12},
  {title: 'Satuan', width: 10},
  {title: 'Schedule', width: 12},
  {title: 'Achievement', width: 13},
  {title: 'Skor (%)', width: 10},
  {title: 'Status', width: 24},
  {title: 'Sumber Data', width: 40},
];

// Daftar divisi korporat ASG — dipakai sebagai pilihan dropdown Divisi di modal Add.
const COMPANY_DIVISIONS = [
  'ADO',
  'After Sales',
  'FNA',
  'HRGA & Lgl',
  'IT',
  'LND',
  'Marcomm',
  'NPD',
  'OPR',
  'PMO',
  'PPIC',
  'PUR',
  'QM',
  'RTL',
  'Sales',
  'Showroom',
  'SAD',
  'TAX',
  'PM',
];

const statusColor = (s) => {
  if (s === null || s === undefined) return 'status-grey';
  if (s >= 100) return 'status-blue';
  if (s >= 90) return 'status-green';
  if (s >= 80) return 'status-yellow';
  return 'status-red';
};
const statusText = (s) => {
  if (s === null || s === undefined) return 'Belum diisi';
  if (s >= 100) return 'Outstanding Performance';
  if (s >= 90) return 'Meet Expectation';
  if (s >= 80) return 'Attention';
  return 'Warning';
};

export default {
  name: 'InputRealisasiKpi',
  components: {
    'oxd-dialog': OxdDialog,
  },
  props: {
    empNumber: {type: Number, default: 0},
  },
  setup() {
    const {userDateFormat, jsDateFormat} = useDateFormat();
    return {userDateFormat, jsDateFormat};
  },
  data() {
    return {
      isLoading: true,
      isAdmin: false,
      currentDivision: '',
      divisions: [],
      locations: [],
      // mapping cabang yang diizinkan utk non-admin: { divisi: '*' | [cabang,...] } (null = admin)
      allowedCabangs: null,
      showAdd: false,
      showEdit: false,
      editForm: null,
      addForm: {
        division: null,
        cabang: null,
        indikator: '',
        target: null,
        bobot: null,
        schedule: 1,
        periode: null,
      },
      rows: [],
      // Halaman aktif tabel (pagination per grup divisi×periode).
      currentPage: 1,
      // Pasangan "setup_id__periode" yang ditandai utk dihapus (hapus per periode).
      deletedKeys: [],
      // History Achievement (modal ikon jam di kolom Aksi)
      showHistory: false,
      historyRows: [],
      historyIndikator: '',
      historyPeriode: '',
      historyCabang: '',
      // Draft edit definisi (target/bobot/schedule) yg BELUM diajukan ke approval.
      // Diisi saat non-admin klik Simpan di modal Edit; baru dikirim ke
      // save_setup_edit_request.php saat tombol "Ajukan Verifikasi & Approve".
      pendingEdits: [],
      // Filter Search (frontend-only): 'filters' = nilai dropdown (draft),
      // 'applied' = snapshot saat tombol Search diklik (dipakai menampilkan).
      filters: {
        division: {id: 0, label: 'Semua'},
        cabang: {id: 0, label: 'Semua'},
        fromDate: '',
        toDate: '',
      },
      applied: {
        division: {id: 0, label: 'Semua'},
        cabang: {id: 0, label: 'Semua'},
        fromDate: '',
        toDate: '',
      },
    };
  },
  computed: {
    divisionLabels() {
      return this.isAdmin
        ? COMPANY_DIVISIONS
        : this.divisions.length
        ? this.divisions
        : COMPANY_DIVISIONS;
    },
    divisionOptions() {
      // label = nama tampilan, code = kode divisi yang disimpan di DB.
      return this.divisionLabels.map((d, i) => ({
        id: i + 1,
        label: this.divisiName(d),
        code: d,
      }));
    },
    filterDivisionOptions() {
      return [{id: 0, label: 'Semua'}, ...this.divisionOptions];
    },
    locationOptions() {
      // Admin: HO + semua lokasi. Non-admin: dibatasi ke cabang yang ia miliki
      // di mapping (allowedCabangs: { divisi: '*' | [cabang,...] }).
      if (this.isAdmin) {
        return this.fullCabangOptions;
      }
      return this.allowedCabangOptions;
    },
    fullCabangOptions() {
      const labels = [{id: -1, label: 'HO'}];
      this.locations.forEach((l, i) => labels.push({id: i + 1, label: l.name}));
      return labels;
    },
    // true jika user non-admin boleh semua cabang pada minimal satu divisi mapping
    hasWildcardCabang() {
      if (!this.allowedCabangs) return false;
      return Object.values(this.allowedCabangs).some((v) => v === '*');
    },
    // himpunan nama cabang eksplisit yang diizinkan (tanpa '*')
    allowedCabangNames() {
      const set = new Set();
      if (!this.allowedCabangs) return set;
      for (const v of Object.values(this.allowedCabangs)) {
        if (v === '*') continue;
        if (Array.isArray(v)) v.forEach((c) => set.add(c));
      }
      return set;
    },
    allowedCabangOptions() {
      // Untuk dropdown Cabang (add + filter) non-admin. Kalau ada '*' -> semua
      // lokasi + HO. Kalau tidak -> hanya cabang yang ia miliki (termasuk HO
      // bila '' ada, untuk indikator level-HO). Urut sesuai ohrm_location.
      if (this.hasWildcardCabang) return this.fullCabangOptions;
      const names = this.allowedCabangNames;
      const labels = [];
      if (names.has('')) labels.push({id: -1, label: 'HO'});
      this.locations.forEach((l, i) => {
        if (names.has(l.name)) labels.push({id: i + 1, label: l.name});
      });
      return labels;
    },
    filterCabangOptions() {
      return [{id: 0, label: 'Semua'}, ...this.locationOptions];
    },
    scheduleOptions() {
      return [
        {id: 1, label: 'Bulanan'},
        {id: 2, label: 'Triwulan'},
        {id: 3, label: 'Semester'},
        // 'Tahunan' dihapus (duplikat arti dengan 'Annual' & tidak dipakai data).
        {id: 4, label: 'Annual'},
      ];
    },
    periodeOptions() {
      // Opsi periode (YYYY-MM) untuk dropdown Periode modal Add.
      // Rentang bulan TETAP (rolling): 12 bulan terakhir s.d. bulan berjalan
      // + bulan depan, digabung semua periode yang sudah ada datanya / sudah
      // didefinisikan di setup (biar periode historis selalu tersedia).
      // 'Semua Periode' (id '') = berlaku semua periode (perilaku lama).
      // CATATAN: daftar final untuk modal Add disaring lagi oleh
      // addPeriodeOptions() sesuai Schedule yang dipilih.
      const now = new Date();
      const curYm = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(
        2,
        '0',
      )}`;
      const set = new Set([curYm]);
      for (let i = -11; i <= 1; i++) {
        const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
        set.add(
          `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`,
        );
      }
      for (const r of this.rows) if (r.periode) set.add(r.periode);
      const options = Array.from(set)
        .sort()
        .reverse()
        .map((ym) => ({id: ym, label: this.formatPeriode(ym)}));
      return [{id: '', label: 'Semua Periode'}, ...options];
    },
    // Label Schedule yang sedang dipilih di modal Add (bisa objek atau id).
    addScheduleLabel() {
      const sch = this.addForm.schedule;
      if (sch && typeof sch === 'object') return sch.label || '';
      const found = this.scheduleOptions.find((o) => o.id == sch);
      return found ? found.label : '';
    },
    // Opsi Periode untuk modal Add, disesuaikan dengan Schedule:
    //   Triwulan -> hanya bulan ke-3 triwulan (Maret/Juni/September/Desember)
    //   Semester -> hanya bulan ke-6 (Juni/Desember)
    //   lainnya  -> semua periode (perilaku lama)
    // Untuk jadwal periodik, daftar dimulai dari bulan jadwal BERIKUTNYA
    // (bukan 12 bulan ke belakang), supaya tidak muncul periode lampau seperti
    // Desember 2025 saat sekarang Oktober 2026. Periode lampau tetap disertakan
    // HANYA kalau sudah ada datanya (untuk koreksi / lihat histori).
    // CATATAN: forwardPeriodeOptions() & existingPeriodeOptions() WAJIB di
    // `methods` (bukan `computed`) karena dipanggil dengan parameter.
    addPeriodeOptions() {
      const months = this.scheduleMonths(this.addScheduleLabel);
      const all = this.periodeOptions;
      if (!months) return all;
      const forward = this.forwardPeriodeOptions(months, 4);
      const taken = new Set(forward.map((o) => o.id));
      const existing = this.existingPeriodeOptions(months).filter(
        (o) => !taken.has(o.id),
      );
      return [...forward, ...existing];
    },
    rules() {
      return {
        indikator: [required, shouldNotExceedCharLength(255)],
        target: [required, numberBetweenMinMax(0.01, 1000000)],
        bobot: [required, numberShouldBeBetweenMinAndMaxValue(0, 100)],
      };
    },
    groups() {
      const map = new Map();
      for (const r of this.rows) {
        const key = `${r.divisi}||${r.periode}`;
        if (!map.has(key)) {
          map.set(key, {
            key,
            divisi: r.divisi,
            periode: r.periode,
            rows: [],
          });
        }
        map.get(key).rows.push(r);
      }
      return Array.from(map.values());
    },
    filteredGroups() {
      const div =
        this.applied.division && this.applied.division.label !== 'Semua'
          ? this.divisiCode(this.applied.division)
          : null;
      const cab =
        this.applied.cabang && this.applied.cabang.label !== 'Semua'
          ? this.applied.cabang.label
          : null;
      // Filter rentang bulan inklusif: From Date -> To Date (data periode = YYYY-MM)
      let fromYm = null;
      let toYm = null;
      if (this.applied.fromDate)
        fromYm = String(this.applied.fromDate).slice(0, 7);
      if (this.applied.toDate) toYm = String(this.applied.toDate).slice(0, 7);
      if (fromYm && toYm && fromYm > toYm) [fromYm, toYm] = [toYm, fromYm];
      if (!div && !cab && !fromYm && !toYm) return this.groups;
      const map = new Map();
      for (const r of this.rows) {
        if (div && r.divisi !== div) continue;
        if (cab) {
          const isPusat = cab === 'HO';
          if (isPusat ? (r.cabang || '') !== '' : r.cabang !== cab) continue;
        }
        if (fromYm && r.periode < fromYm) continue;
        if (toYm && r.periode > toYm) continue;
        const key = `${r.divisi}||${r.periode}`;
        if (!map.has(key)) {
          map.set(key, {
            key,
            divisi: r.divisi,
            periode: r.periode,
            rows: [],
          });
        }
        map.get(key).rows.push(r);
      }
      return Array.from(map.values());
    },
    filteredRowCount() {
      return this.filteredGroups.reduce((a, g) => a + g.rows.length, 0);
    },
    // Jumlah halaman tabel (dipakai oxd-pagination :length). Dipotong per grup.
    pages() {
      return Math.ceil(this.filteredGroups.length / PAGE_SIZE);
    },
    // Potongan grup utk halaman aktif — grup utuh supaya baris TOTAL BOBOT
    // tidak terpecah ke halaman lain.
    paginatedGroups() {
      const start = (this.currentPage - 1) * PAGE_SIZE;
      return this.filteredGroups.slice(start, start + PAGE_SIZE);
    },
  },
  watch: {
    // Saat filter berubah (Search/Reset) atau data dimuat ulang, pastikan
    // halaman aktif tidak melebihi jumlah halaman baru.
    filteredGroups() {
      if (this.pages < 1) this.currentPage = 1;
      else if (this.currentPage > this.pages) this.currentPage = this.pages;
    },
    // Schedule diganti di modal Add -> daftar Periode menyempit, pilihan yang
    // tidak sah (mis. 'Semua Periode' atau bulan bukan ke-3 triwulan) diganti.
    'addForm.schedule'() {
      this.syncAddPeriode();
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
    // Nama tampilan divisi dari kode (DB tetap menyimpan kode: FNA, PM, SAD, ...).
    divisiName(code) {
      return divisiNameOf(code);
    },
    // Kode divisi dari opsi dropdown (atau string kode langsung).
    divisiCode(opt) {
      return divisiCodeOf(opt);
    },
    locked(r) {
      return !!r.submitted;
    },
    // --- Jadwal penginputan (Triwulan / Semester) ---
    // Indikator Triwulan diisi 3 bulan sekali, yaitu pada bulan ke-3 tiap
    // triwulan: Maret, Juni, September, Desember.
    // Indikator Semester diisi 6 bulan sekali: Juni dan Desember.
    // Di luar jendela itu, input Achievement & Sumber Data di-disable.
    // Admin tidak dibatasi (bisa mengisi kapan saja untuk koreksi).
    scheduleMonths(schedule) {
      const s = String(schedule || '')
        .trim()
        .toLowerCase();
      if (s === 'triwulan') return [3, 6, 9, 12];
      if (s === 'semester') return [6, 12];
      // Annual: periodenya akhir tahun (Desember). Jendela input Desember–Januari
      // (lihat rowInputWindow), karena data realisasi baru tersedia awal tahun.
      if (s === 'annual') return [12];
      return null; // Bulanan -> tidak dibatasi jadwal
    },
    monthsLabel(months) {
      return months.map((m) => MONTH_NAMES[m - 1]).join(' / ');
    },
    // `count` bulan jadwal berikutnya, mulai dari bulan berjalan (kalau bulan
    // berjalan termasuk jadwal) sampai beberapa periode ke depan.
    // Method (bukan computed) karena dipanggil dengan parameter.
    forwardPeriodeOptions(months, count) {
      const out = [];
      const now = new Date();
      let tahun = now.getFullYear();
      let bulan = now.getMonth() + 1;
      for (let i = 0; i < 60 && out.length < count; i++) {
        if (months.includes(bulan)) {
          const ym = `${tahun}-${String(bulan).padStart(2, '0')}`;
          out.push({id: ym, label: this.formatPeriode(ym)});
        }
        bulan++;
        if (bulan > 12) {
          bulan = 1;
          tahun++;
        }
      }
      return out;
    },
    // Periode yang sudah ada datanya dan bulannya termasuk jadwal (mis. baris
    // Triwulan periode Juni 2026 yang sudah pernah dibuat).
    existingPeriodeOptions(months) {
      const set = new Set();
      for (const r of this.rows) {
        const ym = String(r.periode || '');
        if (!/^\d{4}-\d{2}$/.test(ym)) continue;
        const bulan = parseInt(ym.slice(5, 7), 10);
        if (!months.includes(bulan)) continue;
        set.add(ym);
      }
      return Array.from(set)
        .sort()
        .reverse()
        .map((ym) => ({id: ym, label: this.formatPeriode(ym)}));
    },
    // Status jendela penginputan untuk satu baris: {open, reason, future}.
    rowInputWindow(r) {
      const periode = String(r.periode || '');
      const tahun = parseInt(periode.slice(0, 4), 10);
      const bulan = parseInt(periode.slice(5, 7), 10);
      if (!tahun || !bulan) {
        return {open: false, reason: `Periode "${periode}" tidak valid`};
      }
      const now = new Date();
      const rowKey = tahun * 100 + bulan;
      const curKey = now.getFullYear() * 100 + (now.getMonth() + 1);
      // Aturan umum (berlaku untuk SEMUA schedule): periode yang belum berjalan
      // belum bisa diisi — otomatis terbuka saat bulan periode itu tiba.
      // Contoh: periode Desember 2026 saat sekarang Oktober 2026 -> terkunci.
      if (rowKey > curKey) {
        return {
          open: false,
          future: true,
          reason: `Periode ${this.formatPeriode(
            periode,
          )} belum berjalan — baru bisa diisi mulai 1 ${this.monthsLabel([
            bulan,
          ])} ${tahun}`,
        };
      }
      const schedule = String(this.rowSchedule(r) || '').trim();
      const months = this.scheduleMonths(schedule);
      if (!months) return {open: true, reason: ''};
      if (!months.includes(bulan)) {
        return {
          open: false,
          reason: `Indikator ${schedule}: pengisian hanya pada ${this.monthsLabel(
            months,
          )} — periode ${this.formatPeriode(periode)} tidak dipakai`,
        };
      }
      const open = now.getFullYear() === tahun && now.getMonth() + 1 === bulan;
      const isAnnual = schedule.toLowerCase() === 'annual';
      if (isAnnual) {
        // Annual: Desember tahun periode s/d Januari tahun berikutnya.
        const nowY = now.getFullYear();
        const nowM = now.getMonth() + 1;
        const openAnnual =
          (nowY === tahun && nowM === 12) || (nowY === tahun + 1 && nowM === 1);
        return {
          open: openAnnual,
          future: false,
          reason: openAnnual
            ? ''
            : `Pengisian periode ${this.formatPeriode(
                periode,
              )} dibuka Desember ${tahun} – Januari ${tahun + 1}`,
        };
      }
      return {
        open,
        future: false,
        reason: open
          ? ''
          : `Pengisian periode ${this.formatPeriode(
              periode,
            )} dibuka pada ${this.monthsLabel([bulan])} ${tahun}`,
      };
    },
    // true = input Achievement & Sumber Data dikunci untuk baris ini.
    inputLocked(r) {
      if (this.isAdmin) return false;
      return !this.rowInputWindow(r).open;
    },
    inputLockHint(r) {
      if (this.isAdmin) return 'Admin: pengisian tidak dibatasi jadwal';
      const w = this.rowInputWindow(r);
      if (w.open) return '';
      return `${w.reason}. Admin tetap bisa mengisi.`;
    },
    // true bila indikator sudah terisi realisasi (skor jadi ada angka).
    hasScore(r) {
      return this.scoreOf(r) !== null;
    },
    // Checkbox hapus hanya aktif utk indikator yang BELUM terisi skor & belum
    // terkunci (submitted/pending edit). Indikator yg sudah punya angka tidak
    // boleh ditandai hapus.
    isRowDeletable(r) {
      return (
        !this.locked(r) &&
        !this.setupHasPendingEdit(r.setup_id) &&
        !this.hasScore(r)
      );
    },
    pendingEdit(r) {
      // Indikator sedang menunggu persetujuan edit definisi -> tidak bisa diedit.
      return !!r.edit_pending;
    },
    editApproved(r) {
      // Indikator pernah punya pengajuan edit yang sudah disetujui.
      return !!r.edit_approved;
    },
    setupHasPendingEdit(setupId) {
      return (
        this.rows.filter((x) => x.setup_id === setupId && x.edit_pending)
          .length > 0
      );
    },
    setupLocked(setupId) {
      // Indikator (setup) terkunci jika ada periode yang sudah diajukan/menunggu
      return (
        this.rows.filter((x) => x.setup_id === setupId && !!x.submitted)
          .length > 0
      );
    },
    // --- Draft edit definisi yg belum diajukan ke approval (2026-10) ---
    getDraft(setupId) {
      return this.pendingEdits.find((d) => d.setup_id === setupId);
    },
    draftOf(r) {
      return this.getDraft(r.setup_id);
    },
    setupHasDraft(setupId) {
      return !!this.getDraft(setupId);
    },
    // Nilai yang DITAMPILKAN di baris: timpa dengan draft edit bila ada,
    // selain itu nilai asli dari server.
    rowBobot(r) {
      const d = this.draftOf(r);
      return d ? d.bobot : r.bobot;
    },
    rowTarget(r) {
      const d = this.draftOf(r);
      return d ? d.target : r.target;
    },
    rowSchedule(r) {
      const d = this.draftOf(r);
      return d ? d.schedule : r.schedule;
    },
    scoreOf(r) {
      if (
        r.realisasi === null ||
        r.realisasi === undefined ||
        r.realisasi === ''
      ) {
        return null;
      }
      const lower = !!r.lower;
      const s = lower
        ? (r.target / (r.realisasi || 1)) * 100
        : ((r.realisasi || 0) / r.target) * 100;
      return Math.min(100, s);
    },
    displayScore(r) {
      const s = this.scoreOf(r);
      return s === null ? '—' : s.toFixed(1) + '%';
    },
    badgeClass(r) {
      return `orangehrm-status ${statusColor(this.scoreOf(r))}`;
    },
    scoreColorClass(r) {
      const map = {
        'status-blue': 'orangehrm-skor-blue',
        'status-green': 'orangehrm-skor-green',
        'status-yellow': 'orangehrm-skor-yellow',
        'status-red': 'orangehrm-skor-red',
        'status-grey': 'orangehrm-skor-grey',
      };
      return map[statusColor(this.scoreOf(r))] || 'orangehrm-skor-grey';
    },
    groupScoreColorClass(g) {
      const map = {
        'status-blue': 'orangehrm-skor-blue',
        'status-green': 'orangehrm-skor-green',
        'status-yellow': 'orangehrm-skor-yellow',
        'status-red': 'orangehrm-skor-red',
        'status-grey': 'orangehrm-skor-grey',
      };
      return map[statusColor(this.groupSkor(g))] || 'orangehrm-skor-grey';
    },
    statusLabel(r) {
      return statusText(this.scoreOf(r));
    },
    approvalBadgeClass(r) {
      // Badge status approval per baris: Menunggu(kuning)/Disetujui(hijau)/
      // Ditolak(merah). Belum diajukan (null/'') = abu-abu.
      const s = r.approval_status;
      if (s === 'Disetujui') return 'orangehrm-status status-green';
      if (s === 'Ditolak') return 'orangehrm-status status-red';
      if (s === 'Menunggu') return 'orangehrm-status status-yellow';
      return 'orangehrm-status status-grey';
    },
    approvalLabel(r) {
      const s = r.approval_status;
      if (s === 'Disetujui') return 'Disetujui';
      if (s === 'Ditolak') return 'Ditolak';
      if (s === 'Menunggu') return 'Menunggu';
      return 'Belum diajukan';
    },
    formatPeriode(ym) {
      // "2026-09" -> "September 2026"
      if (!ym || typeof ym !== 'string') return ym || '';
      const parts = ym.split('-');
      if (parts.length !== 2) return ym;
      const m = parseInt(parts[1], 10);
      const namaBulan = MONTH_NAMES[m - 1];
      return namaBulan ? `${namaBulan} ${parts[0]}` : ym;
    },
    groupBobot(g) {
      return g.rows.reduce((a, r) => a + (r.bobot || 0), 0);
    },
    groupSkor(g) {
      let wsum = 0;
      let w = 0;
      for (const r of g.rows) {
        const s = this.scoreOf(r);
        if (s === null) continue;
        wsum += (r.bobot || 0) * s;
        w += r.bobot || 0;
      }
      return w > 0 ? wsum / w : null;
    },
    groupSkorDisplay(g) {
      const s = this.groupSkor(g);
      return s === null ? '—' : s.toFixed(1) + '%';
    },
    groupStatus(g) {
      const s = this.groupSkor(g);
      return s === null ? 'Belum diisi' : statusText(s);
    },
    groupBadge(g) {
      const s = this.groupSkor(g);
      return `orangehrm-status ${statusColor(s)}`;
    },
    // --- Export Excel (.xlsx, zero-dep) ---------------------------------
    // Diexport = SEMUA grup hasil filter (bukan hanya halaman pagination
    // aktif), bentuknya mirror tabel: baris indikator + baris TOTAL BOBOT.
    exportExcel() {
      const groups = this.filteredGroups;
      if (!groups.length) return;
      const rows = [];
      rows.push([{v: 'Input Realisasi KPI', s: XLSX_STYLE.TITLE}]);
      rows.push([{v: this.exportFilterText(), s: XLSX_STYLE.META}]);
      rows.push([{v: `Export: ${this.exportStamp()}`, s: XLSX_STYLE.META}]);
      rows.push([]);
      rows.push(
        EXPORT_COLUMNS.map((c) => ({v: c.title, s: XLSX_STYLE.HEADER})),
      );
      for (const g of groups) {
        for (const r of g.rows) rows.push(this.exportDataRow(r));
        rows.push(this.exportTotalRow(g));
      }
      downloadXlsx({
        fileName: `Input_Realisasi_KPI_${this.exportStamp('file')}.xlsx`,
        sheetName: 'Input Realisasi KPI',
        columns: EXPORT_COLUMNS,
        rows,
      });
    },
    // Label filter aktif untuk baris meta di file (mengikuti filter Search).
    exportFilterText() {
      const div =
        this.applied.division && this.applied.division.label !== 'Semua'
          ? this.applied.division.label
          : 'Semua';
      const cab =
        this.applied.cabang && this.applied.cabang.label !== 'Semua'
          ? this.applied.cabang.label
          : 'Semua';
      let per = 'Semua';
      if (this.applied.fromDate || this.applied.toDate) {
        const f = this.applied.fromDate
          ? this.formatPeriode(String(this.applied.fromDate).slice(0, 7))
          : 'Awal';
        const t = this.applied.toDate
          ? this.formatPeriode(String(this.applied.toDate).slice(0, 7))
          : 'Akhir';
        per = `${f} s.d. ${t}`;
      }
      return `Divisi: ${div} • Cabang: ${cab} • Periode: ${per}`;
    },
    // 'file' -> YYYYMMDD (nama file), default -> DD-MM-YYYY HH:MM (isi file).
    exportStamp(kind) {
      const d = new Date();
      const p = (n) => String(n).padStart(2, '0');
      if (kind === 'file') {
        return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}`;
      }
      return `${p(d.getDate())}-${p(d.getMonth() + 1)}-${d.getFullYear()} ${p(
        d.getHours(),
      )}:${p(d.getMinutes())}`;
    },
    // Baris indikator: pakai nilai yang TAMPIL di tabel (draft edit menimpa
    // nilai server) supaya file == layar.
    exportDataRow(r) {
      const skor = this.scoreOf(r);
      const real =
        r.realisasi === '' || r.realisasi === null || r.realisasi === undefined
          ? ''
          : Number(r.realisasi);
      return [
        {v: r.indikator, s: XLSX_STYLE.CELL},
        {v: this.divisiName(r.divisi), s: XLSX_STYLE.CELL},
        {v: this.formatPeriode(r.periode), s: XLSX_STYLE.CELL},
        {v: r.cabang || 'HO', s: XLSX_STYLE.CELL},
        {v: Number(this.rowBobot(r)) || 0, s: XLSX_STYLE.NUM},
        {v: Number(this.rowTarget(r)) || 0, s: XLSX_STYLE.NUM},
        {v: r.satuan || '', s: XLSX_STYLE.CELL},
        {v: this.rowSchedule(r) || '', s: XLSX_STYLE.CELL},
        {v: real, s: XLSX_STYLE.NUM},
        {v: skor === null ? '' : Math.round(skor * 10) / 10, s: XLSX_STYLE.PCT},
        {v: this.statusLabel(r), s: statusStyleForColor(statusColor(skor))},
        {v: r.bukti || '', s: XLSX_STYLE.CELL},
      ];
    },
    // Baris TOTAL BOBOT per grup + capaian berbobot & statusnya.
    exportTotalRow(g) {
      const skor = this.groupSkor(g);
      const grey = {v: '', s: XLSX_STYLE.TOTAL_LABEL};
      return [
        {v: 'TOTAL BOBOT', s: XLSX_STYLE.TOTAL_LABEL},
        {...grey},
        {...grey},
        {...grey},
        {v: this.groupBobot(g), s: XLSX_STYLE.TOTAL_NUM},
        {...grey},
        {...grey},
        {...grey},
        {...grey},
        {
          v: skor === null ? '' : Math.round(skor * 10) / 10,
          s: XLSX_STYLE.TOTAL_PCT,
        },
        {v: this.groupStatus(g), s: statusStyleForColor(statusColor(skor))},
        {...grey},
      ];
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
          this.isAdmin = d.is_admin;
          this.currentDivision = d.current_division;
          this.divisions = d.divisions || [];
          this.locations = d.locations || [];
          this.allowedCabangs = d.allowed_cabangs || null;
          if (!this.addForm.division) {
            this.addForm.division = this.divisionOptions[0] || null;
          }
          if (!this.addForm.cabang) {
            this.addForm.cabang = this.locationOptions[0] || null;
          }
          this.rows = d.rows || [];
        } else {
          console.error('Server:', json.message);
        }
      } catch (e) {
        console.error('Gagal memuat KPI:', e);
      } finally {
        this.isLoading = false;
      }
    },
    async save(flag) {
      // Baris yang input-nya dikunci jadwal (Triwulan/Semester di luar jendela
      // penginputan) TIDAK ikut dikirim, supaya nilai lama di DB tidak tertimpa
      // oleh angka yang tampil di layar.
      const payload = {
        emp: this.empNumber,
        submit: flag,
        rows: this.rows
          .filter((r) => !this.inputLocked(r))
          .map((r) => ({
            setup_id: r.setup_id,
            divisi: r.divisi,
            cabang: r.cabang || '',
            periode: r.periode,
            realisasi: r.realisasi,
            bukti: r.bukti,
          })),
      };
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/save_kpi_realisasi.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
          },
        );
        const json = await res.json();
        if (json.status === 'success') {
          if (flag) {
            // Submit juga semua draft edit definisi yang belum diajukan ke
            // Approval (baru dikirim saat tombol ini diklik).
            const failedEdits = await this.submitPendingEdits();
            // Popup SweetAlert (samakan pola dgn Save Draft/Add) — pengganti toast.
            await Swal.fire({
              icon: failedEdits ? 'warning' : 'success',
              title: 'Berhasil Diajukan',
              text:
                json.message +
                (failedEdits ? ` — ${failedEdits} edit tidak terajukan.` : ''),
              confirmButtonText: 'OK',
            });
            await this.fetchData();
          } else {
            await Swal.fire({
              icon: 'success',
              title: 'Save Successfully',
              text: json.message || 'Draft disimpan.',
              confirmButtonText: 'OK',
            });
          }
        } else {
          this.$toast.error(json.message || 'Gagal menyimpan');
        }
      } catch (e) {
        this.$toast.error('Gagal menyimpan: ' + e.message);
      }
    },
    saveDraft() {
      this.save(false);
    },
    // Kirim semua draft edit definisi (target/bobot/schedule) ke
    // save_setup_edit_request.php sbg request 'Menunggu'. Yang berhasil
    // dikirim keluar dari pendingEdits; yang gagal dipertahankan biar bisa
    // dicoba lagi. Return jumlah draft yang gagal.
    async submitPendingEdits() {
      if (!this.pendingEdits.length) return 0;
      const orig = [...this.pendingEdits];
      const failed = [];
      for (const d of orig) {
        try {
          const res = await fetch(
            `${this.getBaseUrl()}/proxy/save_setup_edit_request.php`,
            {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              body: JSON.stringify({
                emp: this.empNumber,
                setup_id: d.setup_id,
                divisi: d.divisi,
                bobot: d.bobot,
                target: d.target,
                schedule: d.schedule,
              }),
            },
          );
          const json = await res.json();
          if (!json || json.status !== 'success') failed.push(d);
        } catch (e) {
          failed.push(d);
        }
      }
      // Hanya yang berhasil dihapus dari draft; yang gagal tetap tersimpan.
      const failedIds = new Set(failed.map((d) => d.setup_id));
      if (failed.length)
        this.pendingEdits = this.pendingEdits.filter((d) =>
          failedIds.has(d.setup_id),
        );
      else this.pendingEdits = [];
      return failed.length;
    },
    onSubmit() {
      // Tombol "Ajukan Verifikasi & Approve" AKTIF kembali (2026-10-06) —
      // mengirim submit=true ke save_kpi_realisasi.php (mengunci baris terisi).
      this.save(true);
    },
    onSearch() {
      // Snapshot nilai filter -> diterapkan ke tampilan (frontend-only).
      this.currentPage = 1;
      this.applied = {
        division: this.filters.division || null,
        cabang: this.filters.cabang || null,
        fromDate: this.filters.fromDate || '',
        toDate: this.filters.toDate || '',
      };
    },
    onReset() {
      const semua = {id: 0, label: 'Semua'};
      this.currentPage = 1;
      this.filters.division = semua;
      this.filters.cabang = semua;
      this.filters.fromDate = '';
      this.filters.toDate = '';
      this.applied = {
        division: semua,
        cabang: semua,
        fromDate: '',
        toDate: '',
      };
    },
    toggleAdd() {
      this.showAdd = true;
      this.resetAddForm();
    },
    rowKey(r) {
      // Kunci unik per baris = (setup_id, periode, cabang) — indikator sama bisa
      // diisi per cabang berbeda (realisasi dibedakan per cabang). Include cabang
      // supaya delete & react :key tidak bentrok antar cabang.
      return `${r.setup_id}__${r.periode}__${r.cabang || ''}`;
    },
    toggleDelete(r) {
      const key = this.rowKey(r);
      const idx = this.deletedKeys.indexOf(key);
      if (idx >= 0) this.deletedKeys.splice(idx, 1);
      else this.deletedKeys.push(key);
    },
    confirmDeleteSelected() {
      if (!this.deletedKeys.length) return;
      const nama = this.deletedKeys
        .map((key) => {
          const [sid, per] = key.split('__');
          const hit = this.rows.find(
            (x) => x.setup_id === Number(sid) && x.periode === per,
          );
          return hit
            ? `${hit.indikator} (${this.formatPeriode(per)})`
            : `#${sid} ${per}`;
        })
        .join(', ');
      Swal.fire({
        title: 'Hapus Indikator?',
        text: `Indikator berikut akan dihapus untuk periode terpilih: ${nama}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
      }).then((result) => {
        if (result.isConfirmed) this.deleteSelected();
      });
    },
    async deleteSelected() {
      const items = this.deletedKeys.map((key) => {
        const [sid, per] = key.split('__');
        return {setup_id: Number(sid), periode: per};
      });
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/delete_setup_kpi.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({emp: this.empNumber, items}),
          },
        );
        const json = await res.json();
        if (json.status === 'success') {
          await Swal.fire({
            icon: 'success',
            title: 'Deleted',
            text: json.message,
            confirmButtonText: 'OK',
          });
          this.deletedKeys = [];
          // Bersihkan draft edit untuk setup yang dihapus (mencegah draft
          // mengacu ke indikator yang sudah dinonaktifkan).
          const delSetupIds = new Set(items.map((i) => i.setup_id));
          if (delSetupIds.size) {
            this.pendingEdits = this.pendingEdits.filter(
              (d) => !delSetupIds.has(d.setup_id),
            );
          }
          await this.fetchData();
        } else {
          await Swal.fire({
            icon: 'error',
            title: 'Gagal',
            text: json.message,
            confirmButtonText: 'OK',
          });
        }
      } catch (e) {
        await Swal.fire({
          icon: 'error',
          title: 'Gagal',
          text: 'Terjadi kesalahan: ' + e.message,
          confirmButtonText: 'OK',
        });
      }
    },
    onAddCancel() {
      this.showAdd = false;
      this.resetAddForm();
    },
    resetAddForm() {
      this.addForm = {
        division: this.divisionOptions[0] || null,
        cabang: this.locationOptions[0] || null,
        indikator: '',
        target: null,
        bobot: null,
        schedule: this.scheduleOptions[0] || null,
        periode: this.addPeriodeOptions[0] || null,
      };
    },
    // Pastikan addForm.periode selalu ada di daftar opsi yang berlaku.
    syncAddPeriode() {
      const opts = this.addPeriodeOptions;
      const cur = this.addForm.periode;
      const curId = cur && typeof cur === 'object' ? cur.id : cur;
      if (!opts.some((o) => String(o.id) === String(curId))) {
        this.addForm.periode = opts[0] || null;
      }
    },
    async onAddSubmit() {
      const f = this.addForm;
      const div =
        f.division && typeof f.division === 'object'
          ? f.division
          : this.divisionOptions.find((o) => o.id == f.division) ||
            this.divisionOptions.find((o) => o.code === f.division) ||
            this.divisionOptions.find((o) => o.label === f.division);
      const sch =
        f.schedule && typeof f.schedule === 'object'
          ? f.schedule
          : this.scheduleOptions.find((o) => o.id == f.schedule);
      const cab =
        f.cabang && typeof f.cabang === 'object'
          ? f.cabang
          : this.locationOptions.find((o) => o.id == f.cabang);
      const per = f.periode && typeof f.periode === 'object' ? f.periode : null;
      if (!f.indikator || !div || !div.label) {
        this.$toast.error('Indikator dan Divisi wajib diisi.');
        return;
      }
      if (
        !f.target ||
        f.target <= 0 ||
        !f.bobot ||
        f.bobot < 0 ||
        f.bobot > 100
      ) {
        this.$toast.error('Target wajib > 0 dan Bobot wajib 0–100.');
        return;
      }
      const body = {
        emp: this.empNumber,
        divisi: this.divisiCode(div),
        cabang: cab && cab.label !== 'HO' ? cab.label : '',
        periode: per && per.id ? per.id : null,
        indikator: f.indikator,
        target: Number(f.target),
        bobot: Number(f.bobot),
        schedule: sch ? sch.label : 'Monthly',
        lower_better: 0,
      };
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/save_setup_kpi.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
          },
        );
        const json = await res.json();
        if (json.status === 'success') {
          await Swal.fire({
            icon: 'success',
            title: 'Created Successfully',
            text: json.message || 'Indikator KPI berhasil ditambahkan.',
            confirmButtonText: 'OK',
          });
          this.showAdd = false;
          this.resetAddForm();
          await this.fetchData();
        } else {
          this.$toast.error(json.message || 'Gagal menambahkan indikator.');
        }
      } catch (e) {
        this.$toast.error('Gagal menambahkan indikator: ' + e.message);
      }
    },
    scheduleToOption(s) {
      const map = {
        Monthly: 'Bulanan',
        Quarterly: 'Triwulan',
        Semester: 'Semester',
        // 'Tahunan' sudah tidak ada di dropdown -> arahkan ke 'Annual'.
        Yearly: 'Annual',
      };
      const label = map[s] || s;
      return (
        this.scheduleOptions.find((o) => o.label === label) ||
        this.scheduleOptions[0]
      );
    },
    openEdit(r) {
      const d = this.getDraft(r.setup_id);
      this.editForm = {
        setup_id: r.setup_id,
        indikator: r.indikator,
        divisi: r.divisi,
        // Opsi divisi utk dropdown edit (ADMIN saja). Untuk non-admin tetap
        // dipakai divisi string read-only.
        division:
          this.divisionOptions.find((o) => o.code === r.divisi) ||
          this.divisionOptions.find((o) => o.label === r.divisi) ||
          null,
        cabang: r.cabang || 'HO',
        bobot: d ? d.bobot : r.bobot,
        target: d ? d.target : r.target,
        schedule: this.scheduleToOption(d ? d.schedule : r.schedule),
      };
      this.showEdit = true;
    },
    editCancel() {
      this.showEdit = false;
      this.editForm = null;
    },
    // Buka modal riwayat perubahan nilai Achievement utk satu baris.
    async openHistory(r) {
      this.historyIndikator = r.indikator;
      this.historyPeriode = r.periode;
      this.historyCabang = r.cabang || 'HO';
      this.historyRows = [];
      this.showHistory = true;
      try {
        const params = new URLSearchParams({
          emp: String(this.empNumber),
          setup_id: String(r.setup_id),
          periode: r.periode,
          cabang: r.cabang || '',
        });
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/get_kpi_realisasi_history.php?${params}`,
        );
        const json = await res.json();
        this.historyRows =
          json.status === 'success' ? json.data?.sejarah || [] : [];
        if (json.status !== 'success') {
          this.$toast.error(json.message || 'Gagal memuat history.');
        }
      } catch (e) {
        this.$toast.error('Gagal memuat history: ' + e.message);
      }
    },
    closeHistory() {
      this.showHistory = false;
      this.historyRows = [];
    },
    // Teks subjudul modal: periode + cabang.
    historyPeriodeText() {
      if (!this.historyPeriode) return '';
      return `Periode: ${this.formatPeriode(this.historyPeriode)} • Cabang: ${
        this.historyCabang
      }`;
    },
    // Nilai kolom Perubahan (old → new), null = '—'.
    historyValue(v) {
      return v === null || v === undefined || v === '' ? '—' : String(v);
    },
    formatDateTime(dt) {
      if (!dt) return '—';
      // 'YYYY-MM-DD HH:MM:SS' -> 'DD-MM-YYYY HH:MM'
      const m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(String(dt));
      if (!m) return String(dt);
      return `${m[3]}-${m[2]}-${m[1]} ${m[4]}:${m[5]}`;
    },
    async onEditSubmit() {
      const f = this.editForm;
      const sch =
        f.schedule && typeof f.schedule === 'object'
          ? f.schedule
          : this.scheduleOptions.find((o) => o.id == f.schedule);
      // Resolve divisi. ADMIN boleh memilih divisi baru lewat dropdown (edit);
      // non-admin TETAP pakai divisi asli baris (tidak bisa pindah divisi).
      const dOpt =
        this.isAdmin && f.division && typeof f.division === 'object'
          ? f.division
          : null;
      const divisi = dOpt ? this.divisiCode(dOpt) : f.divisi;
      if (!f.setup_id || !f.indikator || !divisi) {
        this.$toast.error('Data indikator tidak valid.');
        return;
      }
      if (
        !f.target ||
        f.target <= 0 ||
        f.bobot === null ||
        f.bobot === undefined ||
        f.bobot < 0 ||
        f.bobot > 100
      ) {
        this.$toast.error('Target wajib > 0 dan Bobot wajib 0–100.');
        return;
      }
      const body = {
        emp: this.empNumber,
        setup_id: f.setup_id,
        divisi: divisi,
        bobot: Number(f.bobot),
        target: Number(f.target),
        schedule: sch && sch.label ? sch.label : 'Monthly',
      };
      try {
        if (this.isAdmin) {
          // Admin: update langsung (save_setup_kpi.php)
          const res = await fetch(
            `${this.getBaseUrl()}/proxy/save_setup_kpi.php`,
            {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              body: JSON.stringify(body),
            },
          );
          const json = await res.json();
          if (json.status === 'success') {
            this.$toast.success(json.message);
            this.editCancel();
            await this.fetchData();
          } else {
            this.$toast.error(json.message || 'Gagal memperbarui indikator.');
          }
        } else {
          // Non-admin: Simpan edit DIKUMPULKAN sbg DRAFT — TIDAK langsung submit
          // approval. Nilai baru ditampilkan di baris (preview) + badge
          // "Perubahan Belum Diajukan". Baru dikirim ke save_setup_edit_request.php
          // (status Menunggu -> tab Approval > Edit Indikator) saat tombol
          // "Ajukan Verifikasi & Approve" diklik.
          const idx = this.pendingEdits.findIndex(
            (d) => d.setup_id === f.setup_id,
          );
          const draft = {
            setup_id: f.setup_id,
            divisi: divisi,
            bobot: Number(f.bobot),
            target: Number(f.target),
            schedule: sch && sch.label ? sch.label : 'Monthly',
          };
          if (idx >= 0) this.pendingEdits.splice(idx, 1, draft);
          else this.pendingEdits.push(draft);
          this.editCancel();
          await Swal.fire({
            icon: 'info',
            title: 'Perubahan Disimpan',
            text: 'Perubahan belum diajukan ke Approval. Klik "Ajukan Verifikasi & Approve" untuk mengajukannya.',
            confirmButtonText: 'OK',
          });
          // JANGAN fetchData di sini — baris menampilkan nilai draft dari
          // pendingEdits, dan fetchData akan menghapus realisasi yang baru
          // diketik (belum disimpan).
        }
      } catch (e) {
        this.$toast.error('Gagal memperbarui indikator: ' + e.message);
      }
    },
  },
};
</script>

<style lang="scss" scoped>
.orangehrm-header-container {
  display: flex;
  justify-content: flex-start;
  margin-bottom: 0.5rem;
}
.orangehrm-kpi-section {
  margin-top: 0.25rem;
}
.orangehrm-kpi-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
}
.orangehrm-scope-tag {
  font-size: 12px;
  color: #4338ca;
}

.orangehrm-empty-filter {
  padding: 1.25rem 0.75rem;
  text-align: center;
  color: #929baa;
}

.orangehrm-table-wrap {
  overflow-x: auto;
  border: 1px solid #e4e6ea;
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

/* Pagination tabel Input Realisasi KPI — center & beri jarak dari tabel */
.orangehrm-pagination {
  display: flex;
  justify-content: center;
  margin-top: 0.75rem;
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

.orangehrm-modal-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #64728c;
  margin-bottom: 4px;
}
.orangehrm-edit {
  color: #fd7e14;
}
.orangehrm-hist {
  color: #64728c;
  margin-left: 0.35rem;
}
.orangehrm-change-cell {
  font-weight: 600;
  color: #303030;
  white-space: nowrap;
}
.orangehrm-editpending {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  background: #dbeafe;
  color: #1d4ed8;
  white-space: nowrap;
}
.orangehrm-editapproved {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  background: #dcfce7;
  color: #15803d;
  white-space: nowrap;
}
.orangehrm-editdraft {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  background: #fef3c7;
  color: #b45309;
  white-space: nowrap;
}
.orangehrm-group-header {
  background: #f3f4f6;
  td {
    border-bottom: 1px solid #e4e6ea;
    padding: 0.6rem 0.75rem;
  }
}
.orangehrm-group-title {
  font-weight: 700;
  font-size: 13px;
  color: #303030;
}
.orangehrm-group-sub {
  font-size: 11px;
  color: #929baa;
  margin-top: 2px;
}

.orangehrm-delete-cell {
  text-align: center;
  width: 4%;
  input[type='checkbox'] {
    width: 16px;
    height: 16px;
    accent-color: #de350b;
    cursor: pointer;
    margin: 0;
  }
}
.orangehrm-indikator {
  font-weight: 600;
  color: #303030;
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
.orangehrm-subrow {
  font-size: 11px;
  color: #929baa;
  margin-top: 2px;
}
.orangehrm-num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.orangehrm-skor-blue {
  color: #1d4ed8;
  font-weight: 600;
}
.orangehrm-skor-green {
  color: #15803d;
  font-weight: 600;
}
.orangehrm-skor-yellow {
  color: #a16207;
  font-weight: 600;
}
.orangehrm-skor-red {
  color: #b91c1c;
  font-weight: 600;
}
.orangehrm-skor-grey {
  color: #64748b;
  font-weight: 600;
}
.orangehrm-bold {
  font-weight: 700;
}

.orangehrm-real {
  width: 74px;
  text-align: center;
}

.orangehrm-sumber {
  display: flex;
  align-items: center;
  gap: 6px;
}
.orangehrm-sumber-input {
  width: 100%;
  min-width: 150px;
  font-size: 12px;
}
.orangehrm-sumber-link {
  font-size: 12px;
  color: #1d4ed8;
  text-decoration: none;
  white-space: nowrap;
  &:hover {
    text-decoration: underline;
  }
}

.orangehrm-open {
  color: #15803d;
  font-size: 13px;
}

.orangehrm-total-row {
  background: #f3f4f6;
  td {
    border-top: 2px solid #e4e6ea;
    font-weight: 700;
    color: #303030;
  }
}

.orangehrm-status {
  display: inline-block;
  padding: 3px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  &.status-blue {
    background: #dbeafe;
    color: #1d4ed8;
  }
  &.status-green {
    background: #dcfce7;
    color: #15803d;
  }
  &.status-yellow {
    background: #fef9c3;
    color: #a16207;
  }
  &.status-red {
    background: #fef2f2;
    color: #b91c1c;
  }
  &.status-grey {
    background: #f1f5f9;
    color: #64748b;
  }
}
</style>
