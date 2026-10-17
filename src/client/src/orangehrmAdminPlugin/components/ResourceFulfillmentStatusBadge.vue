<template>
  <span :style="badgeStyle">{{ status }}</span>
</template>

<script>
export default {
  name: 'ResourceFulfillmentStatusBadge',
  props: {
    status: {
      type: String,
      default: '',
    },
  },
  computed: {
    badgeStyle() {
      let bg = '#f1f3f4';
      let color = '#5f6368';
      if (this.status === 'Terpenuhi') {
        bg = '#e6f4ea';
        color = '#1e8e3e';
      } else if (this.status === 'Kurang') {
        bg = '#fce8e6';
        color = '#c5221f';
      } else if (this.status === 'Lebih') {
        bg = '#e8f0fe';
        color = '#1a73e8';
      } else if (this.status === 'In Progress') {
        bg = '#fef7e0';
        color = '#f9ab00';
      }
      return {
        'background-color': bg,
        color: color,
        padding: '2px 10px',
        'border-radius': '12px',
        'font-size': '12px',
        'font-weight': '600',
        'white-space': 'nowrap',
      };
    },
  },
};
</script>
