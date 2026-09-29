/**
 * Master G-06 (DBV-005/CR-012) di halaman Master Data generik. Meta di bawah mengikuti bentuk GET /master/meta backend
 * untuk kelima master (dikunci `DiklatHukdisKonketTest::testMetaDescribesG06Masters`). AGENTS.md bagian 1: semua aksi
 * baris lewat menu ⋮ (`master-actions-<id>`, urutan & label baku, Hapus paling bawah bertanda bahaya), kolom Status
 * hanya badge. Item urutan hanya tersedia saat daftar menampilkan satu lingkup urutan utuh: `diklat` per jenis
 * pelatihan, `jenis-hukdis` per tingkat, tiga master lainnya global.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/master.service', () => ({
  masterService: { meta: vi.fn(), list: vi.fn(), options: vi.fn(), remove: vi.fn(), reorder: vi.fn(), setStatus: vi.fn() },
}))

import { rowMenuActions, selectRowAction, type RenderedRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import { masterService } from '../services/master.service'
import type { MasterFieldMeta, MasterMeta, MasterRow } from '../types'
import MasterDataView from '../views/MasterDataView.vue'

function g06Meta(key: string, label: string, primaryKey: string, nameField: string, nameMaxLength: number, extra: Partial<MasterMeta> = {}): MasterMeta {
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
    ...extra,
  }
}

function field(name: string, label: string, type: MasterFieldMeta['type'], required: boolean, extra: Partial<MasterFieldMeta> = {}): MasterFieldMeta {
  return { name, label, type, required, options: null, hint: null, max_bytes: null, min: null, max: null, entity: null, depends_on: null, ...extra }
}

const JENIS_DIKLAT = [
  { value: '1', label: 'Struktural' },
  { value: '2', label: 'Teknis' },
  { value: '3', label: 'Fungsional' },
  { value: '4', label: 'Prajabatan' },
  { value: '5', label: 'Sertifikasi' },
]

const G06: MasterMeta[] = [
  g06Meta('diklat', 'Pelatihan', 'id_diklat', 'nama_diklat', 255, {
    name_label: 'Nama Pelatihan',
    id_max_length: 3,
    fields: [field('jenis_diklat', 'Jenis Pelatihan', 'select', true, { options: JENIS_DIKLAT })],
    order_scope: ['jenis_diklat'],
    filters: ['jenis_diklat'],
  }),
  g06Meta('tingkat-hukdis', 'Tingkat Hukuman Disiplin', 'id_tingkat_hukdis', 'tingkat_hukdis', 100),
  g06Meta('jenis-hukdis', 'Jenis Hukuman Disiplin', 'id_jenis_hukdis', 'jenis_hukdis', 255, {
    parent: { field: 'id_tingkat_hukdis', entity: 'tingkat-hukdis' },
    status_chain: true,
    fields: [field('masa_sanksi_bulan', 'Masa Sanksi (bulan)', 'int', false, { min: 1, max: 255 })],
  }),
  g06Meta('jenis-konket', 'Jenis Konfirmasi Ketidakhadiran', 'id_jenis_konket', 'jenis_konket', 255, {
    fields: [
      field('old_id', 'Kode Kategori', 'int', true, { min: 1, max: 2147483647 }),
      field('affect_tukin', 'Pengaruh ke Tukin', 'select', true, { options: [{ value: '1', label: 'Ya' }, { value: '2', label: 'Tidak' }] }),
    ],
  }),
  g06Meta('tanda-jasa', 'Tanda Jasa', 'id_tanda_jasa', 'tanda_jasa', 255),
]

const BASE =
  'Data tidak dihapus permanen: statusnya menjadi Dihapus sehingga hilang dari daftar dan dropdown, sementara data pegawai/riwayat yang sudah memakainya tetap utuh. Bisa dipulihkan lewat filter status Dihapus.'

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

async function actionKeys(wrapper: VueWrapper, id: string): Promise<string[]> {
  return (await rowMenuActions(wrapper, `master-actions-${id}`)).map((a) => a.key)
}

beforeEach(() => {
  vi.mocked(masterService.meta).mockResolvedValue(G06)
  vi.mocked(masterService.options).mockImplementation(async (entity) =>
    entity === 'tingkat-hukdis'
      ? [
          { id: '1', nama: 'Ringan', parent: null },
          { id: '2', nama: 'Sedang', parent: null },
        ]
      : [],
  )
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('MasterDataView — master G-06 (DBV-005/CR-012)', () => {
  it('diklat: item Naikkan/Turunkan urutan di menu ⋮ baru muncul setelah satu jenis pelatihan dipilih', async () => {
    const wrapper = await mountMaster('diklat', [
      { id_diklat: '1', jenis_diklat: '1', nama_diklat: 'Diklatpim Tingkat II', order: 1, status: '1' },
      { id_diklat: '2', jenis_diklat: '1', nama_diklat: 'Diklatpim Tingkat III', order: 2, status: '1' },
    ])

    // Daftar tanpa filter mencampur jenis → tidak ada item urutan; urutan item lain tetap baku.
    expect(await rowMenuActions(wrapper, 'master-actions-1')).toEqual([item('edit', 'Edit'), item('deactivate', 'Nonaktifkan'), item('delete', 'Hapus', false, true)])
    expect(wrapper.get('[data-testid="master-reorder-hint"]').text()).toBe('Pilih Jenis Pelatihan untuk mengubah urutan (urutan berlaku per Jenis Pelatihan).')
    const filter = wrapper.get<HTMLSelectElement>('[data-testid="master-filter-jenis_diklat"]')
    expect(Array.from(filter.element.options).map((o) => o.textContent)).toEqual([
      'Semua Jenis Pelatihan',
      'Struktural',
      'Teknis',
      'Fungsional',
      'Prajabatan',
      'Sertifikasi',
    ])

    await filter.setValue('1')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('diklat', expect.objectContaining({ filters: { jenis_diklat: '1' }, page: 1 }))
    expect(wrapper.find('[data-testid="master-reorder-hint"]').exists()).toBe(false)
    expect(await rowMenuActions(wrapper, 'master-actions-1')).toEqual([
      item('edit', 'Edit'),
      item('deactivate', 'Nonaktifkan'),
      item('up', 'Naikkan urutan', true),
      item('down', 'Turunkan urutan'),
      item('delete', 'Hapus', false, true),
    ])

    vi.mocked(masterService.reorder).mockResolvedValue({ id_diklat: '1', order: 2 })
    await selectRowAction(wrapper, 'master-actions-1', 'down')
    expect(masterService.reorder).toHaveBeenCalledWith('diklat', '1', 2)
    wrapper.unmount()
  })

  it('jenis hukdis: kolom induk tingkat; item urutan di menu ⋮ baru muncul setelah satu tingkat dipilih', async () => {
    const wrapper = await mountMaster('jenis-hukdis', [
      { id_jenis_hukdis: '1', id_tingkat_hukdis: '1', jenis_hukdis: 'Teguran lisan', masa_sanksi_bulan: null, order: 1, status: '1', parent_nama: 'Ringan' },
      { id_jenis_hukdis: '2', id_tingkat_hukdis: '1', jenis_hukdis: 'Teguran tertulis', masa_sanksi_bulan: 6, order: 2, status: '1', parent_nama: 'Ringan' },
    ])

    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Urutan', 'Kode', 'Jenis Hukuman Disiplin', 'Tingkat Hukuman Disiplin', 'Status', 'Aksi'])
    expect(wrapper.get('[data-testid="master-row-2"]').text()).toContain('Ringan')
    expect(await actionKeys(wrapper, '2')).toEqual(['edit', 'deactivate', 'delete'])
    expect(wrapper.get('[data-testid="master-reorder-hint"]').text()).toBe('Pilih Tingkat Hukuman Disiplin untuk mengubah urutan (urutan berlaku per induk).')

    await wrapper.get('select[aria-label="Filter Tingkat Hukuman Disiplin"]').setValue('1')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('jenis-hukdis', expect.objectContaining({ parent: '1', page: 1 }))
    expect(await rowMenuActions(wrapper, 'master-actions-2')).toEqual([
      item('edit', 'Edit'),
      item('deactivate', 'Nonaktifkan'),
      item('up', 'Naikkan urutan'),
      item('down', 'Turunkan urutan', true),
      item('delete', 'Hapus', false, true),
    ])
    wrapper.unmount()
  })

  it('tingkat hukdis: Hapus lewat menu ⋮ membuka konfirmasi yang menyebut jenis hukdis ikut tersembunyi dari dropdown', async () => {
    const wrapper = await mountMaster('tingkat-hukdis', [{ id_tingkat_hukdis: '1', tingkat_hukdis: 'Ringan', order: 1, status: '1' }])

    await selectRowAction(wrapper, 'master-actions-1', 'delete')
    const text = (document.body.querySelector('[role="alertdialog"]')?.textContent ?? '').replace(/\s+/g, ' ')
    expect(text).toContain('Hapus Tingkat Hukuman Disiplin "Ringan"?')
    expect(text).toContain(
      `${BASE} Status Jenis Hukuman Disiplin di bawahnya tidak ikut diubah, tetapi ikut tersembunyi dari dropdown sampai entri ini dipulihkan.`,
    )
    expect(masterService.remove).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it.each([
    ['jenis-konket', 'id_jenis_konket', 'jenis_konket', ['Dinas', 'Sakit', 'Lain-lain']],
    ['tanda-jasa', 'id_tanda_jasa', 'tanda_jasa', ['Satyalancana Karya Satya XXX Tahun', 'Satyalancana Karya Satya XX Tahun', 'LAIN-LAIN']],
  ])('%s: urutan global — menu ⋮ lengkap dengan urutan & label baku, kolom Status hanya badge', async (entity, pk, nameField, names) => {
    const wrapper = await mountMaster(
      entity,
      names.map((nama, i) => ({ [pk]: String(i + 1), [nameField]: nama, order: i + 1, status: i === 2 ? '2' : '1' })),
    )

    expect(await rowMenuActions(wrapper, 'master-actions-1')).toEqual([
      item('edit', 'Edit'),
      item('deactivate', 'Nonaktifkan'),
      item('up', 'Naikkan urutan', true),
      item('down', 'Turunkan urutan'),
      item('delete', 'Hapus', false, true),
    ])
    expect(await rowMenuActions(wrapper, 'master-actions-3')).toEqual([
      item('edit', 'Edit'),
      item('activate', 'Aktifkan'),
      item('up', 'Naikkan urutan'),
      item('down', 'Turunkan urutan', true),
      item('delete', 'Hapus', false, true),
    ])
    for (const [id, status] of [['1', '1'], ['2', '1'], ['3', '2']]) {
      const row = wrapper.get(`[data-testid="master-row-${id}"]`)
      expect(row.find('[role="switch"]').exists()).toBe(false)
      expect(row.get('[data-status]').attributes('data-status')).toBe(status)
      expect(row.findAll('button').map((b) => b.attributes('data-testid'))).toEqual([`master-actions-${id}`])
    }
    expect(wrapper.get('[data-testid="master-actions-3"]').attributes('aria-label')).toBe(`Aksi untuk ${names[2]}`)
    expect(wrapper.find('[data-testid="master-reorder-hint"]').exists()).toBe(false)

    vi.mocked(masterService.setStatus).mockResolvedValue({ [pk]: '3', status: '1' })
    await selectRowAction(wrapper, 'master-actions-3', 'activate')
    expect(masterService.setStatus).toHaveBeenCalledWith(entity, '3', '1')
    expect(wrapper.get('[data-testid="master-row-3"] [data-status]').attributes('data-status')).toBe('1')
    wrapper.unmount()
  })
})
