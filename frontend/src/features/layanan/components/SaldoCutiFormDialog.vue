<script setup lang="ts">
/**
 * Form saldo cuti (Modul C, role 1, 3): tambah (target 1 = semua pegawai aktif jenis 1/6 dalam lingkup, target 2 = satu
 * pegawai lewat `PegawaiPicker`) atau ubah kolom saldo satu baris. Batas per kolom dan aturan lewatan dari server (422
 * per kolom; target 2 yang sudah punya baris → 422 `errors.nip`). Respons tambah (`ringkasan`, `dilewati`) diteruskan
 * ke halaman apa adanya, tidak diringkas diam-diam.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiNotice, UiSelect, UiTextField } from '@/shared/ui'

import { cutiSaldoService, type HasilTambahSaldo, type SaldoCuti, type SaldoIsian } from '../services/cutiSaldo.service'

import PegawaiPicker from './PegawaiPicker.vue'

const props = defineProps<{ open: boolean; row: SaldoCuti | null; tahunAwal: number }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [hasil: HasilTambahSaldo | null] }>()

const KOLOM = ['target', 'nip', 'tahun', 'sisa_cuti', 'sisa_cuti_n1', 'sisa_cuti_n2', 'utang_cuti', 'sisa_cuti_ditangguhkan']
const TARGET_OPSI = [
  { value: '1', label: 'Semua pegawai aktif (jenis 1 dan 6) dalam lingkup' },
  { value: '2', label: 'Satu pegawai' },
]

const edit = computed(() => props.row !== null)
const isian = reactive({ target: '1', nip: '', tahun: '', sisa_cuti: '', sisa_cuti_n1: '', sisa_cuti_n2: '', utang_cuti: '', sisa_cuti_ditangguhkan: '' })
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)

const angka = (v: string): number => Number(v.trim())

function reset(): void {
  errors.value = {}
  umum.value = ''
  Object.assign(isian, { target: '1', nip: '', tahun: String(props.tahunAwal), sisa_cuti: '', sisa_cuti_n1: '', sisa_cuti_n2: '', utang_cuti: '', sisa_cuti_ditangguhkan: '' })
  if (props.row) {
    Object.assign(isian, {
      nip: props.row.nip,
      tahun: String(props.row.tahun),
      sisa_cuti: String(props.row.sisa_cuti),
      sisa_cuti_n1: String(props.row.sisa_cuti_n1),
      sisa_cuti_n2: String(props.row.sisa_cuti_n2),
      utang_cuti: String(props.row.utang_cuti),
      sisa_cuti_ditangguhkan: String(props.row.sisa_cuti_ditangguhkan),
    })
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) reset()
  },
  { immediate: true },
)

function onPilihPegawai(nip: string): void {
  isian.nip = nip
}

function isianSaldo(): SaldoIsian {
  const out: SaldoIsian = {
    sisa_cuti: angka(isian.sisa_cuti),
    sisa_cuti_n1: angka(isian.sisa_cuti_n1),
    sisa_cuti_n2: angka(isian.sisa_cuti_n2),
    utang_cuti: angka(isian.utang_cuti),
  }
  if (isian.sisa_cuti_ditangguhkan.trim() !== '') out.sisa_cuti_ditangguhkan = angka(isian.sisa_cuti_ditangguhkan)
  return out
}

async function simpan(): Promise<void> {
  busy.value = true
  errors.value = {}
  umum.value = ''
  try {
    if (props.row) {
      await cutiSaldoService.ubah(props.row.id, isianSaldo())
      emit('saved', null)
    } else {
      const hasil = await cutiSaldoService.tambah({ target: isian.target === '2' ? 2 : 1, ...(isian.target === '2' ? { nip: isian.nip.trim() } : {}), tahun: angka(isian.tahun), ...isianSaldo() })
      emit('saved', hasil)
    }
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
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="saldo-form-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ edit ? 'Ubah' : 'Tambah' }} Saldo Cuti</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Batas tiap kolom dicek oleh sistem saat disimpan. Target semua pegawai melewati pegawai yang sudah punya saldo tahun itu.
            </DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="saldo-form" @submit.prevent="simpan">
          <p v-if="umum" role="alert" :class="FORM_ALERT_CLASS" data-testid="saldo-form-error">{{ umum }}</p>

          <template v-if="!edit">
            <UiSelect v-model="isian.target" label="Target" :options="TARGET_OPSI" :state="state('target')" :help-text="pesan('target')" data-testid="saldo-target" />
            <PegawaiPicker v-if="isian.target === '2'" :nip="isian.nip" :error="errors.nip" @pilih="onPilihPegawai" />
          </template>
          <UiTextField
            v-if="!edit"
            v-model="isian.tahun"
            type="number"
            label="Tahun"
            required
            :state="state('tahun')"
            :help-text="pesan('tahun')"
            data-testid="saldo-tahun"
          />
          <p v-else :class="FORM_WIDE_CLASS" class="text-body2 text-slate-600">{{ props.row?.nama }} ({{ props.row?.nip }}), tahun {{ props.row?.tahun }}</p>

          <UiTextField v-model="isian.sisa_cuti" type="number" label="Sisa Cuti (N)" required :state="state('sisa_cuti')" :help-text="pesan('sisa_cuti')" data-testid="saldo-sisa" />
          <UiTextField v-model="isian.sisa_cuti_n1" type="number" label="Sisa Cuti (N-1)" required :state="state('sisa_cuti_n1')" :help-text="pesan('sisa_cuti_n1')" data-testid="saldo-n1" />
          <UiTextField v-model="isian.sisa_cuti_n2" type="number" label="Sisa Cuti (N-2)" required :state="state('sisa_cuti_n2')" :help-text="pesan('sisa_cuti_n2')" data-testid="saldo-n2" />
          <UiTextField v-model="isian.utang_cuti" type="number" label="Utang Cuti" required :state="state('utang_cuti')" :help-text="pesan('utang_cuti')" data-testid="saldo-utang" />
          <UiTextField
            v-model="isian.sisa_cuti_ditangguhkan"
            type="number"
            label="Cuti Ditangguhkan"
            :state="state('sisa_cuti_ditangguhkan')"
            :help-text="pesan('sisa_cuti_ditangguhkan')"
            data-testid="saldo-ditangguhkan"
          />

          <UiNotice v-if="!edit" tone="info" :class="FORM_WIDE_CLASS" data-testid="saldo-info-target">
            Untuk target semua pegawai, baris yang sudah ada untuk tahun ini dilewati dan ditampilkan setelah simpan.
          </UiNotice>

          <div :class="FORM_ACTIONS_CLASS">
            <UiButton variant="secondary" appearance="outline" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :disabled="busy" data-testid="saldo-simpan">{{ busy ? 'Menyimpan…' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
