<script setup lang="ts">
/**
 * Struktur Organisasi (B-19, pemilik WS-2, MAKE-012) — semua pengguna login (legacy `hr/so/full` = sesi,
 * `hr/rwy/jabatan/so` = UL_ALL). Bagan pohon dari `GET struktur-organisasi` (struktur_jabatan periode aktif; pejabat
 * definitif, atau Plt/Plh yang berlaku hari ini). Filter unit → satuan kerja (opsi master aktif).
 * [V2] Hanya kondisi saat ini; legacy bisa mundur per bulan dan mengekspor PDF (menyusul).
 */
import { onMounted, ref, watch } from 'vue'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { masterOptions } from '@/shared/services/masterOptions'
import { UiCard, UiNotice, UiSelect } from '@/shared/ui'

import OrgChartNode from '../components/OrgChartNode.vue'
import { describeApiError } from '../services/apiErrors'
import { strukturService } from '../services/struktur.service'
import type { StrukturOrganisasi } from '../types'

type SelectOption = { value: string; label: string }

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Struktur Organisasi' }]

const idUnit = ref('')
const idSatker = ref('')
const unitOptions = ref<SelectOption[]>([])
const satkerOptions = ref<SelectOption[]>([])
const data = ref<StrukturOrganisasi | null>(null)
const memuat = ref(false)
const error = ref('')
let token = 0

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  error.value = ''
  try {
    const hasil = await strukturService.pohon({ id_unit: idUnit.value, id_satker: idSatker.value })
    if (saya === token) data.value = hasil
  } catch (e) {
    if (saya !== token) return
    data.value = null
    error.value = describeApiError(e).message
  } finally {
    if (saya === token) memuat.value = false
  }
}

const keOpsi = (rows: { id: string; nama: string }[]): SelectOption[] => rows.map((r) => ({ value: r.id, label: r.nama }))

watch(idUnit, async (unit) => {
  idSatker.value = ''
  satkerOptions.value = []
  if (unit) {
    try {
      satkerOptions.value = keOpsi(await masterOptions('satker', unit))
    } catch {
      // Opsi satker gagal dimuat → filter cukup per unit.
    }
  }
  void muat()
})

watch(idSatker, () => void muat())

onMounted(async () => {
  void muat()
  try {
    unitOptions.value = keOpsi(await masterOptions('unit'))
  } catch {
    // Tanpa opsi unit → bagan lengkap tetap tampil.
  }
})
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard title="Struktur Organisasi">
      <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <UiSelect v-model="idUnit" :options="unitOptions" label="Unit Kerja" placeholder="Semua unit" clearable data-testid="struktur-filter-unit" />
        <UiSelect
          v-model="idSatker"
          :options="satkerOptions"
          label="Satuan Kerja"
          placeholder="Semua satuan kerja"
          clearable
          :disabled="!idUnit"
          data-testid="struktur-filter-satker"
        />
      </div>

      <p v-if="data?.periode" class="mb-3 text-caption text-slate-500" data-testid="struktur-periode">
        Periode struktur: {{ data.periode.periode_struktur_jabatan }}
      </p>

      <UiNotice v-if="error" tone="danger" data-testid="struktur-error">{{ error }}</UiNotice>
      <p v-else-if="memuat && !data" class="text-body2 text-slate-500">Memuat struktur organisasi…</p>
      <UiNotice v-else-if="data && !data.periode" tone="warning" data-testid="struktur-tanpa-periode">
        Belum ada periode struktur jabatan yang aktif.
      </UiNotice>
      <p v-else-if="data && data.nodes.length === 0" class="text-body2 text-slate-500" data-testid="struktur-kosong">
        Tidak ada jabatan struktural pada pilihan ini.
      </p>
      <div v-else-if="data" class="overflow-x-auto pb-4" :aria-busy="memuat">
        <ul class="org-tree flex min-w-max justify-center gap-6" data-testid="struktur-pohon">
          <OrgChartNode v-for="node in data.nodes" :key="node.id" :node="node" />
        </ul>
      </div>
    </UiCard>
  </RedesignShell>
</template>
