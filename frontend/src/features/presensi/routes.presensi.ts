/**
 * Route Modul D paket 5A (PRD Fase 5A §7.8.1). Diimpor sekali dari `router/index.ts`. Role per halaman di `roles.ts`;
 * guard FE hanya UX (ADR-024), backend tetap menegakkan role + lingkup.
 */
import type { RouteRecordRaw } from 'vue-router'

import {
  PENYESUAIAN_TUKIN_ROLES,
  PRESENSI_ADMIN_ROLES,
  PRESENSI_PEGAWAI_ROLES,
  REKAP_TUKIN_IL_ROLES,
  REKAP_TUKIN_ROLES,
  REKAP_KEHADIRAN_ROLES,
  REKAP_UANG_MAKAN_ROLES,
  SLIP_GAJI_ADMIN_ROLES,
  SLIP_GAJI_PEGAWAI_ROLES,
  TUKIN_DAFTAR_ROLES,
  TUKIN_IL_ROLES,
  TUKIN_PER_PEGAWAI_ROLES,
  TUKIN_SAYA_ROLES,
} from './roles'

export const presensiRoutes: RouteRecordRaw[] = [
  {
    // D-02 presensi online (`hr/services/local/po_in|po_out`, `hr/presensi/view_presensi|list_ptt` = 2, 6, 7).
    path: '/presensi',
    name: 'presensi-saya',
    component: () => import('./views/PresensiPage.vue'),
    meta: { roles: PRESENSI_PEGAWAI_ROLES, title: 'Presensi Saya' },
  },
  {
    // F5-15 data presensi + D-03 manual akun khusus (`hr/presensi/list_ad|list` = 1, 3, 4, 5; `add|edit|delete` = 1).
    path: '/presensi/data',
    name: 'presensi-data',
    component: () => import('./views/DataPresensiPage.vue'),
    meta: { roles: PRESENSI_ADMIN_ROLES, title: 'Data Presensi' },
  },
  {
    // D-11 slip pegawai (`hr/sl_gaji/list|mark_viewed|cetak` = 2, 6, 7).
    path: '/presensi/slip-gaji',
    name: 'presensi-slip-gaji',
    component: () => import('./views/SlipGajiPage.vue'),
    meta: { roles: SLIP_GAJI_PEGAWAI_ROLES, title: 'Slip Gaji' },
  },
  {
    // D-10/D-11 jalur admin (`hr/sl_gaji/admin|import_*|edit_save|reset_access` = 1, 3; SG-2).
    path: '/presensi/slip-gaji/kelola',
    name: 'presensi-slip-gaji-kelola',
    component: () => import('./views/SlipGajiKelolaPage.vue'),
    meta: { roles: SLIP_GAJI_ADMIN_ROLES, title: 'Kelola Slip Gaji' },
  },
  {
    // D-12 Laporan Tukin (5C MAKE-048; `hr/presensi/list_lt` = 1, 3): daftar pegawai.
    path: '/presensi/tukin',
    name: 'presensi-tukin',
    component: () => import('./views/LaporanTukinDaftarPage.vue'),
    meta: { roles: TUKIN_DAFTAR_ROLES, title: 'Laporan Tukin' },
  },
  {
    // Laporan Tukin pegawai Instansi Lain (`list_lt_il` = 1).
    path: '/presensi/tukin/il',
    name: 'presensi-tukin-il',
    component: () => import('./views/LaporanTukinIlPage.vue'),
    meta: { roles: TUKIN_IL_ROLES, title: 'Laporan Tukin Instansi Lain' },
  },
  {
    // Laporan Tukin Saya: NIP dari akun (2, 6, 7). Statis, jadi menang atas `/presensi/tukin/:nip`.
    path: '/presensi/tukin/saya',
    name: 'presensi-tukin-saya',
    component: () => import('./views/LaporanTukinPegawaiPage.vue'),
    props: { saya: true },
    meta: { roles: TUKIN_SAYA_ROLES, title: 'Laporan Tukin Saya' },
  },
  {
    // Laporan Tukin per pegawai (`laporan_tukin/{nip}` = 1..7; 2/6/7 hanya diri sendiri, ditegakkan server).
    path: '/presensi/tukin/:nip(\\d+)',
    name: 'presensi-tukin-pegawai',
    component: () => import('./views/LaporanTukinPegawaiPage.vue'),
    meta: { roles: TUKIN_PER_PEGAWAI_ROLES, title: 'Laporan Tukin' },
  },
  {
    // Rekap Kehadiran (5B MAKE-042, F5B-10; PRD 5C §6): role 1, 3, 4.
    path: '/presensi/rekap-kehadiran',
    name: 'presensi-rekap-kehadiran',
    component: () => import('./views/RekapKehadiranPage.vue'),
    meta: { roles: REKAP_KEHADIRAN_ROLES, title: 'Rekap Kehadiran' },
  },
  {
    // Rekap Uang Makan (5B MAKE-042, F5B-20; PRD 5C §6): role 1, 3; tab Instansi Lain hanya role 1.
    path: '/presensi/uang-makan',
    name: 'presensi-uang-makan',
    component: () => import('./views/RekapUangMakanPage.vue'),
    meta: { roles: REKAP_UANG_MAKAN_ROLES, title: 'Rekap Uang Makan' },
  },
  {
    // D-12 Rekap Tukin (5C MAKE-049; `laporan_tukin_us_skp` = 1, 3): rekap potongan seluruh lingkup.
    path: '/presensi/tukin/rekap',
    name: 'presensi-rekap-tukin',
    component: () => import('./views/RekapTukinPage.vue'),
    meta: { roles: REKAP_TUKIN_ROLES, title: 'Rekap Tukin' },
  },
  {
    // Rekap Tukin pegawai Instansi Lain (`laporan_tukin_us_skp_il` = 1).
    path: '/presensi/tukin/rekap-il',
    name: 'presensi-rekap-tukin-il',
    component: () => import('./views/RekapTukinIlPage.vue'),
    meta: { roles: REKAP_TUKIN_IL_ROLES, title: 'Rekap Tukin Instansi Lain' },
  },
  {
    // Penyesuaian Tukin per NIP (5B MAKE-045, F5B-14; PRD 5C §6): role 1 saja.
    path: '/presensi/tukin/penyesuaian',
    name: 'presensi-penyesuaian-tukin',
    component: () => import('./views/PenyesuaianTukinPage.vue'),
    meta: { roles: PENYESUAIAN_TUKIN_ROLES, title: 'Penyesuaian Tukin' },
  },
  {
    // D-14 verifikasi QR publik (ADR-032): tanpa login & tanpa `guestOnly` (bisa dibuka saat sudah login), tanpa layout
    // aplikasi; path tetap `/verify/slip/:token` karena dicetak di QR PDF lama dan baru (SG-1).
    path: '/verify/slip/:token',
    name: 'verifikasi-slip',
    component: () => import('./views/VerifikasiSlipPage.vue'),
    meta: { requiresAuth: false, title: 'Verifikasi Slip Gaji' },
  },
]
