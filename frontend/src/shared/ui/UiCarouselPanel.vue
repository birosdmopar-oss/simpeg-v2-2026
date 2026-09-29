<script setup lang="ts">
/**
 * Panel carousel (Dashboard Admin §4.1.1, Gambar 12: "Masa Kerja" dan "Berita Terbaru"): kolom kiri berisi judul,
 * subjudul, tombol ‹ › (dan ilustrasi opsional), kolom kanan barisan kartu yang digulir per kartu (scroll-snap).
 * Tombol ‹ › nonaktif di ujung. Keyboard: barisan kartu bisa difokus dan digulir dengan panah.
 *
 * Reusable.
 */
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { onMounted, ref } from 'vue'

defineProps<{ title: string; subtitle?: string }>()

const row = ref<HTMLElement | null>(null)
const atStart = ref(true)
const atEnd = ref(false)

function update(): void {
  const el = row.value
  if (!el) return
  atStart.value = el.scrollLeft <= 2
  atEnd.value = el.scrollLeft + el.clientWidth >= el.scrollWidth - 2
}

function scroll(direction: -1 | 1): void {
  const el = row.value
  if (!el) return
  const card = el.firstElementChild as HTMLElement | null
  const step = (card?.getBoundingClientRect().width ?? 280) + 16
  el.scrollBy?.({ left: direction * step, behavior: 'smooth' })
}

onMounted(update)
</script>

<template>
  <section class="grid gap-5 rounded-card border border-slate-200 bg-white p-5 shadow-card lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)]" :aria-label="title">
    <div class="flex flex-col justify-between gap-4">
      <div>
        <slot name="illustration" />
        <h2 class="text-h5 text-slate-900">{{ title }}</h2>
        <p v-if="subtitle" class="mt-1 text-body1 text-slate-500">{{ subtitle }}</p>
      </div>
      <div class="flex gap-2">
        <button
          type="button"
          class="grid h-9 w-9 place-items-center rounded-lg bg-brand-tertiary/10 text-brand-tertiary transition hover:bg-brand-tertiary/20 disabled:opacity-40"
          :disabled="atStart"
          aria-label="Sebelumnya"
          data-testid="carousel-prev"
          @click="scroll(-1)"
        >
          <ChevronLeft class="h-5 w-5" aria-hidden="true" />
        </button>
        <button
          type="button"
          class="grid h-9 w-9 place-items-center rounded-lg bg-brand-tertiary/10 text-brand-tertiary transition hover:bg-brand-tertiary/20 disabled:opacity-40"
          :disabled="atEnd"
          aria-label="Berikutnya"
          data-testid="carousel-next"
          @click="scroll(1)"
        >
          <ChevronRight class="h-5 w-5" aria-hidden="true" />
        </button>
      </div>
    </div>

    <div
      ref="row"
      class="scrollbar-slim flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2"
      tabindex="0"
      :aria-label="`Daftar ${title}`"
      data-testid="carousel-row"
      @scroll.passive="update"
    >
      <slot />
    </div>
  </section>
</template>
