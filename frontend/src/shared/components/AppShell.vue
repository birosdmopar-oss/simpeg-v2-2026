<script setup lang="ts">
/**
 * Layout aplikasi setelah login: header + navigasi. Menu "Manajemen Akun" hanya tampil untuk role 1 & 3 (A-12),
 * menu "Master Data" hanya role 1 (Modul G).
 * Tampilan menu = UX saja; backend tetap menegakkan RoleFilter (ADR-024).
 */
import { Database, LogOut, Users, Home } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { ROLE_LABELS } from '@/features/auth/types'

const auth = useAuthStore()
const router = useRouter()
const loggingOut = ref(false)

const roleLabel = computed(() => (auth.role ? ROLE_LABELS[auth.role] : ''))

async function logout(): Promise<void> {
  loggingOut.value = true
  try {
    await auth.logout()
  } finally {
    loggingOut.value = false
    await router.push({ name: 'login' })
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
        <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2 font-semibold text-brand-primary">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-brand-primary text-sm text-white">S</span>
          SIMPEG v2
        </RouterLink>

        <nav class="flex items-center gap-1 text-sm" aria-label="Navigasi utama">
          <RouterLink
            :to="{ name: 'home' }"
            class="flex items-center gap-1.5 rounded-md px-3 py-2 text-slate-600 hover:bg-slate-100"
            active-class="bg-slate-100 text-brand-primary font-medium"
          >
            <Home class="h-4 w-4" /> Beranda
          </RouterLink>
          <RouterLink
            v-if="auth.canManageUsers"
            :to="{ name: 'users' }"
            class="flex items-center gap-1.5 rounded-md px-3 py-2 text-slate-600 hover:bg-slate-100"
            active-class="bg-slate-100 text-brand-primary font-medium"
            data-testid="nav-users"
          >
            <Users class="h-4 w-4" /> Manajemen Akun
          </RouterLink>
          <RouterLink
            v-if="auth.canManageMasterData"
            :to="{ name: 'master-data' }"
            class="flex items-center gap-1.5 rounded-md px-3 py-2 text-slate-600 hover:bg-slate-100"
            active-class="bg-slate-100 text-brand-primary font-medium"
            data-testid="nav-master-data"
          >
            <Database class="h-4 w-4" /> Master Data
          </RouterLink>
        </nav>

        <div class="flex items-center gap-3 text-sm">
          <div v-if="auth.user" class="text-right leading-tight">
            <div class="font-medium text-slate-800">{{ auth.user.username }}</div>
            <div class="text-xs text-slate-500">{{ roleLabel }}</div>
          </div>
          <button
            type="button"
            class="flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            :disabled="loggingOut"
            @click="logout"
          >
            <LogOut class="h-4 w-4" /> Keluar
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
      <slot />
    </main>
  </div>
</template>
