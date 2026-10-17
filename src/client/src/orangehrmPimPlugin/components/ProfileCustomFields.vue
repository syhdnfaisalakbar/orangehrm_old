<!--
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com/
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
  <div v-if="fields.length !== 0" class="orangehrm-custom-fields">
    <oxd-divider />
    <div class="orangehrm-card-container">
      <oxd-text tag="h6" class="orangehrm-main-title">{{
        $t('pim.custom_fields')
      }}</oxd-text>
      <oxd-divider />
      <oxd-form :loading="isLoading" @submit-valid="onSave">
        <oxd-form-row>
          <oxd-grid :cols="3" class="orangehrm-full-width-grid">
            <oxd-grid-item v-for="field in fields" :key="field.id">
              <oxd-input-field
                v-model="customFieldsModel[field.model]"
                :type="field.type"
                :label="field.label"
                :options="field.extraData"
                :create-options="field.loader"
                :rules="rules.default"
                :disabled="!$can.update(`${screen}_custom_fields`)"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>
        <template v-if="$can.update(`${screen}_custom_fields`)">
          <oxd-divider />
          <oxd-form-actions>
            <submit-button />
          </oxd-form-actions>
        </template>
      </oxd-form>
    </div>
  </div>
</template>

<script>
import {APIService} from '@/core/util/services/api.service';
import {shouldNotExceedCharLength} from '@ohrm/core/util/validation/rules';

const formatExtraData = (data) => {
  return typeof data === 'string'
    ? data
        .split(',')
        .map((item, i) => {
          return {id: i, label: item};
        })
        .filter((item) => item.label.trim() != '')
    : [];
};

export default {
  name: 'ProfileCustomFields',
  props: {
    employeeId: {
      type: String,
      required: true,
    },
    screen: {
      type: String,
      required: true,
    },
  },

  setup(props) {
    const http = new APIService(
      window.appGlobal.baseUrl,
      `/api/v2/pim/employees/${props.employeeId}/custom-fields?screen=${props.screen}`,
    );
    // Dedicated client for the external-API autocomplete proxy.
    const httpOptions = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/pim/custom-field-options',
    );

    return {
      http,
      httpOptions,
    };
  },

  data() {
    return {
      isLoading: false,
      fields: [],
      customFieldsModel: {},
      rules: {
        default: [shouldNotExceedCharLength(250)],
      },
    };
  },

  beforeMount() {
    this.isLoading = true;
    this.http
      .getAll()
      .then((response) => {
        const {data, meta} = response.data;
        this.customFieldsModel = {...data};
        if (meta.fields && meta.fields.length > 0) {
          this.fields = meta.fields.map((field) => {
            const extraData = formatExtraData(field.extraData);
            const model = `custom${field.id}`;
            let type = 'input';
            let loader = undefined;
            if (field.fieldType == 1) {
              type = 'select';
              if (data[model]) {
                const selected = extraData.find((i) => i.label == data[model]);
                this.customFieldsModel[model] = selected || null;
              }
            } else if (field.fieldType == 2) {
              // external-API autocomplete: model holds {id, label}
              type = 'autocomplete';
              loader = this.makeLoader(field.id);
              if (data[model]) {
                this.customFieldsModel[model] = {
                  id: data[model],
                  label: data[model],
                };
              }
            }
            return {
              id: field.id,
              label: field.fieldName,
              type,
              model,
              extraData,
              loader,
            };
          });
        }
      })
      .finally(() => {
        this.isLoading = false;
      });
  },

  methods: {
    makeLoader(fieldId) {
      return (searchParam) => {
        return new Promise((resolve) => {
          if (searchParam && searchParam.trim()) {
            this.httpOptions
              .getAll({fieldId, q: searchParam.trim()})
              .then(({data}) => {
                resolve(
                  (data.data || []).map((option) => {
                    return {id: option.id, label: option.label};
                  }),
                );
              });
          } else {
            resolve([]);
          }
        });
      };
    },

    onSave() {
      this.isLoading = true;
      // For autocomplete (type 2) fields we store the option's `id` (e.g. Customer ID);
      // for select fields we store the option's `label`, as before.
      const autoKeys = new Set(
        this.fields
          .filter((f) => f.type === 'autocomplete')
          .map((f) => f.model),
      );
      this.http
        .request({
          method: 'PUT',
          url: `/api/v2/pim/employees/${this.employeeId}/custom-fields`,
          data: {...this.customFieldsModel},
          transformRequest: [
            (data) => {
              for (const key in data) {
                if (data[key] && typeof data[key] === 'object') {
                  data[key] = autoKeys.has(key)
                    ? data[key].id ?? null
                    : data[key].label ?? null;
                }
              }
              return JSON.stringify(data);
            },
          ],
        })
        .then(() => {
          this.isLoading = false;
          this.$toast.saveSuccess();
        });
    },
  },
};
</script>
