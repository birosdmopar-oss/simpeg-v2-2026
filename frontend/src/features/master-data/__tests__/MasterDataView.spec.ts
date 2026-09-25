/**
 * Halaman Master Data — keterangan dialog hapus (DBV-002 U3, CR-003). Penyaringan rantai status untuk tampilan
 * pegawai hanya ada di FAQ, jadi kalimat "ikut tersembunyi" hanya untuk master FAQ yang punya turunan; master lain
 * berinduk (mis. wilayah) memakai kalimat netral, master tanpa turunan tanpa kalimat tambahan.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/master.service', () => ({
  masterService: { meta: vi.fn(), list: vi.fn(), options: vi.fn(), remove: vi.fn() },
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
