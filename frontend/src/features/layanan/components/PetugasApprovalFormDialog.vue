<script setup lang="ts">
/**
 * Form Petugas Approval Cuti (Modul C, role 1, 3; PRD Fase 4 §6.2). Opsi jenis pegawai, group jabatan yang memakai
 * golongan/ruang, jabatan koordinasi, dan unit terkunci (role 3) dari `GET cuti/petugas-approval/opsi`; daftar lain dari
 * master options. Pemohon: gol/ruang bila group jabatan termasuk daftar server, selain itu sub group jabatan. Petugas:
 * jabatan struktural (`struk_<id>`), koordinasi (`koord_<id>`), atau pegawai lain (`other_<nip>`). Galat 422 per kolom.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, nextTick, reactive, ref, watch } from 'vue'

import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { masterService } from '@/features/master-data/services/master.service'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiNotice, UiSelect } from '@/shared/ui'
import type { SelectOption } from '@/shared/ui'

import {
  cutiPetugasApprovalService,
  type PetugasApprovalCuti,
  type PetugasIsian,
  type PetugasOpsi,
} from '../services/cutiPetugasApproval.service'

import PegawaiPicker from './PegawaiPicker.vue'

const props = defineProps<{ open: boolean; row: PetugasApprovalCuti | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const KOLOM = [
  'id_jenis_pa',
  'id_jenis_pegawai',
  'id_unit_pemohon',
  'id_group_jabatan_pemohon',
  'id_sub_group_jabatan_pemohon',
  'gol_ruang_pemohon',
  'id_unit_petugas',
  'id_satker_petugas',
  'id_group_jabatan_petugas',
  'id_sub_group_jabatan_petugas',
  'petugas',
]

type ModePetugas = 'struk' | 'koord' | 'other'
const MODE_OPSI: SelectOption[] = [
  { value: 'struk', label: 'Jabatan struktural' },
  { value: 'koord', label: 'Jabatan koordinasi' },
  { value: 'other', label: 'Pegawai lain' },
]

const edit = computed(() => props.row !== null)
const memuat = ref(false)
const opsi = ref<PetugasOpsi | null>(null)
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)

const isian = reactive({
  id_jenis_pegawai: '',
  id_unit_pemohon: '',
  id_group_jabatan_pemohon: '',
  id_sub_group_jabatan_pemohon: '',
  gol_ruang_pemohon: '',
  id_unit_petugas: '',
  id_satker_petugas: '',
  id_group_jabatan_petugas: '',
  id_sub_group_jabatan_petugas: '',
  mode: 'struk' as ModePetugas,
  id_jabatan: '',
  id_jabatan_koord: '',
  nip: '',
})

const opsiMaster = reactive<Record<string, SelectOption[]>>({})
/** Selama isian baris diisi ulang, watcher berantai (group → sub group, unit → satker) tidak menghapus nilainya. */
const mengisiBaris = ref(false)

async function muatMaster(kunci: string, entitas: string, parent?: string | null): Promise<void> {
  const hasil = await masterService.options(entitas, parent ?? undefined)
  opsiMaster[kunci] = hasil.map((o) => ({ value: o.id, label: o.nama }))
}

const golDipakai = computed(() => opsi.value !== null && isian.id_group_jabatan_pemohon !== '' && opsi.value.group_jabatan_pakai_gol.includes(Number(isian.id_group_jabatan_pemohon)))
const jenisOpsi = computed<SelectOption[]>(() => (opsi.value?.jenis_pegawai ?? []).map((j) => ({ value: String(j.id_jenis_pegawai), label: j.jenis_pegawai })))
const koordOpsi = computed<SelectOption[]>(() => (opsi.value?.jabatan_koordinasi ?? []).map((k) => ({ value: String(k.id_jabatan_koordinasi), label: k.jabatan })))
const terkunci = computed(() => opsi.value?.unit_terkunci ?? null)

function reset(): void {
  errors.value = {}
  umum.value = ''
  Object.assign(isian, {
    id_jenis_pegawai: '',
    id_unit_pemohon: '',
    id_group_jabatan_pemohon: '',
    id_sub_group_jabatan_pemohon: '',
    gol_ruang_pemohon: '',
    id_unit_petugas: '',
    id_satker_petugas: '',
    id_group_jabatan_petugas: '',
    id_sub_group_jabatan_petugas: '',
    mode: 'struk',
    id_jabatan: '',
    id_jabatan_koord: '',
    nip: '',
  })
}

function isiDariBaris(r: PetugasApprovalCuti): void {
  Object.assign(isian, {
    id_jenis_pegawai: String(r.id_jenis_pegawai),
    id_unit_pemohon: String(r.id_unit_pemohon),
    id_group_jabatan_pemohon: String(r.id_group_jabatan_pemohon),
    id_sub_group_jabatan_pemohon: r.id_sub_group_jabatan_pemohon === null ? '' : String(r.id_sub_group_jabatan_pemohon),
    gol_ruang_pemohon: r.gol_ruang_pemohon ?? '',
    id_unit_petugas: String(r.id_unit_petugas),
    id_satker_petugas: String(r.id_satker_petugas),
    id_group_jabatan_petugas: String(r.id_group_jabatan_petugas),
    id_sub_group_jabatan_petugas: String(r.id_sub_group_jabatan_petugas),
  })
  if (r.petugas.nip) {
    isian.mode = 'other'
    isian.nip = r.petugas.nip
  } else if (r.id_jabatan_koord !== null) {
    isian.mode = 'koord'
    isian.id_jabatan_koord = String(r.id_jabatan_koord)
  } else if (r.id_jabatan !== null) {
    isian.mode = 'struk'
    isian.id_jabatan = String(r.id_jabatan)
  }
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    reset()
    memuat.value = true
    try {
      opsi.value = await cutiPetugasApprovalService.opsi()
      await Promise.all([
        muatMaster('unit', 'unit'),
        muatMaster('group', 'group-jabatan'),
        muatMaster('pangkat', 'pangkat'),
        muatMaster('jabatan', 'jabatan'),
      ])
      if (opsi.value.unit_terkunci) isian.id_unit_pemohon = String(opsi.value.unit_terkunci.id_unit)
      if (props.row) {
        mengisiBaris.value = true
        isiDariBaris(props.row)
        await Promise.all([
          muatMaster('subPemohon', 'sub-group-jabatan', isian.id_group_jabatan_pemohon),
          muatMaster('subPetugas', 'sub-group-jabatan', isian.id_group_jabatan_petugas),
          muatMaster('satker', 'satker', isian.id_unit_petugas),
        ])
        await nextTick()
        mengisiBaris.value = false
      }
    } catch (e) {
      umum.value = describeApiError(e).message
    } finally {
      memuat.value = false
    }
  },
  { immediate: true },
)

// Sub group jabatan mengikuti group jabatan (pemohon dan petugas); satker mengikuti unit petugas.
watch(
  () => isian.id_group_jabatan_pemohon,
  (gj) => {
    if (mengisiBaris.value) return
    isian.id_sub_group_jabatan_pemohon = ''
    if (gj) void muatMaster('subPemohon', 'sub-group-jabatan', gj)
    else delete opsiMaster.subPemohon
  },
)
watch(
  () => isian.id_group_jabatan_petugas,
  (gj) => {
    if (mengisiBaris.value) return
    isian.id_sub_group_jabatan_petugas = ''
    if (gj) void muatMaster('subPetugas', 'sub-group-jabatan', gj)
    else delete opsiMaster.subPetugas
  },
)
watch(
  () => isian.id_unit_petugas,
  (u) => {
    if (mengisiBaris.value) return
    isian.id_satker_petugas = ''
    if (u) void muatMaster('satker', 'satker', u)
    else delete opsiMaster.satker
  },
)

function payload(): PetugasIsian {
  const n = (v: string): number => Number(v)
  const data: PetugasIsian = {
    id_jenis_pa: opsi.value?.id_jenis_pa ?? 0,
    id_jenis_pegawai: n(isian.id_jenis_pegawai),
    id_unit_pemohon: n(isian.id_unit_pemohon),
    id_group_jabatan_pemohon: n(isian.id_group_jabatan_pemohon),
    id_unit_petugas: n(isian.id_unit_petugas),
    id_satker_petugas: n(isian.id_satker_petugas),
    id_group_jabatan_petugas: n(isian.id_group_jabatan_petugas),
    id_sub_group_jabatan_petugas: n(isian.id_sub_group_jabatan_petugas),
    petugas: isian.mode === 'struk' ? `struk_${isian.id_jabatan}` : isian.mode === 'koord' ? `koord_${isian.id_jabatan_koord}` : `other_${isian.nip.trim()}`,
  }
  if (golDipakai.value) data.gol_ruang_pemohon = isian.gol_ruang_pemohon
  else data.id_sub_group_jabatan_pemohon = n(isian.id_sub_group_jabatan_pemohon)
  return data
}

async function simpan(): Promise<void> {
  busy.value = true
  errors.value = {}
  umum.value = ''
  try {
    if (props.row) await cutiPetugasApprovalService.ubah(props.row.id, payload())
    else await cutiPetugasApprovalService.tambah(payload())
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
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="pa-form-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ edit ? 'Ubah' : 'Tambah' }} Petugas Approval Cuti</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Pemohon dan petugas yang memproses cuti pada layanan cuti.</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <p v-if="memuat" class="py-6 text-center text-body2 text-slate-500" data-testid="pa-form-memuat">Memuat…</p>

        <form v-else :class="FORM_GRID_CLASS" novalidate data-testid="pa-form" @submit.prevent="simpan">
          <p v-if="umum" role="alert" :class="FORM_ALERT_CLASS" data-testid="pa-form-error">{{ umum }}</p>
          <UiNotice v-if="errors.id_jenis_pa" tone="danger" :class="FORM_WIDE_CLASS">{{ errors.id_jenis_pa }}</UiNotice>

          <p class="md:col-span-2 text-caption font-semibold uppercase tracking-wide text-slate-500">Pemohon</p>
          <UiSelect v-model="isian.id_jenis_pegawai" label="Jenis Pegawai" :options="jenisOpsi" required :state="state('id_jenis_pegawai')" :help-text="pesan('id_jenis_pegawai')" data-testid="pa-jenis-pegawai" />
          <UiSelect
            v-model="isian.id_unit_pemohon"
            label="Unit Pemohon"
            :options="opsiMaster.unit ?? []"
            :disabled="terkunci !== null"
            searchable
            required
            :state="state('id_unit_pemohon')"
            :help-text="pesan('id_unit_pemohon')"
            data-testid="pa-unit-pemohon"
          />
          <UiSelect
            v-model="isian.id_group_jabatan_pemohon"
            label="Group Jabatan Pemohon"
            :options="opsiMaster.group ?? []"
            required
            :state="state('id_group_jabatan_pemohon')"
            :help-text="pesan('id_group_jabatan_pemohon')"
            data-testid="pa-group-pemohon"
          />
          <UiSelect
            v-if="golDipakai"
            v-model="isian.gol_ruang_pemohon"
            label="Golongan/Ruang Pemohon"
            :options="opsiMaster.pangkat ?? []"
            required
            :state="state('gol_ruang_pemohon')"
            :help-text="pesan('gol_ruang_pemohon')"
            data-testid="pa-gol-pemohon"
          />
          <UiSelect
            v-else
            v-model="isian.id_sub_group_jabatan_pemohon"
            label="Sub Group Jabatan Pemohon"
            :options="opsiMaster.subPemohon ?? []"
            required
            :state="state('id_sub_group_jabatan_pemohon')"
            :help-text="pesan('id_sub_group_jabatan_pemohon')"
            data-testid="pa-sub-pemohon"
          />

          <p class="md:col-span-2 text-caption font-semibold uppercase tracking-wide text-slate-500">Petugas</p>
          <UiSelect v-model="isian.id_unit_petugas" label="Unit Petugas" :options="opsiMaster.unit ?? []" searchable required :state="state('id_unit_petugas')" :help-text="pesan('id_unit_petugas')" data-testid="pa-unit-petugas" />
          <UiSelect
            v-model="isian.id_satker_petugas"
            label="Satker Petugas"
            :options="opsiMaster.satker ?? []"
            searchable
            required
            :state="state('id_satker_petugas')"
            :help-text="pesan('id_satker_petugas')"
            data-testid="pa-satker-petugas"
          />
          <UiSelect
            v-model="isian.id_group_jabatan_petugas"
            label="Group Jabatan Petugas"
            :options="opsiMaster.group ?? []"
            required
            :state="state('id_group_jabatan_petugas')"
            :help-text="pesan('id_group_jabatan_petugas')"
            data-testid="pa-group-petugas"
          />
          <UiSelect
            v-model="isian.id_sub_group_jabatan_petugas"
            label="Sub Group Jabatan Petugas"
            :options="opsiMaster.subPetugas ?? []"
            required
            :state="state('id_sub_group_jabatan_petugas')"
            :help-text="pesan('id_sub_group_jabatan_petugas')"
            data-testid="pa-sub-petugas"
          />

          <UiSelect v-model="isian.mode" label="Jenis Petugas" :options="MODE_OPSI" required data-testid="pa-mode" />
          <UiSelect
            v-if="isian.mode === 'struk'"
            v-model="isian.id_jabatan"
            label="Jabatan Struktural"
            :options="opsiMaster.jabatan ?? []"
            searchable
            required
            :state="state('petugas')"
            :help-text="pesan('petugas')"
            data-testid="pa-jabatan"
          />
          <UiSelect
            v-else-if="isian.mode === 'koord'"
            v-model="isian.id_jabatan_koord"
            label="Jabatan Koordinasi"
            :options="koordOpsi"
            required
            :state="state('petugas')"
            :help-text="pesan('petugas')"
            data-testid="pa-koord"
          />
          <PegawaiPicker v-else :nip="isian.nip" label="Pegawai Petugas" :error="errors.petugas" @pilih="(nip) => (isian.nip = nip)" />

          <div :class="FORM_ACTIONS_CLASS">
            <UiButton variant="secondary" appearance="outline" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :disabled="busy || memuat" data-testid="pa-simpan">{{ busy ? 'Menyimpan…' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
