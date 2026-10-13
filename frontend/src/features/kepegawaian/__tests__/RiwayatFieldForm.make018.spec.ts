/**
 * Hook FE aditif mesin riwayat untuk WS-2 B-07 Jabatan (MAKE-018), diuji lewat RiwayatFieldForm dengan FieldDef uji:
 * (a) `multiselect` berinduk (`dependsOn`), wajib minimal satu, nilai lewat argumen ketiga `submit`, muat ulang saat
 * Edit dari teks kolom (`loadFrom`, nama dipisah `***`) dengan catatan untuk nama yang tidak cocok; (b) `optionsFilter`
 * jamak dengan syarat `when` atas atribut opsi terpilih (`extra.need_satker`); (c) `optionsSource` path API kustom.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/master-data/services/master.service', () => ({
  masterService: { options: vi.fn() },
}))
vi.mock('@/lib/axios', () => ({ api: { get: vi.fn() } }))

import { masterService } from '@/features/master-data/services/master.service'
import type { MasterOption } from '@/features/master-data/types'
import { api } from '@/lib/axios'

import RiwayatFieldForm from '../riwayat/RiwayatFieldForm.vue'
import { M, R, type FieldDef } from '../riwayat/riwayat.config'

const options = vi.mocked(masterService.options)
const apiGet = vi.mocked(api.get)

const opsi = (id: string, nama: string, parent: string | null = null, extra?: Record<string, string>): MasterOption => ({ id, nama, parent, ...(extra ? { extra } : {}) })

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  options.mockReset()
  apiGet.mockReset()
})

async function mountForm(fields: FieldDef[], extra: Record<string, unknown> = {}) {
  const wrapper = mount(RiwayatFieldForm, { props: { fields, ...extra }, attachTo: document.body })
  await flushPromises()
  await flushPromises()
  return wrapper
}

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const select = (name: string) => q<HTMLSelectElement>(`[data-field="${name}"] select, select[data-field="${name}"]`)
const kotak = (name: string, id: string) => q<HTMLInputElement>(`[data-field="${name}"] [data-opsi="${id}"] input[type="checkbox"]`)
const opsiKotak = (name: string): string[] => [...document.body.querySelectorAll(`[data-field="${name}"] [data-opsi]`)].map((e) => e.getAttribute('data-opsi') ?? '')
const opsiSelect = (name: string): string[] => [...(select(name)?.options ?? [])].map((o) => o.value).filter((v) => v !== '')

async function pilih(name: string, value: string): Promise<void> {
  const el = select(name)
  if (!el) throw new Error(`isian ${name} tidak ada`)
  el.value = value
  el.dispatchEvent(new Event('change'))
  await flushPromises()
  await flushPromises()
}

async function centang(name: string, id: string): Promise<void> {
  const el = kotak(name, id)
  if (!el) throw new Error(`kotak ${name}/${id} tidak ada`)
  el.click()
  await flushPromises()
}

async function kirim(): Promise<void> {
  q('[data-testid="riwayat-form"]')?.dispatchEvent(new Event('submit'))
  await flushPromises()
  await new Promise((r) => setTimeout(r, 0))
  await flushPromises()
}

describe('MAKE-018 (a) multiselect', () => {
  const fields: FieldDef[] = [
    R('id_rumpun_jabatan', 'Rumpun Jabatan', 'rumpun-jabatan'),
    M('id_subrumpun_jabatan', 'Subrumpun Jabatan', 'subrumpun-jabatan', {
      required: true,
      dependsOn: 'id_rumpun_jabatan',
      loadFrom: { kolom: 'subrumpun_jabatan', pemisah: '***', cocokkan: 'label' },
    }),
  ]

  beforeEach(() => {
    options.mockImplementation(async (entity: string, parent?: string | null) => {
      if (entity === 'rumpun-jabatan') return [opsi('1', 'Rumpun A'), opsi('2', 'Rumpun B')]
      if (parent === '1') return [opsi('11', 'Sub A', '1'), opsi('12', 'Sub B', '1')]
      return [opsi('21', 'Sub C', '2')]
    })
  })

  it('opsi dimuat dari induk; wajib minimal satu; nilai lewat argumen ketiga submit; ganti induk mengosongkan pilihan', async () => {
    const wrapper = await mountForm(fields)
    expect(opsiKotak('id_subrumpun_jabatan')).toEqual([])
    expect(options).not.toHaveBeenCalledWith('subrumpun-jabatan', expect.anything(), expect.anything())

    await pilih('id_rumpun_jabatan', '1')
    expect(options).toHaveBeenCalledWith('subrumpun-jabatan', '1', undefined)
    expect(opsiKotak('id_subrumpun_jabatan')).toEqual(['11', '12'])

    await kirim()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Subrumpun Jabatan wajib dipilih minimal satu'))
    expect(wrapper.emitted('submit')).toBeUndefined()

    await centang('id_subrumpun_jabatan', '11')
    await centang('id_subrumpun_jabatan', '12')
    await kirim()
    await vi.waitFor(() => expect(wrapper.emitted('submit')).toHaveLength(1))
    const [values, berkas, jamak] = wrapper.emitted('submit')?.[0] ?? []
    expect(values).toEqual({ id_rumpun_jabatan: '1' })
    expect(berkas).toEqual({})
    expect(jamak).toEqual({ id_subrumpun_jabatan: ['11', '12'] })

    await pilih('id_rumpun_jabatan', '2')
    expect(opsiKotak('id_subrumpun_jabatan')).toEqual(['21'])
    expect(kotak('id_subrumpun_jabatan', '21')?.checked).toBe(false)
    await centang('id_subrumpun_jabatan', '21')
    await kirim()
    await vi.waitFor(() => expect(wrapper.emitted('submit')).toHaveLength(2))
    expect(wrapper.emitted('submit')?.[1]?.[2]).toEqual({ id_subrumpun_jabatan: ['21'] })
  })

  it('Edit: loadFrom mencocokkan nama (tanpa beda huruf) ke opsi; nama tidak cocok tampil sebagai catatan', async () => {
    const wrapper = await mountForm(fields, {
      mode: 'edit',
      initial: { id_rumpun_jabatan: '1' },
      baris: { id_rumpun_jabatan: 1, subrumpun_jabatan: 'Sub A***sub b *** Subrumpun Lama' },
    })
    expect(kotak('id_subrumpun_jabatan', '11')?.checked).toBe(true)
    expect(kotak('id_subrumpun_jabatan', '12')?.checked).toBe(true)
    expect(q('[data-testid="riwayat-catatan-id_subrumpun_jabatan"]')?.textContent).toContain('Subrumpun Lama')

    await kirim()
    await vi.waitFor(() => expect(wrapper.emitted('submit')).toHaveLength(1))
    expect(wrapper.emitted('submit')?.[0]?.[2]).toEqual({ id_subrumpun_jabatan: ['11', '12'] })
  })
})

describe('MAKE-018 (b) optionsFilter jamak dengan syarat when atas atribut opsi', () => {
  const fields: FieldDef[] = [
    R('id_group_jabatan', 'Group Jabatan', 'group-jabatan'),
    R('id_sub_group_jabatan', 'Sub Group Jabatan', 'sub-group-jabatan', { dependsOn: 'id_group_jabatan' }),
    R('id_satker', 'Satuan Kerja', 'satker'),
    R('id_jabatan', 'Jabatan', 'jabatan', {
      optionsFilter: [
        { param: 'id_sub_group_jabatan', field: 'id_sub_group_jabatan' },
        { param: 'id_satker', field: 'id_satker', when: { field: 'id_sub_group_jabatan', opsi: 'need_satker', in: ['1'] } },
      ],
    }),
  ]

  beforeEach(() => {
    options.mockImplementation(async (entity: string, _parent?: string | null, filters?: Record<string, string>) => {
      if (entity === 'group-jabatan') return [opsi('1', 'Struktural')]
      if (entity === 'sub-group-jabatan') return [opsi('5', 'Eselon', '1', { need_satker: '1' }), opsi('6', 'Fungsional', '1', { need_satker: '2' })]
      if (entity === 'satker') return [opsi('9', 'Satker Uji'), opsi('10', 'Satker Lain')]
      return [opsi(`j${filters?.id_sub_group_jabatan ?? 'x'}-${filters?.id_satker ?? 'x'}`, 'Jabatan')]
    })
  })

  it('parameter kosong/tidak memenuhi when tidak dikirim; pemicu berubah → dimuat ulang dan nilai dikosongkan', async () => {
    await mountForm(fields)
    expect(options).toHaveBeenCalledWith('jabatan', null, {})

    await pilih('id_group_jabatan', '1')
    await pilih('id_satker', '9')
    await pilih('id_sub_group_jabatan', '5')
    expect(options).toHaveBeenLastCalledWith('jabatan', null, { id_sub_group_jabatan: '5', id_satker: '9' })
    expect(opsiSelect('id_jabatan')).toEqual(['j5-9'])
    await pilih('id_jabatan', 'j5-9')

    // Sub group tanpa need_satker → id_satker tidak dikirim; nilai jabatan dikosongkan.
    await pilih('id_sub_group_jabatan', '6')
    expect(options).toHaveBeenLastCalledWith('jabatan', null, { id_sub_group_jabatan: '6' })
    expect(select('id_jabatan')?.value).toBe('')
    expect(opsiSelect('id_jabatan')).toEqual(['j6-x'])

    // Satker berganti juga memicu muat ulang (parameternya tetap tidak dikirim selama when tidak terpenuhi).
    options.mockClear()
    await pilih('id_satker', '10')
    expect(options).toHaveBeenCalledWith('jabatan', null, { id_sub_group_jabatan: '6' })
  })

  it('regresi: optionsFilter tunggal lama tetap menunggu isian pemicu terisi', async () => {
    const lama: FieldDef[] = [R('jenis', 'Jenis', 'jenis-x'), R('id_x', 'X', 'x', { optionsFilter: { param: 'jenis', field: 'jenis' } })]
    options.mockResolvedValue([opsi('1', 'Satu')])
    await mountForm(lama)
    expect(options).not.toHaveBeenCalledWith('x', expect.anything(), expect.anything())
    await pilih('jenis', '1')
    expect(options).toHaveBeenCalledWith('x', null, { jenis: '1' })
  })
})

describe('MAKE-018 (c) optionsSource path API kustom', () => {
  const fields: FieldDef[] = [
    R('id_satker', 'Satuan Kerja', 'satker'),
    R('nip_atasan', 'Atasan', '', { optionsSource: (v) => (v.id_satker ? `/pegawai/atasan?id_satker=${v.id_satker}` : null) }),
  ]

  it('null = belum dimuat; path berubah → dimuat ulang dari API dan nilai dikosongkan', async () => {
    options.mockResolvedValue([opsi('9', 'Satker Uji'), opsi('10', 'Satker Lain')])
    apiGet.mockImplementation(async (path: string) => ({ data: [opsi(path.endsWith('9') ? 'A' : 'B', `Atasan ${path.slice(-2)}`)] }))
    await mountForm(fields)
    expect(apiGet).not.toHaveBeenCalled()
    expect(opsiSelect('nip_atasan')).toEqual([])

    await pilih('id_satker', '9')
    expect(apiGet).toHaveBeenCalledWith('/pegawai/atasan?id_satker=9')
    expect(opsiSelect('nip_atasan')).toEqual(['A'])
    await pilih('nip_atasan', 'A')

    await pilih('id_satker', '10')
    expect(apiGet).toHaveBeenLastCalledWith('/pegawai/atasan?id_satker=10')
    expect(select('nip_atasan')?.value).toBe('')
    expect(opsiSelect('nip_atasan')).toEqual(['B'])
  })
})
