<script setup lang="ts">
/**
 * Struktur Organisasi (B-19, pemilik WS-2, MAKE-012) — semua pengguna login (legacy `hr/so/full` = sesi,
 * `hr/rwy/jabatan/so` = UL_ALL). Ikut templat legacy so_012025 (keputusan user 13-10-2026): pemilih Kementerian / unit /
 * satker (`?id_unit_satker=`, opsi dari backend, unit 6 tidak bisa dipilih), judul "Struktur Organisasi <unit/satker>",
 * bagan per format (soTemplat.ts). Kondisi hari ini; riwayat per bulan & unduh PDF menyusul (M5/M6, TCPDF).
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiNotice } from '@/shared/ui'

import OrgChartNode from '../components/OrgChartNode.vue'
import { describeApiError } from '../services/apiErrors'
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

const data = ref<StrukturOrganisasi | null>(null)
const memuat = ref(false)
const error = ref('')
let token = 0

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  error.value = ''
  try {
    const hasil = await strukturService.bagan(pilihanAktif.value)
    if (saya === token) data.value = hasil
  } catch (e) {
    if (saya !== token) return
    data.value = null
    error.value = describeApiError(e).message
  } finally {
    if (saya === token) memuat.value = false
  }
}

watch(pilihanAktif, () => void muat(), { immediate: true })

const bagan = computed(() => (data.value ? susunBagan(data.value) : []))
const indent = (level: number): string => (level > 1 ? '─── ' : '─ ')
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard title="Struktur Organisasi">
      <div class="mb-4 max-w-xl">
        <label for="struktur-pilihan" class="mb-1 block text-body2 font-medium text-slate-700">Unit / Satuan Kerja</label>
        <select
          id="struktur-pilihan"
          v-model="pilihanAktif"
          class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-body1"
          data-testid="struktur-pilihan"
        >
          <option value="0">{{ data?.kementerian || 'Kementerian' }}</option>
          <option v-for="p in data?.pilihan ?? []" :key="p.value" :value="p.value" :disabled="p.disabled">
            {{ indent(p.level) }}{{ p.label }}
          </option>
        </select>
      </div>

      <UiNotice v-if="error" tone="danger" data-testid="struktur-error">{{ error }}</UiNotice>
      <p v-else-if="memuat && !data" class="text-body2 text-slate-500">Memuat struktur organisasi…</p>
      <template v-else-if="data">
        <h2 class="mb-1 text-center text-h5 text-slate-900" data-testid="struktur-judul">
          Struktur Organisasi<br />{{ data.judul }}
        </h2>
        <p class="mb-4 text-center text-caption text-slate-500" data-testid="struktur-periode">
          Kondisi {{ data.tanggal }}<template v-if="data.periode"> · Periode struktur: {{ data.periode.periode_struktur_jabatan }}</template>
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
