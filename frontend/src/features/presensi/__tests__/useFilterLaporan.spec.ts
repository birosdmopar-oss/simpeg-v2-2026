import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import FilterLaporanBar from '../components/FilterLaporanBar.vue'
import { bulanBerjalanWib, lingkupDariFilter, useFilterLaporan, type OpsiFilterLaporan } from '../composables/useFilterLaporan'

const options = vi.fn()
vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: (...a: unknown[]) => options(...a) } }))

function router(): Router {
  return createRouter({ history: createMemoryHistory(), routes: [{ path: '/x', component: { render: () => null } }] })
}

async function pasang(opsi: OpsiFilterLaporan, url = '/x') {
  const r = router()
  await r.push(url)
  let api!: ReturnType<typeof useFilterLaporan>
  const komponen = defineComponent({
    setup() {
      api = useFilterLaporan(opsi)
      return () => h('div')
    },
  })
  const w = mount(komponen, { global: { plugins: [r] } })
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
