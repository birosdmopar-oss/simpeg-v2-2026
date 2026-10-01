/**
 * nav.config — menu sidebar redesign per role & fase aktif. Hak akses mengikuti Matriks Role x Endpoint;
 * menu modul fase > ACTIVE_PHASE (8) sengaja tidak tampil karena halamannya belum ada.
 */
import { describe, expect, it } from 'vitest'

import { Role } from '@/features/auth/types'

import { ACTIVE_PHASE, buildNav } from '../nav.config'

const labels = (role: Parameters<typeof buildNav>[0], phase?: number): string[] =>
  buildNav(role, phase).flatMap((g) => g.items.map((i) => i.label))

describe('buildNav', () => {
  it('fase aktif = 8 (UI redesign eksperimental mencakup Halo Simpeg)', () => {
    expect(ACTIVE_PHASE).toBe(8)
  })

  it('Super Admin melihat semua menu fase 0–3 (fase aktif dibatasi 3)', () => {
    expect(labels(Role.SUPER_ADMIN, 3)).toEqual([
      'Dashboards',
      'Daftar Pegawai',
      'Struktur Organisasi',
      'Master Data',
      'Hari Libur',
      'Manajemen Akun',
      'FAQ',
    ])
  })

  it('menu fase 4+ belum tampil pada fase aktif 3; Presensi/Arsip/E-Kinerja/E-Talenta belum ada UI-nya pada fase 8', () => {
    const all = Object.values(Role).flatMap((r) => labels(r, 3))
    for (const later of ['Layanan', 'Presensi', 'Laporan', 'Arsip', 'E-Kinerja', 'E-Talenta', 'Portal Berita', 'Halo Simpeg']) {
      expect(all).not.toContain(later)
    }
    const all8 = Object.values(Role).flatMap((r) => labels(r))
    for (const later of ['Presensi', 'Arsip', 'E-Kinerja', 'E-Talenta']) {
      expect(all8).not.toContain(later)
    }
  })

  it('Pegawai tidak melihat Daftar Pegawai (hr/employee/index hanya role 1,3,4,5,8) tapi boleh Struktur & FAQ', () => {
    expect(labels(Role.PEGAWAI, 3)).toEqual(['Dashboards', 'Struktur Organisasi', 'FAQ'])
    expect(labels(Role.PEGAWAI)).toEqual(['Dashboards', 'Struktur Organisasi', 'Layanan', 'Portal Berita', 'Halo Simpeg', 'FAQ'])
  })

  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])(
    'role %i melihat Daftar Pegawai',
    (role) => {
      expect(labels(role)).toContain('Daftar Pegawai')
    },
  )

  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i tidak melihat Daftar Pegawai', (role) => {
    expect(labels(role)).not.toContain('Daftar Pegawai')
  })

  it('Admin Satker: Manajemen Akun ya, Master Data & Hari Libur tidak', () => {
    const l = labels(Role.ADMIN_SATKER)
    expect(l).toContain('Manajemen Akun')
    expect(l).not.toContain('Master Data')
    expect(l).not.toContain('Hari Libur')
  })

  it('Pimpinan: Hari Libur ya (baca), Master Data & Manajemen Akun tidak', () => {
    const l = labels(Role.PIMPINAN)
    expect(l).toContain('Hari Libur')
    expect(l).not.toContain('Master Data')
    expect(l).not.toContain('Manajemen Akun')
  })

  it('tanpa role hanya menu tak-terbatas (tanpa item ber-roles)', () => {
    expect(labels(null)).toEqual(['Dashboards', 'Struktur Organisasi', 'Portal Berita', 'FAQ'])
  })

  it('grup tanpa item dibuang (Pegawai tidak punya grup Pengaturan)', () => {
    const keys = buildNav(Role.PEGAWAI).map((g) => g.key)
    expect(keys).toEqual(['main', 'kepegawaian', 'berita', 'bantuan'])
  })

  it('menaikkan fase aktif memunculkan menu berikutnya lengkap dengan submenu (Laporan → 3 anak)', () => {
    expect(labels(Role.SUPER_ADMIN, 4)).toContain('Layanan')
    expect(labels(Role.PIMPINAN)).not.toContain('Layanan')
    const laporan = buildNav(Role.SUPER_ADMIN, 7)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'laporan')
    expect(laporan?.children?.map((c) => c.label)).toEqual(['Unit Kerja', 'Jenis Kelamin', 'Struktural'])
  })
})
