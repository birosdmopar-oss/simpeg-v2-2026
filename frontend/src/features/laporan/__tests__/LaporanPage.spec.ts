/**
 * LaporanPage F-03 (MAKE-036): data dari `GET statistik/{jenis}` + pemilih lingkup dari `statistik/lingkup`, tabel +
 * Subtotal server, pencarian baris, ekspor Excel ke `statistik/{jenis}/xlsx`, galat 403 tampil sebagai notice.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@/lib/axios', () => ({ api: { get: vi.fn() }, isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v }))
vi.mock('@/features/kepegawaian/services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { Role } from '@/features/auth/types'
import { ambilBerkas, simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { api } from '@/lib/axios'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { isLaporanTipe, lingkupKeQuery } from '../laporan.service'
import type { LingkupTersedia, StatistikData } from '../types'
import LaporanPage from '../views/LaporanPage.vue'

const get = vi.mocked(api.get)
const stub = { render: () => h('div') }

const LINGKUP: LingkupTersedia = {
  kementerian: { nama: 'Kementerian Uji' },
  unit: [
    { id: 3, nama: 'Unit A', satker: [{ id: 31, nama: 'Satker A1' }, { id: 32, nama: 'Satker A2' }] },
    { id: 4, nama: 'Unit B', satker: [] },
  ],
}

const statistik = (jenis: StatistikData['jenis'], lingkup: StatistikData['lingkup'] = { tipe: 'kementerian', id: null, nama: 'Kementerian Uji' }): StatistikData => ({
  jenis,
  judul: `Formasi ${jenis}`,
  lingkup,
  label_kategori: lingkup.tipe === 'kementerian' ? 'Unit Kerja Eselon I' : 'Satuan Kerja',
  kategori: [{ id: '3', label: 'Unit A' }, { id: '4', label: 'Unit B' }],
  seri: [{ key: 'pns', label: 'PNS' }, { key: 'pppk', label: 'PPPK' }],
  // Angka sebagai string meniru MySQLi.
  baris: [
    { kategori_id: '3', label: 'Unit A', nilai: ['1200' as unknown as number, 300 as number], total: '1500' as unknown as number },
    { kategori_id: '4', label: 'Unit B', nilai: [626, 10], total: 636 },
  ],
  subtotal: ['1826' as unknown as number, 310],
  total: 2136,
  periode: '2026-10-21',
  cache: { hit: false, ttl: 900 },
})

/** Bentuk setelah interceptor `@/lib/axios` membuka amplop {status, data}: `response.data` = payload (CR-059). */
function envelope<T>(data: T) {
  return Promise.resolve({ data, headers: {} })
}

function pasangApi(opsi: { gagal?: number; lingkup?: LingkupTersedia; kosong?: boolean } = {}): void {
  get.mockReset()
  get.mockImplementation((url: string, config?: { params?: unknown }) => {
    if (url === '/statistik/lingkup') return envelope(opsi.lingkup ?? LINGKUP) as never
    if (opsi.gagal) {
      return Promise.reject({ status: opsi.gagal, message: 'x', errors: null, isNetworkError: false, original: {} }) as never
    }
    const jenis = url.replace('/statistik/', '') as StatistikData['jenis']
    const p = (config?.params ?? {}) as Record<string, string>
    const lingkup = p.lingkup === 'satker' ? { tipe: 'satker' as const, id: Number(p.id), nama: `Satker ${p.id}` } : undefined
    const data = statistik(jenis, lingkup)
    if (opsi.kosong) Object.assign(data, { baris: [], kategori: [], subtotal: [0, 0], total: 0 })
    return envelope(data) as never
  })
}

async function mountPage(tipe: string) {
  loginAs(Role.SUPER_ADMIN)
  const router = createShellRouter()
  router.addRoute({ path: '/laporan/:tipe', name: 'laporan', component: stub })
  await router.push(`/laporan/${tipe}`)
  await router.isReady()
  const wrapper = mount(LaporanPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => pasangApi())
afterEach(() => {
  document.body.innerHTML = ''
  vi.mocked(ambilBerkas).mockReset()
  vi.mocked(simpanBerkas).mockReset()
})
enableAutoUnmount(afterEach)

const settle = () => new Promise((r) => setTimeout(r, 40))

describe('LaporanPage', () => {
  it('memuat lingkup + statistik unit-kerja: baris, Subtotal server (1.826 / 310 / 2.136), judul dari API', async () => {
    const { wrapper } = await mountPage('unit-kerja')
    expect(get).toHaveBeenCalledWith('/statistik/lingkup')
    expect(get).toHaveBeenCalledWith('/statistik/unit-kerja', { params: {} })
    expect(wrapper.text()).toContain('Formasi unit-kerja')
    expect(wrapper.findAll('[data-testid^="laporan-row-"]')).toHaveLength(2)
    expect(wrapper.get('[data-testid="laporan-row-3"]').text()).toContain('1.500')
    const sub = wrapper.get('[data-testid="laporan-subtotal"]').text()
    expect(sub).toContain('1.826')
    expect(sub).toContain('310')
    expect(sub).toContain('2.136')
    // Pemilih lingkup: kementerian + 2 unit + 2 satker.
    const opsi = [...wrapper.get('[data-testid="laporan-lingkup"]').element.querySelectorAll('option')].map((o) => o.value).filter(Boolean)
    expect(opsi).toEqual(['kementerian', 'unit:3', 'satker:31', 'satker:32', 'unit:4'])
  })

  it('memilih lingkup satker mengirim lingkup+id dan memperbarui kolom kategori', async () => {
    const { wrapper } = await mountPage('usia')
    const select = wrapper.get('[data-testid="laporan-lingkup"] select').element as HTMLSelectElement
    select.value = 'satker:31'
    select.dispatchEvent(new Event('change'))
    await flushPromises()
    expect(get).toHaveBeenCalledWith('/statistik/usia', { params: { lingkup: 'satker', id: '31' } })
    expect(wrapper.get('[data-testid="laporan-table"] thead').text()).toContain('Satuan Kerja')
  })

  it('CR-060: pemilih lingkup = dropdown custom; klik trigger lalu klik opsi (bukan <select> native) memuat ulang statistik', async () => {
    const { wrapper } = await mountPage('usia')
    const trigger = wrapper.get('[data-testid="laporan-lingkup"] [role="combobox"]')
    expect(trigger.element.tagName).toBe('BUTTON')

    await trigger.trigger('click')
    const opsi = [...document.body.querySelectorAll<HTMLElement>('[role="listbox"] [role="option"]')]
    // Opsi pertama = pilihan bawaan server (clearable, nilai kosong).
    expect(opsi.map((o) => o.dataset.value)).toEqual(['', 'kementerian', 'unit:3', 'satker:31', 'satker:32', 'unit:4'])

    opsi.find((o) => o.dataset.value === 'satker:32')?.click()
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/statistik/usia', { params: { lingkup: 'satker', id: '32' } })
    expect(document.body.querySelector('[role="listbox"]')).toBeNull()
  })

  it('pindah tipe lewat route memuat ulang statistik tipe baru', async () => {
    const { router } = await mountPage('jabatan')
    expect(get).toHaveBeenCalledWith('/statistik/jabatan', { params: {} })
    await router.push('/laporan/golongan')
    await flushPromises()
    expect(get).toHaveBeenCalledWith('/statistik/golongan', { params: {} })
  })

  it('pencarian menyaring baris; tanpa hasil menampilkan keadaan kosong', async () => {
    const { wrapper } = await mountPage('unit-kerja')
    const input = wrapper.get('[data-testid="laporan-table"]').element.closest('section')!.querySelector('input')!
    input.value = 'Unit B'
    input.dispatchEvent(new Event('input'))
    await settle()
    await flushPromises()
    expect(wrapper.findAll('[data-testid^="laporan-row-"]')).toHaveLength(1)
    input.value = 'zzz'
    input.dispatchEvent(new Event('input'))
    await settle()
    await flushPromises()
    expect(wrapper.find('[data-testid="laporan-empty"]').exists()).toBe(true)
  })

  it('CR-064 T5: subjudul kartu memakai label kategori apa adanya ("Per Unit Kerja Eselon I", bukan "eselon i")', async () => {
    const { wrapper } = await mountPage('unit-kerja')
    expect(wrapper.text()).toContain('Per Unit Kerja Eselon I')
    expect(wrapper.text()).not.toContain('eselon i')
    expect(wrapper.text()).toContain('Komposisi per Unit Kerja Eselon I')
  })

  it('CR-064 T4: tombol dan item ekspor berbahasa Indonesia ("Ekspor" / "Ekspor Excel")', async () => {
    const { wrapper } = await mountPage('pendidikan')
    expect(wrapper.get('[data-testid="export-trigger"]').text()).toBe('Ekspor')
    await wrapper.get('[data-testid="export-trigger"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-format="xlsx"]')?.textContent?.trim()).toBe('Ekspor Excel')
    expect(wrapper.text()).not.toContain('Export')
    expect(document.body.textContent).not.toContain('Export')
  })

  it('CR-064 T11: baris Subtotal mengikuti pencarian (dijumlah dari baris tampil, berlabel); total keseluruhan lingkup tetap dari server', async () => {
    const { wrapper } = await mountPage('unit-kerja')
    expect(wrapper.find('[data-testid="laporan-total-keseluruhan"]').exists()).toBe(false)

    const input = wrapper.get('[data-testid="laporan-table"]').element.closest('section')!.querySelector('input')!
    input.value = 'Unit B'
    input.dispatchEvent(new Event('input'))
    await settle()
    await flushPromises()

    const sub = wrapper.get('[data-testid="laporan-subtotal"]').text()
    expect(sub).toContain('Subtotal hasil pencarian (1 baris)')
    expect(sub).toContain('626')
    expect(sub).toContain('10')
    expect(sub).toContain('636')
    expect(sub).not.toContain('1.826')
    const semua = wrapper.get('[data-testid="laporan-total-keseluruhan"]').text()
    expect(semua).toContain('Total keseluruhan lingkup')
    expect(semua).toContain('1.826')
    expect(semua).toContain('2.136')

    // Pencarian tanpa hasil: Subtotal 0, bukan angka seluruh lingkup.
    input.value = 'zzz'
    input.dispatchEvent(new Event('input'))
    await settle()
    await flushPromises()
    expect(wrapper.get('[data-testid="laporan-subtotal"]').text()).toContain('Subtotal hasil pencarian (0 baris)')
    expect(wrapper.get('[data-testid="laporan-subtotal"]').text()).not.toContain('1.826')

    // Pencarian dikosongkan → kembali ke Subtotal server.
    input.value = ''
    input.dispatchEvent(new Event('input'))
    await settle()
    await flushPromises()
    expect(wrapper.get('[data-testid="laporan-subtotal"]').text()).toContain('1.826')
    expect(wrapper.find('[data-testid="laporan-total-keseluruhan"]').exists()).toBe(false)
  })

  it('CR-064 T12: lingkup tanpa pegawai menampilkan "Tidak ada data pada lingkup ini" tanpa diagram, donat, dan tabel 0', async () => {
    pasangApi({ kosong: true })
    const { wrapper } = await mountPage('golongan')
    expect(wrapper.get('[data-testid="laporan-kosong"]').text()).toBe('Tidak ada data pada lingkup ini')
    expect(wrapper.find('figure').exists()).toBe(false)
    expect(wrapper.find('svg[role="img"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="laporan-table"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="laporan-subtotal"]').exists()).toBe(false)
    // Judul dan ekspor tetap ada.
    expect(wrapper.get('h1').text()).toContain('Formasi golongan')
  })

  it('CR-064 T6: admin satker tidak ditawari unit pembungkus (403); satker miliknya tetap bisa dipilih', async () => {
    pasangApi({ lingkup: { kementerian: null, unit: [{ id: 3, nama: 'Unit A', pilih_unit: false, satker: [{ id: 31, nama: 'Satker A1' }] }] } })
    const { wrapper } = await mountPage('usia')
    const opsi = [...wrapper.get('[data-testid="laporan-lingkup"]').element.querySelectorAll('option')].map((o) => o.value).filter(Boolean)
    expect(opsi).toEqual(['satker:31'])

    // Admin unit (pilih_unit true) tetap melihat unitnya.
    pasangApi({ lingkup: { kementerian: null, unit: [{ id: 3, nama: 'Unit A', pilih_unit: true, satker: [{ id: 31, nama: 'Satker A1' }] }] } })
    const { wrapper: unit } = await mountPage('usia')
    const opsiUnit = [...unit.get('[data-testid="laporan-lingkup"]').element.querySelectorAll('option')].map((o) => o.value).filter(Boolean)
    expect(opsiUnit).toEqual(['unit:3', 'satker:31'])
  })

  it('ekspor Excel mengunduh statistik/{jenis}/xlsx dengan lingkup aktif', async () => {
    vi.mocked(ambilBerkas).mockResolvedValue({ blob: new Blob(['PK']), nama: 'statistik_pendidikan.xlsx' })
    const { wrapper } = await mountPage('pendidikan')
    await wrapper.get('[data-testid="export-trigger"]').trigger('click')
    await flushPromises()
    const formats = [...document.body.querySelectorAll('[data-format]')].map((n) => n.getAttribute('data-format'))
    expect(formats).toEqual(['xlsx'])
    ;(document.body.querySelector('[data-format="xlsx"]') as HTMLElement).click()
    await flushPromises()
    expect(ambilBerkas).toHaveBeenCalledWith('/statistik/pendidikan/xlsx', 'statistik_pendidikan', {})
    expect(simpanBerkas).toHaveBeenCalledWith({ blob: expect.any(Blob), nama: 'statistik_pendidikan.xlsx' })
  })

  it('galat 403 dari server tampil sebagai notice, tabel tidak dirender', async () => {
    pasangApi({ gagal: 403 })
    const { wrapper } = await mountPage('eselon')
    expect(wrapper.get('[data-testid="laporan-failure"]').text()).toContain('tidak berhak')
    expect(wrapper.find('[data-testid="laporan-table"]').exists()).toBe(false)
  })
})

describe('laporan.service', () => {
  it('lingkupKeQuery memetakan pilihan ke parameter endpoint', () => {
    expect(lingkupKeQuery('')).toEqual({})
    expect(lingkupKeQuery('kementerian')).toEqual({ lingkup: 'kementerian' })
    expect(lingkupKeQuery('unit:3')).toEqual({ lingkup: 'unit', id: '3' })
    expect(lingkupKeQuery('satker:31')).toEqual({ lingkup: 'satker', id: '31' })
  })

  it('isLaporanTipe mengenali 7 tipe', () => {
    for (const t of ['unit-kerja', 'jenis-kelamin', 'jabatan', 'eselon', 'pendidikan', 'usia', 'golongan']) expect(isLaporanTipe(t)).toBe(true)
    expect(isLaporanTipe('struktural')).toBe(false)
  })
})
