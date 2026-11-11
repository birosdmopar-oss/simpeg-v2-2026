/**
 * MAKE-087 (F-13 M1) — beranda per keluarga role: admin 1/3/4/5/8 → AdminDashboard dengan komposisi pegawai mode `donut`
 * (donut + tile status hari ini) atau `angka` (semua tile berurutan legacy + bar persen, tanpa chart; Q7-36); pegawai
 * 2/6/7 → PegawaiDashboard; galat memuat tampil tanpa merusak sapaan (`home-role` MTC-001 tetap ada).
 * M2a: 4 kartu pengingat (label legacy, hanya Pensiun bertautan ke /dokumen/pensiun — Q7-37), kartu Butuh Approval hanya
 * role 1/3 di keluarga admin ("—" tanpa tautan selama ACTIVE_PHASE < FASE_LONCENG; fase 7 di ButuhApprovalCard.spec), tabel direktur hanya role 1/4 (+ galat lokal).
 * M3: ACTIVE_PHASE dimock 6 (< FASE_KONTEN) → banner, berita, popup Jalur A tidak dipasang dan tidak ada request konten;
 * fase 7 di `HomeView.konten.spec.ts`.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/beranda.service', () => ({
  berandaService: { ringkasan: vi.fn(), direktur: vi.fn(), ulangTahun: vi.fn(), fotoUlangTahun: vi.fn() },
}))
vi.mock('@/shared/layouts/nav.config', async (asli) => ({ ...(await asli<typeof import('@/shared/layouts/nav.config')>()), ACTIVE_PHASE: 6 }))
vi.mock('@/features/konten/services/konten.service', () => ({
  kontenService: { popupAktif: vi.fn(), bannerAktif: vi.fn(), beritaTerbaru: vi.fn(), gambarBlob: vi.fn() },
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { resetSesiKonten } from '@/features/konten'
import { kontenService } from '@/features/konten/services/konten.service'
import { resetAntreanDialog } from '@/shared/composables/antreanDialog'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import router from '@/router'

import { berandaService } from '../services/beranda.service'
import type { KartuPrediksi, RingkasanAdmin, RingkasanPegawai, TileBeranda, UlangTahunItem } from '../types'
import HomeView from '../views/HomeView.vue'

const tile = (kode: string, label: string, kelompok: TileBeranda['kelompok'], jumlah: number, persen: number): TileBeranda => ({ kode, label, kelompok, jumlah, persen })

const admin = (tampilan: RingkasanAdmin['tampilan_komposisi']): RingkasanAdmin => ({
  keluarga: 'admin',
  lingkup: { jenis: 'kementerian', id: null },
  tampilan_komposisi: tampilan,
  tanggal: '2026-11-10',
  total_aktif: 1200,
  pengingat: [
    { kode: 'pensiun', label: 'Pensiun', jumlah: 12 },
    { kode: 'kp', label: 'Naik Pangkat', jumlah: 1340 },
    { kode: 'tj', label: 'Tanda Jasa', jumlah: 7 },
    { kode: 'kgb', label: 'KGB', jumlah: 0 },
  ],
  tile: [
    tile('pns', 'Total PNS', 'komposisi', 1000, 83),
    tile('ptt', 'Total PTT', 'komposisi', 100, 8),
    tile('non_pns', 'Total Non PNS', 'komposisi', 20, 2),
    tile('staf_khusus', 'Total Staff Khusus', 'komposisi', 5, 0),
    tile('tenaga_ahli', 'Total Tenaga Ahli', 'komposisi', 15, 1),
    tile('tb', 'Tugas Belajar', 'hari_ini', 12, 1),
    tile('ib', 'Izin Belajar', 'hari_ini', 8, 1),
    tile('cltn', 'CLTN', 'hari_ini', 2, 0),
    tile('cuti', 'Cuti Online', 'hari_ini', 30, 3),
    tile('presensi_online', 'Presensi Online', 'hari_ini', 640, 53),
    tile('pppk', 'Total PPPK', 'komposisi', 60, 5),
  ],
})

async function mountAs(role: RoleCode) {
  setActivePinia(createPinia())
  useAuthStore().user = { username: 'uji', user_level: role } as User
  const wrapper = mount(HomeView, { attachTo: document.body, global: { plugins: [router], stubs: { RedesignShell: { template: '<div><slot /></div>' } } } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  resetSesiKonten()
  vi.mocked(kontenService.popupAktif).mockReset().mockResolvedValue([])
  vi.mocked(kontenService.bannerAktif).mockReset().mockResolvedValue([])
  vi.mocked(kontenService.beritaTerbaru).mockReset().mockResolvedValue([])
  vi.mocked(kontenService.gambarBlob).mockReset().mockResolvedValue(new Blob(['x'], { type: 'image/png' }))
  vi.mocked(berandaService.ulangTahun).mockReset().mockResolvedValue([])
  vi.mocked(berandaService.fotoUlangTahun).mockReset().mockResolvedValue(new Blob(['x'], { type: 'image/png' }))
  URL.createObjectURL = vi.fn(() => 'blob:foto-uji')
  URL.revokeObjectURL = vi.fn()
  vi.mocked(berandaService.ringkasan).mockReset()
  vi.mocked(berandaService.direktur).mockReset().mockResolvedValue([
    { nama: 'Dr. Direktur Fixture, M.Par.', politeknik: 'Politeknik Fixture', masa_kerja: '2 Tahun 3 Bulan' },
    { nama: 'Direktur Baru', politeknik: 'Politeknik Lain', masa_kerja: '' },
  ])
})

afterEach(() => {
  resetAntreanDialog()
  document.body.innerHTML = ''
})

describe('HomeView — kartu admin M2a', () => {
  it('4 kartu pengingat berlabel legacy; hanya Pensiun bertautan ke Proyeksi Pensiun', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    const kartu = wrapper.findAll('[data-testid^="beranda-pengingat-"]')
    expect(kartu.map((k) => k.text())).toEqual(['12Pensiun', '1.340Naik Pangkat', '7Tanda Jasa', '0KGB'])
    expect(wrapper.get('[data-testid="beranda-pengingat-pensiun"] a').attributes('href')).toBe('/dokumen/pensiun')
    for (const kode of ['kp', 'tj', 'kgb']) {
      expect(wrapper.find(`[data-testid="beranda-pengingat-${kode}"] a`).exists()).toBe(false)
    }
  })

  it.each([
    [Role.SUPER_ADMIN, true, true],
    [Role.ADMIN_SATKER, true, false],
    [Role.ADMIN_VIEW_ESELON1, false, true],
    [Role.MENTERI, false, false],
    [Role.PIMPINAN, false, false],
  ])('role %i: Butuh Approval %s, tabel direktur %s', async (role, approval, direktur) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    const wrapper = await mountAs(role)

    expect(wrapper.find('[data-testid="beranda-butuh-approval"]').exists()).toBe(approval)
    expect(wrapper.find('[data-testid="beranda-direktur"]').exists()).toBe(direktur)
    expect(berandaService.direktur).toHaveBeenCalledTimes(direktur ? 1 : 0)
  })

  it('Butuh Approval "—" tanpa tautan selama lonceng Jalur C belum dibuka (ACTIVE_PHASE < FASE_LONCENG)', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    const kartu = wrapper.get('[data-testid="beranda-butuh-approval"]')
    expect(kartu.text()).toContain('—')
    expect(kartu.text()).toContain('Butuh Approval')
    expect(kartu.find('a').exists()).toBe(false)
  })

  it('tabel direktur: Nama, Politeknik, Masa Kerja ("—" bila kosong)', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.get('[data-testid="beranda-direktur-0"]').findAll('td').map((t) => t.text())).toEqual(['Dr. Direktur Fixture, M.Par.', 'Politeknik Fixture', '2 Tahun 3 Bulan'])
    expect(wrapper.get('[data-testid="beranda-direktur-1"]').findAll('td')[2]?.text()).toBe('—')
  })

  it('galat tabel direktur hanya di kartunya', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    vi.mocked(berandaService.direktur).mockRejectedValue({ status: 500, message: 'Gagal.', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.get('[data-testid="beranda-direktur"] [role="alert"]').text()).toBe('Gagal.')
    expect(wrapper.find('[data-testid="beranda-pengingat"]').exists()).toBe(true)
  })
})

describe('HomeView — keluarga admin', () => {
  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i → AdminDashboard', async (role) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    const wrapper = await mountAs(role)

    expect(wrapper.find('[data-testid="beranda-admin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="beranda-pegawai"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="home-role"]').text()).toContain(`${role} — `)
  })

  it('mode donut: donut komposisi (jenis pegawai) + tile status hari ini saja', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.find('[data-testid="beranda-komposisi-donut"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="beranda-komposisi-angka"]').exists()).toBe(false)
    expect(wrapper.find('svg').exists()).toBe(true)
    expect(wrapper.get('[data-testid="beranda-komposisi"]').text()).toContain('Total pegawai aktif 1.200')

    const tiles = wrapper.findAll('[data-testid^="beranda-tile-"]').map((t) => t.attributes('data-testid'))
    expect(tiles).toEqual(['tb', 'ib', 'cltn', 'cuti', 'presensi_online'].map((k) => `beranda-tile-${k}`))
    expect(wrapper.get('[data-testid="beranda-tile-presensi_online"]').text()).toContain('640')
  })

  it('mode angka (rollback legacy): 11 tile urutan legacy + bar persen, tanpa chart', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('angka'))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.find('[data-testid="beranda-komposisi-donut"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="beranda-komposisi"] svg').exists()).toBe(false)

    const labels = wrapper.findAll('[data-testid^="beranda-tile-"]').map((t) => t.find('p').text())
    expect(labels).toEqual([
      'Total PNS', 'Total PTT', 'Total Non PNS', 'Total Staff Khusus', 'Total Tenaga Ahli', 'Tugas Belajar', 'Izin Belajar', 'CLTN',
      'Cuti Online', 'Presensi Online', 'Total PPPK',
    ])
    expect(wrapper.get('[data-testid="beranda-tile-pns"]').text()).toContain('1.000')
    expect(wrapper.get('[data-testid="beranda-tile-pns"] [role="progressbar"]').attributes('aria-valuenow')).toBe('83')
  })

  it('lingkup satker ditampilkan', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue({ ...admin('donut'), lingkup: { jenis: 'satker', id: 7 } })
    const wrapper = await mountAs(Role.ADMIN_SATKER)

    expect(wrapper.get('[data-testid="beranda-lingkup"]').text()).toBe('Lingkup data: Satker')
  })
})

const prediksi = (kode: KartuPrediksi['kode'], label: string, extra: Partial<KartuPrediksi> = {}): KartuPrediksi => ({
  kode, label, tanggal: null, teks: null, sisa: null, ...extra,
})

const tanpaBawahan = { atasan: false, presensi: [], ak: [] }

const pegawai = (kartu: KartuPrediksi[], bawahan: RingkasanPegawai['bawahan'] = tanpaBawahan): RingkasanPegawai => ({
  keluarga: 'pegawai',
  tanggal: '2026-11-10',
  prediksi: kartu,
  bawahan,
  kelengkapan: [],
})

describe('HomeView — kartu prediksi pegawai M2b', () => {
  it('role 2: 4 kartu teks legacy (judul, label, sisa; "-" bila kosong)', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([
      prediksi('pensiun', 'Pensiun', { tanggal: '2028-06-01', teks: 'Juni 2028', sisa: '1 Tahun 6 Bulan' }),
      prediksi('kp', 'Naik Pangkat', { tanggal: '2027-04-01', teks: 'April 2027', sisa: '4 Bulan', jenis: 'ST', pangkat_puncak: false, ak: null }),
      prediksi('tj', 'Tanda Jasa', { tanggal: '2026-08-01', teks: 'Agustus 2026', sisa: '-' }),
      prediksi('kgb', 'KGB'),
    ]))
    const wrapper = await mountAs(Role.PEGAWAI)

    const isi = (kode: string) => ['judul', 'label', 'ket'].map((t) => wrapper.get(`[data-testid="beranda-prediksi-${kode}"] [data-testid="${t}"]`).text())
    expect(isi('pensiun')).toEqual(['Juni 2028', 'Pensiun', '1 Tahun 6 Bulan'])
    expect(isi('kp')).toEqual(['April 2027', 'Naik Pangkat', '4 Bulan'])
    expect(isi('tj')).toEqual(['Agustus 2026', 'Tanda Jasa', '-'])
    expect(isi('kgb')).toEqual(['-', 'KGB', '-'])
  })

  it('KP JF: "AK sekarang / target" + "Kurang N AK" (koma desimal)', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([
      prediksi('kp', 'Naik Pangkat', { jenis: 'JF', pangkat_puncak: false, ak: { sekarang: 87.5, target: 100, kurang: 12.5 } }),
    ]))
    const wrapper = await mountAs(Role.PEGAWAI)

    expect(wrapper.get('[data-testid="beranda-prediksi-kp"] [data-testid="judul"]').text()).toBe('87,5 / 100')
    expect(wrapper.get('[data-testid="beranda-prediksi-kp"] [data-testid="ket"]').text()).toBe('Kurang 12,5 AK')
  })

  it('KP pangkat puncak: "Sudah di Pangkat Puncak"', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([prediksi('kp', 'Naik Pangkat', { jenis: 'ST', pangkat_puncak: true, ak: null })]))
    const wrapper = await mountAs(Role.PEGAWAI)

    expect(wrapper.get('[data-testid="beranda-prediksi-kp"] [data-testid="label"]').text()).toBe('Sudah di Pangkat Puncak')
    expect(wrapper.get('[data-testid="beranda-prediksi-kp"] [data-testid="ket"]').text()).toBe('')
  })

  it.each([
    [Role.PEGAWAI, true],
    [Role.PTT, false],
    [Role.PPPK, false],
  ])('role %i: Butuh Approval %s; tanpa kartu prediksi bila daftar kosong; tautan Presensi Saya', async (role, approval) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([]))
    const wrapper = await mountAs(role)

    expect(wrapper.find('[data-testid="beranda-prediksi"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="beranda-butuh-approval"]').exists()).toBe(approval)
    expect(wrapper.get('[data-testid="beranda-presensi-saya"]').attributes('href')).toBe('/presensi')
  })
})

describe('HomeView — bawahan atasan M2b', () => {
  it('atasan: tabel presensi bawahan (Nama, Jam Masuk, Jam Pulang) + AK bawahan JF', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([], {
      atasan: true,
      presensi: [{ nama: 'Staf Umum, S.E.', jam_masuk: '07:31:00', jam_pulang: '' }],
      ak: [{ order: 12.5, nama: 'Fungsional', nip: '199001012020011002', gol_ruang: 'III/a', satker: 'Satker Fixture', jabatan: 'JF Fixture', ak_now: 87.5, ak_kp: 100, kurang: -12.5 }],
    }))
    const wrapper = await mountAs(Role.PEGAWAI)

    expect(wrapper.get('[data-testid="beranda-bawahan-presensi-0"]').findAll('td').map((t) => t.text())).toEqual(['Staf Umum, S.E.', '07:31:00', ''])
    expect(wrapper.get('[data-testid="beranda-bawahan-ak-199001012020011002"]').findAll('td').map((t) => t.text())).toEqual([
      '12,5', 'Fungsional', '199001012020011002', 'III/a', 'Satker Fixture', 'JF Fixture', '87,5', '100', '-12,5',
    ])
  })

  it('atasan tanpa bawahan JF: hanya tabel presensi', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([], { atasan: true, presensi: [{ nama: 'A', jam_masuk: '', jam_pulang: '' }], ak: [] }))
    const wrapper = await mountAs(Role.PEGAWAI)

    expect(wrapper.find('[data-testid="beranda-bawahan-presensi"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="beranda-bawahan-ak"]').exists()).toBe(false)
  })

  it('bukan atasan: tanpa bagian bawahan', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([]))
    const wrapper = await mountAs(Role.PEGAWAI)

    expect(wrapper.find('[data-testid="beranda-bawahan"]').exists()).toBe(false)
  })
})

describe('HomeView — ulang tahun (Q7-45)', () => {
  const ultah = (extra: Partial<UlangTahunItem> = {}): UlangTahunItem => ({
    nip: '198511102010012001', nama: 'Ani', tanggal_lahir: '10 November 1985', jenis_pegawai: 'PNS', jabatan: 'Analis (Ketua Tim)',
    satker: 'Satker Fixture', khusus: false, ada_foto: true, foto_kunci: '198511102010012001', ...extra,
  })

  it.each([Role.SUPER_ADMIN, Role.PEGAWAI, Role.PTT])('role %i: kartu hari ini & besok, field view legacy, foto dimuat', async (role) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(role === Role.SUPER_ADMIN ? admin('donut') : pegawai([]))
    vi.mocked(berandaService.ulangTahun).mockImplementation(async (hari) => (hari === 'ini' ? [ultah()] : []))
    const wrapper = await mountAs(role)

    expect(berandaService.ulangTahun).toHaveBeenCalledWith('ini')
    expect(berandaService.ulangTahun).toHaveBeenCalledWith('besok')
    const item = wrapper.get('[data-testid="beranda-ulang-tahun-ini-item"]')
    expect(['nama', 'tgl', 'nip', 'jabatan', 'satker'].map((t) => item.get(`[data-testid="${t}"]`).text())).toEqual([
      'Ani', '10 November 1985', 'PNS / 198511102010012001', 'Analis (Ketua Tim)', 'Satker Fixture',
    ])
    expect(berandaService.fotoUlangTahun).toHaveBeenCalledWith('198511102010012001')
    expect(item.find('img').attributes('src')).toBe('blob:foto-uji')
    expect(wrapper.get('[data-testid="beranda-ulang-tahun-besok"]').text()).toContain('Tidak ada pegawai yang berulang tahun.')
  })

  it('baris khusus: hanya nama, tanggal lahir, jabatan; tanpa foto tidak memanggil endpoint foto', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    vi.mocked(berandaService.ulangTahun).mockImplementation(async (hari) =>
      hari === 'ini' ? [ultah({ nip: null, jenis_pegawai: null, satker: null, khusus: true, jabatan: 'Menteri Pariwisata', ada_foto: false, foto_kunci: '1' })] : [],
    )
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    const item = wrapper.get('[data-testid="beranda-ulang-tahun-ini-item"]')
    expect(item.find('[data-testid="nip"]').exists()).toBe(false)
    expect(item.find('[data-testid="satker"]').exists()).toBe(false)
    expect(item.get('[data-testid="jabatan"]').text()).toBe('Menteri Pariwisata')
    expect(berandaService.fotoUlangTahun).not.toHaveBeenCalled()
  })

  it('galat memuat ulang tahun hanya di kartunya', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    vi.mocked(berandaService.ulangTahun).mockRejectedValue({ status: 500, message: 'Gagal.', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.get('[data-testid="beranda-ulang-tahun-ini"] [role="alert"]').text()).toBe('Gagal.')
    expect(wrapper.find('[data-testid="beranda-pengingat"]').exists()).toBe(true)
  })
})

describe('HomeView — kelengkapan data pegawai', () => {
  it('kartu < 100%: label, persen, bar, tautan ke tab Detail Pegawai milik sendiri', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue({
      ...pegawai([]),
      kelengkapan: [
        { kode: 'data-umum', label: 'Data Umum', persen: 27 },
        { kode: 'kp', label: 'Data Pangkat', persen: 67 },
      ],
    })
    setActivePinia(createPinia())
    useAuthStore().user = { username: 'uji', user_level: Role.PEGAWAI, nip: '199001012020011001' } as User
    const wrapper = mount(HomeView, { global: { plugins: [router], stubs: { RedesignShell: { template: '<div><slot /></div>' } } } })
    await flushPromises()

    const kartu = wrapper.findAll('[data-testid^="beranda-kelengkapan-"]')
    expect(kartu.map((k) => k.text())).toEqual(['Data Umum27%', 'Data Pangkat67%'])
    const umum = wrapper.get('[data-testid="beranda-kelengkapan-data-umum"] a')
    expect(umum.attributes('href')).toBe('/pegawai/199001012020011001?tab=data-umum')
    expect(umum.attributes('aria-label')).toBe('Klik Untuk Melengkapi Data Umum Anda: 27%')
    expect(wrapper.get('[data-testid="beranda-kelengkapan-data-umum"] [role="progressbar"]').attributes('aria-valuenow')).toBe('27')
    expect(wrapper.get('[data-testid="beranda-kelengkapan-kp"] a').attributes('href')).toBe('/pegawai/199001012020011001?tab=kp')
  })

  it('tanpa kartu kelengkapan bila daftar kosong', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([]))
    const wrapper = await mountAs(Role.PEGAWAI)

    expect(wrapper.find('[data-testid="beranda-kelengkapan"]').exists()).toBe(false)
  })
})

describe('HomeView — keluarga pegawai & galat', () => {
  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i → PegawaiDashboard', async (role) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(pegawai([]))
    const wrapper = await mountAs(role)

    expect(wrapper.find('[data-testid="beranda-pegawai"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="beranda-admin"]').exists()).toBe(false)
  })

  it('galat memuat → pesan, sapaan & home-role tetap', async () => {
    vi.mocked(berandaService.ringkasan).mockRejectedValue({ status: 500, message: 'Server sedang bermasalah.', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.get('[role="alert"]').text()).toBe('Server sedang bermasalah.')
    expect(wrapper.get('[data-testid="home-role"]').text()).toContain('1 — ')
    expect(wrapper.find('[data-testid="beranda-admin"]').exists()).toBe(false)
  })
})

describe('HomeView — konten Jalur A digerbang fase (ACTIVE_PHASE 6 < FASE_KONTEN)', () => {
  const gambar = (id: string) => ({ id, gambar_url: `/g/${id}.png`, url: null, keterangan: `Ket ${id}` })

  it.each([Role.SUPER_ADMIN, Role.PEGAWAI])('role %i: tanpa banner, berita, popup, dan tanpa request konten', async (role) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(role === Role.SUPER_ADMIN ? admin('donut') : pegawai([]))
    vi.mocked(kontenService.bannerAktif).mockResolvedValue([gambar('b1')])
    vi.mocked(kontenService.popupAktif).mockResolvedValue([gambar('p1')])
    vi.mocked(kontenService.beritaTerbaru).mockResolvedValue([{ id: '7', judul: 'Berita Uji', ringkasan: '', kategori: '', tanggal: null, foto_url: null }])
    const wrapper = await mountAs(role)

    expect(wrapper.find('[data-testid="konten-banner"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="konten-berita-terbaru"]').exists()).toBe(false)
    expect(document.body.querySelector('[data-testid="konten-popup-aktif"]')).toBeNull()
    expect(kontenService.bannerAktif).not.toHaveBeenCalled()
    expect(kontenService.popupAktif).not.toHaveBeenCalled()
    expect(kontenService.beritaTerbaru).not.toHaveBeenCalled()
    expect(wrapper.find(role === Role.SUPER_ADMIN ? '[data-testid="beranda-admin"]' : '[data-testid="beranda-pegawai"]').exists()).toBe(true)
  })
})
