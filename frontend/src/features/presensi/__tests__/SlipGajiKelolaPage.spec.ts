/**
 * SlipGajiKelolaPage — tab Monitoring (D-11 jalur admin, AC-FE.4): periode bawaan = periode terbaru tersedia [K
 * `Sl_gaji.php:219-221`]; filter satker hanya role 1; menu ⋮ AGENTS §1 (Edit → Lihat detail → Cetak PDF → Buka ulang),
 * "Buka ulang" `hidden` bila belum dibuka (dari `aksi` server) + `ConfirmDialog`; rincian admin memuat seluruh kolom
 * TK termasuk Pajak; koreksi 422 per kolom. Payload memakai angka/boolean string (MySQLi).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v,
}))
vi.mock('@/features/kepegawaian/services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { Role, type RoleCode } from '@/features/auth/types'
import { ambilBerkas, simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { api } from '@/lib/axios'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import SlipGajiKelolaPage from '../views/SlipGajiKelolaPage.vue'

const get = vi.mocked(api.get)
const post = vi.mocked(api.post)
const put = vi.mocked(api.put)

const ok = <T>(data: T) => Promise.resolve({ data, headers: {} })
const gagal = (status: number, message = 'x', errors: Record<string, string[]> | null = null) =>
  Promise.reject({ status, message, errors, isNetworkError: false, original: {} })

const ROW = (o: Record<string, unknown> = {}) => ({
  id: '11', nip: '199001012020011001', nama: 'Budi Santoso', unit_satker: 'Biro Uji - Satker Uji', periode: '2026-09',
  dibuka: '0', viewed_at: null, viewed_at_wib: null, viewed_ip: null,
  aksi: { lihat: true, cetak: true, koreksi: true, buka_ulang: false },
  ...o,
})
const DIBUKA = ROW({ id: '12', nama: 'Ani', dibuka: '1', viewed_at: '2026-10-04T03:00:00Z', viewed_at_wib: '4 Oktober 2026, 10:00 WIB', viewed_ip: '10.0.0.9', aksi: { lihat: true, cetak: true, koreksi: true, buka_ulang: true } })

const DAFTAR = (items = [ROW(), DIBUKA], tersedia = ['2026-09', '2026-08']) => ({
  items, total: String(items.length), page: '1', per_page: '20',
  ringkasan: { jumlah: String(items.length), dibuka: '1', belum_dibuka: String(items.length - 1) },
  periode_tersedia: tersedia,
})

const DETAIL = {
  id: '11', nip: '199001012020011001', nama: 'Budi Santoso', periode: '2026-09', label: 'September 2026', dibuka: false,
  viewed_at: null, viewed_at_wib: null, viewed_ip: null,
  rincian: { gjpokok: '3000000.00', kotor: '2000000.00', pajak: '70000.00', tunj_pajak: '90000.00' },
  ringkasan: { jumlah_penghasilan_gpp: '3000000.00', jumlah_potongan_gpp: '0.00', bersih_gpp: '3000000.00', bersih_tk: '1900000.00', bersih_2: '0.00', uang_makan: '500000.00', total_penghasilan: '5400000.00' },
  uang_makan: { nominal: '500000.00', hari: '20', periode_kehadiran: '2026-08', tersedia: true },
  aksi: { lihat: true, cetak: true, koreksi: true, buka_ulang: false },
  pegawai: { nip: '199001012020011001', nama: 'Budi Santoso, S.E.', pangkat: 'Penata / III/c', jabatan: 'Analis', unit: 'Biro Uji', satker: 'Satker Uji' },
}

let periodeDiminta: string[] = []

function server(daftar = DAFTAR()): void {
  get.mockImplementation(((url: string, cfg?: { params?: Record<string, string> }) => {
    if (url === '/slip-gaji') {
      periodeDiminta.push(String(cfg?.params?.periode))
      return ok(daftar)
    }
    if (url === '/slip-gaji/11') return ok(DETAIL)
    if (url === '/master/satker/options') return ok([{ id: 7, nama: 'Satker Uji', parent: '1' }])
    return gagal(404)
  }) as never)
}

async function mountPage(role: RoleCode = Role.SUPER_ADMIN) {
  loginAs(role)
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/slip-gaji/kelola', name: 'presensi-slip-gaji-kelola', component: { render: () => null } })
  await router.push('/presensi/slip-gaji/kelola')
  await router.isReady()
  const wrapper = mount(SlipGajiKelolaPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  await flushPromises()
  return wrapper
}

const testid = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`)
const tombol = (teks: string) => Array.from(document.body.querySelectorAll<HTMLButtonElement>('button')).find((b) => b.textContent?.trim() === teks)

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  periodeDiminta = []
})
afterEach(() => {
  document.body.innerHTML = ''
  vi.mocked(ambilBerkas).mockReset()
  vi.mocked(simpanBerkas).mockReset()
})
enableAutoUnmount(afterEach)

describe('SlipGajiKelolaPage — Monitoring', () => {
  it('periode bawaan pindah ke periode terbaru yang tersedia; ringkasan & badge status dari server', async () => {
    server(DAFTAR(undefined, ['2020-01']))
    await mountPage()
    expect(periodeDiminta.length).toBe(2)
    expect(periodeDiminta[1]).toBe('2020-01')
    expect(testid('slipgaji-ringkasan')?.textContent).toMatch(/2\s*Total Data/)
    expect(testid('slipgaji-status-11')?.textContent).toContain('Belum Dibuka')
    expect(testid('slipgaji-status-12')?.textContent).toContain('Sudah Dibuka')
    expect(testid('slipgaji-row-12')?.textContent).toContain('4 Oktober 2026, 10:00 WIB')
  })

  it('filter satker hanya untuk role 1; role 3 tanpa filter & tanpa memuat opsi satker', async () => {
    server()
    const satker = await mountPage(Role.ADMIN_SATKER)
    expect(testid('slipgaji-satker')).toBeNull()
    expect(get.mock.calls.some(([url]) => url === '/master/satker/options')).toBe(false)
    satker.unmount()

    server()
    await mountPage(Role.SUPER_ADMIN)
    expect(testid('slipgaji-satker')).not.toBeNull()
  })

  it('menu ⋮ urut Edit → Lihat detail → Cetak PDF → Buka ulang; Buka ulang tersembunyi bila belum dibuka', async () => {
    server()
    const wrapper = await mountPage()
    expect((await rowMenuActions(wrapper, 'slipgaji-actions-11')).map((a) => a.label)).toEqual(['Edit', 'Lihat detail', 'Cetak PDF'])
    expect((await rowMenuActions(wrapper, 'slipgaji-actions-12')).map((a) => a.label)).toEqual(['Edit', 'Lihat detail', 'Cetak PDF', 'Buka ulang'])
    expect(document.body.querySelector('[aria-label="Aksi untuk Ani"]')).not.toBeNull()
  })

  it('Buka ulang lewat ConfirmDialog → POST buka-ulang lalu daftar dimuat ulang', async () => {
    server()
    post.mockImplementation(() => ok(null) as never)
    const wrapper = await mountPage()
    const sebelum = periodeDiminta.length

    await selectRowAction(wrapper, 'slipgaji-actions-12', 'buka-ulang')
    expect(document.body.textContent).toContain('Pegawai akan bisa mengakses slip gajinya 1x lagi')
    expect(post).not.toHaveBeenCalled()

    tombol('Ya, Buka Akses')?.click()
    await flushPromises()
    expect(post).toHaveBeenCalledWith('/slip-gaji/12/buka-ulang')
    expect(testid('slipgaji-notice')?.textContent).toContain('dibuka kembali')
    expect(periodeDiminta.length).toBe(sebelum + 1)
  })

  it('Lihat detail: identitas + seluruh kolom TK (termasuk Pajak) + total; tidak mengubah status (hanya GET)', async () => {
    server()
    const wrapper = await mountPage()
    await selectRowAction(wrapper, 'slipgaji-actions-11', 'lihat')
    await flushPromises()

    expect(testid('slip-detail-identitas')?.textContent).toContain('Budi Santoso, S.E.')
    expect(testid('slip-detail-identitas')?.textContent).toContain('Penata / III/c')
    expect(testid('slip-detail-pajak')?.textContent).toContain('70.000')
    expect(testid('slip-detail-tunj_pajak')?.textContent).toContain('90.000')
    expect(testid('slip-detail-uang-makan')?.textContent).toContain('Agustus 2026, 20 hari')
    expect(testid('slip-detail-total')?.textContent).toContain('5.400.000')
    expect(post).not.toHaveBeenCalled()
    expect(put).not.toHaveBeenCalled()

    vi.mocked(ambilBerkas).mockResolvedValue({ blob: new Blob(['%PDF-']), nama: 'slip.pdf' })
    testid('slip-detail-cetak')?.click()
    await flushPromises()
    expect(ambilBerkas).toHaveBeenCalledWith('/slip-gaji/11/pdf', 'slip_gaji_199001012020011001_2026-09')
    expect(simpanBerkas).toHaveBeenCalled()
  })

  it('Edit: 28 kolom terisi dari server, 422 ditampilkan per kolom, tanpa menutup dialog', async () => {
    server()
    put.mockImplementation(() => gagal(422, 'Validasi gagal.', { gjpokok: ['Nilai harus berupa angka non-negatif.'] }) as never)
    const wrapper = await mountPage()
    await selectRowAction(wrapper, 'slipgaji-actions-11', 'edit')
    await flushPromises()

    const input = (k: string) => document.body.querySelector<HTMLInputElement>(`[data-testid="slip-koreksi-${k}"] input, input[data-testid="slip-koreksi-${k}"]`)
    expect(document.body.querySelectorAll('[data-testid="slip-koreksi-form"] input').length).toBe(28)
    expect(input('gjpokok')?.value).toBe('3000000.00')
    expect(input('potlain')?.value).toBe('0.00')

    testid('slip-koreksi-simpan')?.click()
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/slip-gaji/11', expect.objectContaining({ gjpokok: '3000000.00', pajak: '70000.00' }))
    expect(testid('slip-koreksi-dialog')).not.toBeNull()
    expect(testid('slip-koreksi-form')?.textContent).toContain('Nilai harus berupa angka non-negatif.')
  })

  it('403 saat memuat → pesan "tidak berhak"', async () => {
    get.mockImplementation(() => gagal(403) as never)
    await mountPage(Role.ADMIN_SATKER)
    expect(testid('slipgaji-failure')?.textContent).toContain('Anda tidak berhak')
  })

  it('periode tanpa data → ajakan impor', async () => {
    server(DAFTAR([], ['2026-09']))
    await mountPage()
    expect(testid('slipgaji-kosong')?.textContent).toContain('belum tersedia')
  })
})
