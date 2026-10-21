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

function envelope<T>(data: T) {
  return Promise.resolve({ data: { status: 'success', data }, headers: {} })
}

function pasangApi(opsi: { gagal?: number } = {}): void {
  get.mockReset()
  get.mockImplementation((url: string, config?: { params?: unknown }) => {
    if (url === '/statistik/lingkup') return envelope(LINGKUP) as never
    if (opsi.gagal) {
      return Promise.reject({ status: opsi.gagal, message: 'x', errors: null, isNetworkError: false, original: {} }) as never
    }
    const jenis = url.replace('/statistik/', '') as StatistikData['jenis']
    const p = (config?.params ?? {}) as Record<string, string>
    const lingkup = p.lingkup === 'satker' ? { tipe: 'satker' as const, id: Number(p.id), nama: `Satker ${p.id}` } : undefined
    return envelope(statistik(jenis, lingkup)) as never
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
