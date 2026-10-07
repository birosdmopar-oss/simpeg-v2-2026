<script setup lang="ts">
/**
 * Panel filter Daftar Pegawai (§4.1.5, Gambar 18): Periode SKP dan Unit/Satker selebar penuh, lalu dua kolom
 * Status/Jenis Pegawai dan Group/Sub Group Jabatan. Panel ini bisa disembunyikan lewat tombol corong di header
 * kartu (Gambar 19) — pengendalian tampil/sembunyi ada di halaman pemanggil.
 */
import { computed } from 'vue'

import { UiSelect } from '@/shared/ui'

import type { PegawaiFacets } from '../types'

export interface PegawaiFilters {
  periode_skp: string
  unit: string
  status_pegawai: string
  jenis_pegawai: string
  group_jabatan: string
  sub_group_jabatan: string
}

const props = defineProps<{ modelValue: PegawaiFilters; facets: PegawaiFacets }>()
const emit = defineEmits<{ 'update:modelValue': [value: PegawaiFilters] }>()

const subOptions = computed(() => props.facets.sub_group_jabatan[props.modelValue.group_jabatan] ?? [])

function set<K extends keyof PegawaiFilters>(key: K, value: PegawaiFilters[K]): void {
  const next = { ...props.modelValue, [key]: value }
  // Sub group hanya valid untuk group yang dipilih → kosongkan saat group berganti.
  if (key === 'group_jabatan') next.sub_group_jabatan = ''
  emit('update:modelValue', next)
}
</script>

<template>
  <div class="space-y-4 border-b border-slate-200 px-5 pb-5" data-testid="filter-panel">
    <UiSelect
      label="Periode SKP"
      :model-value="modelValue.periode_skp"
      :options="facets.periode_skp"
      placeholder="Pilih periode..."
      @update:model-value="set('periode_skp', $event)"
    />
    <UiSelect
      label="Unit/Satker"
      :model-value="modelValue.unit"
      :options="facets.unit"
      placeholder="Kementerian Pariwisata..."
      clearable
      @update:model-value="set('unit', $event)"
    />
    <div class="grid gap-4 md:grid-cols-2">
      <UiSelect
        label="Status Pegawai"
        :model-value="modelValue.status_pegawai"
        :options="facets.status_pegawai"
        placeholder="Semua Status Pegawai..."
        clearable
        @update:model-value="set('status_pegawai', $event)"
      />
      <UiSelect
        label="Jenis Pegawai"
        :model-value="modelValue.jenis_pegawai"
        :options="facets.jenis_pegawai"
        placeholder="Semua Jenis Pegawai..."
        clearable
        @update:model-value="set('jenis_pegawai', $event)"
      />
      <UiSelect
        label="Group Jabatan"
        :model-value="modelValue.group_jabatan"
        :options="facets.group_jabatan"
        placeholder="Semua Group Jabatan..."
        clearable
        @update:model-value="set('group_jabatan', $event)"
      />
      <UiSelect
        label="Sub Group Jabatan"
        :model-value="modelValue.sub_group_jabatan"
        :options="subOptions"
        placeholder="Semua Sub Group Jabatan..."
        clearable
        :disabled="!modelValue.group_jabatan"
        @update:model-value="set('sub_group_jabatan', $event)"
      />
    </div>
  </div>
</template>
