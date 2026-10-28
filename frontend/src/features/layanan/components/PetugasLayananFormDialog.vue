<script setup lang="ts">
/**
 * Form PIC (Petugas Layanan), role 1 saja. Jenis layanan dari `GET layanan/jenis`. Pegawai dipilih dari KANDIDAT server
 * (`GET layanan/petugas/kandidat?search=`, bukan `GET pegawai`): pencarian server min. 3 huruf, debounce 300 ms. Bila
 * server memberi `peringatan` (mis. satker kepegawaian kosong → `items` kosong), peringatan itu ditampilkan apa adanya.
 * Status (aktif/tidak) hanya saat ubah; bawaan server 1. Galat 422 per kolom.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiCombobox, UiNotice, UiSelect } from '@/shared/ui'
import type { SelectOption } from '@/shared/ui'

import {
  petugasLayananService,
  type JenisLayanan,
  type KandidatPetugas,
  type PetugasLayanan,
} from '../services/petugasLayanan.service'

const props = defineProps<{ open: boolean; row: PetugasLayanan | null; jenis: JenisLayanan[] }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const KOLOM = ['id_jenis_layanan', 'nip', 'status']
const STATUS_OPSI: SelectOption[] = [
  { value: '1', label: 'Aktif' },
  { value: '0', label: 'Tidak Aktif' },
]

const edit = computed(() => props.row !== null)
const jenisOpsi = computed<SelectOption[]>(() => props.jenis.map((j) => ({ value: String(j.id_jenis_layanan), label: j.jenis_layanan })))
const isian = reactive({ id_jenis_layanan: '', nip: '', status: '1' })
const teks = ref('')
const kandidat = ref<KandidatPetugas[]>([])
const peringatan = ref<string | null>(null)
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

const labelKandidat = (k: KandidatPetugas): string => `${k.nip} — ${k.nama}`

watch(
  () => props.open,
  (open) => {
    if (!open) return
    errors.value = {}
    umum.value = ''
    peringatan.value = null
    kandidat.value = []
    Object.assign(isian, {
      id_jenis_layanan: props.row ? String(props.row.id_jenis_layanan) : '',
      nip: props.row?.nip ?? '',
      status: props.row ? String(props.row.status) : '1',
    })
    teks.value = props.row ? `${props.row.nip} — ${props.row.nama ?? ''}` : ''
  },
  { immediate: true },
)

/** Pilihan kandidat: teks yang sama dengan label saran memilih NIP; mengetik ulang mengosongkan pilihan. */
function onInput(nilai: string): void {
  teks.value = nilai
  const dipilih = kandidat.value.find((k) => labelKandidat(k) === nilai)
  if (dipilih) {
    isian.nip = dipilih.nip
    return
  }
  isian.nip = ''
  clearTimeout(timer)
  if (nilai.trim().length < 3) {
    kandidat.value = []
    peringatan.value = null
    return
  }
  timer = setTimeout(() => void cari(nilai.trim()), 300)
}

async function cari(kata: string): Promise<void> {
  try {
    const hasil = await petugasLayananService.kandidat(kata)
    kandidat.value = hasil.items
    peringatan.value = hasil.peringatan
  } catch (e) {
    kandidat.value = []
    umum.value = describeApiError(e).message
  }
}

async function simpan(): Promise<void> {
  busy.value = true
  errors.value = {}
  umum.value = ''
  try {
    const data = { id_jenis_layanan: Number(isian.id_jenis_layanan), nip: isian.nip }
    if (props.row) await petugasLayananService.ubah(props.row.id, { ...data, status: Number(isian.status) })
    else await petugasLayananService.tambah(data)
    emit('saved')
    emit('update:open', false)
  } catch (e) {
    const v = splitValidationErrors(e, KOLOM)
    errors.value = v.fields
    umum.value = v.general.length ? v.general.join(' ') : Object.keys(v.fields).length ? '' : describeApiError(e).message
  } finally {
    busy.value = false
  }
}

function tutup(): void {
  if (!busy.value) emit('update:open', false)
}

const state = (k: string): 'default' | 'error' => (errors.value[k] ? 'error' : 'default')
const pesan = (k: string): string | undefined => errors.value[k] || undefined
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="layanan-petugas-form-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ edit ? 'Ubah' : 'Tambah' }} PIC Layanan</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Pilih pegawai dari kandidat satker kepegawaian dan jenis layanannya.</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="layanan-petugas-form" @submit.prevent="simpan">
          <p v-if="umum" role="alert" :class="FORM_ALERT_CLASS" data-testid="layanan-petugas-form-error">{{ umum }}</p>

          <UiSelect
            v-model="isian.id_jenis_layanan"
            label="Jenis Layanan"
            :options="jenisOpsi"
            placeholder="Pilih jenis layanan…"
            required
            :state="state('id_jenis_layanan')"
            :help-text="pesan('id_jenis_layanan')"
            data-testid="layanan-petugas-jenis"
            :class="FORM_WIDE_CLASS"
          />

          <div :class="FORM_WIDE_CLASS" data-testid="layanan-petugas-kandidat">
            <span class="mb-1.5 block text-body2 font-medium text-slate-700">Pegawai (PIC)<span class="text-danger" aria-hidden="true"> *</span></span>
            <UiCombobox
              :model-value="teks"
              :suggestions="kandidat.map(labelKandidat)"
              placeholder="Cari NIP atau nama pegawai (min. 3 huruf)"
              aria-label="Pegawai (PIC)"
              :invalid="!!errors.nip"
              @update:model-value="onInput"
            />
            <span v-if="errors.nip" role="alert" class="mt-1 block text-caption text-danger">{{ errors.nip }}</span>
            <span v-else-if="isian.nip" class="mt-1 block text-caption text-slate-500" data-testid="layanan-petugas-nip">NIP terpilih: {{ isian.nip }}</span>
          </div>

          <UiNotice v-if="peringatan" tone="warning" :class="FORM_WIDE_CLASS" data-testid="layanan-petugas-peringatan">{{ peringatan }}</UiNotice>

          <UiSelect
            v-if="edit"
            v-model="isian.status"
            label="Status"
            :options="STATUS_OPSI"
            :state="state('status')"
            :help-text="pesan('status')"
            data-testid="layanan-petugas-status"
            :class="FORM_WIDE_CLASS"
          />

          <div :class="FORM_ACTIONS_CLASS">
            <UiButton variant="secondary" appearance="outline" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :disabled="busy || !isian.nip || !isian.id_jenis_layanan" data-testid="layanan-petugas-simpan">
              {{ busy ? 'Menyimpan…' : 'Simpan' }}
            </UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
