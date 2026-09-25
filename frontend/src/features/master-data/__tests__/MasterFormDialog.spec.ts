/**
 * DBV-002 E3/E4 — form master generik dengan field `html` yang dikecualikan dari list admin (listExclude):
 * saat edit, isi diambil dari detail (GET master/{entity}/{id}) sebelum form boleh disimpan, agar isi artikel
 * tidak terkirim kosong / terhapus. CR-003: payload simpan (update/create) dipastikan membawa isi tersebut.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/master.service', () => ({
  masterService: { get: vi.fn(), update: vi.fn(), create: vi.fn(), options: vi.fn() },
}))

import MasterFormDialog from '../components/MasterFormDialog.vue'
import { masterService } from '../services/master.service'
import type { MasterMeta, MasterRow } from '../types'

const meta: MasterMeta = {
  key: 'faq-article',
  label: 'Artikel FAQ',
  primary_key: 'id_faq_article',
  id_max_length: 11,
  id_digits: null,
  auto_increment: true,
  name_field: 'title',
  name_label: 'Judul Artikel',
  name_max_length: 255,
  has_order: true,
  has_status: true,
  parent: null,
  fields: [{ name: 'content', label: 'Isi Artikel', type: 'html', required: true, options: null, hint: null }],
}

/** Baris list admin: tanpa `content` (listExclude). */
const listRow: MasterRow = { id_faq_article: 7, title: 'Lupa password', order: 1, status: '1' }

function mountDialog(row: MasterRow | null) {
  return mount(MasterFormDialog, {
    props: { open: true, meta, allMeta: [meta], row },
    attachTo: document.body,
  })
}

function submitButton(): HTMLButtonElement {
  const button = document.body.querySelector<HTMLButtonElement>('button[type="submit"]')
  if (!button) throw new Error('tombol simpan tidak ditemukan')
  return button
}

function contentTextarea(): HTMLTextAreaElement {
  const textarea = document.body.querySelector<HTMLTextAreaElement>('textarea[name="content"]')
  if (!textarea) throw new Error('textarea content tidak ditemukan')
  return textarea
}

function typeInto(selector: string, value: string): void {
  const el = document.body.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)
  if (!el) throw new Error(`${selector} tidak ditemukan`)
  el.value = value
  el.dispatchEvent(new Event('input'))
}

async function chooseOption(index: number, value: string): Promise<void> {
  const select = document.body.querySelectorAll<HTMLSelectElement>('select')[index]
  if (!select) throw new Error(`dropdown ke-${index} tidak ditemukan`)
  select.value = value
  select.dispatchEvent(new Event('change'))
  await flushPromises()
}

/** Kirim form. Validasi skema vee-validate berjalan async (debounce): pemanggil menunggu lewat vi.waitFor. */
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
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('MasterFormDialog — field html listExclude', () => {
  it('edit: isi dimuat dari detail; tombol Simpan terkunci selama memuat', async () => {
    let resolveGet: (row: MasterRow) => void = () => {}
    vi.mocked(masterService.get).mockImplementation(() => new Promise((resolve) => (resolveGet = resolve)))
    const wrapper = mountDialog(listRow)
    await flushPromises()

    expect(masterService.get).toHaveBeenCalledWith('faq-article', '7')
    expect(submitButton().disabled).toBe(true)
    expect(contentTextarea().disabled).toBe(true)

    resolveGet({ ...listRow, content: '<p>Buka menu Akun.</p>' })
    await flushPromises()

    expect(contentTextarea().value).toBe('<p>Buka menu Akun.</p>')
    expect(contentTextarea().disabled).toBe(false)
    expect(submitButton().disabled).toBe(false)
    wrapper.unmount()
  })

  it('edit: detail gagal dimuat → pesan error, form tidak bisa disimpan', async () => {
    vi.mocked(masterService.get).mockRejectedValue({
      status: 500,
      message: 'Server bermasalah.',
      errors: null,
      isNetworkError: false,
      original: new AxiosError('x'),
    })
    const wrapper = mountDialog(listRow)
    await flushPromises()

    expect(document.body.textContent).toContain('Gagal memuat data lengkap (Isi Artikel). Server bermasalah.')
    expect(submitButton().disabled).toBe(true)
    wrapper.unmount()
  })

  it('tambah: tidak memanggil detail; textarea html ≥ 10 baris', async () => {
    const wrapper = mountDialog(null)
    await flushPromises()

    expect(masterService.get).not.toHaveBeenCalled()
    expect(Number(contentTextarea().getAttribute('rows'))).toBeGreaterThanOrEqual(10)
    expect(submitButton().disabled).toBe(false)
    wrapper.unmount()
  })
})

describe('MasterFormDialog — payload simpan field html (CR-003)', () => {
  // Rantai seperti master FAQ sebenarnya: artikel berinduk sub topik → topik (dropdown berjenjang ikut dimuat).
  const topicMeta: MasterMeta = {
    ...meta,
    key: 'faq-topic',
    label: 'Topik FAQ',
    primary_key: 'id_faq_topic',
    name_field: 'faq_topic',
    name_label: 'Topik',
    fields: [],
  }
  const subTopicMeta: MasterMeta = {
    ...meta,
    key: 'faq-sub-topic',
    label: 'Sub Topik FAQ',
    primary_key: 'id_faq_sub_topic',
    name_field: 'faq_sub_topic',
    name_label: 'Sub Topik',
    parent: { field: 'id_faq_topic', entity: 'faq-topic' },
    fields: [],
  }
  const articleMeta: MasterMeta = { ...meta, parent: { field: 'id_faq_sub_topic', entity: 'faq-sub-topic' } }
  const allMeta = [topicMeta, subTopicMeta, articleMeta]

  const articleRow: MasterRow = { id_faq_article: 7, id_faq_sub_topic: 5, title: 'Lupa password', order: 1, status: '1' }

  function mountArticleDialog(row: MasterRow | null) {
    return mount(MasterFormDialog, { props: { open: true, meta: articleMeta, allMeta, row }, attachTo: document.body })
  }

  beforeEach(() => {
    vi.mocked(masterService.get).mockImplementation(async (entity, id): Promise<MasterRow> => {
      if (entity === 'faq-sub-topic') return { id_faq_sub_topic: id, id_faq_topic: 2, faq_sub_topic: 'Login' }
      return { ...articleRow, content: '<p>Buka menu Akun.</p>' }
    })
    vi.mocked(masterService.options).mockImplementation(async (entity) =>
      entity === 'faq-topic' ? [{ id: '2', nama: 'Akun', parent: null }] : [{ id: '5', nama: 'Login', parent: '2' }],
    )
    vi.mocked(masterService.update).mockImplementation(async (_entity, _id, payload) => ({ ...articleRow, ...payload }))
    vi.mocked(masterService.create).mockImplementation(async (_entity, payload) => ({ id_faq_article: 8, ...payload }))
  })

  it('edit tanpa mengubah isi → update() membawa content hasil muat detail', async () => {
    const wrapper = mountArticleDialog(articleRow)
    await flushPromises()
    expect(masterService.get).toHaveBeenCalledWith('faq-article', '7')
    expect(contentTextarea().value).toBe('<p>Buka menu Akun.</p>')

    await submitForm()
    await vi.waitFor(() => expect(masterService.update).toHaveBeenCalledTimes(1))

    expect(masterService.update).toHaveBeenCalledWith(
      'faq-article',
      '7',
      expect.objectContaining({ title: 'Lupa password', content: '<p>Buka menu Akun.</p>', id_faq_sub_topic: '5' }),
    )
    expect(wrapper.emitted('saved')).toHaveLength(1)
    wrapper.unmount()
  })

  it('edit dengan isi disunting → update() membawa content hasil suntingan', async () => {
    const wrapper = mountArticleDialog(articleRow)
    await flushPromises()

    typeInto('textarea[name="content"]', '<p>Buka menu <strong>Profil</strong>.</p>')
    await flushPromises()
    await submitForm()
    await vi.waitFor(() => expect(masterService.update).toHaveBeenCalledTimes(1))

    expect(masterService.update).toHaveBeenCalledWith(
      'faq-article',
      '7',
      expect.objectContaining({ content: '<p>Buka menu <strong>Profil</strong>.</p>' }),
    )
    wrapper.unmount()
  })

  it('tambah → create() membawa content yang diketik', async () => {
    const wrapper = mountArticleDialog(null)
    await flushPromises()

    await chooseOption(0, '2')
    await chooseOption(1, '5')
    typeInto('input[name="title"]', 'Cara ganti foto')
    typeInto('textarea[name="content"]', '<p>Buka menu Profil.</p>')
    await flushPromises()
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalledTimes(1))

    expect(masterService.create).toHaveBeenCalledWith(
      'faq-article',
      expect.objectContaining({ title: 'Cara ganti foto', content: '<p>Buka menu Profil.</p>', id_faq_sub_topic: '5' }),
    )
    expect(masterService.get).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})

describe('MasterFormDialog — field ref berjenjang, boolean, urutan manual (CR-009)', () => {
  const base = { has_order: true, has_status: true, id_digits: null, auto_increment: true, name_max_length: 255, fields: [] }
  const provinsiMeta: MasterMeta = { ...base, key: 'provinsi', label: 'Provinsi', primary_key: 'id_provinsi', id_max_length: 2, name_field: 'provinsi', name_label: 'Nama Provinsi', parent: null }
  const kabMeta: MasterMeta = {
    ...base,
    key: 'kabupaten-kota',
    label: 'Kabupaten/Kota',
    primary_key: 'id_kabupaten_kota',
    id_max_length: 4,
    name_field: 'kabupaten_kota',
    name_label: 'Nama Kabupaten/Kota',
    parent: { field: 'id_provinsi', entity: 'provinsi' },
  }
  const kantorMeta: MasterMeta = {
    ...base,
    key: 'kantor',
    label: 'Kantor',
    primary_key: 'id_kantor',
    id_max_length: 11,
    name_field: 'nama_kantor',
    name_label: 'Nama Kantor',
    parent: null,
    order_mode: 'manual',
    order_max: 127,
    fields: [
      { name: 'id_provinsi', label: 'Provinsi', type: 'ref', required: true, options: null, hint: null, entity: 'provinsi' },
      { name: 'id_kabupaten', label: 'Kabupaten/Kota', type: 'ref', required: false, options: null, hint: null, entity: 'kabupaten-kota', depends_on: 'id_provinsi' },
      { name: 'melayani_tamu', label: 'Melayani Tamu', type: 'boolean', required: false, options: null, hint: null },
    ],
  }
  const allMeta = [provinsiMeta, kabMeta, kantorMeta]

  function mountKantor(row: MasterRow | null) {
    return mount(MasterFormDialog, { props: { open: true, meta: kantorMeta, allMeta, row }, attachTo: document.body })
  }

  function select(name: string): HTMLSelectElement {
    const el = document.body.querySelector<HTMLSelectElement>(`select[name="${name}"]`)
    if (!el) throw new Error(`select ${name} tidak ditemukan`)
    return el
  }

  async function choose(name: string, value: string): Promise<void> {
    const el = select(name)
    el.value = value
    el.dispatchEvent(new Event('change'))
    await flushPromises()
  }

  function optionValues(name: string): string[] {
    return Array.from(select(name).options).map((o) => o.value)
  }

  beforeEach(() => {
    vi.mocked(masterService.options).mockImplementation(async (entity, parent) => {
      if (entity === 'provinsi') {
        return [
          { id: '31', nama: 'DKI Jakarta', parent: null },
          { id: '32', nama: 'Jawa Barat', parent: null },
        ]
      }
      if (entity === 'kabupaten-kota' && parent === '31') return [{ id: '3171', nama: 'Jakarta Pusat', parent: '31' }]
      if (entity === 'kabupaten-kota' && parent === '32') return [{ id: '3273', nama: 'Kota Bandung', parent: '32' }]
      return []
    })
    vi.mocked(masterService.create).mockImplementation(async (_entity, payload) => ({ id_kantor: 9, ...payload }))
    vi.mocked(masterService.update).mockImplementation(async (_entity, _id, payload) => ({ id_kantor: 3, ...payload }))
  })

  it('tambah: dropdown turunan terkunci sampai induk dipilih, ganti induk mengosongkan & memuat ulang turunan', async () => {
    const wrapper = mountKantor(null)
    await flushPromises()

    expect(masterService.options).toHaveBeenCalledWith('provinsi', null)
    expect(masterService.options).not.toHaveBeenCalledWith('kabupaten-kota', expect.anything())
    expect(select('id_kabupaten').disabled).toBe(true)
    expect(optionValues('id_provinsi')).toEqual(['', '31', '32'])
    // Ref opsional bisa dikosongkan lagi; ref wajib tidak.
    expect(select('id_provinsi').options[0]?.disabled).toBe(true)
    expect(select('id_kabupaten').options[0]?.disabled).toBe(false)

    await choose('id_provinsi', '31')
    expect(masterService.options).toHaveBeenCalledWith('kabupaten-kota', '31')
    expect(select('id_kabupaten').disabled).toBe(false)
    await choose('id_kabupaten', '3171')

    await choose('id_provinsi', '32')
    expect(select('id_kabupaten').value).toBe('')
    expect(optionValues('id_kabupaten')).toEqual(['', '3273'])
    await choose('id_kabupaten', '3273')

    typeInto('input[name="nama_kantor"]', 'Kantor Bandung')
    const box = document.body.querySelector<HTMLInputElement>('input[type="checkbox"][name="melayani_tamu"]')
    expect(box?.checked).toBe(false)
    box?.click()
    typeInto('input[name="order"]', '5')
    await flushPromises()

    expect(document.body.textContent).toContain('Urutan (nilai tetap)')
    expect(document.body.textContent).toContain('Nilai urutan (mis. level) disimpan apa adanya, maksimal 127; entri lain tidak bergeser. Kosongkan = nilai terbesar + 1.')

    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalledTimes(1))
    expect(masterService.create).toHaveBeenCalledWith('kantor', {
      nama_kantor: 'Kantor Bandung',
      id_provinsi: '32',
      id_kabupaten: '3273',
      melayani_tamu: '1',
      order: 5,
    })
    wrapper.unmount()
  })

  it('tambah: boolean yang tidak dicentang terkirim 0 (bukan kosong), ref opsional kosong tidak dikirim', async () => {
    const wrapper = mountKantor(null)
    await flushPromises()

    await choose('id_provinsi', '31')
    typeInto('input[name="nama_kantor"]', 'Kantor Jakarta')
    await flushPromises()
    await submitForm()
    await vi.waitFor(() => expect(masterService.create).toHaveBeenCalledTimes(1))
    expect(masterService.create).toHaveBeenCalledWith('kantor', { nama_kantor: 'Kantor Jakarta', id_provinsi: '31', melayani_tamu: '0' })
    wrapper.unmount()
  })

  it('edit: nilai rujukan yang kini non-aktif/hilang tetap tampil bertanda dan ikut terkirim', async () => {
    vi.mocked(masterService.get).mockImplementation(async (entity, id): Promise<MasterRow> => {
      if (entity === 'provinsi') return { id_provinsi: id, provinsi: 'Jawa Tengah' }
      throw new Error('tidak ada')
    })
    const row: MasterRow = { id_kantor: 3, nama_kantor: 'Kantor Semarang', id_provinsi: '33', id_kabupaten: '3374', melayani_tamu: 0, order: 4, status: '1' }
    const wrapper = mountKantor(row)
    await flushPromises()

    expect(select('id_provinsi').value).toBe('33')
    expect(Array.from(select('id_provinsi').options).map((o) => o.textContent?.trim())).toContain('Jawa Tengah — tidak aktif (33)')
    expect(select('id_kabupaten').value).toBe('3374')
    expect(Array.from(select('id_kabupaten').options).map((o) => o.textContent?.trim())).toContain('3374 — tidak ditemukan (3374)')
    expect(document.body.querySelector<HTMLInputElement>('input[name="melayani_tamu"]')?.checked).toBe(false)

    await submitForm()
    await vi.waitFor(() => expect(masterService.update).toHaveBeenCalledTimes(1))
    // Urutan tidak berubah → tidak dikirim.
    expect(masterService.update).toHaveBeenCalledWith('kantor', '3', {
      nama_kantor: 'Kantor Semarang',
      id_provinsi: '33',
      id_kabupaten: '3374',
      melayani_tamu: '0',
    })
    wrapper.unmount()
  })

  describe('rantai ref 3 level (pola kantor DBV-003: provinsi → kabupaten/kota → kecamatan)', () => {
    const kecMeta: MasterMeta = {
      ...base,
      key: 'kecamatan',
      label: 'Kecamatan',
      primary_key: 'id_kecamatan',
      id_max_length: 7,
      name_field: 'kecamatan',
      name_label: 'Nama Kecamatan',
      parent: { field: 'id_kabupaten_kota', entity: 'kabupaten-kota' },
    }
    const kantor3Meta: MasterMeta = {
      ...kantorMeta,
      key: 'kantor-tiga',
      order_mode: 'shift',
      order_max: null,
      fields: [
        { name: 'id_provinsi', label: 'Provinsi', type: 'ref', required: true, options: null, hint: null, entity: 'provinsi' },
        { name: 'id_kabupaten', label: 'Kabupaten/Kota', type: 'ref', required: false, options: null, hint: null, entity: 'kabupaten-kota', depends_on: 'id_provinsi' },
        { name: 'id_kecamatan', label: 'Kecamatan', type: 'ref', required: false, options: null, hint: null, entity: 'kecamatan', depends_on: 'id_kabupaten' },
      ],
    }

    function mountKantor3(row: MasterRow | null) {
      return mount(MasterFormDialog, {
        props: { open: true, meta: kantor3Meta, allMeta: [provinsiMeta, kabMeta, kecMeta, kantor3Meta], row },
        attachTo: document.body,
      })
    }

    beforeEach(() => {
      const base3 = vi.mocked(masterService.options).getMockImplementation()
      vi.mocked(masterService.options).mockImplementation(async (entity, parent) => {
        if (entity === 'kecamatan' && parent === '3171') return [{ id: '3171010', nama: 'Gambir', parent: '3171' }]
        if (entity === 'kecamatan' && parent === '3273') return [{ id: '3273010', nama: 'Sukasari', parent: '3273' }]
        return base3 ? base3(entity, parent) : []
      })
    })

    it('ganti level teratas mengosongkan & mengunci SEMUA turunan (bukan hanya anak langsung)', async () => {
      const wrapper = mountKantor3(null)
      await flushPromises()

      await choose('id_provinsi', '31')
      await choose('id_kabupaten', '3171')
      expect(masterService.options).toHaveBeenCalledWith('kecamatan', '3171')
      await choose('id_kecamatan', '3171010')
      expect(select('id_kecamatan').value).toBe('3171010')

      await choose('id_provinsi', '32')
      expect(select('id_kabupaten').value).toBe('')
      expect(optionValues('id_kabupaten')).toEqual(['', '3273'])
      expect(select('id_kecamatan').value).toBe('')
      expect(select('id_kecamatan').disabled).toBe(true)
      expect(optionValues('id_kecamatan')).toEqual([''])

      typeInto('input[name="nama_kantor"]', 'Kantor Bandung')
      await flushPromises()
      await submitForm()
      await vi.waitFor(() => expect(masterService.create).toHaveBeenCalledTimes(1))
      // Kecamatan lama (milik provinsi sebelumnya) tidak ikut terkirim.
      expect(masterService.create).toHaveBeenCalledWith('kantor-tiga', { nama_kantor: 'Kantor Bandung', id_provinsi: '32' })
      wrapper.unmount()
    })

    it('edit: pilihan seluruh level dimuat berjenjang dari nilai tersimpan', async () => {
      const row: MasterRow = { id_kantor: 3, nama_kantor: 'Kantor Pusat', id_provinsi: '31', id_kabupaten: '3171', id_kecamatan: '3171010', order: 1, status: '1' }
      const wrapper = mountKantor3(row)
      await flushPromises()

      expect(masterService.options).toHaveBeenCalledWith('provinsi', null)
      expect(masterService.options).toHaveBeenCalledWith('kabupaten-kota', '31')
      expect(masterService.options).toHaveBeenCalledWith('kecamatan', '3171')
      expect([select('id_provinsi').value, select('id_kabupaten').value, select('id_kecamatan').value]).toEqual(['31', '3171', '3171010'])
      expect(select('id_kecamatan').disabled).toBe(false)
      expect(Array.from(select('id_kecamatan').options).map((o) => o.textContent?.trim())).toContain('Gambir (3171010)')
      wrapper.unmount()
    })
  })

  it('edit: pilihan ref gagal dimuat → nilai tersimpan tetap dipertahankan tanpa ditandai non-aktif', async () => {
    vi.mocked(masterService.options).mockImplementation(async (entity) => {
      if (entity === 'provinsi') throw new Error('jaringan putus')
      return []
    })
    vi.mocked(masterService.get).mockImplementation(async (_entity, id): Promise<MasterRow> => ({ id_provinsi: id, provinsi: 'DKI Jakarta' }))
    const row: MasterRow = { id_kantor: 3, nama_kantor: 'Kantor Pusat', id_provinsi: '31', id_kabupaten: '', melayani_tamu: 1, order: 1, status: '1' }
    const wrapper = mountKantor(row)
    await flushPromises()

    expect(document.body.textContent).toContain('Gagal memuat pilihan Provinsi.')
    expect(select('id_provinsi').value).toBe('31')
    const labels = Array.from(select('id_provinsi').options).map((o) => o.textContent?.trim())
    expect(labels).toContain('31 (31)')
    expect(labels.some((label) => label?.includes('tidak aktif'))).toBe(false)
    wrapper.unmount()
  })
})
