/**
 * nav.config — menu sidebar redesign per role & fase aktif. Hak akses mengikuti Matriks Role x Endpoint
 * (Fase 3: Matriks v2 + keputusan user). Semua menu yang ada di `main` (termasuk Web Config) wajib ada; menu Fase 3
 * tampil sejak B-20 penutup (ACTIVE_PHASE = 3, WS-2 MAKE-014); menu fase 4+ tetap tersembunyi.
 */
import { describe, expect, it } from 'vitest'

import { Role, type RoleCode } from '@/features/auth/types'

import { ACTIVE_PHASE, buildNav } from '../nav.config'

const labels = (role: Parameters<typeof buildNav>[0], phase?: number): string[] =>
  buildNav(role, phase).flatMap((g) => g.items.map((i) => i.label))

const rolesWith = (menu: string, phase?: number): RoleCode[] => Object.values(Role).filter((r) => labels(r, phase).includes(menu))

const FASE3_MENUS = ['Daftar Pegawai', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH']

describe('buildNav — fase aktif 3 (B-20 penutup)', () => {
  it('ACTIVE_PHASE = 3 sejak halaman Fase 3 tersambung API', () => {
    expect(ACTIVE_PHASE).toBe(3)
  })

  // Menu lengkap per role (urut sidebar): Kepegawaian Fase 3 lalu menu main.
  it.each([
    [Role.SUPER_ADMIN, ['Beranda', ...FASE3_MENUS, 'Master Data', 'Web Config', 'Hari Libur', 'Manajemen Akun', 'FAQ']],
    [Role.PEGAWAI, ['Beranda', 'Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH', 'FAQ']],
    [Role.ADMIN_SATKER, ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Verifikasi LKH', 'Manajemen Akun', 'FAQ']],
    [Role.ADMIN_VIEW_ESELON1, ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Hari Libur', 'FAQ']],
    [Role.MENTERI, ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Hari Libur', 'FAQ']],
    [Role.PTT, ['Beranda', 'Struktur Organisasi', 'Usulan Konket', 'Verifikasi LKH', 'FAQ']],
    [Role.PPPK, ['Beranda', 'Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH', 'FAQ']],
    [Role.PIMPINAN, ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Hari Libur', 'FAQ']],
  ])('role %i melihat menu sesuai Matriks v2', (role, harapan) => {
    expect(labels(role)).toEqual(harapan)
  })

  it('Daftar Pegawai hanya role 1, 3, 4, 5, 8', () => {
    expect(rolesWith('Daftar Pegawai')).toEqual([1, 3, 4, 5, 8])
  })

  it('Struktur Organisasi untuk semua role login (dan tanpa role)', () => {
    expect(rolesWith('Struktur Organisasi')).toEqual(Object.values(Role))
    expect(labels(null)).toEqual(['Beranda', 'Struktur Organisasi', 'FAQ'])
  })

  it('Plt/Plh = role 1, 3, 4, 5', () => {
    expect(rolesWith('Plt/Plh')).toEqual([1, 3, 4, 5])
  })

  it('Usulan Konket = 1, 2, 3, 6, 7 + 4, 5 lihat saja [K]; Verifikasi LKH = 1, 2, 3, 6, 7', () => {
    expect(rolesWith('Usulan Konket')).toEqual([1, 2, 3, 4, 5, 6, 7])
    expect(rolesWith('Verifikasi LKH')).toEqual([1, 2, 3, 6, 7])
  })

  it('Usulan Karpeg/Karis = role 1, 2, 4, 5, 7', () => {
    expect(rolesWith('Usulan Karpeg/Karis')).toEqual([1, 2, 4, 5, 7])
  })

  it('menu fase 4+ belum tampil untuk siapa pun', () => {
    const all = Object.values(Role).flatMap((r) => labels(r))
    for (const later of ['Layanan', 'Presensi', 'Laporan', 'Arsip', 'E-Kinerja', 'E-Talenta', 'Portal Berita', 'Halo Simpeg']) {
      expect(all).not.toContain(later)
    }
  })

  it.each(Object.values(Role).filter((r) => r !== Role.SUPER_ADMIN))('role %i tidak melihat Web Config (G-09, role 1 saja)', (role) => {
    expect(labels(role)).not.toContain('Web Config')
  })

  it('grup tanpa item dibuang (Pegawai tidak punya grup Pengaturan)', () => {
    expect(buildNav(Role.PEGAWAI).map((g) => g.key)).toEqual(['main', 'kepegawaian', 'bantuan'])
  })

  it('tujuan menu memakai nama route yang sama dengan router', () => {
    const items = buildNav(Role.SUPER_ADMIN).flatMap((g) => g.items)
    expect(Object.fromEntries(items.map((i) => [i.key, i.to]))).toEqual({
      home: { name: 'home' },
      pegawai: { name: 'pegawai-list' },
      struktur: { name: 'org-structure' },
      penugasan: { name: 'penugasan' },
      konket: { name: 'konket-usulan' },
      'karpeg-karis': { name: 'karpeg-karis-usulan' },
      'lkh-verifikasi': { name: 'lkh-verifikasi' },
      'master-data': { name: 'master-data' },
      'web-config': { name: 'web-config' },
      'hari-libur': { name: 'hari-libur' },
      users: { name: 'users' },
      faq: { name: 'faq' },
    })
  })
})

describe('buildNav — fase 2 (sebelum B-20 penutup)', () => {
  it('menu Fase 3 tersembunyi untuk semua role', () => {
    const all = Object.values(Role).flatMap((r) => labels(r, 2))
    for (const menu of FASE3_MENUS) expect(all).not.toContain(menu)
  })

  it('Super Admin melihat menu main saja', () => {
    expect(labels(Role.SUPER_ADMIN, 2)).toEqual(['Beranda', 'Master Data', 'Web Config', 'Hari Libur', 'Manajemen Akun', 'FAQ'])
  })
})

describe('buildNav — fase berikutnya', () => {
  it('menaikkan fase aktif memunculkan menu berikutnya lengkap dengan submenu (Laporan → 3 anak)', () => {
    expect(labels(Role.SUPER_ADMIN, 4)).toContain('Layanan')
    const laporan = buildNav(Role.SUPER_ADMIN, 7)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'laporan')
    expect(laporan?.children?.map((c) => c.label)).toEqual(['Unit Kerja', 'Jenis Kelamin', 'Struktural'])
  })
})
