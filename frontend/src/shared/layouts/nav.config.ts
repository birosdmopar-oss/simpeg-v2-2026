/**
 * Konfigurasi menu sidebar redesign (Laporan Redesign §4, Gambar 12/25: grup KEPEGAWAIAN, KARIER, BERITA,
 * PUSAT BANTUAN). Satu sumber untuk seluruh menu supaya urutan, ikon, dan hak akses tidak tersebar di komponen.
 *
 * `phase` = fase pengembangan modul tujuan menu (00-INDEX.md). Menu dengan `phase > ACTIVE_PHASE` sengaja tidak
 * ditampilkan: UI redesign dikerjakan bertahap sampai Fase 3 (Kepegawaian Core), dan halaman tujuannya belum ada.
 * Menaikkan ACTIVE_PHASE saat fase berikutnya selesai cukup untuk memunculkan menunya.
 *
 * Hak akses per role mengikuti Matriks Role x Endpoint (Fase 3: Matriks v2, `features/kepegawaian/roles.ts`).
 * Tampilan menu hanya UX — RoleFilter backend tetap yang menegakkan (ADR-024).
 * Modul yang sudah ada tetapi tidak tampil di mockup (Master Data, Web Config, Hari Libur, Manajemen Akun) ditaruh di
 * grup "Pengaturan". Ganti Password & Keluar ada di menu profil sidebar (AppSidebar), bukan di daftar ini.
 *
 * Semua menu Fase 3 sudah didaftarkan dengan `phase: 3`; menu itu tersembunyi selama ACTIVE_PHASE = 2 dan baru
 * tampil setelah halamannya tersambung API (WS-2 menaikkan ACTIVE_PHASE di B-20 penutup).
 */
import {
  AlarmClock,
  BadgeCheck,
  Briefcase,
  ClipboardCheck,
  Database,
  Folder,
  CalendarDays,
  CircleHelp,
  FileText,
  House,
  MessageCircle,
  MousePointerClick,
  Network,
  Newspaper,
  Settings,
  TrendingUp,
  UserCog,
  Users,
} from 'lucide-vue-next'
import type { Component } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

import { USER_MANAGEMENT_ROLES, type RoleCode } from '@/features/auth/types'
import { KARPEG_KARIS_ROLES, KONKET_ROLES, LKH_ROLES, PEGAWAI_LIST_ROLES } from '@/features/kepegawaian/roles'
import { HARI_LIBUR_READ_ROLES } from '@/features/master-data/hariLibur.types'
import { MASTER_DATA_ROLES } from '@/features/master-data/types'
import { WEB_CONFIG_ROLES } from '@/features/master-data/webConfig.types'

/**
 * Fase tertinggi yang menunya ditampilkan. Tetap 2 sampai halaman Fase 3 tersambung API (B-20 penutup, WS-2);
 * menu Fase 3 sudah terdaftar di bawah tetapi tersembunyi.
 */
export const ACTIVE_PHASE = 2

export interface NavChild {
  key: string
  label: string
  to: RouteLocationRaw
}

export interface NavItem {
  key: string
  label: string
  icon: Component
  to?: RouteLocationRaw
  children?: NavChild[]
  /** Angka kecil merah di kanan label (mis. jumlah berita baru). */
  badge?: number
  /** Kosong = semua role login. */
  roles?: readonly RoleCode[]
  phase: number
}

export interface NavGroup {
  key: string
  /** Judul grup (overline). Kosong = tanpa judul (mis. Dashboards). */
  title?: string
  items: NavItem[]
}

const NAV: NavGroup[] = [
  {
    key: 'main',
    items: [{ key: 'home', label: 'Beranda', icon: House, to: { name: 'home' }, phase: 0 }],
  },
  {
    key: 'kepegawaian',
    title: 'Kepegawaian',
    items: [
      { key: 'pegawai', label: 'Daftar Pegawai', icon: Users, to: { name: 'pegawai-list' }, roles: PEGAWAI_LIST_ROLES, phase: 3 },
      { key: 'struktur', label: 'Struktur Organisasi', icon: Network, to: { name: 'org-structure' }, phase: 3 },
      // Konket & Karpeg/Karis = halaman usulan mandiri (bukan tab Detail Pegawai); LKH diverifikasi atasan langsung.
      { key: 'konket', label: 'Usulan Konket', icon: Briefcase, to: { name: 'konket-usulan' }, roles: KONKET_ROLES, phase: 3 },
      {
        key: 'karpeg-karis',
        label: 'Usulan Karpeg/Karis',
        icon: BadgeCheck,
        to: { name: 'karpeg-karis-usulan' },
        roles: KARPEG_KARIS_ROLES,
        phase: 3,
      },
      { key: 'lkh-verifikasi', label: 'Verifikasi LKH', icon: ClipboardCheck, to: { name: 'lkh-verifikasi' }, roles: LKH_ROLES, phase: 3 },
      // Fase 7 (F-10 Arsip Digital).
      { key: 'arsip', label: 'Arsip', icon: Folder, phase: 7 },
      // Fase 4 (Layanan & Approval). Submenu mengikuti mockup Gambar 17.
      {
        key: 'layanan',
        label: 'Layanan',
        icon: MousePointerClick,
        phase: 4,
        children: [{ key: 'layanan-status', label: 'Status Layanan', to: '/layanan/status' }],
      },
      // Fase 5 (Presensi & Remunerasi).
      { key: 'presensi', label: 'Presensi', icon: AlarmClock, phase: 5, children: [] },
      // Fase 7 (Dashboard/laporan). Submenu mengikuti mockup Gambar 15.
      {
        key: 'laporan',
        label: 'Laporan',
        icon: FileText,
        phase: 7,
        children: [
          { key: 'laporan-unit', label: 'Unit Kerja', to: '/laporan/unit-kerja' },
          { key: 'laporan-jk', label: 'Jenis Kelamin', to: '/laporan/jenis-kelamin' },
          { key: 'laporan-struktural', label: 'Struktural', to: '/laporan/struktural' },
        ],
      },
    ],
  },
  {
    key: 'karier',
    title: 'Karier',
    // Fase 6 (Integrasi) — perkiraan; belum dikonfirmasi di dokumen.
    items: [
      { key: 'ekinerja', label: 'E-Kinerja', icon: FileText, phase: 6, children: [] },
      { key: 'etalenta', label: 'E-Talenta', icon: TrendingUp, phase: 6, children: [] },
    ],
  },
  {
    key: 'berita',
    title: 'Berita',
    items: [{ key: 'berita', label: 'Portal Berita', icon: Newspaper, phase: 7 }],
  },
  {
    key: 'pengaturan',
    title: 'Pengaturan',
    items: [
      { key: 'master-data', label: 'Master Data', icon: Database, to: { name: 'master-data' }, roles: MASTER_DATA_ROLES, phase: 2 },
      { key: 'web-config', label: 'Web Config', icon: Settings, to: { name: 'web-config' }, roles: WEB_CONFIG_ROLES, phase: 2 },
      { key: 'hari-libur', label: 'Hari Libur', icon: CalendarDays, to: { name: 'hari-libur' }, roles: HARI_LIBUR_READ_ROLES, phase: 2 },
      { key: 'users', label: 'Manajemen Akun', icon: UserCog, to: { name: 'users' }, roles: USER_MANAGEMENT_ROLES, phase: 1 },
    ],
  },
  {
    key: 'bantuan',
    title: 'Pusat Bantuan',
    items: [
      // Fase 8 (Halo Simpeg).
      { key: 'halo', label: 'Halo Simpeg', icon: MessageCircle, phase: 8 },
      { key: 'faq', label: 'FAQ', icon: CircleHelp, to: { name: 'faq' }, phase: 2 },
    ],
  },
]

/** Menu yang boleh tampil untuk `role` pada fase aktif; grup tanpa item dibuang. */
export function buildNav(role: RoleCode | null, activePhase: number = ACTIVE_PHASE): NavGroup[] {
  return NAV.map((group) => ({
    ...group,
    items: group.items.filter(
      (item) => item.phase <= activePhase && (!item.roles || (role !== null && item.roles.includes(role))),
    ),
  })).filter((group) => group.items.length > 0)
}
