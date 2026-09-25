/**
 * DBV-003/CR-010 — form master kantor: field ref ber-`allow_system` punya pilihan LAIN-LAIN (kode `system_ids` master
 * wilayah), LAIN-LAIN berjenjang ke semua level turunan (terkunci, tanpa memanggil API), dan isian `other_for`
 * (`*_lain`) hanya tampil & wajib bila field ref-nya LAIN-LAIN. Backend (KantorHooks) tetap menegakkan aturan yang sama.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/master.service', () => ({
  masterService: { get: vi.fn(), update: vi.fn(), create: vi.fn(), options: vi.fn() },
}))

import MasterFormDialog from '../components/MasterFormDialog.vue'
import { buildMasterSchema, isSystemValue, MASTER_SYSTEM_LABEL, systemIdsOf } from '../schemas/master.schema'
import { masterService } from '../services/master.service'
import type { MasterFieldMeta, MasterMeta, MasterRow } from '../types'

const base = { has_order: true, has_status: true, auto_increment: false, name_max_length: 255, fields: [] }
const wilayah = (key: string, pk: string, digits: number, systemId: string, parent: MasterMeta['parent']): MasterMeta => ({
  ...base,
  key,
  label: key,
  primary_key: pk,
  id_max_length: digits,
  id_digits: digits,
  name_field: pk.replace('id_', ''),
  name_label: `Nama ${key}`,
  parent,
  system_ids: [systemId],
})
const provinsi = wilayah('provinsi', 'id_provinsi', 2, '99', null)
const kab = wilayah('kabupaten-kota', 'id_kabupaten_kota', 4, '9999', { field: 'id_provinsi', entity: 'provinsi' })
const kec = wilayah('kecamatan', 'id_kecamatan', 7, '9999999', { field: 'id_kabupaten_kota', entity: 'kabupaten-kota' })
const kel = wilayah('kelurahan', 'id_kelurahan', 10, '9999999999', { field: 'id_kecamatan', entity: 'kecamatan' })

const ref = (name: string, label: string, entity: string, dependsOn: string | null): MasterFieldMeta => ({
  name,
  label,
  type: 'ref',
  required: true,
  options: null,
  hint: null,
  entity,
  depends_on: dependsOn,
  allow_system: true,
})
const lain = (name: string, label: string, otherFor: string): MasterFieldMeta => ({
  name,
  label,
  type: 'text',
  required: false,
  options: null,
  hint: null,
  other_for: otherFor,
})

const kantor: MasterMeta = {
  ...base,
  key: 'kantor',
  label: 'Kantor',
  primary_key: 'id_kantor',
  id_max_length: 10,
  id_digits: null,
  auto_increment: true,
  name_field: 'nama_kantor',
  name_label: 'Nama Kantor',
  parent: null,
  system_ids: [],
  fields: [
    ref('id_provinsi', 'Provinsi', 'provinsi', null),
    lain('provinsi_lain', 'Provinsi Lainnya', 'id_provinsi'),
    ref('id_kabupaten', 'Kabupaten/Kota', 'kabupaten-kota', 'id_provinsi'),
    lain('kabupaten_lain', 'Kabupaten/Kota Lainnya', 'id_kabupaten'),
    ref('id_kecamatan', 'Kecamatan', 'kecamatan', 'id_kabupaten'),
    lain('kecamatan_lain', 'Kecamatan Lainnya', 'id_kecamatan'),
    ref('id_kelurahan', 'Kelurahan/Desa', 'kelurahan', 'id_kecamatan'),
    lain('kelurahan_lain', 'Kelurahan/Desa Lainnya', 'id_kelurahan'),
    { name: 'kode_pos', label: 'Kode Pos', type: 'text', required: false, options: null, hint: null, other_for: null },
  ],
}
const allMeta = [provinsi, kab, kec, kel, kantor]

function mountKantor(row: MasterRow | null) {
  return mount(MasterFormDialog, { props: { open: true, meta: kantor, allMeta, row }, attachTo: document.body })
}

function select(name: string): HTMLSelectElement {
  const el = document.body.querySelector<HTMLSelectElement>(`select[name="${name}"]`)
  if (!el) throw new Error(`select ${name} tidak ditemukan`)
  return el
}

function input(name: string): HTMLInputElement | null {
  return document.body.querySelector<HTMLInputElement>(`input[name="${name}"]`)
}

async function choose(name: string, value: string): Promise<void> {
  const el = select(name)
  el.value = value
  el.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function type(name: string, value: string): Promise<void> {
  const el = input(name)
  if (!el) throw new Error(`input ${name} tidak ditemukan`)
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

function optionValues(name: string): string[] {
  return Array.from(select(name).options).map((o) => o.value)
}

async function submitForm(): Promise<void> {
  const form = document.body.querySelector<HTMLFormElement>('form[data-testid="master-form"]')
  if (!form) throw new Error('form tidak ditemukan')
  form.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

const LEVELS = ['id_provinsi', 'id_kabupaten', 'id_kecamatan', 'id_kelurahan']
const LAIN = ['provinsi_lain', 'kabupaten_lain', 'kecamatan_lain', 'kelurahan_lain']

beforeEach(() => {
  vi.mocked(masterService.get).mockReset()
  vi.mocked(masterService.update).mockReset()
  vi.mocked(masterService.create).mockReset()
  vi.mocked(masterService.options).mockReset()
  vi.mocked(masterService.options).mockImplementation(async (entity, parent) => {
    if (entity === 'provinsi') return [{ id: '31', nama: 'DKI Jakarta', parent: null }]
    if (entity === 'kabupaten-kota' && parent === '31') return [{ id: '3171', nama: 'Jakarta Pusat', parent: '31' }]
    if (entity === 'kecamatan' && parent === '3171') return [{ id: '3171010', nama: 'Gambir', parent: '3171' }]
    if (entity === 'kelurahan' && parent === '3171010') return [{ id: '3171010001', nama: 'Gambir', parent: '3171010' }]
    return []
  })
  vi.mocked(masterService.create).mockImplementation(async (_entity, payload) => ({ id_kantor: 9, ...payload }))
  vi.mocked(masterService.update).mockImplementation(async (_entity, _id, payload) => ({ id_kantor: 2, ...payload }))
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('master.schema — kode sistem & isian other_for (CR-010)', () => {
  it('systemIdsOf / isSystemValue membaca system_ids master rujukan', () => {
    expect(systemIdsOf('provinsi', allMeta)).toEqual(['99'])
    expect(systemIdsOf('tidak-ada', allMeta)).toEqual([])
    expect(isSystemValue(kantor, 'id_provinsi', '99', allMeta)).toBe(true)
    expect(isSystemValue(kantor, 'id_provinsi', '31', allMeta)).toBe(false)
    expect(isSystemValue(kantor, 'kode_pos', '99', allMeta)).toBe(false)
    expect(MASTER_SYSTEM_LABEL).toBe('LAIN-LAIN')
  })

  it('isian *_lain wajib hanya bila field ref-nya LAIN-LAIN', () => {
    const schema = buildMasterSchema(kantor, false, allMeta)
    const riil = { nama_kantor: 'Kantor', id_provinsi: '31', id_kabupaten: '3171', id_kecamatan: '3171010', id_kelurahan: '3171010001', order: '' }
    expect(schema.safeParse(riil).success).toBe(true)

    const lainLain = { ...riil, id_provinsi: '99', id_kabupaten: '9999', id_kecamatan: '9999999', id_kelurahan: '9999999999' }
    const result = schema.safeParse({ ...lainLain, provinsi_lain: '  ', kabupaten_lain: 'Tokyo' })
    expect(result.success).toBe(false)
    const paths = result.success ? [] : result.error.issues.map((i) => i.path.join('.'))
    expect(paths).toEqual(['provinsi_lain', 'kecamatan_lain', 'kelurahan_lain'])
    expect(result.success ? '' : result.error.issues[0]?.message).toBe('Provinsi Lainnya wajib diisi.')

    expect(schema.safeParse({ ...lainLain, provinsi_lain: 'Jepang', kabupaten_lain: 'Tokyo', kecamatan_lain: 'Minato', kelurahan_lain: 'Akasaka' }).success).toBe(true)
    // Tanpa daftar meta (pemanggil lama): tidak ada kode sistem yang dikenal, isian lain tetap opsional.
    expect(buildMasterSchema(kantor, false).safeParse(lainLain).success).toBe(true)
  })
})

describe('MasterFormDialog — pilihan LAIN-LAIN kantor (CR-010)', () => {
  it('tambah: LAIN-LAIN di akhir daftar; memilihnya mengisi & mengunci semua level turunan tanpa API; *_lain wajib', async () => {
    const wrapper = mountKantor(null)
    await flushPromises()

    expect(optionValues('id_provinsi')).toEqual(['', '31', '99'])
    expect(Array.from(select('id_provinsi').options).map((o) => o.textContent?.trim())).toContain('LAIN-LAIN (99)')
    for (const name of LAIN) expect(input(name)).toBeNull()

    await choose('id_provinsi', '99')
    expect(LEVELS.map((name) => select(name).value)).toEqual(['99', '9999', '9999999', '9999999999'])
    for (const name of LEVELS.slice(1)) {
      expect(select(name).disabled).toBe(true)
      expect(optionValues(name).filter((v) => v !== '')).toHaveLength(1)
    }
    expect(masterService.options).not.toHaveBeenCalledWith('kabupaten-kota', '99')
    expect(masterService.options).not.toHaveBeenCalledWith('kecamatan', expect.anything())
    for (const name of LAIN) expect(input(name)).not.toBeNull()
    expect(document.body.textContent).toContain('Wajib diisi karena Provinsi LAIN-LAIN.')

    await type('nama_kantor', 'KBRI Tokyo')
    await submitForm()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Provinsi Lainnya wajib diisi.'))
    expect(masterService.create).not.toHaveBeenCalled()

    await type('provinsi_lain', 'Jepang')
    await type('kabupaten_lain', 'Tokyo')
    await type('kecamatan_lain', 'Minato')
    await type('kelurahan_lain', 'Akasaka')
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalledTimes(1))
    expect(masterService.create).toHaveBeenCalledWith('kantor', {
      nama_kantor: 'KBRI Tokyo',
      id_provinsi: '99',
      provinsi_lain: 'Jepang',
      id_kabupaten: '9999',
      kabupaten_lain: 'Tokyo',
      id_kecamatan: '9999999',
      kecamatan_lain: 'Minato',
      id_kelurahan: '9999999999',
      kelurahan_lain: 'Akasaka',
    })
    wrapper.unmount()
  })

  it('induk riil + anak LAIN-LAIN boleh; kembali ke wilayah riil menyembunyikan & mengosongkan *_lain', async () => {
    const wrapper = mountKantor(null)
    await flushPromises()

    await choose('id_provinsi', '99')
    await type('provinsi_lain', 'Jepang')

    await choose('id_provinsi', '31')
    expect(input('provinsi_lain')).toBeNull()
    expect(select('id_kabupaten').value).toBe('')
    expect(select('id_kabupaten').disabled).toBe(false)
    expect(optionValues('id_kabupaten')).toEqual(['', '3171', '9999'])

    await choose('id_kabupaten', '3171')
    expect(optionValues('id_kecamatan')).toEqual(['', '3171010', '9999999'])
    await choose('id_kecamatan', '9999999')
    expect(select('id_kelurahan').value).toBe('9999999999')
    expect(select('id_kelurahan').disabled).toBe(true)
    expect([input('kabupaten_lain'), input('kecamatan_lain') !== null, input('kelurahan_lain') !== null]).toEqual([null, true, true])

    await type('nama_kantor', 'Kantor Pulau Seribu')
    await type('kecamatan_lain', 'Kepulauan Seribu Utara')
    await type('kelurahan_lain', 'Pulau Panggang')
    await type('kode_pos', '14530')
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalledTimes(1))
    // provinsi_lain yang sempat diketik ikut dibuang karena disembunyikan.
    expect(masterService.create).toHaveBeenCalledWith('kantor', {
      nama_kantor: 'Kantor Pulau Seribu',
      id_provinsi: '31',
      id_kabupaten: '3171',
      id_kecamatan: '9999999',
      kecamatan_lain: 'Kepulauan Seribu Utara',
      id_kelurahan: '9999999999',
      kelurahan_lain: 'Pulau Panggang',
      kode_pos: '14530',
    })
    wrapper.unmount()
  })

  it('edit kantor LAIN-LAIN: nilai tersimpan tampil sebagai LAIN-LAIN (bukan "tidak aktif") dan ikut terkirim', async () => {
    const row: MasterRow = {
      id_kantor: 2,
      nama_kantor: 'Kantor Perwakilan Luar Negeri',
      id_provinsi: '99',
      provinsi_lain: 'Jepang',
      id_kabupaten: '9999',
      kabupaten_lain: 'Tokyo',
      id_kecamatan: '9999999',
      kecamatan_lain: 'Shinagawa',
      id_kelurahan: '9999999999',
      kelurahan_lain: 'Higashi-Gotanda',
      kode_pos: null,
      order: 2,
      status: '1',
    }
    const wrapper = mountKantor(row)
    await flushPromises()

    expect(masterService.options).toHaveBeenCalledWith('provinsi', null)
    expect(masterService.options).toHaveBeenCalledTimes(1)
    expect(masterService.get).not.toHaveBeenCalled()
    expect(LEVELS.map((name) => select(name).value)).toEqual(['99', '9999', '9999999', '9999999999'])
    const labels = LEVELS.flatMap((name) => Array.from(select(name).options).map((o) => o.textContent ?? ''))
    expect(labels.some((label) => label.includes('tidak aktif') || label.includes('tidak ditemukan'))).toBe(false)
    expect(LAIN.map((name) => input(name)?.value)).toEqual(['Jepang', 'Tokyo', 'Shinagawa', 'Higashi-Gotanda'])

    await type('kelurahan_lain', 'Kita-Shinagawa')
    await submitForm()
    await vi.waitFor(() => expect(masterService.update).toHaveBeenCalledTimes(1))
    expect(masterService.update).toHaveBeenCalledWith('kantor', '2', {
      nama_kantor: 'Kantor Perwakilan Luar Negeri',
      id_provinsi: '99',
      provinsi_lain: 'Jepang',
      id_kabupaten: '9999',
      kabupaten_lain: 'Tokyo',
      id_kecamatan: '9999999',
      kecamatan_lain: 'Shinagawa',
      id_kelurahan: '9999999999',
      kelurahan_lain: 'Kita-Shinagawa',
      kode_pos: '',
    })
    wrapper.unmount()
  })
})
