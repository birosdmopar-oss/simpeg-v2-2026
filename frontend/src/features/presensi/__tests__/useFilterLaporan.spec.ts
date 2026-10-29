import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter, RouterView, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import FilterLaporanBar from '../components/FilterLaporanBar.vue'
import { bulanBerjalanWib, bulanPeriodeTukinWib, lingkupDariFilter, useFilterLaporan, type OpsiFilterLaporan } from '../composables/useFilterLaporan'

const options = vi.fn()
vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: (...a: unknown[]) => options(...a) } }))

/** Halaman ber-filter dipasang di bawah RouterView seperti di aplikasi (onBeforeRouteUpdate hanya aktif di sana). */
async function pasang(opsi: OpsiFilterLaporan, url = '/x') {
  let api!: ReturnType<typeof useFilterLaporan>
  const halaman = defineComponent({
    setup() {
      api = useFilterLaporan(opsi)
      return () => h('div')
    },
  })
  const r: Router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/x', name: 'x', component: halaman },
      { path: '/y', name: 'y', component: { render: () => null } },
    ],
  })
  await r.push(url)
  const w = mount(defineComponent({ render: () => h(RouterView) }), { global: { plugins: [r] } })
  await flushPromises()
  return { r, api, w }
}

beforeEach(() => {
  options.mockReset()
  options.mockImplementation(async (entitas: string, parent?: string) => {
    if (entitas === 'jenis-pegawai') return [{ id: '1', nama: 'PNS', parent: null }, { id: '6', nama: 'PPPK', parent: null }]
    if (entitas === 'unit') return [{ id: '5', nama: 'Unit A', parent: null }]
    if (entitas === 'satker') return parent === '5' ? [{ id: '9', nama: 'Satker X', parent: '5' }] : []
    return []
  })
})

describe('bulanBerjalanWib & lingkupDariFilter', () => {
  it('bulan berjalan menurut WIB, bukan UTC', () => {
    expect(bulanBerjalanWib(new Date('2026-10-31T18:00:00Z'))).toBe('2026-11')
    expect(bulanBerjalanWib(new Date('2026-10-31T16:59:00Z'))).toBe('2026-10')
  })

  it('bulan kalender (Kehadiran, Uang Makan) tidak ikut aturan tanggal 15: 29-10 WIB tetap Oktober', () => {
    expect(bulanBerjalanWib(new Date('2026-10-29T03:00:00Z'))).toBe('2026-10')
    expect(bulanBerjalanWib(new Date('2026-12-31T18:00:00Z'))).toBe('2027-01')
  })

  it('periode Tukin: tanggal > 15 WIB → bulan berikutnya; batas 15/16 dan akhir tahun', () => {
    expect(bulanPeriodeTukinWib(new Date('2026-10-15T16:59:59Z'))).toBe('2026-10') // 15-10 23:59:59 WIB
    expect(bulanPeriodeTukinWib(new Date('2026-10-15T17:00:00Z'))).toBe('2026-11') // 16-10 00:00 WIB
    expect(bulanPeriodeTukinWib(new Date('2026-10-29T03:00:00Z'))).toBe('2026-11') // 29-10 10:00 WIB (contoh reviewer)
    expect(bulanPeriodeTukinWib(new Date('2026-12-16T00:00:00Z'))).toBe('2027-01') // 16-12 07:00 WIB
    expect(bulanPeriodeTukinWib(new Date('2026-12-15T16:59:00Z'))).toBe('2026-12') // 15-12 23:59 WIB
  })

  it('periode Tukin menurut WIB, bukan tanggal UTC: 16-10 00:30 WIB sudah bulan berikutnya', () => {
    expect(new Date('2026-10-15T17:30:00Z').getUTCDate()).toBe(15)
    expect(bulanPeriodeTukinWib(new Date('2026-10-15T17:30:00Z'))).toBe('2026-11')
  })

  it('tanggal 1 sampai 15 tetap bulan berjalan untuk periode Tukin', () => {
    expect(bulanPeriodeTukinWib(new Date('2026-10-01T03:00:00Z'))).toBe('2026-10')
  })

  it('lingkup: satker menang atas unit; kosong = 0', () => {
    expect(lingkupDariFilter('', '')).toBe('0')
    expect(lingkupDariFilter('5', '')).toBe('5')
    expect(lingkupDariFilter('5', '9')).toBe('sat_9')
  })
})

describe('useFilterLaporan', () => {
  it('param role 1: bulan, unit_satker, jenis_pegawai, search, paginasi; nilai kosong tidak dikirim', async () => {
    const { api } = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' })
    expect(api.param.value).toEqual({ bulan: '2026-10', unit_satker: '0', page: 1, per_page: 25 })

    api.filter.idUnit = '5'
    api.filter.idSatker = '9'
    api.setJenisPegawai('1', true)
    api.setJenisPegawai('6', true)
    api.filter.search = '  ani '
    expect(api.param.value).toEqual({ bulan: '2026-10', unit_satker: 'sat_9', jenis_pegawai: ['1', '6'], search: 'ani', page: 1, per_page: 25 })
    api.setJenisPegawai('1', false)
    expect(api.param.value.jenis_pegawai).toEqual(['6'])
  })

  it('role 3/4 (bisaPilihUnit = false): tidak pernah mengirim unit_satker, URL unit/satker diabaikan', async () => {
    const { api } = await pasang({ bisaPilihUnit: false, bulanBawaan: '2026-10' }, '/x?unit=5&satker=9')
    expect(api.filter.idUnit).toBe('')
    expect(api.param.value).not.toHaveProperty('unit_satker')
  })

  it('memulihkan filter dari query string dan menolak nilai tidak sah', async () => {
    const { api } = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' }, '/x?bulan=2026-07&unit=5&jp=1,x,6&q=ani&page=3&per_page=50')
    expect(api.filter).toMatchObject({ bulan: '2026-07', idUnit: '5', jenisPegawai: ['1', '6'], search: 'ani', page: 3, perPage: 50 })

    const salah = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' }, '/x?bulan=2026-13&unit=abc&page=-2&per_page=7')
    expect(salah.api.filter).toMatchObject({ bulan: '2026-10', idUnit: '', page: 1, perPage: 25 })
  })

  it('menulis filter ke URL (hanya yang berbeda dari bawaan) dan kembali ke halaman 1 saat filter berubah', async () => {
    const { r, api } = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' })
    expect(r.currentRoute.value.query).toEqual({})

    api.filter.page = 4
    await flushPromises()
    expect(r.currentRoute.value.query).toEqual({ page: '4' })

    api.setBulan('2026-08')
    await flushPromises()
    expect(api.filter.page).toBe(1)
    expect(r.currentRoute.value.query).toEqual({ bulan: '2026-08' })

    api.setBulan('bukan-bulan')
    expect(api.filter.bulan).toBe('2026-08')
  })

  it('muatOpsi memuat jenis pegawai + unit; ganti unit memuat satker dan membuang satker lama yang tidak sah', async () => {
    const { api } = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' })
    await api.muatOpsi()
    expect(api.jenisPegawaiOpsi.value).toEqual([{ value: '1', label: 'PNS' }, { value: '6', label: 'PPPK' }])
    expect(api.unitOpsi.value).toEqual([{ value: '5', label: 'Unit A' }])

    api.filter.idUnit = '5'
    await flushPromises()
    expect(api.satkerOpsi.value).toEqual([{ value: '9', label: 'Satker X' }])
    api.filter.idSatker = '9'
    api.filter.idUnit = '7'
    await flushPromises()
    expect(api.satkerOpsi.value).toEqual([])
    expect(api.filter.idSatker).toBe('')
  })

  it('opsi master gagal dimuat → opsi kosong, tidak melempar', async () => {
    options.mockRejectedValue(new Error('boom'))
    const { api } = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' })
    await expect(api.muatOpsi()).resolves.toBeUndefined()
    expect(api.unitOpsi.value).toEqual([])
    expect(api.jenisPegawaiOpsi.value).toEqual([])
  })

  it('role 3/4 tidak memuat unit', async () => {
    const { api } = await pasang({ bisaPilihUnit: false, bulanBawaan: '2026-10' })
    await api.muatOpsi()
    expect(options.mock.calls.map((c) => c[0])).toEqual(['jenis-pegawai'])
  })

  it('CR-078 T-04: query yang berganti pada komponen yang sama (back/forward, menu ke halaman sama) diterapkan ke filter', async () => {
    const { r, api } = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' }, '/x?bulan=2026-09&unit=5&page=3&q=lama')
    expect(api.filter).toMatchObject({ bulan: '2026-09', idUnit: '5', page: 3, search: 'lama' })

    await r.push('/x?bulan=2026-08&q=ani')
    await flushPromises()
    expect(api.filter).toMatchObject({ bulan: '2026-08', idUnit: '', idSatker: '', page: 1, search: 'ani' })
    expect(api.param.value).toEqual({ bulan: '2026-08', unit_satker: '0', page: 1, per_page: 25, search: 'ani' })
    // Penerapan dari URL tidak ditulis ulang menjadi query lain (tanpa putaran).
    expect(r.currentRoute.value.query).toEqual({ bulan: '2026-08', q: 'ani' })
  })

  it('CR-078 T-04: URL dengan halaman dari back/forward dipertahankan (tidak direset ke halaman 1)', async () => {
    const { r, api } = await pasang({ bisaPilihUnit: false, bulanBawaan: '2026-10' }, '/x?page=2')
    await r.push('/x?page=4&bulan=2026-09')
    await flushPromises()
    expect(api.filter).toMatchObject({ bulan: '2026-09', page: 4 })
  })

  it('CR-078 T-04 revisi: pindah ke rute lain yang ber-query → query tujuan utuh, halaman lama tidak menulis balik', async () => {
    const { r, api } = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' }, '/x?unit=5&page=3&q=lama')
    const replace = vi.spyOn(r, 'replace')

    await r.push('/y?bulan=2026-08&q=ani')
    await flushPromises()

    expect(r.currentRoute.value.fullPath).toBe('/y?bulan=2026-08&q=ani')
    expect(replace).not.toHaveBeenCalled()
    expect(api.filter).toMatchObject({ bulan: '2026-10', idUnit: '5', search: 'lama' }) // filter lama tidak ikut pindah
  })

  it('CR-078 T-04: menu ke halaman yang sama tanpa query mengembalikan filter ke bawaan (Unit lama tidak tersisa)', async () => {
    const { r, api } = await pasang({ bisaPilihUnit: true, bulanBawaan: '2026-10' }, '/x?unit=5&satker=9')
    expect(api.filter.idUnit).toBe('5')

    await r.push('/x')
    await flushPromises()
    expect(api.filter).toMatchObject({ bulan: '2026-10', idUnit: '', idSatker: '', page: 1 })
    expect(api.param.value).toEqual({ bulan: '2026-10', unit_satker: '0', page: 1, per_page: 25 })
  })
})

describe('FilterLaporanBar', () => {
  const jenis = [{ value: '1', label: 'PNS' }, { value: '6', label: 'PPPK' }]

  it('role 3/4: tanpa pemilih Unit/Satker; Bulan dan Jenis Pegawai tampil', () => {
    const w = mount(FilterLaporanBar, { props: { bulan: '2026-10', jenisPegawaiOpsi: jenis } })
    expect(w.text()).toContain('Bulan')
    expect(w.text()).not.toContain('Unit')
    expect(w.text()).not.toContain('Satker')
    expect(w.find('[data-testid="filter-jenis-pegawai"]').exists()).toBe(true)
    expect(w.find('select').exists()).toBe(false)
  })

  it('role 1: Unit dan Satker tampil; Satker nonaktif sampai unit dipilih', async () => {
    const w = mount(FilterLaporanBar, { props: { bulan: '2026-10', bisaPilihUnit: true, idUnit: '', unitOpsi: [{ value: '5', label: 'Unit A' }] } })
    expect(w.text()).toContain('Unit')
    expect(w.text()).toContain('Satker')
    const combo = w.findAll('[role="combobox"]')
    expect(combo.length).toBeGreaterThanOrEqual(2)
    expect(combo[1]!.attributes('disabled') ?? combo[1]!.attributes('aria-disabled')).toBeDefined()
    await w.setProps({ idUnit: '5' })
    await nextTick()
    expect(w.findAll('[role="combobox"]')[1]!.attributes('disabled')).toBeUndefined()
  })

  it('mencentang Jenis Pegawai memancarkan daftar baru; tanpa opsi, fieldset tidak ada', async () => {
    const w = mount(FilterLaporanBar, { props: { bulan: '2026-10', jenisPegawai: ['1'], jenisPegawaiOpsi: jenis } })
    const kotak = w.findAll('input[type="checkbox"]')
    expect(kotak.map((k) => (k.element as HTMLInputElement).checked)).toEqual([true, false])
    await kotak[1]!.setValue(true)
    expect(w.emitted('update:jenisPegawai')![0]).toEqual([['1', '6']])
    await kotak[0]!.setValue(false)
    expect(w.emitted('update:jenisPegawai')![1]).toEqual([['6']])

    expect(mount(FilterLaporanBar, { props: { bulan: '2026-10', tampilCari: false } }).find('[data-testid="filter-jenis-pegawai"]').exists()).toBe(false)
  })

  it('kotak cari memancarkan update:search', async () => {
    const w = mount(FilterLaporanBar, { props: { bulan: '2026-10' } })
    await w.get('input[type="search"], input[type="text"]:not([placeholder="bb/tttt"])').setValue('ani')
    expect(w.emitted('update:search')!.at(-1)).toEqual(['ani'])
  })
})
