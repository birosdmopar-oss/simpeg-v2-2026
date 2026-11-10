/**
 * Route Modul F — konten admin Fase 7 (MAKE-079..082). Portal berita untuk semua role (baca; penargetan oleh backend);
 * halaman kelola per role (roles.ts). Diimpor sekali dari `router/index.ts`. Guard FE hanya UX (ADR-024).
 */
import type { RouteRecordRaw } from 'vue-router'

import { KONTEN_ADMIN_ROLES, KONTEN_BERITA_ROLES } from './roles'
import { ROUTE_DETAIL_BERITA, ROUTE_PORTAL_BERITA } from './routes'
import type { HalamanKonten } from './views/KontenPage.vue'

const page = () => import('./views/KontenPage.vue')
const rute = (path: string, name: string, halaman: HalamanKonten, title: string, roles?: RouteRecordRaw['meta']): RouteRecordRaw => ({
  path,
  name,
  component: page,
  props: { halaman },
  meta: { title, ...roles },
})

export const kontenRoutes: RouteRecordRaw[] = [
  rute('/berita', ROUTE_PORTAL_BERITA, 'portal', 'Berita'),
  rute('/berita/:id', ROUTE_DETAIL_BERITA, 'detail', 'Detail Berita'),
  rute('/konten/berita', 'konten-berita-kelola', 'berita-kelola', 'Kelola Berita', { roles: KONTEN_BERITA_ROLES }),
  rute('/konten/berita-kategori', 'konten-berita-kategori', 'berita-kategori', 'Kategori Berita', { roles: KONTEN_ADMIN_ROLES }),
  rute('/konten/popup', 'konten-popup-kelola', 'popup', 'Popup', { roles: KONTEN_ADMIN_ROLES }),
  rute('/konten/banner', 'konten-banner-kelola', 'banner', 'Banner', { roles: KONTEN_ADMIN_ROLES }),
  rute('/konten/dibo', 'konten-dibo-kelola', 'dibo', 'Kelola DIBO', { roles: KONTEN_ADMIN_ROLES }),
  rute('/konten/dibo-jenis', 'konten-dibo-jenis', 'dibo-jenis', 'Jenis DIBO', { roles: KONTEN_ADMIN_ROLES }),
]
