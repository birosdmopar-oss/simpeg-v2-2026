<script setup lang="ts">
/**
 * Tab riwayat berbentuk satu rekaman (Data Alamat): form langsung di dalam kartu, tombol "Batalkan Perubahan" +
 * "Simpan Perubahan" seperti form Data Umum. DATA CONTOH — simpan hanya sementara dan diberi tahu jelas.
 */
import { onMounted, ref, watch } from 'vue'

import { UiCard, UiNotice } from '@/shared/ui'

import RiwayatFieldForm from './RiwayatFieldForm.vue'
import type { RiwayatConfig } from './riwayat.config'
import { riwayatService } from './riwayat.service'

const props = defineProps<{ nip: string; config: RiwayatConfig; readonly: boolean }>()

const values = ref<Record<string, string>>({})
const loading = ref(true)
const notice = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  values.value = await riwayatService.getRecord(props.nip, props.config.key)
  loading.value = false
}
onMounted(load)
watch(() => [props.nip, props.config.key], () => {
  notice.value = null
  void load()
})

async function onSubmit(next: Record<string, string>): Promise<void> {
  await riwayatService.saveRecord(props.nip, props.config.key, next)
  values.value = { ...next }
  notice.value = `Perubahan hanya tersimpan sementara di halaman ini — belum tersambung ke backend (task ${props.config.task}). Status verifikasi menjadi "Menunggu Verifikasi".`
}
</script>

<template>
  <div class="space-y-4" :data-testid="`riwayat-section-${config.key}`">
    <UiNotice v-if="notice" tone="info" dismissible @dismiss="notice = null">{{ notice }}</UiNotice>

    <UiCard :title="config.title" :subtitle="config.subtitle" flush>
      <div v-if="loading" class="mx-5 mb-5 mt-4 h-40 animate-pulse rounded-xl bg-slate-100" aria-busy="true" />
      <div v-else class="mt-3 border-t border-slate-200 px-5 py-5">
        <RiwayatFieldForm
          :fields="config.fields"
          :initial="values"
          :readonly="readonly"
          submit-label="Simpan Perubahan"
          cancel-label="Batalkan Perubahan"
          testid="riwayat-record"
          @submit="onSubmit"
        />
      </div>
    </UiCard>
  </div>
</template>
