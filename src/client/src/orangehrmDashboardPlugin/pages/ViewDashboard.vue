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
  <oxd-grid class="orangehrm-dashboard-grid" :cols="3">
    <oxd-grid-item class="orangehrm-dashboard-widget">
      <resource-fulfillment-widget></resource-fulfillment-widget>
    </oxd-grid-item>
    <oxd-grid-item
      v-if="$can.read('dashboard_time_widget')"
      class="orangehrm-dashboard-widget"
    >
      <employee-attendance-widget></employee-attendance-widget>
    </oxd-grid-item>
    <oxd-grid-item class="orangehrm-dashboard-widget">
      <my-action-summary-widget></my-action-summary-widget>
    </oxd-grid-item>
    <oxd-grid-item class="orangehrm-dashboard-widget">
      <quick-launch-widget></quick-launch-widget>
    </oxd-grid-item>
    <oxd-grid-item
      v-if="$can.read('dashboard_subunit_widget')"
      class="orangehrm-dashboard-widget"
    >
      <employee-subunit-widget></employee-subunit-widget>
    </oxd-grid-item>
    <oxd-grid-item
      v-if="$can.read('dashboard_location_widget')"
      class="orangehrm-dashboard-widget"
    >
      <employee-location-widget></employee-location-widget>
    </oxd-grid-item>
  </oxd-grid>
</template>

<script>
import {APIService} from '@/core/util/services/api.service';
import QuickLaunchWidget from '@/orangehrmDashboardPlugin/components/QuickLaunchWidget.vue';
import EmployeeSubunitWidget from '@/orangehrmDashboardPlugin/components/EmployeeSubunitWidget.vue';
import MyActionSummaryWidget from '@/orangehrmDashboardPlugin/components/MyActionSummaryWidget.vue';
import EmployeeLocationWidget from '@/orangehrmDashboardPlugin/components/EmployeeLocationWidget.vue';
import EmployeeAttendanceWidget from '@/orangehrmDashboardPlugin/components/EmployeeAttendanceWidget.vue';
import ResourceFulfillmentWidget from '@/orangehrmDashboardPlugin/components/ResourceFulfillmentWidget.vue';

export default {
  components: {
    'resource-fulfillment-widget': ResourceFulfillmentWidget,
    'quick-launch-widget': QuickLaunchWidget,
    'employee-subunit-widget': EmployeeSubunitWidget,
    'my-action-summary-widget': MyActionSummaryWidget,
    'employee-location-widget': EmployeeLocationWidget,
    'employee-attendance-widget': EmployeeAttendanceWidget,
  },
  mounted() {
    const http = new APIService(window.appGlobal.baseUrl, 'events/push');
    http.create();
  },
};
</script>

<style lang="scss" scoped>
.orangehrm-dashboard-grid {
  margin: 0 auto;
  box-sizing: border-box;
  max-width: calc(350px * 3);
  grid-template-columns: repeat(auto-fill, minmax(max(320px, 100%/3), 1fr));
}
</style>
