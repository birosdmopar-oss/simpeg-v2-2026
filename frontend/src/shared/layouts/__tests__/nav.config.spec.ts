/**
 * nav.config — menu sidebar redesign per role & fase aktif. Hak akses mengikuti Matriks Role x Endpoint
 * (Fase 3: Matriks v2 + keputusan user). Semua menu yang ada di `main` (termasuk Web Config) wajib ada; menu Fase 3
 * tampil sejak B-20 penutup (WS-2 MAKE-014), menu Layanan Fase 4 sejak penutup M7 (MAKE-026, PRD Fase 4 §6.11.2), dan
 * menu Presensi Fase 5 sejak penutup 5C (MAKE-053, tanpa `rilis`; anak per role `NavChild.roles`), dan menu Fase 6–8
 * (Integrasi, Pusat Notifikasi, Arsip, Laporan, Dokumen, konten admin, Halo Simpeg, Chatbot Manager) sejak rilis menu Fase 8
 * (ACTIVE_PHASE = 8, CR-135). Item tanpa `to` dan tanpa anak (E-Kinerja/E-Talenta, belum ada halaman) tidak pernah tampil [V2].
 */
import { describe, expect, it } from 'vitest'

import { Role, type RoleCode } from '@/features/auth/types'

import { ACTIVE_PHASE, buildNav } from '../nav.config'

const labels = (role: Parameters<typeof buildNav>[0], phase?: number): string[] =>
  buildNav(role, phase).flatMap((g) => g.items.map((i) => i.label))

const rolesWith = (menu: string, phase?: number): RoleCode[] => Object.values(Role).filter((r) => labels(r, phase).includes(menu))

const FASE3_MENUS = ['Daftar Pegawai', 'Pengajuan Data Umum', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH']

describe('buildNav — fase aktif 8 (rilis menu Fase 8, CR-135)', () => {
  it('ACTIVE_PHASE = 8 sejak sign-off Fase 8 (Horii 18-11-2026, H8-11 (a))', () => {
    expect(ACTIVE_PHASE).toBe(8)
  })

  // Menu lengkap per role (urut sidebar). Role 1: "Halo Simpeg" dua kali = master admin (Pengaturan) + halaman tiket (Pusat Bantuan).
  const BERITA = ['Portal Berita', 'DIBO']
  it.each([
    [
      Role.SUPER_ADMIN,
      ['Beranda', 'Notifikasi', ...FASE3_MENUS, 'Arsip', 'Layanan', 'Presensi', 'Laporan', 'Dokumen', ...BERITA, 'Kelola Berita', 'Kelola Konten', 'Form Digital', 'Survei Layanan',
        'Master Data', 'Angka Kredit JF', 'Web Config', 'Hari Libur', 'Manajemen Akun', 'Integrasi', 'Chatbot Manager', 'Halo Simpeg', 'Halo Simpeg', 'FAQ'],
    ],
    [Role.PEGAWAI, ['Beranda', 'Notifikasi', 'Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH', 'Arsip', 'Layanan', 'Presensi', ...BERITA, 'Halo Simpeg', 'FAQ']],
    [
      Role.ADMIN_SATKER,
      ['Beranda', 'Notifikasi', 'Daftar Pegawai', 'Pengajuan Data Umum', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Verifikasi LKH', 'Arsip', 'Layanan', 'Presensi', 'Laporan', 'Dokumen',
        ...BERITA, 'Kelola Berita', 'Manajemen Akun', 'Halo Simpeg', 'FAQ'],
    ],
    [
      Role.ADMIN_VIEW_ESELON1,
      ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Arsip', 'Layanan', 'Presensi', 'Laporan', 'Dokumen', ...BERITA, 'Hari Libur', 'Halo Simpeg', 'FAQ'],
    ],
    [
      Role.MENTERI,
      ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Arsip', 'Layanan', 'Presensi', 'Laporan', 'Dokumen', ...BERITA, 'Hari Libur', 'Halo Simpeg', 'FAQ'],
    ],
    [Role.PTT, ['Beranda', 'Notifikasi', 'Struktur Organisasi', 'Usulan Konket', 'Verifikasi LKH', 'Arsip', 'Layanan', 'Presensi', ...BERITA, 'Halo Simpeg', 'FAQ']],
    [Role.PPPK, ['Beranda', 'Notifikasi', 'Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH', 'Arsip', 'Layanan', 'Presensi', ...BERITA, 'Halo Simpeg', 'FAQ']],
    [Role.PIMPINAN, ['Beranda', 'Daftar Pegawai', 'Struktur Organisasi', 'Laporan', 'Dokumen', ...BERITA, 'Hari Libur', 'FAQ']],
  ])('role %i melihat menu sesuai Matriks v2 + menu Fase 6–8', (role, harapan) => {
    expect(labels(role)).toEqual(harapan)
  })

  it('tidak ada item ber-`rilis` dengan `phase <= ACTIVE_PHASE` (rilis lebih awal sudah dibersihkan, CR-135)', () => {
    for (const role of Object.values(Role)) {
      for (const item of buildNav(role, Number.MAX_SAFE_INTEGER).flatMap((g) => g.items)) {
        if (item.rilis) expect(item.phase, `${item.key} masih ber-rilis`).toBeGreaterThan(ACTIVE_PHASE)
      }
    }
  })

  it.each(Object.values(Role))('role %i: tanpa key duplikat dan tanpa item mati (tanpa `to` dan tanpa anak)', (role) => {
    const items = buildNav(role).flatMap((g) => g.items)
    const keys = [...items.map((i) => i.key), ...items.flatMap((i) => (i.children ?? []).map((c) => c.key))]
    expect(new Set(keys).size).toBe(keys.length)
    for (const i of items) expect(i.to !== undefined || (i.children?.length ?? 0) > 0, `${i.key} tanpa tujuan`).toBe(true)
  })

  it('Daftar Pegawai hanya role 1, 3, 4, 5, 8', () => {
    expect(rolesWith('Daftar Pegawai')).toEqual([1, 3, 4, 5, 8])
  })

  it('Struktur Organisasi untuk semua role login (dan tanpa role)', () => {
    expect(rolesWith('Struktur Organisasi')).toEqual(Object.values(Role))
    expect(labels(null)).toEqual(['Beranda', 'Struktur Organisasi', 'Portal Berita', 'DIBO', 'FAQ'])
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

  it('item tanpa `to` dan tanpa anak (E-Kinerja, E-Talenta) tidak tampil; grup Karier ikut hilang, pada fase berapa pun', () => {
    for (const fase of [ACTIVE_PHASE, Number.MAX_SAFE_INTEGER]) {
      const all = Object.values(Role).flatMap((r) => labels(r, fase))
      expect(all).not.toContain('E-Kinerja')
      expect(all).not.toContain('E-Talenta')
      for (const r of Object.values(Role)) expect(buildNav(r, fase).map((g) => g.key)).not.toContain('karier')
    }
  })

  it.each(Object.values(Role).filter((r) => r !== Role.SUPER_ADMIN))('role %i tidak melihat Web Config (G-09, role 1 saja)', (role) => {
    expect(labels(role)).not.toContain('Web Config')
  })

  it('grup tanpa item dibuang (Pegawai tidak punya grup Pengaturan; Karier kosong karena itemnya tanpa tujuan)', () => {
    expect(buildNav(Role.PEGAWAI).map((g) => g.key)).toEqual(['main', 'kepegawaian', 'berita', 'bantuan'])
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
      integrasi: { name: 'integrasi-admin' },
      notifikasi: '/notifikasi',
      arsip: { name: 'arsip' },
      layanan: undefined,
      presensi: undefined,
      laporan: undefined,
      dokumen: undefined,
      berita: { name: 'konten-berita' },
      'konten-dibo-baca': { name: 'konten-dibo' },
      'konten-berita-kelola': { name: 'konten-berita-kelola' },
      'konten-admin': undefined,
      'form-digital': { name: 'form-digital-kelola' },
      'survei-layanan': { name: 'survei-layanan-rekap' },
      'chatbot-manager': undefined,
      'halo-admin': undefined,
      halo: { name: 'halo-simpeg' },
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

  it('Super Admin melihat menu main saja (Integrasi fase 6 tanpa `rilis` sejak CR-135 → tidak ada di fase 2)', () => {
    expect(labels(Role.SUPER_ADMIN, 2)).toEqual(['Beranda', 'Master Data', 'Web Config', 'Hari Libur', 'Manajemen Akun', 'FAQ'])
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

  it('Laporan (fase 7) = role 1, 3, 4, 5, 8 (hr/chart/*), tampil pada fase aktif 8 (CR-135), tersembunyi di fase 6', () => {
    const punya = Object.values(Role).filter((r) => buildNav(r).flatMap((g) => g.items).some((i) => i.key === 'laporan'))
    expect(punya).toEqual([1, 3, 4, 5, 8])
    expect(Object.values(Role).flatMap((r) => labels(r, 6))).not.toContain('Laporan')
  })

  it('Dokumen (fase 7, MAKE-037) = role 1, 3, 4, 5, 8 (hr/document/*), 4 anak, tersembunyi di fase 6', () => {
    const punya = Object.values(Role).filter((r) => buildNav(r, 7).flatMap((g) => g.items).some((i) => i.key === 'dokumen'))
    expect(punya).toEqual([1, 3, 4, 5, 8])
    const dokumen = buildNav(Role.SUPER_ADMIN, 7)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'dokumen')
    expect(dokumen?.children?.map((c) => c.label)).toEqual(['DUK', 'Nominatif', 'Proyeksi Pensiun', 'Baperjakat'])
    expect(dokumen?.children?.map((c) => c.to)).toEqual(['/dokumen/duk', '/dokumen/nominatif', '/dokumen/pensiun', '/dokumen/baperjakat'])
    expect(Object.values(Role).flatMap((r) => labels(r, 6))).not.toContain('Dokumen')
  })
})

describe('buildNav — Arsip (F-10, MAKE-091)', () => {
  const arsip = (role: RoleCode | null, phase?: number) =>
    buildNav(role, phase)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'arsip')

  it('menu Arsip = role 1–7 (role 8 dan tanpa role tidak), menuju route `arsip`, di grup Kepegawaian', () => {
    const punya = Object.values(Role).filter((r) => arsip(r, 7) !== undefined)
    expect(punya).toEqual([1, 2, 3, 4, 5, 6, 7])
    expect(arsip(null, 7)).toBeUndefined()
    expect(arsip(Role.PEGAWAI, 7)).toMatchObject({ label: 'Arsip', to: { name: 'arsip' }, phase: 7 })
    const grup = buildNav(Role.PEGAWAI, 7).find((g) => g.items.some((i) => i.key === 'arsip'))
    expect(grup?.key).toBe('kepegawaian')
  })

  it('ikut gerbang fase 7 tanpa `rilis` (QF10-3): tampil pada fase aktif 8 (CR-135), tersembunyi di fase 6', () => {
    expect(ACTIVE_PHASE).toBeGreaterThanOrEqual(7)
    expect(Object.values(Role).filter((r) => arsip(r) !== undefined)).toEqual([1, 2, 3, 4, 5, 6, 7])
    for (const role of Object.values(Role)) expect(arsip(role, 6), `role ${role}`).toBeUndefined()
    expect(arsip(Role.SUPER_ADMIN, 7)).not.toHaveProperty('rilis')
  })
})

describe('buildNav — `rilis` & `NavChild.roles` (F5-18, PRD Fase 5A §7.8.2)', () => {
  const presensi = (role: RoleCode | null, phase?: number) =>
    buildNav(role, phase)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'presensi')

  it('grup Presensi (phase 5, tanpa `rilis`) tampil pada fase aktif (≥ 5) dan tersembunyi pada fase 4', () => {
    expect(ACTIVE_PHASE).toBeGreaterThanOrEqual(5)
    expect(presensi(Role.PEGAWAI)).toMatchObject({ phase: 5 })
    expect(presensi(Role.PEGAWAI)).not.toHaveProperty('rilis')
    for (const role of Object.values(Role)) expect(presensi(role, 4), `role ${role}`).toBeUndefined()
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
        { key: 'presensi-rekap-tukin', label: 'Rekap Tukin', to: '/presensi/tukin/rekap', roles: [1, 3] },
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

  it('item tanpa `to` dengan children kosong tidak tampil walau fasenya lewat (E-Kinerja fase 6, CR-135)', () => {
    expect(labels(Role.SUPER_ADMIN, 5)).not.toContain('E-Kinerja')
    expect(labels(Role.SUPER_ADMIN, 6)).not.toContain('E-Kinerja')
    expect(labels(Role.SUPER_ADMIN)).not.toContain('E-Kinerja')
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

describe('buildNav — admin master Halo Simpeg (MAKE-073)', () => {
  const adminHalo = (role: RoleCode, fase: number) =>
    buildNav(role, fase)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'halo-admin')

  it('role 1 saja, terbuka saat fase 8 (= ACTIVE_PHASE sejak CR-135, tersembunyi di fase 7); anak Kategori/Petugas/Kontak', () => {
    expect(adminHalo(Role.SUPER_ADMIN, 7)).toBeUndefined()
    expect(adminHalo(Role.SUPER_ADMIN, ACTIVE_PHASE)).toBeDefined()
    const item = adminHalo(Role.SUPER_ADMIN, 8)
    expect(item?.children?.map((c) => [c.label, c.to])).toEqual([
      ['Kategori', { name: 'halo-admin-kategori' }],
      ['Petugas', { name: 'halo-admin-petugas' }],
      ['Kontak', { name: 'halo-admin-kontak' }],
    ])
    for (const role of [Role.PEGAWAI, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PTT, Role.PPPK, Role.PIMPINAN]) {
      expect(adminHalo(role, 8)).toBeUndefined()
    }
  })

  it('item `halo` Pusat Bantuan (CR-135): to = route `halo-simpeg`, roles = HALO_ROLES 1–7; role 8 dan tanpa role tidak melihat', () => {
    const halo = (role: RoleCode | null) =>
      buildNav(role)
        .flatMap((g) => g.items)
        .find((i) => i.key === 'halo')
    expect(halo(Role.PEGAWAI)).toMatchObject({ label: 'Halo Simpeg', to: { name: 'halo-simpeg' }, roles: [1, 2, 3, 4, 5, 6, 7], phase: 8 })
    expect(Object.values(Role).filter((r) => halo(r) !== undefined)).toEqual([1, 2, 3, 4, 5, 6, 7])
    expect(halo(Role.PIMPINAN)).toBeUndefined()
    expect(halo(null)).toBeUndefined()
    expect(buildNav(Role.PEGAWAI, 7).flatMap((g) => g.items).some((i) => i.key === 'halo')).toBe(false)
  })

  it('nama route item `halo` terdaftar di router Halo Simpeg', async () => {
    const { haloRoutes } = await import('@/features/halo-simpeg/routes.halo')
    const nama = (rs: readonly { name?: unknown; children?: readonly unknown[] }[]): unknown[] =>
      rs.flatMap((r) => [r.name, ...nama((r.children ?? []) as { name?: unknown }[])])
    expect(nama(haloRoutes as never)).toContain('halo-simpeg')
  })
})
