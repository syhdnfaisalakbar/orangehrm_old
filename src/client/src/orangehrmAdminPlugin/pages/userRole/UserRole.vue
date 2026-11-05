<!--
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 */
 -->

<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-paper-container">
      <div class="orangehrm-header-container">
        <oxd-text tag="h6" class="orangehrm-main-title"> User Role </oxd-text>
        <div>
          <oxd-button
            label="Add"
            icon-name="plus"
            display-type="secondary"
            @click="onClickAdd"
          />
        </div>
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
          :loading="isLoading"
          :headers="headers"
          :items="items?.data"
          :selectable="true"
          :clickable="false"
          row-decorator="oxd-table-decorator-card"
        />
      </div>

      <div class="orangehrm-bottom-container orangehrm-user-role-bottom">
        <oxd-text tag="p" class="orangehrm-user-role-count">
          Menampilkan {{ rangeStart }}–{{ rangeEnd }} dari {{ total }} role
        </oxd-text>
        <div class="orangehrm-user-role-bottom-right">
          <div class="orangehrm-user-role-page-size">
            <oxd-text tag="p">Baris per halaman</oxd-text>
            <span
              v-for="size in pageSizeOptions"
              :key="size"
              class="orangehrm-user-role-page-size-chip"
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

    <user-role-permission
      v-if="permissionRole"
      :role-id="permissionRole.id"
      :role-name="permissionRole.displayName || permissionRole.name"
      @close="permissionRole = null"
    />

    <oxd-dialog
      v-if="showForm"
      class="orangehrm-dialog-modal"
      @update:show="closeForm"
    >
      <div class="orangehrm-modal-header">
        <oxd-text tag="h6">
          {{ isEditing ? 'Edit User Role' : 'Add User Role' }}
        </oxd-text>
      </div>
      <oxd-divider />
      <oxd-form ref="roleForm" :loading="saveLoading" @submit-valid="onSave">
        <oxd-form-row>
          <oxd-input-field
            v-model="role.name"
            label="Role Name"
            :rules="rules.name"
            placeholder="e.g. Recruiter"
            :disabled="isEditing && role.isPredefined"
            required
          />
        </oxd-form-row>
        <oxd-form-row>
          <oxd-input-field
            v-model="role.displayName"
            label="Display Name"
            :rules="rules.displayName"
            placeholder="e.g. Recruiter"
            required
          />
        </oxd-form-row>
        <oxd-form-row>
          <oxd-input-field
            v-model="role.isAssignable"
            type="checkbox"
            label="Bisa dipilih di form User"
          />
        </oxd-form-row>
        <oxd-form-actions>
          <oxd-button display-type="ghost" label="Cancel" @click="closeForm" />
          <submit-button :label="isEditing ? 'Save' : 'Add'" />
        </oxd-form-actions>
      </oxd-form>
    </oxd-dialog>
  </div>
</template>

<script>
import usePaginate from '@ohrm/core/util/composable/usePaginate';
import DeleteConfirmationDialog from '@ohrm/components/dialogs/DeleteConfirmationDialog';
import UserRolePermissionDialog from './UserRolePermissionDialog.vue';
import {APIService} from '@ohrm/core/util/services/api.service';
import {required} from '@ohrm/core/util/validation/rules';

export default {
  components: {
    'delete-confirmation': DeleteConfirmationDialog,
    'user-role-permission': UserRolePermissionDialog,
  },

  setup() {
    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/admin/user-roles',
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
    } = usePaginate(http, {pageSize: 5});

    return {
      http,
      showPaginator,
      currentPage,
      isLoading,
      total,
      pages,
      pageSize,
      pageSizeOptions: [5, 10, 25, 50],
      execQuery,
      items: response,
    };
  },

  data() {
    return {
      headers: [
        {
          name: 'name',
          slot: 'title',
          title: 'Role',
          style: {'flex-basis': '30%'},
        },
        {
          name: 'displayName',
          title: 'Display Name',
          style: {'flex-basis': '25%'},
        },
        {
          name: 'userCount',
          title: 'Users',
          style: {'flex-basis': '15%'},
        },
        {
          name: 'isAssignable',
          title: 'Assignable',
          style: {'flex-basis': '15%'},
        },
        {
          name: 'actions',
          slot: 'action',
          title: 'Actions',
          style: {'flex-shrink': 1},
          cellType: 'oxd-table-cell-actions',
          cellConfig: {
            permissions: {
              onClick: this.onClickPermissions,
              component: 'oxd-icon-button',
              props: {
                // Ikon kunci = atur hak akses; teks muncul sebagai tooltip.
                name: 'key',
                title: 'Hak Akses',
              },
            },
            edit: {
              onClick: this.onClickEdit,
              component: 'oxd-icon-button',
              props: {name: 'pencil-fill', title: 'Edit'},
            },
            delete: {
              onClick: this.onClickDelete,
              component: 'oxd-icon-button',
              props: {name: 'trash', title: 'Hapus'},
            },
          },
        },
      ],
      checkedItems: [],
      permissionRole: null,
      showForm: false,
      isEditing: false,
      saveLoading: false,
      role: {
        id: null,
        name: '',
        displayName: '',
        isAssignable: true,
      },
      rules: {
        name: [required],
        displayName: [required],
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

  methods: {
    onClickAdd() {
      this.isEditing = false;
      this.role = {id: null, name: '', displayName: '', isAssignable: true};
      this.showForm = true;
    },
    onClickEdit(item) {
      this.isEditing = true;
      this.role = {
        id: item.id,
        name: item.name,
        displayName: item.displayName,
        isAssignable: item.isAssignable,
      };
      this.showForm = true;
    },
    closeForm() {
      this.showForm = false;
    },
    onClickPermissions(item) {
      this.permissionRole = {
        id: item.id,
        name: item.name,
        displayName: item.displayName,
      };
    },
    onSave() {
      if (this.saveLoading) return;
      this.saveLoading = true;
      const payload = {
        name: this.role.name,
        displayName: this.role.displayName,
        isAssignable: this.role.isAssignable,
      };
      const request = this.isEditing
        ? this.http.update(this.role.id, payload)
        : this.http.create(payload);

      request
        .then(() => {
          this.$toast.saveSuccess();
          this.showForm = false;
          return this.execQuery();
        })
        .catch((error) => {
          const data = error?.response?.data;
          const message =
            data?.error?.data?.invalidParamKeys?.length > 0
              ? 'Data tidak valid.'
              : data?.error?.message ||
                data?.message ||
                'Gagal menyimpan role.';
          this.$toast.error({title: 'Error', message});
        })
        .finally(() => {
          this.saveLoading = false;
        });
    },
    onClickDeleteSelected() {
      const ids = [];
      this.checkedItems.forEach((index) => {
        ids.push(this.items?.data[index].id);
      });
      this.$refs.deleteDialog.showDialog().then((confirmation) => {
        if (confirmation === 'ok') {
          this.deleteItems(ids);
        }
      });
    },
    onClickDelete(item) {
      this.$refs.deleteDialog.showDialog().then((confirmation) => {
        if (confirmation === 'ok') {
          this.deleteItems([item.id]);
        }
      });
    },
    deleteItems(ids) {
      if (!(ids instanceof Array) || ids.length === 0) return;
      this.isLoading = true;
      this.http
        .deleteAll({ids})
        .then((response) => {
          const skipped = response?.data?.data?.skipped || [];
          if (skipped.length > 0) {
            this.$toast.warn({
              title: 'Sebagian tidak dihapus',
              message: skipped.map((r) => `${r.name}: ${r.reason}`).join(', '),
            });
          } else {
            this.$toast.deleteSuccess();
          }
          this.checkedItems = [];
          return this.execQuery();
        })
        .catch((error) => {
          const message =
            error?.response?.data?.error?.message || 'Gagal menghapus role.';
          this.$toast.error({title: 'Error', message});
        })
        .finally(() => {
          this.isLoading = false;
        });
    },
  },
};
</script>

<style src="./user-role.scss" lang="scss" scoped></style>
