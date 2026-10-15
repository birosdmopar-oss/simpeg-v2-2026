/**
 * Hook FE aditif mesin riwayat untuk WS-2 B-07 Jabatan (MAKE-018), diuji lewat RiwayatFieldForm dengan FieldDef uji:
 * (a) `multiselect` berinduk (`dependsOn`), wajib minimal satu, nilai lewat argumen ketiga `submit`, muat ulang saat
 * Edit dari teks kolom (`loadFrom`, nama dipisah `***`) dengan catatan untuk nama yang tidak cocok; (b) `optionsFilter`
 * jamak dengan syarat `when` atas atribut opsi terpilih (`extra.need_satker`); (c) `optionsSource` path API kustom;
 * susulan review: `showWhen`/`requiredWhen` jamak `any`/`all` dan aksesibilitas multiselect.
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
import UsulanFormDialog from '../riwayat/UsulanFormDialog.vue'
import type { UsulanConfig } from '../riwayat/usulan.config'
import { M, R, S, T, type FieldDef } from '../riwayat/riwayat.config'

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

describe('CR-049 D-F4: isian berjenjang dikosongkan tanpa galat dini', () => {
  it('memilih induk tidak memunculkan "wajib" pada turunan sebelum Simpan', async () => {
    options.mockImplementation(async (entity: string, parent?: string | null) => {
      if (entity === 'provinsi') return [opsi('31', 'DKI Jakarta')]
      if (entity === 'kabupaten-kota') return parent === '31' ? [opsi('3171', 'Jakarta Selatan', '31')] : []
      return parent ? [opsi(`${parent}01`, 'Kec', parent)] : []
    })
    const fields: FieldDef[] = [
      R('id_provinsi', 'Provinsi', 'provinsi', { required: true }),
      R('id_kabupaten_kota', 'Kabupaten/Kota', 'kabupaten-kota', { required: true, dependsOn: 'id_provinsi' }),
      R('id_kecamatan', 'Kecamatan', 'kecamatan', { required: true, dependsOn: 'id_kabupaten_kota' }),
    ]
    await mountForm(fields)
    await pilih('id_provinsi', '31')
    await pilih('id_kabupaten_kota', '3171')
    await pilih('id_provinsi', '31')

    expect(document.body.textContent, document.body.textContent ?? '').not.toContain('wajib')
    expect(document.body.querySelectorAll('[aria-invalid="true"]')).toHaveLength(0)

    await kirim()
    await vi.waitFor(() => expect(document.body.textContent).toContain('wajib'))
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

const isian = (name: string) => q<HTMLInputElement>(`[data-field="${name}"] input, input[data-field="${name}"]`)

async function ketik(name: string, value: string): Promise<void> {
  const el = isian(name)
  if (!el) throw new Error(`isian ${name} tidak ada`)
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

describe('MAKE-018 susulan: showWhen/requiredWhen jamak (any/all)', () => {
  const JENIS = [
    { value: '1', label: 'Struktural' },
    { value: '2', label: 'Fungsional' },
    { value: '3', label: 'Pelaksana' },
  ]
  // Kasus B-07: teks jabatan tampil bila (jenis 2) ATAU (jenis 1/3 DAN satker 39).
  const fields: FieldDef[] = [
    S('jenis_jabatan', 'Jenis Jabatan', JENIS),
    R('id_satker', 'Satuan Kerja', 'satker'),
    T('jabatan', 'Nama Jabatan', {
      required: true,
      showWhen: { any: [{ field: 'jenis_jabatan', in: ['2'] }, { all: [{ field: 'jenis_jabatan', in: ['1', '3'] }, { field: 'id_satker', in: ['39'] }] }] },
    }),
    T('keterangan', 'Keterangan', { requiredWhen: { all: [{ field: 'jenis_jabatan', in: ['1'] }, { field: 'id_satker', in: ['39'] }] } }),
  ]

  beforeEach(() => {
    options.mockResolvedValue([opsi('39', 'Satker Khusus'), opsi('10', 'Satker Lain')])
  })

  it('tampil menurut any/all; wajib hanya saat tampil; requiredWhen mewajibkan isian yang selalu tampil', async () => {
    const wrapper = await mountForm(fields)
    expect(isian('jabatan')).toBeNull()

    await pilih('jenis_jabatan', '2')
    expect(isian('jabatan')).not.toBeNull()

    await pilih('jenis_jabatan', '1')
    await pilih('id_satker', '10')
    expect(isian('jabatan')).toBeNull()
    await pilih('id_satker', '39')
    expect(isian('jabatan')).not.toBeNull()
    await pilih('jenis_jabatan', '3')
    expect(isian('jabatan')).not.toBeNull()

    // jenis 3 + satker 39: jabatan tampil & wajib; keterangan tidak wajib.
    await kirim()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Nama Jabatan wajib diisi'))
    expect(document.body.textContent).not.toContain('Keterangan wajib diisi')
    expect(isian('keterangan')?.getAttribute('aria-required')).toBeNull()

    // jenis 1 + satker 39: keterangan menjadi wajib.
    await pilih('jenis_jabatan', '1')
    expect(isian('keterangan')?.getAttribute('aria-required')).toBe('true')
    await ketik('jabatan', 'Kepala Khusus')
    await kirim()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Keterangan wajib diisi'))
    expect(wrapper.emitted('submit')).toBeUndefined()

    await ketik('keterangan', 'Catatan')
    await kirim()
    await vi.waitFor(() => expect(wrapper.emitted('submit')).toHaveLength(1))
    expect(wrapper.emitted('submit')?.[0]?.[0]).toEqual({ jenis_jabatan: '1', id_satker: '39', jabatan: 'Kepala Khusus', keterangan: 'Catatan' })
  })
})

describe('MAKE-018 susulan: aksesibilitas multiselect', () => {
  it('fieldset tanpa aria-invalid; checkbox invalid + aria-describedby ke pesan galat/petunjuk; tanda * aria-hidden', async () => {
    options.mockImplementation(async (entity: string) => (entity === 'rumpun-jabatan' ? [opsi('1', 'Rumpun A')] : [opsi('11', 'Sub A', '1')]))
    await mountForm([
      R('id_rumpun_jabatan', 'Rumpun Jabatan', 'rumpun-jabatan'),
      M('id_subrumpun_jabatan', 'Subrumpun Jabatan', 'subrumpun-jabatan', { required: true, dependsOn: 'id_rumpun_jabatan', hint: 'Boleh lebih dari satu.' }),
    ])
    await pilih('id_rumpun_jabatan', '1')
    const fieldset = q('[data-field="id_subrumpun_jabatan"]')
    expect(fieldset?.hasAttribute('aria-invalid')).toBe(false)
    expect(fieldset?.querySelector('legend span')?.getAttribute('aria-hidden')).toBe('true')
    expect(kotak('id_subrumpun_jabatan', '11')?.getAttribute('aria-describedby')).toBe('riwayat-id_subrumpun_jabatan-hint')
    expect(q('#riwayat-id_subrumpun_jabatan-hint')?.textContent).toContain('Boleh lebih dari satu.')

    await kirim()
    await vi.waitFor(() => expect(kotak('id_subrumpun_jabatan', '11')?.getAttribute('aria-describedby')).toBe('riwayat-id_subrumpun_jabatan-galat'))
    expect(kotak('id_subrumpun_jabatan', '11')?.getAttribute('aria-invalid')).toBe('true')
    expect(q('#riwayat-id_subrumpun_jabatan-galat')?.textContent).toContain('wajib dipilih minimal satu')
    expect(fieldset?.getAttribute('aria-describedby')).toBe('riwayat-id_subrumpun_jabatan-galat')
  })
})

describe('MAKE-018 susulan: requiredWhen di UsulanFormDialog', () => {
  it('isian wajib bersyarat ditolak kosong hanya saat syaratnya terpenuhi', async () => {
    const config: UsulanConfig = {
      jenis: 'karpeg',
      title: 'Uji',
      singular: 'usulan uji',
      titleMenurutJenisKelamin: null,
      fields: [
        S('jenis_permohonan', 'Jenis Permohonan', [
          { value: 'A', label: 'A' },
          { value: 'B', label: 'B' },
        ], { required: true }),
        S('alasan', 'Alasan', [{ value: 'x', label: 'X' }], { requiredWhen: { field: 'jenis_permohonan', in: ['B'] } }),
      ],
      berkas: [],
      batas_mb: 5,
      ekstensi: ['pdf'],
    }
    const wrapper = mount(UsulanFormDialog, { props: { open: true, config, row: null }, attachTo: document.body })
    await flushPromises()
    const kirimUsulan = async () => {
      q('[data-testid="usulan-form"]')?.dispatchEvent(new Event('submit'))
      await flushPromises()
    }

    await pilih('jenis_permohonan', 'A')
    await kirimUsulan()
    expect(wrapper.emitted('submit')).toHaveLength(1)

    await pilih('jenis_permohonan', 'B')
    await kirimUsulan()
    expect(wrapper.emitted('submit')).toHaveLength(1)
    expect(document.body.textContent).toContain('Alasan wajib diisi')
  })
})
