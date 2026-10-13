/**
 * nav.config — menu sidebar redesign per role & fase aktif. Hak akses mengikuti Matriks Role x Endpoint
 * (Fase 3: Matriks v2). Semua menu yang ada di `main` (termasuk Web Config) wajib ada; menu Fase 3 sudah terdaftar
 * dengan `phase: 3` tetapi tersembunyi selama ACTIVE_PHASE = 2.
 */
import { describe, expect, it } from 'vitest'

import { Role, type RoleCode } from '@/features/auth/types'

import { ACTIVE_PHASE, buildNav } from '../nav.config'

const labels = (role: Parameters<typeof buildNav>[0], phase?: number): string[] =>
  buildNav(role, phase).flatMap((g) => g.items.map((i) => i.label))

const rolesWith = (menu: string, phase: number): RoleCode[] => Object.values(Role).filter((r) => labels(r, phase).includes(menu))

const FASE3_MENUS = ['Daftar Pegawai', 'Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH']

describe('buildNav — fase aktif 2 (main)', () => {
  it('ACTIVE_PHASE = 2 sampai halaman Fase 3 tersambung API', () => {
    expect(ACTIVE_PHASE).toBe(2)
  })

  it('Super Admin melihat semua menu yang ada di main (termasuk Web Config)', () => {
    expect(labels(Role.SUPER_ADMIN)).toEqual(['Beranda', 'Master Data', 'Web Config', 'Hari Libur', 'Manajemen Akun', 'FAQ'])
  })

  it('menu Fase 3 tersembunyi untuk semua role', () => {
    const all = Object.values(Role).flatMap((r) => labels(r))
    for (const menu of FASE3_MENUS) expect(all).not.toContain(menu)
  })

  it('menu fase 4+ belum tampil untuk siapa pun', () => {
    const all = Object.values(Role).flatMap((r) => labels(r))
    for (const later of ['Layanan', 'Presensi', 'Laporan', 'Arsip', 'E-Kinerja', 'E-Talenta', 'Portal Berita', 'Halo Simpeg']) {
      expect(all).not.toContain(later)
    }
  })

  it('Pegawai hanya melihat Beranda dan FAQ', () => {
    expect(labels(Role.PEGAWAI)).toEqual(['Beranda', 'FAQ'])
  })

  it.each([Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i melihat Hari Libur tanpa Master Data (G-08)', (role) => {
    expect(labels(role)).toEqual(['Beranda', 'Hari Libur', 'FAQ'])
  })

  it.each([Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT, Role.PPPK])('role %i tidak melihat Hari Libur', (role) => {
    expect(labels(role)).not.toContain('Hari Libur')
  })

  it.each(Object.values(Role).filter((r) => r !== Role.SUPER_ADMIN))('role %i tidak melihat Web Config (G-09, role 1 saja)', (role) => {
    expect(labels(role)).not.toContain('Web Config')
  })

  it('Admin Satker: Manajemen Akun ya, Master Data & Hari Libur tidak', () => {
    expect(labels(Role.ADMIN_SATKER)).toEqual(['Beranda', 'Manajemen Akun', 'FAQ'])
  })

  it('tanpa role hanya menu tak-terbatas (tanpa item ber-roles)', () => {
    expect(labels(null)).toEqual(['Beranda', 'FAQ'])
  })

  it('grup tanpa item dibuang (Pegawai tidak punya grup Kepegawaian & Pengaturan)', () => {
    expect(buildNav(Role.PEGAWAI).map((g) => g.key)).toEqual(['main', 'bantuan'])
  })

  it('tujuan menu memakai nama route yang sama dengan router', () => {
    const items = buildNav(Role.SUPER_ADMIN).flatMap((g) => g.items)
    expect(Object.fromEntries(items.map((i) => [i.key, i.to]))).toEqual({
      home: { name: 'home' },
      'master-data': { name: 'master-data' },
      'web-config': { name: 'web-config' },
      'hari-libur': { name: 'hari-libur' },
      users: { name: 'users' },
      faq: { name: 'faq' },
    })
  })
})

describe('buildNav — fase aktif 3 (setelah B-20 penutup)', () => {
  const fase3 = (role: RoleCode) => labels(role, 3).filter((l) => FASE3_MENUS.includes(l))

  it('Super Admin melihat semua menu Fase 3 dan menu main tetap ada', () => {
    expect(fase3(Role.SUPER_ADMIN)).toEqual(FASE3_MENUS)
    expect(labels(Role.SUPER_ADMIN, 3)).toContain('Web Config')
  })

  it('Daftar Pegawai hanya role 1, 3, 4, 5, 8', () => {
    expect(rolesWith('Daftar Pegawai', 3)).toEqual([1, 3, 4, 5, 8])
  })

  it('Struktur Organisasi untuk semua role login', () => {
    expect(rolesWith('Struktur Organisasi', 3)).toEqual(Object.values(Role))
  })

  it('Usulan Konket & Verifikasi LKH = role 1, 2, 3, 6, 7', () => {
    expect(rolesWith('Usulan Konket', 3)).toEqual([1, 2, 3, 6, 7])
    expect(rolesWith('Verifikasi LKH', 3)).toEqual([1, 2, 3, 6, 7])
  })

  it('Usulan Karpeg/Karis = role 1, 2, 4, 5, 7', () => {
    expect(rolesWith('Usulan Karpeg/Karis', 3)).toEqual([1, 2, 4, 5, 7])
  })

  it('Pegawai: Struktur, Konket, Karpeg/Karis, LKH — tanpa Daftar Pegawai', () => {
    expect(fase3(Role.PEGAWAI)).toEqual(['Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH'])
  })

  it('menaikkan fase aktif memunculkan menu berikutnya lengkap dengan submenu (Laporan → 3 anak)', () => {
    expect(labels(Role.SUPER_ADMIN, 4)).toContain('Layanan')
    const laporan = buildNav(Role.SUPER_ADMIN, 7)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'laporan')
    expect(laporan?.children?.map((c) => c.label)).toEqual(['Unit Kerja', 'Jenis Kelamin', 'Struktural'])
  })
})
