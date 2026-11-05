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
  <div class="orangehrm-background-container">
    <oxd-table-filter :filter-title="$t('admin.system_users')">
      <oxd-form @submit-valid="filterItems">
        <oxd-form-row>
          <oxd-grid :cols="4" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.username"
                :label="$t('general.username')"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.userRoleId"
                type="select"
                :label="$t('general.user_role')"
                :options="userRoles"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <employee-autocomplete
                v-model="filters.empNumber"
                :rules="rules.employee"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.status"
                type="select"
                :label="$t('general.status')"
                :options="userStatuses"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>

        <oxd-divider />

        <oxd-form-actions>
          <oxd-button
            display-type="ghost"
            :label="$t('general.reset')"
            @click="onClickReset"
          />
          <oxd-button
            class="orangehrm-left-space"
            display-type="secondary"
            :label="$t('general.search')"
            type="submit"
          />
        </oxd-form-actions>
      </oxd-form>
    </oxd-table-filter>
    <br />
    <div class="orangehrm-paper-container">
      <div class="orangehrm-header-container">
        <oxd-button
          :label="$t('general.add')"
          icon-name="plus"
          display-type="secondary"
          @click="onClickAdd"
        />
      </div>
      <table-header
        :selected="checkedItems.length"
        :total="total"
        :loading="isLoading"
        @delete="onClickDeleteSelected"
      ></table-header>
      <div class="orangehrm-container">
        <oxd-card-table
          v-model:selected="checkedItems"
          v-model:order="sortDefinition"
          :headers="headers"
          :items="items?.data"
          :selectable="true"
          :clickable="false"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
        />
      </div>
      <div class="orangehrm-bottom-container orangehrm-system-user-bottom">
        <oxd-text tag="p" class="orangehrm-system-user-count">
          Menampilkan {{ rangeStart }}–{{ rangeEnd }} dari {{ total }} user
        </oxd-text>
        <div class="orangehrm-system-user-bottom-right">
          <div class="orangehrm-system-user-page-size">
            <oxd-text tag="p">Baris per halaman</oxd-text>
            <span
              v-for="size in pageSizeOptions"
              :key="size"
              class="orangehrm-system-user-page-size-chip"
              :class="{'--active': pageSize === size}"
              @click="pageSize = size"
            >
              {{ size }}
            </span>
          </div>
          <oxd-pagination
            v-if="showPaginator"
            v-model:current="currentPage"
            :length="pages"
          />
        </div>
      </div>
    </div>
    <delete-confirmation ref="deleteDialog"></delete-confirmation>
  </div>
</template>

<script>
import {computed, ref} from 'vue';
import DeleteConfirmationDialog from '@ohrm/components/dialogs/DeleteConfirmationDialog';
import usePaginate from '@ohrm/core/util/composable/usePaginate';
import {navigate} from '@ohrm/core/util/helper/navigation';
import {APIService} from '@/core/util/services/api.service';
import EmployeeAutocomplete from '@/core/components/inputs/EmployeeAutocomplete';
import useSort from '@ohrm/core/util/composable/useSort';
import {validSelection} from '@/core/util/validation/rules';
import useEmployeeNameTranslate from '@/core/util/composable/useEmployeeNameTranslate';
import usei18n from '@/core/util/composable/usei18n';

const defaultFilters = {
  username: '',
  userRoleId: null,
  empNumber: null,
  status: null,
};

const defaultSortOrder = {
  'u.userName': 'ASC',
  'r.displayName': 'ASC',
  'e.firstName': 'ASC',
  'u.status': 'DEFAULT',
};

export default {
  components: {
    'delete-confirmation': DeleteConfirmationDialog,
    'employee-autocomplete': EmployeeAutocomplete,
  },
  props: {
    unselectableIds: {
      type: Array,
      default: () => [],
    },
  },

  setup(props) {
    const {$t} = usei18n();
    const {$tEmpName} = useEmployeeNameTranslate();
    const userdataNormalizer = (data) => {
      return data.map((item) => {
        const selectable = props.unselectableIds.findIndex(
          (id) => id == item.id,
        );
        return {
          id: item.id,
          userName: item.userName,
          // Tampilkan role apa adanya. Sebelumnya semua role non-Admin
          // dipaksa tampil "ESS" (role seperti BOD/MPP jadi salah label).
          role: item.userRole?.displayName || item.userRole?.name || '',
          empName: $tEmpName(item.employee, {includeMiddle: false}),
          status: item.status ? $t('general.enabled') : $t('general.disabled'),
          isSelectable: selectable === -1,
        };
      });
    };

    const filters = ref({...defaultFilters});

    const {sortDefinition, sortField, sortOrder, onSort} = useSort({
      sortDefinition: defaultSortOrder,
    });
    const serializedFilters = computed(() => {
      return {
        username: filters.value.username,
        userRoleId: filters.value.userRoleId?.id,
        empNumber: filters.value.empNumber?.id,
        status: filters.value.status?.id,
        sortField: sortField.value,
        sortOrder: sortOrder.value,
      };
    });
    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/admin/users',
    );
    const {
      showPaginator,
      currentPage,
      total,
      pages,
      pageSize,
      response,
      isLoading,
      execQuery,
    } = usePaginate(http, {
      query: serializedFilters,
      normalizer: userdataNormalizer,
      pageSize: 5,
    });

    onSort(execQuery);

    return {
      http,
      showPaginator,
      currentPage,
      isLoading,
      total,
      pages,
      pageSize,
      execQuery,
      items: response,
      pageSizeOptions: [5, 10, 25, 50],
      filters,
      sortDefinition,
    };
  },

  data() {
    return {
      headers: [
        {
          name: 'userName',
          title: this.$t('general.username'),
          sortField: 'u.userName',
          style: {flex: 1},
        },
        {
          name: 'role',
          title: this.$t('general.user_role'),
          style: {flex: 1},
          sortField: 'r.displayName',
        },
        {
          name: 'empName',
          slot: 'title',
          title: this.$t('general.employee_name'),
          sortField: 'e.firstName',
          style: {flex: 1},
        },
        {
          name: 'status',
          title: this.$t('general.status'),
          sortField: 'u.status',
          style: {flex: 1},
        },
        {
          name: 'actions',
          slot: 'action',
          title: this.$t('general.actions'),
          style: {flex: 1},
          cellType: 'oxd-table-cell-actions',
          cellConfig: {
            delete: {
              onClick: this.onClickDelete,
              component: 'oxd-icon-button',
              props: {
                name: 'trash',
              },
            },
            edit: {
              onClick: this.onClickEdit,
              props: {
                name: 'pencil-fill',
              },
            },
          },
        },
      ],
      // Diisi dari /api/v2/admin/user-roles (lihat loadUserRoles)
      userRoles: [
        {id: 1, label: this.$t('general.admin')},
        {id: 2, label: this.$t('general.ess')},
      ],
      userStatuses: [
        {id: 1, label: this.$t('general.enabled')},
        {id: 0, label: this.$t('general.disabled')},
      ],
      checkedItems: [],
      rules: {
        employee: [validSelection],
      },
    };
  },

  computed: {
    rangeStart() {
      if (!this.total) return 0;
      return (this.currentPage - 1) * this.pageSize + 1;
    },
    rangeEnd() {
      return Math.min(this.currentPage * this.pageSize, this.total);
    },
  },

  // 👇==== TAMBAHKAN BLOK MOUNTED INI ====👇
  mounted() {
    this.loadUserRoles();
    setTimeout(() => {
      // Pastikan trik ini HANYA berjalan jika URL-nya murni Admin (tidak mengandung kata 'page=')
      if (!window.location.href.includes('page=')) {
        const allMenus = document.querySelectorAll('.oxd-main-menu-item');

        // Hapus warna hijau dari semua menu
        allMenus.forEach((menu) => menu.classList.remove('active'));

        // Kembalikan warna hijau HANYA ke menu yang bernama 'Admin'
        allMenus.forEach((menu) => {
          if (menu.textContent.trim() === 'Admin') {
            menu.classList.add('active');
          }
        });
      }
    }, 150);
  },
  // 👆===================================👆

  methods: {
    /**
     * Filter "User Role" harus memuat semua role (termasuk role baru seperti BOD),
     * jadi opsinya diambil dari API, bukan daftar tetap Admin + ESS.
     */
    loadUserRoles() {
      const http = new APIService(
        window.appGlobal.baseUrl,
        '/api/v2/admin/user-roles',
      );
      http
        .getAll({limit: 100, offset: 0})
        .then((response) => {
          const roles = response.data?.data ?? [];
          if (roles.length) {
            this.userRoles = roles.map((role) => ({
              id: role.id,
              label: role.displayName || role.name,
            }));
          }
        })
        .catch(() => {
          // biarkan daftar default kalau API gagal
        });
    },
    onClickAdd() {
      navigate('/admin/saveSystemUser');
    },
    onClickEdit(item) {
      navigate('/admin/saveSystemUser/{id}', {id: item.id});
    },
    onClickDeleteSelected() {
      const ids = this.checkedItems.map((index) => {
        return this.items?.data[index].id;
      });
      this.$refs.deleteDialog.showDialog().then((confirmation) => {
        if (confirmation === 'ok') {
          this.deleteItems(ids);
        }
      });
    },
    onClickDelete(item) {
      const isSelectable = this.unselectableIds.findIndex(
        (id) => id == item.id,
      );
      if (isSelectable > -1) {
        return this.$toast.cannotDelete();
      }
      this.$refs.deleteDialog.showDialog().then((confirmation) => {
        if (confirmation === 'ok') {
          this.deleteItems([item.id]);
        }
      });
    },
    deleteItems(items) {
      if (items instanceof Array) {
        this.isLoading = true;
        this.http
          .deleteAll({
            ids: items,
          })
          .then(() => {
            return this.$toast.deleteSuccess();
          })
          .then(() => {
            this.isLoading = false;
            this.resetDataTable();
          });
      }
    },
    async resetDataTable() {
      this.checkedItems = [];
      await this.execQuery();
    },
    async filterItems() {
      await this.execQuery();
    },
    onClickReset() {
      this.filters = {...defaultFilters};
      this.filterItems();
    },
  },
};
</script>

<style lang="scss" scoped>
@import './system-user.scss';
</style>
