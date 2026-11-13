<script setup lang="ts">
/**
 * Widget chatbot "Vanya" (PRD Fase 8 v1.1 §6.3, §7.10.3; port view widget dev `dev:VP` dengan token desain v2, K-11, tanpa
 * frame Figma). Dipasang sekali di `RedesignShell.vue`; dirender hanya bila gerbang rilis F8-50 terbuka
 * (`ACTIVE_PHASE >= FASE_CHATBOT`), role sesi ∈ `UL_PEGAWAI` (F8-30), dan tidak sedang wajib ganti password (F8-43).
 * Isi percakapan di `stores/percakapan.ts` (memori, bertahan lintas halaman, dikosongkan saat logout).
 * Jawaban HTML hanya lewat `SafeHtml`; pesan pengguna dan teks sistem selalu teks (BP8-14, A2).
 * Launcher membuka/menutup jendela (`dev:VP:382`); Escape menutup dari mana pun selama terbuka (`dev:VP:384`) dan fokus
 * kembali ke launcher [V2].
 */
import { LayoutGrid, Send, Star, X } from 'lucide-vue-next'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import SafeHtml from '@/shared/components/SafeHtml.vue'
import { ACTIVE_PHASE } from '@/shared/layouts/nav.config'

import { CHATBOT_WIDGET_ROLES, FASE_CHATBOT, MAKS_PESAN, PEEK_LAMA_MS, PEEK_TUNDA_MS, TEKS_VANYA } from '../../constants'
import { peekSudahTampil, tandaiPeekTampil } from '../../stores/peek'
import { usePercakapanVanya } from '../../stores/percakapan'
import VanyaAvatar from './VanyaAvatar.vue'
import VanyaPenilaian from './VanyaPenilaian.vue'

const auth = useAuthStore()
const vanya = usePercakapanVanya()
const state = vanya.state

const aktif = computed(
  () => ACTIVE_PHASE >= FASE_CHATBOT && auth.role !== null && CHATBOT_WIDGET_ROLES.includes(auth.role) && !auth.mustChangePassword,
)

const launcherEl = ref<HTMLButtonElement | null>(null)
const inputEl = ref<HTMLInputElement | null>(null)
const isiEl = ref<HTMLElement | null>(null)
const ketikan = ref('')
const kartuHover = ref(false)
const kartuPeek = ref(false)
const timers: ReturnType<typeof setTimeout>[] = []

const kartuTampil = computed(() => !state.terbuka && (kartuHover.value || kartuPeek.value))
const sisa = computed(() => MAKS_PESAN - [...ketikan.value].length)

function fokusInput(): void {
  void nextTick(() => inputEl.value?.focus())
}

function buka(): void {
  kartuPeek.value = false
  vanya.buka()
  fokusInput()
}

function tutup(): void {
  vanya.tutup()
  void nextTick(() => launcherEl.value?.focus())
}

function alihkan(): void {
  if (state.terbuka) tutup()
  else buka()
}

function onDocKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape' && state.terbuka && aktif.value) tutup()
}

async function kirim(): Promise<void> {
  const jalan = vanya.kirim(ketikan.value, MAKS_PESAN)
  if (jalan === null) return
  ketikan.value = ''
  await jalan
  fokusInput()
}

watch(
  () => [state.pesan.length, state.mengetik] as const,
  () => {
    void nextTick(() => {
      const el = isiEl.value
      if (el) el.scrollTop = el.scrollHeight
    })
  },
)

onMounted(() => {
  document.addEventListener('keydown', onDocKeydown)
  if (!aktif.value || state.terbuka || peekSudahTampil()) return
  // Kartu sapaan sekali per sesi browser untuk semua perangkat (`dev:VP:413-422`).
  tandaiPeekTampil()
  timers.push(
    setTimeout(() => {
      kartuPeek.value = true
      timers.push(setTimeout(() => (kartuPeek.value = false), PEEK_LAMA_MS))
    }, PEEK_TUNDA_MS),
  )
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onDocKeydown)
  for (const t of timers) clearTimeout(t)
})

const kelasBalonBot = 'max-w-full whitespace-pre-wrap break-words rounded-2xl rounded-bl-sm border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700'
const kelasChip =
  'rounded-full border px-3 py-1.5 text-left text-xs font-semibold leading-snug focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary disabled:opacity-60'
</script>

<template>
  <div v-if="aktif" class="vanya pointer-events-none fixed bottom-4 right-4 z-30 flex flex-col items-end gap-3 sm:bottom-6 sm:right-6" data-testid="vanya-widget">
    <section
      v-if="state.terbuka"
      id="vanya-jendela"
      role="dialog"
      aria-labelledby="vanya-judul"
      class="vanya-jendela pointer-events-auto flex h-[min(36rem,calc(100dvh-7rem))] w-[min(24rem,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-panel"
      data-testid="vanya-jendela"
    >
      <h2 id="vanya-judul" class="sr-only">{{ TEKS_VANYA.judulDialog }}</h2>
      <header class="flex items-center gap-3 bg-gradient-to-br from-brand-primary to-[#2a4a80] px-4 py-3 text-white">
        <span class="h-11 w-11 shrink-0 overflow-hidden rounded-full border-2 border-white/85"><VanyaAvatar :size="40" /></span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold">{{ TEKS_VANYA.nama }}</p>
          <p class="flex items-center gap-1.5 text-xs text-white/80">
            <span class="inline-block h-2 w-2 rounded-full bg-success" aria-hidden="true" /> {{ TEKS_VANYA.peran }} · {{ TEKS_VANYA.online }}
          </p>
        </div>
        <button
          type="button"
          class="rounded-lg p-1 text-white/80 hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
          aria-label="Tutup chat"
          data-testid="vanya-tutup"
          @click="tutup"
        >
          <X class="h-5 w-5" aria-hidden="true" />
        </button>
      </header>

      <div ref="isiEl" class="scrollbar-slim flex flex-1 flex-col gap-3 overflow-y-auto bg-slate-50 px-3 py-4" aria-live="polite" data-testid="vanya-isi">
        <template v-for="p in state.pesan" :key="p.id">
          <div v-if="p.jenis === 'user'" class="ml-auto flex max-w-[85%] justify-end" data-pesan="user">
            <p class="whitespace-pre-wrap break-words rounded-2xl rounded-br-sm bg-brand-tertiary px-3 py-2 text-sm text-white">{{ p.teks }}</p>
          </div>
          <div v-else class="flex max-w-[92%] items-end gap-2" :data-pesan="p.jenis === 'sistem' && p.galat ? 'galat' : p.jenis">
            <span class="h-7 w-7 shrink-0 overflow-hidden rounded-full"><VanyaAvatar :size="28" /></span>
            <div class="flex min-w-0 flex-col gap-2">
              <p v-if="p.jenis === 'sistem'" :class="[kelasBalonBot, p.galat ? 'border-danger/40 bg-danger-soft text-red-700' : '']">{{ p.teks }}</p>
              <div v-else-if="p.jenis === 'jawaban'" :class="kelasBalonBot" class="whitespace-normal">
                <SafeHtml :html="p.html" />
                <VanyaPenilaian v-if="p.penilaian" :penilaian="p.penilaian" :id-pesan="p.id" @nilai="vanya.nilai(p.id, $event)" />
              </div>
              <template v-else-if="p.jenis === 'topik'">
                <p :class="kelasBalonBot">{{ p.teks }}</p>
                <ul class="flex flex-wrap gap-2" aria-label="Pilih topik">
                  <li v-for="t in p.topik" :key="t.id">
                    <button
                      type="button"
                      :class="kelasChip"
                      class="border-brand-tertiary bg-white text-brand-tertiary hover:bg-brand-tertiary hover:text-white"
                      :data-testid="`vanya-topik-${t.id}`"
                      :disabled="state.mengetik"
                      @click="vanya.pilihTopik(t)"
                    >
                      {{ t.judul }}
                    </button>
                  </li>
                </ul>
              </template>
              <template v-else>
                <p v-if="p.teks" :class="kelasBalonBot">{{ p.teks }}</p>
                <ul class="flex flex-col items-start gap-2" aria-label="Pilih pertanyaan">
                  <li v-for="q in p.pertanyaan" :key="q.id_qna">
                    <button
                      type="button"
                      :class="kelasChip"
                      class="rounded-xl border-slate-300 bg-white font-medium text-brand-primary hover:border-brand-primary hover:bg-brand-primary hover:text-white"
                      :data-testid="`vanya-pertanyaan-${q.id_qna}`"
                      :disabled="state.mengetik"
                      @click="vanya.pilihPertanyaan(q)"
                    >
                      {{ q.pertanyaan }}
                    </button>
                  </li>
                </ul>
              </template>
            </div>
          </div>
        </template>
        <div v-if="state.mengetik" class="flex items-end gap-2" data-testid="vanya-mengetik">
          <span class="h-7 w-7 shrink-0 overflow-hidden rounded-full"><VanyaAvatar :size="28" /></span>
          <p class="flex items-center gap-1 rounded-2xl rounded-bl-sm border border-slate-200 bg-white px-3 py-3">
            <span class="sr-only">{{ TEKS_VANYA.mengetik }}</span>
            <span v-for="i in 3" :key="i" class="vanya-titik h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true" />
          </p>
        </div>
      </div>

      <div class="border-t border-slate-200 bg-white px-3 pb-3 pt-2">
        <div class="mb-2 flex gap-1.5">
          <button
            type="button"
            class="flex items-center gap-1 rounded-full border border-brand-primary px-3 py-1 text-xs font-semibold text-brand-primary hover:bg-brand-primary hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary disabled:opacity-60"
            data-testid="vanya-btn-topik"
            :disabled="state.mengetik"
            @click="vanya.tampilTopik()"
          >
            <LayoutGrid class="h-3.5 w-3.5" aria-hidden="true" /> Topik
          </button>
          <button
            type="button"
            class="flex items-center gap-1 rounded-full border border-brand-primary px-3 py-1 text-xs font-semibold text-brand-primary hover:bg-brand-primary hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary disabled:opacity-60"
            data-testid="vanya-btn-populer"
            :disabled="state.mengetik"
            @click="vanya.tampilPopuler()"
          >
            <Star class="h-3.5 w-3.5" aria-hidden="true" /> Populer
          </button>
        </div>
        <form class="flex items-center gap-2" data-testid="vanya-form" @submit.prevent="kirim">
          <label for="vanya-input" class="sr-only">Pertanyaan untuk Vanya</label>
          <input
            id="vanya-input"
            ref="inputEl"
            v-model="ketikan"
            type="text"
            :maxlength="MAKS_PESAN"
            autocomplete="off"
            :placeholder="TEKS_VANYA.placeholder"
            aria-describedby="vanya-sisa"
            class="min-w-0 flex-1 rounded-full border-0 bg-slate-100 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-tertiary/50"
            data-testid="vanya-input"
          />
          <span id="vanya-sisa" class="sr-only">Sisa {{ sisa }} karakter</span>
          <button
            type="submit"
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-tertiary text-white hover:bg-brand-tertiary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary disabled:opacity-50"
            aria-label="Kirim"
            data-testid="vanya-kirim"
            :disabled="state.mengetik || ketikan.trim() === ''"
          >
            <Send class="h-4 w-4" aria-hidden="true" />
          </button>
        </form>
      </div>
    </section>

    <div class="pointer-events-auto relative">
      <p
        v-if="kartuTampil"
        class="vanya-kartu absolute bottom-full right-0 mb-4 w-60 rounded-xl bg-white px-4 py-3 text-xs leading-relaxed text-slate-700 shadow-panel"
        data-testid="vanya-kartu"
        aria-hidden="true"
      >
        <strong class="text-brand-primary">{{ TEKS_VANYA.kartuSapaanTebal }}</strong>{{ TEKS_VANYA.kartuSapaan }}
      </p>
      <button
        ref="launcherEl"
        type="button"
        class="vanya-launcher relative block h-16 w-16 rounded-full transition-transform hover:scale-105 focus-visible:scale-105 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-brand-tertiary motion-reduce:transition-none motion-reduce:hover:scale-100"
        :aria-label="TEKS_VANYA.launcher"
        aria-haspopup="dialog"
        :aria-expanded="state.terbuka ? 'true' : 'false'"
        aria-controls="vanya-jendela"
        data-testid="vanya-launcher"
        @click="alihkan"
        @mouseenter="kartuHover = true"
        @mouseleave="kartuHover = false"
        @focus="kartuHover = true"
        @blur="kartuHover = false"
      >
        <span class="block h-full w-full overflow-hidden rounded-full border-[3px] border-white shadow-panel"><VanyaAvatar :size="58" /></span>
        <span class="absolute bottom-0.5 right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white bg-success" aria-hidden="true" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.vanya-jendela,
.vanya-kartu {
  animation: vanya-muncul 0.18s ease-out;
}
.vanya-launcher::before {
  content: '';
  position: absolute;
  inset: -4px;
  border-radius: 9999px;
  animation: vanya-denyut 2.6s infinite;
}
.vanya-titik {
  animation: vanya-titik 1.2s infinite ease-in-out;
}
.vanya-titik:nth-child(3) {
  animation-delay: 0.15s;
}
.vanya-titik:nth-child(4) {
  animation-delay: 0.3s;
}
@keyframes vanya-muncul {
  from {
    opacity: 0;
    transform: translateY(0.5rem);
  }
}
@keyframes vanya-denyut {
  0% {
    box-shadow: 0 0 0 0 rgb(33 122 255 / 0.45);
  }
  70% {
    box-shadow: 0 0 0 12px rgb(33 122 255 / 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgb(33 122 255 / 0);
  }
}
@keyframes vanya-titik {
  50% {
    opacity: 0.3;
  }
}
@media (prefers-reduced-motion: reduce) {
  .vanya-jendela,
  .vanya-kartu,
  .vanya-titik,
  .vanya-launcher::before {
    animation: none;
  }
}
</style>
