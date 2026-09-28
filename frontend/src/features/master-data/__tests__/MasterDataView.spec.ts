/**
 * Halaman Master Data — keterangan dialog hapus (DBV-002 U3, CR-003). Penyaringan rantai status untuk tampilan
 * pegawai hanya ada di FAQ, jadi kalimat "ikut tersembunyi" hanya untuk master FAQ yang punya turunan; master lain
 * berinduk (mis. wilayah) memakai kalimat netral, master tanpa turunan tanpa kalimat tambahan.
 * CR-015: semua aksi baris (edit, nonaktifkan/aktifkan, naik/turun urutan, pulihkan, hapus) lewat menu ⋮
 * (RowActionsMenu, testid `master-actions-<id>`); kolom Status hanya badge.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/master.service', () => ({
  masterService: { meta: vi.fn(), list: vi.fn(), options: vi.fn(), remove: vi.fn(), reorder: vi.fn(), setStatus: vi.fn() },
}))

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import { masterService } from '../services/master.service'
import type { MasterMeta, MasterRow } from '../types'
import MasterDataView from '../views/MasterDataView.vue'

function metaOf(key: string, label: string, parent: MasterMeta['parent'] = null): MasterMeta {
  return {
    key,
    label,
    primary_key: 'kode',
    id_max_length: 10,
    id_digits: null,
    auto_increment: false,
    name_field: 'nama',
    name_label: 'Nama',
    name_max_length: 100,
    has_order: false,
    has_status: true,
    parent,
    fields: [],
  }
}

const metas: MasterMeta[] = [
  metaOf('provinsi', 'Provinsi'),
  metaOf('kabupaten-kota', 'Kabupaten/Kota', { field: 'id_provinsi', entity: 'provinsi' }),
  metaOf('faq-topic', 'Topik FAQ'),
  metaOf('faq-sub-topic', 'Sub Topik FAQ', { field: 'id_faq_topic', entity: 'faq-topic' }),
  metaOf('faq-article', 'Artikel FAQ', { field: 'id_faq_sub_topic', entity: 'faq-sub-topic' }),
]

const BASE =
  'Data tidak dihapus permanen: statusnya menjadi Dihapus sehingga hilang dari daftar dan dropdown, sementara data pegawai/riwayat yang sudah memakainya tetap utuh. Bisa dipulihkan lewat filter status Dihapus.'

/** Mount halaman master `entity` (data dari mock masterService yang sedang aktif). */
async function mountMaster(entity: string) {
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

/** Key aksi yang tampil di menu ⋮ baris `id` (menu dibuka lalu ditutup lagi). */
async function actionKeys(wrapper: VueWrapper, id: string): Promise<string[]> {
  return (await rowMenuActions(wrapper, `master-actions-${id}`)).map((a) => a.key)
}

/** Buka halaman master `entity`, pilih Hapus di menu ⋮ baris pertama, kembalikan isi keterangan dialog konfirmasi. */
async function deleteDescriptionFor(entity: string): Promise<string> {
  const wrapper = await mountMaster(entity)

  await selectRowAction(wrapper, 'master-actions-1', 'delete')
  const dialog = document.body.querySelector('[role="alertdialog"]')
  if (!dialog) throw new Error('dialog konfirmasi hapus tidak tampil')
  const text = (dialog.textContent ?? '').replace(/\s+/g, ' ')
  wrapper.unmount()
  return text
}

beforeEach(() => {
  vi.mocked(masterService.meta).mockResolvedValue(metas)
  vi.mocked(masterService.list).mockResolvedValue({
    items: [{ kode: '1', nama: 'Contoh', status: '1' }],
    total: 1,
    page: 1,
    per_page: 20,
  })
  vi.mocked(masterService.options).mockResolvedValue([])
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('MasterDataView — keterangan hapus', () => {
  it.each([
    ['faq-topic', 'Sub Topik FAQ'],
    ['faq-sub-topic', 'Artikel FAQ'],
  ])('master FAQ berturunan (%s) → turunan ikut tersembunyi dari halaman FAQ pegawai', async (entity, children) => {
    const text = await deleteDescriptionFor(entity)

    expect(text).toContain(BASE)
    expect(text).toContain(
      `Status ${children} di bawahnya tidak ikut diubah, tetapi ikut tersembunyi dari halaman FAQ pegawai sampai entri ini dipulihkan.`,
    )
  })

  it('master non-FAQ berturunan (wilayah) → kalimat netral, tanpa klaim tersembunyi di halaman pegawai', async () => {
    const text = await deleteDescriptionFor('provinsi')

    expect(text).toContain(`${BASE} Status data turunan tidak ikut diubah.`)
    expect(text).not.toContain('tersembunyi')
    expect(text).not.toContain('FAQ')
  })

  it.each(['faq-article', 'kabupaten-kota'])('master tanpa turunan (%s) → hanya keterangan dasar', async (entity) => {
    const text = await deleteDescriptionFor(entity)

    expect(text).toContain(BASE)
    expect(text).not.toContain('turunan')
    expect(text).not.toContain('di bawahnya')
  })
})

describe('MasterDataView — mode urutan, lingkup urutan, filter field, rantai status (CR-009)', () => {
  const base = { ...metaOf('x', 'X'), has_order: true }
  const levelMeta: MasterMeta = {
    ...base,
    key: 'uji-level',
    label: 'Level Uji',
    order_mode: 'manual',
    order_max: 127,
    filters: ['kategori'],
    fields: [{ name: 'kategori', label: 'Kategori', type: 'select', required: true, options: [{ value: '1', label: 'CPNS' }, { value: '2', label: 'PNS' }], hint: null }],
  }
  const diklatMeta: MasterMeta = {
    ...base,
    key: 'uji-diklat',
    label: 'Diklat Uji',
    order_mode: 'shift',
    order_scope: ['jenis'],
    filters: ['jenis', 'aktif_sertifikasi'],
    fields: [
      { name: 'jenis', label: 'Jenis Diklat', type: 'select', required: true, options: [{ value: '1', label: 'Struktural' }, { value: '2', label: 'Teknis' }], hint: null },
      { name: 'aktif_sertifikasi', label: 'Sertifikasi', type: 'boolean', required: false, options: null, hint: null },
    ],
  }
  const bidangMeta: MasterMeta = { ...base, key: 'uji-bidang', label: 'Bidang Uji' }
  const jurusanMeta: MasterMeta = { ...base, key: 'uji-jurusan', label: 'Jurusan Uji', parent: { field: 'id_bidang', entity: 'uji-bidang' }, status_chain: true }
  const peminatanMeta: MasterMeta = { ...base, key: 'uji-peminatan', label: 'Peminatan Uji', parent: { field: 'id_jurusan', entity: 'uji-jurusan' }, status_chain: true }
  // order_scope tanpa filter lingkup (backend menolak konfigurasi ini; FE tetap tidak boleh menampilkan panah).
  const diklatCampurMeta: MasterMeta = { ...diklatMeta, key: 'uji-diklat-campur', label: 'Diklat Campur', filters: [] }
  const kantorMeta: MasterMeta = {
    ...base,
    key: 'uji-kantor',
    label: 'Kantor Uji',
    filters: ['id_provinsi'],
    fields: [{ name: 'id_provinsi', label: 'Provinsi', type: 'ref', required: false, options: null, hint: null, entity: 'provinsi' }],
  }

  async function mountView(
    entity: string,
    items: MasterRow[] = [
      { kode: '1', nama: 'Pertama', order: 1, status: '1' },
      { kode: '2', nama: 'Kedua', order: 2, status: '1' },
    ],
  ) {
    vi.mocked(masterService.meta).mockResolvedValue([levelMeta, diklatMeta, bidangMeta, jurusanMeta, peminatanMeta, diklatCampurMeta, kantorMeta])
    vi.mocked(masterService.list).mockResolvedValue({ items, total: items.length, page: 1, per_page: 20 })
    return mountMaster(entity)
  }

  it('mode manual: menu ⋮ tanpa Naikkan/Turunkan urutan, keterangan menyuruh ubah lewat Edit', async () => {
    const wrapper = await mountView('uji-level')

    for (const id of ['1', '2']) {
      const keys = await actionKeys(wrapper, id)
      expect(keys).toContain('edit')
      expect(keys).not.toContain('up')
      expect(keys).not.toContain('down')
    }
    expect(wrapper.get('[data-testid="master-reorder-hint"]').text()).toBe(
      'Urutan Level Uji adalah nilai tetap (mis. level): ubah lewat Edit; entri lain tidak bergeser.',
    )
    wrapper.unmount()
  })

  it('order_scope: naik/turun di menu ⋮ hanya saat satu jenis dipilih dan filter lain kosong; filter terkirim ke daftar', async () => {
    const wrapper = await mountView('uji-diklat')

    expect(await actionKeys(wrapper, '1')).not.toContain('down')
    expect(await actionKeys(wrapper, '1')).not.toContain('up')
    expect(wrapper.get('[data-testid="master-reorder-hint"]').text()).toBe(
      'Pilih Jenis Diklat dan kosongkan filter Sertifikasi untuk mengubah urutan (urutan berlaku per Jenis Diklat).',
    )

    await wrapper.get('[data-testid="master-filter-jenis"]').setValue('2')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('uji-diklat', expect.objectContaining({ filters: { jenis: '2' }, page: 1 }))
    expect(wrapper.find('[data-testid="master-reorder-hint"]').exists()).toBe(false)

    // Baris pertama tidak bisa naik, baris terakhir tidak bisa turun (item tampil tetapi nonaktif).
    const first = await rowMenuActions(wrapper, 'master-actions-1')
    expect(first.filter((a) => a.key === 'up' || a.key === 'down')).toEqual([
      { key: 'up', label: 'Naikkan urutan', disabled: true, danger: false },
      { key: 'down', label: 'Turunkan urutan', disabled: false, danger: false },
    ])
    const last = await rowMenuActions(wrapper, 'master-actions-2')
    expect(last.filter((a) => a.key === 'up' || a.key === 'down').map((a) => [a.key, a.disabled])).toEqual([
      ['up', false],
      ['down', true],
    ])

    await selectRowAction(wrapper, 'master-actions-1', 'up')
    expect(masterService.reorder).not.toHaveBeenCalled()

    vi.mocked(masterService.reorder).mockResolvedValue({ kode: '1', order: 2 })
    await selectRowAction(wrapper, 'master-actions-1', 'down')
    expect(masterService.reorder).toHaveBeenCalledWith('uji-diklat', '1', 2)
    expect(masterService.reorder).toHaveBeenCalledTimes(1)

    // Filter di luar lingkup urutan membuat daftar tidak utuh → panah disembunyikan lagi.
    await wrapper.get('[data-testid="master-filter-aktif_sertifikasi"]').setValue('1')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('uji-diklat', expect.objectContaining({ filters: { jenis: '2', aktif_sertifikasi: '1' } }))
    expect(await actionKeys(wrapper, '1')).not.toContain('down')
    expect(await actionKeys(wrapper, '1')).not.toContain('up')
    expect(
      Array.from(wrapper.get<HTMLSelectElement>('[data-testid="master-filter-aktif_sertifikasi"]').element.options).map((o) => o.textContent),
    ).toEqual(['Semua Sertifikasi', 'Ya', 'Tidak'])
    wrapper.unmount()
  })

  it('order_scope yang tidak ada di filter: daftar mencampur lingkup → panah tidak pernah tampil', async () => {
    // Daftar gabungan dua jenis: posisi baris (B1 = baris ke-3) ≠ posisi di jenisnya (1).
    const wrapper = await mountView('uji-diklat-campur', [
      { kode: '1', nama: 'A1', jenis: '1', order: 1, status: '1' },
      { kode: '2', nama: 'A2', jenis: '1', order: 2, status: '1' },
      { kode: '3', nama: 'B1', jenis: '2', order: 1, status: '1' },
    ])

    expect(wrapper.find('[data-testid="master-row-3"]').exists()).toBe(true)
    for (const id of ['1', '2', '3']) {
      const keys = await actionKeys(wrapper, id)
      expect(keys).toContain('edit')
      expect(keys).not.toContain('up')
      expect(keys).not.toContain('down')
    }
    expect(wrapper.get('[data-testid="master-reorder-hint"]').text()).toBe(
      'Urutan berlaku per Jenis Diklat dan daftar ini tidak bisa disaring per lingkup itu: ubah urutan lewat Edit.',
    )
    expect(masterService.reorder).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('filter field ref: pilihan dimuat dari {entity}/options dan nilai terpilih terkirim ke daftar', async () => {
    vi.mocked(masterService.options).mockImplementation(async (entity) =>
      entity === 'provinsi'
        ? [
            { id: '31', nama: 'DKI Jakarta', parent: null },
            { id: '32', nama: 'Jawa Barat', parent: null },
          ]
        : [],
    )
    const wrapper = await mountView('uji-kantor')

    expect(masterService.options).toHaveBeenCalledWith('provinsi')
    const select = wrapper.get<HTMLSelectElement>('[data-testid="master-filter-id_provinsi"]')
    expect(Array.from(select.element.options).map((o) => [o.value, o.textContent])).toEqual([
      ['', 'Semua Provinsi'],
      ['31', 'DKI Jakarta'],
      ['32', 'Jawa Barat'],
    ])

    await select.setValue('32')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('uji-kantor', expect.objectContaining({ filters: { id_provinsi: '32' }, page: 1 }))
    wrapper.unmount()
  })

  it('hapus induk dari master ber-status_chain: turunan (semua level) disebut ikut tersembunyi dari dropdown', async () => {
    const wrapper = await mountView('uji-bidang')
    await selectRowAction(wrapper, 'master-actions-1', 'delete')
    const text = (document.body.querySelector('[role="alertdialog"]')?.textContent ?? '').replace(/\s+/g, ' ')

    expect(text).toContain(
      `${BASE} Status Jurusan Uji, Peminatan Uji di bawahnya tidak ikut diubah, tetapi ikut tersembunyi dari dropdown sampai entri ini dipulihkan.`,
    )
    expect(text).not.toContain('FAQ')
    wrapper.unmount()
  })
})

describe('MasterDataView — menu aksi baris ⋮ per status (CR-015)', () => {
  const rows: MasterRow[] = [
    { kode: '1', nama: 'Aktif', status: '1' },
    { kode: '2', nama: 'Nonaktif', status: '2' },
    { kode: '3', nama: 'Terhapus', status: '10' },
  ]

  /** Master Provinsi (tanpa urutan): tiap baris mewakili satu status legacy 1 / 2 / 10. */
  async function mountStatuses() {
    vi.mocked(masterService.list).mockResolvedValue({ items: rows.map((r) => ({ ...r })), total: rows.length, page: 1, per_page: 20 })
    return mountMaster('provinsi')
  }

  it('isi menu mengikuti status: Aktif → Nonaktifkan, Tidak Aktif → Aktifkan, Dihapus → Pulihkan tanpa Hapus', async () => {
    const wrapper = await mountStatuses()

    expect(await rowMenuActions(wrapper, 'master-actions-1')).toEqual([
      { key: 'edit', label: 'Edit', disabled: false, danger: false },
      { key: 'deactivate', label: 'Nonaktifkan', disabled: false, danger: false },
      { key: 'delete', label: 'Hapus', disabled: false, danger: true },
    ])
    expect(await actionKeys(wrapper, '2')).toEqual(['edit', 'activate', 'delete'])
    expect(await actionKeys(wrapper, '3')).toEqual(['edit', 'restore'])
    wrapper.unmount()
  })

  it('kolom Status hanya badge (tanpa switch); satu tombol ⋮ per baris dengan label nama baris', async () => {
    const wrapper = await mountStatuses()

    for (const [id, status] of [['1', '1'], ['2', '2'], ['3', '10']]) {
      const row = wrapper.get(`[data-testid="master-row-${id}"]`)
      expect(row.find('[role="switch"]').exists()).toBe(false)
      expect(row.get('[data-status]').attributes('data-status')).toBe(status)
      expect(row.findAll('button').map((b) => b.attributes('data-testid'))).toEqual([`master-actions-${id}`])
    }
    expect(wrapper.get('[data-testid="master-actions-3"]').attributes('aria-label')).toBe('Aksi untuk Terhapus')
    wrapper.unmount()
  })

  it('Nonaktifkan / Aktifkan lewat menu memanggil setStatus dan memperbarui badge serta isi menu', async () => {
    const wrapper = await mountStatuses()
    vi.mocked(masterService.setStatus).mockResolvedValueOnce({ kode: '1', status: '2' }).mockResolvedValueOnce({ kode: '2', status: '1' })

    await selectRowAction(wrapper, 'master-actions-1', 'deactivate')
    expect(masterService.setStatus).toHaveBeenLastCalledWith('provinsi', '1', '2')
    expect(wrapper.get('[data-testid="master-row-1"] [data-status]').attributes('data-status')).toBe('2')
    expect(wrapper.get('p[role="status"]').text()).toBe('"Aktif" dinonaktifkan dan tidak lagi muncul di dropdown.')
    expect(await actionKeys(wrapper, '1')).toEqual(['edit', 'activate', 'delete'])

    await selectRowAction(wrapper, 'master-actions-2', 'activate')
    expect(masterService.setStatus).toHaveBeenLastCalledWith('provinsi', '2', '1')
    expect(wrapper.get('[data-testid="master-row-2"] [data-status]').attributes('data-status')).toBe('1')
    expect(masterService.setStatus).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('Pulihkan (hanya baris Dihapus) mengembalikan status Aktif lalu memuat ulang daftar', async () => {
    const wrapper = await mountStatuses()
    vi.mocked(masterService.setStatus).mockResolvedValue({ kode: '3', status: '1' })
    const loadsBefore = vi.mocked(masterService.list).mock.calls.length

    await selectRowAction(wrapper, 'master-actions-3', 'restore')

    expect(masterService.setStatus).toHaveBeenCalledWith('provinsi', '3', '1')
    expect(masterService.list).toHaveBeenCalledTimes(loadsBefore + 1)
    expect(wrapper.get('p[role="status"]').text()).toBe('"Terhapus" dipulihkan dan kembali aktif.')
    wrapper.unmount()
  })

  it('selama perubahan status berjalan, aksi status/pulihkan di semua baris nonaktif (cegah klik ganda)', async () => {
    const wrapper = await mountStatuses()
    let finish: (row: MasterRow) => void = () => {}
    vi.mocked(masterService.setStatus).mockReturnValueOnce(new Promise<MasterRow>((resolve) => (finish = resolve)))

    await selectRowAction(wrapper, 'master-actions-1', 'deactivate')
    const disabledOf = async (id: string) => Object.fromEntries((await rowMenuActions(wrapper, `master-actions-${id}`)).map((a) => [a.key, a.disabled]))
    expect(await disabledOf('2')).toEqual({ edit: false, activate: true, delete: false })
    expect(await disabledOf('3')).toEqual({ edit: false, restore: true })

    finish({ kode: '1', status: '2' })
    await flushPromises()
    expect(await disabledOf('2')).toEqual({ edit: false, activate: false, delete: false })
    wrapper.unmount()
  })
})
