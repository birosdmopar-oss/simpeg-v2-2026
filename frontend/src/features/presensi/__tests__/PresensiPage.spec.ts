/**
 * PresensiPage (D-02, AC-FE.1, AC-FE.8): status lokasi tervisualisasi sebelum submit (izin, koordinat, akurasi, putusan
 * server dalam/luar radius, jarak, indikator radius); tombol masuk/pulang mengikuti `boleh_*` server dan — bila
 * `wajib_geofence` — putusan `cek-lokasi` server (FE tidak menghitung jarak sebagai penentu); `cek-lokasi` maks sekali
 * per 10 detik; koordinat dikirim di body; 422 server ditampilkan. Payload memakai angka/boolean string (MySQLi).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v,
}))

import { Role } from '@/features/auth/types'
import { api } from '@/lib/axios'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import PresensiPage from '../views/PresensiPage.vue'

const get = vi.mocked(api.get)
const post = vi.mocked(api.post)
const ok = <T>(data: T) => Promise.resolve({ data, headers: {} })
const gagal = (status: number, message = 'x') => Promise.reject({ status, message, errors: null, isNetworkError: false, original: {} })

const HARI_INI = (o: Record<string, unknown> = {}) => ({
  tanggal_lokal: '2026-10-28', jam_lokal: '07:10:05', zonasi_menit: '0',
  jendela: { masuk: ['04:00:00', '12:30:59'], pulang: ['12:31:00', '23:59:59'] },
  boleh_masuk: '1', boleh_pulang: '0', presensi: null, wajib_geofence: '1',
  lokasi: [{ id_lokasi_presensi: '3', nama_lokasi: 'Kantor Pusat', latitude: '-6.2', longitude: '106.8', radius: '56' }],
  ...o,
})
const RIWAYAT = { bulan: '2026-10', total: '1', page: '1', per_page: '20', items: [{ tgl: '2026-10-27', jam_masuk: '07:00:00', jam_pulang: '16:05:00', sumber: '2' }] }

type Sukses = (p: { coords: { latitude: number; longitude: number; accuracy: number } }) => void
type Gagal = (e: { code: number }) => void
let geoPanggil = 0
let geoMode: 'izin' | 'tolak' = 'izin'

function pasangGeolokasi(): void {
  geoPanggil = 0
  Object.defineProperty(navigator, 'geolocation', {
    configurable: true,
    value: {
      getCurrentPosition: (s: Sukses, e: Gagal) => {
        geoPanggil += 1
        if (geoMode === 'tolak') e({ code: 1 })
        else s({ coords: { latitude: -6.2001, longitude: 106.8001, accuracy: 15.7 } })
      },
    },
  })
}

function server(hariIni = HARI_INI(), cek: Record<string, unknown> = { wajib_geofence: true, dalam_radius: '1', lokasi_terdekat: { id: '3', nama: 'Kantor Pusat', radius: '56', jarak_m: '16' } }): void {
  get.mockImplementation(((url: string) => (url === '/presensi/saya/hari-ini' ? ok(hariIni) : url === '/presensi/saya' ? ok(RIWAYAT) : gagal(404))) as never)
  post.mockImplementation(((url: string) => (url === '/presensi/cek-lokasi' ? ok(cek) : gagal(404))) as never)
}

async function mountPage() {
  loginAs(Role.PEGAWAI)
  const router = createShellRouter()
  router.addRoute({ path: '/presensi', name: 'presensi-saya', component: { render: () => null } })
  await router.push('/presensi')
  await router.isReady()
  const wrapper = mount(PresensiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  await flushPromises()
  return wrapper
}

const testid = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`)
const tombol = (id: string) => testid(id) as HTMLButtonElement
const cekDipanggil = () => post.mock.calls.filter(([url]) => url === '/presensi/cek-lokasi').length

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  get.mockReset()
  post.mockReset()
  geoMode = 'izin'
  pasangGeolokasi()
})
afterEach(() => {
  document.body.innerHTML = ''
  vi.useRealTimers()
})
enableAutoUnmount(afterEach)

describe('PresensiPage — status lokasi & tombol', () => {
  it('panel lokasi sebelum submit: izin, koordinat, akurasi, putusan server + indikator radius; Rekam Masuk aktif', async () => {
    server()
    await mountPage()

    expect(testid('presensi-izin')?.textContent).toContain('Diizinkan')
    expect(testid('presensi-koordinat')?.textContent).toContain('-6.200100, 106.800100')
    expect(testid('presensi-panel-lokasi')?.textContent).toContain('±16 m')
    expect(testid('presensi-hasil-cek')?.textContent).toContain('Di dalam radius Kantor Pusat')
    expect(testid('presensi-hasil-cek')?.textContent).toContain('radius 56 m')
    expect(testid('indikator-radius')).not.toBeNull()
    expect(testid('indikator-perangkat')).not.toBeNull()

    expect(tombol('presensi-masuk').disabled).toBe(false)
    expect(tombol('presensi-pulang').disabled).toBe(true)
    expect(testid('presensi-alasan-pulang')?.textContent).toContain('12:31–23:59')
  })

  it('wajib geofence + server menyatakan di luar radius → tombol nonaktif dengan alasan teks', async () => {
    server(HARI_INI(), { wajib_geofence: true, dalam_radius: '0', lokasi_terdekat: { id: '3', nama: 'Kantor Pusat', radius: '56', jarak_m: '1112' } })
    await mountPage()
    expect(testid('presensi-hasil-cek')?.textContent).toContain('Di luar radius Kantor Pusat — jarak ±1112 m')
    expect(tombol('presensi-masuk').disabled).toBe(true)
    expect(testid('presensi-alasan-masuk')?.textContent).toContain('di luar radius')
  })

  it('izin lokasi ditolak + wajib geofence → status "Ditolak", pesan, tombol nonaktif, tanpa cek-lokasi', async () => {
    geoMode = 'tolak'
    server()
    await mountPage()
    expect(testid('presensi-izin')?.textContent).toContain('Ditolak')
    expect(testid('presensi-pesan-geo')?.textContent).toContain('Akses lokasi ditolak')
    expect(tombol('presensi-masuk').disabled).toBe(true)
    expect(cekDipanggil()).toBe(0)
  })

  it('tidak wajib geofence → tombol aktif tanpa menunggu putusan lokasi', async () => {
    geoMode = 'tolak'
    server(HARI_INI({ wajib_geofence: '0', lokasi: [] }))
    await mountPage()
    expect(tombol('presensi-masuk').disabled).toBe(false)
    expect(testid('indikator-radius')).toBeNull()
  })

  it('geolokasi dibaca tiap 10 detik; cek-lokasi maks sekali per 10 detik', async () => {
    server()
    await mountPage()
    expect(geoPanggil).toBe(1)
    expect(cekDipanggil()).toBe(1)

    await vi.advanceTimersByTimeAsync(5_000)
    expect(cekDipanggil()).toBe(1)

    await vi.advanceTimersByTimeAsync(5_000)
    await flushPromises()
    expect(geoPanggil).toBe(2)
    expect(cekDipanggil()).toBe(2)
  })
})

describe('PresensiPage — rekam & riwayat', () => {
  it('Rekam Masuk mengirim koordinat di body lalu status disegarkan dari server', async () => {
    server()
    await mountPage()
    post.mockImplementation(((url: string) =>
      url === '/presensi/masuk'
        ? ok({ tgl: '2026-10-28', jam_masuk: '07:15:00', jam_pulang: null, id_lokasi_masuk: '3', jarak_m: '16' })
        : ok({ wajib_geofence: true, dalam_radius: '1', lokasi_terdekat: null })) as never)
    get.mockImplementation(((url: string) =>
      url === '/presensi/saya/hari-ini' ? ok(HARI_INI({ boleh_masuk: '0', presensi: { jam_masuk: '07:15:00', jam_pulang: null } })) : ok(RIWAYAT)) as never)

    tombol('presensi-masuk').click()
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/presensi/masuk', { latitude: -6.2001, longitude: 106.8001, akurasi: 15.7 })
    expect(testid('presensi-notice')?.textContent).toContain('terekam pukul 07:15')
    expect(testid('presensi-status-hari-ini')?.textContent).toContain('07:15')
    expect(tombol('presensi-masuk').disabled).toBe(true)
    expect(testid('presensi-alasan-masuk')?.textContent).toContain('sudah terekam pukul 07:15')
  })

  it('422 server saat rekam ditampilkan apa adanya', async () => {
    server()
    await mountPage()
    post.mockImplementation(((url: string) =>
      url === '/presensi/masuk'
        ? gagal(422, 'Anda berada di luar radius lokasi presensi. Lokasi terdekat: Kantor Pusat (jarak ±80 m, radius 56 m).')
        : ok({ wajib_geofence: true, dalam_radius: '0', lokasi_terdekat: { id: '3', nama: 'Kantor Pusat', radius: '56', jarak_m: '80' } })) as never)

    tombol('presensi-masuk').click()
    await flushPromises()
    expect(testid('presensi-galat-rekam')?.textContent).toContain('jarak ±80 m, radius 56 m')
  })

  it('riwayat: hari, tanggal, jam, badge sumber', async () => {
    server()
    await mountPage()
    const baris = testid('presensi-riwayat-2026-10-27')
    expect(baris?.textContent).toContain('Selasa')
    expect(baris?.textContent).toContain('27 Oktober 2026')
    expect(baris?.textContent).toContain('16:05')
    expect(baris?.textContent).toContain('Manual')
    expect(get).toHaveBeenCalledWith('/presensi/saya', { params: { page: 1, per_page: 20 } })
  })
})
