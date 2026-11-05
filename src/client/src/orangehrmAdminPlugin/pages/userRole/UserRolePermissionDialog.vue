<!--
/**
 * Dialog matriks hak akses per role (Admin -> User Role -> Hak Akses).
 * Sumber data: /api/v2/admin/user-role-screens
 *  - GET  ?roleId=x                     daftar menu + screen + flag hak akses
 *  - PUT  {roleId, screens[]}           simpan flag
 *  - POST /copy {roleId, sourceRoleId}  salin seluruh hak akses role lain
 */
 -->

<template>
  <oxd-dialog
    class="orangehrm-dialog-modal orangehrm-role-permission-dialog"
    @update:show="$emit('close')"
  >
    <div class="permission-header">
      <div class="permission-header-text">
        <oxd-text tag="h6" class="permission-title">Hak Akses</oxd-text>
        <oxd-text tag="p" class="permission-subtitle">
          {{ roleName }} ·
          <span class="permission-stat">
            {{ activeScreens }} dari {{ totalScreens }} menu aktif
          </span>
        </oxd-text>
      </div>
      <oxd-button display-type="ghost" label="Tutup" @click="$emit('close')" />
    </div>

    <oxd-divider />

    <oxd-text v-if="!editable" tag="p" class="permission-locked-note">
      Hak akses role Admin dikunci (penuh) dan tidak dapat diubah dari halaman
      ini.
    </oxd-text>

    <div v-if="loading" class="permission-loading">
      <oxd-text tag="p">Memuat hak akses...</oxd-text>
    </div>

    <template v-else>
      <div class="permission-toolbar">
        <div class="permission-search">
          <oxd-input-field
            v-model="search"
            :show-label="false"
            placeholder="Cari menu (mis. leave, pim, admin)..."
          />
        </div>
        <div class="permission-copy">
          <oxd-input-field
            v-model="copyFrom"
            type="select"
            :options="copyOptions"
            :clear="false"
            :show-label="false"
            placeholder="Salin dari role"
          />
          <oxd-button
            class="permission-copy-button"
            label="Salin"
            display-type="secondary"
            :disabled="!copyFrom || copying || !editable"
            @click="onCopy"
          />
        </div>
      </div>

      <div class="permission-quicklinks">
        <a href="#" @click.prevent="toggleAll(true)">Buka semua</a>
        <a href="#" @click.prevent="toggleAll(false)">Tutup semua</a>
        <span class="permission-legend">
          Lihat = boleh buka menunya · Tambah/Ubah/Hapus = aksi di dalamnya
        </span>
      </div>

      <div class="permission-list">
        <div v-if="filteredGroups.length === 0" class="permission-empty">
          <oxd-text tag="p">
            Tidak ada menu yang cocok dengan "{{ search }}".
          </oxd-text>
        </div>

        <div
          v-for="entry in filteredGroups"
          :key="entry.group.menuId"
          class="permission-group"
          :class="{'permission-group-open': entry.group._open}"
        >
          <div class="permission-group-head" @click="toggleGroup(entry.group)">
            <span class="permission-caret">
              {{ entry.group._open ? '▾' : '▸' }}
            </span>
            <span class="permission-group-title">
              {{ entry.group.menuTitle }}
            </span>
            <span class="permission-group-module">
              {{ entry.group.moduleName }}
            </span>
            <span
              class="permission-group-count"
              :class="{'is-active': groupActive(entry.group) > 0}"
            >
              {{ groupActive(entry.group) }}/{{ entry.group.screens.length }}
            </span>
            <span class="permission-group-actions" @click.stop>
              <a href="#" @click.prevent="setGroup(entry.group, true)">
                Semua
              </a>
              <a href="#" @click.prevent="setGroup(entry.group, false)">
                Kosong
              </a>
            </span>
          </div>

          <div v-show="entry.group._open" class="permission-table-wrap">
            <table class="permission-table">
              <thead>
                <tr>
                  <th class="col-screen">Menu</th>
                  <th v-for="field in fields" :key="field" class="col-check">
                    {{ columnLabels[field] }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="screen in entry.screens"
                  :key="screen.screenId"
                  :class="{'permission-row-active': screen.canRead}"
                >
                  <td class="col-screen">
                    <span class="permission-screen-name">
                      {{ screen.screenName }}
                    </span>
                    <span class="permission-screen-url">
                      {{ screen.actionUrl }}
                    </span>
                  </td>
                  <td
                    v-for="field in fields"
                    :key="field"
                    class="col-check"
                    @click="toggleField(screen, field)"
                  >
                    <span class="permission-check">
                      <input
                        v-model="screen[field]"
                        type="checkbox"
                        :disabled="!editable"
                        @click.stop
                        @change="syncSameScreen(screen)"
                      />
                      <span class="permission-check-box" />
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="permission-footer">
        <oxd-text tag="p" class="permission-footer-note">
          {{ activeScreens }} menu aktif. Perubahan berlaku setelah Simpan.
        </oxd-text>
        <div class="permission-footer-actions">
          <oxd-button
            display-type="ghost"
            label="Batal"
            @click="$emit('close')"
          />
          <oxd-button
            v-if="editable"
            display-type="secondary"
            :label="saving ? 'Menyimpan...' : 'Simpan'"
            :disabled="saving"
            @click="onSave"
          />
        </div>
      </div>
    </template>
  </oxd-dialog>
</template>

<script>
import {APIService} from '@ohrm/core/util/services/api.service';

export default {
  name: 'UserRolePermissionDialog',

  props: {
    roleId: {
      type: Number,
      required: true,
    },
    roleName: {
      type: String,
      default: '',
    },
  },

  emits: ['close'],

  data() {
    return {
      fields: ['canRead', 'canCreate', 'canUpdate', 'canDelete'],
      columnLabels: {
        canRead: 'Lihat',
        canCreate: 'Tambah',
        canUpdate: 'Ubah',
        canDelete: 'Hapus',
      },
      groups: [],
      roles: [],
      search: '',
      copyFrom: null,
      editable: true,
      loading: true,
      saving: false,
      copying: false,
      http: null,
    };
  },

  computed: {
    copyOptions() {
      return this.roles
        .filter((role) => role.id !== this.roleId)
        .map((role) => ({id: role.id, label: role.displayName || role.name}));
    },
    totalScreens() {
      return this.groups.reduce((sum, group) => sum + group.screens.length, 0);
    },
    activeScreens() {
      return this.groups.reduce(
        (sum, group) => sum + this.groupActive(group),
        0,
      );
    },
    filteredGroups() {
      const query = this.search.trim().toLowerCase();
      if (!query) {
        return this.groups.map((group) => ({group, screens: group.screens}));
      }
      return this.groups
        .map((group) => {
          const screens = group.screens.filter(
            (screen) =>
              screen.screenName.toLowerCase().includes(query) ||
              (screen.actionUrl || '').toLowerCase().includes(query) ||
              group.menuTitle.toLowerCase().includes(query),
          );
          return screens.length ? {group, screens} : null;
        })
        .filter(Boolean);
    },
  },

  mounted() {
    this.http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/admin/user-role-screens',
    );
    this.loadRoles();
    this.loadPermissions();
  },

  methods: {
    loadRoles() {
      const http = new APIService(
        window.appGlobal.baseUrl,
        '/api/v2/admin/user-roles',
      );
      http
        .getAll({limit: 200, offset: 0})
        .then((response) => {
          this.roles = response?.data?.data || [];
        })
        .catch(() => {
          this.roles = [];
        });
    },

    loadPermissions() {
      this.loading = true;
      this.http
        .getAll({roleId: this.roleId})
        .then((response) => {
          this.editable = response?.data?.meta?.editable !== false;
          const groups = response?.data?.data || [];
          this.groups = groups.map((group, index) => ({
            ...group,
            _open: index < 3,
            screens: group.screens.map((screen) => ({
              ...screen,
              canRead: !!screen.canRead,
              canCreate: !!screen.canCreate,
              canUpdate: !!screen.canUpdate,
              canDelete: !!screen.canDelete,
            })),
          }));
        })
        .catch((error) => {
          this.$toast.error({
            title: 'Error',
            message:
              error?.response?.data?.error?.message ||
              'Gagal memuat hak akses role.',
          });
        })
        .finally(() => {
          this.loading = false;
        });
    },

    groupActive(group) {
      return group.screens.filter(
        (screen) =>
          screen.canRead ||
          screen.canCreate ||
          screen.canUpdate ||
          screen.canDelete,
      ).length;
    },

    toggleGroup(group) {
      group._open = !group._open;
    },

    toggleAll(open) {
      this.groups.forEach((group) => {
        group._open = open;
      });
    },

    setGroup(group, value) {
      if (!this.editable) return;
      group.screens.forEach((screen) => {
        this.fields.forEach((field) => {
          screen[field] = value;
        });
        this.syncSameScreen(screen);
      });
    },

    toggleField(screen, field) {
      if (!this.editable) return;
      screen[field] = !screen[field];
      this.syncSameScreen(screen);
    },

    /**
     * Beberapa menu di fork ini menunjuk screen yang sama (mis. "History Quota
     * Department" dan "Users" sama-sama screen 1). Izinnya satu baris di DB,
     * jadi semua baris dengan screenId sama harus ikut berubah.
     */
    syncSameScreen(screen) {
      this.groups.forEach((group) => {
        group.screens.forEach((other) => {
          if (other !== screen && other.screenId === screen.screenId) {
            this.fields.forEach((field) => {
              other[field] = screen[field];
            });
          }
        });
      });
    },

    onCopy() {
      // oxd-input-field type=select mengembalikan objek opsi, bukan id.
      // Harus diambil id-nya, kalau tidak API menolak dengan 422 Invalid Parameter.
      const source = this.copyFrom;
      const sourceRoleId = Number(
        source && typeof source === 'object' ? source.id : source,
      );
      if (!sourceRoleId || this.copying) return;
      this.copying = true;
      this.http
        .request({
          method: 'post',
          url: '/api/v2/admin/user-role-screens/copy',
          data: {roleId: Number(this.roleId), sourceRoleId},
        })
        .then((response) => {
          const data = response?.data?.data || {};
          this.$toast.success({
            title: 'Berhasil',
            message: `Hak akses disalin: ${data.screens || 0} screen, ${
              data.dataGroups || 0
            } data group.`,
          });
          this.copyFrom = null;
          this.loadPermissions();
        })
        .catch((error) => {
          const data = error?.response?.data?.error;
          const keys = data?.data?.invalidParamKeys;
          this.$toast.error({
            title: 'Error',
            message: keys?.length
              ? `Parameter tidak valid: ${keys.join(', ')}`
              : data?.message || 'Gagal menyalin hak akses.',
          });
        })
        .finally(() => {
          this.copying = false;
        });
    },

    onSave() {
      if (this.saving) return;
      this.saving = true;
      const screens = [];
      this.groups.forEach((group) => {
        group.screens.forEach((screen) => {
          screens.push({
            screenId: screen.screenId,
            canRead: screen.canRead,
            canCreate: screen.canCreate,
            canUpdate: screen.canUpdate,
            canDelete: screen.canDelete,
          });
        });
      });

      this.http
        .request({
          method: 'put',
          url: '/api/v2/admin/user-role-screens',
          data: {roleId: Number(this.roleId), screens},
        })
        .then(() => {
          this.$toast.saveSuccess();
          this.$emit('close');
        })
        .catch((error) => {
          const data = error?.response?.data?.error;
          const keys = data?.data?.invalidParamKeys;
          this.$toast.error({
            title: 'Error',
            message: keys?.length
              ? `Parameter tidak valid: ${keys.join(', ')}`
              : data?.message || 'Gagal menyimpan hak akses.',
          });
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style src="./user-role-permission.scss" lang="scss" scoped></style>
