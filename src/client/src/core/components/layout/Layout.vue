<template>
  <oxd-layout v-bind="$attrs">
    <template v-for="(_, name) in $slots" #[name]="slotData">
      <slot :name="name" v-bind="slotData" />
    </template>
    <template #user-actions>
      <li>
        <a
          href="#"
          role="menuitem"
          class="oxd-userdropdown-link"
          @click="openAboutModel"
        >
          {{ $t('general.about') }}
        </a>
      </li>
      <li>
        <a :href="supportUrl" role="menuitem" class="oxd-userdropdown-link">
          {{ $t('general.support') }}
        </a>
      </li>
      <li v-if="updatePasswordUrl">
        <a
          :href="updatePasswordUrl"
          role="menuitem"
          class="oxd-userdropdown-link"
        >
          {{ $t('general.change_password') }}
        </a>
      </li>
      <li>
        <a :href="logoutUrl" role="menuitem" class="oxd-userdropdown-link">
          {{ $t('general.logout') }}
        </a>
      </li>
    </template>
    <template #nav-actions>
      <oxd-icon-button
        name="question-lg"
        :title="$t('general.help')"
        @click="onClickSupport"
      />
    </template>
  </oxd-layout>
  <about v-if="showAboutModel" @close="closeAboutModel"></about>
</template>

<script>
import {provide, readonly, ref} from 'vue';
import About from '@/core/pages/About.vue';
import {OxdLayout} from '@ohrm/oxd';
import {dateFormatKey} from '@/core/util/composable/useDateFormat';

export default {
  name: 'AppLayout',
  components: {
    about: About,
    'oxd-layout': OxdLayout,
  },
  inheritAttrs: false,
  props: {
    permissions: {
      type: Object,
      default: () => ({}),
    },
    logoutUrl: {
      type: String,
      default: '#',
    },
    supportUrl: {
      type: String,
      default: '#',
    },
    updatePasswordUrl: {
      type: String,
      default: '#',
    },
    dateFormat: {
      type: Object,
      default: null,
    },
    helpUrl: {
      type: String,
      default: null,
    },
  },
  setup(props) {
    const showAboutModel = ref(false);
    provide('permissions', readonly(props.permissions));
    provide(dateFormatKey, readonly(props.dateFormat));

    const openAboutModel = () => {
      showAboutModel.value = true;
    };

    const closeAboutModel = () => {
      showAboutModel.value = false;
    };

    const onClickSupport = () => {
      if (props.helpUrl) window.open(props.helpUrl, '_blank');
    };

    return {
      onClickSupport,
      showAboutModel,
      openAboutModel,
      closeAboutModel,
    };
  },
  mounted() {
    this.$nextTick(() => {
      this.injectNotificationBell();
    });
  },
  beforeUnmount() {
    if (this._notifInterval) {
      clearInterval(this._notifInterval);
    }
  },
  methods: {
    getBaseUrl() {
      return window.appGlobal?.baseUrl || '';
    },
    getDropdown() {
      return document.querySelector('.oxd-notif-dropdown');
    },
    injectNotificationBell() {
      const userArea = document.querySelector('.oxd-topbar-header-userarea ul');
      if (!userArea) return;
      if (userArea.querySelector('.oxd-notification-item')) return;

      // Baca timestamp terakhir yang sudah dilihat dari localStorage
      // (pakai timestamp, bukan id — id antar tabel bisa bertabrakan)
      // Hapus key lama jika masih ada
      localStorage.removeItem('notif_last_count');
      localStorage.removeItem('notif_last_id'); // key lama berbasis id
      const saved = localStorage.getItem('notif_last_ts');
      this._notifLastTs = saved || '';

      // Inject bell button
      const li = document.createElement('li');
      li.className = 'oxd-notification-item';
      li.style.position = 'relative';
      li.innerHTML = `
        <button class="oxd-notification-btn" title="Notifications">
          <i class="oxd-icon bi-bell"></i>
          <span class="oxd-notification-badge" style="display:none">0</span>
        </button>
        <div class="oxd-notif-dropdown" style="display:none">
          <div class="oxd-notif-dropdown-header">Notifications</div>
          <div class="oxd-notif-dropdown-body">
            <div class="oxd-notif-empty">No notifications</div>
          </div>
        </div>
      `;

      li.querySelector('.oxd-notification-btn').addEventListener(
        'click',
        (e) => {
          e.preventDefault();
          e.stopPropagation();
          this.toggleDropdown();
        },
      );

      // Close dropdown on outside click
      document.addEventListener('click', (e) => {
        const dd = this.getDropdown();
        if (dd && !dd.closest('.oxd-notification-item').contains(e.target)) {
          dd.style.display = 'none';
        }
      });

      userArea.prepend(li);

      // Listen for Acumatica sync complete → refresh bell
      window.addEventListener('acumatica-sync-complete', () => {
        this.updateNotificationCount();
      });

      this.updateNotificationCount();
      this._notifInterval = setInterval(() => {
        this.updateNotificationCount();
      }, 60000);
    },
    toggleDropdown() {
      const dd = this.getDropdown();
      if (!dd) return;
      if (dd.style.display === 'block') {
        dd.style.display = 'none';
        return;
      }
      // NOTE: saveLastSeenId() TIDAK dipanggil di sini — kalau dipanggil
      // sebelum render, notif baru langsung ditandai dilihat tanpa user sempat
      // melihat isinya (dropdown jadi kosong). Mark-as-viewed terjadi di
      // renderDropdown() hanya saat display sudah 'block'.
      const badge = document.querySelector('.oxd-notification-badge');
      if (badge) {
        badge.style.display = 'none';
      }
      const bellIcon = document.querySelector(
        '.oxd-notification-item .bi-bell, .oxd-notification-item .bi-bell-fill',
      );
      if (bellIcon) {
        bellIcon.classList.remove('bi-bell-fill');
        bellIcon.classList.add('bi-bell');
      }
      // Refresh data & show
      this.updateNotificationCount(true);
      dd.style.display = 'block';
    },
    async updateNotificationCount(forceRefresh) {
      try {
        const baseUrl = this.getBaseUrl();
        // Selalu ambil SEMUA data (tanpa filter after)
        const url = baseUrl + '/admin/notificationCount';
        const res = await fetch(url);
        if (!res.ok) return;
        const result = await res.json();
        const items = result?.data?.items || [];

        // Hitung total & "baru" (updated_at > _notifLastTs) dari semua group
        let allEntries = [];
        let newCount = 0;
        for (const group of items) {
          if (group.list && group.list.length > 0) {
            for (const entry of group.list) {
              allEntries.push({...entry, type: entry.type || group.type});
              // Baru = timestamp lebih baru dari yang terakhir dilihat
              if (String(entry.updated_at || '') > this._notifLastTs) {
                newCount++;
              }
            }
          }
        }

        // Update badge — muncul hanya jika ada data BARU
        const badge = document.querySelector('.oxd-notification-badge');
        if (badge) {
          const showBadge = newCount > 0;
          badge.textContent = newCount > 99 ? '99+' : String(newCount);
          badge.style.display = showBadge ? 'inline-flex' : 'none';
        }

        // Update bell icon
        const bellIcon = document.querySelector(
          '.oxd-notification-item .bi-bell, .oxd-notification-item .bi-bell-fill',
        );
        if (bellIcon) {
          if (newCount > 0) {
            bellIcon.classList.remove('bi-bell');
            bellIcon.classList.add('bi-bell-fill');
          } else {
            bellIcon.classList.remove('bi-bell-fill');
            bellIcon.classList.add('bi-bell');
          }
        }

        // Update dropdown content — SELALU render semua (history tetap ada)
        if (forceRefresh || this._notifList === undefined) {
          this._notifList = items;
        }
        this.renderDropdown(items);
      } catch (e) {
        // silent
      }
    },
    renderDropdown(items) {
      const body = document.querySelector('.oxd-notif-dropdown-body');
      if (!body) return;

      // Collect all list entries (ikutkan type group)
      let allEntries = [];
      for (const group of items) {
        if (group.list && group.list.length > 0) {
          allEntries = allEntries.concat(
            group.list.map((entry) => ({
              ...entry,
              type: entry.type || group.type,
            })),
          );
        }
      }

      // HANYA tampilkan entry yang BELUM dilihat (updated_at > _notifLastTs).
      // Case yang sudah pernah muncul/dilihat tidak ditampilkan lagi.
      allEntries = allEntries.filter(
        (entry) => String(entry.updated_at || '') > this._notifLastTs,
      );

      if (allEntries.length === 0) {
        body.innerHTML = '<div class="oxd-notif-empty">No notifications</div>';
        return;
      }

      body.innerHTML = allEntries
        .map((entry) => {
          const isNew = String(entry.updated_at || '') > this._notifLastTs;
          const newClass = isNew ? ' oxd-notif-entry-new' : '';

          // Notifikasi Approval KPI (keputusan pengajuan edit indikator)
          if (entry.type === 'kpi_edit_request') {
            return this.renderKpiEntry(entry, newClass);
          }

          const statusText = entry.status || 'Completed';
          return `
        <div class="oxd-notif-entry${newClass}" data-case="${
            entry.case_id || ''
          }" data-id="${entry.id || ''}" data-ts="${
            entry.updated_at || ''
          }" data-type="${entry.type || ''}">
          <div class="oxd-notif-entry-title">
            <span class="oxd-notif-badge-dot oxd-notif-dot-completed"></span>
            Case #${this.escapeHtml(entry.case_id || '—')}
            <button class="oxd-notif-eye" title="Lihat detail Case">
              <i class="oxd-icon bi-eye"></i>
            </button>
            <span class="oxd-notif-status-badge">${this.escapeHtml(
              statusText,
            )}</span>
          </div>
          <div class="oxd-notif-entry-detail">
            ${this.escapeHtml(entry.department || '')} ${this.escapeHtml(
            entry.position ? '— ' + entry.position : '',
          )}
          </div>
          <div class="oxd-notif-entry-time">${this.formatTime(
            entry.updated_at,
          )}</div>
        </div>
      `;
        })
        .join('');

      // Click handler on each entry — click anywhere goes to page terkait
      body.querySelectorAll('.oxd-notif-entry').forEach((el) => {
        el.addEventListener('click', (e) => {
          // Jangan trigger kalo yang diklik tombol eye
          if (e.target.closest('.oxd-notif-eye')) return;
          this.openNotificationEntry(el);
        });
      });

      // Click handler khusus tombol eye
      body.querySelectorAll('.oxd-notif-eye').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          this.openNotificationEntry(btn.closest('.oxd-notif-entry'));
        });
      });

      // NOTE: saveLastSeenId() sengaja TIDAK dipanggil di sini tanpa syarat —
      // renderDropdown juga dipanggil oleh poll 60 detik di background. Kalau
      // dipanggil saat dropdown masih hidden, notifikasi baru langsung
      // "ditelan" (ditandai dilihat) tanpa user sempat membuka dropdown.
      // Mark-as-viewed hanya dilakukan saat dropdown BENAR-BENAR terbuka
      // (display block), lihat toggleDropdown().
      if (this.getDropdown()?.style.display === 'block') {
        this.saveLastSeenId();
      }
    },
    // Entry khusus notifikasi Approval KPI (keputusan pengajuan edit indikator).
    // Klik entry -> langsung halaman Approval KPI (bukan viewSystemUsers).
    renderKpiEntry(entry, newClass) {
      const approved = entry.status === 'Disetujui';
      const title = entry.divisi ? 'KPI ' + entry.divisi : 'Approval KPI';
      const detail = [
        entry.indikator || '',
        entry.periode ? 'Periode ' + entry.periode : '',
      ]
        .filter(Boolean)
        .join(' — ');
      const note =
        entry.status === 'Ditolak'
          ? 'Alasan: ' + (entry.note || '-')
          : 'Pengaju: ' + (entry.requester_name || '-');
      return `
        <div class="oxd-notif-entry${newClass}" data-id="${
        entry.id || ''
      }" data-ts="${entry.updated_at || ''}" data-type="kpi_edit_request">
          <div class="oxd-notif-entry-title">
            <span class="oxd-notif-badge-dot ${
              approved ? 'oxd-notif-dot-completed' : 'oxd-notif-dot-rejected'
            }"></span>
            ${this.escapeHtml(title)}
            <span class="oxd-notif-status-badge ${
              approved
                ? 'oxd-notif-status-approved'
                : 'oxd-notif-status-rejected'
            }">${this.escapeHtml(entry.status || '')}</span>
          </div>
          <div class="oxd-notif-entry-detail">${this.escapeHtml(detail)}</div>
          <div class="oxd-notif-entry-detail">${this.escapeHtml(note)}</div>
          <div class="oxd-notif-entry-time">${this.formatTime(
            entry.updated_at,
          )}</div>
        </div>
      `;
    },
    // 1 pintu navigasi dari dropdown: KPI -> halaman Approval KPI,
    // Manpower/HRPLAN -> halaman list Acumatica (perilaku lama).
    openNotificationEntry(el) {
      if (!el) return;
      const baseUrl = this.getBaseUrl();
      const type = el.dataset.type;
      if (type === 'kpi_edit_request') {
        window.location.href = baseUrl + '/performance/approvalKpi';
        return;
      }
      if (el.dataset.case) {
        const page =
          type === 'hrplan_completed' ? 'quota-request' : 'manpower-request';
        window.location.href = baseUrl + '/admin/viewSystemUsers?page=' + page;
      }
    },
    escapeHtml(str) {
      if (!str) return '';
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    },
    formatTime(dateStr) {
      if (!dateStr) return '';
      try {
        const d = new Date(dateStr);
        const now = new Date();
        const diff = now - d;
        const mins = Math.floor(diff / 60000);
        if (mins < 1) return 'Just now';
        if (mins < 60) return mins + 'm ago';
        const hours = Math.floor(mins / 60);
        if (hours < 24) return hours + 'h ago';
        const days = Math.floor(hours / 24);
        if (days < 7) return days + 'd ago';
        return d.toLocaleDateString();
      } catch (e) {
        return dateStr;
      }
    },
    onClickNotification() {
      // Kept for backward compat — now handled by toggleDropdown
      this.toggleDropdown();
    },

    saveLastSeenId() {
      // Simpan timestamp terbaru dari entry yang sedang ditampilkan
      const entries = document.querySelectorAll('.oxd-notif-entry');
      let maxTs = this._notifLastTs || '';
      entries.forEach((el) => {
        const ts = el.dataset.ts || '';
        if (ts > maxTs) maxTs = ts;
      });
      if (maxTs) {
        this._notifLastTs = maxTs;
        localStorage.setItem('notif_last_ts', maxTs);
      }
    },
  },
};
</script>

<style>
.oxd-notification-item {
  list-style: none;
  display: flex;
  align-items: center;
  margin-right: 4px;
  position: relative;
}

.oxd-notification-btn {
  background: none;
  border: none;
  color: rgba(255, 255, 255, 0.85);
  font-size: 1.25rem;
  cursor: pointer;
  padding: 6px 10px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  transition: background-color 0.15s ease, color 0.15s ease;
  line-height: 1;
}

.oxd-notification-btn:hover {
  color: #ffffff;
  background-color: rgba(255, 255, 255, 0.12);
}

.oxd-notification-btn .oxd-icon {
  font-size: inherit;
  color: inherit;
}

.oxd-notification-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  background-color: #e74c3c;
  color: #ffffff;
  font-size: 10px;
  font-weight: 700;
  font-family: 'Nunito Sans', sans-serif;
  border-radius: 8px;
  align-items: center;
  justify-content: center;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
  pointer-events: none;
  line-height: 1;
}

/* --- DROPDOWN --- */

.oxd-notif-dropdown {
  position: absolute;
  top: 100%;
  right: 0;
  width: 360px;
  max-height: 400px;
  background: #ffffff;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
  z-index: 9999;
  margin-top: 8px;
  overflow: hidden;
  display: none;
  color: #333;
  font-family: 'Nunito Sans', sans-serif;
}

.oxd-notif-dropdown-header {
  padding: 14px 16px;
  font-size: 14px;
  font-weight: 700;
  color: #1a1a1a;
  border-bottom: 1px solid #e9ecef;
  background: #f8f9fa;
}

.oxd-notif-dropdown-body {
  max-height: 340px;
  overflow-y: auto;
}

.oxd-notif-empty {
  padding: 32px 16px;
  text-align: center;
  color: #adb5bd;
  font-size: 13px;
}

.oxd-notif-entry {
  padding: 12px 16px;
  border-bottom: 1px solid #f1f3f5;
  cursor: pointer;
  transition: background 0.12s;
}

.oxd-notif-entry:hover {
  background: #f8f9fa;
}

/* Highlight untuk entry baru */
.oxd-notif-entry-new {
  background: #f0f9ff;
  border-left: 3px solid #0d6efd;
  box-shadow: inset 0 0 8px rgba(13, 110, 253, 0.06);
}
.oxd-notif-entry-new:hover {
  background: #e6f2ff;
}

.oxd-notif-entry:last-child {
  border-bottom: none;
}

.oxd-notif-entry-title {
  font-size: 13px;
  font-weight: 600;
  color: #1a1a1a;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 3px;
}

.oxd-notif-badge-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #0d6efd;
  flex-shrink: 0;
}

.oxd-notif-dot-completed {
  background: #1e8e3e;
}

.oxd-notif-dot-rejected {
  background: #d93025;
}

.oxd-notif-status-badge {
  font-size: 10px;
  font-weight: 700;
  color: #1e8e3e;
  background-color: #e6f4ea;
  padding: 1px 6px;
  border-radius: 10px;
  margin-left: auto;
  white-space: nowrap;
}

.oxd-notif-status-badge.oxd-notif-status-rejected {
  color: #b91c1c;
  background-color: #fee2e2;
}

.oxd-notif-eye {
  background: none;
  border: none;
  cursor: pointer;
  color: #6c757d;
  font-size: 14px;
  padding: 2px 6px;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
  transition: color 0.12s, background 0.12s;
  margin-left: 4px;
}

.oxd-notif-eye:hover {
  color: #0d6efd;
  background: #e9ecef;
}

.oxd-notif-entry-detail {
  font-size: 12px;
  color: #6c757d;
  padding-left: 16px;
  margin-bottom: 2px;
}

.oxd-notif-entry-time {
  font-size: 11px;
  color: #adb5bd;
  padding-left: 16px;
}
</style>
