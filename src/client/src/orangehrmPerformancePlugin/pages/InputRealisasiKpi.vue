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
            <oxd-grid :cols="3" class="orangehrm-full-width-grid">
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
                  v-model="filters.year"
                  type="select"
                  :label="'Year'"
                  :options="yearOptions"
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
              label="Export"
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
          label="Add Indikator"
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
          <!-- Rumus Perhitungan Skor (permintaan Pak): menentukan arah rasio
               pencapaian yang dipakai menghitung kolom Skor indikator ini. -->
          <oxd-form-row>
            <oxd-input-field
              v-model="addForm.rumus"
              type="select"
              :label="'Rumus Perhitungan Skor'"
              :options="rumusOptions"
              required
            />
          </oxd-form-row>
          <!-- Validasi bobot (permintaan Pak): total bobot divisi × periode
               tidak boleh lewat 100%. Teks merah muncul di bawah form. -->
          <oxd-text v-if="addBobotError" tag="p" class="ohrm-bobot-error">
            {{ addBobotError }}
          </oxd-text>
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

      <oxd-dialog
        v-if="sumberModal"
        :style="{width: '90%', maxWidth: '620px'}"
        @update:show="closeSumber"
      >
        <div class="orangehrm-modal-header">
          <oxd-text type="card-title">Sumber Data</oxd-text>
        </div>
        <oxd-text type="subtitle-2">{{ sumberModal.label }}</oxd-text>
        <oxd-divider />
        <div v-if="sumberInfo" class="orangehrm-sumber-info">
          <div class="orangehrm-sumber-row">
            <span>Indikator</span>
            <b>{{ sumberInfo.indikator }}</b>
          </div>
          <div
            class="orangehrm-sumber-row orangehrm-sumber-kpi"
            :class="{'orangehrm-sumber-kpi-max': sumberInfo.capped}"
          >
            <span>KPI Ach</span>
            <b>{{ sumberInfo.kpi }}</b>
          </div>
        </div>
        <oxd-text tag="p" class="orangehrm-sumber-note">
          KPI Ach = bobot penuh bila Achievement &ge; Target; bila di bawah
          target = (Achievement &divide; Target) &times; Bobot.
        </oxd-text>
        <oxd-divider />
        <oxd-input-field
          v-model.trim="sumberModal.bukti"
          :label="'Link Sumber Data'"
          :disabled="sumberModal.locked"
          placeholder="Tempel link (mis. Google Drive)"
          autocomplete="off"
        />
        <oxd-text
          v-if="sumberModal.locked"
          tag="p"
          class="orangehrm-editpending"
        >
          Bulan ini sudah diajukan / di luar jadwal — link tidak bisa diubah.
        </oxd-text>
        <oxd-divider />
        <div class="orangehrm-form-action">
          <oxd-button
            display-type="ghost"
            label="Batal"
            type="button"
            @click="closeSumber"
          />
          <oxd-button
            display-type="secondary"
            label="Simpan"
            type="button"
            :disabled="sumberModal.locked"
            :loading="sumberSaving"
            @click="saveSumber"
          />
        </div>
      </oxd-dialog>

      <oxd-form :loading="isLoading" @submit-valid="onSubmit">
        <div
          v-if="rows.length && yearRows.length"
          class="orangehrm-kpi-section"
        >
          <div class="orangehrm-kpi-summary">
            <oxd-text type="subtitle-2">
              {{ yearRows.length }} indikator • {{ yearGroups.length }} divisi •
              tahun {{ applied.year }}
            </oxd-text>
            <span class="orangehrm-sumber-hint">
              Klik ikon &#128279; di dalam sel bulan untuk mengisi link Sumber
              Data bulan tersebut.
            </span>
            <span
              v-if="!isAdmin && currentDivision"
              class="orangehrm-scope-tag"
            >
              Anda mengisi divisi: <b>{{ divisiName(currentDivision) }}</b>
            </span>
          </div>

          <div class="orangehrm-table-wrap">
            <table
              class="oxd-table-data orangehrm-kpi-table orangehrm-kpi-year"
            >
              <thead>
                <tr>
                  <th
                    style="width: 3%"
                    class="orangehrm-delete-cell orangehrm-delete-head"
                  >
                    <input
                      type="checkbox"
                      :checked="allYearsMarked"
                      :disabled="!deletableYears.length"
                      :indeterminate.prop="someYearsMarked && !allYearsMarked"
                      :title="
                        allYearsMarked
                          ? 'Batalkan pilih semua indikator'
                          : 'Pilih semua indikator yang bisa dihapus'
                      "
                      @change="toggleDeleteAll"
                    />
                  </th>
                  <th style="width: 18%">Indikator</th>
                  <th style="width: 9%">Divisi</th>
                  <th style="width: 7%">Cabang</th>
                  <th style="width: 6%">Target</th>
                  <th style="width: 5%">Bobot</th>
                  <th style="width: 5%">Year</th>
                  <th style="width: 7%">Schedule</th>
                  <th
                    v-for="m in 12"
                    :key="'mh' + m"
                    class="orangehrm-month-head"
                  >
                    {{ monthShort[m - 1] }}
                  </th>
                  <th style="width: 8%">AVG KPI Ach</th>
                  <th style="width: 7%">AVG Ach</th>
                  <th style="width: 8%">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="g in yearGroups" :key="g.key">
                  <tr v-for="y in g.rows" :key="y.key">
                    <td class="orangehrm-delete-cell">
                      <input
                        type="checkbox"
                        class="oxd-input"
                        :checked="yearMarked(y)"
                        :disabled="!yearDeletable(y)"
                        :title="
                          yearDeletable(y)
                            ? 'Tandai untuk hapus (indikator ini di semua bulan)'
                            : 'Tidak bisa dihapus (sudah ada nilai/pengajuan)'
                        "
                        @change="toggleDeleteYear(y)"
                      />
                    </td>
                    <td>
                      <div class="orangehrm-indikator">{{ y.indikator }}</div>
                    </td>
                    <td>
                      <span class="orangehrm-divisi">{{
                        divisiName(y.divisi)
                      }}</span>
                    </td>
                    <td>
                      <span class="orangehrm-cabang">{{
                        y.cabang || 'HO'
                      }}</span>
                    </td>
                    <td class="orangehrm-num orangehrm-bold">
                      {{ yTarget(y) }}{{ y.satuan }}
                    </td>
                    <td class="orangehrm-num orangehrm-bold">
                      {{ yBobot(y) }}%
                    </td>
                    <td class="orangehrm-num orangehrm-bold">
                      {{ applied.year }}
                    </td>
                    <td class="orangehrm-bold">{{ ySchedule(y) }}</td>
                    <td
                      v-for="m in 12"
                      :key="'mc' + m + y.key"
                      class="orangehrm-num orangehrm-month-cell"
                    >
                      <template v-if="cellOf(y, m)">
                        <input
                          class="oxd-input orangehrm-real"
                          type="text"
                          inputmode="decimal"
                          autocomplete="off"
                          :value="
                            y.months[m].realisasi === null ||
                            y.months[m].realisasi === undefined
                              ? ''
                              : y.months[m].realisasi
                          "
                          placeholder="—"
                          :disabled="cellLocked(y, m)"
                          :title="cellLockHint(y, m)"
                          @input="onAchInput(y, m, $event)"
                          @blur="onAchBlur(y, m, $event)"
                        />
                        <button
                          type="button"
                          class="orangehrm-sumber-btn"
                          :class="{
                            'orangehrm-sumber-filled': !!y.months[m].bukti,
                          }"
                          :title="
                            y.months[m].bukti
                              ? 'Sumber Data: ' + y.months[m].bukti
                              : 'Tempel link Sumber Data'
                          "
                          @click="openSumber(y, m)"
                        >
                          <i class="oxd-icon bi-link-45deg"></i>
                        </button>
                      </template>
                      <span v-else class="orangehrm-month-empty">—</span>
                    </td>
                    <td class="orangehrm-num orangehrm-bold">
                      <span>{{ yScoreDisplay(y) }}</span>
                    </td>
                    <td class="orangehrm-num orangehrm-bold">
                      <span :class="yAvgAchClass(y)">{{
                        yAvgAchDisplay(y)
                      }}</span>
                    </td>
                    <td class="orangehrm-aksi-cell">
                      <span
                        v-if="pendingEdit(y.ref)"
                        class="orangehrm-editpending"
                        >&#9203; Menunggu</span
                      >
                      <oxd-icon-button
                        v-else
                        class="orangehrm-edit"
                        :with-container="false"
                        name="pencil-fill"
                        :title="'Edit'"
                        @click="openEdit(y.ref)"
                      />
                      <oxd-icon-button
                        v-if="yearHasValue(y)"
                        class="orangehrm-hist"
                        :with-container="false"
                        name="clock-fill"
                        :title="'History Achievement'"
                        @click="openHistoryYear(y)"
                      />
                    </td>
                  </tr>
                  <tr class="orangehrm-total-row">
                    <td></td>
                    <td class="orangehrm-bold">TOTAL</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="orangehrm-num orangehrm-bold">
                      {{ yGroupBobot(g) }}%
                    </td>
                    <td></td>
                    <td></td>
                    <td
                      v-for="m in 12"
                      :key="`gt-${m}`"
                      class="orangehrm-num orangehrm-bold orangehrm-total-month"
                    >
                      {{ yGroupMonthDisplay(g, m) }}
                    </td>
                    <td class="orangehrm-num orangehrm-bold">
                      <span>{{ yGroupScoreDisplay(g) }}</span>
                    </td>
                    <td class="orangehrm-num orangehrm-bold">
                      <span :class="yGroupAvgAchClass(g)">{{
                        yGroupAvgAchDisplay(g)
                      }}</span>
                    </td>
                    <td></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>

        <div
          v-else-if="rows.length && !yearRows.length"
          class="orangehrm-empty-filter"
        >
          <oxd-text type="subtitle-2">
            Tidak ada data untuk filter ini. Ubah filter atau klik Reset.
          </oxd-text>
        </div>

        <oxd-divider v-if="yearRows.length" />

        <oxd-form-actions v-if="yearRows.length">
          <required-text />
          <oxd-button
            class="orangehrm-btn-kuning"
            display-type="warning"
            :label="'Simpan'"
            type="button"
            @click="saveDraft"
          />
          <oxd-button
            class="orangehrm-left-space orangehrm-btn-kuning"
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
import {XLSX_STYLE, statusStyleForColor} from '../utils/xlsxReport';
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

// Nama bulan pendek untuk header kolom tabel per tahun.
const MONTH_SHORT = [
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

// Unduh CSV tanpa dependency. Kutip/koma/baris baru di-escape, dan BOM UTF-8
// ditambahkan supaya Excel (Windows) membaca huruf non-ASCII dengan benar.
const downloadCsv = (fileName, matrix) => {
  const escapeCell = (value) => {
    const text = value === null || value === undefined ? '' : String(value);
    return /[",;\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  };
  const csv = matrix.map((row) => row.map(escapeCell).join(',')).join('\r\n');
  const blob = new Blob([`\uFEFF${csv}`], {
    type: 'text/csv;charset=utf-8;',
  });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
};

// Kolom file Export Spreadsheet (.csv) — mirror kolom tabel + kolom Satuan
// (Target dipisah dari satuannya supaya di spreadsheet tetap angka & bisa dijumlah).
const EXPORT_COLUMNS = [
  {title: 'Indikator', width: 38},
  {title: 'Divisi', width: 16},
  {title: 'Cabang', width: 14},
  {title: 'Target', width: 12},
  {title: 'Satuan', width: 10},
  {title: 'Bobot (%)', width: 10},
  {title: 'Year', width: 8},
  {title: 'Schedule', width: 12},
  ...MONTH_SHORT.map((m) => ({title: m, width: 9})),
  {title: 'AVG KPI Ach (%)', width: 10},
  {title: 'AVG Ach (%)', width: 10},
  {title: 'Status', width: 24},
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
        // Rumus Perhitungan Skor (opsi objek {id,label}); default opsi pertama.
        rumus: null,
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
      // Sejak 2026-11-17 tabel tampil per TAHUN (kolom Jan..Des), jadi filter
      // rentang tanggal diganti dropdown Year (default tahun berjalan).
      filters: {
        division: {id: 0, label: 'Semua'},
        cabang: {id: 0, label: 'Semua'},
        year: {
          id: String(new Date().getFullYear()),
          label: String(new Date().getFullYear()),
        },
      },
      applied: {
        division: {id: 0, label: 'Semua'},
        cabang: {id: 0, label: 'Semua'},
        year: String(new Date().getFullYear()),
      },
      // Bulan yang diajukan (objek {id,label}) — approval tetap PER BULAN.
      // Modal Sumber Data per sel bulan: {key, label, bukti, locked}.
      sumberModal: null,
      sumberSaving: false,
      // Nama bulan pendek untuk header kolom Jan..Des.
      monthShort: MONTH_SHORT,
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
    // Pilihan "Rumus Perhitungan Skor" di modal Add.
    // Opsi 1: makin tinggi Achievement dibanding Target, makin tinggi Skor.
    // Opsi 2: kebalikannya (makin rendah pencapaian makin baik, mis. jumlah komplain).
    // Skor tetap ditampilkan dalam persen 0-100 (keputusan Pak 2026-11-15),
    // bobot dipakai di baris TOTAL BOBOT (rata-rata berbobot).
    rumusOptions() {
      return [
        {id: 'ach_target', label: '(Achievement/Target)'},
        {id: 'target_ach', label: '(Target/Achievement)'},
      ];
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
    // Opsi divisi yang sedang dipilih di modal Add (bisa objek atau id/kode/label).
    addDivisiOption() {
      const f = this.addForm.division;
      if (!f) return null;
      if (typeof f === 'object') return f;
      return (
        this.divisionOptions.find((o) => o.id == f) ||
        this.divisionOptions.find((o) => o.code === f) ||
        this.divisionOptions.find((o) => o.label === f) ||
        null
      );
    },
    // Id periode (YYYY-MM) yang dipilih; '' = 'Semua Periode' (tanpa batas).
    addPeriodeId() {
      const p = this.addForm.periode;
      if (!p) return null;
      const id = typeof p === 'object' ? p.id : p;
      return id ? String(id) : null;
    },
    addPeriodeLabel() {
      return this.addPeriodeId ? this.formatPeriode(this.addPeriodeId) : '';
    },
    // Total bobot yang SUDAH terpakai pada divisi × periode tersebut.
    // Batas 100% berlaku per divisi × periode (sama seperti baris TOTAL BOBOT
    // di tabel); cabang tidak memecah batas, jadi semua cabang ikut dihitung.
    addBobotTerpakai() {
      const div = this.addDivisiOption;
      const periode = this.addPeriodeId;
      if (!div || !div.code || !periode) return 0;
      return this.rows
        .filter((r) => r.divisi === div.code && String(r.periode) === periode)
        .reduce((a, r) => a + (Number(r.bobot) || 0), 0);
    },
    // Pesan teks merah di bawah form modal Add. Kosong = valid / belum bisa dihitung.
    addBobotError() {
      const input = Number(this.addForm.bobot);
      if (!this.addForm.bobot || !Number.isFinite(input)) return '';
      if (!this.addPeriodeId) return '';
      if (!this.addDivisiOption || !this.addDivisiOption.code) return '';
      const total = this.addBobotTerpakai + input;
      if (total <= 100) return '';
      const fmt = (v) => Math.round(v * 100) / 100;
      return (
        `Total bobot pada periode ${this.addPeriodeLabel} ` +
        `menjadi ${fmt(total)}% — melebihi batas 100%.`
      );
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
      // Filter TAHUN (YYYY) — menggantikan From/To Date sejak 2026-11-17.
      const year = this.applied.year ? String(this.applied.year) : '';
      if (!div && !cab && !year) return this.groups;
      const map = new Map();
      for (const r of this.rows) {
        if (div && r.divisi !== div) continue;
        if (cab) {
          const isPusat = cab === 'HO';
          if (isPusat ? (r.cabang || '') !== '' : r.cabang !== cab) continue;
        }
        if (year && String(r.periode).slice(0, 4) !== year) continue;
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
    // Bulan berjalan (YYYY-MM) — bulan yang belum berjalan tidak bisa diisi
    // (aturan server save_kpi_realisasi; Admin dikecualikan).
    currentYm() {
      const n = new Date();
      return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}`;
    },
    // Data yang ditampilkan di modal Sumber Data: indikator + KPI Achievement
    // bulan itu (otomatis dari achievement, target, dan bobot).
    sumberInfo() {
      const s = this.sumberModal;
      if (!s) return null;
      const y = this.yearRows.find((r) => r.key === s.yKey);
      if (!y) return null;
      const target = this.yTarget(y);
      const bobot = this.yBobot(y);
      const ach = this.achNumOf(y, s.m);
      const kpi = this.kpiAchOfPopup(y, s.m);
      const satuan = y.satuan || '';
      const num = (v, unit = '') =>
        v === null || v === undefined || v === ''
          ? '—'
          : `${Math.round(Number(v) * 100) / 100}${unit}`;
      const pct =
        ach !== null && Number(target) ? (ach / Number(target)) * 100 : null;
      return {
        indikator: y.indikator,
        divisi: y.divisi,
        cabang: y.cabang || '-',
        bulan: `${MONTH_NAMES[s.m - 1]} ${this.applied.year}`,
        target: num(target, satuan ? ` ${satuan}` : ''),
        bobot: num(bobot, '%'),
        ach: num(ach, satuan ? ` ${satuan}` : ''),
        achPct: pct === null ? '—' : `${Math.round(pct * 100) / 100}%`,
        // Satuan % ditambahkan (permintaan Pak 2026-11-19): angka KPI Ach di popup
        // Sumber Data tampil sebagai "30%", bukan "30".
        kpi: num(kpi, '%'),
        // Sorotan "KPI Ach maksimal" dihitung dari hasil akhirnya, bukan dari
        // achievement >= target, supaya indikator lower-is-better (mis.
        // Pengendalian Piutang Overdue) tidak ikut tersorot saat angkanya besar.
        capped:
          kpi !== null && bobot !== null && bobot !== undefined
            ? Number(kpi) >= Number(bobot)
            : false,
      };
    },
    filteredRowCount() {
      return this.filteredGroups.reduce((a, g) => a + g.rows.length, 0);
    },
    // Pilihan tahun: tahun berjalan ±1 + tahun yang ada di data.
    yearOptions() {
      const now = new Date().getFullYear();
      const set = new Set([String(now - 1), String(now), String(now + 1)]);
      for (const r of this.rows) {
        if (r.periode) set.add(String(r.periode).slice(0, 4));
      }
      return Array.from(set)
        .sort()
        .reverse()
        .map((y) => ({id: y, label: y}));
    },
    // Baris tabel versi TAHUN: satu baris = satu indikator × cabang, dengan
    // 12 sel bulan (Jan..Des). Sel kosong = belum ada baris DB utk bulan itu,
    // tapi tetap bisa diisi karena save proxy melakukan upsert.
    yearRows() {
      // Satu baris = satu NAMA indikator × cabang (bukan per setup_id): setup
      // umum (semua periode) dan setup periode khusus untuk indikator yang sama
      // DIGABUNG jadi satu baris, sehingga sel Desember diisi setup khusus dan
      // bulan lainnya oleh setup umum — tidak muncul dua baris kembar.
      const map = new Map();
      for (const g of this.filteredGroups) {
        for (const r of g.rows) {
          const key = `${r.divisi}||${r.cabang || ''}||${String(
            r.indikator,
          ).toLowerCase()}`;
          let y = map.get(key);
          if (!y) {
            y = {
              key,
              divisi: r.divisi,
              cabang: r.cabang || '',
              setup_id: r.setup_id,
              indikator: r.indikator,
              satuan: r.satuan || '',
              ref: r,
              months: {},
            };
            map.set(key, y);
          }
          const m = Number(String(r.periode).slice(5, 7));
          if (m < 1 || m > 12) continue;
          const prev = y.months[m];
          // Utamakan baris dari setup PERIODE KHUSUS (periode tidak kosong) —
          // itulah definisi yang berlaku untuk bulan tersebut.
          if (!prev) {
            y.months[m] = r;
          }
          y.setupIds = y.setupIds || new Set();
          y.setupIds.add(r.setup_id);
        }
      }
      return Array.from(map.values());
    },
    // Grup per DIVISI (baris TOTAL BOBOT mengikuti divisi, bukan periode).
    yearGroups() {
      const map = new Map();
      for (const y of this.yearRows) {
        if (!map.has(y.divisi)) {
          map.set(y.divisi, {
            key: `div-${y.divisi}`,
            divisi: y.divisi,
            rows: [],
          });
        }
        map.get(y.divisi).rows.push(y);
      }
      return Array.from(map.values());
    },
    // Daftar periode yang ada datanya (unik, terbaru di atas) — satu periode
    // menjadi satu halaman tabel.
    periodeList() {
      const list = [];
      this.filteredGroups.forEach((g) => {
        if (g.periode && !list.includes(g.periode)) list.push(g.periode);
      });
      return list.sort((a, b) => String(b).localeCompare(String(a)));
    },
    // Jumlah halaman = jumlah periode.
    pages() {
      return Math.max(1, this.periodeList.length);
    },
    currentPeriode() {
      return this.periodeList[this.currentPage - 1] || null;
    },
    currentPeriodeLabel() {
      const total = this.periodeList.length;
      if (!total || !this.currentPeriode) return '';
      return `${this.formatPeriode(this.currentPeriode)} · ${
        this.currentPage
      } dari ${total}`;
    },
    // Halaman aktif = SEMUA grup (divisi × cabang) pada periode itu, utuh,
    // supaya baris TOTAL BOBOT tidak terpecah ke halaman lain.
    paginatedGroups() {
      const periode = this.currentPeriode;
      if (!periode) return [];
      return this.filteredGroups.filter((g) => g.periode === periode);
    },
    // --- "Pilih semua" di header tabel (permintaan Pak 2026-11-18) ---
    // Hanya baris yang boleh dihapus yang ikut tercentang; baris yang sudah
    // punya nilai/pengajuan tetap dilewati supaya server tidak menolak.
    deletableYears() {
      return this.yearRows.filter((y) => this.yearDeletable(y));
    },
    allYearsMarked() {
      const list = this.deletableYears;
      return list.length > 0 && list.every((y) => this.yearMarked(y));
    },
    someYearsMarked() {
      return this.yearRows.some((y) => this.yearMarked(y));
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
    // Default "Rumus Perhitungan Skor" = opsi pertama (Achievement / Target).
    if (!this.addForm.rumus) this.addForm.rumus = this.rumusOptions[0] || null;
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
    // Geser halaman per periode.
    prevPeriode() {
      if (this.currentPage > 1) this.currentPage -= 1;
    },
    nextPeriode() {
      if (this.currentPage < this.pages) this.currentPage += 1;
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

    // ===== Tabel per TAHUN (permintaan Pak, 2026-11-17) =====
    // Satu baris = satu indikator × cabang, dengan 12 sel bulan.
    skorClassOf(score) {
      const map = {
        'status-blue': 'orangehrm-skor-blue',
        'status-green': 'orangehrm-skor-green',
        'status-yellow': 'orangehrm-skor-yellow',
        'status-red': 'orangehrm-skor-red',
        'status-grey': 'orangehrm-skor-grey',
      };
      return map[statusColor(score)] || 'orangehrm-skor-grey';
    },
    cellOf(y, m) {
      return y.months[m] || null;
    },
    // Angka achievement sebuah sel (null kalau kosong / bukan angka).
    // Nilai Achievement selalu tampil 2 desimal (7.40) agar konsisten saat
    // diisi maupun setelah dimuat dari server.
    fmtAch(v) {
      if (v === null || v === undefined || String(v).trim() === '') return '';
      const n = Number(String(v).replace(',', '.'));
      if (Number.isNaN(n)) return String(v);
      // 2 desimal HANYA kalau tidak menghilangkan presisi. Angka kecil (mis.
      // Stock Opname 0.002 dengan target 0.025) harus tetap 0.002 — kalau
      // dipaksa 0.00 nilainya hilang dan skornya jadi salah.
      const r2 = Math.round(n * 100) / 100;
      return Math.abs(n - r2) < 1e-9 ? r2.toFixed(2) : String(n);
    },
    // Hanya izinkan angka + satu titik desimal, maksimal 2 desimal.
    onAchInput(y, m, ev) {
      let v = String(ev.target.value).replace(',', '.');
      v = v.replace(/[^0-9.]/g, '');
      const parts = v.split('.');
      if (parts.length > 2) v = `${parts[0]}.${parts.slice(1).join('')}`;
      if (v.includes('.')) {
        const [a, b] = v.split('.');
        // Maksimal 6 desimal: cukup untuk angka kecil (0.002) tanpa memotong
        // presisi seperti kalau dipaksa 2 desimal.
        v = `${a}.${b.slice(0, 6)}`;
      }
      if (ev.target.value !== v) ev.target.value = v;
      y.months[m].realisasi = v;
    },
    // Saat keluar dari sel, rapikan menjadi 2 desimal (7.4 -> 7.40).
    onAchBlur(y, m, ev) {
      const raw = String(ev.target.value).replace(',', '.').trim();
      let out = '';
      if (raw !== '' && !Number.isNaN(Number(raw))) {
        const n = Number(raw);
        const r2 = Math.round(n * 100) / 100;
        // Lihat fmtAch(): 2 desimal hanya bila presisinya utuh, supaya angka
        // kecil seperti 0.002 tidak berubah menjadi 0.00.
        out = Math.abs(n - r2) < 1e-9 ? r2.toFixed(2) : String(n);
      }
      y.months[m].realisasi = out;
      ev.target.value = out;
    },
    achNumOf(y, m) {
      const c = this.cellOf(y, m);
      if (!c) return null;
      const v = String(c.realisasi ?? '').replace(',', '.');
      if (v === '') return null;
      const n = Number(v);
      return Number.isNaN(n) ? null : n;
    },
    // KPI Achievement sebuah sel bulan:
    //   achievement >= target  -> bobot penuh (nilai maksimal)
    //   achievement <  target  -> (achievement / target) x bobot
    // Dipakai di modal Sumber Data. Indikator lain bisa memakai rumus berbeda
    // (rumus per indikator), jadi perhitungan dipusatkan di sini.
    // Rumus khusus indikator "Pengendalian Piutang Overdue" di popup Sumber Data
    // (permintaan Pak 2026-11-19):
    //   achievement <= 2  -> KPI Ach = bobot penuh
    //   achievement >  2  -> KPI Ach = (target / achievement) x bobot
    // Indikator lain tetap memakai kpiAchOf().
    kpiAchOfPopup(y, m) {
      if (!/pengendalian piutang overdue/i.test(String(y.indikator || ''))) {
        return this.kpiAchOf(y, m);
      }
      const ach = this.achNumOf(y, m);
      const target = Number(this.yTarget(y));
      const bobot = this.yBobot(y);
      if (ach === null || !target || bobot === null || bobot === undefined) {
        return null;
      }
      if (ach <= 2) return Number(bobot);
      return Math.min(Number(bobot), (target / ach) * Number(bobot));
    },
    kpiAchOf(y, m) {
      const ach = this.achNumOf(y, m);
      const target = Number(this.yTarget(y));
      const bobot = this.yBobot(y);
      if (ach === null || !target || bobot === null || bobot === undefined) {
        return null;
      }
      if (ach >= target) return Number(bobot);
      return (ach / target) * Number(bobot);
    },
    yBobot(y) {
      const d = this.getDraft(y.setup_id);
      return d ? d.bobot : y.ref.bobot;
    },
    yTarget(y) {
      const d = this.getDraft(y.setup_id);
      return d ? d.target : y.ref.target;
    },
    ySchedule(y) {
      const d = this.getDraft(y.setup_id);
      return d ? d.schedule : y.ref.schedule;
    },
    // Bulan yang boleh diisi sesuai Schedule (null = semua bulan).
    yScheduleMonths(y) {
      return this.scheduleMonths(this.ySchedule(y));
    },
    // Sel terkunci bila: belum ada baris data, bulan itu sudah diajukan, atau
    // bulannya di luar jadwal indikator (Triwulan/Semester/Annual).
    cellLocked(y, m) {
      const c = this.cellOf(y, m);
      if (!c) return true;
      if (c.submitted) return true;
      const months = this.yScheduleMonths(y);
      if (months && !months.includes(m)) return true;
      // Bulan yang belum berjalan dikunci (kecuali Admin) — server menolak
      // menyimpan periode yang belum berjalan.
      if (!this.isAdmin && String(c.periode) > this.currentYm) return true;
      return false;
    },
    cellLockHint(y, m) {
      const c = this.cellOf(y, m);
      if (!c) return 'Bulan ini belum punya baris data';
      if (c.submitted) return 'Bulan ini sudah diajukan untuk approval';
      const months = this.yScheduleMonths(y);
      if (months && !months.includes(m)) {
        return `Jadwal ${this.ySchedule(
          y,
        )} hanya diisi bulan ${this.monthsLabel(months)}`;
      }
      if (!this.isAdmin && String(c.periode) > this.currentYm) {
        return 'Bulan belum berjalan — belum bisa diisi';
      }
      return 'Achievement bulan ini';
    },
    // Skor satu sel = persen 0-100 (bobot TIDAK dikali) — sama dengan aturan
    // kolom Skor sebelumnya.
    cellScore(c) {
      if (
        !c ||
        c.realisasi === null ||
        c.realisasi === undefined ||
        c.realisasi === ''
      ) {
        return null;
      }
      const target = Number(c.target) || 0;
      const real = Number(c.realisasi) || 0;
      if (!target) return null;
      const s = c.lower ? (target / (real || 1)) * 100 : (real / target) * 100;
      return Math.min(100, s);
    },
    // Average (aturan Pak, 2026-11-17):
    //   Average = (jumlah KPI Ach pada bulan jadwal) / jumlah bulan jadwal
    //   - Bulanan  -> 12 bulan, dibagi 12
    //   - Triwulan -> Mar/Jun/Sep/Des, dibagi 4
    //   - Semester -> Jun/Des, dibagi 2
    //   - Annual   -> Des, dibagi 1
    // KPI Ach tiap bulan sudah berbobot (lihat kpiAchOf), jadi kalau semua
    // target tercapai nilainya 100 (sesuai total bobot divisi).
    yScore(y) {
      const months = this.yScheduleMonths(y);
      const divisor = months && months.length ? months.length : 12;
      let sum = 0;
      let filled = false;
      for (let m = 1; m <= 12; m += 1) {
        if (months && !months.includes(m)) continue;
        // Sejak 2026-11-19 kolom AVG KPI Ach ikut memakai rumus popup
        // (kpiAchOfPopup) agar angkanya sama dengan angka KPI Ach yang dilihat
        // user di ikon klip - termasuk aturan khusus Pengendalian Piutang
        // Overdue: <=2 bobot penuh, >2 (target/achievement) x bobot.
        const k = this.kpiAchOfPopup(y, m);
        if (k === null) continue;
        sum += k;
        filled = true;
      }
      if (!filled) return null;
      return sum / divisor;
    },
    yScoreDisplay(y) {
      const s = this.yScore(y);
      // Dua angka di belakang koma (permintaan Pak 2026-11-19), mis. 41.67%.
      return s === null ? '—' : `${s.toFixed(2)}%`;
    },
    // AVG Ach (permintaan Pak 2026-11-20, revisi rumus 2026-11-20):
    //   Ach bulan = (Achievement ÷ Target) × 100, dibatasi maksimal 100.
    //   AVG Ach   = rata-rata Ach selama setahun HANYA dari bulan yang terisi —
    //               bulan jadwal yang belum diisi TIDAK ikut jadi pembagi
    //               (dulu dibagi jumlah bulan jadwal sehingga angka tampak kecil,
    //               mis. LND 16.48% padahal bulan yang ada nilainya ~99%).
    // Indikator ber-rumus (Target/Achievement) tetap ikut aturannya lewat
    // cellScore(), sama seperti skor bulanan. Warna: >=100 biru, >=90 hijau,
    // >=80 kuning, <80 merah (skorClassOf).
    yAvgAch(y) {
      const months = this.yScheduleMonths(y);
      let sum = 0;
      let count = 0;
      for (let m = 1; m <= 12; m += 1) {
        if (months && !months.includes(m)) continue;
        const s = this.cellScore(this.cellOf(y, m));
        if (s === null) continue;
        sum += s;
        count += 1;
      }
      if (!count) return null;
      return sum / count;
    },
    yAvgAchDisplay(y) {
      const s = this.yAvgAch(y);
      return s === null ? '—' : `${s.toFixed(2)}%`;
    },
    yAvgAchClass(y) {
      return this.skorClassOf(this.yAvgAch(y));
    },
    // Baris TOTAL (permintaan Pak 2026-11-20, revisi ke-3):
    //   tiap indikator -> AVG Ach (achievement per bulan ÷ target × 100, maks 100)
    //   TOTAL = jumlah AVG Ach indikator grup ÷ jumlah indikator yang SUDAH ADA
    //   NILAI-nya. Indikator kosong tidak ikut jadi pembagi (kontribusi 0 hanya
    //   kalau memang belum ada nilai). Grup = kombinasi divisi + cabang.
    yGroupAvgAch(g) {
      let sum = 0;
      let count = 0;
      for (const y of g.rows) {
        const v = this.yAvgAch(y);
        if (v === null) continue;
        sum += v;
        count += 1;
      }
      // Tidak ada satu pun indikator yang terisi -> '—' (bukan 0.00%).
      if (!count) return null;
      return sum / count;
    },
    yGroupAvgAchDisplay(g) {
      const s = this.yGroupAvgAch(g);
      return s === null ? '—' : `${s.toFixed(2)}%`;
    },
    yGroupAvgAchClass(g) {
      return this.skorClassOf(this.yGroupAvgAch(g));
    },
    yStatusLabel(y) {
      return statusText(this.yScore(y));
    },
    yBadge(y) {
      return `orangehrm-status ${statusColor(this.yScore(y))}`;
    },
    yScoreColorClass(y) {
      return this.skorClassOf(this.yScore(y));
    },
    // Total bobot & skor per divisi (baris TOTAL BOBOT).
    yGroupBobot(g) {
      return g.rows.reduce((a, y) => a + (Number(this.yBobot(y)) || 0), 0);
    },
    // Total divisi = jumlah Average tiap indikator (bobot tiap indikator sudah
    // masuk ke KPI Ach, jadi total maksimalnya = total bobot divisi).
    yGroupScore(g) {
      let sum = 0;
      let filled = false;
      for (const y of g.rows) {
        const s = this.yScore(y);
        if (s === null) continue;
        sum += s;
        filled = true;
      }
      return filled ? sum : null;
    },
    // Baris TOTAL kolom bulan (revisi Pak 2026-11-20): rata-rata ACHIEVEMENT
    // semua indikator pada bulan itu —
    //   tiap indikator: achievement bulan ÷ target × 100 (maks 100)
    //   nilai sel    : jumlah hasil semua indikator ÷ jumlah indikator yang
    //                  achievement bulan itu TERISI
    // Jadi angkanya persen murni 0–100 (bukan lagi Σ KPI Ach berbobot yang
    // maksimumnya = total bobot divisi). Bulan tanpa nilai sama sekali → '—'.
    yGroupMonthScore(g, m) {
      let sum = 0;
      let count = 0;
      for (const y of g.rows) {
        const s = this.cellScore(this.cellOf(y, m));
        if (s === null) continue;
        sum += s;
        count += 1;
      }
      return count ? sum / count : null;
    },
    yGroupMonthDisplay(g, m) {
      const s = this.yGroupMonthScore(g, m);
      return s === null ? '—' : `${s.toFixed(2)}%`;
    },
    yGroupScoreDisplay(g) {
      const s = this.yGroupScore(g);
      return s === null ? '—' : `${s.toFixed(2)}%`;
    },
    yGroupStatus(g) {
      const s = this.yGroupScore(g);
      return s === null ? 'Belum diisi' : statusText(s);
    },
    yGroupBadge(g) {
      return `orangehrm-status ${statusColor(this.yGroupScore(g))}`;
    },
    yGroupScoreClass(g) {
      return this.skorClassOf(this.yGroupScore(g));
    },
    // Ada nilai Achievement di salah satu bulan? (untuk ikon History)
    yearHasValue(y) {
      for (let m = 1; m <= 12; m += 1) {
        if (this.cellScore(this.cellOf(y, m)) !== null) return true;
      }
      return false;
    },
    openHistoryYear(y) {
      for (let m = 1; m <= 12; m += 1) {
        const c = this.cellOf(y, m);
        if (this.cellScore(c) !== null) {
          this.openHistory(c);
          return;
        }
      }
    },
    // --- Hapus indikator (semua bulan pada tahun yang tampil) ---
    yearRowKeys(y) {
      const keys = [];
      for (let m = 1; m <= 12; m += 1) {
        const c = this.cellOf(y, m);
        if (c) keys.push(this.rowKey(c));
      }
      return keys;
    },
    yearMarked(y) {
      return this.yearRowKeys(y).some((k) => this.deletedKeys.includes(k));
    },
    yearDeletable(y) {
      return this.yearRowKeys(y).some((k) => {
        const c = this.rows.find((r) => this.rowKey(r) === k);
        return c ? this.isRowDeletable(c) : false;
      });
    },
    // Centang / lepas SEMUA indikator sekaligus dari checkbox di header tabel.
    toggleDeleteAll() {
      const list = this.deletableYears;
      if (this.allYearsMarked) {
        list.forEach((y) => {
          if (this.yearMarked(y)) this.toggleDeleteYear(y);
        });
        return;
      }
      list.forEach((y) => {
        if (!this.yearMarked(y)) this.toggleDeleteYear(y);
      });
    },
    toggleDeleteYear(y) {
      const keys = this.yearRowKeys(y);
      if (this.yearMarked(y)) {
        this.deletedKeys = this.deletedKeys.filter((k) => !keys.includes(k));
        return;
      }
      const add = keys.filter((k) => {
        const c = this.rows.find((r) => this.rowKey(r) === k);
        return c ? this.isRowDeletable(c) : false;
      });
      this.deletedKeys = Array.from(new Set([...this.deletedKeys, ...add]));
    },
    // --- Sumber Data per sel bulan (modal kecil) ---
    openSumber(y, m) {
      const c = this.cellOf(y, m);
      if (!c) return;
      this.sumberModal = {
        key: this.rowKey(c),
        yKey: y.key,
        m,
        label: `${y.indikator} — ${MONTH_NAMES[m - 1]} ${this.applied.year}`,
        bukti: c.bukti || '',
        locked: this.cellLocked(y, m),
      };
    },
    closeSumber() {
      this.sumberModal = null;
    },
    // Simpan link Sumber Data: langsung dikirim ke server (draft) untuk baris
    // bulan itu saja, lalu tampilkan pesan berhasil.
    async saveSumber() {
      if (!this.sumberModal) return;
      const s = this.sumberModal;
      if (s.locked) {
        this.sumberModal = null;
        return;
      }
      const r = this.rows.find((x) => this.rowKey(x) === s.key);
      const link = String(s.bukti || '').trim();
      if (!r) {
        this.sumberModal = null;
        return;
      }
      if (link === '') {
        this.$toast.error({
          title: 'Link Sumber Data kosong',
          message: 'Isi link terlebih dahulu, lalu klik Simpan.',
        });
        return;
      }
      this.sumberSaving = true;
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/save_kpi_realisasi.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
              emp: this.empNumber,
              submit: false,
              rows: [
                {
                  setup_id: r.setup_id,
                  divisi: r.divisi,
                  cabang: r.cabang || '',
                  periode: r.periode,
                  realisasi: r.realisasi,
                  bukti: link,
                },
              ],
            }),
          },
        );
        const json = await res.json();
        if (json.status === 'success' && Number(json.saved) > 0) {
          r.bukti = link;
          this.sumberModal = null;
          await Swal.fire({
            icon: 'success',
            title: 'Berhasil Disimpan',
            html:
              'Link Sumber Data berhasil disimpan.' +
              `<br><span style="font-size:12px;color:#6b7280">${this.escapeHtml(
                link,
              )}</span>`,
            confirmButtonText: 'OK',
          });
        } else {
          // Server menolak (mis. bulan di luar jadwal) — simpan sementara di
          // tabel supaya tidak hilang, tapi beri tahu Pak bahwa belum masuk DB.
          r.bukti = link;
          this.sumberModal = null;
          this.$toast.warning({
            title: 'Belum tersimpan ke server',
            message:
              json.message ||
              'Link disimpan di tabel. Klik Simpan di bawah tabel untuk mencoba lagi.',
          });
        }
      } catch (e) {
        this.$toast.error({
          title: 'Gagal menyimpan',
          message: e.message,
        });
      } finally {
        this.sumberSaving = false;
      }
    },
    escapeHtml(v) {
      return String(v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
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
    // --- Export Spreadsheet (.csv, zero-dep) -----------------------------
    // Diexport = SEMUA grup hasil filter (bukan hanya halaman pagination
    // aktif), bentuknya mirror tabel: baris indikator + baris TOTAL BOBOT.
    // Baris judul/filter tidak ikut supaya kolom CSV langsung lurus (rapi saat
    // dibuka di Excel / Google Sheets dan gampang difilter).
    exportExcel() {
      const groups = this.yearGroups;
      if (!groups.length) return;
      const matrix = [EXPORT_COLUMNS.map((c) => c.title)];
      const monthCells = (y) =>
        Array.from({length: 12}, (_, i) => {
          const c = this.cellOf(y, i + 1);
          if (!c) return '';
          if (c.realisasi === null || c.realisasi === undefined) return '';
          const raw = String(c.realisasi).trim();
          if (raw === '') return '';
          const n = Number(raw.replace(',', '.'));
          return Number.isNaN(n) ? raw : n;
        });
      for (const g of groups) {
        for (const y of g.rows) {
          const skor = this.yScore(y);
          matrix.push([
            y.indikator,
            this.divisiName(y.divisi),
            y.cabang || 'HO',
            Number(this.yTarget(y)) || 0,
            y.satuan || '',
            Number(this.yBobot(y)) || 0,
            this.applied.year,
            this.ySchedule(y) || '',
            ...monthCells(y),
            skor === null ? '' : Math.round(skor * 100) / 100,
            this.yAvgAch(y) === null
              ? ''
              : Math.round(this.yAvgAch(y) * 100) / 100,
            this.yStatusLabel(y),
          ]);
        }
        const gskor = this.yGroupScore(g);
        matrix.push([
          'TOTAL',
          this.divisiName(g.divisi),
          '',
          '',
          '',
          Math.round(this.yGroupBobot(g) * 100) / 100,
          this.applied.year,
          '',
          ...Array.from({length: 12}, (_, i) => {
            const gv = this.yGroupMonthScore(g, i + 1);
            return gv === null ? '' : Math.round(gv * 100) / 100;
          }),
          gskor === null ? '' : Math.round(gskor * 100) / 100,
          this.yGroupAvgAch(g) === null
            ? ''
            : Math.round(this.yGroupAvgAch(g) * 100) / 100,
          this.yGroupStatus(g),
        ]);
      }
      downloadCsv(
        `Input_Realisasi_KPI_${this.applied.year}_${this.exportStamp(
          'file',
        )}.csv`,
        matrix,
      );
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
        {
          v: skor === null ? '' : Math.round(skor * 100) / 100,
          s: XLSX_STYLE.PCT,
        },
        {v: this.statusLabel(r), s: statusStyleForColor(statusColor(skor))},
        {v: r.bukti || '', s: XLSX_STYLE.CELL},
      ];
    },
    // Baris TOTAL BOBOT per grup + capaian berbobot & statusnya.
    exportTotalRow(g) {
      const skor = this.groupSkor(g);
      const grey = {v: '', s: XLSX_STYLE.TOTAL_LABEL};
      return [
        {v: 'TOTAL', s: XLSX_STYLE.TOTAL_LABEL},
        {...grey},
        {...grey},
        {...grey},
        {v: this.groupBobot(g), s: XLSX_STYLE.TOTAL_NUM},
        {...grey},
        {...grey},
        {...grey},
        {...grey},
        {
          v: skor === null ? '' : Math.round(skor * 100) / 100,
          s: XLSX_STYLE.TOTAL_PCT,
        },
        {v: this.groupStatus(g), s: statusStyleForColor(statusColor(skor))},
        {...grey},
      ];
    },
    async fetchData() {
      this.isLoading = true;
      try {
        const params = new URLSearchParams({
          emp: String(this.empNumber),
          year: String(this.applied.year || new Date().getFullYear()),
        });
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
          // Simpan nilai asli per baris supaya saat Simpan hanya sel yang
          // benar-benar diubah yang dikirim (menghindari ratusan baris bulan
          // lain/ masa depan ikut terkirim dan "dilewati" server).
          this.rows = (d.rows || []).map((r) => ({
            ...r,
            // Nilai Achievement ditampilkan dengan 2 angka di belakang koma
            // (permintaan Pak 2026-11-19), mis. 7.4 -> 7.40. Nilai asli server
            // tetap disimpan di _origRealisasi untuk deteksi perubahan.
            realisasi: this.fmtAch(r.realisasi),
            _origRealisasi: r.realisasi,
            _origBukti: r.bukti,
          }));
        } else {
          console.error('Server:', json.message);
        }
      } catch (e) {
        console.error('Gagal memuat KPI:', e);
      } finally {
        this.isLoading = false;
      }
    },
    // Baris dianggap berubah bila nilai Achievement atau link bukti berbeda
    // dari data terakhir yang dimuat dari server.
    isRowDirty(r) {
      const txt = (v) =>
        v === null || v === undefined ? '' : String(v).trim();
      if (txt(r.bukti) !== txt(r._origBukti)) return true;
      const num = (v) => {
        if (v === null || v === undefined || String(v).trim() === '')
          return null;
        const n = Number(String(v).replace(',', '.'));
        return Number.isNaN(n) ? String(v).trim() : n;
      };
      const a = num(r.realisasi);
      const b = num(r._origRealisasi);
      if (a === null && b === null) return false;
      return a !== b;
    },
    async save(flag) {
      // Sejak tabel per tahun (2026-11-17):
      // - Simpan draft -> kirim semua sel tahun aktif yang belum diajukan.
      // - Ajukan       -> semua sel terisi pada tahun aktif. Dropdown "Bulan
      //                   Pengajuan" dihapus atas permintaan Pak; approval tetap
      //                   per bulan karena tiap baris membawa periode sendiri
      //                   dan server memvalidasi jendela input per periode.
      const year = String(this.applied.year || '');
      const source = this.rows.filter(
        (r) => !year || String(r.periode).slice(0, 4) === year,
      );
      const payload = {
        emp: this.empNumber,
        submit: flag,
        rows: source
          .filter((r) => !r.submitted)
          .filter((r) =>
            flag
              ? r.realisasi !== null &&
                r.realisasi !== undefined &&
                r.realisasi !== ''
              : this.isRowDirty(r),
          )
          .map((r) => ({
            setup_id: r.setup_id,
            divisi: r.divisi,
            cabang: r.cabang || '',
            periode: r.periode,
            realisasi: r.realisasi,
            bukti: r.bukti,
          })),
      };
      if (flag && !payload.rows.length) {
        this.$toast.error({
          title: 'Tidak ada nilai untuk diajukan',
          message: 'Isi minimal satu sel pada tahun aktif terlebih dahulu.',
        });
        return;
      }
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
              icon:
                failedEdits || Number(json.skippedJadwal || 0) > 0
                  ? 'warning'
                  : 'success',
              title: 'Berhasil Diajukan',
              text:
                json.message +
                (failedEdits ? ` — ${failedEdits} edit tidak terajukan.` : ''),
              confirmButtonText: 'OK',
            });
            await this.fetchData();
          } else {
            // Jangan tampilkan "sukses" polos kalau ada baris yang dilewati
            // server (mis. periode masa depan) — nilainya tidak tersimpan.
            await Swal.fire({
              icon: Number(json.skippedJadwal || 0) > 0 ? 'warning' : 'success',
              title:
                Number(json.skippedJadwal || 0) > 0
                  ? 'Sebagian Belum Tersimpan'
                  : 'Save Successfully',
              text: json.message || 'Draft disimpan.',
              confirmButtonText: 'OK',
            });
            // Tandai nilai terkirim sebagai baseline baru.
            this.rows.forEach((r) => {
              r._origRealisasi = r.realisasi;
              r._origBukti = r.bukti;
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
      // Snapshot filter -> tampilan. Year dipakai juga untuk memuat 12 bulan
      // tahun itu dari server (biar kolom Jan..Des selalu lengkap).
      const tahun = this.filters.year?.id
        ? String(this.filters.year.id)
        : String(new Date().getFullYear());
      this.applied = {
        division: this.filters.division || null,
        cabang: this.filters.cabang || null,
        year: tahun,
      };
      this.fetchData();
    },
    onReset() {
      const semua = {id: 0, label: 'Semua'};
      const tahun = String(new Date().getFullYear());
      this.filters.division = semua;
      this.filters.cabang = semua;
      this.filters.year = {id: tahun, label: tahun};
      this.applied = {
        division: semua,
        cabang: semua,
        year: tahun,
      };
      this.fetchData();
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
        rumus: this.rumusOptions[0] || null,
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
      const rumus =
        f.rumus && typeof f.rumus === 'object' ? f.rumus : this.rumusOptions[0];
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
      // Validasi total bobot divisi × periode (maksimal 100%).
      // OXD hanya mengenal $toast.error({title, message}); kalau dikirim string,
      // toast tampil kosong (judul & pesan undefined).
      if (this.addBobotError) {
        this.$toast.error({
          title: 'Bobot melebihi batas',
          message: this.addBobotError,
        });
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
        // Opsi "(Target / Pencapaian)" = indikator makin rendah makin baik
        // -> lower_better 1 (dipakai server & semua halaman utk hitung Skor).
        lower_better: rumus.id === 'target_ach' ? 1 : 0,
        rumus: rumus.label,
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
/* Teks merah validasi total bobot (modal Add Indikator) */
.ohrm-bobot-error {
  margin: -0.25rem 0 0.5rem;
  color: #eb0910;
  font-size: 12px;
  line-height: 1.5;
}

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
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  margin-top: 0.75rem;
}

.orangehrm-pagination-label {
  font-size: 13px;
  font-weight: 700;
  color: #64748b;
  min-width: 180px;
  text-align: center;
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
  margin-left: 0.6rem;
}
// Kolom Aksi: ikon pencil + history berjajar ke samping (tidak turun ke bawah),
// diberi jarak supaya tidak berdesakan, dan sel-nya menempel di kanan tabel
// (lihat `.orangehrm-kpi-year` -> th/td:last-child) agar tetap terlihat saat
// tabel 12 bulan digeser horizontal.
.orangehrm-aksi-cell {
  white-space: nowrap;
  text-align: center;
  padding: 4px 10px !important;

  > * {
    vertical-align: middle;
  }
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
// Checkbox "pilih semua" di header: sedikit lebih kecil + warna abu netral
// supaya tidak terbaca sebagai baris yang ikut terhapus.
.orangehrm-delete-head input[type='checkbox'] {
  width: 15px;
  height: 15px;
  accent-color: #64728c;
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
  // ditebalkan (permintaan Pak 2026-11-18): 600 -> 700
  font-weight: 700;
  background: #f1f5f9;
  color: #475569;
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

// ==== Tabel Input Realisasi KPI per TAHUN (2026-11-17) ====
// 12 kolom bulan + kolom tetap; tabel dilebarkan dan bisa digeser horizontal
// (pembungkus .orangehrm-table-wrap sudah overflow-x: auto).
.orangehrm-kpi-year {
  min-width: 1480px;
  // Nama kolom dibuat tebal (permintaan Pak, 2026-11-17) + warna lebih gelap
  // supaya terbaca jelas di atas 12 kolom bulan.
  thead th {
    font-weight: 700;
    color: #4b5563;
  }
  // Kolom Indikator menempel di kiri saat tabel digeser horizontal (12 bulan),
  // supaya nama indikator tetap terlihat.
  th:nth-child(2),
  td:nth-child(2) {
    position: sticky;
    left: 0;
    z-index: 2;
    background: #fff;
    box-shadow: 1px 0 0 #e4e6ea;
  }
  thead th:nth-child(2) {
    z-index: 3;
  }
  .orangehrm-total-row td:nth-child(2) {
    background: #f3f4f6;
  }
  // Kolom Aksi menempel di KANAN: ikon tetap terlihat tanpa harus menggeser
  // tabel ke ujung, dan tidak berdesakan lagi.
  th:last-child,
  td:last-child {
    position: sticky;
    right: 0;
    z-index: 2;
    background: #fff;
    box-shadow: -1px 0 0 #e4e6ea;
  }
  th:last-child {
    z-index: 3;
    min-width: 86px;
  }
  .orangehrm-total-row td:last-child {
    background: #f3f4f6;
  }
}
// Tombol bawah tabel (Simpan & Ajukan Verifikasi) dibuat KUNING pekat
// (permintaan Pak 2026-11-18). OXD tidak punya varian kuning, jadi di-override
// di scoped style komponen — kode OXD tidak diubah.
.orangehrm-btn-kuning {
  background-color: #ffc107 !important;
  border-color: #ffc107 !important;
  color: #202020 !important;
  font-weight: 600;

  &:hover:not(:disabled),
  &:focus:not(:disabled) {
    background-color: #e8ae00 !important;
    border-color: #e8ae00 !important;
    color: #202020 !important;
  }

  &:active:not(:disabled) {
    background-color: #cf9b00 !important;
    border-color: #cf9b00 !important;
  }

  &:disabled {
    background-color: #ffe6a3 !important;
    border-color: #ffe6a3 !important;
    color: #8a7a3d !important;
    cursor: not-allowed;
  }
}
.orangehrm-month-head {
  text-align: center !important;
  white-space: nowrap;
}
.orangehrm-month-cell {
  padding: 3px 4px !important;
  position: relative;
  text-align: center;
  white-space: nowrap;
  .orangehrm-real {
    // ikon klip sekarang 15px & bold: kotaknya melebar ~23px, jadi input
    // dilebarkan + padding kanan ditambah supaya angka tidak tertimpa ikon.
    width: 70px;
    padding-right: 26px;
    text-align: right;
  }
}
.orangehrm-month-empty {
  color: #b7bcc4;
}
// Ikon klip (Sumber Data) ditebalkan (permintaan Pak 2026-11-18):
// emoji tidak punya ketebalan font, jadi ukuran dinaikkan + digambar ulang
// dengan stroke tipis (text-stroke/shadow) supaya terlihat bold dan jelas.
.orangehrm-sumber-btn {
  position: absolute;
  right: 2px;
  top: 50%;
  transform: translateY(-50%);
  border: 0;
  background: transparent;
  padding: 0 2px;
  // Ikon vektor (bukan emoji) supaya bisa dicetak tebal: emoji mengabaikan
  // color/font-weight, ikon font mengikuti keduanya.
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
  color: #1d4ed8;
  opacity: 1;
  -webkit-text-stroke: 0.45px currentColor;
  &:hover {
    color: #1e40af;
    -webkit-text-stroke: 0.6px currentColor;
  }
}
.orangehrm-sumber-filled {
  opacity: 1;
  color: #1d4ed8;
}
.orangehrm-sumber-hint {
  font-size: 12px;
  color: #6b7280;
  margin-right: 12px;
}
.orangehrm-sumber-info {
  margin: 0.4rem 0 0.6rem;
  border: 1px solid #e4e6ea;
  border-radius: 6px;
  overflow: hidden;
}
.orangehrm-sumber-row {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 6px 10px;
  font-size: 12.5px;
  border-bottom: 1px solid #f0f1f3;
  span {
    color: #6b7280;
    white-space: nowrap;
  }
  b {
    color: #303030;
    text-align: right;
    word-break: break-word;
  }
  &:last-child {
    border-bottom: 0;
  }
}
.orangehrm-sumber-kpi {
  background: #f7f8fa;
  b {
    font-size: 14px;
    color: #2463eb;
  }
}
.orangehrm-sumber-kpi-max {
  b {
    color: #12a150;
  }
}
.orangehrm-sumber-note {
  margin: 0 0 0.4rem;
  font-size: 11.5px;
  color: #8b95a5;
  line-height: 1.45;
}
.orangehrm-submit-month {
  display: inline-block;
  min-width: 200px;
  margin-right: 12px;
  vertical-align: bottom;
}
</style>
