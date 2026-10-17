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
  <!-- Hanya status Hired (id 9) yang dirender sebagai badge hijau;
       status lainnya tetap teks biasa. -->
  <span :class="{'candidate-status-badge': isHired}">
    {{ label }}
  </span>
</template>

<script>
export default {
  name: 'CandidateStatusBadge',
  props: {
    label: {
      type: String,
      default: '',
    },
    statusId: {
      type: Number,
      default: null,
    },
  },
  computed: {
    isHired() {
      return Number(this.statusId) === 9;
    },
  },
};
</script>

<style scoped>
.candidate-status-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.5;
  white-space: nowrap;
  background-color: #e6f4ea;
  color: #1e8e3e;
}
</style>
