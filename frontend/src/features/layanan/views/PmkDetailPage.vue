<script setup lang="ts">
/**
 * Detail pengajuan PMK (PRD §6.11.1): data pengajuan, stepper dari `tahap[]` server, alasan penolakan, dan berkas yang
 * diunggah. Aksi (Edit, Proses/Setujui/Tolak PIC, Unduh berkas, Hapus) lewat satu menu ⋮ (`pmk-actions-<id>`) yang
 * disaring `aksi {}`/`aksi_tersedia[]` server. 404 → "Data tidak ditemukan"; 403 → "Anda tidak berhak …".
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiNotice, UiStepper } from '@/shared/ui'

import PmkFormDialog from '../components/PmkFormDialog.vue'
import PmkProsesDialog from '../components/PmkProsesDialog.vue'
import StatusLayananBadge from '../components/StatusLayananBadge.vue'
import { useFokusPemicu } from '../composables/useFokusPemicu'
import { usePmkAksi } from '../composables/usePmkAksi'
import { aksiBarisPmk, labelAksiPmk } from '../rowActionsPmk'
import { LAYANAN_ROUTE } from '../routes.layanan'
import { KOLOM_BERKAS_PMK, pmkService } from '../services/pmk.service'
import { nadaStepper, tahapKeSteps } from '../tahapStepper'
import type { PmkItem } from '../types'

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const detail = ref<PmkItem | null>(null)
const labelBerkas = ref<Record<string, string>>({})
const memuat = ref(true)
const galat = ref('')
const formOpen = ref(false)

const crumbs = computed(() => [
  { label: 'Home', to: { name: 'home' } },
  { label: 'Layanan' },
  { label: 'PMK', to: { name: LAYANAN_ROUTE.PMK } },
  { label: 'Detail PMK' },
])

async function muat(): Promise<void> {
  memuat.value = true
  galat.value = ''
  try {
    detail.value = await pmkService.detail(id.value)
  } catch (e) {
    detail.value = null
    galat.value = describeApiError(e).message
  } finally {
    memuat.value = false
  }
}

const aksi = usePmkAksi(async (jenis) => {
  if (jenis === 'hapus') {
    await router.replace({ name: LAYANAN_ROUTE.PMK })
    return
  }
  await muat()
})

onMounted(async () => {
  void muat()
  try {
    labelBerkas.value = (await pmkService.opsi()).berkas
  } catch {
    // Label bawaan dipakai bila opsi gagal (mis. role tanpa hak ajukan).
  }
})

function onAction(key: string): void {
  if (!detail.value) return
  if (key === 'edit') formOpen.value = true
  else aksi.pilih(key, detail.value)
}

function onSaved(): void {
  aksi.notice.value = { tone: 'success', text: 'Pengajuan PMK disimpan.' }
  void muat()
}

const periode = computed(() => (detail.value ? `${tgl(detail.value.tgl_awal)} s.d. ${tgl(detail.value.tgl_akhir)}` : ''))
const subjekProses = computed(() => (detail.value ? `PMK ${detail.value.nama || detail.value.nip}` : ''))
// CR-072 butir 8: fokus kembali ke ⋮ setelah dialog proses ditutup (Esc, Batal, atau selesai).
useFokusPemicu(aksi.prosesOpen, () => (detail.value ? `[data-testid="pmk-actions-${detail.value.id}"]` : null))
const labelKolom = (kolom: string): string => labelBerkas.value[kolom] ?? kolom
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="galat" tone="danger" class="mb-4" data-testid="pmk-detail-error">{{ galat }}</UiNotice>
    <UiNotice v-if="aksi.notice.value" :tone="aksi.notice.value.tone" class="mb-4" data-testid="pmk-detail-notice">{{ aksi.notice.value.text }}</UiNotice>
    <p v-if="memuat && !detail" class="py-10 text-center text-body2 text-slate-500">Memuat…</p>

    <template v-if="detail">
      <UiCard :title="`PMK ${detail.jenis_pmk} — ${detail.instansi}`" :subtitle="`NIP ${detail.nip}`">
        <template #actions>
          <div class="flex items-center gap-3">
            <StatusLayananBadge :label="detail.label_status" :nada="detail.nada_status" />
            <RowActionsMenu
              :actions="aksiBarisPmk(detail, { busy: aksi.busyId.value === detail.id, detail: false, labelBerkas })"
              :label="labelAksiPmk(detail, periode, true)"
              :testid="`pmk-actions-${detail.id}`"
              @select="onAction"
            />
          </div>
        </template>

        <div class="mb-6 overflow-x-auto" data-testid="pmk-stepper">
          <UiStepper :steps="tahapKeSteps(detail.tahap)" :tone="nadaStepper(detail.nada_status)" />
        </div>

        <dl class="grid gap-x-8 gap-y-3 text-body2 md:grid-cols-2" data-testid="pmk-detail-data">
          <div><dt class="text-slate-500">Jenis PMK</dt><dd class="text-slate-900">{{ detail.jenis_pmk }}</dd></div>
          <div><dt class="text-slate-500">Instansi</dt><dd class="text-slate-900">{{ detail.instansi }}</dd></div>
          <div><dt class="text-slate-500">Periode</dt><dd class="text-slate-900">{{ periode }}</dd></div>
          <div><dt class="text-slate-500">Masa Kerja</dt><dd class="text-slate-900">{{ detail.mker_th }} tahun {{ detail.mker_bl }} bulan</dd></div>
          <div v-if="detail.status === 2 && detail.reason_note" class="md:col-span-2">
            <dt class="text-slate-500">Alasan Penolakan</dt>
            <dd class="whitespace-pre-line text-danger" data-testid="pmk-alasan">{{ detail.reason_note }}</dd>
          </div>
        </dl>
      </UiCard>

      <UiCard title="Berkas" class="mt-4">
        <ul class="space-y-2 text-body2" data-testid="pmk-berkas">
          <li v-for="kolom in KOLOM_BERKAS_PMK" :key="kolom" class="flex items-center justify-between gap-3">
            <span class="text-slate-900">{{ labelKolom(kolom) }}</span>
            <span :class="detail.berkas[kolom] ? 'text-success' : 'text-slate-500'">{{ detail.berkas[kolom] ? 'Sudah diunggah (unduh lewat menu Aksi)' : 'Belum ada' }}</span>
          </li>
        </ul>
      </UiCard>
    </template>

    <PmkFormDialog v-if="detail" v-model:open="formOpen" :row="detail" @saved="onSaved" />

    <PmkProsesDialog
      v-if="aksi.prosesRow.value"
      :open="aksi.prosesOpen.value"
      :aksi="aksi.prosesAksi.value"
      :subject="subjekProses"
      :loading="aksi.prosesBusy.value"
      :error="aksi.prosesError.value"
      @update:open="(v) => (aksi.prosesOpen.value = v)"
      @confirm="aksi.proses"
    />

    <ConfirmDialog
      :open="aksi.hapusOpen.value"
      title="Hapus pengajuan PMK?"
      description="Pengajuan beserta berkas yang diunggah akan dihapus."
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (aksi.hapusOpen.value = v)"
      @confirm="aksi.hapus"
    />
  </RedesignShell>
</template>
