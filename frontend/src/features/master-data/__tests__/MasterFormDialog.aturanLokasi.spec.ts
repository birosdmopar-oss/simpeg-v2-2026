/**
 * G-03 (DBV-007/CR-031) — form aturan lokasi presensi tanpa JSON mentah: target lokasi, unit/satker, dan jenis pegawai
 * dipilih lewat daftar centang dari endpoint options lalu disusun menjadi JSON array; hari berlaku lewat centang
 * Senin..Minggu menjadi "1,3,5". Tidak ada opsi satker karangan; unit/satker hanya dari master bila sudah tersedia.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/master.service', () => ({
  masterService: { get: vi.fn(), update: vi.fn(), create: vi.fn(), options: vi.fn() },
}))

import MasterFormDialog from '../components/MasterFormDialog.vue'
import { masterService } from '../services/master.service'
import type { MasterMeta, MasterOption, MasterRow } from '../types'

const aturanMeta: MasterMeta = {
  key: 'aturan-lokasi-presensi',
  label: 'Aturan Lokasi Presensi',
  primary_key: 'id_dm_user_lokasi_presensi',
  id_max_length: 11,
  id_digits: null,
  auto_increment: true,
  name_field: 'target_lp_desc',
  name_label: 'Target Lokasi',
  name_max_length: 65535,
  name_required: false,
  has_order: false,
  has_status: true,
  parent: null,
  fields: [
    { name: 'target_lp', label: 'Target Lokasi', type: 'textarea', required: true, options: null, hint: null },
    { name: 'target_uns', label: 'Target Unit/Satker', type: 'textarea', required: true, options: null, hint: null },
    { name: 'target_jp', label: 'Jenis Pegawai', type: 'textarea', required: true, options: null, hint: null },
    { name: 'hari_berlaku', label: 'Hari Berlaku', type: 'text', required: false, options: null, hint: null },
    { name: 'keterangan', label: 'Keterangan', type: 'textarea', required: false, options: null, hint: null },
  ],
}

const OPTIONS: Record<string, MasterOption[]> = {
  'lokasi-presensi': [
    { id: '2', nama: 'Gedung Sapta Pesona', parent: null },
    { id: '1', nama: 'Kantor Pusat', parent: null },
  ],
  'jenis-pegawai': [
    { id: '1', nama: 'Pegawai Negeri Sipil', parent: null },
    { id: '3', nama: 'Pegawai Tidak Tetap', parent: null },
    { id: '7', nama: 'Jenis Tujuh', parent: null },
  ],
  unit: [{ id: '3', nama: 'Sekretariat Kementerian', parent: null }],
  satker: [{ id: '3', nama: 'Biro SDM', parent: null }],
}

function mountDialog(row: MasterRow | null, allMeta: MasterMeta[] = [aturanMeta]) {
  return mount(MasterFormDialog, {
    props: { open: true, meta: aturanMeta, allMeta, row },
    attachTo: document.body,
  })
}

function checkbox(field: string, value: string): HTMLInputElement {
  const input = document.body.querySelector<HTMLInputElement>(`[data-testid="${field}-option-${value}"] input[type="checkbox"]`)
  if (!input) throw new Error(`centang ${field}=${value} tidak ditemukan`)
  return input
}

function optionValues(field: string): string[] {
  return Array.from(document.body.querySelectorAll<HTMLElement>(`[data-testid^="${field}-option-"]`)).map((el) =>
    (el.dataset.testid ?? '').replace(`${field}-option-`, ''),
  )
}

async function tick(field: string, value: string, checked = true): Promise<void> {
  const input = checkbox(field, value)
  input.checked = checked
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function submitForm(): Promise<void> {
  const form = document.body.querySelector<HTMLFormElement>('form[data-testid="master-form"]')
  if (!form) throw new Error('form tidak ditemukan')
  form.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

beforeEach(() => {
  vi.mocked(masterService.get).mockReset()
  vi.mocked(masterService.update).mockReset()
  vi.mocked(masterService.create).mockReset()
  vi.mocked(masterService.options).mockReset()
  vi.mocked(masterService.options).mockImplementation(async (entity: string) => OPTIONS[entity] ?? [])
  vi.mocked(masterService.create).mockResolvedValue({ id_dm_user_lokasi_presensi: 2, status: '1' })
  vi.mocked(masterService.update).mockResolvedValue({ id_dm_user_lokasi_presensi: 1, status: '1' })
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('MasterFormDialog — aturan lokasi presensi (G-03)', () => {
  it('tambah: pilihan centang menghasilkan JSON array dan hari "1,3,5"; tanpa JSON mentah', async () => {
    const wrapper = mountDialog(null)
    await flushPromises()

    // Tidak ada input JSON mentah maupun input nama (nama turunan diisi backend).
    for (const name of ['target_lp', 'target_uns', 'target_jp', 'target_lp_desc', 'hari_berlaku']) {
      expect(document.body.querySelector(`textarea[name="${name}"], input[name="${name}"]`)).toBeNull()
    }
    expect(document.body.textContent).not.toContain('JSON')

    // Unit/satker belum ada di v2: hanya "Seluruh Kementerian"; tidak ada satker karangan.
    expect(optionValues('target_uns')).toEqual(['0'])
    expect(document.body.textContent).not.toContain('sat_')
    expect(document.body.textContent).toContain('Data unit/satker belum tersedia')
    expect(masterService.options).not.toHaveBeenCalledWith('unit')
    expect(masterService.options).not.toHaveBeenCalledWith('satker')

    // Jenis pegawai id 7 tidak bisa dipilih (K-8).
    expect(optionValues('target_jp')).toEqual(['1', '3'])

    await tick('target_lp', '1')
    await tick('target_lp', '2')
    await tick('target_uns', '0')
    await tick('target_jp', '3')
    await tick('target_jp', '1')
    await tick('hari_berlaku', '5')
    await tick('hari_berlaku', '1')
    await tick('hari_berlaku', '3')
    await tick('hari_berlaku', '6')
    await tick('hari_berlaku', '6', false)

    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalledTimes(1))

    const [entity, payload] = vi.mocked(masterService.create).mock.calls[0] ?? []
    expect(entity).toBe('aturan-lokasi-presensi')
    expect(payload).toMatchObject({
      // Urutan mengikuti daftar pilihan (lokasi diurutkan nama oleh endpoint options).
      target_lp: '["2","1"]',
      target_uns: '["0"]',
      target_jp: '["1","3"]',
      hari_berlaku: '1,3,5',
    })
    expect(JSON.parse(String(payload?.target_lp))).toEqual(['2', '1'])
    wrapper.unmount()
  })

  it('tambah: target wajib — tanpa pilihan tidak terkirim dan pesan tampil per field', async () => {
    const wrapper = mountDialog(null)
    await flushPromises()

    await tick('target_lp', '2')
    await tick('target_lp', '2', false)
    await submitForm()
    await flushPromises()

    expect(masterService.create).not.toHaveBeenCalled()
    for (const field of ['target_lp', 'target_uns', 'target_jp']) {
      expect(document.body.querySelector(`[data-testid="checkbox-group-${field}"] [role="alert"]`)?.textContent).toContain('wajib')
    }
    wrapper.unmount()
  })

  it('edit: nilai tersimpan tercentang, nilai tidak aktif tetap tampil bertanda, kosongkan hari = setiap hari', async () => {
    const row: MasterRow = {
      id_dm_user_lokasi_presensi: 1,
      target_lp: '["2","9"]',
      target_lp_desc: '["Gedung Sapta Pesona","Lokasi Lama"]',
      target_uns: '["0"]',
      target_jp: '[1]',
      hari_berlaku: '1,3',
      keterangan: 'Aturan kantor pusat',
      status: '1',
    }
    const wrapper = mountDialog(row)
    await flushPromises()

    expect(checkbox('target_lp', '2').checked).toBe(true)
    expect(checkbox('target_lp', '1').checked).toBe(false)
    expect(checkbox('target_lp', '9').checked).toBe(true)
    expect(document.body.querySelector('[data-testid="target_lp-option-9"]')?.textContent).toContain('tidak aktif')
    expect(checkbox('target_jp', '1').checked).toBe(true)
    expect(['1', '2', '3'].map((d) => checkbox('hari_berlaku', d).checked)).toEqual([true, false, true])

    // Lepas lokasi tidak aktif dan seluruh hari.
    await tick('target_lp', '9', false)
    await tick('hari_berlaku', '1', false)
    await tick('hari_berlaku', '3', false)
    await submitForm()
    await vi.waitFor(() => expect(masterService.update).toHaveBeenCalledTimes(1))

    const [, id, payload] = vi.mocked(masterService.update).mock.calls[0] ?? []
    expect(id).toBe('1')
    // Field yang tidak disentuh dikirim apa adanya (backend menormalkan hanya kolom yang berubah).
    expect(payload).toMatchObject({ target_lp: '["2"]', target_uns: '["0"]', target_jp: '[1]', hari_berlaku: '' })
    wrapper.unmount()
  })

  it('unit/satker diambil dari endpoint options bila master-nya sudah ada; satker berawalan sat_', async () => {
    const unitMeta: MasterMeta = { ...aturanMeta, key: 'unit', label: 'Unit', name_required: true, fields: [] }
    const satkerMeta: MasterMeta = { ...aturanMeta, key: 'satker', label: 'Satker', name_required: true, fields: [] }
    const wrapper = mountDialog(null, [aturanMeta, unitMeta, satkerMeta])
    await flushPromises()

    expect(masterService.options).toHaveBeenCalledWith('unit')
    expect(masterService.options).toHaveBeenCalledWith('satker')
    expect(optionValues('target_uns')).toEqual(['0', '3', 'sat_3'])
    expect(document.body.querySelector('[data-testid="target_uns-option-sat_3"]')?.textContent).toContain('Biro SDM')
    expect(document.body.textContent).not.toContain('Data unit/satker belum tersedia')

    await tick('target_uns', 'sat_3')
    await tick('target_uns', '3')
    await tick('target_lp', '1')
    await tick('target_jp', '1')
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalledTimes(1))
    expect(vi.mocked(masterService.create).mock.calls[0]?.[1]).toMatchObject({ target_uns: '["3","sat_3"]' })
    wrapper.unmount()
  })
})
