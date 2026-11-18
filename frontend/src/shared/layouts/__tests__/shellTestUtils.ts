/**
 * Helper test bersama untuk komponen yang dirender di dalam RedesignShell (bukan spec).
 * Router tiruan memuat SEMUA nama route yang dirujuk nav.config.ts — `router.resolve` melempar error bila ada
 * nama route yang tidak terdaftar — plus route halaman Kepegawaian.
 */
import { createPinia, setActivePinia } from 'pinia'
import { h } from 'vue'
import { createMemoryHistory, createRouter, type RouteLocationRaw, type Router, type RouteRecordRaw } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'

import { buildNav } from '../nav.config'

const stub = { render: () => h('div') }

const namaRute = (to: RouteLocationRaw | undefined): string | null =>
  to && typeof to === 'object' && 'name' in to && typeof to.name === 'string' ? to.name : null

/**
 * CR-135: sejak ACTIVE_PHASE 8 menu Fase 6–8 tampil, jadi setiap nama route yang dirujuk menu (semua role, semua fase) didaftarkan
 * sebagai rute tiruan `/__nav/<nama>` bila belum ada di daftar tetap di bawah — menu baru tidak perlu ditambah manual di sini.
 */
function ruteMenuTiruan(sudahAda: ReadonlySet<string>): RouteRecordRaw[] {
  const nama = new Set<string>()
  for (const role of Object.values(Role) as RoleCode[]) {
    for (const g of buildNav(role, Number.MAX_SAFE_INTEGER, '197001011995011001')) {
      for (const item of g.items) {
        for (const n of [namaRute(item.to), ...(item.children ?? []).map((c) => namaRute(c.to))]) if (n) nama.add(n)
      }
    }
  }
  return [...nama].filter((n) => !sudahAda.has(n)).map((n) => ({ path: `/__nav/${n}`, name: n, component: stub }))
}

export function createShellRouter(): Router {
  const routes: RouteRecordRaw[] = [
    { path: '/', name: 'home', component: stub },
    { path: '/login', name: 'login', component: stub },
    { path: '/akun', name: 'users', component: stub },
    { path: '/master/:entity?', name: 'master-data', component: stub },
    { path: '/master-ak-jf', name: 'master-ak-jf', component: stub },
    { path: '/hari-libur', name: 'hari-libur', component: stub },
    { path: '/web-config', name: 'web-config', component: stub },
    { path: '/integrasi', name: 'integrasi-admin', component: stub },
    { path: '/faq/:id?', name: 'faq', component: stub },
    { path: '/ganti-password', name: 'change-password', component: stub },
    { path: '/pegawai', name: 'pegawai-list', component: stub },
    { path: '/pegawai/:nip', name: 'pegawai-detail', component: stub },
    { path: '/struktur-organisasi', name: 'org-structure', component: stub },
    { path: '/penugasan', name: 'penugasan', component: stub },
    { path: '/biodata/pengajuan', name: 'biodata-antrean', component: stub },
    { path: '/konket', name: 'konket-usulan', component: stub },
    { path: '/lkh/verifikasi', name: 'lkh-verifikasi', component: stub },
    { path: '/karpeg-karis', name: 'karpeg-karis-usulan', component: stub },
    // F-10 Arsip (MAKE-091): dirujuk menu `arsip` dan tombol "Arsip" di header Detail Pegawai.
    { path: '/arsip', name: 'arsip', component: stub },
    { path: '/arsip/:nip', name: 'arsip-pegawai', component: stub },
    { path: '/arsip/:nip/:folder', name: 'arsip-folder', component: stub },
    // Nama route menu lain (Fase 4–8) ditambah otomatis oleh `ruteMenuTiruan` (CR-135).
  ]
  const sudahAda = new Set(routes.map((r) => String(r.name ?? '')))
  return createRouter({ history: createMemoryHistory(), routes: [...routes, ...ruteMenuTiruan(sudahAda)] })
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
