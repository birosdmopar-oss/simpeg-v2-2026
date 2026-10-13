<script setup lang="ts">
/**
 * Form LKH (B-12b, MAKE-013) — salinan isian legacy `views/hr/employee/rwy/lkh/form.php`: tanggal laporan dalam
 * [tanggal minimal, hari ini] dan baris kegiatan berulang (kegiatan, output, jumlah diselesaikan, jam mulai, jam
 * selesai). Atasan langsung ditentukan server (ditampilkan hanya-baca). Galat 422 per kolom/baris (`kegiatan.<i>`).
 */
import { Plus, Trash2, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiNotice, UiTextField } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import { lkhService } from '../services/lkh.service'
import type { LkhItem, LkhKegiatan, LkhOpsi } from '../types'

const props = defineProps<{ open: boolean; opsi: LkhOpsi | null; row: LkhItem | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: LkhItem] }>()

const kosong = (): LkhKegiatan => ({ kegiatan: '', output: '', jumlah_diselesaikan: '', jam_mulai: '', jam_selesai: '' })

const isian = reactive<{ tgl_laporan: string; kegiatan: LkhKegiatan[] }>({ tgl_laporan: '', kegiatan: [kosong()] })
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    errors.value = {}
    umum.value = ''
    isian.tgl_laporan = props.row?.tgl_laporan ?? props.opsi?.tanggal_maksimal ?? ''
    isian.kegiatan = [kosong()]
    if (props.row) {
      try {
        const detail = await lkhService.detail(props.row.id)
        isian.kegiatan = detail.kegiatan?.length ? detail.kegiatan.map((k) => ({ ...k })) : [kosong()]
      } catch (e) {
        umum.value = describeApiError(e).message
      }
    }
  },
  { immediate: true },
)

const tambahBaris = (): void => void isian.kegiatan.push(kosong())
const hapusBaris = (i: number): void => {
  isian.kegiatan.splice(i, 1)
  if (isian.kegiatan.length === 0) isian.kegiatan.push(kosong())
}

async function simpan(): Promise<void> {
  busy.value = true
  errors.value = {}
  umum.value = ''
  try {
    const data = { tgl_laporan: isian.tgl_laporan, kegiatan: isian.kegiatan }
    const row = props.row ? await lkhService.update(props.row.id, data) : await lkhService.create(data)
    emit('saved', row)
    emit('update:open', false)
  } catch (e) {
    if (isApiError(e) && e.status === 422 && e.errors) {
      errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v?.[0] ?? '']))
      umum.value = Object.entries(errors.value)
        .filter(([k]) => k !== 'tgl_laporan' && !k.startsWith('kegiatan.'))
        .map(([, v]) => v)
        .join(' ')
    } else {
      umum.value = describeApiError(e).message
    }
  } finally {
    busy.value = false
  }
}

function tutup(): void {
  if (!busy.value) emit('update:open', false)
}

const KOLOM: { key: keyof LkhKegiatan; label: string; type?: string }[] = [
  { key: 'kegiatan', label: 'Kegiatan' },
  { key: 'output', label: 'Output' },
  { key: 'jumlah_diselesaikan', label: 'Jumlah diselesaikan' },
  { key: 'jam_mulai', label: 'Jam mulai', type: 'time' },
  { key: 'jam_selesai', label: 'Jam selesai', type: 'time' },
]
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-4xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="lkh-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ row ? 'Ubah' : 'Tambah' }} Laporan Kerja Harian</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              <template v-if="opsi">Tanggal laporan {{ opsi.tanggal_minimal }} s.d. {{ opsi.tanggal_maksimal }}.</template>
              <template v-if="opsi?.atasan"> Diverifikasi oleh atasan langsung: {{ opsi.atasan.nama }}.</template>
            </DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <UiNotice v-if="opsi && !opsi.atasan" tone="warning" class="mb-4" data-testid="lkh-tanpa-atasan">
          Atasan langsung belum ditemukan; periksa riwayat jabatan Anda sebelum mengirim LKH.
        </UiNotice>

        <form class="space-y-4" data-testid="lkh-form" @submit.prevent="simpan">
          <UiTextField
            v-model="isian.tgl_laporan"
            class="max-w-xs"
            type="date"
            label="Tanggal Laporan"
            required
            :state="errors.tgl_laporan ? 'error' : 'default'"
            :help-text="errors.tgl_laporan"
            data-testid="lkh-tanggal"
          />

          <fieldset class="space-y-3">
            <legend class="mb-2 text-body2 font-semibold text-slate-800">Kegiatan *</legend>
            <div
              v-for="(baris, i) in isian.kegiatan"
              :key="i"
              class="grid gap-2 rounded-xl border border-slate-200 p-3 md:grid-cols-[2fr_2fr_1fr_1fr_1fr_auto]"
              :data-testid="`lkh-baris-${i}`"
            >
              <label v-for="k in KOLOM" :key="k.key" class="text-caption text-slate-600">
                {{ k.label }}
                <textarea
                  v-if="!k.type && (k.key === 'kegiatan' || k.key === 'output')"
                  v-model="baris[k.key]"
                  rows="2"
                  class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-body2"
                  :data-field="k.key"
                />
                <input v-else v-model="baris[k.key]" :type="k.type ?? 'text'" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-body2" :data-field="k.key" />
              </label>
              <button
                type="button"
                class="self-end rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-danger"
                :aria-label="`Hapus baris kegiatan ${i + 1}`"
                @click="hapusBaris(i)"
              >
                <Trash2 class="h-4 w-4" aria-hidden="true" />
              </button>
              <p v-if="errors[`kegiatan.${i}`]" role="alert" class="text-caption text-danger md:col-span-6">{{ errors[`kegiatan.${i}`] }}</p>
            </div>
            <p v-if="errors.kegiatan" role="alert" class="text-caption text-danger">{{ errors.kegiatan }}</p>
            <UiButton type="button" variant="secondary" data-testid="lkh-tambah-baris" @click="tambahBaris">
              <Plus class="h-4 w-4" aria-hidden="true" /> Tambah kegiatan
            </UiButton>
          </fieldset>

          <UiNotice v-if="umum" tone="danger" data-testid="lkh-error">{{ umum }}</UiNotice>

          <div class="flex justify-end gap-2">
            <UiButton type="button" variant="secondary" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :loading="busy" data-testid="lkh-simpan">Simpan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
