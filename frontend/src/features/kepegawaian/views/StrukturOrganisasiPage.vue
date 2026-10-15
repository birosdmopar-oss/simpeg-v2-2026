<script setup lang="ts">
/**
 * Struktur Organisasi (B-19, pemilik WS-2, MAKE-012) — semua pengguna login (legacy `hr/so/full` = sesi,
 * `hr/rwy/jabatan/so` = UL_ALL). Ikut templat legacy so_012025 (keputusan user 13-10-2026): pemilih Kementerian / unit /
 * satker (`?id_unit_satker=`, opsi dari backend, unit 6 tidak bisa dipilih), judul "Struktur Organisasi <unit/satker>",
 * bagan per format (soTemplat.ts). Pemilih bulan sejak Jan 2022 (`?bulan=`, MAKE-013) dan Unduh PDF (TCPDF).
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiSelect, type SelectOption } from '@/shared/ui'

import OrgChartNode from '../components/OrgChartNode.vue'
import { describeApiError } from '../services/apiErrors'
import { simpanBerkas } from '../services/berkasUnduhan'
import { strukturService } from '../services/struktur.service'
import { susunBagan } from '../soTemplat'
import type { StrukturOrganisasi } from '../types'

const route = useRoute()
const router = useRouter()

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Struktur Organisasi' }]

const pilihanAktif = computed<string>({
  get: () => (typeof route.query.id_unit_satker === 'string' && route.query.id_unit_satker !== '' ? route.query.id_unit_satker : '0'),
  set: (value) => void router.replace({ query: { ...route.query, id_unit_satker: value === '0' ? undefined : value } }),
})

const bulan = computed<string>({
  get: () => (typeof route.query.bulan === 'string' ? route.query.bulan : ''),
  set: (value) => void router.replace({ query: { ...route.query, bulan: value || undefined } }),
})

const data = ref<StrukturOrganisasi | null>(null)
const mengunduh = ref(false)
const memuat = ref(false)
const error = ref('')
/** Galat unduh PDF terpisah dari galat muat: bagan tetap tampil (review FE-2 MAKE-013). */
const errorUnduh = ref('')
let token = 0

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  error.value = ''
  try {
    const hasil = await strukturService.bagan(pilihanAktif.value, bulan.value || undefined)
    if (saya === token) data.value = hasil
  } catch (e) {
    if (saya !== token) return
    data.value = null
    error.value = describeApiError(e).message
  } finally {
    if (saya === token) memuat.value = false
  }
}

watch([pilihanAktif, bulan], () => void muat(), { immediate: true })

const opsiPilihan = computed<SelectOption[]>(() => [
  { value: '0', label: data.value?.kementerian || 'Kementerian' },
  ...(data.value?.pilihan ?? []).map((p) => ({ value: p.value, label: `${indent(p.level)}${p.label}`, disabled: p.disabled })),
])
const opsiBulan = computed<SelectOption[]>(() => (data.value?.bulan_tersedia ?? []).map((b) => ({ value: b.value, label: b.label })))

async function unduh(): Promise<void> {
  mengunduh.value = true
  errorUnduh.value = ''
  try {
    const berkas = await strukturService.pdf(pilihanAktif.value, data.value?.bulan)
    simpanBerkas(berkas)
  } catch (e) {
    errorUnduh.value = describeApiError(e).message
  } finally {
    mengunduh.value = false
  }
}

const bagan = computed(() => (data.value ? susunBagan(data.value) : []))
const indent = (level: number): string => (level > 1 ? '─── ' : '─ ')
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard title="Struktur Organisasi">
      <template #actions>
        <UiButton appearance="soft" :loading="mengunduh" :disabled="!data" data-testid="struktur-unduh" @click="unduh">Unduh PDF</UiButton>
      </template>
      <div class="mb-4 grid max-w-3xl gap-3 md:grid-cols-[2fr_1fr]">
        <!-- CR-049 D-F14: UiSelect (komponen redesign), bukan <select> native. -->
        <UiSelect v-model="pilihanAktif" label="Unit / Satuan Kerja" :options="opsiPilihan" placeholder="Pilih unit / satuan kerja" data-testid="struktur-pilihan" />
        <UiSelect v-model="bulan" label="Bulan" :options="opsiBulan" placeholder="Bulan ini" clearable data-testid="struktur-bulan" />
      </div>

      <UiNotice v-if="errorUnduh" tone="danger" class="mb-3" data-testid="struktur-error-unduh">{{ errorUnduh }}</UiNotice>
      <UiNotice v-if="error" tone="danger" data-testid="struktur-error">{{ error }}</UiNotice>
      <p v-else-if="memuat && !data" class="text-body2 text-slate-500">Memuat struktur organisasi…</p>
      <template v-else-if="data">
        <h2 class="mb-1 text-center text-h5 text-slate-900" data-testid="struktur-judul">
          Struktur Organisasi<br />{{ data.judul }}
        </h2>
        <p class="mb-4 text-center text-caption text-slate-500" data-testid="struktur-periode">
          Kondisi {{ tgl(data.tanggal) }}<template v-if="data.periode"> · Periode struktur: {{ data.periode.periode_struktur_jabatan }}</template>
        </p>
        <UiNotice v-if="!data.periode" tone="warning" data-testid="struktur-tanpa-periode">
          Belum ada periode struktur jabatan yang aktif.
        </UiNotice>
        <p v-else-if="bagan.length === 0" class="text-body2 text-slate-500" data-testid="struktur-kosong">
          Tidak ada jabatan struktural pada pilihan ini.
        </p>
        <div v-else class="overflow-x-auto pb-4" :aria-busy="memuat">
          <ul
            class="org-tree flex min-w-max justify-center gap-6"
            :data-format="data.format"
            :aria-label="`Bagan struktur organisasi ${data.judul}`"
            data-testid="struktur-pohon"
          >
            <OrgChartNode v-for="node in bagan" :key="node.id" :node="node" />
          </ul>
        </div>
      </template>
    </UiCard>
  </RedesignShell>
</template>
