/**
 * Router aplikasi (ADR-024): role requirement dideklarasikan di `meta` ({ requiresAuth, roles }).
 * Satu global beforeEach guard memanggil fetchCurrentUser() untuk memvalidasi cookie saat pertama kali diperlukan.
 * Belum login → /login (dengan ?redirect). Salah role → /403 (terpisah dari kasus belum login).
 * Guard FE murni UX — otorisasi sesungguhnya ditegakkan RoleFilter backend (ADR-005).
 */
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { USER_MANAGEMENT_ROLES } from '@/features/auth/types'
import { MASTER_DATA_ROLES } from '@/features/master-data/types'

declare module 'vue-router' {
  interface RouteMeta {
    /** Default true. false = halaman publik (login). */
    requiresAuth?: boolean
    /** Kode role (App\Constants\Role backend) yang boleh mengakses; kosong = semua role login. */
    roles?: readonly number[]
    /** Halaman khusus tamu: kalau sudah login diarahkan ke beranda. */
    guestOnly?: boolean
    title?: string
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/features/dashboard/views/HomeView.vue'),
    meta: { title: 'Beranda' },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/features/auth/views/LoginView.vue'),
    meta: { requiresAuth: false, guestOnly: true, title: 'Masuk' },
  },
  {
    path: '/akun',
    name: 'users',
    component: () => import('@/features/auth/views/UserManagementPage.vue'),
    meta: { roles: USER_MANAGEMENT_ROLES, title: 'Manajemen Akun' },
  },
  {
    path: '/master/:entity?',
    name: 'master-data',
    component: () => import('@/features/master-data/views/MasterDataPage.vue'),
    meta: { roles: MASTER_DATA_ROLES, title: 'Master Data' },
  },
  {
    // G-10 FAQ pegawai: semua role login (UL_ALL). :id = artikel yang dibuka, ?q= = kata kunci pencarian.
    path: '/faq/:id?',
    name: 'faq',
    component: () => import('@/features/master-data/views/FaqPage.vue'),
    meta: { title: 'FAQ' },
  },
  {
    path: '/403',
    name: 'forbidden',
    component: () => import('@/shared/views/ForbiddenView.vue'),
    meta: { title: 'Akses ditolak' },
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: { name: 'home' },
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const requiresAuth = to.meta.requiresAuth !== false

  if (!requiresAuth) {
    // Halaman tamu: kalau status sudah diketahui authenticated, lempar ke beranda. Tidak memanggil /auth/me
    // di sini agar halaman login tidak memicu siklus refresh token.
    if (to.meta.guestOnly && auth.isAuthenticated) return { name: 'home' }
    return true
  }

  const ok = await auth.fetchCurrentUser()
  if (!ok) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }

  if (to.meta.roles && to.meta.roles.length > 0 && (auth.role === null || !to.meta.roles.includes(auth.role))) {
    return { name: 'forbidden' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · SIMPEG v2` : 'SIMPEG v2'
})

export default router
