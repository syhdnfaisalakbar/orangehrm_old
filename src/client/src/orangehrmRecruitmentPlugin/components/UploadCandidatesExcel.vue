<!--
  UploadCandidatesExcel.vue — tombol + modal upload kandidat via Excel (fork ASG).

  Alur: pilih file .xlsx -> Pratinjau (endpoint preview_candidate_import.php,
  tidak menulis DB) -> Simpan (save_candidate_import.php).

  Template Excel: web/proxy/candidate_import_template.php (dibuat dinamis, berisi
  sheet Kandidat / Petunjuk / Daftar Vacancy).
-->
<template>
  <div class="cand-import">
    <oxd-button
      :label="isSaving ? 'Menyimpan...' : 'Upload'"
      icon-name="upload"
      display-type="secondary"
      :disabled="isSaving"
      @click="openModal"
    />

    <div v-if="visible" class="cand-import__overlay" @click.self="closeModal">
      <div class="cand-import__modal">
        <div class="cand-import__head">
          <h6 class="oxd-text oxd-text--h6" style="margin: 0">
            Upload Kandidat via Excel
          </h6>
          <button class="cand-import__x" type="button" @click="closeModal">
            &times;
          </button>
        </div>

        <div class="cand-import__body">
          <!-- STEP 1: pilih file -->
          <div v-if="step === 'select'">
            <p class="cand-import__p">
              Unduh template, isi sesuai petunjuk, lalu unggah kembali file
              <strong>.xlsx</strong> di sini.
            </p>
            <p class="cand-import__p">
              <a :href="templateUrl" class="cand-import__link"
                >Download Template Excel</a
              >
              <span class="cand-import__hint">
                (berisi kolom wajib, petunjuk, dan daftar vacancy terbaru)
              </span>
            </p>

            <div class="cand-import__file">
              <input
                ref="fileInput"
                type="file"
                accept=".xlsx"
                @change="onFileChange"
              />
              <span v-if="selectedFileName" class="cand-import__filename">
                {{ selectedFileName }}
              </span>
            </div>

            <label class="cand-import__check">
              <input v-model="pushToAcumatica" type="checkbox" />
              <span>
                Push ke Acumatica setelah simpan
                <span class="cand-import__hint"
                  >(tulis ulang Description Case, buat Task kandidat, lampirkan
                  CV, kirim Note status)</span
                >
              </span>
            </label>

            <p v-if="errorMessage" class="cand-import__error">
              {{ errorMessage }}
            </p>
          </div>

          <!-- STEP 2: pratinjau -->
          <div v-else>
            <div class="cand-import__summary">
              <span
                >Total: <strong>{{ summary.total }}</strong></span
              >
              <span class="cand-import__ok"
                >Siap masuk: <strong>{{ summary.ok }}</strong></span
              >
              <span class="cand-import__skip"
                >Dilewati: <strong>{{ summary.skip }}</strong></span
              >
              <span class="cand-import__bad"
                >Error: <strong>{{ summary.error }}</strong></span
              >
            </div>
            <p class="cand-import__hint" style="margin: 4px 0 8px">
              Hanya baris berstatus <strong>OK</strong> yang akan disimpan.
              Baris "Dilewati" berarti email tersebut sudah pernah melamar di
              vacancy yang sama.
            </p>

            <div class="cand-import__tablewrap">
              <table class="cand-import__table">
                <thead>
                  <tr>
                    <th style="width: 48px">Baris</th>
                    <th style="width: 180px">Nama</th>
                    <th style="width: 200px">Email</th>
                    <th>Vacancy</th>
                    <th style="width: 92px">Tanggal</th>
                    <th style="width: 70px">Resume</th>
                    <th style="width: 78px">Status</th>
                    <th style="width: 240px">Keterangan</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in rows" :key="row.row">
                    <td>{{ row.row }}</td>
                    <td :title="row.full_name">{{ row.full_name }}</td>
                    <td :title="row.email">{{ row.email }}</td>
                    <td :title="row.vacancy_name">
                      <span class="cand-import__ellipsis">{{
                        row.vacancy_name || row.vacancy_raw || '-'
                      }}</span>
                    </td>
                    <td>{{ row.date_of_application }}</td>
                    <td>{{ row.resume_url ? 'link' : '-' }}</td>
                    <td>
                      <span :class="statusClass(row.status)">{{
                        statusLabel(row.status)
                      }}</span>
                    </td>
                    <td :title="row.message">
                      <span class="cand-import__ellipsis">{{
                        row.message
                      }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <p v-if="errorMessage" class="cand-import__error">
              {{ errorMessage }}
            </p>
          </div>
        </div>

        <div class="cand-import__foot">
          <template v-if="step === 'select'">
            <oxd-button
              display-type="ghost"
              label="Tutup"
              @click="closeModal"
            />
            <oxd-button
              display-type="secondary"
              :label="isPreviewing ? 'Memproses...' : 'Pratinjau'"
              :disabled="isPreviewing || !selectedFile"
              @click="doPreview"
            />
          </template>
          <template v-else>
            <oxd-button
              display-type="ghost"
              label="Pilih File Lain"
              :disabled="isSaving"
              @click="backToSelect"
            />
            <oxd-button
              display-type="secondary"
              :label="
                isSaving ? 'Menyimpan...' : `Simpan ${summary.ok} Kandidat`
              "
              :disabled="isSaving || summary.ok === 0"
              @click="doSave"
            />
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import {ref} from 'vue';
import Swal from 'sweetalert2';

export default {
  name: 'UploadCandidatesExcel',
  emits: ['imported'],
  setup(_, {emit}) {
    const baseUrl = String(window.appGlobal?.baseUrl || '')
      .replace(/\/index\.php.*$/i, '')
      .replace(/\/$/, '');

    const visible = ref(false);
    const step = ref('select');
    const selectedFile = ref(null);
    const selectedFileName = ref('');
    const rows = ref([]);
    const summary = ref({total: 0, ok: 0, skip: 0, error: 0});
    const isPreviewing = ref(false);
    const isSaving = ref(false);
    const errorMessage = ref('');
    // Fork ASG: push ke Acumatica setelah kandidat tersimpan (bisa dimatikan)
    const pushToAcumatica = ref(true);

    const templateUrl = `${baseUrl}/proxy/candidate_import_template.php`;

    const resetState = () => {
      step.value = 'select';
      rows.value = [];
      summary.value = {total: 0, ok: 0, skip: 0, error: 0};
      errorMessage.value = '';
    };

    const openModal = () => {
      resetState();
      visible.value = true;
    };

    const closeModal = () => {
      if (isSaving.value) return;
      visible.value = false;
    };

    const onFileChange = (event) => {
      const file = event.target.files && event.target.files[0];
      errorMessage.value = '';
      if (!file) {
        selectedFile.value = null;
        selectedFileName.value = '';
        return;
      }
      if (!/\.xlsx$/i.test(file.name)) {
        selectedFile.value = null;
        selectedFileName.value = '';
        errorMessage.value =
          'Format harus .xlsx. Kalau file masih .xls, buka di Excel lalu Save As ke .xlsx.';
        return;
      }
      selectedFile.value = file;
      selectedFileName.value = file.name;
    };

    const postFile = async (endpoint) => {
      const form = new FormData();
      form.append('file', selectedFile.value);
      form.append('push', pushToAcumatica.value ? '1' : '0');
      const response = await fetch(`${baseUrl}/proxy/${endpoint}`, {
        method: 'POST',
        body: form,
        credentials: 'include',
        headers: {Accept: 'application/json'},
      });
      const text = await response.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch (e) {
        throw new Error(
          `Respons tidak valid dari server (HTTP ${response.status}).`,
        );
      }
      if (!response.ok || data.status !== 'success') {
        throw new Error(
          data.message || `Permintaan gagal (HTTP ${response.status}).`,
        );
      }
      return data;
    };

    const doPreview = async () => {
      if (!selectedFile.value) {
        errorMessage.value = 'Pilih file .xlsx terlebih dahulu.';
        return;
      }
      isPreviewing.value = true;
      errorMessage.value = '';
      try {
        const data = await postFile('preview_candidate_import.php');
        rows.value = data.rows || [];
        summary.value = data.summary || {total: 0, ok: 0, skip: 0, error: 0};
        step.value = 'preview';
      } catch (e) {
        errorMessage.value = e.message || 'Gagal memproses file.';
      } finally {
        isPreviewing.value = false;
      }
    };

    const doSave = async () => {
      isSaving.value = true;
      errorMessage.value = '';
      try {
        const data = await postFile('save_candidate_import.php');
        const s = data.summary || {};
        visible.value = false;
        emit('imported');

        const failed = (data.rows || []).filter(
          (r) =>
            r.status === 'error' ||
            (r.status === 'inserted' && r.push_status === 'error'),
        );
        const failedHtml = failed.length
          ? `<div style="margin-top:10px;max-height:220px;overflow-y:auto;text-align:left">${failed
              .slice(0, 20)
              .map(
                (r) =>
                  `<div style="display:flex;gap:6px"><span>&bull;</span><span>Baris ${
                    r.row
                  } (${r.full_name || '-'}): ${
                    r.status === 'error'
                      ? r.message
                      : r.push_message || 'push gagal'
                  }</span></div>`,
              )
              .join('')}${
              failed.length > 20
                ? `<div style="margin-top:6px">... dan ${
                    failed.length - 20
                  } baris lain</div>`
                : ''
            }</div>`
          : '';

        Swal.fire({
          title: 'Upload selesai',
          icon: failed.length ? 'warning' : 'success',
          html: `<div style="text-align:left">
            <div><strong>${s.inserted || 0}</strong> kandidat masuk</div>
            <div><strong>${
              s.skipped || 0
            }</strong> dilewati (sudah pernah melamar di vacancy yang sama)</div>
            <div><strong>${s.failed || 0}</strong> gagal</div>
            <div><strong>${
              s.resume_attached || 0
            }</strong> resume terlampir</div>
            ${
              s.pushed !== undefined
                ? `<div style="margin-top:6px"><strong>${
                    s.pushed
                  }</strong> ter-push ke Acumatica${
                    s.push_failed
                      ? `, <strong>${s.push_failed}</strong> gagal push`
                      : ''
                  }</div>`
                : ''
            }
            ${failedHtml}
          </div>`,
        });
      } catch (e) {
        errorMessage.value = e.message || 'Gagal menyimpan.';
      } finally {
        isSaving.value = false;
      }
    };

    const backToSelect = () => {
      resetState();
      selectedFile.value = null;
      selectedFileName.value = '';
    };

    const statusLabel = (status) =>
      ({ok: 'OK', skip: 'Dilewati', error: 'Error'}[status] || status);

    const statusClass = (status) =>
      ({
        ok: 'cand-import__tag cand-import__tag--ok',
        skip: 'cand-import__tag cand-import__tag--skip',
        error: 'cand-import__tag cand-import__tag--bad',
      }[status] || 'cand-import__tag');

    return {
      visible,
      step,
      rows,
      summary,
      selectedFileName,
      selectedFile,
      isPreviewing,
      isSaving,
      errorMessage,
      pushToAcumatica,
      templateUrl,
      openModal,
      closeModal,
      onFileChange,
      doPreview,
      doSave,
      backToSelect,
      statusLabel,
      statusClass,
    };
  },
};
</script>

<style src="./UploadCandidatesExcel.scss" lang="scss" scoped></style>
