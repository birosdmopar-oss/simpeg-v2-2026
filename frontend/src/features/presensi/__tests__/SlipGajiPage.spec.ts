/**
 * SlipGajiPage (D-11, AC-FE.3): empat keadaan legacy [K `list.php`], modal konfirmasi sekali buka (Lanjutkan aktif
 * setelah centang), rincian + Cetak PDF hanya pada sesi buka, keadaan "sudah dibuka" tanpa nominal & tanpa Cetak PDF,
 * 422 saat buka menyegarkan status dari server. Payload memakai angka string (MySQLi).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v,
}))
vi.mock('@/features/kepegawaian/services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { Role } from '@/features/auth/types'
import { ambilBerkas, simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { api } from '@/lib/axios'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import SlipGajiPage from '../views/SlipGajiPage.vue'

const get = vi.mocked(api.get)
const post = vi.mocked(api.post)
const stub = { render: () => h('div') }

type Item = { periode: string; label: string; ada_data: unknown; dibuka: unknown; viewed_at: string | null; viewed_at_wib: string | null }

const ITEM = (o: Partial<Item> = {}): Item => ({
  periode: '2026-09',
  label: 'September 2026',
  ada_data: true,
  dibuka: false,
  viewed_at: null,
  viewed_at_wib: null,
  ...o,
})

const BUKA = {
  periode: '2026-09',
  label: 'September 2026',
  viewed_at: '2026-10-27T02:00:00Z',
  viewed_at_wib: '27 Oktober 2026, 09:00 WIB',
  rincian: { gjpokok: '3000000.00', tjistri: '300000.00', potpfkbul: '100000.00', bersih_gpp: '3200000.00', kotor: '2000000.00', bersih_tk: '1900000.00', bersih_2: '1850000.00' },
  ringkasan: {
    jumlah_penghasilan_gpp: '3300000.00',
    jumlah_potongan_gpp: '100000.00',
    bersih_gpp: '3200000.00',
    bersih_tk: '1900000.00',
    bersih_2: '1850000.00',
    uang_makan: '0.00',
    total_penghasilan: '5100000.00',
  },
  uang_makan: { nominal: '0.00', hari: null, periode_kehadiran: null, tersedia: false },
  pegawai: { nip: '199001012020011001', nama: 'Dr. Budi Santoso, M.Si.', pangkat: 'Penata / III/c', jabatan: 'Analis Uji', unit: 'Biro Uji', satker: 'Satker Uji' },
}

const ok = <T>(data: T) => Promise.resolve({ data, headers: {} })
const gagal = (status: number, message = 'x') => Promise.reject({ status, message, errors: null, isNetworkError: false, original: {} })

function daftar(items: Item[], terbaru: string | null = items[0]?.periode ?? null): void {
  get.mockImplementation((url: string) => (url === '/slip-gaji/saya' ? ok({ items, periode_terbaru: terbaru }) : gagal(404)) as never)
}

async function mountPage() {
  loginAs(Role.PEGAWAI)
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/slip-gaji', name: 'presensi-slip-gaji', component: stub })
  await router.push('/presensi/slip-gaji')
  await router.isReady()
  const wrapper = mount(SlipGajiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

const $ = (sel: string) => document.body.querySelector(sel)
const testid = (id: string) => $(`[data-testid="${id}"]`)

beforeEach(() => {
  get.mockReset()
  post.mockReset()
})
afterEach(() => {
  document.body.innerHTML = ''
  vi.mocked(ambilBerkas).mockReset()
  vi.mocked(simpanBerkas).mockReset()
})
enableAutoUnmount(afterEach)

describe('SlipGajiPage — keadaan legacy', () => {
  it('tanpa periode: pesan "Tidak ada periode…", tanpa pemilih bulan', async () => {
    daftar([], null)
    await mountPage()
    expect(testid('slip-kosong')?.textContent).toContain('Tidak ada periode slip gaji yang tersedia saat ini.')
    expect(testid('slip-periode')).toBeNull()
  })

  it('data belum tersedia (ada_data "0" string)', async () => {
    daftar([ITEM({ ada_data: '0' })])
    await mountPage()
    expect(testid('slip-belum-tersedia')?.textContent).toContain('Data Belum Tersedia')
    expect(testid('slip-lihat')).toBeNull()
  })

  it('sudah dibuka (kunjungan berikutnya): waktu WIB dari server, tanpa nominal, tanpa Cetak PDF', async () => {
    daftar([ITEM({ dibuka: '1', viewed_at: '2026-10-04T03:00:00Z', viewed_at_wib: '4 Oktober 2026, 10:00 WIB' })])
    await mountPage()
    expect(testid('slip-sudah-dibuka')?.textContent).toContain('Slip Gaji Sudah Dibuka')
    expect(testid('slip-waktu-dibuka')?.textContent).toContain('4 Oktober 2026, 10:00 WIB')
    expect(testid('slip-cetak-pdf')).toBeNull()
    expect(testid('slip-kartu')).toBeNull()
    expect(document.body.textContent).not.toContain('Rp.')
    expect(post).not.toHaveBeenCalled()
  })

  it('periode bawaan = periode_terbaru dari server', async () => {
    daftar([ITEM({ periode: '2026-08', label: 'Agustus 2026' }), ITEM({ periode: '2026-09', label: 'September 2026', ada_data: false })], '2026-09')
    await mountPage()
    expect(testid('slip-belum-tersedia')?.textContent).toContain('September 2026')
  })
})

describe('SlipGajiPage — buka sekali & cetak', () => {
  it('Lihat Slip Gaji → modal; Lanjutkan nonaktif sampai centang; buka → kartu + total + Cetak PDF', async () => {
    daftar([ITEM()])
    post.mockImplementation(() => ok(BUKA) as never)
    await mountPage()

    expect(testid('slip-cetak-pdf')).toBeNull()
    ;(testid('slip-lihat') as HTMLButtonElement).click()
    await flushPromises()

    expect(testid('buka-slip-dialog')?.textContent).toContain('Slip gaji hanya dapat dibuka satu kali')
    const lanjut = testid('buka-slip-lanjutkan') as HTMLButtonElement
    expect(lanjut.disabled).toBe(true)

    const centang = $('[data-testid="buka-slip-setuju"] input[type="checkbox"]') as HTMLInputElement
    centang.click()
    await flushPromises()
    expect(lanjut.disabled).toBe(false)

    lanjut.click()
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/slip-gaji/saya/2026-09/buka')
    expect(testid('buka-slip-dialog')).toBeNull()
    expect(testid('slip-identitas')?.textContent).toContain('Dr. Budi Santoso, M.Si.')
    expect(testid('slip-gjpokok')?.textContent).toContain('3.000.000')
    expect(testid('slip-potpfkbul')?.textContent).toContain('- 100.000')
    expect(testid('slip-total')?.textContent).toContain('5.100.000')
    expect(testid('slip-ringkasan')?.textContent).toContain('Total Penghasilan September 2026')

    vi.mocked(ambilBerkas).mockResolvedValue({ blob: new Blob(['%PDF-']), nama: 'slip.pdf' })
    ;(testid('slip-cetak-pdf') as HTMLButtonElement).click()
    await flushPromises()
    expect(ambilBerkas).toHaveBeenCalledWith('/slip-gaji/saya/2026-09/pdf', 'slip_gaji_2026-09')
    expect(simpanBerkas).toHaveBeenCalledWith({ blob: expect.any(Blob), nama: 'slip.pdf' })
  })

  it('nominal tidak disimpan di storage browser', async () => {
    daftar([ITEM()])
    post.mockImplementation(() => ok(BUKA) as never)
    const setItem = vi.spyOn(Storage.prototype, 'setItem')
    await mountPage()
    ;(testid('slip-lihat') as HTMLButtonElement).click()
    await flushPromises()
    ;($('[data-testid="buka-slip-setuju"] input[type="checkbox"]') as HTMLInputElement).click()
    await flushPromises()
    ;(testid('buka-slip-lanjutkan') as HTMLButtonElement).click()
    await flushPromises()
    for (const [, nilai] of setItem.mock.calls) expect(String(nilai)).not.toContain('5100000')
    setItem.mockRestore()
  })

  it('422 saat buka (mis. sudah dibuka di perangkat lain): modal ditutup, pesan server tampil, status disegarkan', async () => {
    let panggil = 0
    get.mockImplementation(() => {
      panggil += 1
      const dibuka = panggil > 1
      return ok({ items: [ITEM({ dibuka, viewed_at_wib: dibuka ? '27 Oktober 2026, 08:00 WIB' : null })], periode_terbaru: '2026-09' }) as never
    })
    post.mockImplementation(() => gagal(422, 'Slip gaji periode September 2026 sudah dibuka.') as never)
    await mountPage()

    ;(testid('slip-lihat') as HTMLButtonElement).click()
    await flushPromises()
    ;($('[data-testid="buka-slip-setuju"] input[type="checkbox"]') as HTMLInputElement).click()
    await flushPromises()
    ;(testid('buka-slip-lanjutkan') as HTMLButtonElement).click()
    await flushPromises()

    expect(testid('buka-slip-dialog')).toBeNull()
    expect(testid('slip-failure')?.textContent).toContain('sudah dibuka')
    expect(testid('slip-sudah-dibuka')?.textContent).toContain('27 Oktober 2026, 08:00 WIB')
  })

  it('galat jaringan saat buka tetap di modal dengan pesan baku', async () => {
    daftar([ITEM()])
    post.mockImplementation(() => Promise.reject({ status: null, message: 'x', errors: null, isNetworkError: true, original: {} }) as never)
    await mountPage()
    ;(testid('slip-lihat') as HTMLButtonElement).click()
    await flushPromises()
    ;($('[data-testid="buka-slip-setuju"] input[type="checkbox"]') as HTMLInputElement).click()
    await flushPromises()
    ;(testid('buka-slip-lanjutkan') as HTMLButtonElement).click()
    await flushPromises()
    expect(testid('buka-slip-error')?.textContent).toContain('Tidak dapat terhubung ke server')
    expect(testid('slip-kartu')).toBeNull()
  })

  it('403 saat memuat daftar → pesan "tidak berhak"', async () => {
    get.mockImplementation(() => gagal(403) as never)
    await mountPage()
    expect(testid('slip-failure')?.textContent).toContain('Anda tidak berhak')
  })
})
