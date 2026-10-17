<!--
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA  02110-1301, USA
 */
-->
<template>
  <span class="vacancy-status-badge" :class="badgeClass">
    {{ status }}
  </span>
</template>

<script>
export default {
  name: 'VacancyStatusBadge',
  props: {
    status: {
      type: String,
      default: '',
    },
    // Boleh dikirim eksplisit (boolean). Kalau null/undefined,
    // otomatis deteksi dari string status: 'Closed'/'close' = closed, selain itu active.
    active: {
      type: Boolean,
      default: null,
    },
  },
  computed: {
    isActive() {
      if (this.active !== null && this.active !== undefined) {
        return this.active;
      }
      const s = String(this.status || '').toLowerCase();
      return s !== 'closed' && s !== 'close';
    },
    badgeClass() {
      const s = String(this.status || '').toLowerCase();
      if (s === 'rejected') return 'badge-rejected';
      return this.isActive ? 'badge-active' : 'badge-closed';
    },
  },
};
</script>

<style scoped>
.vacancy-status-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.5;
  white-space: nowrap;
}

.badge-active {
  background-color: #dbeafe;
  color: #1e40af;
}

.badge-closed {
  background-color: #e6f4ea;
  color: #1e8e3e;
}

.badge-rejected {
  background-color: #fce8e6;
  color: #c5221f;
}
</style>
