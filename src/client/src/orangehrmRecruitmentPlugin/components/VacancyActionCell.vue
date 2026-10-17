<template>
  <div class="oxd-table-cell-actions">
    <!-- Reject hanya tampil untuk vacancy yang masih Active (statusRaw true).
         Kalau Closed / Rejected, action Reject disembunyikan. -->
    <oxd-icon-button
      v-if="showReject"
      name="x-circle"
      title="Reject"
      class="oxd-table-cell-action-space"
      @click="onReject && onReject(row)"
    />
    <oxd-icon-button
      name="pencil-fill"
      title="Edit"
      class="oxd-table-cell-action-space"
      @click="onEdit && onEdit(row)"
    />
  </div>
</template>

<script>
export default {
  name: 'VacancyActionCell',
  props: {
    row: {
      type: Object,
      default: () => ({}),
    },
    onReject: {
      type: Function,
      default: null,
    },
    onEdit: {
      type: Function,
      default: null,
    },
  },
  computed: {
    showReject() {
      // statusRaw = true (Active) → tampilkan Reject; false (Closed/Rejected) → sembunyikan
      return Boolean(this.row.statusRaw);
    },
  },
};
</script>
