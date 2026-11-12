<script setup lang="ts">
/**
 * Halaman Arsip (F-10 Tahap 1, MAKE-091; PRD F-10 §5, §9): `/arsip` = pemilih pegawai (role 1/3/4/5; role 2/6/7 sudah
 * dialihkan ke arsip sendiri oleh route), `/arsip/:nip` = folder arsip pegawai itu (grid kartu per jenis riwayat + folder
 * "Semua berkas"). Data dari `GET pegawai/{nip}/arsip`; tanpa data contoh. Lingkup/izin ditegakkan server (403/404 tampil
 * sebagai pesan); guard FE hanya UX. Semua teks diinterpolasi (tanpa `v-html`).
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice } from '@/shared/ui'
import type { Crumb } from '@/shared/ui'

import type { ArsipFolderResponse } from '../arsip.types'
import { namaPegawaiArsip } from '../arsip.utils'
import ArsipFolderGrid from '../components/arsip/ArsipFolderGrid.vue'
import ArsipPemilihPegawai from '../components/arsip/ArsipPemilihPegawai.vue'
import { describeApiError } from '../services/apiErrors'
import { arsipService } from '../services/arsip.service'

const route = useRoute()
const router = useRouter()

const nip = computed(() => (typeof route.params.nip === 'string' ? route.params.nip : ''))
const data = ref<ArsipFolderResponse | null>(null)
const loading = ref(false)
const failure = ref<string | null>(null)

let permintaan = 0
async function muat(): Promise<void> {
  const ini = ++permintaan
  data.value = null
  failure.value = null
  if (nip.value === '') {
    loading.value = false
    return
  }
  loading.value = true
  try {
    const hasil = await arsipService.folder(nip.value)
    if (ini !== permintaan) return
    data.value = hasil
  } catch (error) {
    if (ini !== permintaan) return
    failure.value = describeApiError(error).message
  } finally {
    if (ini === permintaan) loading.value = false
  }
}
watch(nip, () => void muat(), { immediate: true })

const namaPegawai = computed(() => (data.value ? namaPegawaiArsip(data.value.pegawai) : ''))

const crumbs = computed<Crumb[]>(() => {
  if (nip.value === '') return [{ label: 'Arsip' }]
  return [{ label: 'Arsip', to: { name: 'arsip' } }, { label: namaPegawai.value || 'Pegawai' }]
})

function pilihPegawai(pilihan: string): void {
  void router.push({ name: 'arsip-pegawai', params: { nip: pilihan } })
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="flex flex-col gap-5" data-testid="arsip-page" :aria-busy="loading">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Arsip</h1>
          <p v-if="data" class="text-body2 text-slate-500" data-testid="arsip-pegawai-nama">{{ namaPegawai }}</p>
          <p v-else-if="nip === ''" class="text-body2 text-slate-500">Pilih pegawai untuk melihat arsip berkasnya.</p>
        </div>
        <UiButton v-if="nip !== ''" appearance="outline" :to="{ name: 'pegawai-detail', params: { nip } }" data-testid="arsip-ke-detail">Data Pegawai</UiButton>
      </div>

      <UiCard v-if="nip === ''" title="Pilih pegawai">
        <ArsipPemilihPegawai @pilih="pilihPegawai" />
      </UiCard>

      <template v-else>
        <UiNotice v-if="failure" tone="danger" data-testid="arsip-failure">{{ failure }}</UiNotice>
        <div v-else-if="loading" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-hidden="true" data-testid="arsip-memuat">
          <div v-for="n in 3" :key="n" class="h-24 animate-pulse rounded-card bg-slate-200" />
        </div>
        <template v-else-if="data">
          <UiNotice v-if="data.total === 0" tone="info" data-testid="arsip-kosong">Pegawai ini belum memiliki berkas arsip.</UiNotice>
          <ArsipFolderGrid
            v-else
            :nip="data.pegawai.nip"
            :folder="data.folder"
            :total="data.total"
            :total-hilang="data.total_hilang"
            :total-tak-dapat-dibuka="data.total_tak_dapat_dibuka"
          />
        </template>
      </template>
    </div>
  </RedesignShell>
</template>
