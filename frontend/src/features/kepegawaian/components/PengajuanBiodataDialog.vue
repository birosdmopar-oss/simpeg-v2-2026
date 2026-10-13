<script setup lang="ts">
/**
 * Proses pengajuan perubahan Data Umum (B-04, MAKE-011): ApprovalDialog (alasan wajib saat Tolak) dengan slot `diff`
 * berisi perbandingan "Sekarang" ↔ "Diajukan" dari `GET biodata/pengajuan/{id}` → `perubahan`, plus foto pengajuan
 * (`GET …/foto` → object URL, dicabut saat dialog ditutup/dilepas). `can_process` false → pemberitahuan dan tombol
 * proses nonaktif. Kirim `POST biodata/pengajuan/{id}/process`. Galat 403/404/422 ditampilkan; sukses → `processed`.
 * Dibuka dari Detail Pegawai dan halaman antrean `AntreanBiodataPage` (MAKE-014); pemilik melihat perubahannya di Data
 * Umum lewat `PerubahanBiodataTabel`.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ApprovalDialog from '@/shared/components/ApprovalDialog.vue'
import type { ApprovalAksi, ApprovalPayload } from '@/shared/components/approvalActions'
import { UiNotice } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { PengajuanBiodataDetail, PerubahanBiodata } from '../types'

import PerubahanBiodataTabel from './PerubahanBiodataTabel.vue'

const props = defineProps<{ open: boolean; mode: ApprovalAksi; idHist: number; nama: string }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; processed: [detail: PengajuanBiodataDetail] }>()

const detail = ref<PengajuanBiodataDetail | null>(null)
const memuat = ref(false)
const busy = ref(false)
const error = ref('')
const fotoUrl = ref<string | null>(null)
let token = 0

function lepasFoto(): void {
  if (fotoUrl.value) URL.revokeObjectURL(fotoUrl.value)
  fotoUrl.value = null
}

watch(
  () => [props.open, props.idHist] as const,
  async ([open]) => {
    const saya = ++token
    lepasFoto()
    if (!open) return
    error.value = ''
    memuat.value = true
    try {
      const hasil = await pegawaiService.pengajuan(props.idHist)
      if (saya !== token) return
      detail.value = hasil
      if (hasil.pengajuan.ada_foto) {
        try {
          const blob = await pegawaiService.fotoPengajuan(props.idHist)
          if (saya === token) fotoUrl.value = URL.createObjectURL(blob)
        } catch {
          // Foto tidak tersedia → tanpa pratinjau.
        }
      }
    } catch (e) {
      if (saya !== token) return
      detail.value = null
      error.value = describeApiError(e).message
    } finally {
      if (saya === token) memuat.value = false
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  token++
  lepasFoto()
})

const bolehProses = computed(() => detail.value?.can_process ?? false)

const perubahan = computed<PerubahanBiodata[]>(() => detail.value?.perubahan ?? [])


async function onConfirm(payload: ApprovalPayload): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    const hasil = await pegawaiService.prosesPengajuan(props.idHist, payload)
    emit('processed', hasil)
    emit('update:open', false)
  } catch (e) {
    const pesan = isApiError(e) ? Object.values(e.errors ?? {})[0]?.[0] : undefined
    error.value = pesan ?? describeApiError(e).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <ApprovalDialog
    :open="open"
    :mode="mode"
    :subject="`pengajuan Data Umum — ${nama}`"
    description="Periksa perubahan yang diajukan sebelum memproses."
    :loading="busy"
    :error="error"
    :disabled="!memuat && !bolehProses"
    @update:open="(v) => emit('update:open', v)"
    @confirm="onConfirm"
  >
    <template #diff>
      <div data-testid="pengajuan-diff">
        <UiNotice v-if="detail && !bolehProses" tone="warning" class="mb-3" data-testid="pengajuan-tidak-bisa-diproses">
          Pengajuan ini sudah diproses atau di luar lingkup Anda; tidak dapat disetujui/ditolak.
        </UiNotice>
        <img v-if="fotoUrl" :src="fotoUrl" alt="Foto yang diajukan" class="mb-3 h-28 w-24 rounded-lg object-cover" data-testid="pengajuan-foto" />
        <p v-if="memuat" class="text-body2 text-slate-500">Memuat perubahan…</p>
        <PerubahanBiodataTabel v-else-if="detail" :perubahan="perubahan" />
      </div>
    </template>
  </ApprovalDialog>
</template>
