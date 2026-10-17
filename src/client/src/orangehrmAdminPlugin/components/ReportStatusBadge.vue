<template>
  <span class="report-status-badge" :class="badgeClass">
    {{ status }}
  </span>
</template>

<script>
export default {
  name: 'ReportStatusBadge',
  props: {
    status: {
      type: String,
      default: '',
    },
  },
  computed: {
    badgeClass() {
      const s = String(this.status || '').toLowerCase();
      if (s === 'open') return 'badge-open';
      if (s === 'closed' || s === 'completed') return 'badge-green';
      if (s === 'unplanned') return 'badge-amber';
      return 'badge-gray';
    },
  },
};
</script>

<style scoped>
.report-status-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.5;
  white-space: nowrap;
}

.badge-open {
  background-color: #dbeafe;
  color: #1e40af;
}

.badge-green {
  background-color: #e6f4ea;
  color: #1e8e3e;
}

.badge-amber {
  background-color: #fef3c7;
  color: #b45309;
}

.badge-gray {
  background-color: #f1f3f4;
  color: #5f6368;
}
</style>
