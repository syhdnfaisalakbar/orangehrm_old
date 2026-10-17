<!--
  OrangeHRM - Kpi Division Members (Pengelolaan Member KPI)
  Admin memetakan user -> (divisi, cabang) utk scope input KPI.
  2026-10-14: kolom PIC (is_pic) DIHAPUS dari tabel + form — tidak pernah
  dipakai sistem (scoping & approval hanya pakai divisi+cabang+aktif).
  Hanya role Admin yang akses (screen 195 + user_role_screen role 1 + guard
  isAdmin di frontend). Tabel memakai <table> HTML biasa (sama dgn
  ApprovalKpi/InputRealisasiKpi) — komponen oxd-table-cell tidak ter-register
  di plugin ini, pakai pola HTML yg sudah terbukti bekerja.
  Data: web/proxy/get_kpi_division_members.php, save_kpi_division_member.php,
        delete_kpi_division_member.php
-->
<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-card-container">
      <oxd-text class="orangehrm-main-title" tag="h6">
        Pengelolaan Member KPI
      </oxd-text>
      <oxd-text type="subtitle-2">
        Mapping user dengan divisi &amp; cabang untuk hak input realisasi KPI.
        Cabang kosong = semua cabang. Hanya Admin yang dapat mengelola.
      </oxd-text>
      <oxd-divider />

      <oxd-table-filter v-if="isAdmin" :filter-title="'Filter'">
        <oxd-form @submit-valid="onSearch" @reset="onReset">
          <oxd-form-row>
            <oxd-grid :cols="2" class="orangehrm-full-width-grid">
              <oxd-grid-item>
                <oxd-input-field
                  v-model="filters.karyawan"
                  :label="'Karyawan'"
                  :placeholder="'Cari nama atau NIK…'"
                  autocomplete="off"
                />
              </oxd-grid-item>
              <oxd-grid-item>
                <oxd-input-field
                  v-model="filters.divisi"
                  type="select"
                  :label="'Divisi'"
                  :options="filterDivisionOptions"
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

      <div v-if="!isAdmin" class="orangehrm-message">
        Anda tidak berhak mengelola member KPI.
      </div>

      <div v-else class="orangehrm-header-container">
        <oxd-button
          v-if="selectedIds.length"
          display-type="danger"
          icon-name="trash"
          :label="`Delete (${selectedIds.length})`"
          @click="confirmDeleteSelected"
        />
        <oxd-button
          icon-name="plus"
          display-type="secondary"
          label="Add"
          @click="toggleAdd"
        />
      </div>

      <div v-if="isAdmin" class="orangehrm-table-wrap">
        <table class="orangehrm-table">
          <thead>
            <tr>
              <th style="width: 4%" class="orangehrm-check-cell">
                <input
                  type="checkbox"
                  class="oxd-input"
                  :checked="isAllChecked"
                  :disabled="!members.length"
                  @change="onToggleAll"
                />
              </th>
              <th style="width: 22%">Divisi</th>
              <th style="width: 18%">Cabang</th>
              <th style="width: 30%">Karyawan</th>
              <th style="width: 10%">Status</th>
              <th style="width: 16%">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in filteredMembers" :key="m.id">
              <td class="orangehrm-check-cell">
                <input
                  type="checkbox"
                  class="oxd-input"
                  :checked="selectedIds.includes(m.id)"
                  @change="onToggleRow(m.id)"
                />
              </td>
              <td>
                <span class="orangehrm-divisi-badge">{{ m.divisi }}</span>
              </td>
              <td>{{ m.all_cabang ? 'Semua Cabang' : m.cabang || 'HO' }}</td>
              <td class="orangehrm-employee">
                {{ m.emp_name }} ({{ m.emp_number }})
              </td>
              <td>
                <div class="orangehrm-status-cell">
                  <oxd-switch-input
                    :model-value="!!m.aktif"
                    @update:model-value="(val) => onToggleActive(m, val)"
                  />
                  <span
                    class="orangehrm-status-text"
                    :class="m.aktif ? 'is-on' : 'is-off'"
                  >
                    {{ m.aktif ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </div>
              </td>
              <td>
                <oxd-icon-button
                  :with-container="false"
                  name="pencil-fill"
                  :title="'Edit'"
                  class="orangehrm-btn-edit"
                  @click="openEdit(m)"
                />
              </td>
            </tr>
            <tr
              v-if="!loading && members.length === 0"
              class="orangehrm-empty-row"
            >
              <td colspan="6">Belum ada member terdaftar.</td>
            </tr>
            <tr
              v-if="!loading && members.length && !filteredMembers.length"
              class="orangehrm-empty-row"
            >
              <td colspan="6">
                Tidak ada data member untuk filter ini. Ubah filter atau klik
                Reset.
              </td>
            </tr>
            <tr v-if="loading" class="orangehrm-empty-row">
              <td colspan="6">Memuat...</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else-if="loading" class="orangehrm-empty-row">Memuat...</div>
    </div>

    <oxd-dialog
      v-if="showForm"
      :style="{width: '90%', maxWidth: '620px'}"
      @update:show="onCancel"
    >
      <div class="orangehrm-modal-header">
        <oxd-text type="card-title">
          {{ form.id ? 'Edit Member' : 'Add Member KPI' }}
        </oxd-text>
      </div>
      <oxd-divider />
      <oxd-form :loading="saving" @submit-valid="onSubmit">
        <oxd-form-row>
          <oxd-grid :cols="2" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <oxd-input-field
                v-model="form.division"
                type="select"
                :label="'Divisi'"
                :options="divisionOptions"
                required
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="form.cabang"
                type="select"
                :label="'Cabang'"
                :options="cabangOptions"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>
        <oxd-form-row>
          <oxd-grid :cols="1" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <oxd-input-field
                v-model="form.emp_number"
                type="autocomplete"
                :label="'Karyawan'"
                :create-options="loadEmployees"
                :clear="false"
                required
                placeholder="Ketik untuk mencari karyawan…"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>
        <oxd-divider />
        <oxd-form-actions>
          <oxd-button
            display-type="ghost"
            label="Cancel"
            type="reset"
            @click="onCancel"
          />
          <oxd-button
            class="orangehrm-left-space"
            display-type="secondary"
            label="Save"
            type="submit"
          />
        </oxd-form-actions>
      </oxd-form>
    </oxd-dialog>
  </div>
</template>

<script>
import {required} from '@ohrm/core/util/validation/rules';
import {OxdDialog, OxdSwitchInput} from '@ohrm/oxd';
import Swal from 'sweetalert2';

// Daftar divisi korporat ASG (sama dgn InputRealisasiKpi) — dropdown Divisi di
// modal Add/Edit Member harus menampilkan SEMUA divisi, bukan hanya yang aktif
// di setup KPI (proxy divisions hanya berisi divisi yang sudah punya indikator).
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

export default {
  name: 'KpiDivisionMembers',
  components: {
    'oxd-dialog': OxdDialog,
    'oxd-switch-input': OxdSwitchInput,
  },
  props: {
    empNumber: {type: Number, default: 0},
  },
  data() {
    return {
      loading: true,
      saving: false,
      isAdmin: false,
      members: [],
      locations: [],
      divisions: [],
      employees: [],
      selectedIds: [],
      // Filter tabel member (Karyawan = teks, Divisi = dropdown). 'filters' =
      // draft, 'applied' = snapshot saat Search diklik (dipakai menampilkan).
      filters: {karyawan: '', divisi: {id: 0, label: 'Semua'}},
      applied: {karyawan: '', divisi: {id: 0, label: 'Semua'}},
      showForm: false,
      form: {
        id: 0,
        division: null,
        cabang: null,
        emp_number: null,
      },
      rules: {
        division: [required],
        emp_number: [required],
      },
    };
  },
  computed: {
    divisionOptions() {
      // Dropdown Divisi menampilkan SEMUA divisi korporat (COMPANY_DIVISIONS).
      // Tambahkan divisi apa pun dari proxy yang belum ada di daftar (jaga jika
      // ada divisi custom di member yang tidak terdaftar di konstanta).
      const seen = new Set(COMPANY_DIVISIONS);
      const all = COMPANY_DIVISIONS.slice();
      for (const d of this.divisions) {
        if (!seen.has(d)) {
          seen.add(d);
          all.push(d);
        }
      }
      return all.map((d, i) => ({id: i + 1, label: d}));
    },
    cabangOptions() {
      // 'Semua Cabang' ('' / NULL) = user dibolehkan SEMUA cabang divisi itu.
      // 'HO' = Pusat / Head Office: disimpan literal 'HO'; backend (kpiScope di
      // get_kpi_realisasi.php) menormalisasi 'HO' -> '' (= cakupan HO saja),
      // beda dengan NULL yang = semua cabang. Sisanya = lokasi ohrm_location.
      const labels = [
        {id: -1, label: 'Semua Cabang'},
        {id: 0, label: 'HO'},
      ];
      this.locations.forEach((l, i) => labels.push({id: i + 1, label: l.name}));
      return labels;
    },
    employeeOptions() {
      return this.employees.map((e) => ({
        id: e.emp_number,
        label: `${e.label} (${e.emp_number})`,
      }));
    },
    isAllChecked() {
      return (
        this.filteredMembers.length > 0 &&
        this.filteredMembers.every((m) => this.selectedIds.includes(m.id))
      );
    },
    // Opsi dropdown Divisi utk filter: 'Semua' + semua divisi korporat.
    filterDivisionOptions() {
      return [{id: 0, label: 'Semua'}, ...this.divisionOptions];
    },
    // Baris member yang lolos filter (Karyawan: nama/NIK substring, Divisi: exact).
    filteredMembers() {
      const nama = String(this.applied.karyawan || '')
        .trim()
        .toLowerCase();
      const div = this.applied.divisi?.label ?? this.applied.divisi;
      return this.members.filter((m) => {
        if (div && div !== 'Semua' && m.divisi !== div) return false;
        if (nama) {
          const hay = `${m.emp_name || ''} ${m.emp_number || ''}`.toLowerCase();
          if (!hay.includes(nama)) return false;
        }
        return true;
      });
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
    // Format label karyawan untuk opsi autocomplete (sama dgn label dropdown
    // select lama): "Nama (emp_number)".
    empLabel(e) {
      return e ? `${e.label} (${e.emp_number})` : '';
    },
    // Bangun opsi {id, label} dari emp_number, dipakai prefill saat edit. Kalau
    // karyawan tidak lagi ada di daftar employees, tetap bangun opsi minimal.
    empOptionFor(empNumber) {
      const e = this.employees.find((x) => x.emp_number === empNumber) || {
        emp_number: empNumber,
        label: String(empNumber),
      };
      return {id: e.emp_number, label: this.empLabel(e)};
    },
    // create-options utk autocomplete field Karyawan: pencarian lokal dari daftar
    // employees (sudah dimuat get_kpi_division_members.php). Term kosong => semua
    // karyawan; selain itu filter label/emp_number case-insensitive.
    async loadEmployees(searchTerm) {
      const t = String(searchTerm || '')
        .toLowerCase()
        .trim();
      const list = this.employees.filter((e) => {
        if (!t) return true;
        return (
          this.empLabel(e).toLowerCase().includes(t) ||
          String(e.emp_number).includes(t)
        );
      });
      return list.map((e) => ({id: e.emp_number, label: this.empLabel(e)}));
    },
    async fetchData() {
      this.loading = true;
      try {
        const params = new URLSearchParams({emp: String(this.empNumber)});
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/get_kpi_division_members.php?${params}`,
        );
        const json = await res.json();
        if (json.status === 'success') {
          this.isAdmin = json.data.is_admin;
          this.members = json.data.members || [];
          this.locations = json.data.locations || [];
          this.divisions = json.data.divisions || [];
          this.employees = json.data.employees || [];
        } else {
          this.$toast.error(json.message || 'Gagal memuat member.');
        }
      } catch (e) {
        this.$toast.error('Gagal memuat member: ' + e.message);
      } finally {
        this.loading = false;
      }
    },
    toggleAdd() {
      if (!this.isAdmin) {
        this.$toast.error('Anda tidak berhak mengelola member KPI.');
        return;
      }
      this.form = {
        id: 0,
        division: this.divisionOptions[0] || null,
        cabang: this.cabangOptions[0] || null,
        emp_number: null,
      };
      this.showForm = true;
    },
    openEdit(m) {
      if (!this.isAdmin) return;
      this.form = {
        id: m.id,
        division:
          this.divisionOptions.find((o) => o.label === m.divisi) || null,
        cabang:
          this.cabangOptions.find((o) =>
            m.cabang ? o.label === m.cabang : o.label === 'Semua Cabang',
          ) || null,
        emp_number: this.empOptionFor(m.emp_number),
      };
      this.showForm = true;
    },
    onCancel() {
      this.showForm = false;
    },
    onToggleRow(id) {
      const idx = this.selectedIds.indexOf(id);
      if (idx >= 0) this.selectedIds.splice(idx, 1);
      else this.selectedIds.push(id);
    },
    onToggleAll() {
      if (this.isAllChecked) this.selectedIds = [];
      else this.selectedIds = this.filteredMembers.map((m) => m.id);
    },
    // Terapkan filter (snapshot draft -> applied) & reset pilihan.
    onSearch() {
      this.applied = {
        karyawan: this.filters.karyawan,
        divisi: this.filters.divisi || this.filterDivisionOptions[0],
      };
    },
    onReset() {
      const semua = this.filterDivisionOptions[0] || {id: 0, label: 'Semua'};
      this.filters.karyawan = '';
      this.filters.divisi = semua;
      this.applied.karyawan = '';
      this.applied.divisi = semua;
      this.selectedIds = [];
    },
    // Aktif/nonaktifkan satu baris member (langsung simpan). Saat nonaktif, user
    // kehilangan hak input KPI utk baris mapping tsb (scoping mengabaikannya).
    async onToggleActive(m, val) {
      const baru = val ? 1 : 0;
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/set_kpi_division_member_active.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({emp: this.empNumber, id: m.id, aktif: baru}),
          },
        );
        const json = await res.json();
        if (json.status === 'success') {
          m.aktif = baru;
          await Swal.fire({
            icon: 'success',
            title: baru ? 'Diaktifkan' : 'Dinonaktifkan',
            text: `Hak input KPI ${baru ? 'diaktifkan' : 'dinonaktifkan'}`,
            confirmButtonText: 'OK',
          });
        } else {
          this.$toast.error(json.message || 'Gagal mengubah status member.');
        }
      } catch (e) {
        this.$toast.error('Gagal mengubah status member: ' + e.message);
      }
    },
    confirmDeleteSelected() {
      if (!this.selectedIds.length) return;
      const nama = this.members
        .filter((m) => this.selectedIds.includes(m.id))
        .map((m) => `${m.emp_name} — ${m.divisi}`)
        .join(', ');
      Swal.fire({
        title: 'Hapus Member?',
        text: `Mapping berikut akan dihapus: ${nama}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
      }).then((res) => {
        if (res.isConfirmed) this.deleteSelected();
      });
    },
    async deleteSelected() {
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/delete_kpi_division_member.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({emp: this.empNumber, ids: this.selectedIds}),
          },
        );
        const json = await res.json();
        if (json.status === 'success') {
          this.$toast.success(json.message);
          this.selectedIds = [];
          await this.fetchData();
        } else {
          this.$toast.error(json.message || 'Gagal menghapus member.');
        }
      } catch (e) {
        this.$toast.error('Gagal menghapus member: ' + e.message);
      }
    },
    async onSubmit() {
      if (!this.isAdmin) return;
      const f = this.form;
      const div =
        f.division && typeof f.division === 'object'
          ? f.division
          : this.divisionOptions.find((o) => o.id == f.division);
      const cab =
        f.cabang && typeof f.cabang === 'object'
          ? f.cabang
          : this.cabangOptions.find((o) => o.id == f.cabang);
      const emp =
        f.emp_number && typeof f.emp_number === 'object'
          ? f.emp_number
          : this.employeeOptions.find((o) => o.id == f.emp_number);
      if (!div || !emp) {
        this.$toast.error('Divisi dan Karyawan wajib diisi.');
        return;
      }
      this.saving = true;
      const body = {
        emp: this.empNumber,
        id: f.id,
        divisi: div.label,
        cabang: cab && cab.label !== 'Semua Cabang' ? cab.label : '',
        emp_number: emp.id,
      };
      try {
        const res = await fetch(
          `${this.getBaseUrl()}/proxy/save_kpi_division_member.php`,
          {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
          },
        );
        const json = await res.json();
        if (json.status === 'success') {
          this.$toast.success(json.message);
          this.showForm = false;
          await this.fetchData();
        } else {
          this.$toast.error(json.message || 'Gagal menyimpan member.');
        }
      } catch (e) {
        this.$toast.error('Gagal menyimpan member: ' + e.message);
      } finally {
        this.saving = false;
      }
    },
  },
};
</script>

<style lang="scss" scoped>
.orangehrm-left-space {
  margin-left: 0.5rem;
}
.orangehrm-status-cell {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  white-space: nowrap;
}
.orangehrm-status-text {
  font-size: 12px;
  font-weight: 600;
}
.orangehrm-status-text.is-on {
  color: #15803d;
}
.orangehrm-status-text.is-off {
  color: #b91c1c;
}
.orangehrm-message {
  margin-top: 8px;
  padding: 12px;
  background: #fbe9e7;
  color: #b71c1c;
  border-radius: 6px;
}
.orangehrm-table-wrap {
  margin-top: 12px;
  overflow-x: auto;
}
.orangehrm-table {
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
.orangehrm-divisi-badge {
  background: #e9f1ff;
  color: #1257a0;
  border-radius: 8px;
  padding: 2px 8px;
  font-weight: 600;
}
.orangehrm-employee {
  color: #303030;
  font-weight: 600;
}
.orangehrm-empty-row td {
  text-align: center;
  color: #929baa;
  padding: 1.25rem;
}
.orangehrm-btn-edit {
  color: #1257a0;
  &:hover {
    color: #0a3d75;
  }
}
</style>
