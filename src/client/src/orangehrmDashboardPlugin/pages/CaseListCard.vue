<template>
  <div class="cases-container">
    <div class="cases-header">
      <h2>Daftar Case (Acumatica ERP)</h2>
      <span class="total-badge">Total: {{ caseList.length }}</span>
    </div>

    <div v-if="!caseList.length" class="empty-state">
      Tidak ada data case ditemukan.
    </div>

    <div v-else class="case-cards-list">
      <div v-for="item in caseList" :key="item.id" class="case-card">
        <!-- HEADER CASE -->
        <div class="card-header">
          <div class="header-main">
            <span class="case-id">CASE #{{ item.CaseID?.value }}</span>
            <h3 class="case-subject">{{ item.Subject?.value }}</h3>
          </div>
          <div class="header-tags">
            <span
              class="badge status"
              :class="item.Status?.value?.toLowerCase()"
            >
              {{ item.Status?.value }}
            </span>
            <span
              v-if="item.Priority?.value"
              class="badge priority"
              :class="item.Priority?.value?.toLowerCase()"
            >
              {{ item.Priority?.value }}
            </span>
          </div>
        </div>

        <!-- INFORMASI UTAMA & DESKRIPSI -->
        <div class="card-body">
          <div class="info-grid">
            <div class="info-item">
              <span class="label">Tanggal Dibuat:</span>
              <span class="value">
                {{ formatDate(item.DateReported?.value) }}
              </span>
            </div>
            <div class="info-item">
              <span class="label">Assigned To (Owner):</span>
              <span class="value">
                {{ item.OwnerEmployeeName?.value || '-' }}
              </span>
            </div>
            <div class="info-item">
              <span class="label">Kategori (Class):</span>
              <span class="value">{{ item.ClassID?.value || '-' }}</span>
            </div>
          </div>

          <!-- DESKRIPSI (JIKA ADA) -->
          <div v-if="item.Description?.value" class="description-box">
            <span class="label">Deskripsi:</span>
            <div
              class="description-content"
              v-html="cleanDescription(item.Description.value)"
            ></div>
          </div>

          <!-- DETAIL ATRIBUT (JIKA ADA) -->
          <div
            v-if="item.Attributes && item.Attributes.length"
            class="attributes-box"
          >
            <span class="label">Atribut Detail:</span>
            <ul class="attr-list">
              <li v-for="attr in item.Attributes" :key="attr.id">
                <strong>{{ attr.AttributeDescription?.value }}:</strong>
                {{ attr.ValueDescription?.value || attr.Value?.value }}
              </li>
            </ul>
          </div>

          <!-- AKTIVITAS TERAKHIR / TIMELINE SINGKAT -->
          <div
            v-if="item.Activities && item.Activities.length"
            class="activities-box"
          >
            <span class="label">
              Aktivitas Terkait ({{ item.Activities.length }}):
            </span>
            <div class="activities-summary">
              <div
                v-for="act in item.Activities"
                :key="act.id"
                class="act-item"
              >
                <span class="act-type">{{ act.Type?.value }}:</span>
                <span class="act-title">{{ act.Summary?.value }}</span>
                <span
                  class="badge act-status"
                  :class="act.Status?.value?.toLowerCase()"
                >
                  {{ act.Status?.value }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {defineProps} from 'vue';

const props = defineProps({
  caseList: {
    type: Array,
    required: true,
    default: () => [],
  },
});

// Pembersih HTML agar style internal Acumatica tidak merusak layout
const cleanDescription = (rawHtml) => {
  if (!rawHtml) return '-';
  const parser = new DOMParser();
  const doc = parser.parseFromString(rawHtml, 'text/html');
  return doc.body.textContent || doc.body.innerText || '-';
};

// Format tanggal agar rapi
const formatDate = (isoString) => {
  if (!isoString) return '-';
  const date = new Date(isoString);
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date);
};
</script>

<style scoped>
.cases-container {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica,
    Arial, sans-serif;
  max-width: 850px;
  margin: 0 auto;
  padding: 16px;
}

.cases-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.cases-header h2 {
  margin: 0;
  font-size: 1.2rem;
  color: #0f172a;
}

.total-badge {
  background-color: #e2e8f0;
  color: #334155;
  padding: 4px 12px;
  border-radius: 9999px;
  font-size: 0.8rem;
  font-weight: 600;
}

.empty-state {
  text-align: center;
  padding: 32px;
  background-color: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  color: #64748b;
}

.case-cards-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.case-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background-color: #ffffff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  overflow: hidden;
}

.card-header {
  padding: 12px 16px;
  background-color: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.case-id {
  font-size: 0.75rem;
  font-weight: bold;
  color: #64748b;
}

.case-subject {
  margin: 2px 0 0 0;
  font-size: 1rem;
  color: #0f172a;
}

.header-tags {
  display: flex;
  gap: 6px;
}

.badge {
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 600;
}

.badge.status.open {
  background-color: #dbeafe;
  color: #1e40af;
}

.badge.priority.medium {
  background-color: #ffedd5;
  color: #c2410c;
}

.badge.priority.high {
  background-color: #fee2e2;
  color: #991b1b;
}

.card-body {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.info-item {
  display: flex;
  flex-direction: column;
}

.label {
  font-size: 0.75rem;
  color: #64748b;
  margin-bottom: 2px;
  font-weight: 600;
}

.value {
  font-size: 0.85rem;
  color: #1e293b;
}

.description-box {
  background-color: #f8fafc;
  padding: 10px;
  border-radius: 6px;
  border-left: 3px solid #0284c7;
}

.description-content {
  margin-top: 4px;
  font-size: 0.85rem;
  color: #334155;
  line-height: 1.4;
}

.attributes-box {
  background-color: #fafafa;
  padding: 10px;
  border-radius: 6px;
  border: 1px solid #f1f5f9;
}

.attr-list {
  margin: 4px 0 0 0;
  padding-left: 18px;
  font-size: 0.85rem;
  color: #334155;
}

.activities-box {
  margin-top: 4px;
}

.activities-summary {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 6px;
}

.act-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.8rem;
  background-color: #f8fafc;
  padding: 6px 10px;
  border-radius: 4px;
}

.act-type {
  font-weight: bold;
  color: #475569;
}

.act-title {
  flex: 1;
  color: #1e293b;
}

.badge.act-status.completed {
  background-color: #dcfce7;
  color: #166534;
}

.badge.act-status.open {
  background-color: #e0f2fe;
  color: #0369a1;
}

.badge.act-status.rejected {
  background-color: #fee2e2;
  color: #991b1b;
}
</style>
