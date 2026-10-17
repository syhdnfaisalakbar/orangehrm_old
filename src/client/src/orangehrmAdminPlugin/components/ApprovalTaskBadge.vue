<template>
  <span class="approval-task-badge" :class="badgeClass">{{ status }}</span>
</template>

<script>
export default {
  name: 'ApprovalTaskBadge',
  props: {
    status: {
      type: String,
      default: '-',
    },
  },
  computed: {
    badgeClass() {
      const s = String(this.status || '').toLowerCase();
      if (s === 'completed') return 'badge-completed';
      if (s === 'open' || s === 'pending') return 'badge-open';
      if (s === 'rejected') return 'badge-rejected';
      return 'badge-default';
    },
  },
};
</script>

<style scoped>
.approval-task-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.5;
  white-space: nowrap;
}

.badge-completed {
  background-color: #e6f4ea;
  color: #1e8e3e;
}

.badge-open {
  background-color: #dbeafe;
  color: #1e40af;
}

.badge-rejected {
  background-color: #fce8e6;
  color: #c5221f;
}

.badge-default {
  background-color: #f0f0f0;
  color: #5c5c5c;
}
</style>
