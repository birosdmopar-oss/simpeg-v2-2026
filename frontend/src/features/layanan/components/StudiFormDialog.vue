<script setup lang="ts">
/**
 * Form pengajuan / ubah Tugas Belajar dan Izin Belajar (`jenis` dari `studiConfig`). Slot berkas, label slot, dan
 * berkas wajib DARI SERVER (`opsi.slot_berkas`, `opsi.berkas_wajib`); jenjang dan up juga dari opsi. Role 2/6/7 untuk diri
 * sendiri; role 1/3 memilih pegawai (`PegawaiPicker`, lalu `opsi?nip=`). Kolom status dan SK hanya untuk role 1 (server
 * menegakkan aturan data SK saat status 1). Galat 422 per kolom ditampilkan di field masing-masing.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiFileField, UiNotice, UiSelect, UiTextField } from '@/shared/ui'
import type { SelectOption } from '@/shared/ui'

import PegawaiPicker from './PegawaiPicker.vue'
import type { StudiConfig } from '../studiConfig'
import { kolomSkStudi, type StudiIsian, type StudiItem, type StudiOpsi, type StudiService } from '../services/studi.service'

const props = defineProps<{ open: boolean; row: StudiItem | null; cfg: StudiConfig; service: StudiService }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const BERKAS = { allowed: ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'], maxMb: 5 } as const
const KOLOM_TETAP = ['nip', 'id_jenjang_pendidikan', 'institusi_pendidikan', 'jurusan_pendidikan', 'lokasi_pendidikan', 'lembaga_yang_mendanai', 'up', 'tgl_mulai', 'tgl_akhir', 'dalam_negeri', 'tembusan', 'keterangan', 'status']

const auth = useAuthStore()
const admin = computed(() => auth.role === Role.SUPER_ADMIN)
const edit = computed(() => props.row !== null)
const sk = computed(() => kolomSkStudi(props.cfg.jenis))

const opsi = ref<StudiOpsi | null>(null)
const memuat = ref(false)
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)
const berkas = reactive<Record<string, File | undefined>>({})
const kunciBerkas = ref(0)

const isian = reactive({
  nip: '',
  id_jenjang_pendidikan: '',
  institusi_pendidikan: '',
  jurusan_pendidikan: '',
  lokasi_pendidikan: '',
  lembaga_yang_mendanai: '',
  up: '',
  tgl_mulai: '',
  tgl_akhir: '',
  dalam_negeri: '1',
  tembusan: '',
  keterangan: '',
  status: '0',
  no_sk: '',
  tgl_sk: '',
  ds: '',
  no_ds: '',
  tgl_ds: '',
  perihal_ds: '',
})

const JENIS_OPSI: SelectOption[] = [
  { value: '1', label: 'Dalam negeri' },
  { value: '2', label: 'Luar negeri' },
]
const STATUS_OPSI: SelectOption[] = [
  { value: '0', label: 'Pengajuan (menunggu verifikasi)' },
  { value: '1', label: 'Langsung disetujui' },
]
const jenjangOpsi = computed<SelectOption[]>(() => (opsi.value?.jenjang_pendidikan ?? []).map((j) => ({ value: String(j.id_jenjang_pendidikan), label: j.jenjang_pendidikan })))
const tampilSk = computed(() => admin.value && (!props.cfg.statusAdminDipilih || isian.status === '1'))
const tampilStatus = computed(() => admin.value && props.cfg.statusAdminDipilih)

/** Nilai awal dari baris (ubah) atau kosong (tambah). */
function reset(): void {
  errors.value = {}
  umum.value = ''
  for (const k of Object.keys(berkas)) delete berkas[k]
  kunciBerkas.value++
  const r = props.row
  Object.assign(isian, {
    nip: r?.nip ?? '',
    id_jenjang_pendidikan: r?.id_jenjang_pendidikan ? String(r.id_jenjang_pendidikan) : '',
    institusi_pendidikan: r?.institusi_pendidikan ?? '',
    jurusan_pendidikan: r?.jurusan_pendidikan ?? '',
    lokasi_pendidikan: r?.lokasi_pendidikan ?? '',
    lembaga_yang_mendanai: r?.lembaga_yang_mendanai ?? '',
    up: r?.up ?? '',
    tgl_mulai: r?.tgl_mulai ?? '',
    tgl_akhir: r?.tgl_akhir ?? '',
    dalam_negeri: String(r?.dalam_negeri ?? 1),
    tembusan: r?.tembusan ?? '',
    keterangan: r?.keterangan ?? '',
    status: String(r?.status ?? 0),
    no_sk: r?.sk.no_sk ?? '',
    tgl_sk: r?.sk.tgl_sk ?? '',
    ds: r?.sk.ds ?? '',
    no_ds: r?.sk.no_ds ?? '',
    tgl_ds: r?.sk.tgl_ds ?? '',
    perihal_ds: r?.sk.perihal_ds ?? '',
  })
}

async function muatOpsi(nip?: string): Promise<void> {
  memuat.value = true
  umum.value = ''
  try {
    opsi.value = await props.service.opsi(nip)
    if (!edit.value && opsi.value.up) isian.up = opsi.value.up
  } catch (e) {
    opsi.value = null
    umum.value = describeApiError(e).message
  } finally {
    memuat.value = false
  }
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    reset()
    await muatOpsi(props.row && admin.value ? props.row.nip : undefined)
  },
  { immediate: true },
)

async function onPilihPegawai(nip: string): Promise<void> {
  isian.nip = nip
  opsi.value = null
  if (nip !== '') await muatOpsi(nip)
}

/** Isian dikirim sesuai hak: status dan SK hanya role 1. */
function payload(): StudiIsian {
  const data: StudiIsian = {
    id_jenjang_pendidikan: isian.id_jenjang_pendidikan,
    institusi_pendidikan: isian.institusi_pendidikan,
    jurusan_pendidikan: isian.jurusan_pendidikan,
    lokasi_pendidikan: isian.lokasi_pendidikan,
    lembaga_yang_mendanai: isian.lembaga_yang_mendanai,
    up: isian.up,
    tgl_mulai: isian.tgl_mulai,
    tgl_akhir: isian.tgl_akhir,
    dalam_negeri: isian.dalam_negeri,
    tembusan: isian.tembusan,
    keterangan: isian.keterangan,
  }
  if (admin.value) {
    if (!edit.value) data.nip = isian.nip.trim()
    if (tampilStatus.value) data.status = isian.status
    if (tampilSk.value) {
      data[sk.value.no_sk] = isian.no_sk
      data[sk.value.tgl_sk] = isian.tgl_sk
      data[sk.value.ds] = isian.ds
      data[sk.value.no_ds] = isian.no_ds
      data[sk.value.tgl_ds] = isian.tgl_ds
      data[sk.value.perihal_ds] = isian.perihal_ds
    }
  }
  return data
}

async function simpan(): Promise<void> {
  busy.value = true
  errors.value = {}
  umum.value = ''
  try {
    if (props.row) await props.service.ubah(props.row.id, payload(), berkas)
    else await props.service.tambah(payload(), berkas)
    emit('saved')
    emit('update:open', false)
  } catch (e) {
    const v = splitValidationErrors(e, [...KOLOM_TETAP, ...Object.values(sk.value), ...(opsi.value?.slot_berkas.map((s) => s.tag) ?? [])])
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
        :data-testid="`${cfg.testid}-form-dialog`"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ edit ? 'Ubah' : 'Ajukan' }} {{ cfg.judul }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Batas dan kelengkapan berkas diperiksa server saat disimpan.</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <p v-if="memuat" class="py-6 text-center text-body2 text-slate-500" :data-testid="`${cfg.testid}-form-memuat`">Memuat…</p>

        <form v-else :class="FORM_GRID_CLASS" novalidate :data-testid="`${cfg.testid}-form`" @submit.prevent="simpan">
          <p v-if="umum" role="alert" :class="FORM_ALERT_CLASS" :data-testid="`${cfg.testid}-form-error`">{{ umum }}</p>

          <PegawaiPicker v-if="admin && !edit" :class="FORM_WIDE_CLASS" :nip="isian.nip" :error="errors.nip" @pilih="onPilihPegawai" />
          <UiNotice v-if="opsi && !opsi.can_create && !edit" tone="warning" :class="FORM_WIDE_CLASS">Pengajuan {{ cfg.judul }} tidak dapat dibuat untuk pegawai ini.</UiNotice>

          <template v-if="opsi && (opsi.can_create || edit)">
            <UiSelect
              v-model="isian.id_jenjang_pendidikan"
              label="Jenjang Pendidikan"
              :options="jenjangOpsi"
              searchable
              required
              :state="state('id_jenjang_pendidikan')"
              :help-text="pesan('id_jenjang_pendidikan')"
              :data-testid="`${cfg.testid}-jenjang`"
            />
            <UiSelect v-model="isian.dalam_negeri" label="Lokasi Studi" :options="JENIS_OPSI" :state="state('dalam_negeri')" :help-text="pesan('dalam_negeri')" />
            <UiTextField v-model="isian.institusi_pendidikan" label="Institusi Pendidikan" required :state="state('institusi_pendidikan')" :help-text="pesan('institusi_pendidikan')" />
            <UiTextField v-model="isian.jurusan_pendidikan" label="Jurusan" required :state="state('jurusan_pendidikan')" :help-text="pesan('jurusan_pendidikan')" />
            <UiTextField v-model="isian.lokasi_pendidikan" label="Lokasi Pendidikan" required :state="state('lokasi_pendidikan')" :help-text="pesan('lokasi_pendidikan')" />
            <UiTextField v-model="isian.lembaga_yang_mendanai" label="Lembaga yang Mendanai" required :state="state('lembaga_yang_mendanai')" :help-text="pesan('lembaga_yang_mendanai')" />
            <UiTextField v-model="isian.up" label="Unit Pendidikan (UP)" required :state="state('up')" :help-text="pesan('up')" />
            <UiTextField v-model="isian.tgl_mulai" type="date" label="Tanggal Mulai" required :state="state('tgl_mulai')" :help-text="pesan('tgl_mulai')" />
            <UiTextField v-model="isian.tgl_akhir" type="date" label="Tanggal Akhir" required :state="state('tgl_akhir')" :help-text="pesan('tgl_akhir')" />
            <UiTextField v-model="isian.tembusan" label="Tembusan" :state="state('tembusan')" :help-text="pesan('tembusan')" />
            <UiTextField v-model="isian.keterangan" label="Keterangan" :state="state('keterangan')" :help-text="pesan('keterangan')" />

            <UiSelect v-if="tampilStatus" v-model="isian.status" label="Status Awal" :options="STATUS_OPSI" :state="state('status')" :help-text="pesan('status')" />

            <template v-if="tampilSk">
              <p :class="FORM_WIDE_CLASS" class="text-caption font-semibold uppercase tracking-wide text-slate-500">Data SK</p>
              <UiTextField v-model="isian.no_sk" label="No. SK" :state="state(sk.no_sk)" :help-text="pesan(sk.no_sk)" />
              <UiTextField v-model="isian.tgl_sk" type="date" label="Tanggal SK" :state="state(sk.tgl_sk)" :help-text="pesan(sk.tgl_sk)" />
              <UiTextField v-model="isian.ds" label="Dasar SK" :state="state(sk.ds)" :help-text="pesan(sk.ds)" />
              <UiTextField v-model="isian.no_ds" label="No. Dasar" :state="state(sk.no_ds)" :help-text="pesan(sk.no_ds)" />
              <UiTextField v-model="isian.tgl_ds" type="date" label="Tanggal Dasar" :state="state(sk.tgl_ds)" :help-text="pesan(sk.tgl_ds)" />
              <UiTextField v-model="isian.perihal_ds" label="Perihal Dasar" :state="state(sk.perihal_ds)" :help-text="pesan(sk.perihal_ds)" />
            </template>

            <p :class="FORM_WIDE_CLASS" class="text-caption font-semibold uppercase tracking-wide text-slate-500">Berkas</p>
            <UiFileField
              v-for="slot in opsi.slot_berkas"
              :key="`${slot.tag}-${kunciBerkas}`"
              :label="slot.label"
              :allowed="[...BERKAS.allowed]"
              :max-mb="BERKAS.maxMb"
              :state="state(slot.tag)"
              :help-text="pesan(slot.tag) ?? (opsi.berkas_wajib && !edit ? 'Wajib diunggah.' : undefined)"
              :data-testid="`${cfg.testid}-berkas-${slot.tag}`"
              @change="(f: File | null) => (berkas[slot.tag] = f ?? undefined)"
            />
          </template>

          <div :class="FORM_ACTIONS_CLASS">
            <UiButton variant="secondary" appearance="outline" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :disabled="busy || memuat || (opsi !== null && !opsi.can_create && !edit)" :data-testid="`${cfg.testid}-simpan`">
              {{ busy ? 'Menyimpan…' : 'Simpan' }}
            </UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
