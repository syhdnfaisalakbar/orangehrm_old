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
  <oxd-input-field
    v-model="value"
    type="autocomplete"
    :label="$t('recruitment.candidate_name')"
    :clear="false"
    :create-options="loadCandidates"
  >
    <template #option="{data}">
      <span>{{ data.label }}</span>
    </template>
  </oxd-input-field>
</template>

<script>
import {APIService} from '@ohrm/core/util/services/api.service';
import {computed} from 'vue';
export default {
  name: 'CandidateAutocomplete',
  props: {
    modelValue: {
      type: [Object, Number, String],
      required: false,
      default: null,
    },
    params: {
      type: Object,
      default: () => ({}),
    },
  },
  emits: ['update:modelValue'],
  setup(props, {emit}) {
    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/recruitment/candidates',
    );
    const value = computed({
      get: () => props.modelValue,
      set: (val) => emit('update:modelValue', val),
    });
    return {
      http,
      value,
    };
  },
  methods: {
    async loadCandidates(serachParam) {
      return new Promise((resolve) => {
        if (serachParam.trim()) {
          this.http
            .getAll({
              candidateName: serachParam.trim(),
              ...this.params,
            })
            .then(({data}) => {
              resolve(
                data.data.map((candidate) => {
                  return {
                    id: candidate.id,
                    label: `${candidate.firstName} ${
                      candidate.middleName || ''
                    } ${candidate.lastName}`,
                    _candidate: candidate,
                  };
                }),
              );
            });
        } else {
          resolve([]);
        }
      });
    },
  },
};
</script>

<style scoped>
.past-employee-tag {
  margin-left: auto;
}
</style>
