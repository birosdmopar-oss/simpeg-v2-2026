<script setup lang="ts">
/**
 * Banner beranda (F-05, PRD Fase 7 v2.0 §5.4): carousel gambar rasio 4:1 dari banner aktif milik aktor (urut `order`).
 * Navigasi sebelumnya/berikutnya + indikator + panah keyboard; autoplay dapat dijeda (hover/fokus + tombol jeda) dan
 * TIDAK berjalan bila pengguna meminta gerak berkurang (prefers-reduced-motion). Kosong/403/galat → tidak merender apa pun.
 */
import { ChevronLeft, ChevronRight, Pause, Play } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { kontenService } from '../services/konten.service'
import type { BannerAktif } from '../types'

/** `class` dari parent diteruskan otomatis ke elemen akar (fallthrough attrs). */
const props = withDefaults(defineProps<{ intervalMs?: number }>(), { intervalMs: 6000 })

const daftar = ref<BannerAktif[]>([])
const indeks = ref(0)
const dijeda = ref(false) // dijeda pengguna lewat tombol
const tertahan = ref(false) // hover/fokus sementara
const gerakBerkurang = ref(false)
let timer: ReturnType<typeof setInterval> | null = null

const aktif = computed<BannerAktif | undefined>(() => daftar.value[indeks.value])
const berjalan = computed(() => !gerakBerkurang.value && !dijeda.value && !tertahan.value && daftar.value.length > 1)

function bersihkanTimer(): void {
  if (timer !== null) {
    clearInterval(timer)
    timer = null
  }
}

function atur(): void {
  bersihkanTimer()
  if (berjalan.value) timer = setInterval(berikutnya, props.intervalMs)
}

function pergi(i: number): void {
  const n = daftar.value.length
  if (n === 0) return
  indeks.value = ((i % n) + n) % n
}

function berikutnya(): void {
  pergi(indeks.value + 1)
}

function sebelumnya(): void {
  pergi(indeks.value - 1)
}

function toggleJeda(): void {
  dijeda.value = !dijeda.value
  atur()
}

function tahan(v: boolean): void {
  tertahan.value = v
  atur()
}

function onKey(e: KeyboardEvent): void {
  if (e.key === 'ArrowRight') {
    e.preventDefault()
    berikutnya()
  } else if (e.key === 'ArrowLeft') {
    e.preventDefault()
    sebelumnya()
  }
}

onMounted(async () => {
  try {
    gerakBerkurang.value = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
  } catch {
    gerakBerkurang.value = false
  }
  try {
    daftar.value = await kontenService.bannerAktif()
    atur()
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[konten] banner aktif gagal dimuat', e)
  }
})

onBeforeUnmount(bersihkanTimer)
</script>

<template>
  <section
    v-if="aktif"
    class="relative overflow-hidden rounded-2xl bg-slate-100"
    role="region"
    aria-roledescription="carousel"
    aria-label="Banner"
    tabindex="0"
    data-testid="konten-banner"
    @keydown="onKey"
    @mouseenter="tahan(true)"
    @mouseleave="tahan(false)"
    @focusin="tahan(true)"
    @focusout="tahan(false)"
  >
    <a v-if="aktif.url" :href="aktif.url" target="_blank" rel="noopener noreferrer" data-testid="konten-banner-tautan">
      <img :src="aktif.gambar_url" :alt="aktif.keterangan ?? 'Banner'" class="aspect-[4/1] w-full object-cover" />
    </a>
    <img v-else :src="aktif.gambar_url" :alt="aktif.keterangan ?? 'Banner'" class="aspect-[4/1] w-full object-cover" />

    <template v-if="daftar.length > 1">
      <button
        type="button"
        class="absolute left-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/80 text-slate-700 shadow hover:bg-white"
        aria-label="Banner sebelumnya"
        data-testid="konten-banner-sebelumnya"
        @click="sebelumnya"
      >
        <ChevronLeft class="h-4 w-4" aria-hidden="true" />
      </button>
      <button
        type="button"
        class="absolute right-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/80 text-slate-700 shadow hover:bg-white"
        aria-label="Banner berikutnya"
        data-testid="konten-banner-berikutnya"
        @click="berikutnya"
      >
        <ChevronRight class="h-4 w-4" aria-hidden="true" />
      </button>
      <div class="absolute inset-x-0 bottom-2 flex items-center justify-center gap-2">
        <button
          v-for="(b, i) in daftar"
          :key="b.id"
          type="button"
          class="h-2 w-2 rounded-full"
          :class="i === indeks ? 'bg-white' : 'bg-white/50'"
          :aria-label="`Banner ${i + 1} dari ${daftar.length}`"
          :aria-current="i === indeks ? 'true' : undefined"
          data-testid="konten-banner-indikator"
          @click="pergi(i)"
        />
        <button
          v-if="!gerakBerkurang"
          type="button"
          class="ml-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/80 text-slate-700"
          :aria-label="dijeda ? 'Putar banner otomatis' : 'Jeda banner otomatis'"
          data-testid="konten-banner-jeda"
          @click="toggleJeda"
        >
          <Play v-if="dijeda" class="h-3 w-3" aria-hidden="true" />
          <Pause v-else class="h-3 w-3" aria-hidden="true" />
        </button>
      </div>
    </template>
  </section>
</template>
