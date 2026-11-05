<!--
/**
 * Dropdown filter "Acumatica Task Status" pada daftar kandidat.
 * Nilai mengikuti Status Task di Acumatica: Open / Processing / Canceled.
 * Nilainya dipakai sebagai query param `acumaticaTaskStatus` ke API kandidat.
 */
-->
<template>
  <oxd-input-field
    v-model="value"
    type="select"
    label="Acumatica Task Status"
    :options="options"
    :clearable="true"
  />
</template>

<script>
import {computed} from 'vue';

const TASK_STATUS_OPTIONS = [
  {id: 'Open', label: 'Open'},
  {id: 'Processing', label: 'Processing'},
  {id: 'Canceled', label: 'Canceled'},
];

export default {
  name: 'AcumaticaTaskStatusDropdown',
  props: {
    modelValue: {
      type: [Object, String],
      required: false,
      default: null,
    },
  },
  emits: ['update:modelValue'],
  setup(props, {emit}) {
    const options = TASK_STATUS_OPTIONS;
    const value = computed({
      get: () => props.modelValue,
      set: (val) => emit('update:modelValue', val),
    });
    return {
      options,
      value,
    };
  },
};
</script>
