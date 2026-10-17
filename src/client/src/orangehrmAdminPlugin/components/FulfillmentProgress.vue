<template>
  <div class="fulfillment-progress">
    <div class="fp-bar">
      <div class="fp-fill" :class="barClass" :style="{width: barWidth}"></div>
    </div>
    <span class="fp-text">{{ displayText }}</span>
  </div>
</template>

<script>
export default {
  name: 'FulfillmentProgress',
  props: {
    // Pemenuhan dalam persen (0-100+), null = N/A (quota 0/negatif)
    percent: {
      type: Number,
      default: null,
    },
  },
  computed: {
    hasValue() {
      return this.percent !== null && this.percent !== undefined;
    },
    displayText() {
      if (!this.hasValue) return 'N/A';
      return Math.round(this.percent * 10) / 10 + '%';
    },
    barWidth() {
      if (!this.hasValue) return '0%';
      return Math.min(100, Math.max(0, this.percent)) + '%';
    },
    barClass() {
      if (!this.hasValue) return 'fp-na';
      if (this.percent < 50) return 'fp-low';
      if (this.percent < 100) return 'fp-mid';
      if (this.percent === 100) return 'fp-full';
      return 'fp-over';
    },
  },
};
</script>

<style scoped>
.fulfillment-progress {
  display: flex;
  align-items: center;
  gap: 8px;
}

.fp-bar {
  width: 80px;
  height: 8px;
  border-radius: 4px;
  background-color: #e9ecef;
  overflow: hidden;
  flex-shrink: 0;
}

.fp-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.3s ease;
}

.fp-na {
  background-color: #ced4da;
}

.fp-low {
  background-color: #e74c3c;
}

.fp-mid {
  background-color: #f5a623;
}

.fp-full {
  background-color: #28a745;
}

.fp-over {
  background-color: #1a73e8;
}

.fp-text {
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
</style>
