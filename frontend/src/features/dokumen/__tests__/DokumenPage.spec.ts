/**
 * DokumenPage F-09 (MAKE-037): daftar dari `GET dokumen/{jenis}` + pemilih lingkup dari `statistik/lingkup`, unduh
 * PDF/XLSX, fallback PDF F7-15 (tombol PDF nonaktif + alasan saat lingkup kementerian / di atas 1.700 baris, pesan 422
 * server dari body Blob tampil apa adanya), filter Pensiun, Baperjakat (centang lintas halaman + judul → POST), aksi
 * baris "Lihat DRH" lewat menu ⋮, galat daftar sebagai notice.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v,
}))
vi.mock('@/features/kepegawaian/services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), kirimBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { Role } from '@/features/auth/types'
import { ambilBerkas, kirimBerkas, simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import type { LingkupTersedia } from '@/features/laporan/types'
import { api } from '@/lib/axios'
import { selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { paramsDokumen, pesanGalatUnduhan } from '../dokumen.service'
import type { DokumenData, DokumenJenis } from '../types'
import DokumenPage from '../views/DokumenPage.vue'

const get = vi.mocked(api.get)
const stub = { render: () => h('div') }
const berkas = { blob: new Blob(['x']), nama: 'berkas.pdf' }

const LINGKUP: LingkupTersedia = {
  kementerian: { nama: 'Kementerian Uji' },
  unit: [{ id: 3, nama: 'Unit A', satker: [{ id: 31, nama: 'Satker A1' }] }],
}

const KOLOM = { nama: 'NAMA', nip: 'NIP', gol_ruang: 'KP (GOL)', tmtsk_pangkat: 'KP (TMT)', jabatan: 'JABATAN', satker: 'SATUAN KERJA', tmtsk_cpns: 'TMT CPNS', tgl_lahir: 'TGL LAHIR', nip_lama: 'NIP LAMA' }

function dokumen(jenis: DokumenJenis, opsi: { total?: number; lingkup?: DokumenData['lingkup'] } = {}): DokumenData {
  return {
    jenis,
    judul: `Judul ${jenis}`,
    lingkup: opsi.lingkup ?? { tipe: 'kementerian', id: null, nama: 'Kementerian Uji' },
    parameter: jenis === 'pensiun' ? { tahun: 2026, sampai: 2026 } : [],
    kolom: KOLOM,
    items: [
      { no: 1, nama: 'Citra, S.H.', nip: '196805152000032001', gol_ruang: 'IV/a', jabatan: 'Kepala Biro', satker: 'Satker A1', nip_lama: '' },
      { no: 2, nama: 'Budi', nip: '198501012010011002', gol_ruang: 'III/a', jabatan: 'Analis', satker: 'Satker A1', nip_lama: '' },
    ],
    // Angka sebagai string meniru MySQLi.
    total: String(opsi.total ?? 2) as unknown as number,
    page: 1,
    per_page: 20,
    periode: '2026-10-21',
  }
}

function envelope<T>(data: T) {
  return Promise.resolve({ data: { status: 'success', data }, headers: {} })
}

/** Galat unduhan seperti dari interceptor: body JSON datang sebagai Blob (responseType blob). */
function galatBlob(status: number, body: unknown) {
  return { status, message: `HTTP ${status}`, errors: null, isNetworkError: false, original: { response: { status, data: new Blob([JSON.stringify(body)], { type: 'application/json' }) } } }
}

function pasangApi(opsi: { total?: number; gagal?: number } = {}): void {
  get.mockReset()
  get.mockImplementation((url: string, config?: { params?: unknown }) => {
    if (url === '/statistik/lingkup') return envelope(LINGKUP) as never
    if (opsi.gagal) return Promise.reject({ status: opsi.gagal, message: 'x', errors: null, isNetworkError: false, original: {} }) as never
    const jenis = url.replace('/dokumen/', '') as DokumenJenis
    const p = (config?.params ?? {}) as Record<string, string>
    const lingkup = p.lingkup === 'satker' ? { tipe: 'satker' as const, id: Number(p.id), nama: `Satker ${p.id}` } : undefined
    return envelope(dokumen(jenis, { total: opsi.total, lingkup })) as never
  })
}

async function mountPage(jenis: DokumenJenis, role: number = Role.SUPER_ADMIN) {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/dokumen/:jenis', name: 'dokumen', component: stub })
  await router.push(`/dokumen/${jenis}`)
  await router.isReady()
  const wrapper = mount(DokumenPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

/** Pilih opsi UiSelect lingkup lewat elemen native di dalamnya. */
async function pilihLingkup(wrapper: Awaited<ReturnType<typeof mountPage>>, nilai: string): Promise<void> {
  const select = wrapper.get('[data-testid="dokumen-lingkup"] select')
  await select.setValue(nilai)
  await flushPromises()
}

const panggilDaftar = (jenis: DokumenJenis) => get.mock.calls.filter(([url]) => url === `/dokumen/${jenis}`)

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(ambilBerkas).mockReset().mockResolvedValue(berkas)
  vi.mocked(kirimBerkas).mockReset().mockResolvedValue(berkas)
  vi.mocked(simpanBerkas).mockReset()
  vi.mocked(api.post).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('DokumenPage — daftar & unduh (MAKE-037)', () => {
  it('memuat DUK: lingkup + daftar halaman 1, kolom inti berlabel server, baris tabel, unduh XLSX seluruh kementerian', async () => {
    pasangApi()
    const wrapper = await mountPage('duk')

    expect(get).toHaveBeenCalledWith('/statistik/lingkup')
    expect(panggilDaftar('duk')[0]?.[1]).toEqual({ params: { page: '1', per_page: '20' } })
    expect(wrapper.get('[data-testid="dokumen-page"]').text()).toContain('Judul duk')
    const header = wrapper.get('[data-testid="dokumen-table"] thead').text()
    expect(header).toContain('KP (GOL)')
    expect(header).not.toContain('NIP LAMA') // kolom lengkap hanya di XLSX
    expect(wrapper.get('[data-testid="dokumen-row-196805152000032001"]').text()).toContain('Citra, S.H.')

    await wrapper.get('[data-testid="dokumen-unduh-xlsx"]').trigger('click')
    await flushPromises()
    expect(ambilBerkas).toHaveBeenCalledWith('/dokumen/duk/xlsx', 'dokumen_duk', {})
    expect(simpanBerkas).toHaveBeenCalledWith(berkas)
  })

  it('fallback F7-15: PDF nonaktif + alasan pada lingkup kementerian; pilih satker → PDF aktif dan dikirim dengan lingkup', async () => {
    pasangApi()
    const wrapper = await mountPage('duk')

    expect(wrapper.get('[data-testid="dokumen-unduh-pdf"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="dokumen-pdf-alasan"]').text()).toContain('PDF hanya tersedia per unit/satker')

    await pilihLingkup(wrapper, 'satker:31')
    expect(panggilDaftar('duk').at(-1)?.[1]).toEqual({ params: { lingkup: 'satker', id: '31', page: '1', per_page: '20' } })
    expect(wrapper.find('[data-testid="dokumen-pdf-alasan"]').exists()).toBe(false)

    await wrapper.get('[data-testid="dokumen-unduh-pdf"]').trigger('click')
    await flushPromises()
    expect(ambilBerkas).toHaveBeenCalledWith('/dokumen/duk/pdf', 'dokumen_duk', { lingkup: 'satker', id: '31' })
  })

  it('di atas 1.700 baris PDF nonaktif dengan saran XLSX; pesan 422 server (body Blob) tampil apa adanya', async () => {
    pasangApi({ total: 1701 })
    const wrapper = await mountPage('nominatif')
    await pilihLingkup(wrapper, 'unit:3')

    expect(wrapper.get('[data-testid="dokumen-unduh-pdf"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="dokumen-pdf-alasan"]').text()).toContain('Persempit lingkup atau unduh XLSX')

    // Data berubah di server sesudah daftar dimuat: 422 tetap terbaca.
    pasangApi({ total: 2 })
    await pilihLingkup(wrapper, 'satker:31')
    vi.mocked(ambilBerkas).mockRejectedValueOnce(galatBlob(422, { status: 'error', message: 'Validasi gagal.', errors: { lingkup: ['Persempit lingkup atau unduh XLSX.'] } }))
    await wrapper.get('[data-testid="dokumen-unduh-pdf"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="dokumen-unduh-galat"]').text()).toBe('Persempit lingkup atau unduh XLSX.')
    expect(simpanBerkas).not.toHaveBeenCalled()
  })

  it('Pensiun: tahun/sampai dikirim ke daftar (tertunda) dan ke unduhan', async () => {
    pasangApi()
    const wrapper = await mountPage('pensiun')
    vi.useFakeTimers()

    await wrapper.get('[data-testid="dokumen-rentang"] input[name="tahun"]').setValue('2027')
    await wrapper.get('[data-testid="dokumen-rentang"] input[name="sampai"]').setValue('2029')
    await vi.advanceTimersByTimeAsync(350)
    vi.useRealTimers()
    await flushPromises()
    expect(panggilDaftar('pensiun').at(-1)?.[1]).toEqual({ params: { page: '1', per_page: '20', tahun: '2027', sampai: '2029' } })

    await wrapper.get('[data-testid="dokumen-unduh-xlsx"]').trigger('click')
    await flushPromises()
    expect(ambilBerkas).toHaveBeenCalledWith('/dokumen/pensiun/xlsx', 'dokumen_pensiun', { tahun: '2027', sampai: '2029' })
  })

  it('aksi baris ⋮ "Lihat DRH" mengunduh DRH pegawai', async () => {
    pasangApi()
    const wrapper = await mountPage('duk')
    expect(wrapper.get('[data-testid="dokumen-actions-198501012010011002"]').attributes('aria-label')).toBe('Aksi untuk Budi')

    await selectRowAction(wrapper, 'dokumen-actions-198501012010011002', 'drh')
    await flushPromises()
    expect(ambilBerkas).toHaveBeenCalledWith('/pegawai/198501012010011002/drh', 'drh_198501012010011002')
    expect(simpanBerkas).toHaveBeenCalledWith(berkas)
  })

  it('galat daftar (403) tampil sebagai notice', async () => {
    pasangApi({ gagal: 403 })
    const wrapper = await mountPage('duk')
    expect(wrapper.get('[data-testid="dokumen-failure"]').text()).toBe('Anda tidak berhak mengakses data ini.')
  })
})

describe('DokumenPage — Baperjakat (MAKE-037)', () => {
  it('centang baris + judul → POST nip[] terpilih; tanpa judul / tanpa pilihan ditolak di klien', async () => {
    pasangApi()
    const wrapper = await mountPage('baperjakat')

    await wrapper.get('[data-testid="dokumen-unduh-xlsx"]').trigger('click')
    await flushPromises()
    expect(kirimBerkas).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="dokumen-bap-nip-galat"]').text()).toBe('Pilih minimal satu pegawai.')
    expect(wrapper.get('[data-testid="dokumen-baperjakat"]').text()).toContain('Judul wajib diisi.')

    await wrapper.get('[data-testid="dokumen-pilih-halaman"] input').setValue(true)
    await wrapper.get('[data-testid="dokumen-pilih-196805152000032001"] input').setValue(false)
    await wrapper.get('[data-testid="dokumen-baperjakat"] input[name="judul"]').setValue('  Rapat Baperjakat  ')
    await wrapper.get('[data-testid="dokumen-bap-teks"]').setValue('Catatan')
    expect(wrapper.get('[data-testid="dokumen-baperjakat"]').text()).toContain('1 pegawai dipilih')

    await wrapper.get('[data-testid="dokumen-unduh-xlsx"]').trigger('click')
    await flushPromises()
    expect(kirimBerkas).toHaveBeenCalledWith('/dokumen/baperjakat/xlsx', { nip: ['198501012010011002'], judul: 'Rapat Baperjakat', teks: 'Catatan' }, 'dokumen_baperjakat')
  })

  it('pilihan bertahan lintas halaman; 422 server errors.nip tampil; PDF tidak terkunci lingkup', async () => {
    pasangApi({ total: 40 })
    const wrapper = await mountPage('baperjakat')
    expect(wrapper.find('[data-testid="dokumen-pdf-alasan"]').exists()).toBe(false)

    await wrapper.get('[data-testid="dokumen-pilih-198501012010011002"] input').setValue(true)
    await wrapper.get('[data-testid="dokumen-baperjakat"] input[name="judul"]').setValue('Baperjakat')
    // Pindah halaman: daftar dimuat ulang, pilihan tetap.
    await wrapper.findAll('button').find((b) => b.text() === '2')?.trigger('click')
    await flushPromises()
    expect(panggilDaftar('baperjakat').at(-1)?.[1]).toEqual({ params: { page: '2', per_page: '20' } })
    expect(wrapper.get('[data-testid="dokumen-baperjakat"]').text()).toContain('1 pegawai dipilih')

    vi.mocked(kirimBerkas).mockRejectedValueOnce(galatBlob(422, { status: 'error', message: 'Validasi gagal.', errors: { nip: ['NIP tidak ditemukan, nonaktif, atau di luar lingkup Anda: 198501012010011002.'] } }))
    await wrapper.get('[data-testid="dokumen-unduh-pdf"]').trigger('click')
    await flushPromises()
    expect(kirimBerkas).toHaveBeenCalledWith('/dokumen/baperjakat/pdf', { nip: ['198501012010011002'], judul: 'Baperjakat' }, 'dokumen_baperjakat')
    expect(wrapper.get('[data-testid="dokumen-unduh-galat"]').text()).toContain('di luar lingkup Anda')
  })
})

describe('dokumen.service', () => {
  it('paramsDokumen: lingkup dari pemilih, nilai kosong tidak dikirim', () => {
    expect(paramsDokumen('', { search: '  ', page: 1 })).toEqual({ page: '1' })
    expect(paramsDokumen('unit:3', { search: ' Budi ', tahun: '' })).toEqual({ lingkup: 'unit', id: '3', search: 'Budi' })
    expect(paramsDokumen('kementerian')).toEqual({ lingkup: 'kementerian' })
  })

  it('pesanGalatUnduhan: errors.* dari body Blob, lalu message, lalu pesan baku (429, bukan JSON)', async () => {
    expect(await pesanGalatUnduhan(galatBlob(422, { message: 'Validasi gagal.', errors: { lingkup: ['PDF hanya tersedia per unit/satker.'] } }))).toBe('PDF hanya tersedia per unit/satker.')
    expect(await pesanGalatUnduhan(galatBlob(404, { message: 'Jenis dokumen tidak dikenal.' }))).toBe('Jenis dokumen tidak dikenal.')
    const bukanJson = { status: 429, message: 'x', errors: null, isNetworkError: false, original: { response: { data: new Blob(['<html>']) } } }
    expect(await pesanGalatUnduhan(bukanJson)).toBe('Terlalu banyak unduhan dokumen. Coba lagi dalam satu menit.')
    expect(await pesanGalatUnduhan(new Error('x'))).toBe('Terjadi kesalahan. Coba lagi.')
  })
})
