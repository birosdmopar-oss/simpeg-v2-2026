<script setup lang="ts">
/**
 * Panel pencarian lanjutan Daftar Pegawai (B-20 penutup, MAKE-014; legacy `views/hr/employee/form_advsearch.php`,
 * role 1, 3, 4, 5). Baris kriteria: penghubung (AND/OR, mulai baris ke-2) → Jenis Riwayat → Jenis Data (Data Terakhir /
 * Seluruh Riwayat) → Atribut → Operator → Nilai (pilihan master bila atribut berpilihan, selain itu teks; `IN`/`NOT IN`
 * dipisah koma). Katalog & whitelist dari server (`GET pegawai/pencarian/katalog`); galat 422 per `kriteria.<i>.<kunci>`.
 */
import { Plus, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, reactive, ref } from 'vue'

import { masterService } from '@/features/master-data/services/master.service'
import { UiButton, UiNotice, UiSelect, UiTextField, type SelectOption } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { KatalogPencarian, KriteriaPencarian } from '../types'

const props = defineProps<{ errors?: Record<string, string> }>()
const emit = defineEmits<{ cari: [kriteria: KriteriaPencarian[]]; reset: [] }>()

type Baris = KriteriaPencarian & { _kunci: number }

const katalog = ref<KatalogPencarian | null>(null)
const galatKatalog = ref('')
const pilihanMaster = reactive<Record<string, SelectOption[]>>({})
let kunci = 0
const kosong = (): Baris => ({ _kunci: ++kunci, jenis_rwy: '', jenis_data: 'rwy', atribut: '', operator: '=', nilai: '', hubung: 'AND' })
const baris = ref<Baris[]>([kosong()])

const LABEL_DATA: Record<'last' | 'rwy', string> = { last: 'Data Terakhir', rwy: 'Seluruh Riwayat' }
const HUBUNG: SelectOption[] = [
  { value: 'AND', label: 'DAN (AND)' },
  { value: 'OR', label: 'ATAU (OR)' },
]

onMounted(async () => {
  try {
    katalog.value = await pegawaiService.katalogPencarian()
  } catch (e) {
    galatKatalog.value = describeApiError(e).message
  }
})

const opsiJenis = computed<SelectOption[]>(() => (katalog.value?.jenis ?? []).map((j) => ({ value: j.key, label: j.label })))
const opsiOperator = computed<SelectOption[]>(() => (katalog.value?.operator ?? []).map((o) => ({ value: o, label: o })))
const jenisDari = (b: Baris) => katalog.value?.jenis.find((j) => j.key === b.jenis_rwy)
const atributDari = (b: Baris) => jenisDari(b)?.atribut.find((a) => a.field === b.atribut)

function opsiData(b: Baris): SelectOption[] {
  return (jenisDari(b)?.jenis_data ?? []).map((d) => ({ value: d, label: LABEL_DATA[d] }))
}

function opsiAtribut(b: Baris): SelectOption[] {
  return (jenisDari(b)?.atribut ?? []).filter((a) => b.jenis_data === 'rwy' || !a.hanya_rwy).map((a) => ({ value: a.field, label: a.label }))
}

/** Pilihan nilai atribut berpilihan; `null` = isian teks (juga untuk IN/NOT IN/LIKE). */
function opsiNilai(b: Baris): SelectOption[] | null {
  const pilihan = atributDari(b)?.pilihan
  if (!pilihan || ['IN', 'NOT IN', 'LIKE'].includes(b.operator)) return null
  if (Array.isArray(pilihan)) return pilihan.map((p) => ({ value: p.id, label: p.nama }))
  return pilihanMaster[pilihan] ?? []
}

async function muatPilihan(entity: string): Promise<void> {
  if (pilihanMaster[entity]) return
  pilihanMaster[entity] = []
  try {
    pilihanMaster[entity] = (await masterService.options(entity)).map((o) => ({ value: o.id, label: o.nama }))
  } catch {
    // Pilihan gagal dimuat: daftar kosong; pengguna bisa memakai operator IN dengan ID.
  }
}

function setJenis(b: Baris, jenis: string): void {
  b.jenis_rwy = jenis
  const data = jenisDari(b)?.jenis_data ?? ['rwy']
  b.jenis_data = data.includes('last') ? 'last' : 'rwy'
  b.atribut = ''
  b.nilai = ''
}

function setData(b: Baris, data: string): void {
  b.jenis_data = data === 'last' ? 'last' : 'rwy'
  if (!opsiAtribut(b).some((o) => o.value === b.atribut)) b.atribut = ''
}

function setAtribut(b: Baris, atribut: string): void {
  b.atribut = atribut
  b.nilai = ''
  const pilihan = atributDari(b)?.pilihan
  if (typeof pilihan === 'string') void muatPilihan(pilihan)
}

const tambah = (): void => void baris.value.push(kosong())
function hapus(i: number): void {
  baris.value.splice(i, 1)
  if (baris.value.length === 0) baris.value.push(kosong())
}

function cari(): void {
  emit('cari', baris.value.map(({ jenis_rwy, jenis_data, atribut, operator, nilai, hubung }) => ({ jenis_rwy, jenis_data, atribut, operator, nilai, hubung })))
}

function reset(): void {
  baris.value = [kosong()]
  emit('reset')
}

const galat = (i: number, k: string): string => props.errors?.[`kriteria.${i}.${k}`] ?? ''
const galatUmum = computed(() => props.errors?.kriteria ?? '')
</script>

<template>
  <form class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4" data-testid="pencarian-lanjutan" @submit.prevent="cari">
    <UiNotice v-if="galatKatalog" tone="danger">{{ galatKatalog }}</UiNotice>
    <div
      v-for="(b, i) in baris"
      :key="b._kunci"
      class="grid items-end gap-2 md:grid-cols-[7rem_1fr_1fr_1.3fr_7rem_1.5fr_auto]"
      :data-testid="`pencarian-baris-${i}`"
    >
      <UiSelect
        v-if="i > 0"
        :model-value="b.hubung"
        label="Penghubung"
        :options="HUBUNG"
        data-field="hubung"
        :state="galat(i, 'hubung') ? 'error' : 'default'"
        :help-text="galat(i, 'hubung')"
        @update:model-value="(v) => (b.hubung = v === 'OR' ? 'OR' : 'AND')"
      />
      <span v-else class="hidden md:block" aria-hidden="true" />
      <UiSelect
        :model-value="b.jenis_rwy"
        label="Jenis Riwayat"
        placeholder="Pilih"
        :options="opsiJenis"
        data-field="jenis_rwy"
        :state="galat(i, 'jenis_rwy') ? 'error' : 'default'"
        :help-text="galat(i, 'jenis_rwy')"
        @update:model-value="(v) => setJenis(b, v)"
      />
      <UiSelect
        :model-value="b.jenis_data"
        label="Jenis Data"
        :options="opsiData(b)"
        :disabled="!b.jenis_rwy"
        data-field="jenis_data"
        :state="galat(i, 'jenis_data') ? 'error' : 'default'"
        :help-text="galat(i, 'jenis_data')"
        @update:model-value="(v) => setData(b, v)"
      />
      <UiSelect
        :model-value="b.atribut"
        label="Atribut"
        placeholder="Pilih"
        :options="opsiAtribut(b)"
        :disabled="!b.jenis_rwy"
        data-field="atribut"
        :state="galat(i, 'atribut') ? 'error' : 'default'"
        :help-text="galat(i, 'atribut')"
        @update:model-value="(v) => setAtribut(b, v)"
      />
      <UiSelect
        :model-value="b.operator"
        label="Operator"
        :options="opsiOperator"
        data-field="operator"
        :state="galat(i, 'operator') ? 'error' : 'default'"
        :help-text="galat(i, 'operator')"
        @update:model-value="(v) => (b.operator = v)"
      />
      <UiSelect
        v-if="opsiNilai(b)"
        :model-value="b.nilai"
        label="Nilai"
        placeholder="Pilih"
        :options="opsiNilai(b) ?? []"
        data-field="nilai"
        :state="galat(i, 'nilai') ? 'error' : 'default'"
        :help-text="galat(i, 'nilai')"
        @update:model-value="(v) => (b.nilai = v)"
      />
      <UiTextField
        v-else
        v-model="b.nilai"
        label="Nilai"
        :placeholder="['IN', 'NOT IN'].includes(b.operator) ? 'Pisahkan dengan koma' : 'Kata kunci'"
        data-field="nilai"
        :state="galat(i, 'nilai') ? 'error' : 'default'"
        :help-text="galat(i, 'nilai')"
      />
      <button
        type="button"
        class="mb-1 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-danger"
        :aria-label="`Hapus kriteria ${i + 1}`"
        @click="hapus(i)"
      >
        <Trash2 class="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
    <p v-if="galatUmum" role="alert" class="text-caption text-danger">{{ galatUmum }}</p>
    <div class="flex flex-wrap gap-2">
      <UiButton type="button" variant="secondary" data-testid="pencarian-tambah" @click="tambah"><Plus class="h-4 w-4" aria-hidden="true" /> Tambah kriteria</UiButton>
      <UiButton type="submit" data-testid="pencarian-cari">Cari</UiButton>
      <UiButton type="button" variant="secondary" appearance="soft" data-testid="pencarian-reset" @click="reset">Kembali ke daftar</UiButton>
    </div>
  </form>
</template>
