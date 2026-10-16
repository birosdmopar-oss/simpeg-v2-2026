/**
 * Route Modul B milik WS-2 (Kepegawaian: pegawai, struktur, Konket, LKH). Diimpor sekali dari `router/index.ts`;
 * menambah route WS-2 cukup di berkas ini. Role mengikuti Matriks v2 (`roles.ts`); guard FE hanya UX (ADR-024).
 */
import type { RouteRecordRaw } from 'vue-router'

import { BIODATA_PROSES_ROLES, KONKET_ROLES, LKH_ROLES, PEGAWAI_LIST_ROLES, PENUGASAN_ROLES } from './roles'

export const pegawaiRoutes: RouteRecordRaw[] = [
  {
    // B-20 daftar pegawai (hr/employee/index = role 1, 3, 4, 5, 8).
    path: '/pegawai',
    name: 'pegawai-list',
    component: () => import('./views/DaftarPegawaiPage.vue'),
    meta: { roles: PEGAWAI_LIST_ROLES, title: 'Daftar Pegawai' },
  },
  {
    // B-20 detail pegawai (hr/employee/detail/{nip} = semua role login; lingkup ditegakkan backend).
    path: '/pegawai/:nip',
    name: 'pegawai-detail',
    component: () => import('./views/DetailPegawaiPage.vue'),
    meta: { title: 'Data Pegawai' },
  },
  {
    // B-04 antrean pengajuan perubahan Data Umum (role 1, 3; MAKE-014).
    path: '/biodata/pengajuan',
    name: 'biodata-antrean',
    component: () => import('./views/AntreanBiodataPage.vue'),
    meta: { roles: BIODATA_PROSES_ROLES, title: 'Pengajuan Data Umum' },
  },
  {
    // B-19 struktur organisasi (hr/so/full = semua role login).
    path: '/struktur-organisasi',
    name: 'org-structure',
    component: () => import('./views/StrukturOrganisasiPage.vue'),
    meta: { title: 'Struktur Organisasi' },
  },
  {
    // B-07 penugasan Plt/Plh (legacy list_off_plt|plh; lihat 1, 3, 4, 5 — tulis role 1, ditegakkan backend).
    path: '/penugasan',
    name: 'penugasan',
    component: () => import('./views/PenugasanPage.vue'),
    meta: { roles: PENUGASAN_ROLES, title: 'Plt/Plh' },
  },
  {
    // B-13 Usulan Konket (hr/rwy/konket/* = role 1, 2, 3, 6, 7) — halaman usulan mandiri (MAKE-013).
    path: '/konket',
    name: 'konket-usulan',
    component: () => import('./views/UsulanKonketPage.vue'),
    meta: { roles: KONKET_ROLES, title: 'Usulan Konket' },
  },
  {
    // B-12b LKH (hr/rwy/lkh/* = role 1, 2, 3, 6, 7; approver = atasan langsung) — tab LKH Saya & Verifikasi Bawahan (MAKE-013).
    path: '/lkh/verifikasi',
    name: 'lkh-verifikasi',
    component: () => import('./views/VerifikasiLkhPage.vue'),
    meta: { roles: LKH_ROLES, title: 'Verifikasi LKH' },
  },
]
