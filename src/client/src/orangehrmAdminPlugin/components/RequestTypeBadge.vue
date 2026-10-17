<template>
  <span class="request-type-badge badge-default">
    {{ reqType }}
  </span>
</template>

<script>
export default {
  name: 'RequestTypeBadge',
  props: {
    reqType: {
      type: String,
      default: '',
    },
  },
};
</script>

<style scoped>
.request-type-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.5;
  white-space: nowrap;
}

.badge-default {
  background-color: #f0f0f0;
  color: #5c5c5c;
}
</style>
