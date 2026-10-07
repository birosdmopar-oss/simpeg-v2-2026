<script setup lang="ts">
/**
 * Shell aplikasi redesign: sidebar mengambang + topbar navy + breadcrumb + area konten.
 * Satu-satunya shell setelah login: membungkus semua halaman (AppShell lama sudah dipensiunkan).
 *
 * - Menu diturunkan dari role sesi lewat `buildNav` (nav.config.ts).
 * - Status collapse sidebar diingat di localStorage (opsional; gagal → tetap jalan tanpa disimpan).
 * - Aksesibilitas: tautan "Lewati ke konten", landmark <main id="main-content">.
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { ROLE_LABELS } from '@/features/auth/types'
import { UiBreadcrumb, type Crumb } from '@/shared/ui'

import AppSidebar from './AppSidebar.vue'
import AppTopbar from './AppTopbar.vue'
import { buildNav } from './nav.config'

defineProps<{ breadcrumbs?: Crumb[] }>()

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

watch(collapsed, (value) => {
  try {
    window.localStorage.setItem(COLLAPSE_KEY, value ? '1' : '0')
  } catch {
    /* penyimpanan tidak tersedia (mode privat) — abaikan */
  }
})

const groups = computed(() => buildNav(auth.role))
const userName = computed(() => auth.user?.name ?? auth.user?.username ?? 'Pengguna')
const userSubtitle = computed(() => auth.user?.nip ?? (auth.role ? ROLE_LABELS[auth.role] : ''))

async function logout(): Promise<void> {
  try {
    await auth.logout()
  } finally {
    await router.push({ name: 'login' })
  }
}
</script>

<template>
  <div class="app-canvas min-h-screen">
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
        <AppTopbar @open-menu="mobileOpen = true" />

        <UiBreadcrumb v-if="breadcrumbs?.length" :items="breadcrumbs" class="mt-4 block" />

        <main id="main-content" class="mt-4" tabindex="-1">
          <slot />
        </main>
      </div>
    </div>
  </div>
</template>
