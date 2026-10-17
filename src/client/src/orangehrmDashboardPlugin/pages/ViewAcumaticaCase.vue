<template>
  <div class="page-container">
    <CaseListCard :case-list="casesData" />
  </div>
</template>

<script setup>
import {ref, onMounted} from 'vue';
import CaseListCard from './CaseListCard.vue';

const casesData = ref([]);

onMounted(async () => {
  try {
    const response = await fetch(
      "https://asg.acumatica.com/entity/Default/25.200.001/Case?$filter=Status eq 'Open' or Status eq 'New'&$expand=Attributes,Activities",
    );
    const data = await response.json();
    casesData.value = data;
  } catch (error) {
    console.error('Gagal mengambil data cases:', error);
  }
});
</script>

<style scoped>
.page-container {
  padding: 20px;
  background-color: #f8fafc;
  min-height: 100vh;
}
</style>
