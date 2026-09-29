/**
 * Helper test bersama untuk komponen yang dirender di dalam RedesignShell (bukan spec).
 * Router tiruan memuat SEMUA nama route yang dirujuk nav.config.ts — `router.resolve` melempar error bila ada
 * nama route yang tidak terdaftar — plus route halaman Kepegawaian.
 */
import { createPinia, setActivePinia } from 'pinia'
import { h } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import type { RoleCode, User } from '@/features/auth/types'

const stub = { render: () => h('div') }

export function createShellRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/login', name: 'login', component: stub },
      { path: '/akun', name: 'users', component: stub },
      { path: '/master/:entity?', name: 'master-data', component: stub },
      { path: '/hari-libur', name: 'hari-libur', component: stub },
      { path: '/faq/:id?', name: 'faq', component: stub },
      { path: '/ganti-password', name: 'change-password', component: stub },
      { path: '/pegawai', name: 'pegawai-list', component: stub },
      { path: '/pegawai/:nip', name: 'pegawai-detail', component: stub },
      { path: '/struktur-organisasi', name: 'org-structure', component: stub },
      { path: '/layanan/status', name: 'layanan-status', component: stub },
      { path: '/laporan/:tipe', name: 'laporan', component: stub },
      { path: '/berita', name: 'news', component: stub },
      { path: '/halo-simpeg/admin', name: 'halo-admin', component: stub },
      // Menu fase lanjutan memakai path string, tidak perlu nama route.
    ],
  })
}

export function userWithRole(role: RoleCode, extra: Partial<User> = {}): User {
  return { id_pengguna: 1, nip: null, username: 'tester', name: 'Tester', user_level: role, ...extra } as User
}

/** Pinia baru + sesi login `role`; kembalikan store agar test bisa mengubahnya. */
export function loginAs(role: RoleCode, extra: Partial<User> = {}) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.setSession(userWithRole(role, extra))
  return auth
}
