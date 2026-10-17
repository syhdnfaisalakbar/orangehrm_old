<!--
/**
 * OrangeHRM — Approval KPI (PMS Antar-Departemen)
 *
 * Halaman persetujuan realisasi KPI yang sudah diajukan (submitted=1).
 * Data dari web/proxy/get_approval.php & update_approval.php.
 *
 * Alur: User Input Realisasi -> Simpan Draft (belum terlihat) -> Ajukan
 * Verifikasi & Approve (submitted=1, approval_status='Menunggu') -> baris
 * muncul di halaman ini -> Admin Approve (Disetujui) / Reject (Ditolak +
 * alasan).
 *
 * Approval/Reject PER BARIS (setup_id+periode). Tombol Approve/Reject HANYA
 * aktif untuk baris berstatus 'Menunggu'; status lain tampil read-only agar
 * riwayat disetujui/ditolak tetap terlihat.
 */
-->
<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-card-container">
      <oxd-text class="orangehrm-main-title" tag="h6">Approval KPI</oxd-text>
      <oxd-text
        v-if="!isAdmin"
        type="subtitle-2"
        class="orangehrm-readonly-note"
      >
        Anda hanya dapat melihat riwayat pengajuan (read-only). Aksi
        setujui/tolak hanya untuk Admin.
      </oxd-text>
      <oxd-divider />

      <!-- Tab: Realisasi | Edit Indikator -->
      <div class="orangehrm-tabs">
        <button
          type="button"
          disabled
          class="orangehrm-tab is-disabled"
          :class="{'is-active': activeTab === 'realisasi'}"
          @click="switchTab('realisasi')"
        >
          Realisasi
        </button>
        <button
          type="button"
          class="orangehrm-tab"
          :class="{'is-active': activeTab === 'edit'}"
          @click="switchTab('edit')"
        >
          Edit Indikator
        </button>
      </div>
      <br />

      <template v-if="activeTab === 'realisasi'">
        <!-- Filter (frontend-only) -->
        <oxd-table-filter :filter-title="'Filter'">
          <oxd-form @submit-valid="onSearch" @reset="onReset">
            <oxd-form-row>
              <oxd-grid
                :cols="isAdmin ? 4 : 3"
                class="orangehrm-full-width-grid"
              >
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
                    v-model="filters.indikator"
                    :label="'Indikator'"
                    :placeholder="'Cari indikator…'"
                    autocomplete="off"
                  />
                </oxd-grid-item>
                <oxd-grid-item>
                  <oxd-input-field
                    v-model="filters.approval"
                    type="select"
                    :label="'Status Approval'"
                    :options="approvalOptions"
                  />
                </oxd-grid-item>
                <oxd-grid-item>
                  <oxd-input-field
                    v-model="filters.periode"
                    type="select"
                    :label="'Periode'"
                    :options="periodeOptions"
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
            </oxd-form-actions>
          </oxd-form>
        </oxd-table-filter>
        <br />

        <div v-if="loading" class="orangehrm-loading">
          <oxd-text type="subtitle-2">Memuat data...</oxd-text>
        </div>

        <div v-else-if="!filteredRows.length" class="orangehrm-empty">
          <oxd-text type="subtitle-2">Tidak ada data approval.</oxd-text>
        </div>

        <div v-else class="orangehrm-approval-table-wrap">
          <oxd-text type="subtitle-2" class="orangehrm-count">
            {{ filteredRows.length }} baris menunggu persetujuan
          </oxd-text>

          <div
            v-if="isAdmin && selectedIds.length"
            class="orangehrm-bulk-actions"
          >
            <oxd-text type="subtitle-2" class="orangehrm-bulk-info"
              >{{ selectedIds.length }} dipilih</oxd-text
            >
            <oxd-button
              display-type="success"
              label="Approve Terpilih"
              type="button"
              @click="approveSelected"
            />
            <oxd-button
              class="orangehrm-left-space"
              display-type="danger"
              label="Reject Terpilih"
              type="button"
              @click="rejectSelected"
            />
          </div>

          <table class="orangehrm-approval-table">
            <thead>
              <tr>
                <th style="width: 4%" class="orangehrm-check-cell">
                  <input
                    type="checkbox"
                    class="oxd-input"
                    :checked="isAllPendingChecked"
                    :disabled="!pendingRowsPage.length"
                    @change="toggleSelectAll"
                  />
                </th>
                <th style="width: 22%">Indikator</th>
                <th style="width: 9%">Divisi</th>
                <th style="width: 7%">Cabang</th>
                <th style="width: 7%">Periode</th>
                <th style="width: 8%">Achievement</th>
                <th style="width: 7%">Skor</th>
                <th style="width: 10%">Penginput</th>
                <th style="width: 10%">Status</th>
                <th style="width: 8%">Sumber Data</th>
                <th style="width: 8%">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in paginatedRows" :key="r.id">
                <td class="orangehrm-check-cell">
                  <input
                    v-if="r.approval_status === 'Menunggu'"
                    type="checkbox"
                    class="oxd-input"
                    :checked="selectedIds.includes(r.id)"
                    @change="toggleSelect(r.id)"
                  />
                </td>
                <td class="orangehrm-indikator">{{ r.indikator }}</td>
                <td>
                  <span class="orangehrm-divisi-badge">{{ r.divisi }}</span>
                </td>
                <td>{{ r.cabang || 'HO' }}</td>
                <td>{{ r.periode }}</td>
                <td class="orangehrm-num">{{ r.realisasi ?? '—' }}</td>
                <td class="orangehrm-num" :class="scoreClass(r)">
                  <span>{{ r.skor ?? '—' }}</span>
                </td>
                <td>{{ r.inputer_name || '—' }}</td>
                <td>
                  <span
                    class="orangehrm-status-badge"
                    :class="approvalClass(r.approval_status)"
                  >
                    {{ r.approval_status || 'Belum diajukan' }}
                  </span>
                </td>
                <td>
                  <a
                    v-if="r.bukti"
                    :href="r.bukti"
                    target="_blank"
                    rel="noopener"
                    class="orangehrm-link"
                  >
                    Buka
                  </a>
                  <span v-else>—</span>
                </td>
                <td>
                  <oxd-icon-button
                    :with-container="false"
                    name="clock"
                    :title="'Riwayat Approval'"
                    class="orangehrm-btn-history"
                    @click="openHistory(r)"
                  />
                  <template v-if="isAdmin && r.approval_status === 'Menunggu'">
                    <oxd-icon-button
                      :with-container="false"
                      name="checkmark"
                      :title="'Approve'"
                      class="orangehrm-btn-approve"
                      @click="approve(r)"
                    />
                    <oxd-icon-button
                      :with-container="false"
                      name="close"
                      :title="'Reject'"
                      class="orangehrm-btn-reject"
                      @click="reject(r)"
                    />
                  </template>
                  <oxd-text v-else type="subtitle-2" class="orangehrm-done">
                    <template v-if="r.approval_status === 'Disetujui'"
                      >✓ Disetujui</template
                    >
                    <template v-else-if="r.approval_status === 'Ditolak'">
                      ✕ Ditolak
                      <div v-if="r.reject_reason" class="orangehrm-reason">
                        {{ r.reject_reason }}
                      </div>
                    </template>
                    <template v-else-if="r.approval_status === 'Menunggu'"
                      >Menunggu</template
                    >
                  </oxd-text>
                </td>
              </tr>
            </tbody>
          </table>

          <div
            v-if="realisasiPages > 1"
            class="orangehrm-bottom-container orangehrm-pagination"
          >
            <oxd-pagination
              v-model:current="pageRealisasi"
              :length="realisasiPages"
            />
          </div>
        </div>
      </template>

      <template v-else>
        <div class="orangehrm-edit-wrap">
          <!-- Filter Search indikator (frontend-only) -->
          <oxd-table-filter :filter-title="'Filter'">
            <oxd-form @submit-valid="onEditSearch" @reset="onEditReset">
              <oxd-form-row>
                <oxd-grid :cols="2" class="orangehrm-full-width-grid">
                  <oxd-grid-item>
                    <oxd-input-field
                      v-model="editFilters.indikator"
                      :label="'Indikator'"
                      :placeholder="'Cari indikator…'"
                      autocomplete="off"
                    />
                  </oxd-grid-item>
                  <oxd-grid-item>
                    <oxd-input-field
                      v-model="editFilters.status"
                      type="select"
                      :label="'Status'"
                      :options="editApprovalOptions"
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
              </oxd-form-actions>
            </oxd-form>
          </oxd-table-filter>
          <br />

          <oxd-text type="subtitle-2" class="orangehrm-count">
            {{ filteredEditRows.length }} pengajuan edit definisi —
            {{ editPendingCount }}
            menunggu
          </oxd-text>

          <div v-if="editLoading" class="orangehrm-loading">
            <oxd-text type="subtitle-2">Memuat data pengajuan...</oxd-text>
          </div>
          <div v-else-if="!filteredEditRows.length" class="orangehrm-empty">
            <oxd-text type="subtitle-2"
              >Tidak ada pengajuan edit definisi.</oxd-text
            >
          </div>

          <table v-else class="orangehrm-approval-table">
            <thead>
              <tr>
                <th style="width: 20%">Indikator</th>
                <th style="width: 10%">Divisi</th>
                <th style="width: 26%">Perubahan (Lama → Baru)</th>
                <th style="width: 11%">Pengaju</th>
                <th style="width: 12%">Tanggal Pengajuan</th>
                <th style="width: 10%">Status</th>
                <th style="width: 18%">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="er in paginatedEditRows" :key="er.id">
                <td class="orangehrm-indikator">{{ er.indikator }}</td>
                <td>
                  <span class="orangehrm-divisi-badge">{{ er.divisi }}</span>
                </td>
                <td class="orangehrm-change">
                  <div>Target: {{ er.target_old }} → {{ er.target }}</div>
                  <div>Bobot: {{ er.bobot_old }}% → {{ er.bobot }}%</div>
                  <div>Schedule: {{ er.schedule_old }} → {{ er.schedule }}</div>
                </td>
                <td>{{ er.requester_name || '—' }}</td>
                <td>{{ fmtDateTime(er.requested_at) }}</td>
                <td>
                  <span
                    class="orangehrm-status-badge"
                    :class="approvalClass(er.request_status)"
                  >
                    {{ er.request_status || '—' }}
                  </span>
                </td>
                <td>
                  <template v-if="isAdmin && er.request_status === 'Menunggu'">
                    <div class="orangehrm-edit-actions">
                      <oxd-button
                        display-type="success"
                        label="Approve"
                        type="button"
                        @click="decideEdit(er, 'approve')"
                      />
                      <oxd-button
                        class="orangehrm-left-space"
                        display-type="danger"
                        label="Reject"
                        type="button"
                        @click="decideEdit(er, 'reject')"
                      />
                    </div>
                  </template>
                  <span v-else class="orangehrm-done">
                    <template v-if="er.request_status === 'Disetujui'"
                      >✓ Disetujui</template
                    >
                    <template v-else-if="er.request_status === 'Ditolak'"
                      >✕ Ditolak
                      <div v-if="er.reject_reason" class="orangehrm-reason">
                        {{ er.reject_reason }}
                      </div></template
                    >
                    <template v-else>Menunggu verifikasi</template>
                  </span>
                </td>
              </tr>
            </tbody>
          </table>

          <div
            v-if="editPages > 1"
            class="orangehrm-bottom-container orangehrm-pagination"
          >
            <oxd-pagination v-model:current="pageEdit" :length="editPages" />
          </div>
        </div>
      </template>
    </div>

    <!-- Modal Riwayat Approval (audit) -->
    <oxd-dialog
      v-if="showHistory"
      :style="{width: '80%', maxWidth: '640px'}"
      @update:show="showHistory = false"
    >
      <div class="orangehrm-modal-header">
        <oxd-text type="card-title">Riwayat Approval</oxd-text>
      </div>
      <oxd-divider />
      <oxd-text
        v-if="historyRow"
        type="subtitle-2"
        class="orangehrm-history-sub"
      >
        {{ historyRow.indikator }} — {{ historyRow.divisi }} /
        {{ historyRow.periode }}
      </oxd-text>
      <div v-if="historyLoading" class="orangehrm-loading">
        <oxd-text type="subtitle-2">Memuat riwayat...</oxd-text>
      </div>
      <div v-else-if="!historyEntries.length" class="orangehrm-empty">
        <oxd-text type="subtitle-2"
          >Belum ada riwayat approval untuk indikator ini.</oxd-text
        >
      </div>
      <div v-else class="orangehrm-timeline">
        <div
          v-for="en in historyEntries"
          :key="en.id"
          class="orangehrm-timeline-item"
        >
          <div
            class="orangehrm-timeline-mark"
            :class="timelineActionClass(en.action)"
          ></div>
          <div class="orangehrm-timeline-body">
            <div class="orangehrm-timeline-head">
              <span
                class="orangehrm-timeline-action"
                :class="timelineActionClass(en.action)"
              >
                {{ timelineActionLabel(en.action) }}
              </span>
              <span class="orangehrm-timeline-date">{{
                fmtDateTime(en.acted_at)
              }}</span>
            </div>
            <div class="orangehrm-timeline-detail">
              <span v-if="en.realisasi !== null"
                >Achievement: <b>{{ en.realisasi }}</b></span
              >
              <span v-if="en.skor !== null">
                · Skor: <b>{{ en.skor }}</b></span
              >
              <span v-if="en.actor_name">
                · Oleh: <b>{{ en.actor_name }}</b></span
              >
            </div>
            <div v-if="en.note" class="orangehrm-timeline-note">
              {{ en.note }}
            </div>
          </div>
        </div>
      </div>
      <oxd-divider />
      <oxd-form-actions class="orangehrm-form-action">
        <oxd-button
          display-type="ghost"
          label="Tutup"
          type="button"
          @click="showHistory = false"
        />
      </oxd-form-actions>
    </oxd-dialog>
  </div>
</template>

<script>
import Swal from 'sweetalert2';
import {OxdDialog} from '@ohrm/oxd';

const APPROVAL_STATUS = [
  {id: 0, label: 'Semua'},
  {id: 1, label: 'Menunggu'},
  {id: 2, label: 'Disetujui'},
  {id: 3, label: 'Ditolak'},
];

// Jumlah baris per halaman tabel Approval (Realisasi & Edit Indikator).
const PAGE_SIZE = 10;

export default {
  name: 'ApprovalKpi',
  components: {
    'oxd-dialog': OxdDialog,
  },
  props: {
    empNumber: {type: Number, default: 0},
  },
  data() {
    return {
      loading: true,
      isAdmin: false,
      currentDivision: '',
      divisions: [],
      rows: [],
      selectedIds: [],
      showHistory: false,
      historyRow: null,
      historyLoading: false,
      historyEntries: [],
      activeTab: 'edit',
      editRows: [],
      editLoading: false,
      // Halaman aktif tabel (pagination per-baris).
      pageRealisasi: 1,
      pageEdit: 1,
      filters: {
        division: {id: 0, label: 'Semua'},
        approval: {id: 0, label: 'Semua'},
        periode: {id: 0, label: 'Semua'},
        indikator: '',
      },
      applied: {
        division: {id: 0, label: 'Semua'},
        approval: {id: 0, label: 'Semua'},
        periode: {id: 0, label: 'Semua'},
        indikator: '',
      },
      // Filter Search tab Edit Indikator (frontend-only).
      editFilters: {
        indikator: '',
        status: {id: 0, label: 'Semua'},
      },
      editApplied: {
        indikator: '',
        status: {id: 0, label: 'Semua'},
      },
    };
  },
  computed: {
    divisionOptions() {
      const list = this.divisions.length ? this.divisions : this.divisions;
      return list.map((d, i) => ({id: i + 1, label: d}));
    },
    filterDivisionOptions() {
      return [{id: 0, label: 'Semua'}, ...this.divisionOptions];
    },
    approvalOptions() {
      return APPROVAL_STATUS;
    },
    periodeOptions() {
      const set = new Set();
      for (const r of this.rows) set.add(r.periode);
      const list = Array.from(set).sort((a, b) => (a < b ? 1 : -1));
      return [
        {id: 0, label: 'Semua'},
        ...list.map((p, i) => ({id: i + 1, label: p})),
      ];
    },
    filteredRows() {
      const div =
        this.applied.division?.label !== 'Semua'
          ? this.applied.division.label
          : null;
      const appr =
        this.applied.approval?.label !== 'Semua'
          ? this.applied.approval.label
          : null;
      const per =
        this.applied.periode?.label !== 'Semua'
          ? this.applied.periode.label
          : null;
      const ind = String(this.applied.indikator || '')
        .trim()
        .toLowerCase();
      return this.rows.filter((r) => {
        if (div && r.divisi !== div) return false;
        if (appr && (r.approval_status || '') !== appr) return false;
        if (per && r.periode !== per) return false;
        if (
          ind &&
          !String(r.indikator || '')
            .toLowerCase()
            .includes(ind)
        )
          return false;
        return true;
      });
    },
    pendingRows() {
      return this.filteredRows.filter((r) => r.approval_status === 'Menunggu');
    },
    realisasiPages() {
      return Math.ceil(this.filteredRows.length / PAGE_SIZE);
    },
    paginatedRows() {
      const start = (this.pageRealisasi - 1) * PAGE_SIZE;
      return this.filteredRows.slice(start, start + PAGE_SIZE);
    },
    // Baris pending pada HALAMAN aktif (utk checkbox "pilih semua" per halaman).
    pendingRowsPage() {
      return this.paginatedRows.filter((r) => r.approval_status === 'Menunggu');
    },
    // Opsi Status untuk filter tab Edit Indikator (request_status).
    editApprovalOptions() {
      return [
        {id: 0, label: 'Semua'},
        {id: 1, label: 'Menunggu'},
        {id: 2, label: 'Disetujui'},
        {id: 3, label: 'Ditolak'},
      ];
    },
    // Baris pengajuan edit setelah filter Search indikator + status (frontend-only).
    filteredEditRows() {
      const ind = String(this.editApplied.indikator || '')
        .trim()
        .toLowerCase();
      const st =
        this.editApplied.status && this.editApplied.status.label !== 'Semua'
          ? this.editApplied.status.label
          : null;
      if (!ind && !st) return this.editRows;
      return this.editRows.filter((r) => {
        if (
          ind &&
          !String(r.indikator || '')
            .toLowerCase()
            .includes(ind)
        )
          return false;
        if (st && (r.request_status || '') !== st) return false;
        return true;
      });
    },
    editPendingCount() {
      return this.filteredEditRows.filter(
        (r) => r.request_status === 'Menunggu',
      ).length;
    },
    editPages() {
      return Math.ceil(this.filteredEditRows.length / PAGE_SIZE);
    },
    paginatedEditRows() {
      const start = (this.pageEdit - 1) * PAGE_SIZE;
      return this.filteredEditRows.slice(start, start + PAGE_SIZE);
    },
    isAllPendingChecked() {
      return (
        this.pendingRowsPage.length > 0 &&
        this.pendingRowsPage.every((r) => this.selectedIds.includes(r.id))
      );
    },
  },
  mounted() {
    this.fetchData();
    this.fetchEditRequests(); // default tab = Edit Indikator, muat data sejak awal
  },
  watch: {
    // Clamp halaman aktif supaya tidak melebihi jumlah halaman setelah data
    // berubah (filter/fetch/approve) atau halaman terakhir jadi kosong.
    filteredRows() {
      if (this.pageRealisasi > this.realisasiPages) {
        this.pageRealisasi = Math.max(1, this.realisasiPages);
      }
    },
    filteredEditRows() {
      if (this.pageEdit > this.editPages) {
        this.pageEdit = Math.max(1, this.editPages);
      }
    },
  },
  methods: {
    getBaseUrl() {
      const base = window.appGlobal?.baseUrl || '';
      return base.replace('/index.php', '') || '';
    },
    async fetchData() {
      this.loading = true;
      try {
        const params = new URLSearchParams({emp: String(this.empNumber)});
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/get_approval.php?${params}`,
        );
        const json = await res.json();
        if (json.status === 'success') {
          const d = json.data;
          this.isAdmin = d.is_admin;
          this.currentDivision = d.current_division;
          this.divisions = d.divisions || [];
          this.rows = d.rows || [];
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
          text: 'Tidak dapat memuat data approval.',
          confirmButtonText: 'OK',
        });
      } finally {
        this.loading = false;
      }
    },
    async updateDecision(ids, decision, reason) {
      const idList = Array.isArray(ids) ? ids : [ids];
      if (!idList.length) return false;
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/update_approval.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
              emp: this.empNumber,
              ids: idList,
              decision,
              reason: reason || '',
            }),
          },
        );
        const json = await res.json();
        if (json.status === 'success') {
          await Swal.fire({
            icon: 'success',
            title: decision === 'approve' ? 'Approved' : 'Rejected',
            text: json.message,
            confirmButtonText: 'OK',
          });
          this.selectedIds = [];
          await this.fetchData();
          return true;
        }
        await Swal.fire({
          icon: 'error',
          title: 'Gagal',
          text: json.message,
          confirmButtonText: 'OK',
        });
        return false;
      } catch (e) {
        await Swal.fire({
          icon: 'error',
          title: 'Gagal',
          text: 'Terjadi kesalahan.',
          confirmButtonText: 'OK',
        });
        return false;
      }
    },
    approve(r) {
      Swal.fire({
        title: r ? 'Approve Indikator?' : 'Approve Terpilih?',
        text: r
          ? `${r.indikator} (${r.divisi} — ${r.periode})`
          : `${this.selectedIds.length} indikator terpilih akan disetujui.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, Approve',
        cancelButtonText: 'Batal',
      }).then((result) => {
        if (result.isConfirmed)
          this.updateDecision(r ? [r.id] : this.selectedIds, 'approve');
      });
    },
    reject(r) {
      Swal.fire({
        title: r ? 'Reject Indikator' : 'Reject Terpilih',
        input: 'text',
        inputLabel: 'Alasan penolakan',
        inputPlaceholder: 'Isi alasan penolakan...',
        inputValidator: (value) => {
          if (!value || !value.trim()) return 'Alasan penolakan wajib diisi';
          return undefined;
        },
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Reject',
        cancelButtonText: 'Batal',
      }).then((result) => {
        if (result.isConfirmed)
          this.updateDecision(
            r ? [r.id] : this.selectedIds,
            'reject',
            result.value,
          );
      });
    },
    approveSelected() {
      if (!this.selectedIds.length) return;
      this.approve(null);
    },
    rejectSelected() {
      if (!this.selectedIds.length) return;
      this.reject(null);
    },
    toggleSelect(id) {
      const idx = this.selectedIds.indexOf(id);
      if (idx >= 0) this.selectedIds.splice(idx, 1);
      else this.selectedIds.push(id);
    },
    toggleSelectAll() {
      const pend = this.pendingRowsPage;
      if (this.isAllPendingChecked) {
        this.selectedIds = this.selectedIds.filter(
          (id) => !pend.some((r) => r.id === id),
        );
      } else {
        const cur = new Set(this.selectedIds);
        pend.forEach((r) => cur.add(r.id));
        this.selectedIds = Array.from(cur);
      }
    },
    async openHistory(r) {
      this.historyRow = r;
      this.historyEntries = [];
      this.historyLoading = true;
      this.showHistory = true;
      try {
        const params = new URLSearchParams({
          setup_id: String(r.setup_id),
          periode: r.periode || '',
        });
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/get_approval_history.php?${params}`,
        );
        const json = await res.json();
        if (json.status === 'success') this.historyEntries = json.rows || [];
        else
          await Swal.fire({
            icon: 'error',
            title: 'Gagal',
            text: json.message,
            confirmButtonText: 'OK',
          });
      } catch (e) {
        await Swal.fire({
          icon: 'error',
          title: 'Gagal',
          text: 'Tidak dapat memuat riwayat.',
          confirmButtonText: 'OK',
        });
      } finally {
        this.historyLoading = false;
      }
    },
    timelineActionLabel(action) {
      if (action === 'submitted') return 'Diajukan';
      if (action === 'approved') return 'Disetujui';
      if (action === 'rejected') return 'Ditolak';
      return action;
    },
    timelineActionClass(action) {
      if (action === 'submitted') return '--submit';
      if (action === 'approved') return '--approved';
      if (action === 'rejected') return '--rejected';
      return '';
    },
    fmtDateTime(dt) {
      if (!dt) return '';
      const d = new Date(dt);
      if (isNaN(d)) return dt;
      const pad = (n) => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(
        d.getDate(),
      )} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
    onSearch() {
      this.pageRealisasi = 1;
      this.applied = {
        division: this.filters.division || null,
        approval: this.filters.approval || null,
        periode: this.filters.periode || null,
        indikator: this.filters.indikator || '',
      };
    },
    onReset() {
      this.pageRealisasi = 1;
      const semua = {id: 0, label: 'Semua'};
      this.filters.division = semua;
      this.filters.approval = semua;
      this.filters.periode = semua;
      this.filters.indikator = '';
      this.applied = {
        division: semua,
        approval: semua,
        periode: semua,
        indikator: '',
      };
    },
    // Filter tab Edit Indikator (search nama indikator, frontend-only).
    onEditSearch() {
      this.pageEdit = 1;
      this.editApplied = {
        indikator: this.editFilters.indikator || '',
        status: this.editFilters.status || null,
      };
    },
    onEditReset() {
      this.pageEdit = 1;
      this.editFilters.indikator = '';
      this.editFilters.status = {id: 0, label: 'Semua'};
      this.editApplied = {
        indikator: '',
        status: {id: 0, label: 'Semua'},
      };
    },
    scoreClass(r) {
      if (r.skor === null || r.skor === undefined) return '';
      if (r.skor >= 100) return 'orangehrm-score-blue';
      if (r.skor >= 90) return 'orangehrm-score-green';
      if (r.skor >= 80) return 'orangehrm-score-yellow';
      return 'orangehrm-score-red';
    },
    approvalClass(s) {
      if (s === 'Menunggu') return '--pending';
      if (s === 'Disetujui') return '--approved';
      if (s === 'Ditolak') return '--rejected';
      return '';
    },
    switchTab(mode) {
      if (mode === 'realisasi') return; // tab Realisasi di-disable
      this.activeTab = mode;
      if (mode === 'edit') this.fetchEditRequests();
    },
    async fetchEditRequests() {
      this.editLoading = true;
      try {
        const params = new URLSearchParams({emp: String(this.empNumber)});
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/get_setup_edit_requests.php?${params}`,
        );
        const json = await res.json();
        if (json.status === 'success') this.editRows = json.data.rows || [];
        else
          await Swal.fire({
            icon: 'error',
            title: 'Gagal',
            text: json.message,
            confirmButtonText: 'OK',
          });
      } catch (e) {
        await Swal.fire({
          icon: 'error',
          title: 'Gagal',
          text: 'Tidak dapat memuat pengajuan edit.',
          confirmButtonText: 'OK',
        });
      } finally {
        this.editLoading = false;
      }
    },
    async decideEdit(er, decision) {
      const isApprove = decision === 'approve';
      const changeTxt = `Target ${er.target_old}→${er.target}, Bobot ${er.bobot_old}%→${er.bobot}%, Schedule ${er.schedule_old}→${er.schedule}`;
      const conf = await Swal.fire({
        title: isApprove
          ? 'Approve Perubahan Definisi?'
          : 'Reject Perubahan Definisi',
        text: isApprove ? `${er.indikator} — ${changeTxt}` : '',
        input: isApprove ? undefined : 'text',
        inputLabel: isApprove ? undefined : 'Alasan penolakan',
        inputPlaceholder: isApprove ? undefined : 'Isi alasan penolakan...',
        inputValidator: isApprove
          ? undefined
          : (value) => {
              if (!value || !value.trim())
                return 'Alasan penolakan wajib diisi';
              return undefined;
            },
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: isApprove ? 'Ya, Approve' : 'Reject',
        cancelButtonText: 'Batal',
      });
      if (!conf.isConfirmed) return;
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/update_setup_edit_request.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
              emp: this.empNumber,
              ids: [er.id],
              decision,
              reason: isApprove ? '' : conf.value || '',
            }),
          },
        );
        const json = await res.json();
        if (json.status === 'success') {
          await Swal.fire({
            icon: 'success',
            title: 'Sukses',
            text: json.message,
            confirmButtonText: 'OK',
          });
          await this.fetchEditRequests();
          // Refresh lonceng notifikasi segera (Layout.vue mendengarkan event ini),
          // biar notifikasi hasil Approve/Reject langsung muncul tanpa tunggu poll 60s.
          try {
            window.dispatchEvent(new CustomEvent('acumatica-sync-complete'));
          } catch (e) {
            // diabaikan: kegagalan refresh bell tidak boleh menggagalkan keputusan
          }
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
          text: 'Terjadi kesalahan.',
          confirmButtonText: 'OK',
        });
      }
    },
  },
};
</script>

<style lang="scss" scoped>
.orangehrm-readonly-note {
  display: block;
  margin-top: 4px;
  color: #929baa;
}
.orangehrm-approval-table-wrap {
  .orangehrm-count {
    margin-bottom: 0.75rem;
  }
}

/* Pagination tabel Approval — center & beri jarak dari tabel */
.orangehrm-pagination {
  display: flex;
  justify-content: center;
  margin-top: 0.9rem;
}
.orangehrm-tabs {
  display: flex;
  gap: 0.5rem;
}
.orangehrm-tab {
  border: 1px solid #e4e6ea;
  background: #fff;
  color: #64728c;
  padding: 0.45rem 1.1rem;
  border-radius: 6px;
  font-weight: 600;
  font-size: 13px;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
  &.is-active {
    background: #ff9f1a;
    border-color: #ff9f1a;
    color: #fff;
  }
  &.is-disabled,
  &[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
  }
}
.orangehrm-change {
  color: #303030;
  font-size: 12px;
  line-height: 1.55;
}
.orangehrm-edit-actions {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
}
.orangehrm-approval-table {
  width: 100%;
  border-collapse: collapse;
  font-family: var(--oxd-interface-font-family, 'Nunito Sans', sans-serif);
  th {
    text-align: left;
    color: #929baa;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid #e4e6ea;
    background: #fff;
  }
  td {
    padding: 0.75rem;
    color: #64728c;
    border-bottom: 1px solid #eceef0;
    font-size: 13px;
    vertical-align: middle;
  }
  tr:hover td {
    background: #f7f9fb;
  }
}
.orangehrm-indikator {
  color: #303030;
  font-weight: 600;
}
.orangehrm-check-cell {
  text-align: center !important;
  width: 4%;
  input[type='checkbox'] {
    width: 16px;
    height: 16px;
    accent-color: #ff9f1a;
    cursor: pointer;
    margin: 0;
  }
}
.orangehrm-bulk-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  .orangehrm-bulk-info {
    margin-right: 0.5rem;
    font-weight: 600;
  }
}
.orangehrm-num {
  font-variant-numeric: tabular-nums;
}
.orangehrm-divisi-badge {
  display: inline-block;
  padding: 3px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  background: #f1f5f9;
  color: #64748b;
  white-space: nowrap;
}
.orangehrm-status-badge {
  display: inline-block;
  padding: 3px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  &.--pending {
    background: #fef9c3;
    color: #a16207;
  }
  &.--approved {
    background: #dcfce7;
    color: #15803d;
  }
  &.--rejected {
    background: #fee2e2;
    color: #b91c1c;
  }
}
.orangehrm-link {
  color: #0056b3;
  text-decoration: underline;
  cursor: pointer;
}
.orangehrm-btn-approve {
  color: #15803d;
  & + & {
    margin-left: 0.4rem;
  }
}
.orangehrm-btn-reject {
  color: #b91c1c;
}
.orangehrm-done {
  color: #64748b;
  font-size: 12px;
}
.orangehrm-reason {
  color: #b91c1c;
  font-size: 11px;
  max-width: 180px;
  white-space: normal;
}
.orangehrm-score-blue {
  color: #1d4ed8;
  font-weight: 600;
}
.orangehrm-score-green {
  color: #15803d;
  font-weight: 600;
}
.orangehrm-score-yellow {
  color: #a16207;
  font-weight: 600;
}
.orangehrm-score-red {
  color: #b91c1c;
  font-weight: 600;
}
.orangehrm-btn-history {
  color: #475569;
}
.orangehrm-history-sub {
  margin-bottom: 0.75rem;
  color: #475569;
}
.orangehrm-timeline {
  padding: 0.25rem 0;
  .orangehrm-timeline-item {
    display: flex;
    gap: 0.75rem;
    padding: 0.4rem 0;
    position: relative;
  }
  .orangehrm-timeline-mark {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 5px;
    &.--submit {
      background: #a16207;
    }
    &.--approved {
      background: #15803d;
    }
    &.--rejected {
      background: #b91c1c;
    }
  }
  .orangehrm-timeline-body {
    flex: 1;
  }
  .orangehrm-timeline-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
  }
  .orangehrm-timeline-action {
    font-weight: 700;
    font-size: 13px;
    &.--submit {
      color: #a16207;
    }
    &.--approved {
      color: #15803d;
    }
    &.--rejected {
      color: #b91c1c;
    }
  }
  .orangehrm-timeline-date {
    font-size: 12px;
    color: #64748b;
  }
  .orangehrm-timeline-detail {
    font-size: 12px;
    color: #64748b;
  }
  .orangehrm-timeline-note {
    font-size: 12px;
    color: #b91c1c;
    font-style: italic;
    margin-top: 2px;
  }
}
.orangehrm-empty {
  padding: 1rem 0;
}
.orangehrm-loading {
  padding: 1rem 0;
}
</style>
