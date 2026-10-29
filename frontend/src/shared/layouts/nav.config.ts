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
  ClipboardPen,
  FileText,
  Files,
  House,
  MessageCircle,
  MousePointerClick,
  Network,
  Newspaper,
  Settings,
  TrendingUp,
  UserCheck,
  UserCog,
  Users,
} from 'lucide-vue-next'
import type { Component } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

import { USER_MANAGEMENT_ROLES, type RoleCode } from '@/features/auth/types'
import { DOKUMEN_ROLES } from '@/features/dokumen/types'
import {
  AK_JF_ROLES,
  BIODATA_PROSES_ROLES,
  DATA_SAYA_ROLES,
  KARPEG_KARIS_ROLES,
  KONKET_ROLES,
  LKH_ROLES,
  PEGAWAI_LIST_ROLES,
  PENUGASAN_ROLES,
} from '@/features/kepegawaian/roles'
import {
  CUTI_ADMIN_ROLES,
  CUTI_ROLES,
  IB_ROLES,
  PETUGAS_LAYANAN_ROLES,
  PMK_ROLES,
  STATUS_LAYANAN_ROLES,
  TB_ROLES,
} from '@/features/layanan/roles'
import { LAPORAN_ROLES } from '@/features/laporan/types'
import { HARI_LIBUR_READ_ROLES } from '@/features/master-data/hariLibur.types'
import {
  PRESENSI_ADMIN_ROLES,
  PRESENSI_PEGAWAI_ROLES,
  SLIP_GAJI_ADMIN_ROLES,
  SLIP_GAJI_PEGAWAI_ROLES,
  TUKIN_DAFTAR_ROLES,
  TUKIN_SAYA_ROLES,
} from '@/features/presensi/roles'
import { MASTER_DATA_ROLES } from '@/features/master-data/types'
import { WEB_CONFIG_ROLES } from '@/features/master-data/webConfig.types'

/**
 * Fase tertinggi yang menunya ditampilkan. 4 sejak paket penutup M7 (MAKE-026): semua halaman Modul C tersambung API
 * (sebelumnya 3 sejak B-20 penutup WS-2 MAKE-014). Menu fase 5+ sudah terdaftar di bawah tetapi tersembunyi
 * (kecuali yang ber-`rilis`).
 */
export const ACTIVE_PHASE = 4

export interface NavChild {
  key: string
  label: string
  to: RouteLocationRaw
  /**
   * Role yang melihat submenu ini; kosong = semua role yang melihat induknya. Item induk ber-`children` yang semua
   * anaknya tersaring dibuang (grup tidak tampil kosong). Tambahan F5-18 (PRD Fase 5A §7.8.2) — satu definisi untuk
   * semua fase.
   */
  roles?: readonly RoleCode[]
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
  /**
   * Menu dirilis lebih awal: tampil walau `phase > ACTIVE_PHASE` (hak role tetap berlaku). Untuk modul yang selesai
   * sebelum fasenya ditutup (F5-18, PRD Fase 5A §7.8.2; Fase 4 M7 memakai definisi yang sama). Hapus `rilis` saat
   * penutup fase menaikkan `ACTIVE_PHASE` melewati `phase` item itu. Tanpa `rilis` = perilaku lama.
   */
  rilis?: true
  /** Tujuan = Detail Pegawai NIP akun; item disembunyikan bila akun tanpa NIP (CR-049). */
  nipAkun?: true
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
      // Legacy "Kelola Data" (hr/employee/edit, data sendiri) — CR-049 ISSUE-034.
      { key: 'data-saya', label: 'Kelola Data', icon: ClipboardPen, roles: DATA_SAYA_ROLES, phase: 3, nipAkun: true },
      { key: 'pegawai', label: 'Daftar Pegawai', icon: Users, to: { name: 'pegawai-list' }, roles: PEGAWAI_LIST_ROLES, phase: 3 },
      // B-04 antrean pengajuan perubahan Data Umum (MAKE-014).
      { key: 'biodata-antrean', label: 'Pengajuan Data Umum', icon: UserCheck, to: { name: 'biodata-antrean' }, roles: BIODATA_PROSES_ROLES, phase: 3 },
      { key: 'struktur', label: 'Struktur Organisasi', icon: Network, to: { name: 'org-structure' }, phase: 3 },
      // B-07 penugasan Plt/Plh (legacy hr/rwy/jabatan/list_off_plt|plh; MAKE-012).
      { key: 'penugasan', label: 'Plt/Plh', icon: UserCog, to: { name: 'penugasan' }, roles: PENUGASAN_ROLES, phase: 3 },
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
      // Fase 4 (Layanan & Approval): submenu per role PRD Fase 4 §6.11.2; grup tampil bila minimal satu anak tampil.
      {
        key: 'layanan',
        label: 'Layanan',
        icon: MousePointerClick,
        phase: 4,
        children: [
          { key: 'layanan-cuti', label: 'Cuti', to: '/layanan/cuti', roles: CUTI_ROLES },
          { key: 'layanan-cuti-saldo', label: 'Saldo Cuti', to: '/layanan/cuti/saldo', roles: CUTI_ADMIN_ROLES },
          { key: 'layanan-cuti-petugas-approval', label: 'Petugas Approval Cuti', to: '/layanan/cuti/petugas-approval', roles: CUTI_ADMIN_ROLES },
          { key: 'layanan-tb', label: 'Tugas Belajar', to: '/layanan/tb', roles: TB_ROLES },
          { key: 'layanan-ib', label: 'Izin Belajar', to: '/layanan/ib', roles: IB_ROLES },
          { key: 'layanan-pmk', label: 'PMK', to: '/layanan/pmk', roles: PMK_ROLES },
          { key: 'layanan-status', label: 'Status Layanan', to: '/layanan/status', roles: STATUS_LAYANAN_ROLES },
          { key: 'layanan-petugas', label: 'Petugas Layanan', to: '/layanan/petugas', roles: PETUGAS_LAYANAN_ROLES },
        ],
      },
      // Fase 5 (Presensi & Remunerasi). Paket 5A dirilis sebelum ACTIVE_PHASE 5 (`rilis`, F5-18; dihapus saat 5C
      // menaikkan ACTIVE_PHASE ke 5). Anak per role PRD 5A §7.8.2; grup tampil bila minimal satu anak tampil.
      {
        key: 'presensi',
        label: 'Presensi',
        icon: AlarmClock,
        phase: 5,
        rilis: true,
        children: [
          { key: 'presensi-saya', label: 'Presensi Saya', to: '/presensi', roles: PRESENSI_PEGAWAI_ROLES },
          { key: 'presensi-slip-gaji', label: 'Slip Gaji', to: '/presensi/slip-gaji', roles: SLIP_GAJI_PEGAWAI_ROLES },
          { key: 'presensi-slip-gaji-kelola', label: 'Kelola Slip Gaji', to: '/presensi/slip-gaji/kelola', roles: SLIP_GAJI_ADMIN_ROLES },
          { key: 'presensi-data', label: 'Data Presensi', to: '/presensi/data', roles: PRESENSI_ADMIN_ROLES },
          { key: 'presensi-tukin', label: 'Laporan Tukin', to: '/presensi/tukin', roles: TUKIN_DAFTAR_ROLES },
          { key: 'presensi-tukin-saya', label: 'Laporan Tukin Saya', to: '/presensi/tukin/saya', roles: TUKIN_SAYA_ROLES },
        ],
      },
      // Fase 7 (Dashboard/laporan) F-03 MAKE-036: 7 statistik (hr/chart/* = role 1, 3, 4, 5, 8); 3 pertama mengikuti
      // mockup Gambar 15. Tetap `phase: 7` (tersembunyi sampai ACTIVE_PHASE ≥ 7); QA lewat URL langsung (PRD F7-14).
      {
        key: 'laporan',
        label: 'Laporan',
        icon: FileText,
        roles: LAPORAN_ROLES,
        phase: 7,
        children: [
          { key: 'laporan-unit', label: 'Unit Kerja', to: '/laporan/unit-kerja' },
          { key: 'laporan-jk', label: 'Jenis Kelamin', to: '/laporan/jenis-kelamin' },
          { key: 'laporan-struktural', label: 'Struktural', to: '/laporan/eselon' },
          { key: 'laporan-jabatan', label: 'Jabatan', to: '/laporan/jabatan' },
          { key: 'laporan-pendidikan', label: 'Pendidikan', to: '/laporan/pendidikan' },
          { key: 'laporan-usia', label: 'Usia', to: '/laporan/usia' },
          { key: 'laporan-golongan', label: 'Golongan', to: '/laporan/golongan' },
        ],
      },
      // Fase 7 F-09 MAKE-037: generator dokumen (hr/document/* = role 1, 3, 4, 5, 8). Tetap `phase: 7` (tersembunyi
      // sampai ACTIVE_PHASE ≥ 7, tanpa `rilis`); QA lewat URL langsung (PRD F7-14).
      {
        key: 'dokumen',
        label: 'Dokumen',
        icon: Files,
        roles: DOKUMEN_ROLES,
        phase: 7,
        children: [
          { key: 'dokumen-duk', label: 'DUK', to: '/dokumen/duk' },
          { key: 'dokumen-nominatif', label: 'Nominatif', to: '/dokumen/nominatif' },
          { key: 'dokumen-pensiun', label: 'Proyeksi Pensiun', to: '/dokumen/pensiun' },
          { key: 'dokumen-baperjakat', label: 'Baperjakat', to: '/dokumen/baperjakat' },
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
      // MAKE-021: legacy Data Master > JABATAN > Angka Kredit JF (master `dm_ak_jf`, role 1).
      { key: 'master-ak-jf', label: 'Angka Kredit JF', icon: Database, to: { name: 'master-ak-jf' }, roles: AK_JF_ROLES, phase: 3 },
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

const bolehRole = (roles: readonly RoleCode[] | undefined, role: RoleCode | null): boolean =>
  !roles || (role !== null && roles.includes(role))

/** Submenu yang boleh dilihat `role`; item ber-anak yang semua anaknya tersaring → null (dibuang). */
function saringAnak(item: NavItem, role: RoleCode | null): NavItem | null {
  if (!item.children || item.children.length === 0) return item
  const children = item.children.filter((c) => bolehRole(c.roles, role))
  return children.length > 0 ? { ...item, children } : null
}

/**
 * Menu yang boleh tampil untuk `role` pada fase aktif (atau ber-`rilis`); submenu disaring per `NavChild.roles`; item &
 * grup kosong dibuang. `nip` = NIP akun (item `nipAkun` menuju Detail Pegawai NIP itu; tanpa NIP item itu tidak tampil).
 */
export function buildNav(role: RoleCode | null, activePhase: number = ACTIVE_PHASE, nip: string | null = null): NavGroup[] {
  return NAV.map((group) => ({
    ...group,
    items: group.items
      .filter((item) => (item.phase <= activePhase || item.rilis === true) && bolehRole(item.roles, role))
      .filter((item) => !item.nipAkun || Boolean(nip))
      .map((item) => saringAnak(item, role))
      .filter((item): item is NavItem => item !== null)
      .map((item) => (item.nipAkun && nip ? { ...item, to: { name: 'pegawai-detail', params: { nip } } } : item)),
  })).filter((group) => group.items.length > 0)
}
