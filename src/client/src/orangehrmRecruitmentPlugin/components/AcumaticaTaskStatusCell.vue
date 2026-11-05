<!--
/**
 * Sel kolom "Acumatica Task Status" di tabel Recruitment -> Candidates.
 * Nilainya berasal dari /proxy/get_candidates_task_status.php (DB lokal),
 * jadi tidak memicu login ke Acumatica.
 */
-->
<template>
  <span :class="['acumatica-task-status', statusClass]">{{
    label || '-'
  }}</span>
</template>

<script>
export default {
  name: 'AcumaticaTaskStatusCell',
  props: {
    label: {
      type: String,
      default: '',
    },
    syncedAt: {
      type: String,
      default: '',
    },
  },
  computed: {
    statusClass() {
      switch (String(this.label || '').toLowerCase()) {
        case 'completed':
          return 'is-completed';
        case 'processing':
          return 'is-processing';
        case 'canceled':
        case 'cancelled':
          return 'is-canceled';
        case 'open':
          return 'is-open';
        default:
          return 'is-empty';
      }
    },
  },
};
</script>

<style scoped>
.acumatica-task-status {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.5;
  white-space: nowrap;
  background-color: #f1f5f9;
  color: #475569;
}
.acumatica-task-status.is-processing {
  background-color: #e0f2fe;
  color: #0369a1;
}
.acumatica-task-status.is-completed {
  background-color: #e6f4ea;
  color: #1e8e3e;
}
.acumatica-task-status.is-canceled {
  background-color: #fee2e2;
  color: #b91c1c;
}
.acumatica-task-status.is-empty {
  background-color: transparent;
  color: #94a3b8;
  font-weight: 400;
}
</style>
