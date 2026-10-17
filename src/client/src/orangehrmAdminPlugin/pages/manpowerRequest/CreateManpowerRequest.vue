<template>
  <div class="orangehrm-background-container">
    <div class="orangehrm-card-container">
      <h6 class="oxd-text oxd-text--h6 orangehrm-main-title">
        Create Manpower Request (FPTK)
      </h6>

      <hr class="oxd-divider" />

      <oxd-form @submit-valid="onSave">
        <oxd-form-row>
          <oxd-grid :cols="2" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <oxd-input-field
                v-model="form.department"
                label="Department"
                required
                placeholder="Ex: Information Technology"
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.position"
                label="Position Title"
                required
                placeholder="Ex: Software Engineer"
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.requestType"
                type="select"
                label="Request Type"
                required
                :options="requestTypeOptions"
                @update:model-value="onRequestTypeChange"
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.totPerson"
                label="Total Person Needed"
                required
                :rules="rules.totPerson"
              />
            </oxd-grid-item>

            <!-- case_id: muncul hanya jika Request Type = Planned -->
            <oxd-grid-item v-if="showCaseId">
              <oxd-input-field
                v-model="form.caseId"
                type="select"
                label="Case ID (Quota Request)"
                :options="quotaRequestOptions"
                :rules="rules.caseId"
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.gender"
                type="select"
                label="Gender"
                :options="genderOptions"
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.employmentStatus"
                type="select"
                label="Employment Status"
                :options="employmentStatusOptions"
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.skill"
                label="Skill"
                placeholder="Ex: PHP, JavaScript, Vue.js"
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.targetDate"
                type="date"
                label="Target Date"
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.jobDesc"
                type="textarea"
                label="Job Description"
                placeholder="Describe the job responsibilities..."
              />
            </oxd-grid-item>

            <oxd-grid-item>
              <oxd-input-field
                v-model="form.reason"
                type="textarea"
                label="Reason / Justification"
                placeholder="Type your reason here..."
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>

        <oxd-divider />

        <oxd-form-actions>
          <oxd-button display-type="ghost" label="Cancel" @click="onCancel" />
          <oxd-button
            class="orangehrm-left-space"
            display-type="secondary"
            label="Save"
            type="submit"
            :loading="isSaving"
          />
        </oxd-form-actions>
      </oxd-form>

      <div
        v-if="errorMsg"
        class="oxd-alert oxd-alert--danger"
        style="margin-top: 12px"
      >
        <div class="oxd-alert-content">{{ errorMsg }}</div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'CreateManpowerRequest',
  data() {
    return {
      isSaving: false,
      errorMsg: '',
      showCaseId: false,
      quotaRequestOptions: [],
      rules: {
        totPerson: [
          (v) => !!v || 'Total person is required',
          (v) => /^\d+$/.test(v) || 'Must be a valid number',
          (v) => parseInt(v) > 0 || 'Must be at least 1',
        ],
        caseId: [(v) => !!v || 'Please select a Case ID for Planned request'],
      },
      requestTypeOptions: [
        {id: 'Planned', label: 'Planned'},
        {id: 'Replacement', label: 'Replacement'},
        {id: 'Unplanned', label: 'Unplanned'},
      ],
      genderOptions: [
        {id: 'Male', label: 'Male'},
        {id: 'Female', label: 'Female'},
        {id: 'Any', label: 'Any'},
      ],
      employmentStatusOptions: [
        {id: 'Permanent', label: 'Permanent'},
        {id: 'Contract', label: 'Contract'},
        {id: 'Intern', label: 'Intern'},
        {id: 'Outsource', label: 'Outsource'},
      ],
      form: {
        department: '',
        position: '',
        requestType: 'Planned',
        totPerson: 1,
        caseId: null,
        gender: null,
        employmentStatus: null,
        skill: '',
        targetDate: '',
        jobDesc: '',
        reason: '',
      },
    };
  },
  mounted() {
    setTimeout(() => {
      const breadcrumb = document.querySelector(
        '.oxd-topbar-header-breadcrumb',
      );
      if (breadcrumb) {
        breadcrumb.innerHTML = `
          <h6 class="oxd-text oxd-text--h6 oxd-topbar-header-breadcrumb-module">
            Manpower Request (FPTK)
          </h6>
          <h6 class="oxd-text oxd-text--h6 oxd-topbar-header-breadcrumb-level">
            / Create
          </h6>
        `;
      }
      document.title = 'Create Manpower Request (FPTK)';

      const allMenus = document.querySelectorAll('.oxd-main-menu-item');
      allMenus.forEach((menu) => menu.classList.remove('active'));
      allMenus.forEach((menu) => {
        if (menu.textContent.trim() === 'Manpower Request (FPTK)') {
          menu.classList.add('active');
        }
      });
    }, 150);

    // Load case_id options if initial request type is Planned
    if (this.form.requestType === 'Planned') {
      this.loadQuotaRequests();
    }
  },
  methods: {
    getBaseUrl() {
      return window.appGlobal?.baseUrl || '';
    },
    onRequestTypeChange(val) {
      this.showCaseId = val === 'Planned';
      if (val === 'Planned') {
        this.loadQuotaRequests();
      } else {
        this.form.caseId = null;
      }
    },
    async loadQuotaRequests() {
      try {
        const baseUrl = this.getBaseUrl();
        const res = await fetch(baseUrl + '/admin/saveManpowerRequest?search=');
        if (res.ok) {
          const result = await res.json();
          this.quotaRequestOptions = (result.data || []).map((item) => ({
            id: item.id,
            label:
              item.request_no +
              ' — ' +
              item.department +
              ' (' +
              item.position_name +
              ')',
          }));
          this.showCaseId = true;
        }
      } catch (e) {
        console.warn('Failed to load quota requests:', e);
      }
    },
    onCancel() {
      const baseUrl = this.getBaseUrl();
      window.location.href =
        baseUrl + '/admin/viewSystemUsers?page=manpower-request';
    },
    async onSave() {
      this.isSaving = true;
      this.errorMsg = '';

      try {
        const baseUrl = this.getBaseUrl();
        const payload = {
          department: this.form.department,
          position_name: this.form.position,
          request_type: this.form.requestType,
          quantity: Number(this.form.totPerson),
          case_id: this.form.caseId,
          gender: this.form.gender,
          employment_status: this.form.employmentStatus,
          skill: this.form.skill,
          job_description: this.form.jobDesc,
          description: this.form.reason,
          target_date: this.form.targetDate || null,
        };

        const res = await fetch(baseUrl + '/admin/saveManpowerRequest', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload),
        });
        if (!res.ok) {
          const errData = await res.json().catch(() => ({}));
          throw new Error(
            errData?.error?.message ||
              'Failed to save request (HTTP ' + res.status + ')',
          );
        }

        window.location.href =
          baseUrl + '/admin/viewSystemUsers?page=manpower-request';
      } catch (e) {
        this.errorMsg = e.message || 'Terjadi kesalahan saat menyimpan';
        this.isSaving = false;
      }
    },
  },
};
</script>
