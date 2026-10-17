<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-paper-container">
      <div class="orangehrm-header-container">
        <oxd-button
          label="Add"
          icon-name="plus"
          display-type="secondary"
          @click="onClickAdd"
        />
      </div>

      <table-header
        :selected="checkedItems.length"
        :total="items.length"
        :loading="isLoading"
        @delete="onClickDeleteSelected"
      />

      <div class="orangehrm-container">
        <oxd-card-table
          v-model:selected="checkedItems"
          :headers="headers"
          :items="items"
          :selectable="true"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
        />
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ViewJobVacancies',
  data() {
    return {
      isLoading: false,
      checkedItems: [],
      headers: [
        {name: 'vacancy', title: 'Vacancy', style: {flex: 2}},
        {name: 'department', title: 'Department', style: {flex: 1.5}},
        {name: 'status', title: 'Status', style: {flex: 1}},
        {name: 'positions', title: 'Positions', style: {flex: 1}},
        {
          name: 'actions',
          slot: 'action',
          title: 'Actions',
          style: {flex: 1},
          cellType: 'oxd-table-cell-actions',
          cellConfig: {
            edit: {onClick: this.onClickEdit, props: {name: 'pencil-fill'}},
            delete: {
              onClick: this.onClickDelete,
              component: 'oxd-icon-button',
              props: {name: 'trash'},
            },
          },
        },
      ],
      items: [
        {
          id: 1,
          vacancy: 'Software Engineer',
          department: 'IT',
          status: 'Open',
          positions: 3,
        },
        {
          id: 2,
          vacancy: 'HR Staff',
          department: 'HRD',
          status: 'Open',
          positions: 1,
        },
        {
          id: 3,
          vacancy: 'Sales Executive',
          department: 'Sales',
          status: 'Closed',
          positions: 0,
        },
      ],
    };
  },
  mounted() {
    setTimeout(() => {
      const breadcrumb = document.querySelector(
        '.oxd-topbar-header-breadcrumb',
      );
      if (breadcrumb) {
        breadcrumb.innerHTML = `<h6 class="oxd-text oxd-text--h6 oxd-topbar-header-breadcrumb-module">Job Vacancies</h6>`;
      }
      document.title = 'Job Vacancies';
      const allMenus = document.querySelectorAll('.oxd-main-menu-item');
      allMenus.forEach((menu) => menu.classList.remove('active'));
      allMenus.forEach((menu) => {
        if (menu.textContent.trim() === 'Job Vacancies') {
          menu.classList.add('active');
        }
      });
    }, 150);
  },
  methods: {
    getBaseUrl() {
      return window.appGlobal?.baseUrl || '';
    },
    onClickAdd() {
      alert('Add feature coming soon');
    },
    onClickEdit(item) {
      alert('Edit: ' + item.vacancy);
    },
    onClickDelete(item) {
      if (confirm('Delete ' + item.vacancy + '?')) {
        this.items = this.items.filter((i) => i.id !== item.id);
      }
    },
    onClickDeleteSelected() {
      if (this.checkedItems.length > 0) {
        alert(this.checkedItems.length + ' selected for delete');
      }
    },
  },
};
</script>
