/**
 * Halaman Master Data — keterangan dialog hapus (DBV-002 U3, CR-003). Penyaringan rantai status untuk tampilan
 * pegawai hanya ada di FAQ, jadi kalimat "ikut tersembunyi" hanya untuk master FAQ yang punya turunan; master lain
 * berinduk (mis. wilayah) memakai kalimat netral, master tanpa turunan tanpa kalimat tambahan.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/master.service', () => ({
  masterService: { meta: vi.fn(), list: vi.fn(), options: vi.fn(), remove: vi.fn(), reorder: vi.fn() },
}))

import { masterService } from '../services/master.service'
import type { MasterMeta } from '../types'
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

/** Buka halaman master `entity`, klik Hapus pada baris pertama, kembalikan isi keterangan dialog konfirmasi. */
async function deleteDescriptionFor(entity: string): Promise<string> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/master/:entity?', name: 'master-data', component: MasterDataView }],
  })
  await router.push(`/master/${entity}`)
  await router.isReady()
  const wrapper = mount(MasterDataView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()

  await wrapper.get('[data-testid="master-row-1"] button[title="Hapus"]').trigger('click')
  await flushPromises()
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

  async function mountView(entity: string) {
    vi.mocked(masterService.meta).mockResolvedValue([levelMeta, diklatMeta, bidangMeta, jurusanMeta, peminatanMeta])
    vi.mocked(masterService.list).mockResolvedValue({
      items: [
        { kode: '1', nama: 'Pertama', order: 1, status: '1' },
        { kode: '2', nama: 'Kedua', order: 2, status: '1' },
      ],
      total: 2,
      page: 1,
      per_page: 20,
    })
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

  it('mode manual: tanpa panah naik/turun, keterangan menyuruh ubah lewat Edit', async () => {
    const wrapper = await mountView('uji-level')

    expect(wrapper.find('button[title="Naikkan urutan"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="master-reorder-hint"]').text()).toBe(
      'Urutan Level Uji adalah nilai tetap (mis. level): ubah lewat Edit; entri lain tidak bergeser.',
    )
    wrapper.unmount()
  })

  it('order_scope: panah hanya saat satu jenis dipilih dan filter lain kosong; filter terkirim ke daftar', async () => {
    const wrapper = await mountView('uji-diklat')

    expect(wrapper.find('button[title="Turunkan urutan"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="master-reorder-hint"]').text()).toBe(
      'Pilih Jenis Diklat dan kosongkan filter Sertifikasi untuk mengubah urutan (urutan berlaku per Jenis Diklat).',
    )

    await wrapper.get('[data-testid="master-filter-jenis"]').setValue('2')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('uji-diklat', expect.objectContaining({ filters: { jenis: '2' }, page: 1 }))
    expect(wrapper.find('[data-testid="master-reorder-hint"]').exists()).toBe(false)

    vi.mocked(masterService.reorder).mockResolvedValue({ kode: '1', order: 2 })
    await wrapper.get('[data-testid="master-row-1"] button[title="Turunkan urutan"]').trigger('click')
    await flushPromises()
    expect(masterService.reorder).toHaveBeenCalledWith('uji-diklat', '1', 2)

    // Filter di luar lingkup urutan membuat daftar tidak utuh → panah disembunyikan lagi.
    await wrapper.get('[data-testid="master-filter-aktif_sertifikasi"]').setValue('1')
    await flushPromises()
    expect(masterService.list).toHaveBeenLastCalledWith('uji-diklat', expect.objectContaining({ filters: { jenis: '2', aktif_sertifikasi: '1' } }))
    expect(wrapper.find('button[title="Turunkan urutan"]').exists()).toBe(false)
    expect(
      Array.from(wrapper.get<HTMLSelectElement>('[data-testid="master-filter-aktif_sertifikasi"]').element.options).map((o) => o.textContent),
    ).toEqual(['Semua Sertifikasi', 'Ya', 'Tidak'])
    wrapper.unmount()
  })

  it('hapus induk dari master ber-status_chain: turunan (semua level) disebut ikut tersembunyi dari dropdown', async () => {
    const wrapper = await mountView('uji-bidang')
    await wrapper.get('[data-testid="master-row-1"] button[title="Hapus"]').trigger('click')
    await flushPromises()
    const text = (document.body.querySelector('[role="alertdialog"]')?.textContent ?? '').replace(/\s+/g, ' ')

    expect(text).toContain(
      `${BASE} Status Jurusan Uji, Peminatan Uji di bawahnya tidak ikut diubah, tetapi ikut tersembunyi dari dropdown sampai entri ini dipulihkan.`,
    )
    expect(text).not.toContain('FAQ')
    wrapper.unmount()
  })
})
