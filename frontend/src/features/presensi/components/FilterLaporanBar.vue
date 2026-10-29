<script setup lang="ts">
/**
 * Filter bar halaman laporan Presensi 5C (PRD 5C §7.1): Bulan (`UiTextField type="month"`), Unit/Satker (`UiSelect`,
 * hanya bila `bisaPilihUnit` = role 1; role 3/4 dipaksa server), Jenis Pegawai (multi, `UiCheckbox`), dan Cari. Hanya
 * tampilan: nilai lewat `v-model:*`, opsi lewat props (dimuat `useFilterLaporan`). Komponen custom `shared/ui` saja.
 */
import { UiCheckbox, UiSearchInput, UiSelect, UiTextField, type SelectOption } from '@/shared/ui'

withDefaults(
  defineProps<{
    bisaPilihUnit?: boolean
    unitOpsi?: SelectOption[]
    satkerOpsi?: SelectOption[]
    jenisPegawaiOpsi?: SelectOption[]
    tampilCari?: boolean
    placeholderCari?: string
  }>(),
  { bisaPilihUnit: false, unitOpsi: () => [], satkerOpsi: () => [], jenisPegawaiOpsi: () => [], tampilCari: true, placeholderCari: 'Cari nama atau NIP' },
)

const bulan = defineModel<string>('bulan', { required: true })
const idUnit = defineModel<string>('idUnit', { default: '' })
const idSatker = defineModel<string>('idSatker', { default: '' })
const jenisPegawai = defineModel<string[]>('jenisPegawai', { default: () => [] })
const search = defineModel<string>('search', { default: '' })

function ubahJenis(id: string, aktif: boolean): void {
  const tanpa = jenisPegawai.value.filter((v) => v !== id)
  jenisPegawai.value = aktif ? [...tanpa, id] : tanpa
}
</script>

<template>
  <div class="flex flex-col gap-3" data-testid="filter-laporan">
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
      <UiTextField v-model="bulan" type="month" label="Bulan" placeholder="bb/tttt" required />
      <template v-if="bisaPilihUnit">
        <UiSelect v-model="idUnit" label="Unit" placeholder="Semua Unit" :options="unitOpsi" searchable clearable />
        <UiSelect v-model="idSatker" label="Satker" :placeholder="idUnit ? 'Semua Satker di unit ini' : 'Pilih unit dulu'" :options="satkerOpsi" :disabled="!idUnit" searchable clearable />
      </template>
    </div>
    <div v-if="jenisPegawaiOpsi.length > 0 || tampilCari" class="flex flex-wrap items-end justify-between gap-3">
      <fieldset v-if="jenisPegawaiOpsi.length > 0" class="min-w-0" data-testid="filter-jenis-pegawai">
        <legend class="mb-1.5 text-body2 font-medium text-slate-700">Jenis Pegawai</legend>
        <div class="flex flex-wrap gap-x-5 gap-y-2">
          <UiCheckbox
            v-for="o in jenisPegawaiOpsi"
            :key="String(o.value)"
            :model-value="jenisPegawai.includes(String(o.value))"
            :label="o.label"
            @update:model-value="ubahJenis(String(o.value), $event)"
          />
        </div>
      </fieldset>
      <div v-if="tampilCari" class="w-64 max-w-full">
        <UiSearchInput v-model="search" label="Cari pegawai" :placeholder="placeholderCari" />
      </div>
    </div>
  </div>
</template>
