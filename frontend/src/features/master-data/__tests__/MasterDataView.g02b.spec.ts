/**
 * Master G-02 sisa (DBV-018/CR-032) di halaman Master Data generik dan form master generik. Meta di bawah mengikuti bentuk
 * GET /master/meta backend untuk keempat master (dikunci `JabatanSisaTest::testMetaDescribesG02bMasters`).
 * AGENTS.md bagian 1: semua aksi baris lewat menu ⋮ (`master-actions-<id>`, urutan & label baku, Hapus paling bawah
 * bertanda bahaya), kolom Status hanya badge. Item urutan hanya untuk master ber-`order` dan hanya saat satu lingkup utuh
 * tampil: rumpun global, sub rumpun per rumpun; jabatan akademik & periode struktur tanpa urutan.
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

function g02bMeta(key: string, label: string, primaryKey: string, nameField: string, nameLabel: string, nameMaxLength: number, extra: Partial<MasterMeta> = {}): MasterMeta {
  return {
    key,
    label,
    primary_key: primaryKey,
    id_max_length: 11,
    id_digits: null,
    auto_increment: true,
    name_field: nameField,
    name_label: nameLabel,
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

const RUMPUN = g02bMeta('rumpun-jabatan', 'Rumpun Jabatan', 'id_rumpun_jabatan', 'rumpun_jabatan', 'Rumpun Jabatan', 50, { id_max_length: 3 })
const SUBRUMPUN = g02bMeta('subrumpun-jabatan', 'Sub Rumpun Jabatan', 'id_subrumpun_jabatan', 'subrumpun_jabatan', 'Sub Rumpun Jabatan', 255, {
  id_max_length: 3,
  parent: { field: 'id_rumpun_jabatan', entity: 'rumpun-jabatan' },
  status_chain: true,
})
const AKADEMIK = g02bMeta('jabatan-akademik', 'Jabatan Akademik', 'id_jabatan_akademik', 'jabatan_akademik', 'Jabatan Akademik', 255, {
  has_order: false,
  fields: [
    field('is_atasan', 'Jabatan Atasan', 'select', true, {
      options: [
        { value: '1', label: 'Ya' },
        { value: '2', label: 'Tidak' },
      ],
    }),
  ],
})
const PERIODE = g02bMeta('periode-struktur-jabatan', 'Periode Struktur Jabatan', 'id_periode_struktur_jabatan', 'periode_struktur_jabatan', 'Periode Struktur', 45, {
  has_order: false,
})

const G02B: MasterMeta[] = [RUMPUN, SUBRUMPUN, AKADEMIK, PERIODE]

const OPTIONS: Record<string, MasterOption[]> = {
  'rumpun-jabatan': [
    { id: '1', nama: 'Manajemen', parent: null },
    { id: '2', nama: 'Pariwisata', parent: null },
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
  vi.mocked(masterService.meta).mockResolvedValue(G02B)
  vi.mocked(masterService.options).mockReset()
  vi.mocked(masterService.options).mockImplementation(async (entity, parent) =>
    (OPTIONS[entity] ?? []).filter((o) => parent === undefined || parent === null || o.parent === parent),
  )
  vi.mocked(masterService.create).mockReset()
  vi.mocked(masterService.update).mockReset()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('MasterDataView — master G-02 sisa (DBV-018/CR-032)', () => {
  it('rumpun jabatan: urutan global — menu ⋮ lengkap dengan urutan & label baku, kolom Urutan tampil', async () => {
    const wrapper = await mountMaster('rumpun-jabatan', [
      { id_rumpun_jabatan: '1', rumpun_jabatan: 'Manajemen', order: 1, status: '1' },
      { id_rumpun_jabatan: '2', rumpun_jabatan: 'Pariwisata', order: 2, status: '1' },
    ])

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Urutan', 'Kode', 'Rumpun Jabatan', 'Status', 'Aksi'])
    expect(await rowMenuActions(wrapper, 'master-actions-2')).toEqual([
      item('edit', 'Edit'),
      item('deactivate', 'Nonaktifkan'),
      item('up', 'Naikkan urutan'),
      item('down', 'Turunkan urutan', true),
      item('delete', 'Hapus', false, true),
    ])
    expect(wrapper.get('[data-testid="master-actions-2"]').attributes('aria-label')).toBe('Aksi untuk Pariwisata')
    wrapper.unmount()
  })

  it('sub rumpun jabatan: kolom induk; item urutan di menu ⋮ baru muncul setelah satu rumpun dipilih', async () => {
    const wrapper = await mountMaster('subrumpun-jabatan', [
      { id_subrumpun_jabatan: '1', id_rumpun_jabatan: '1', subrumpun_jabatan: 'Manajemen Sumber Daya Manusia', order: 1, status: '1', parent_nama: 'Manajemen' },
      { id_subrumpun_jabatan: '2', id_rumpun_jabatan: '1', subrumpun_jabatan: 'Manajemen Keuangan', order: 2, status: '2', parent_nama: 'Manajemen' },
    ])

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Urutan', 'Kode', 'Sub Rumpun Jabatan', 'Rumpun Jabatan', 'Status', 'Aksi'])
    expect(await rowMenuActions(wrapper, 'master-actions-2')).toEqual([item('edit', 'Edit'), item('activate', 'Aktifkan'), item('delete', 'Hapus', false, true)])

    await wrapper.get('select[aria-label="Filter Rumpun Jabatan"]').setValue('1')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('subrumpun-jabatan', expect.objectContaining({ parent: '1', page: 1 }))
    expect(await rowMenuActions(wrapper, 'master-actions-2')).toEqual([
      item('edit', 'Edit'),
      item('activate', 'Aktifkan'),
      item('up', 'Naikkan urutan'),
      item('down', 'Turunkan urutan', true),
      item('delete', 'Hapus', false, true),
    ])
    wrapper.unmount()
  })

  it.each([
    ['jabatan-akademik', 'id_jabatan_akademik', 'jabatan_akademik', 'Jabatan Akademik', 'Lektor'],
    ['periode-struktur-jabatan', 'id_periode_struktur_jabatan', 'periode_struktur_jabatan', 'Periode Struktur', '2024'],
  ])('%s: tanpa urutan — tanpa kolom Urutan & item urutan; entri dihapus hanya bisa dipulihkan', async (entity, pk, nameField, nameLabel, name) => {
    const wrapper = await mountMaster(entity, [
      { [pk]: '1', [nameField]: name, status: '1' },
      { [pk]: '2', [nameField]: `${name} Lama`, status: '10' },
    ])

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Kode', nameLabel, 'Status', 'Aksi'])
    expect(wrapper.get('[data-testid="master-actions-1"]').attributes('aria-label')).toBe(`Aksi untuk ${name}`)
    expect(await rowMenuActions(wrapper, 'master-actions-1')).toEqual([item('edit', 'Edit'), item('deactivate', 'Nonaktifkan'), item('delete', 'Hapus', false, true)])
    expect(await rowMenuActions(wrapper, 'master-actions-2')).toEqual([item('edit', 'Edit'), item('restore', 'Pulihkan')])
    expect(wrapper.find('[data-testid="master-reorder-hint"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('MasterFormDialog — master G-02 sisa (DBV-018/CR-032)', () => {
  it('jabatan akademik: Jabatan Atasan wajib dipilih (Ya/Tidak) sebelum dikirim', async () => {
    const wrapper = mount(MasterFormDialog, { props: { open: true, meta: AKADEMIK, allMeta: G02B, row: null }, attachTo: document.body })
    await flushPromises()

    expect(Array.from(el<HTMLSelectElement>('select[name="is_atasan"]').options).map((o) => o.textContent)).toEqual(['Pilih Jabatan Atasan', 'Ya', 'Tidak'])

    await typeInto('input[name="jabatan_akademik"]', 'Lektor Kepala')
    await submitForm()
    await vi.waitFor(() => expect(document.body.textContent).toContain('Jabatan Atasan wajib dipilih.'))
    expect(masterService.create).not.toHaveBeenCalled()

    vi.mocked(masterService.create).mockResolvedValue({ id_jabatan_akademik: '4', jabatan_akademik: 'Lektor Kepala', is_atasan: '1', status: '1' })
    await choose('select[name="is_atasan"]', '1')
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalled())
    expect(masterService.create).toHaveBeenCalledWith('jabatan-akademik', expect.objectContaining({ jabatan_akademik: 'Lektor Kepala', is_atasan: '1' }))
    wrapper.unmount()
  })

  it('sub rumpun jabatan: rumpun induk dipilih dari dropdown rumpun aktif', async () => {
    const wrapper = mount(MasterFormDialog, { props: { open: true, meta: SUBRUMPUN, allMeta: G02B, row: null }, attachTo: document.body })
    await flushPromises()

    expect(masterService.options).toHaveBeenCalledWith('rumpun-jabatan', null)
    vi.mocked(masterService.create).mockResolvedValue({ id_subrumpun_jabatan: '5', id_rumpun_jabatan: '2', subrumpun_jabatan: 'Ekonomi Kreatif', status: '1' })
    await choose('select[name="id_rumpun_jabatan"]', '2')
    await typeInto('input[name="subrumpun_jabatan"]', 'Ekonomi Kreatif')
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalled())
    expect(masterService.create).toHaveBeenCalledWith('subrumpun-jabatan', expect.objectContaining({ id_rumpun_jabatan: '2', subrumpun_jabatan: 'Ekonomi Kreatif' }))
    wrapper.unmount()
  })
})
