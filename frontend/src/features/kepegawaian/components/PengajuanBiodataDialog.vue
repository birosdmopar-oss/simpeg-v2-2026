<script setup lang="ts">
/**
 * Proses pengajuan perubahan Data Umum (B-04, MAKE-011): ApprovalDialog (alasan wajib saat Tolak) dengan slot `diff`
 * berisi perbandingan "Sekarang" ↔ "Diajukan" dari `GET biodata/pengajuan/{id}` → `perubahan`. Kirim
 * `POST biodata/pengajuan/{id}/process`. Galat 403/404/422 ditampilkan di dialog; sukses → emit `processed`.
 */
import { computed, ref, watch } from 'vue'

import ApprovalDialog from '@/shared/components/ApprovalDialog.vue'
import type { ApprovalAksi, ApprovalPayload } from '@/shared/components/approvalActions'
import { isApiError } from '@/lib/axios'

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

watch(
  () => [props.open, props.idHist] as const,
  async ([open]) => {
    if (!open) return
    error.value = ''
    memuat.value = true
    try {
      detail.value = await pegawaiService.pengajuan(props.idHist)
    } catch (e) {
      detail.value = null
      error.value = describeApiError(e).message
    } finally {
      memuat.value = false
    }
  },
  { immediate: true },
)

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
    @update:open="(v) => emit('update:open', v)"
    @confirm="onConfirm"
  >
    <template #diff>
      <div data-testid="pengajuan-diff">
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
