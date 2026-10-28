<script setup lang="ts">
/**
 * Form pengajuan / ubah PMK (PRD §6.9, §6.11.1). Jenis dan batas tanggal dari `GET pmk/opsi`; masa kerja (`mker_th`,
 * `mker_bl`) dari kalkulator server `POST pmk/hitung-mker` (debounce 300 ms) dan dikirim apa adanya — FE tidak menghitung.
 * Empat berkas (kontrak, paklaring, surat PHK, slip gaji; 2 MB, jpg/jpeg/png/gif/pdf): wajib saat tambah, atau saat ubah
 * bila kolomnya belum berisi (server yang menegakkan). Role 1: memilih pegawai (pencarian server) lalu `pmk/opsi?nip=`.
 * Galat 422 per kolom.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiFileField, UiNotice, UiSelect, UiTextField } from '@/shared/ui'

import { useHitungMker } from '../composables/useHitungMker'
import { KOLOM_BERKAS_PMK, pmkService } from '../services/pmk.service'
import type { KolomBerkasPmk, PmkIsian, PmkItem, PmkOpsi } from '../types'

import PegawaiPicker from './PegawaiPicker.vue'

const props = defineProps<{ open: boolean; row: PmkItem | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: PmkItem] }>()

const KOLOM = ['nip', 'jenis_pmk', 'instansi', 'tgl_awal', 'tgl_akhir', 'mker_th', 'mker_bl', ...KOLOM_BERKAS_PMK]
const BERKAS = { allowed: ['jpg', 'jpeg', 'png', 'gif', 'pdf'], maxMb: 2 } as const
const LABEL_BAWAAN: Record<string, string> = {
  file_kontrak: 'Surat Kontrak Kerja',
  file_paklaring: 'Surat Paklaring',
  file_surat_phk: 'Surat PHK',
  file_slip_gaji: 'Slip Gaji',
}

const auth = useAuthStore()
const admin = computed(() => auth.role === Role.SUPER_ADMIN)
const edit = computed(() => props.row !== null)

const opsi = ref<PmkOpsi | null>(null)
const memuatOpsi = ref(false)
const isian = reactive({ nip: '', jenis_pmk: '', instansi: '', tgl_awal: '', tgl_akhir: '' })
const berkas = ref<Partial<Record<KolomBerkasPmk, File>>>({})
const kunciBerkas = ref(0)
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)

const aktifKalkulator = computed(() => props.open && opsi.value !== null)
const kalkulator = useHitungMker(isian, aktifKalkulator)

const jenisOpsi = computed(() => (opsi.value?.jenis_pmk ?? []).map((j) => ({ value: j, label: j })))
const bisaDiisi = computed(() => opsi.value !== null && opsi.value.can_create)
const labelBerkas = (kolom: string): string => opsi.value?.berkas[kolom] ?? LABEL_BAWAAN[kolom] ?? kolom
const sudahAda = (kolom: KolomBerkasPmk): boolean => props.row?.berkas[kolom] === true

function reset(): void {
  opsi.value = null
  errors.value = {}
  umum.value = ''
  berkas.value = {}
  kunciBerkas.value++
  Object.assign(isian, { nip: '', jenis_pmk: '', instansi: '', tgl_awal: '', tgl_akhir: '' })
}

async function muatOpsi(nip?: string): Promise<void> {
  memuatOpsi.value = true
  umum.value = ''
  try {
    opsi.value = await pmkService.opsi(nip)
  } catch (e) {
    opsi.value = null
    umum.value = describeApiError(e).message
  } finally {
    memuatOpsi.value = false
  }
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    reset()
    const r = props.row
    if (r) {
      Object.assign(isian, { nip: r.nip, jenis_pmk: r.jenis_pmk, instansi: r.instansi, tgl_awal: r.tgl_awal, tgl_akhir: r.tgl_akhir })
      await muatOpsi(admin.value ? r.nip : undefined)
    } else if (!admin.value) {
      await muatOpsi()
    }
  },
  { immediate: true },
)

function onPilihPegawai(nip: string): void {
  isian.nip = nip
  opsi.value = null
  if (nip !== '') void muatOpsi(nip)
}

function pilihBerkas(kolom: KolomBerkasPmk, file: File | null): void {
  const baru = { ...berkas.value }
  if (file) baru[kolom] = file
  else delete baru[kolom]
  berkas.value = baru
}

async function simpan(): Promise<void> {
  busy.value = true
  errors.value = {}
  umum.value = ''
  const data: PmkIsian = {
    jenis_pmk: isian.jenis_pmk,
    instansi: isian.instansi,
    tgl_awal: isian.tgl_awal,
    tgl_akhir: isian.tgl_akhir,
    mker_th: String(kalkulator.hasil.value?.mker_th ?? props.row?.mker_th ?? ''),
    mker_bl: String(kalkulator.hasil.value?.mker_bl ?? props.row?.mker_bl ?? ''),
  }
  if (admin.value) data.nip = isian.nip.trim()
  try {
    const item = props.row ? await pmkService.update(props.row.id, data, berkas.value) : await pmkService.create(data, berkas.value)
    emit('saved', item)
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

const state = (k: string): 'default' | 'error' => (errors.value[k] || kalkulator.galat.value[k] ? 'error' : 'default')
const pesan = (k: string): string | undefined => errors.value[k] || kalkulator.galat.value[k] || undefined
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="pmk-form-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ edit ? 'Ubah' : 'Ajukan' }} PMK</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Masa kerja dihitung oleh sistem dari tanggal awal dan akhir; validasi akhir tetap pada server saat disimpan.
            </DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <p v-if="memuatOpsi" class="py-6 text-center text-body2 text-slate-500" data-testid="pmk-form-memuat">Memuat…</p>

        <form v-else :class="FORM_GRID_CLASS" novalidate data-testid="pmk-form" @submit.prevent="simpan">
          <p v-if="umum" role="alert" :class="FORM_ALERT_CLASS" data-testid="pmk-form-error">{{ umum }}</p>

          <PegawaiPicker v-if="admin && !edit" :class="FORM_WIDE_CLASS" :nip="isian.nip" :error="errors.nip" @pilih="onPilihPegawai" />
          <UiNotice v-if="opsi && !bisaDiisi" tone="warning" :class="FORM_WIDE_CLASS" data-testid="pmk-tidak-bisa">PMK hanya dapat diajukan oleh PNS.</UiNotice>
          <UiNotice v-if="opsi && opsi.tmt_cpns" tone="info" :class="FORM_WIDE_CLASS" data-testid="pmk-tmt-cpns">
            TMT CPNS: {{ opsi.tmt_cpns }}. Tanggal awal PMK harus sebelum TMT CPNS.
          </UiNotice>

          <template v-if="opsi && bisaDiisi">
            <UiSelect
              v-model="isian.jenis_pmk"
              label="Jenis PMK"
              placeholder="Pilih jenis..."
              :options="jenisOpsi"
              required
              :state="state('jenis_pmk')"
              :help-text="pesan('jenis_pmk')"
              data-testid="pmk-jenis"
            />
            <UiTextField v-model="isian.instansi" label="Instansi" required :state="state('instansi')" :help-text="pesan('instansi')" data-testid="pmk-instansi" />
            <UiTextField
              v-model="isian.tgl_awal"
              type="date"
              label="Tanggal Awal"
              required
              :max="opsi.tgl_awal_maks ?? undefined"
              :state="state('tgl_awal')"
              :help-text="pesan('tgl_awal')"
              data-testid="pmk-tgl-awal"
            />
            <UiTextField
              v-model="isian.tgl_akhir"
              type="date"
              label="Tanggal Akhir"
              required
              :state="state('tgl_akhir')"
              :help-text="pesan('tgl_akhir')"
              data-testid="pmk-tgl-akhir"
            />

            <div :class="FORM_WIDE_CLASS" class="rounded-xl border border-slate-200 bg-slate-50 p-4" aria-live="polite" data-testid="pmk-kalkulator">
              <p v-if="kalkulator.memuat.value && !kalkulator.hasil.value" class="text-body2 text-slate-500">Menghitung…</p>
              <p v-else-if="!kalkulator.hasil.value && !kalkulator.galatUmum.value" class="text-body2 text-slate-500">
                Isi tanggal awal dan akhir untuk melihat masa kerja.
              </p>
              <UiNotice v-if="kalkulator.galatUmum.value" tone="warning" data-testid="pmk-kalkulator-galat">{{ kalkulator.galatUmum.value }}</UiNotice>
              <dl v-if="kalkulator.hasil.value" class="grid grid-cols-2 gap-3 text-body2">
                <div><dt class="text-slate-500">Masa Kerja (Tahun)</dt><dd class="font-semibold text-slate-900" data-testid="pmk-mker-th">{{ kalkulator.hasil.value.mker_th }}</dd></div>
                <div><dt class="text-slate-500">Masa Kerja (Bulan)</dt><dd class="font-semibold text-slate-900" data-testid="pmk-mker-bl">{{ kalkulator.hasil.value.mker_bl }}</dd></div>
              </dl>
              <UiNotice v-if="kalkulator.hasil.value?.peringatan" tone="warning" class="mt-2" data-testid="pmk-peringatan">{{ kalkulator.hasil.value.peringatan }}</UiNotice>
              <span v-if="errors.mker_th || errors.mker_bl" role="alert" class="mt-1 block text-caption text-danger">{{ errors.mker_th || errors.mker_bl }}</span>
            </div>

            <div v-for="kolom in KOLOM_BERKAS_PMK" :key="`${kolom}-${kunciBerkas}`" :data-testid="`pmk-berkas-${kolom}`">
              <UiFileField
                :label="sudahAda(kolom) ? labelBerkas(kolom) : `${labelBerkas(kolom)} *`"
                :allowed="[...BERKAS.allowed]"
                :max-mb="BERKAS.maxMb"
                :hint="sudahAda(kolom) ? 'Sudah ada; unggah untuk mengganti.' : 'Wajib.'"
                @change="(f) => pilihBerkas(kolom, f)"
              />
              <span v-if="errors[kolom]" role="alert" class="mt-1 block text-caption text-danger">{{ errors[kolom] }}</span>
            </div>
          </template>

          <div :class="FORM_ACTIONS_CLASS">
            <UiButton type="button" appearance="soft" variant="secondary" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :loading="busy" :disabled="!bisaDiisi" data-testid="pmk-simpan">Simpan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
