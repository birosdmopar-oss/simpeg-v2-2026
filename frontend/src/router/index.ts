/**
 * Router aplikasi (ADR-024): role requirement dideklarasikan di `meta` ({ requiresAuth, roles }).
 * Global guard (fetchCurrentUser + 401/403 handling) diimplementasikan di Fase 1 (Modul A).
 * Di Fase 0 hanya ada dua halaman placeholder agar redirect /login dari axios punya tujuan.
 */
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    /** Kode role (App\Constants\Role backend) yang boleh mengakses. */
    roles?: number[]
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
    meta: { requiresAuth: false, title: 'Masuk' },
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · SIMPEG v2` : 'SIMPEG v2'
})

export default router
