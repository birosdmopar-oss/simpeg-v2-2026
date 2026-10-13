<script setup lang="ts">
/**
 * Panel filter Daftar Pegawai (B-20 penutup, MAKE-014; legacy `views/hr/employee/list.php` :86-157): status pegawai,
 * unit → satuan kerja (bertingkat), jenis pegawai, dan urutan. Opsi dari master options (G-02/G-03, entri aktif).
 * Hanya UX — lingkup pemanggil (PegawaiScope) tetap ditegakkan server, jadi role 3 hanya mendapat pegawai dalam
 * lingkupnya meski memilih unit lain.
 */
import { onMounted, ref, watch } from 'vue'

import { masterService } from '@/features/master-data/services/master.service'
import { UiSelect, type SelectOption } from '@/shared/ui'

import type { PegawaiListQuery } from '../types'

export type PegawaiFilter = Required<Pick<PegawaiListQuery, 'status' | 'id_unit' | 'id_satker' | 'id_jenis_pegawai'>> & {
  sort: NonNullable<PegawaiListQuery['sort']>
  order: NonNullable<PegawaiListQuery['order']>
}

const model = defineModel<PegawaiFilter>({ required: true })

const STATUS: SelectOption[] = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Tidak Aktif' },
]
const URUT: SelectOption[] = [
  { value: 'nama', label: 'Nama' },
  { value: 'nip', label: 'NIP' },
  { value: 'jabatan', label: 'Jabatan' },
  { value: 'unit', label: 'Unit Kerja' },
  { value: 'satker', label: 'Satuan Kerja' },
  { value: 'gol_ruang', label: 'Golongan' },
]
const ARAH: SelectOption[] = [
  { value: 'asc', label: 'Naik (A–Z)' },
  { value: 'desc', label: 'Turun (Z–A)' },
]

const unit = ref<SelectOption[]>([])
const satker = ref<SelectOption[]>([])
const jenis = ref<SelectOption[]>([])

const keOpsi = (list: { id: string; nama: string }[]): SelectOption[] => list.map((o) => ({ value: o.id, label: o.nama }))

async function muat(entity: string, parent?: string): Promise<SelectOption[]> {
  try {
    return keOpsi(await masterService.options(entity, parent))
  } catch {
    // Opsi gagal dimuat: filter itu kosong; daftar tetap bisa dipakai.
    return []
  }
}

onMounted(async () => {
  ;[unit.value, jenis.value] = await Promise.all([muat('unit'), muat('jenis-pegawai')])
  if (model.value.id_unit) satker.value = await muat('satker', model.value.id_unit)
})

watch(
  () => model.value.id_unit,
  async (idUnit, lama) => {
    if (idUnit === lama) return
    satker.value = idUnit ? await muat('satker', idUnit) : []
    if (model.value.id_satker && !satker.value.some((o) => String(o.value) === model.value.id_satker)) set('id_satker', '')
  },
)

function set<K extends keyof PegawaiFilter>(key: K, value: PegawaiFilter[K]): void {
  model.value = { ...model.value, [key]: value }
}
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6" data-testid="pegawai-filter">
    <UiSelect
      :model-value="model.status"
      label="Status Pegawai"
      placeholder="Semua"
      clearable
      :options="STATUS"
      data-testid="pegawai-filter-status"
      @update:model-value="(v) => set('status', v)"
    />
    <UiSelect
      :model-value="model.id_unit"
      label="Unit Kerja"
      placeholder="Semua unit"
      clearable
      :options="unit"
      data-testid="pegawai-filter-unit"
      @update:model-value="(v) => set('id_unit', v)"
    />
    <UiSelect
      :model-value="model.id_satker"
      label="Satuan Kerja"
      :placeholder="model.id_unit ? 'Semua satker' : 'Pilih unit dulu'"
      clearable
      :disabled="!model.id_unit"
      :options="satker"
      data-testid="pegawai-filter-satker"
      @update:model-value="(v) => set('id_satker', v)"
    />
    <UiSelect
      :model-value="model.id_jenis_pegawai"
      label="Jenis Pegawai"
      placeholder="Semua"
      clearable
      :options="jenis"
      data-testid="pegawai-filter-jenis"
      @update:model-value="(v) => set('id_jenis_pegawai', v)"
    />
    <UiSelect
      :model-value="model.sort"
      label="Urutkan"
      :options="URUT"
      data-testid="pegawai-filter-sort"
      @update:model-value="(v) => set('sort', v as PegawaiFilter['sort'])"
    />
    <UiSelect
      :model-value="model.order"
      label="Arah"
      :options="ARAH"
      data-testid="pegawai-filter-order"
      @update:model-value="(v) => set('order', v as PegawaiFilter['order'])"
    />
  </div>
</template>
