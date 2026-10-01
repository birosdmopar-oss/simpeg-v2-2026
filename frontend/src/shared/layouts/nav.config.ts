/**
 * Konfigurasi menu sidebar redesign (Laporan Redesign §4, Gambar 12/25: grup KEPEGAWAIAN, KARIER, BERITA,
 * PUSAT BANTUAN). Satu sumber untuk seluruh menu supaya urutan, ikon, dan hak akses tidak tersebar di komponen.
 *
 * `phase` = fase pengembangan modul tujuan menu (00-INDEX.md). Menu dengan `phase > ACTIVE_PHASE` tidak ditampilkan.
 * Menu tanpa halaman tujuan (tanpa `to` dan tanpa submenu berisi: Arsip, Presensi, E-Kinerja, E-Talenta) juga
 * disembunyikan sampai halamannya ada — mockup tidak menggambarkannya.
 *
 * Hak akses per role mengikuti Matriks Role x Endpoint (hr/employee/index = 1,3,4,5,8; detail & struktur = semua
 * role login). Tampilan menu hanya UX — RoleFilter backend tetap yang menegakkan (ADR-024).
 * ASUMSI (kartu BLK-001, menunggu keputusan): Master Data & Manajemen Akun (modul yang sudah ada, tidak tampil di
 * mockup) ditaruh di grup "PENGATURAN".
 */
import {
  AlarmClock,
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
  TrendingUp,
  UserCog,
  Users,
} from 'lucide-vue-next'
import type { Component } from 'vue'
import type { RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'

import { USER_MANAGEMENT_ROLES, type RoleCode } from '@/features/auth/types'
import { HALO_ADMIN_ROLES, HALO_USER_ROLES } from '@/features/halo-simpeg/types'
import { PEGAWAI_LIST_ROLES } from '@/features/kepegawaian/types'
import { LAPORAN_ROLES } from '@/features/laporan/types'
import { LAYANAN_ROLES } from '@/features/layanan/types'
import { HARI_LIBUR_READ_ROLES } from '@/features/master-data/hariLibur.types'
import { MASTER_DATA_ROLES } from '@/features/master-data/types'

/** Fase tertinggi yang UI redesign-nya sudah dikerjakan. */
export const ACTIVE_PHASE = 8

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
  /** Menggantikan pencocokan path bila dua menu menuju path yang sama (FAQ vs Halo Simpeg: ?chat=1). */
  activeWhen?: (route: RouteLocationNormalizedLoaded) => boolean
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
    items: [{ key: 'home', label: 'Dashboards', icon: House, to: { name: 'home' }, phase: 0 }],
  },
  {
    key: 'kepegawaian',
    title: 'Kepegawaian',
    items: [
      { key: 'pegawai', label: 'Daftar Pegawai', icon: Users, to: { name: 'pegawai-list' }, roles: PEGAWAI_LIST_ROLES, phase: 3 },
      { key: 'struktur', label: 'Struktur Organisasi', icon: Network, to: { name: 'org-structure' }, phase: 3 },
      // Fase 7 (F-10 Arsip Digital).
      { key: 'arsip', label: 'Arsip', icon: Folder, phase: 7 },
      // Fase 4 (Layanan & Approval). Submenu mengikuti mockup Gambar 17.
      {
        key: 'layanan',
        label: 'Layanan',
        icon: MousePointerClick,
        phase: 4,
        roles: LAYANAN_ROLES,
        children: [{ key: 'layanan-status', label: 'Status Layanan', to: { name: 'layanan-status' } }],
      },
      // Fase 5 (Presensi & Remunerasi).
      { key: 'presensi', label: 'Presensi', icon: AlarmClock, phase: 5, children: [] },
      // Fase 7 (Dashboard/laporan). Submenu mengikuti mockup Gambar 15.
      {
        key: 'laporan',
        label: 'Laporan',
        icon: FileText,
        phase: 7,
        roles: LAPORAN_ROLES,
        children: [
          { key: 'laporan-unit', label: 'Unit Kerja', to: { name: 'laporan', params: { tipe: 'unit-kerja' } } },
          { key: 'laporan-jk', label: 'Jenis Kelamin', to: { name: 'laporan', params: { tipe: 'jenis-kelamin' } } },
          { key: 'laporan-struktural', label: 'Struktural', to: { name: 'laporan', params: { tipe: 'struktural' } } },
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
    items: [{ key: 'berita', label: 'Portal Berita', icon: Newspaper, to: { name: 'news' }, phase: 7 }],
  },
  {
    key: 'pengaturan',
    title: 'Pengaturan',
    items: [
      { key: 'master', label: 'Master Data', icon: Database, to: { name: 'master-data' }, roles: MASTER_DATA_ROLES, phase: 2 },
      { key: 'libur', label: 'Hari Libur', icon: CalendarDays, to: { name: 'hari-libur' }, roles: HARI_LIBUR_READ_ROLES, phase: 2 },
      { key: 'akun', label: 'Manajemen Akun', icon: UserCog, to: { name: 'users' }, roles: USER_MANAGEMENT_ROLES, phase: 1 },
    ],
  },
  {
    key: 'bantuan',
    title: 'Pusat Bantuan',
    items: [
      // Fase 8 (Halo Simpeg).
      // Pengguna: drawer chat di halaman FAQ (Gambar 31). Admin: inbox terpisah (Gambar 32).
      { key: 'halo', label: 'Halo Simpeg', icon: MessageCircle, to: { name: 'faq', query: { chat: '1' } }, roles: HALO_USER_ROLES, phase: 8, activeWhen: (r) => r.name === 'faq' && r.query.chat === '1' },
      { key: 'halo-admin', label: 'Halo Simpeg', icon: MessageCircle, to: { name: 'halo-admin' }, roles: HALO_ADMIN_ROLES, phase: 8 },
      { key: 'faq', label: 'FAQ', icon: CircleHelp, to: { name: 'faq' }, phase: 2, activeWhen: (r) => r.name === 'faq' && r.query.chat !== '1' },
    ],
  },
]

/** Menu yang boleh tampil untuk `role` pada fase aktif; grup tanpa item dibuang. */
export function buildNav(role: RoleCode | null, activePhase: number = ACTIVE_PHASE): NavGroup[] {
  return NAV.map((group) => ({
    ...group,
    items: group.items.filter(
      (item) =>
        item.phase <= activePhase &&
        Boolean(item.to || (item.children && item.children.length > 0)) &&
        (!item.roles || (role !== null && item.roles.includes(role))),
    ),
  })).filter((group) => group.items.length > 0)
}
