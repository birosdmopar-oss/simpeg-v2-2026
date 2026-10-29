/**
 * nav.config — menu sidebar redesign per role & fase aktif. Hak akses mengikuti Matriks Role x Endpoint
 * (Fase 3: Matriks v2 + keputusan user). Semua menu yang ada di `main` (termasuk Web Config) wajib ada; menu Fase 3
 * tampil sejak B-20 penutup (WS-2 MAKE-014) dan menu Layanan Fase 4 sejak penutup M7 (ACTIVE_PHASE = 4, MAKE-026,
 * PRD Fase 4 §6.11.2); menu fase 5+ tetap tersembunyi kecuali ber-`rilis` (F5-18: grup Presensi paket 5A, anak per
 * role `NavChild.roles`).
 */
import { describe, expect, it } from 'vitest'

import { Role, type RoleCode } from '@/features/auth/types'

import { ACTIVE_PHASE, buildNav } from '../nav.config'

const labels = (role: Parameters<typeof buildNav>[0], phase?: number): string[] =>
  buildNav(role, phase).flatMap((g) => g.items.map((i) => i.label))

const rolesWith = (menu: string, phase?: number): RoleCode[] => Object.values(Role).filter((r) => labels(r, phase).includes(menu))

const FASE3_MENUS = ['Daftar Pegawai', 'Pengajuan Data Umum', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH']

describe('buildNav — fase aktif 4 (penutup M7 Fase 4)', () => {
  it('ACTIVE_PHASE = 4 sejak halaman Modul C (Layanan) tersambung API', () => {
    expect(ACTIVE_PHASE).toBe(4)
  })

  // Menu lengkap per role (urut sidebar): Kepegawaian Fase 3 lalu menu main.
  it.each([
    [Role.SUPER_ADMIN, ['Beranda', ...FASE3_MENUS, 'Layanan', 'Presensi', 'Master Data', 'Angka Kredit JF', 'Web Config', 'Hari Libur', 'Manajemen Akun', 'FAQ']],
    [Role.PEGAWAI, ['Beranda', 'Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH', 'Layanan', 'Presensi', 'FAQ']],
    [Role.ADMIN_SATKER, ['Beranda', 'Daftar Pegawai', 'Pengajuan Data Umum', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Verifikasi LKH', 'Layanan', 'Presensi', 'Manajemen Akun', 'FAQ']],
    [Role.ADMIN_VIEW_ESELON1, ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Layanan', 'Presensi', 'Hari Libur', 'FAQ']],
    [Role.MENTERI, ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Layanan', 'Presensi', 'Hari Libur', 'FAQ']],
    [Role.PTT, ['Beranda', 'Struktur Organisasi', 'Usulan Konket', 'Verifikasi LKH', 'Layanan', 'Presensi', 'FAQ']],
    [Role.PPPK, ['Beranda', 'Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH', 'Layanan', 'Presensi', 'FAQ']],
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

  it('Pengajuan Data Umum (antrean B-04) = role 1, 3', () => {
    expect(rolesWith('Pengajuan Data Umum')).toEqual([1, 3])
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

  it('menu fase 5+ tanpa `rilis` belum tampil untuk siapa pun', () => {
    const all = Object.values(Role).flatMap((r) => labels(r))
    for (const later of ['Laporan', 'Arsip', 'E-Kinerja', 'E-Talenta', 'Portal Berita', 'Halo Simpeg']) {
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
      'biodata-antrean': { name: 'biodata-antrean' },
      struktur: { name: 'org-structure' },
      penugasan: { name: 'penugasan' },
      konket: { name: 'konket-usulan' },
      'karpeg-karis': { name: 'karpeg-karis-usulan' },
      'lkh-verifikasi': { name: 'lkh-verifikasi' },
      'master-data': { name: 'master-data' },
      'master-ak-jf': { name: 'master-ak-jf' },
      'web-config': { name: 'web-config' },
      'hari-libur': { name: 'hari-libur' },
      users: { name: 'users' },
      faq: { name: 'faq' },
    })
  })
})

describe('buildNav — Kelola Data (profil sendiri, CR-049 ISSUE-034)', () => {
  const NIP = '199001012015011001'

  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])(
    'role %i ber-NIP: "Kelola Data" → Detail Pegawai NIP akun, paling atas di Kepegawaian',
    (role) => {
      const kepeg = buildNav(role, ACTIVE_PHASE, NIP).find((g) => g.key === 'kepegawaian')
      expect(kepeg?.items[0]).toMatchObject({ key: 'data-saya', label: 'Kelola Data', to: { name: 'pegawai-detail', params: { nip: NIP } } })
    },
  )

  it('tanpa NIP akun, atau role 1/3/4/5/8 (ikut legacy), tidak ada "Kelola Data"', () => {
    expect(labels(Role.PEGAWAI)).not.toContain('Kelola Data')
    for (const role of [Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN]) {
      expect(buildNav(role, ACTIVE_PHASE, NIP).flatMap((g) => g.items.map((i) => i.label))).not.toContain('Kelola Data')
    }
  })
})

describe('buildNav — fase 2 (sebelum B-20 penutup)', () => {
  it('menu Fase 3 tersembunyi untuk semua role', () => {
    const all = Object.values(Role).flatMap((r) => labels(r, 2))
    for (const menu of FASE3_MENUS) expect(all).not.toContain(menu)
  })

  it('Super Admin melihat menu main saja', () => {
    expect(labels(Role.SUPER_ADMIN, 2)).toEqual(['Beranda', 'Presensi', 'Master Data', 'Web Config', 'Hari Libur', 'Manajemen Akun', 'FAQ'])
  })
})

describe('buildNav — fase berikutnya', () => {
  it('menaikkan fase aktif memunculkan menu berikutnya lengkap dengan submenu (Laporan → 7 anak, MAKE-036)', () => {
    expect(labels(Role.SUPER_ADMIN, 4)).toContain('Layanan')
    const laporan = buildNav(Role.SUPER_ADMIN, 7)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'laporan')
    expect(laporan?.children?.map((c) => c.label)).toEqual(['Unit Kerja', 'Jenis Kelamin', 'Struktural', 'Jabatan', 'Pendidikan', 'Usia', 'Golongan'])
    expect(laporan?.children?.map((c) => c.to)).toEqual(['/laporan/unit-kerja', '/laporan/jenis-kelamin', '/laporan/eselon', '/laporan/jabatan', '/laporan/pendidikan', '/laporan/usia', '/laporan/golongan'])
  })

  it('Laporan (fase 7) = role 1, 3, 4, 5, 8 (hr/chart/*), tetap tersembunyi pada fase aktif', () => {
    const punya = Object.values(Role).filter((r) => buildNav(r, 7).flatMap((g) => g.items).some((i) => i.key === 'laporan'))
    expect(punya).toEqual([1, 3, 4, 5, 8])
    expect(Object.values(Role).flatMap((r) => labels(r))).not.toContain('Laporan')
  })

  it('Dokumen (fase 7, MAKE-037) = role 1, 3, 4, 5, 8 (hr/document/*), 4 anak, tetap tersembunyi pada fase aktif', () => {
    const punya = Object.values(Role).filter((r) => buildNav(r, 7).flatMap((g) => g.items).some((i) => i.key === 'dokumen'))
    expect(punya).toEqual([1, 3, 4, 5, 8])
    const dokumen = buildNav(Role.SUPER_ADMIN, 7)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'dokumen')
    expect(dokumen?.children?.map((c) => c.label)).toEqual(['DUK', 'Nominatif', 'Proyeksi Pensiun', 'Baperjakat'])
    expect(dokumen?.children?.map((c) => c.to)).toEqual(['/dokumen/duk', '/dokumen/nominatif', '/dokumen/pensiun', '/dokumen/baperjakat'])
    expect(Object.values(Role).flatMap((r) => labels(r))).not.toContain('Dokumen')
  })
})

describe('buildNav — `rilis` & `NavChild.roles` (F5-18, PRD Fase 5A §7.8.2)', () => {
  const presensi = (role: RoleCode | null, phase?: number) =>
    buildNav(role, phase)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'presensi')

  it('grup Presensi (phase 5, `rilis`) tampil pada ACTIVE_PHASE 4 tanpa menaikkan fase ke 5', () => {
    expect(ACTIVE_PHASE).toBe(4)
    expect(presensi(Role.PEGAWAI)).toMatchObject({ phase: 5, rilis: true })
  })

  it('anak disaring per role: Presensi Saya + Slip Gaji = role 2, 6, 7', () => {
    for (const role of [Role.PEGAWAI, Role.PTT, Role.PPPK]) {
      expect(presensi(role)?.children).toEqual([
        { key: 'presensi-saya', label: 'Presensi Saya', to: '/presensi', roles: [2, 6, 7] },
        { key: 'presensi-slip-gaji', label: 'Slip Gaji', to: '/presensi/slip-gaji', roles: [2, 6, 7] },
        { key: 'presensi-tukin-saya', label: 'Laporan Tukin Saya', to: '/presensi/tukin/saya', roles: [2, 6, 7] },
      ])
    }
  })

  it('anak disaring per role: Data Presensi = role 1, 3, 4, 5 → /presensi/data', () => {
    expect(presensi(Role.MENTERI)?.children).toEqual([{ key: 'presensi-data', label: 'Data Presensi', to: '/presensi/data', roles: [1, 3, 4, 5] }])
    expect(presensi(Role.ADMIN_VIEW_ESELON1)?.children).toEqual([
      { key: 'presensi-data', label: 'Data Presensi', to: '/presensi/data', roles: [1, 3, 4, 5] },
      { key: 'presensi-rekap-kehadiran', label: 'Rekap Kehadiran', to: '/presensi/rekap-kehadiran', roles: [1, 3, 4] },
    ])
  })

  it('anak disaring per role: Kelola Slip Gaji = role 1, 3 → /presensi/slip-gaji/kelola', () => {
    for (const role of [Role.SUPER_ADMIN, Role.ADMIN_SATKER]) {
      expect(presensi(role)?.children).toEqual([
        { key: 'presensi-slip-gaji-kelola', label: 'Kelola Slip Gaji', to: '/presensi/slip-gaji/kelola', roles: [1, 3] },
        { key: 'presensi-data', label: 'Data Presensi', to: '/presensi/data', roles: [1, 3, 4, 5] },
        { key: 'presensi-tukin', label: 'Laporan Tukin', to: '/presensi/tukin', roles: [1, 3] },
        { key: 'presensi-rekap-kehadiran', label: 'Rekap Kehadiran', to: '/presensi/rekap-kehadiran', roles: [1, 3, 4] },
        { key: 'presensi-uang-makan', label: 'Rekap Uang Makan', to: '/presensi/uang-makan', roles: [1, 3] },
        ...(role === Role.SUPER_ADMIN
          ? [{ key: 'presensi-penyesuaian-tukin', label: 'Penyesuaian Tukin', to: '/presensi/tukin/penyesuaian', roles: [1] }]
          : []),
      ])
    }
  })

  it('grup dibuang bila tidak ada anak yang boleh dilihat role itu (role 8 tidak pernah melihat Presensi)', () => {
    expect(Object.values(Role).filter((r) => presensi(r) !== undefined)).toEqual([1, 2, 3, 4, 5, 6, 7])
    expect(presensi(Role.PIMPINAN)).toBeUndefined()
    expect(presensi(null)).toBeUndefined()
  })

  it('`rilis` tidak melewati penyaringan role dan fase lebih tinggi tetap memakai anak yang sama', () => {
    expect(presensi(Role.PIMPINAN)).toBeUndefined()
    expect(presensi(Role.PEGAWAI, 5)?.children?.map((c) => c.key)).toEqual(['presensi-saya', 'presensi-slip-gaji', 'presensi-tukin-saya'])
  })

  it('item tanpa `rilis` dengan children kosong tetap mengikuti fase (E-Kinerja fase 6)', () => {
    expect(labels(Role.SUPER_ADMIN)).not.toContain('E-Kinerja')
    expect(labels(Role.SUPER_ADMIN, 6)).toContain('E-Kinerja')
  })
})

describe('buildNav — grup Layanan Fase 4 per role (PRD Fase 4 §6.11.2, AC-11.5)', () => {
  const layanan = (role: RoleCode | null, phase?: number) =>
    buildNav(role, phase)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'layanan')

  const anak = (role: RoleCode) => layanan(role)?.children?.map((c) => c.label)

  it.each([
    [Role.SUPER_ADMIN, ['Cuti', 'Saldo Cuti', 'Petugas Approval Cuti', 'Tugas Belajar', 'Izin Belajar', 'PMK', 'Status Layanan', 'Petugas Layanan']],
    [Role.PEGAWAI, ['Cuti', 'Tugas Belajar', 'Izin Belajar', 'PMK', 'Status Layanan']],
    [Role.ADMIN_SATKER, ['Cuti', 'Saldo Cuti', 'Petugas Approval Cuti', 'Tugas Belajar', 'Izin Belajar', 'PMK', 'Status Layanan']],
    [Role.ADMIN_VIEW_ESELON1, ['Status Layanan']],
    [Role.MENTERI, ['Status Layanan']],
    [Role.PTT, ['Cuti', 'Tugas Belajar', 'Status Layanan']],
    [Role.PPPK, ['Cuti', 'Izin Belajar', 'Status Layanan']],
  ])('role %i melihat anak Layanan %j', (role, harapan) => {
    expect(anak(role)).toEqual(harapan)
  })

  it('role 8 dan tanpa role tidak melihat grup Layanan; Tugas Belajar tanpa role 7, Izin Belajar tanpa role 6', () => {
    expect(layanan(Role.PIMPINAN)).toBeUndefined()
    expect(layanan(null)).toBeUndefined()
    expect(anak(Role.PPPK)).not.toContain('Tugas Belajar')
    expect(anak(Role.PTT)).not.toContain('Izin Belajar')
  })

  it('tujuan menu = path route layanan', () => {
    expect(layanan(Role.SUPER_ADMIN)?.children?.map((c) => c.to)).toEqual([
      '/layanan/cuti',
      '/layanan/cuti/saldo',
      '/layanan/cuti/petugas-approval',
      '/layanan/tb',
      '/layanan/ib',
      '/layanan/pmk',
      '/layanan/status',
      '/layanan/petugas',
    ])
  })

  it('tersembunyi pada fase aktif 3 (sebelum penutup M7)', () => {
    expect(Object.values(Role).filter((r) => layanan(r, 3) !== undefined)).toEqual([])
  })

  it('anak menu sama dengan role route (menu tidak menjanjikan halaman yang ditolak guard)', async () => {
    const { layananRoutes } = await import('@/features/layanan/routes.layanan')
    const roleRute = (path: string) => layananRoutes.find((r) => r.path === path)?.meta?.roles
    for (const c of layanan(Role.SUPER_ADMIN)?.children ?? []) {
      expect(c.roles, c.key).toEqual(roleRute(c.to as string))
    }
  })
})
