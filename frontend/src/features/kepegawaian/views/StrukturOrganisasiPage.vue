<script setup lang="ts">
/**
 * Halaman Struktur Organisasi (§4.1.2, Gambar 13–14) — Fase 3, B-19. Akses: semua role login (hr/so/full).
 * Judul + tombol Export (popup "Export PDF"), pemilih unit, lalu bagan organisasi yang bisa digulir dan di-zoom.
 *
 * DATA CONTOH: bagan berasal dari mock sampai endpoint B-19 ada. Export belum tersambung (pesan ditampilkan).
 */
import { Maximize, Minus, Plus } from 'lucide-vue-next'
import { computed, nextTick, onMounted, ref, watch } from 'vue'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiExportMenu, UiNotice, UiSelect } from '@/shared/ui'

import OrgChartNode from '../components/OrgChartNode.vue'
import { pegawaiService } from '../services/pegawai.service'
import type { OrgNode, SelectOptionItem } from '../types'

const roots = ref<SelectOptionItem[]>([])
const rootId = ref('')
const tree = ref<OrgNode | null>(null)
const loading = ref(true)
const notice = ref<string | null>(null)
const zoom = ref(1)
const scroller = ref<HTMLElement | null>(null)
const content = ref<HTMLElement | null>(null)

const ZOOM_MIN = 0.3
const ZOOM_MAX = 1.5
const subtitle = computed(() => tree.value?.jabatan.replace(/^Menteri /, 'Kementerian ') ?? '')

let requestId = 0
async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  const result = await pegawaiService.orgTree(rootId.value || undefined)
  if (current !== requestId) return
  tree.value = result
  loading.value = false
  await nextTick()
  await fit()
}

/** Bagan bisa jauh lebih lebar dari layar: pusatkan gulir agar simpul akar langsung terlihat. */
function centerScroll(): void {
  const box = scroller.value
  if (box) box.scrollLeft = Math.max(0, (box.scrollWidth - box.clientWidth) / 2)
}

/** Skala bagan supaya muat selebar wadah (tidak lebih kecil dari ZOOM_MIN), lalu pusatkan. */
async function fit(): Promise<void> {
  zoom.value = 1
  await nextTick()
  const box = scroller.value
  const el = content.value
  // Lebar 0 = belum ada layout (tab tersembunyi/jsdom): biarkan 100% daripada menghitung rasio tak terhingga.
  if (!box || !el || el.scrollWidth === 0) return
  const ratio = (box.clientWidth - 40) / el.scrollWidth
  zoom.value = Math.min(1, Math.max(ZOOM_MIN, Math.floor(ratio * 10) / 10))
  await nextTick()
  centerScroll()
}

onMounted(async () => {
  roots.value = await pegawaiService.orgRoots()
  await load()
})
watch(rootId, load)

function changeZoom(delta: number): void {
  zoom.value = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Math.round((zoom.value + delta) * 10) / 10))
  void nextTick(centerScroll)
}

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Struktur Organisasi' }]
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="notice" tone="info" dismissible class="mb-4" @dismiss="notice = null">{{ notice }}</UiNotice>

    <UiCard title="Struktur Organisasi" flush>
      <template #actions>
        <UiExportMenu @select="notice = 'Export PDF bagan belum tersambung ke backend — akan aktif bersama task B-19.'" />
      </template>

      <div class="mt-4 space-y-5 border-t border-slate-200 pt-5">
        <div class="px-5">
          <UiSelect
            v-model="rootId"
            :options="roots"
            placeholder="Kementerian Pariwisata..."
            clearable
            aria-label="Pilih unit organisasi"
          />
        </div>

        <div class="flex flex-wrap items-end justify-between gap-3 border-t border-slate-200 px-5 pt-5">
          <div class="mx-auto text-center">
            <h2 class="text-h3 text-slate-800">Struktur Organisasi</h2>
            <p class="text-body1 text-slate-500">{{ subtitle }}</p>
            <span class="mx-auto mt-2 block h-0.5 w-28 rounded-full bg-gradient-to-r from-brand-primary via-brand-primary to-brand-secondary" aria-hidden="true" />
          </div>

          <div class="flex items-center gap-1" role="group" aria-label="Zoom bagan">
            <UiButton size="sm" appearance="soft" variant="secondary" aria-label="Perkecil" :disabled="zoom <= ZOOM_MIN" data-testid="zoom-out" @click="changeZoom(-0.1)">
              <Minus class="h-4 w-4" aria-hidden="true" />
            </UiButton>
            <span class="w-12 text-center text-body2 text-slate-600" data-testid="zoom-level" aria-live="polite">{{ Math.round(zoom * 100) }}%</span>
            <UiButton size="sm" appearance="soft" variant="secondary" aria-label="Sesuaikan ukuran bagan" title="Sesuaikan ukuran" data-testid="zoom-fit" @click="fit">
              <Maximize class="h-4 w-4" aria-hidden="true" />
            </UiButton>
            <UiButton size="sm" appearance="soft" variant="secondary" aria-label="Perbesar" :disabled="zoom >= ZOOM_MAX" data-testid="zoom-in" @click="changeZoom(0.1)">
              <Plus class="h-4 w-4" aria-hidden="true" />
            </UiButton>
          </div>
        </div>

        <div ref="scroller" class="scrollbar-slim overflow-auto px-5 pb-8 pt-4" data-testid="org-scroll">
          <div v-if="loading" class="h-56 animate-pulse rounded-xl bg-slate-100" aria-busy="true" aria-label="Memuat bagan" />
          <div v-else-if="tree" ref="content" class="min-w-max" :style="{ zoom }">
            <ul class="org-tree flex justify-center" aria-label="Bagan struktur organisasi">
              <OrgChartNode :node="tree" />
            </ul>
          </div>
        </div>
      </div>
    </UiCard>
  </RedesignShell>
</template>
