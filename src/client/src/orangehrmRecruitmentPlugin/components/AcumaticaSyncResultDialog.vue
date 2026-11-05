<!--
/**
 * Popup hasil tombol "Sync Acumatica" di daftar kandidat.
 * Menampilkan ringkasan: kandidat yang ter-update beserta status task-nya,
 * dan kandidat yang dilewati beserta alasannya.
 */
-->
<template>
  <teleport to="#app">
    <simple-dialog
      v-if="show"
      :with-close="true"
      class="orangehrm-confirmation-dialog"
      @update:show="close"
    >
      <div class="orangehrm-modal-header">
        <oxd-text type="card-title">Hasil Sync Acumatica</oxd-text>
      </div>

      <div class="acumatica-sync-body">
        <oxd-text v-if="!result.ok" type="card-body">
          {{ result.message || 'Sync gagal.' }}
        </oxd-text>
        <template v-else>
          <oxd-text type="card-body" class="acumatica-sync-summary">
            {{ okCount }} kandidat ter-update, {{ skipCount }} dilewati
          </oxd-text>
          <div
            v-for="(row, index) in result.rows"
            :key="index"
            :class="['acumatica-sync-row', row.ok ? 'is-ok' : 'is-skip']"
          >
            <span class="acumatica-sync-name">{{ row.name }}</span>
            <span class="acumatica-sync-status">
              {{ row.ok ? row.status : row.message }}
            </span>
          </div>
          <div
            v-for="(row, index) in cvChanges"
            :key="`cv-${index}`"
            class="acumatica-sync-cv"
          >
            <span class="acumatica-sync-name">{{ row.name }}</span>
            <span class="acumatica-sync-cv-text">
              {{ row.text }}
            </span>
          </div>
        </template>
      </div>

      <div class="orangehrm-modal-footer">
        <oxd-button
          label="OK"
          display-type="secondary"
          class="orangehrm-button-margin"
          @click="close"
        />
      </div>
    </simple-dialog>
  </teleport>
</template>

<script>
import {OxdDialog} from '@ohrm/oxd';

export default {
  name: 'AcumaticaSyncResultDialog',
  components: {
    'simple-dialog': OxdDialog,
  },
  data() {
    return {
      show: false,
      result: {ok: true, rows: [], message: ''},
    };
  },
  computed: {
    okCount() {
      return (this.result.rows || []).filter((r) => r.ok).length;
    },
    skipCount() {
      return (this.result.rows || []).filter((r) => !r.ok).length;
    },
    // Baris tambahan: perubahan status kandidat di OrangeHRM akibat sync.
    cvChanges() {
      return (this.result.rows || [])
        .filter((r) => r.cvAction === 'rejected' || r.cvAction === 'restored')
        .map((r) => ({
          name: r.name,
          text:
            r.cvAction === 'rejected'
              ? `Kandidat di-Reject (dari ${r.cvFrom || '-'})`
              : `Kandidat dipulihkan ke ${r.cvTo || '-'}`,
        }));
    },
  },
  methods: {
    showDialog(result) {
      this.result = {...{ok: true, rows: [], message: ''}, ...(result || {})};
      this.show = true;
    },
    close() {
      this.show = false;
    },
  },
};
</script>

<style lang="scss" scoped>
.orangehrm-modal-header {
  margin-bottom: 1rem;
  display: flex;
  justify-content: center;
}

.orangehrm-modal-footer {
  margin-top: 1.2rem;
  display: flex;
  justify-content: center;
}

.orangehrm-button-margin {
  margin: 0.25rem;
}

.acumatica-sync-body {
  max-height: 320px;
  overflow-y: auto;
  min-width: 380px;
}

.acumatica-sync-summary {
  display: block;
  margin-bottom: 0.6rem;
  font-weight: 600;
}

.acumatica-sync-row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid #e5e7eb;
  font-size: 13px;

  &.is-skip .acumatica-sync-status {
    color: #b45309;
  }
  &.is-ok .acumatica-sync-status {
    color: #1e8e3e;
    font-weight: 600;
  }
}

.acumatica-sync-name {
  color: #334155;
}

.acumatica-sync-cv {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.2rem 0 0.35rem;
  font-size: 12px;
  color: #b45309;

  .acumatica-sync-cv-text {
    font-weight: 600;
  }
}
</style>
