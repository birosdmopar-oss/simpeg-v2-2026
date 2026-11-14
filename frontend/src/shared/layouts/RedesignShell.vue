<script setup lang="ts">
/**
 * Shell aplikasi redesign: sidebar mengambang + topbar navy + breadcrumb + area konten.
 * Satu-satunya shell setelah login: membungkus semua halaman (AppShell lama sudah dipensiunkan).
 *
 * - Menu diturunkan dari role sesi lewat `buildNav` (nav.config.ts); disembunyikan seluruhnya selama sesi wajib
 *   ganti password (MAKE-020) — tinggal menu profil (Ganti Password, Keluar).
 * - Status collapse sidebar diingat di localStorage (opsional; gagal → tetap jalan tanpa disimpan).
 * - Aksesibilitas: tautan "Lewati ke konten", landmark <main id="main-content">.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { ROLE_LABELS } from '@/features/auth/types'
import InteraksiPopupHost from '@/features/form-digital/components/InteraksiPopupHost.vue'
import VanyaWidget from '@/features/halo-simpeg/chatbot/components/widget/VanyaWidget.vue'
import NotifikasiPushShell from '@/features/integrasi/components/NotifikasiPushShell.vue'
import PengingatCutiShell from '@/features/layanan/components/PengingatCutiShell.vue'
import { resetPengingatCuti } from '@/features/layanan/composables/pengingatCuti'
import { FASE_LONCENG, NOTIFIKASI_ROLES, resetRingkasanNotifikasi, useRingkasanNotifikasi } from '@/features/notifikasi'
import { UiBreadcrumb, type Crumb } from '@/shared/ui'

import AppSidebar from './AppSidebar.vue'
import AppTopbar from './AppTopbar.vue'
import { ACTIVE_PHASE, buildNav } from './nav.config'

const props = defineProps<{ breadcrumbs?: Crumb[] }>()

const COLLAPSE_KEY = 'simpeg.sidebar.collapsed'

const auth = useAuthStore()
const router = useRouter()

function readCollapsed(): boolean {
  try {
    return window.localStorage.getItem(COLLAPSE_KEY) === '1'
  } catch {
    return false
  }
}

const collapsed = ref(readCollapsed())
const mobileOpen = ref(false)

/**
 * Halaman isi-penuh (`.page-fill`, CR-060): tingginya = viewport − `--page-top`. Alih-alih angka tetap, jarak itu DIUKUR dari
 * posisi <main> sebenarnya (navbar sticky + breadcrumb + jarak) dan dipasang sebagai CSS var di akar shell; diukur ulang saat
 * ukuran konten/jendela atau breadcrumb berubah. Tanpa pengukuran (jsdom/0) nilai cadangan di main.css dipakai.
 */
const rootEl = ref<HTMLElement | null>(null)
const mainEl = ref<HTMLElement | null>(null)
const pageTop = ref<string | undefined>(undefined)
let resizeObserver: ResizeObserver | null = null

function measurePageTop(): void {
  const top = (mainEl.value?.getBoundingClientRect().top ?? 0) + window.scrollY
  pageTop.value = top > 0 ? `${Math.round(top * 100) / 100}px` : undefined
}

onMounted(() => {
  measurePageTop()
  window.addEventListener('resize', measurePageTop)
  if (typeof ResizeObserver !== 'undefined' && rootEl.value) {
    resizeObserver = new ResizeObserver(measurePageTop)
    resizeObserver.observe(rootEl.value)
  }
})

watch(
  () => props.breadcrumbs?.length ?? 0,
  () => void nextTick(measurePageTop),
)

/**
 * Drawer mobile (CR-060 butir I): saat dibuka fokus pindah ke tombol tutup di drawer, saat ditutup fokus kembali ke
 * tombol pemicu (hamburger) dan Esc menutup. Preferensi `collapsed` tidak disentuh di sini.
 */
function onDrawerKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') mobileOpen.value = false
}

watch(mobileOpen, (open) => {
  if (open) {
    window.addEventListener('keydown', onDrawerKeydown)
    void nextTick(() => document.querySelector<HTMLElement>('[data-testid="sidebar-close"]')?.focus())
  } else {
    window.removeEventListener('keydown', onDrawerKeydown)
    void nextTick(() => document.querySelector<HTMLElement>('[data-testid="topbar-open-menu"]')?.focus())
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onDrawerKeydown)
  window.removeEventListener('resize', measurePageTop)
  resizeObserver?.disconnect()
})

watch(collapsed, (value) => {
  try {
    window.localStorage.setItem(COLLAPSE_KEY, value ? '1' : '0')
  } catch {
    /* penyimpanan tidak tersedia (mode privat) — abaikan */
  }
})

const groups = computed(() => (auth.mustChangePassword ? [] : buildNav(auth.role, undefined, auth.user?.nip ?? null)))
const userName = computed(() => auth.user?.name ?? auth.user?.username ?? 'Pengguna')
const userSubtitle = computed(() => auth.user?.nip ?? (auth.role ? ROLE_LABELS[auth.role] : ''))

/**
 * Lonceng F-08 (MAKE-085): angka dari `useRingkasanNotifikasi` (singleton, polling 60 detik), dimuat ulang tiap pindah
 * halaman. Klik lonceng → Pusat Notifikasi untuk role yang punya halamannya (Q7-29); role lain tetap melihat angka saja.
 * Mengikuti fase menu (keputusan reviewer 10-11-2026, pola F7-14): selama `ACTIVE_PHASE < FASE_LONCENG` lonceng tetap
 * tanpa angka, tanpa polling, dan klik tidak berpindah — dibuka di penutup Fase 7.
 */
const loncengAktif = ACTIVE_PHASE >= FASE_LONCENG
const lonceng = loncengAktif ? useRingkasanNotifikasi() : null
const unread = computed(() => (lonceng === null || auth.mustChangePassword ? 0 : lonceng.total.value))
watch(
  () => router.currentRoute.value.fullPath,
  () => {
    if (lonceng !== null && !auth.mustChangePassword) void lonceng.muatUlang()
  },
)

function bukaNotifikasi(): void {
  if (!loncengAktif || auth.mustChangePassword || auth.role === null || !NOTIFIKASI_ROLES.includes(auth.role)) return
  void router.push('/notifikasi')
}

async function logout(): Promise<void> {
  try {
    await auth.logout()
  } finally {
    resetPengingatCuti()
    resetRingkasanNotifikasi()
    await router.push({ name: 'login' })
  }
}
</script>

<template>
  <div ref="rootEl" class="app-canvas min-h-screen" :style="{ '--page-top': pageTop }" data-testid="app-shell">
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-brand-primary focus:shadow-panel"
    >
      Lewati ke konten
    </a>

    <div class="flex">
      <AppSidebar
        v-model:collapsed="collapsed"
        v-model:mobile-open="mobileOpen"
        :groups="groups"
        :user-name="userName"
        :user-subtitle="userSubtitle"
        @logout="logout"
      />

      <!-- Latar gelap di belakang drawer (mobile). -->
      <div
        v-if="mobileOpen"
        class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
        aria-hidden="true"
        data-testid="sidebar-overlay"
        @click="mobileOpen = false"
      />

      <div class="min-w-0 flex-1 p-4 lg:p-5">
        <!--
          Top navbar sticky di semua halaman (AGENTS.md bagian 3, CR-060). Pembungkus menutup celah di atas navbar
          dengan gradasi latar supaya konten yang digulir tidak terlihat di sela-sela navbar yang mengambang;
          z-20 di bawah drawer/overlay mobile (z-30/40) dan dialog (z-50).
        -->
        <div
          class="sticky top-0 z-20 -mx-4 -mt-4 bg-gradient-to-b from-slate-100 via-slate-100/90 to-transparent px-4 pb-3 pt-4 lg:-mx-5 lg:-mt-5 lg:px-5 lg:pt-5"
          data-testid="app-topbar-sticky"
        >
          <AppTopbar :unread="unread" @open-menu="mobileOpen = true" @open-notifikasi="bukaNotifikasi" />
        </div>

        <UiBreadcrumb v-if="breadcrumbs?.length" :items="breadcrumbs" class="mt-4 block" />

        <!-- Izin & pendaftaran token push web (PRD Fase 6 F6-13, MAKE-066). -->
        <NotifikasiPushShell v-if="!auth.mustChangePassword" />

        <main id="main-content" ref="mainEl" class="mt-4" tabindex="-1">
          <slot />
        </main>
      </div>
    </div>

    <!-- Pengingat persetujuan cuti sekali per sesi setelah login (PRD Fase 4 §6.4.7). -->
    <PengingatCutiShell />
    <InteraksiPopupHost />
    <!-- Widget chatbot Vanya (PRD Fase 8 v1.1 F8-30/F8-43/F8-50): role UL_PEGAWAI, gerbang fase di komponen. -->
    <VanyaWidget v-if="!auth.mustChangePassword" />
  </div>
</template>
