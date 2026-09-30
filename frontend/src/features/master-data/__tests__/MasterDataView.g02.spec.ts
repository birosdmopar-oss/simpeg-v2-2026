/**
 * Master G-02 (DBV-008/CR-026) di halaman Master Data generik dan form master generik. Meta di bawah mengikuti bentuk
 * GET /master/meta backend untuk keenam master (dikunci `JabatanUnitSatkerTest::testMetaDescribesG02Masters`).
 * AGENTS.md bagian 1: semua aksi baris lewat menu ⋮ (`master-actions-<id>`, urutan & label baku, Hapus paling bawah
 * bertanda bahaya), kolom Status hanya badge. Item urutan hanya untuk master ber-`order` dan hanya saat satu lingkup utuh
 * tampil: unit & group global, satker per unit, sub group per group; jabatan & kelas jabatan tanpa urutan. Kelas jabatan
 * `code_as_name` (CR-026): form hanya punya input nomor kelas saat tambah, tanpa input nama; nomor tidak bisa diubah.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/master.service', () => ({
  masterService: {
    meta: vi.fn(),
    list: vi.fn(),
    options: vi.fn(),
    remove: vi.fn(),
    reorder: vi.fn(),
    setStatus: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
  },
}))

import { rowMenuActions, type RenderedRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import MasterFormDialog from '../components/MasterFormDialog.vue'
import { masterService } from '../services/master.service'
import type { MasterFieldMeta, MasterMeta, MasterOption, MasterRow } from '../types'
import MasterDataView from '../views/MasterDataView.vue'

function g02Meta(key: string, label: string, primaryKey: string, nameField: string, nameMaxLength: number, extra: Partial<MasterMeta> = {}): MasterMeta {
  return {
    key,
    label,
    primary_key: primaryKey,
    id_max_length: 11,
    id_digits: null,
    auto_increment: true,
    name_field: nameField,
    name_label: label,
    name_max_length: nameMaxLength,
    has_order: true,
    has_status: true,
    parent: null,
    fields: [],
    order_mode: 'shift',
    order_scope: [],
    order_max: null,
    filters: [],
    status_chain: false,
    system_ids: [],
    code_as_name: false,
    id_range: null,
    ...extra,
  }
}

function field(name: string, label: string, type: MasterFieldMeta['type'], required: boolean, extra: Partial<MasterFieldMeta> = {}): MasterFieldMeta {
  return { name, label, type, required, options: null, hint: null, max_bytes: null, min: null, max: null, entity: null, depends_on: null, ...extra }
}

const IS_UPT = field('is_upt', 'UPT', 'boolean', false, { hint: 'Centang bila merupakan Unit Pelaksana Teknis (UPT).' })
const ZONASI_HINT = 'Selisih jam presensi dari WIB dalam menit: 0 = WIB, 60 = WITA, 120 = WIT.'

const UNIT = g02Meta('unit', 'Unit Kerja', 'id_unit', 'unit', 150, {
  fields: [IS_UPT, field('alamat_pdf_header', 'Alamat PDF Header', 'textarea', false, { max_bytes: 255 }), field('tembusan_kppn', 'Tembusan KPPN', 'text', false), field('lokasi_kppn', 'Lokasi KPPN', 'text', false)],
})
const SATKER = g02Meta('satker', 'Satuan Kerja', 'id_satker', 'satker', 150, {
  parent: { field: 'id_unit', entity: 'unit' },
  status_chain: true,
  fields: [
    field('zonasi', 'Zonasi Presensi (menit)', 'int', true, { min: 0, max: 120, hint: ZONASI_HINT }),
    IS_UPT,
    field('alamat_pdf_header', 'Alamat PDF Header', 'textarea', false, { max_bytes: 65535 }),
    field('tembusan_kppn', 'Tembusan KPPN', 'text', false),
    field('lokasi_kppn', 'Lokasi KPPN', 'text', false),
  ],
})
const GROUP = g02Meta('group-jabatan', 'Group Jabatan', 'id_group_jabatan', 'group_jabatan', 45)
const SUB_GROUP = g02Meta('sub-group-jabatan', 'Sub Group Jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 100, {
  parent: { field: 'id_group_jabatan', entity: 'group-jabatan' },
  status_chain: true,
  fields: [
    field('need_satker', 'Butuh Satuan Kerja', 'select', true, {
      options: [
        { value: '1', label: 'Ya' },
        { value: '2', label: 'Tidak' },
      ],
    }),
  ],
})
const KELAS = g02Meta('kelas-jabatan', 'Kelas Jabatan', 'kelas_jabatan', 'kelas_jabatan', 2, {
  auto_increment: false,
  id_max_length: 2,
  has_order: false,
  code_as_name: true,
  id_range: [1, 20],
  fields: [field('tukin', 'Tunjangan Kinerja', 'int', true, { min: 0, max: 2147483647 })],
})
const JABATAN = g02Meta('jabatan', 'Jabatan', 'id_jabatan', 'jabatan', 250, {
  has_order: false,
  filters: ['id_group_jabatan', 'id_sub_group_jabatan', 'id_satker', 'kelas_jabatan'],
  fields: [
    field('id_group_jabatan', 'Group Jabatan', 'ref', true, { entity: 'group-jabatan', allow_system: false }),
    field('id_sub_group_jabatan', 'Sub Group Jabatan', 'ref', true, { entity: 'sub-group-jabatan', depends_on: 'id_group_jabatan', allow_system: false }),
    field('id_satker', 'Satuan Kerja', 'ref', false, { entity: 'satker', allow_system: false }),
    field('kelas_jabatan', 'Kelas Jabatan', 'ref', false, { entity: 'kelas-jabatan', allow_system: false }),
    field('umur_pensiun', 'Umur Pensiun', 'int', false, { min: 50, max: 80 }),
  ],
})

const G02: MasterMeta[] = [UNIT, SATKER, GROUP, SUB_GROUP, KELAS, JABATAN]

const OPTIONS: Record<string, MasterOption[]> = {
  unit: [
    { id: '1', nama: 'Sekretariat Kementerian', parent: null },
    { id: '2', nama: 'Deputi Bidang Sumber Daya dan Kelembagaan', parent: null },
  ],
  'group-jabatan': [
    { id: '1', nama: 'Struktural', parent: null },
    { id: '2', nama: 'Fungsional', parent: null },
  ],
  'sub-group-jabatan': [
    { id: '1', nama: 'Pimpinan Tinggi Pratama', parent: '1' },
    { id: '2', nama: 'Administrator', parent: '1' },
  ],
  satker: [{ id: '1', nama: 'Biro Sumber Daya Manusia', parent: '1' }],
  'kelas-jabatan': [
    { id: '7', nama: '7', parent: null },
    { id: '9', nama: '9', parent: null },
  ],
}

const item = (key: string, label: string, disabled = false, danger = false): RenderedRowAction => ({ key, label, disabled, danger })

async function mountMaster(entity: string, items: MasterRow[]): Promise<VueWrapper> {
  vi.mocked(masterService.list).mockResolvedValue({ items, total: items.length, page: 1, per_page: 20 })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/master/:entity?', name: 'master-data', component: MasterDataView }],
  })
  await router.push(`/master/${entity}`)
  await router.isReady()
  const wrapper = mount(MasterDataView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

function mountForm(meta: MasterMeta, row: MasterRow | null): VueWrapper {
  return mount(MasterFormDialog, { props: { open: true, meta, allMeta: G02, row }, attachTo: document.body })
}

function el<T extends Element>(selector: string): T {
  const found = document.body.querySelector<T>(selector)
  if (!found) throw new Error(`${selector} tidak ditemukan`)
  return found
}

async function typeInto(selector: string, value: string): Promise<void> {
  const input = el<HTMLInputElement>(selector)
  input.value = value
  input.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function choose(selector: string, value: string): Promise<void> {
  const select = el<HTMLSelectElement>(selector)
  select.value = value
  select.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function submitForm(): Promise<void> {
  el<HTMLFormElement>('form[data-testid="master-form"]').dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

beforeEach(() => {
  vi.mocked(masterService.meta).mockResolvedValue(G02)
  vi.mocked(masterService.options).mockReset()
  vi.mocked(masterService.options).mockImplementation(async (entity, parent) =>
    (OPTIONS[entity] ?? []).filter((o) => parent === undefined || parent === null || o.parent === parent),
  )
  vi.mocked(masterService.create).mockReset()
  vi.mocked(masterService.update).mockReset()
  vi.mocked(masterService.get).mockReset()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('MasterDataView — master G-02 (DBV-008/CR-026)', () => {
  it.each([
    ['unit', 'id_unit', 'unit', 'Unit Kerja', ['Sekretariat Kementerian', 'Deputi Bidang Sumber Daya dan Kelembagaan']],
    ['group-jabatan', 'id_group_jabatan', 'group_jabatan', 'Group Jabatan', ['Struktural', 'Fungsional']],
  ])('%s: urutan global — menu ⋮ lengkap dengan urutan & label baku, kolom Urutan tampil', async (entity, pk, nameField, label, names) => {
    const wrapper = await mountMaster(
      entity,
      names.map((nama, i) => ({ [pk]: String(i + 1), [nameField]: nama, order: i + 1, status: '1' })),
    )

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Urutan', 'Kode', label, 'Status', 'Aksi'])
    expect(await rowMenuActions(wrapper, 'master-actions-2')).toEqual([
      item('edit', 'Edit'),
      item('deactivate', 'Nonaktifkan'),
      item('up', 'Naikkan urutan'),
      item('down', 'Turunkan urutan', true),
      item('delete', 'Hapus', false, true),
    ])
    expect(wrapper.get('[data-testid="master-actions-2"]').attributes('aria-label')).toBe(`Aksi untuk ${names[1]}`)
    wrapper.unmount()
  })

  it.each([
    ['satker', 'id_satker', 'satker', 'Unit Kerja', 'Biro Umum', 'id_unit'],
    ['sub-group-jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 'Group Jabatan', 'Administrator', 'id_group_jabatan'],
  ])('%s: kolom induk; item urutan di menu ⋮ baru muncul setelah satu induk dipilih', async (entity, pk, nameField, parentLabel, name, parentField) => {
    const wrapper = await mountMaster(entity, [
      { [pk]: '1', [parentField]: '1', [nameField]: 'Entri Pertama', order: 1, status: '1', parent_nama: 'Induk' },
      { [pk]: '2', [parentField]: '1', [nameField]: name, order: 2, status: '2', parent_nama: 'Induk' },
    ])

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Urutan', 'Kode', entity === 'satker' ? 'Satuan Kerja' : 'Sub Group Jabatan', parentLabel, 'Status', 'Aksi'])
    expect(await rowMenuActions(wrapper, 'master-actions-2')).toEqual([item('edit', 'Edit'), item('activate', 'Aktifkan'), item('delete', 'Hapus', false, true)])
    expect(wrapper.get('[data-testid="master-reorder-hint"]').text()).toBe(`Pilih ${parentLabel} untuk mengubah urutan (urutan berlaku per induk).`)

    await wrapper.get(`select[aria-label="Filter ${parentLabel}"]`).setValue('1')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith(entity, expect.objectContaining({ parent: '1', page: 1 }))
    expect(await rowMenuActions(wrapper, 'master-actions-2')).toEqual([
      item('edit', 'Edit'),
      item('activate', 'Aktifkan'),
      item('up', 'Naikkan urutan'),
      item('down', 'Turunkan urutan', true),
      item('delete', 'Hapus', false, true),
    ])
    wrapper.unmount()
  })

  it('jabatan: tanpa urutan — tanpa kolom Urutan & item urutan; filter group, sub group, satker, kelas', async () => {
    const wrapper = await mountMaster('jabatan', [
      { id_jabatan: '1', id_group_jabatan: '1', id_sub_group_jabatan: '1', id_satker: '1', kelas_jabatan: '13', jabatan: 'Kepala Biro Sumber Daya Manusia', umur_pensiun: null, status: '1' },
      { id_jabatan: '3', id_group_jabatan: '2', id_sub_group_jabatan: '3', id_satker: null, kelas_jabatan: '9', jabatan: 'Analis SDM Aparatur', umur_pensiun: null, status: '1' },
    ])

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Kode', 'Jabatan', 'Status', 'Aksi'])
    expect(await rowMenuActions(wrapper, 'master-actions-1')).toEqual([item('edit', 'Edit'), item('deactivate', 'Nonaktifkan'), item('delete', 'Hapus', false, true)])
    expect(wrapper.find('[data-testid="master-reorder-hint"]').exists()).toBe(false)

    for (const name of ['id_group_jabatan', 'id_sub_group_jabatan', 'id_satker', 'kelas_jabatan']) {
      expect(wrapper.find(`[data-testid="master-filter-${name}"]`).exists()).toBe(true)
    }
    const kelasFilter = wrapper.get<HTMLSelectElement>('[data-testid="master-filter-kelas_jabatan"]')
    expect(Array.from(kelasFilter.element.options).map((o) => o.textContent)).toEqual(['Semua Kelas Jabatan', '7', '9'])

    await kelasFilter.setValue('9')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('jabatan', expect.objectContaining({ filters: { kelas_jabatan: '9' }, page: 1 }))
    // Filter tidak memunculkan urutan untuk master tanpa kolom order.
    expect(await rowMenuActions(wrapper, 'master-actions-3')).toEqual([item('edit', 'Edit'), item('deactivate', 'Nonaktifkan'), item('delete', 'Hapus', false, true)])
    wrapper.unmount()
  })

  it('kelas jabatan: tanpa urutan; kode = nama (nomor kelas) di tabel dan menu ⋮', async () => {
    const wrapper = await mountMaster('kelas-jabatan', [
      { kelas_jabatan: '7', tukin: '5079200', status: '1' },
      { kelas_jabatan: '9', tukin: '6335750', status: '10' },
    ])

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Kode', 'Kelas Jabatan', 'Status', 'Aksi'])
    expect(wrapper.get('[data-testid="master-actions-7"]').attributes('aria-label')).toBe('Aksi untuk 7')
    expect(await rowMenuActions(wrapper, 'master-actions-7')).toEqual([item('edit', 'Edit'), item('deactivate', 'Nonaktifkan'), item('delete', 'Hapus', false, true)])
    expect(await rowMenuActions(wrapper, 'master-actions-9')).toEqual([item('edit', 'Edit'), item('restore', 'Pulihkan')])
    wrapper.unmount()
  })
})

describe('MasterFormDialog — master G-02 (DBV-008/CR-026)', () => {
  it('satker: induk unit berjenjang, zonasi angka 0–120 wajib dengan keterangan WIB/WITA/WIT', async () => {
    const wrapper = mountForm(SATKER, null)
    await flushPromises()

    expect(masterService.options).toHaveBeenCalledWith('unit', null)
    const zonasi = el<HTMLInputElement>('input[name="zonasi"]')
    expect(zonasi.type).toBe('number')
    expect(document.body.textContent).toContain(ZONASI_HINT)

    await choose('select[name="id_unit"]', '1')
    await typeInto('input[name="satker"]', 'Biro Uji')
    await typeInto('input[name="zonasi"]', '121')
    await submitForm()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Zonasi Presensi (menit) maksimal 120.'))
    expect(masterService.create).not.toHaveBeenCalled()

    vi.mocked(masterService.create).mockResolvedValue({ id_satker: '9', id_unit: '1', satker: 'Biro Uji', zonasi: '60', status: '1' })
    await typeInto('input[name="zonasi"]', '60')
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalled())
    expect(masterService.create).toHaveBeenCalledWith('satker', expect.objectContaining({ id_unit: '1', satker: 'Biro Uji', zonasi: '60', is_upt: '0' }))
    wrapper.unmount()
  })

  it('kelas jabatan (tambah): satu input nomor kelas berlabel nama master, 1–20 tanpa nol di depan; tanpa input nama terpisah', async () => {
    const wrapper = mountForm(KELAS, null)
    await flushPromises()

    expect(document.body.querySelectorAll('input[name="kelas_jabatan"]')).toHaveLength(1)
    expect(el<HTMLLabelElement>(`label[for="${el<HTMLInputElement>('input[name="kelas_jabatan"]').id}"]`).textContent).toContain('Kelas Jabatan')
    expect(document.body.textContent).toContain('Bilangan bulat 1 sampai 20, tanpa nol di depan.')

    await typeInto('input[name="kelas_jabatan"]', '07')
    await typeInto('input[name="tukin"]', '17064000')
    await submitForm()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Kelas Jabatan harus bilangan bulat 1 sampai 20 (tanpa nol di depan).'))

    await typeInto('input[name="kelas_jabatan"]', '21')
    await submitForm()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Kelas Jabatan harus bilangan bulat 1 sampai 20.'))
    expect(masterService.create).not.toHaveBeenCalled()

    vi.mocked(masterService.create).mockResolvedValue({ kelas_jabatan: '15', tukin: '17064000', status: '1' })
    await typeInto('input[name="kelas_jabatan"]', '15')
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalled())
    expect(masterService.create).toHaveBeenCalledWith('kelas-jabatan', { kelas_jabatan: '15', tukin: '17064000' })
    wrapper.unmount()
  })

  it('kelas jabatan (edit): nomor kelas tidak bisa diubah — tanpa input kode/nama, payload membawa nomor yang sama', async () => {
    vi.mocked(masterService.update).mockResolvedValue({ kelas_jabatan: '9', tukin: '6400000', status: '1' })
    const wrapper = mountForm(KELAS, { kelas_jabatan: '9', tukin: '6335750', status: '1' })
    await flushPromises()

    expect(document.body.querySelector('input[name="kelas_jabatan"]')).toBeNull()
    expect(document.body.textContent).toContain('Kode 9 (kode tidak dapat diubah)')

    await typeInto('input[name="tukin"]', '6400000')
    await submitForm()
    await vi.waitFor(() => expect(masterService.update).toHaveBeenCalled())
    expect(masterService.update).toHaveBeenCalledWith('kelas-jabatan', '9', { kelas_jabatan: '9', tukin: '6400000' })
    wrapper.unmount()
  })

  it('jabatan: sub group terkunci sampai group dipilih, lalu dimuat per group; pilihan kelas cukup nomornya', async () => {
    const wrapper = mountForm(JABATAN, null)
    await flushPromises()

    const subGroup = el<HTMLSelectElement>('select[name="id_sub_group_jabatan"]')
    expect(subGroup.disabled).toBe(true)
    expect(masterService.options).not.toHaveBeenCalledWith('sub-group-jabatan', expect.anything())

    await choose('select[name="id_group_jabatan"]', '1')
    expect(masterService.options).toHaveBeenCalledWith('sub-group-jabatan', '1')
    expect(el<HTMLSelectElement>('select[name="id_sub_group_jabatan"]').disabled).toBe(false)
    expect(Array.from(el<HTMLSelectElement>('select[name="id_sub_group_jabatan"]').options).map((o) => o.textContent)).toEqual([
      'Pilih Sub Group Jabatan',
      'Pimpinan Tinggi Pratama (1)',
      'Administrator (2)',
    ])
    expect(Array.from(el<HTMLSelectElement>('select[name="kelas_jabatan"]').options).map((o) => o.textContent)).toEqual(['Pilih Kelas Jabatan', '7', '9'])
    wrapper.unmount()
  })
})
