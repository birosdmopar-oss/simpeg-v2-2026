/**
 * Route halaman Arsip (F-10 Tahap 1, MAKE-091; PRD F-10 §9.1). Diimpor sekali dari `router/index.ts`.
 *  - `/arsip` (name `arsip`): role 1/3/4/5 melihat pemilih pegawai; role 2/6/7 dialihkan ke `/arsip/<nip sendiri>`.
 *  - `/arsip/:nip` (name `arsip-pegawai`): folder arsip. Role 2/6/7 dengan `:nip` ≠ NIP sendiri dialihkan ke NIP sendiri
 *    tanpa pesan galat (AC1-03, [K] legacy `Arsip.php:15-21`).
 *  - `/arsip/:nip/:folder` (name `arsip-folder`): tabel berkas; `:folder` = `semua`, `id_jenis_rwy`, atau `tanpa_jenis`.
 * `meta.roles` = 1–7 (role 8 → halaman terlarang). Guard FE hanya UX (ADR-024); backend tetap menegakkan role + lingkup.
 */
import type { RouteLocationNormalized, RouteLocationRaw, RouteRecordRaw } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode } from '@/features/auth/types'

import { ARSIP_ROLES } from './roles'

/** Role yang hanya melihat arsip sendiri. */
const ARSIP_SENDIRI_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.PTT, Role.PPPK]

/**
 * Pengalihan role 2/6/7 ke arsip NIP sendiri. `true` = lanjut: role lain, atau akun tanpa NIP (tidak ada tujuan; server
 * tetap menjawab 403).
 */
export function alihkanArsipSendiri(
  to: Pick<RouteLocationNormalized, 'name' | 'params'>,
  role: RoleCode | null,
  nipSendiri: string | null,
): true | RouteLocationRaw {
  if (role === null || !ARSIP_SENDIRI_ROLES.includes(role) || !nipSendiri) return true
  const nip = typeof to.params.nip === 'string' ? to.params.nip : null
  if (to.name === 'arsip') return { name: 'arsip-pegawai', params: { nip: nipSendiri }, replace: true }
  if (nip === nipSendiri) return true
  if (to.name === 'arsip-folder') {
    return { name: 'arsip-folder', params: { nip: nipSendiri, folder: String(to.params.folder ?? 'semua') }, replace: true }
  }
  return { name: 'arsip-pegawai', params: { nip: nipSendiri }, replace: true }
}

function guardSendiri(to: RouteLocationNormalized): true | RouteLocationRaw {
  const auth = useAuthStore()
  return alihkanArsipSendiri(to, auth.role, auth.user?.nip ?? null)
}

export const arsipRoutes: RouteRecordRaw[] = [
  {
    path: '/arsip',
    name: 'arsip',
    component: () => import('./views/ArsipPage.vue'),
    meta: { roles: ARSIP_ROLES, title: 'Arsip' },
    beforeEnter: guardSendiri,
  },
  {
    path: '/arsip/:nip',
    name: 'arsip-pegawai',
    component: () => import('./views/ArsipPage.vue'),
    meta: { roles: ARSIP_ROLES, title: 'Arsip' },
    beforeEnter: guardSendiri,
  },
  {
    path: '/arsip/:nip/:folder',
    name: 'arsip-folder',
    component: () => import('./views/ArsipFolderPage.vue'),
    meta: { roles: ARSIP_ROLES, title: 'Arsip' },
    beforeEnter: guardSendiri,
  },
]
