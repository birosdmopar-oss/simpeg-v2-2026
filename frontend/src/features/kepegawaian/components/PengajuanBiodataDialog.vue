<script setup lang="ts">
/**
 * Proses pengajuan perubahan Data Umum (B-04, MAKE-011): ApprovalDialog (alasan wajib saat Tolak) dengan slot `diff`
 * berisi perbandingan "Sekarang" ↔ "Diajukan" dari `GET biodata/pengajuan/{id}` → `perubahan`, plus foto pengajuan
 * (`GET …/foto` → object URL, dicabut saat dialog ditutup/dilepas). `can_process` false → pemberitahuan dan tombol
 * proses nonaktif. Kirim `POST biodata/pengajuan/{id}/process`. Galat 403/404/422 ditampilkan; sukses → `processed`.
 * TODO(B-20 penutup, MAKE-014): pemilik melihat diff pengajuannya sendiri + halaman antrean pengajuan.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ApprovalDialog from '@/shared/components/ApprovalDialog.vue'
import type { ApprovalAksi, ApprovalPayload } from '@/shared/components/approvalActions'
import { UiNotice } from '@/shared/ui'

import { labelKolomBiodata } from '../dataUmum.config'
import { describeApiError } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { PengajuanBiodataDetail, PerubahanBiodata } from '../types'

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

function tampil(p: PerubahanBiodata, sisi: 'lama' | 'baru'): string {
  const v = p[sisi]
  if (p.kolom === 'foto') return v ? 'Ada foto' : 'Tanpa foto'
  return v === null || v === '' ? '—' : String(v)
}

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
        <p v-else-if="detail && perubahan.length === 0" class="text-body2 text-slate-500">Tidak ada kolom yang berubah.</p>
        <table v-else-if="detail" class="w-full border-collapse text-left text-body2">
          <thead>
            <tr class="border-b border-slate-200 text-slate-500">
              <th scope="col" class="py-2 pr-3 font-medium">Kolom</th>
              <th scope="col" class="py-2 pr-3 font-medium">Sekarang</th>
              <th scope="col" class="py-2 font-medium">Diajukan</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="p in perubahan" :key="p.kolom" :data-kolom="p.kolom">
              <td class="py-2 pr-3 text-slate-700">{{ labelKolomBiodata(p.kolom) }}</td>
              <td class="py-2 pr-3 text-slate-500">{{ tampil(p, 'lama') }}</td>
              <td class="py-2 font-medium text-slate-900">{{ tampil(p, 'baru') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </ApprovalDialog>
</template>
