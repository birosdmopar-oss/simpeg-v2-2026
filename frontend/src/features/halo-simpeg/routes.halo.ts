/**
 * Route helpdesk Halo Simpeg (PRD Fase 8 v1.3 §6.3, F8-04; 8_2.md §3a). Diimpor sekali dari `router/index.ts`.
 * - `/halo-simpeg` (nama `halo-simpeg`): halaman pemilik + petugas, role 1–7 (role 8 tanpa akses).
 * - `/halo-simpeg/tiket/:id` (nama `halo-tiket`): rute = keadaan panel kanan master-detail (deep-link lonceng/push, tombol
 *   kembali browser); `id` hanya angka.
 * - `/halo-simpeg-admin/:bagian` (nama `halo-admin-<bagian>`): master Kategori | Petugas | Kontak, role 1;
 *   `/halo-simpeg-admin` → kategori. Prefiks sendiri (pola `/chatbot-manager`, keputusan reviewer V2): `AppSidebar` mencocokkan
 *   prefiks path, jadi di bawah `/halo-simpeg/` layar admin akan ikut mengaktifkan item `halo` sesudah CR-135.
 * Menu `halo` tetap `phase: 8` tanpa `to`/`roles` sampai CR-135 (H8-11 (a)); selama `ACTIVE_PHASE < 8` QA lewat URL langsung.
 * Guard FE hanya UX (ADR-024); backend tetap menegakkan role.
 */
import type { RouteRecordRaw } from 'vue-router'

import { HALO_ADMIN_ROLES, HALO_ROLES } from './constants'

export const BAGIAN_ADMIN_HALO = {
  kategori: 'Kategori',
  petugas: 'Petugas',
  kontak: 'Kontak',
} as const

export type BagianAdminHalo = keyof typeof BAGIAN_ADMIN_HALO

const halamanHalo = () => import('./views/HaloSimpegPage.vue')
const halamanAdmin = () => import('./views/HaloSimpegAdminPage.vue')

/** `:id` yang lolos pola `\d+` → angka positif; selain itu `null` (halaman menampilkan galat tidak ditemukan). */
export function idTiketDariParam(param: unknown): number | null {
  const n = Number(Array.isArray(param) ? param[0] : param)
  return Number.isSafeInteger(n) && n > 0 ? n : null
}

export const haloRoutes: RouteRecordRaw[] = [
  {
    path: '/halo-simpeg',
    name: 'halo-simpeg',
    component: halamanHalo,
    props: { idTiket: null },
    meta: { roles: HALO_ROLES, title: 'Halo Simpeg' },
  },
  {
    path: '/halo-simpeg/tiket/:id(\\d+)',
    name: 'halo-tiket',
    component: halamanHalo,
    props: (route) => ({ idTiket: idTiketDariParam(route.params.id) }),
    meta: { roles: HALO_ROLES, title: 'Halo Simpeg' },
  },
  { path: '/halo-simpeg-admin', redirect: { name: 'halo-admin-kategori' } },
  ...(Object.keys(BAGIAN_ADMIN_HALO) as BagianAdminHalo[]).map(
    (bagian): RouteRecordRaw => ({
      path: `/halo-simpeg-admin/${bagian}`,
      name: `halo-admin-${bagian}`,
      component: halamanAdmin,
      props: { bagian },
      meta: { roles: HALO_ADMIN_ROLES, title: `Halo Simpeg · ${BAGIAN_ADMIN_HALO[bagian]}` },
    }),
  ),
]
