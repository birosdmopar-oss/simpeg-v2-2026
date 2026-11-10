<script setup lang="ts" generic="T">
/**
 * Panel carousel bersama (F-13 MAKE-087; PRD Fase 7 v2.0 §3: `shared/ui/UiCarouselPanel` milik Jalur D) — pengganti carousel
 * Bootstrap legacy (kartu ulang tahun `dashboard_ad.php:245-380`, yang punya tombol Pause/Play). Pola WAI-ARIA Carousel:
 * - wadah `role="region"` + `aria-roledescription="carousel"` + `aria-label` (prop `label`);
 * - tiap slide `role="group"` + `aria-roledescription="slide"` + `aria-label="i dari n"`; hanya slide aktif yang terlihat;
 * - tombol Sebelumnya/Berikutnya, indikator per slide (`aria-current`), tombol Jeda/Putar (`aria-pressed`) bila autoplay;
 * - keyboard di wadah: ←/→ pindah, Home/End ke awal/akhir;
 * - autoplay (bawaan 5 detik) berhenti saat hover/fokus di dalam panel, saat dijeda, dan bila `prefers-reduced-motion`;
 *   live region `aria-live` = `off` selama berputar otomatis, `polite` saat manual.
 * Isi slide lewat slot ber-scope `#item="{ item, index }"`; daftar kosong → slot `empty`.
 */
import { ChevronLeft, ChevronRight, Pause, Play } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    items: T[]
    label: string
    /** Selang autoplay (ms); 0 = tanpa autoplay. */
    interval?: number
  }>(),
  { interval: 5000 },
)

defineSlots<{
  item(props: { item: T; index: number }): unknown
  empty?(): unknown
}>()

const aktif = ref(0)
const dijeda = ref(false)
const ditahan = ref(false)
const kurangiGerak = ref(false)
const idPanel = useId()

const jumlah = computed(() => props.items.length)
const bisaAutoplay = computed(() => props.interval > 0 && jumlah.value > 1 && !kurangiGerak.value)
const berputar = computed(() => bisaAutoplay.value && !dijeda.value && !ditahan.value)

let timer: ReturnType<typeof setInterval> | undefined

function ke(i: number): void {
  if (jumlah.value === 0) return
  aktif.value = ((i % jumlah.value) + jumlah.value) % jumlah.value
}

function berhenti(): void {
  if (timer !== undefined) clearInterval(timer)
  timer = undefined
}

function mulai(): void {
  berhenti()
  if (berputar.value) timer = setInterval(() => ke(aktif.value + 1), props.interval)
}

watch(berputar, mulai)
watch(jumlah, (n) => {
  if (aktif.value >= n) aktif.value = 0
})

function onKey(e: KeyboardEvent): void {
  const aksi: Record<string, () => void> = {
    ArrowLeft: () => ke(aktif.value - 1),
    ArrowRight: () => ke(aktif.value + 1),
    Home: () => ke(0),
    End: () => ke(jumlah.value - 1),
  }
  const fn = aksi[e.key]
  if (fn && jumlah.value > 1) {
    e.preventDefault()
    fn()
  }
}

function onFocusOut(e: FocusEvent): void {
  const wadah = e.currentTarget as HTMLElement
  if (!wadah.contains(e.relatedTarget as Node | null)) ditahan.value = false
}

let media: MediaQueryList | null = null
const onMedia = (e: MediaQueryListEvent): void => {
  kurangiGerak.value = e.matches
}

onMounted(() => {
  if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    media = window.matchMedia('(prefers-reduced-motion: reduce)')
    kurangiGerak.value = media.matches
    media.addEventListener?.('change', onMedia)
  }
  mulai()
})

onBeforeUnmount(() => {
  berhenti()
  media?.removeEventListener?.('change', onMedia)
})
</script>

<template>
  <section
    role="region"
    aria-roledescription="carousel"
    :aria-label="label"
    class="relative flex flex-col gap-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
    tabindex="0"
    data-testid="ui-carousel"
    @keydown="onKey"
    @mouseenter="ditahan = true"
    @mouseleave="ditahan = false"
    @focusin="ditahan = true"
    @focusout="onFocusOut"
  >
    <div v-if="jumlah === 0" data-testid="ui-carousel-kosong">
      <slot name="empty" />
    </div>

    <template v-else>
      <div :id="idPanel" :aria-live="berputar ? 'off' : 'polite'">
        <div
          v-for="(item, i) in items"
          v-show="i === aktif"
          :key="i"
          role="group"
          aria-roledescription="slide"
          :aria-label="`${i + 1} dari ${jumlah}`"
          :data-testid="`ui-carousel-slide-${i}`"
        >
          <slot name="item" :item="item" :index="i" />
        </div>
      </div>

      <div v-if="jumlah > 1" class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-1">
          <button
            v-if="bisaAutoplay"
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
            :aria-label="dijeda ? 'Putar otomatis' : 'Jeda putar otomatis'"
            :aria-pressed="dijeda"
            data-testid="ui-carousel-jeda"
            @click="dijeda = !dijeda"
          >
            <Play v-if="dijeda" class="h-4 w-4" aria-hidden="true" />
            <Pause v-else class="h-4 w-4" aria-hidden="true" />
          </button>
          <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
            aria-label="Sebelumnya"
            :aria-controls="idPanel"
            data-testid="ui-carousel-sebelum"
            @click="ke(aktif - 1)"
          >
            <ChevronLeft class="h-4 w-4" aria-hidden="true" />
          </button>
          <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
            aria-label="Berikutnya"
            :aria-controls="idPanel"
            data-testid="ui-carousel-berikut"
            @click="ke(aktif + 1)"
          >
            <ChevronRight class="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
        <div class="flex flex-wrap items-center gap-1.5" data-testid="ui-carousel-indikator">
          <button
            v-for="(_, i) in items"
            :key="i"
            type="button"
            class="h-2 w-2 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
            :class="i === aktif ? 'bg-brand-tertiary' : 'bg-slate-300'"
            :aria-label="`Tampilkan slide ${i + 1}`"
            :aria-current="i === aktif ? 'true' : undefined"
            @click="ke(i)"
          />
        </div>
      </div>
    </template>
  </section>
</template>
