import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const svc = vi.hoisted(() => {
  const crud = () => ({ list: vi.fn(), simpan: vi.fn(), setStatus: vi.fn(), hapus: vi.fn() })
  return {
    kategori: crud(),
    tag: { ...crud(), ekspor: vi.fn() },
    qna: { ...crud(), detail: vi.fn(), opsi: vi.fn(), template: vi.fn(), impor: vi.fn() },
    stat: { statistik: vi.fn(), riwayat: vi.fn() },
  }
})
vi.mock('../services/chatbot.service', async (asli) => ({
  ...(await asli<typeof import('../services/chatbot.service')>()),
  kategoriChatbotService: svc.kategori,
  tagChatbotService: svc.tag,
  qnaChatbotService: svc.qna,
  statistikChatbotService: svc.stat,
}))
vi.mock('@/features/kepegawaian/services/berkasUnduhan', async (asli) => ({
  ...(await asli<typeof import('@/features/kepegawaian/services/berkasUnduhan')>()),
  simpanBerkas: vi.fn(),
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import router from '@/router'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { buildNav } from '@/shared/layouts/nav.config'

import MasterChatbotView from '../views/admin/MasterChatbotView.vue'
import QnaChatbotView from '../views/admin/QnaChatbotView.vue'
import RiwayatChatbotView from '../views/admin/RiwayatChatbotView.vue'
import StatistikChatbotView from '../views/admin/StatistikChatbotView.vue'
import type { KategoriRow, QnaDetail, QnaRow, TagRow } from '../types'
import galat422Qna from './fixtures/galat-422-qna.json'

function apiError(status: number, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: false, original: new AxiosError('x') }
}

const daftar = <T,>(items: T[], total = items.length) => ({ items, total, page: 1, per_page: 20 })

const kategori: KategoriRow[] = [
  { id: 3, judul: 'Cuti', tampil_bubble: true, status: 1 },
  { id: 4, judul: 'Arsip', tampil_bubble: false, status: 2 },
]
const tags: TagRow[] = [{ id: 6, judul: 'libur', status: 1 }]
const qna: QnaRow[] = [
  {
    id: 12,
    pertanyaan: 'Berapa jatah cuti tahunan?',
    is_fallback_option: true,
    status: 1,
    kategori: { id: 3, judul: 'Cuti', status: 1 },
    tag: [
      { id: 4, judul: 'cuti tahunan', status: 1 },
      { id: 6, judul: 'libur', status: 2 },
      { id: 7, judul: 'lama', status: 10 },
    ],
  },
  { id: 11, pertanyaan: 'Cara cek SKP?', is_fallback_option: false, status: 2, kategori: { id: 9, judul: 'Kinerja Lama', status: 10 }, tag: [] },
]

type RiwayatRowUji = { id_log: number; waktu: string; pesan_user: string; pertanyaan_teratas: string | null; skor: number; cocok: boolean; feedback: 1 | -1 | null }
const riwayatRow = (id: number, pesan: string): RiwayatRowUji => ({ id_log: id, waktu: '2026-11-13T08:30:00+07:00', pesan_user: pesan, pertanyaan_teratas: null, skor: 0, cocok: false, feedback: null })

let wrapper: VueWrapper | null = null

async function pasang<C>(komponen: C, props: Record<string, unknown> = {}): Promise<VueWrapper> {
  wrapper = mount(komponen as never, { props, attachTo: document.body } as never)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  useAuthStore().user = { username: 'admin.uji', user_level: Role.SUPER_ADMIN } as User
  document.body.innerHTML = ''
  for (const g of Object.values(svc)) for (const f of Object.values(g)) f.mockReset()
  svc.kategori.list.mockResolvedValue(daftar(kategori))
  svc.tag.list.mockResolvedValue(daftar(tags))
  svc.qna.list.mockResolvedValue(daftar(qna))
  svc.qna.opsi.mockResolvedValue({ kategori: [{ id: 3, judul: 'Cuti' }], tag: [{ id: 4, judul: 'cuti tahunan' }] })
  for (const f of [svc.kategori.setStatus, svc.kategori.hapus, svc.qna.setStatus, svc.qna.hapus]) f.mockResolvedValue(undefined)
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

describe('Chatbot Manager — Kategori', () => {
  it('daftar: kolom No/Kategori/Bubble/Status, filter bawaan Aktif dikirim ke server, status hanya badge', async () => {
    const w = await pasang(MasterChatbotView, { jenis: 'kategori' })
    expect(svc.kategori.list).toHaveBeenCalledWith({ page: 1, per_page: 20, status: 1, q: '' })
    const baris = w.get('[data-testid="chatbot-kategori-row-3"]').text()
    expect(baris).toContain('Cuti')
    expect(baris).toContain('Ya')
    expect(w.get('[data-testid="chatbot-kategori-row-4"] [data-status="2"]').text()).toBe('Tidak Aktif')
    expect(w.find('[data-testid="chatbot-kategori-row-3"] [role="switch"]').exists()).toBe(false)
  })

  it('menu ⋮: urutan baku Edit → Nonaktifkan → Hapus (aktif) dan Edit → Aktifkan → Hapus (tidak aktif); label aksi', async () => {
    const w = await pasang(MasterChatbotView, { jenis: 'kategori' })
    expect((await rowMenuActions(w, 'chatbot-kategori-actions-3')).map((a) => a.label)).toEqual(['Edit', 'Nonaktifkan', 'Hapus'])
    expect((await rowMenuActions(w, 'chatbot-kategori-actions-4')).map((a) => [a.label, a.danger])).toEqual([
      ['Edit', false],
      ['Aktifkan', false],
      ['Hapus', true],
    ])
    expect(document.querySelector('[data-testid="chatbot-kategori-actions-3"]')?.getAttribute('aria-label')).toBe('Aksi untuk Cuti')
  })

  it('Nonaktifkan lewat PATCH status; Hapus lewat ConfirmDialog', async () => {
    const w = await pasang(MasterChatbotView, { jenis: 'kategori' })
    await selectRowAction(w, 'chatbot-kategori-actions-3', 'deactivate')
    expect(svc.kategori.setStatus).toHaveBeenCalledWith(3, 2)

    await selectRowAction(w, 'chatbot-kategori-actions-4', 'delete')
    expect(svc.kategori.hapus).not.toHaveBeenCalled()
    const konfirmasi = [...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Hapus') as HTMLButtonElement
    konfirmasi.click()
    await flushPromises()
    expect(svc.kategori.hapus).toHaveBeenCalledWith(4)
    expect(w.text()).toContain('Kategori berhasil dihapus.')
  })

  it('form: judul wajib; galat 422 per kolom dari server ditampilkan', async () => {
    svc.kategori.simpan.mockRejectedValueOnce(apiError(422, 'Data tidak valid.', { judul: ['Judul kategori sudah dipakai.'] }))
    const w = await pasang(MasterChatbotView, { jenis: 'kategori' })
    await w.get('[data-testid="chatbot-kategori-tambah"]').trigger('click')
    await flushPromises()
    const form = document.querySelector('[data-testid="chatbot-kategori-form"]') as HTMLFormElement
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(document.body.textContent).toContain('Judul kategori tidak boleh kosong!')
    expect(svc.kategori.simpan).not.toHaveBeenCalled()

    const input = form.querySelector('input[name="judul"]') as HTMLInputElement
    input.value = 'Cuti'
    input.dispatchEvent(new Event('input'))
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.kategori.simpan).toHaveBeenCalledWith(null, { judul: 'Cuti', tampil_bubble: false, status: 1 })
    expect(document.body.textContent).toContain('Judul kategori sudah dipakai.')
  })
})

describe('Chatbot Manager — Tag', () => {
  it('ekspor xlsx lewat UiExportMenu; tanpa kolom Bubble', async () => {
    svc.tag.ekspor.mockResolvedValue({ blob: new Blob(['x']), nama: 'Export_Tag_QnA_20261113_101010.xlsx' })
    const w = await pasang(MasterChatbotView, { jenis: 'tag' })
    expect(w.text()).not.toContain('Bubble')
    w.getComponent({ name: 'UiExportMenu' }).vm.$emit('select', 'xlsx')
    await flushPromises()
    expect(svc.tag.ekspor).toHaveBeenCalledTimes(1)
    expect(simpanBerkas).toHaveBeenCalledWith({ blob: expect.any(Blob), nama: 'Export_Tag_QnA_20261113_101010.xlsx' })
  })
})

describe('Chatbot Manager — QnA', () => {
  it('badge status induk (F8-49): tag Tidak Aktif berbadge, tag Dihapus tidak tampil, kategori Dihapus berpenanda', async () => {
    const w = await pasang(QnaChatbotView)
    const tagSel = w.get('[data-testid="chatbot-qna-row-12"] [data-kolom="tag"]').text()
    expect(tagSel).toContain('cuti tahunan')
    expect(tagSel).toContain('libur (Tidak Aktif)')
    expect(tagSel).not.toContain('lama')
    expect(w.get('[data-testid="chatbot-qna-row-11"] [data-kolom="kategori"] [data-status="10"]').text()).toBe('Dihapus')
    expect(w.get('[data-testid="chatbot-qna-row-12"]').text()).toContain('Ya')
  })

  it('form ubah: memuat detail, mempertahankan tag Tidak Aktif, tidak mengirim tag Dihapus, jawaban HTML ber-pratinjau', async () => {
    const detail: QnaDetail = { ...qna[0]!, jawaban: '<p>Dua belas hari</p>' }
    svc.qna.detail.mockResolvedValue(detail)
    svc.qna.simpan.mockResolvedValue(qna[0])
    const w = await pasang(QnaChatbotView)
    await selectRowAction(w, 'chatbot-qna-actions-12', 'edit')
    await flushPromises()
    expect(svc.qna.detail).toHaveBeenCalledWith(12)
    const tagBox = document.querySelector('[data-testid="chatbot-qna-tag"]')!
    expect(tagBox.querySelector('[data-testid="chatbot-qna-tag-6"]')?.textContent).toContain('Tidak Aktif')
    expect(tagBox.querySelector('[data-testid="chatbot-qna-tag-7"]')).toBeNull()

    // Jawaban HTML diisi ke field `html` FormField (textarea + tombol pratinjau SafeHtml bawaan FormField).
    expect((document.querySelector('textarea[name="jawaban"], textarea.font-mono') as HTMLTextAreaElement).value).toBe('<p>Dua belas hari</p>')
    expect(document.querySelector('[data-testid="html-preview-toggle"]')).not.toBeNull()

    ;(document.querySelector('[data-testid="chatbot-qna-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.qna.simpan).toHaveBeenCalledWith(12, {
      id_kategori: 3,
      tag: [4, 6],
      is_fallback_option: true,
      status: 1,
      pertanyaan: 'Berapa jatah cuti tahunan?',
      jawaban: '<p>Dua belas hari</p>',
    })
    expect(w.text()).toContain('QnA berhasil diperbarui.')
  })

  it('form ubah QnA berkategori Dihapus: penanda + galat 422 BE ditampilkan', async () => {
    svc.qna.detail.mockResolvedValue({ ...qna[1]!, jawaban: '<p>x</p>' })
    // Bentuk kontrak MAKE-094 = amplop v2 (fixture galat-422-qna.json).
    svc.qna.simpan.mockRejectedValueOnce(apiError(422, galat422Qna.message, galat422Qna.errors))
    const w = await pasang(QnaChatbotView)
    await selectRowAction(w, 'chatbot-qna-actions-11', 'edit')
    await flushPromises()
    expect(document.querySelector('[data-testid="chatbot-qna-kategori-induk"]')?.textContent).toContain('Kategori QnA sudah dihapus')
    ;(document.querySelector('[data-testid="chatbot-qna-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.qna.simpan).toHaveBeenCalledWith(11, expect.objectContaining({ id_kategori: 9 }))
    expect(document.querySelectorAll('[role="alert"]').length).toBeGreaterThan(0)
    expect(document.body.textContent).toContain(galat422Qna.message)
    expect([...document.querySelectorAll('[role="alert"]')].some((el) => el.textContent?.trim() === 'Kategori QnA sudah dihapus. Pilih kategori lain.')).toBe(true)
    void w
  })

  it('Unduh template dan Impor Excel (ringkasan hasil; galat → tidak ada yang tersimpan)', async () => {
    svc.qna.template.mockResolvedValue({ blob: new Blob(['x']), nama: 'Template_Import_QnA.xlsx' })
    svc.qna.impor.mockResolvedValueOnce({ ditambah: 0, kategori_baru: 0, tag_baru: 0, dilewati: [], galat: [{ baris: 3, alasan: 'Kategori wajib diisi.' }] })
    svc.qna.impor.mockResolvedValueOnce({ ditambah: 5, kategori_baru: 1, tag_baru: 2, dilewati: [{ baris: 7, alasan: 'Pertanyaan kosong.' }], galat: [] })
    const w = await pasang(QnaChatbotView)
    await w.get('[data-testid="chatbot-qna-template"]').trigger('click')
    await flushPromises()
    expect(simpanBerkas).toHaveBeenCalledWith({ blob: expect.any(Blob), nama: 'Template_Import_QnA.xlsx' })

    await w.get('[data-testid="chatbot-qna-impor"]').trigger('click')
    await flushPromises()
    const field = w.findComponent({ name: 'UiFileField' })
    field.vm.$emit('change', new File(['x'], 'qna.xlsx'))
    await flushPromises()
    ;(document.querySelector('[data-testid="chatbot-impor-kirim"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(document.querySelector('[data-testid="impor-daftar-galat"]')?.textContent).toContain('Baris 3: Kategori wajib diisi.')
    expect(document.body.textContent).toContain('Tidak ada data yang tersimpan')

    ;(document.querySelector('[data-testid="chatbot-impor-kirim"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(document.querySelector('[data-testid="impor-ditambah"]')?.textContent).toBe('5')
    expect(document.querySelector('[data-testid="impor-daftar-dilewati"]')?.textContent).toContain('Baris 7')
    expect(w.text()).toContain('Data QnA beserta Kategori dan Tag berhasil diimpor!')
  })
})

describe('Chatbot Manager — Statistik & Riwayat', () => {
  it('statistik: KPI 4 desimal, persen 1 desimal, QnA hilang → #id, teks ter-escape', async () => {
    svc.stat.statistik.mockResolvedValue({
      kpi: { total_chat: 1200, chat_hari_ini: 7, rata_skor: 0.4567, rata_skor_cocok: 0.7 },
      kepuasan: { membantu: 9, tidak_membantu: 3, total: 12, persen_puas: 75 },
      top_pesan: [{ pesan_user: '<b>cuti</b>', jumlah: 10 }],
      top_qna_cocok: [{ id_qna: 12, pertanyaan: 'Berapa jatah cuti tahunan?', jumlah: 8 }],
      top_qna_negatif: [{ id_qna: 99, pertanyaan: null, jumlah: 2 }],
    })
    const w = await pasang(StatistikChatbotView)
    const kpi = w.get('[data-testid="chatbot-statistik-kpi"]').text()
    expect(kpi).toContain('1.200')
    expect(kpi).toContain('0,4567')
    expect(kpi).toContain('0,7000')
    expect(kpi).toContain('75,0%')
    expect(w.get('[data-testid="chatbot-statistik-negatif"]').text()).toContain('#99')
    expect(w.get('[data-testid="chatbot-statistik-pesan"]').find('b').exists()).toBe(false)
    expect(w.get('[data-testid="chatbot-statistik-pesan"]').text()).toContain('<b>cuti</b>')
  })

  it('riwayat: waktu WIB, pesan sebagai teks, status Cocok/Fallback, feedback untuk pembaca layar, filter ke server', async () => {
    svc.stat.riwayat.mockResolvedValue({
      items: [
        { id_log: 41, waktu: '2026-11-13T00:30:00+07:00', pesan_user: '<script>x</script> cuti', pertanyaan_teratas: 'Berapa jatah cuti?', skor: 0.8123, cocok: true, feedback: 1 },
        { id_log: 40, waktu: '2026-11-12T23:59:00+07:00', pesan_user: 'asdf', pertanyaan_teratas: null, skor: 0, cocok: false, feedback: null },
      ],
      total: 2,
      page: 1,
      per_page: 25,
    })
    const w = await pasang(RiwayatChatbotView)
    expect(svc.stat.riwayat).toHaveBeenLastCalledWith({ page: 1, per_page: 25, dari: undefined, sampai: undefined, cocok: undefined, feedback: undefined })
    const b41 = w.get('[data-testid="chatbot-riwayat-row-41"]')
    expect(b41.get('[data-kolom="waktu"]').text()).toMatch(/13 Nov 2026.*00[.:]30/)
    expect(b41.get('[data-kolom="pesan"]').find('script').exists()).toBe(false)
    expect(b41.get('[data-kolom="pesan"]').text()).toBe('<script>x</script> cuti')
    expect(b41.text()).toContain('Cocok')
    expect(b41.text()).toContain('0,8123')
    expect(b41.get('[data-kolom="feedback"]').text()).toBe('Membantu')
    const b40 = w.get('[data-testid="chatbot-riwayat-row-40"]')
    expect(b40.text()).toContain('(Tidak ada kecocokan)')
    expect(b40.text()).toContain('Fallback')

    const pilihCocok = w.findAllComponents({ name: 'UiSelect' })[0]!
    pilihCocok.vm.$emit('update:modelValue', '0')
    await flushPromises()
    expect(svc.stat.riwayat).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, cocok: 0 }))
  })
})

function tunda<T>() {
  let lepas: (v: T) => void = () => {}
  let tolak: (e: unknown) => void = () => {}
  const janji = new Promise<T>((r, j) => {
    lepas = r
    tolak = j
  })
  return { janji, lepas, tolak }
}

const klikKonfirmasi = (): void => {
  ;([...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Hapus') as HTMLButtonElement).click()
}

describe('Chatbot Manager — review CR (M-1..M-3, M-9, M-11)', () => {
  it('M-1: sesudah impor sukses tombol Impor hilang dan berkas dilepas (tidak bisa impor dua kali)', async () => {
    svc.qna.impor.mockResolvedValue({ ditambah: 2, kategori_baru: 0, tag_baru: 0, dilewati: [], galat: [] })
    const w = await pasang(QnaChatbotView)
    await w.get('[data-testid="chatbot-qna-impor"]').trigger('click')
    await flushPromises()
    w.findComponent({ name: 'UiFileField' }).vm.$emit('change', new File(['x'], 'qna.xlsx'))
    await flushPromises()
    ;(document.querySelector('[data-testid="chatbot-impor-kirim"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(document.querySelector('[data-testid="impor-ditambah"]')?.textContent).toBe('2')
    expect(document.querySelector('[data-testid="chatbot-impor-kirim"]')).toBeNull()
    expect(svc.qna.impor).toHaveBeenCalledTimes(1)
  })

  it('M-2: opsi gagal → form ditahan + muat ulang; tag lama (aktif & Tidak Aktif) tetap terkirim walau tidak ada di opsi', async () => {
    svc.qna.opsi.mockReset()
    svc.qna.opsi.mockRejectedValueOnce(apiError(500, 'Server galat.'))
    svc.qna.opsi.mockResolvedValueOnce({ kategori: [{ id: 3, judul: 'Cuti' }], tag: [{ id: 9, judul: 'lain' }] })
    svc.qna.detail.mockResolvedValue({ ...qna[0]!, jawaban: '<p>x</p>' })
    svc.qna.simpan.mockResolvedValue(qna[0])
    const w = await pasang(QnaChatbotView)
    await selectRowAction(w, 'chatbot-qna-actions-12', 'edit')
    await flushPromises()
    expect(document.querySelector('[data-testid="chatbot-qna-opsi-galat"]')?.textContent).toContain('Server galat.')
    const simpan = document.querySelector('[data-testid="chatbot-qna-form"] button[type="submit"]') as HTMLButtonElement
    expect(simpan.disabled).toBe(true)
    expect(document.querySelector('[data-testid="chatbot-qna-tag-4"]')).not.toBeNull()

    ;(document.querySelector('[data-testid="chatbot-qna-opsi-muat-ulang"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(document.querySelector('[data-testid="chatbot-qna-opsi-galat"]')).toBeNull()
    ;(document.querySelector('[data-testid="chatbot-qna-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.qna.simpan).toHaveBeenCalledWith(12, expect.objectContaining({ tag: [4, 6] }))
  })

  it('M-3: respons daftar yang tiba terbalik — yang tampil hasil permintaan terakhir (Riwayat)', async () => {
    const pertama = tunda<ReturnType<typeof daftar<RiwayatRowUji>>>()
    const kedua = tunda<ReturnType<typeof daftar<RiwayatRowUji>>>()
    svc.stat.riwayat.mockReturnValueOnce(pertama.janji).mockReturnValueOnce(kedua.janji)
    const w = await pasang(RiwayatChatbotView)
    w.findAllComponents({ name: 'UiSelect' })[0]!.vm.$emit('update:modelValue', '1')
    await flushPromises()
    kedua.lepas(daftar([riwayatRow(2, 'baru')]))
    await flushPromises()
    pertama.lepas(daftar([riwayatRow(1, 'lama')]))
    await flushPromises()
    expect(w.find('[data-testid="chatbot-riwayat-row-2"]').exists()).toBe(true)
    expect(w.find('[data-testid="chatbot-riwayat-row-1"]').exists()).toBe(false)
    expect(w.text()).not.toContain('Memuat...')
  })

  it('M-3: galat permintaan lama tidak menimpa hasil terbaru (Master)', async () => {
    const pertama = tunda<ReturnType<typeof daftar<KategoriRow>>>()
    svc.kategori.list.mockReturnValueOnce(pertama.janji).mockResolvedValueOnce(daftar([kategori[1]!]))
    const w = await pasang(MasterChatbotView, { jenis: 'kategori' })
    w.findComponent({ name: 'UiSelect' }).vm.$emit('update:modelValue', '2')
    await flushPromises()
    pertama.tolak(apiError(500, 'lama gagal'))
    await flushPromises()
    expect(w.find('[data-testid="chatbot-kategori-row-4"]').exists()).toBe(true)
    expect(w.text()).not.toContain('lama gagal')
  })

  it('M-3: respons terbalik di QnA — hasil filter terakhir yang tampil', async () => {
    const pertama = tunda<ReturnType<typeof daftar<QnaRow>>>()
    svc.qna.list.mockReturnValueOnce(pertama.janji).mockResolvedValueOnce(daftar([qna[1]!]))
    const w = await pasang(QnaChatbotView)
    w.findAllComponents({ name: 'UiSelect' })[0]!.vm.$emit('update:modelValue', '2')
    await flushPromises()
    pertama.lepas(daftar([qna[0]!]))
    await flushPromises()
    expect(w.find('[data-testid="chatbot-qna-row-11"]').exists()).toBe(true)
    expect(w.find('[data-testid="chatbot-qna-row-12"]').exists()).toBe(false)
  })

  it('M-9: filter server QnA — argumen awal, kategori/tag lewat UiSelect, pencarian debounce 300 ms (fake timers)', async () => {
    vi.useFakeTimers()
    try {
      const w = await pasang(QnaChatbotView)
      expect(svc.qna.list).toHaveBeenLastCalledWith({ page: 1, per_page: 20, status: 1, kategori: undefined, tag: undefined, q: '' })
      const pilih = w.findAllComponents({ name: 'UiSelect' })
      pilih[1]!.vm.$emit('update:modelValue', '3')
      await flushPromises()
      expect(svc.qna.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, kategori: 3 }))
      pilih[2]!.vm.$emit('update:modelValue', '4')
      await flushPromises()
      expect(svc.qna.list).toHaveBeenLastCalledWith(expect.objectContaining({ kategori: 3, tag: 4 }))
      const sebelum = svc.qna.list.mock.calls.length
      w.findComponent({ name: 'UiSearchInput' }).vm.$emit('update:modelValue', '  cuti ')
      await vi.advanceTimersByTimeAsync(299)
      expect(svc.qna.list.mock.calls.length).toBe(sebelum)
      await vi.advanceTimersByTimeAsync(1)
      await flushPromises()
      expect(svc.qna.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, q: 'cuti' }))
    } finally {
      vi.useRealTimers()
    }
  })

  it('M-9: filter server Riwayat — dari/sampai, feedback "0" → 0; galat 422 per kolom tampil', async () => {
    svc.stat.riwayat.mockResolvedValue(daftar([]))
    const w = await pasang(RiwayatChatbotView)
    const tanggal = w.findAllComponents({ name: 'UiTextField' })
    tanggal[0]!.vm.$emit('update:modelValue', '2026-11-01')
    tanggal[1]!.vm.$emit('update:modelValue', '2026-11-13')
    w.findAllComponents({ name: 'UiSelect' })[1]!.vm.$emit('update:modelValue', '0')
    await flushPromises()
    expect(svc.stat.riwayat).toHaveBeenLastCalledWith({ page: 1, per_page: 25, dari: '2026-11-01', sampai: '2026-11-13', cocok: undefined, feedback: 0 })

    svc.stat.riwayat.mockRejectedValueOnce(apiError(422, 'Tanggal tidak valid.', { dari: ['Tanggal tidak valid.'] }))
    tanggal[0]!.vm.$emit('update:modelValue', '2026-13-01')
    await flushPromises()
    expect(w.findAllComponents({ name: 'UiTextField' })[0]!.props('helpText')).toBe('Tanggal tidak valid.')
  })

  it('M-11b: ⋮ QnA — urutan baku, Aktifkan memanggil setStatus, Hapus menunggu ConfirmDialog', async () => {
    const w = await pasang(QnaChatbotView)
    expect((await rowMenuActions(w, 'chatbot-qna-actions-12')).map((a) => [a.label, a.danger])).toEqual([
      ['Edit', false],
      ['Nonaktifkan', false],
      ['Hapus', true],
    ])
    expect((await rowMenuActions(w, 'chatbot-qna-actions-11')).map((a) => a.label)).toEqual(['Edit', 'Aktifkan', 'Hapus'])
    await selectRowAction(w, 'chatbot-qna-actions-11', 'activate')
    expect(svc.qna.setStatus).toHaveBeenCalledWith(11, 1)
    await selectRowAction(w, 'chatbot-qna-actions-12', 'delete')
    expect(svc.qna.hapus).not.toHaveBeenCalled()
    klikKonfirmasi()
    await flushPromises()
    expect(svc.qna.hapus).toHaveBeenCalledWith(12)
  })

  it('M-11c: impor 422 errors.berkas → pesan tampil tepat sekali, tanpa ringkasan, daftar tidak dimuat ulang', async () => {
    const pesan = 'Format template tidak sesuai.'
    svc.qna.impor.mockRejectedValueOnce(apiError(422, pesan, { berkas: [pesan] }))
    const w = await pasang(QnaChatbotView)
    const muatAwal = svc.qna.list.mock.calls.length
    await w.get('[data-testid="chatbot-qna-impor"]').trigger('click')
    await flushPromises()
    w.findComponent({ name: 'UiFileField' }).vm.$emit('change', new File(['x'], 'qna.xlsx'))
    await flushPromises()
    ;(document.querySelector('[data-testid="chatbot-impor-kirim"]') as HTMLButtonElement).click()
    await flushPromises()
    const galat = document.querySelector('[data-testid="chatbot-impor-galat"]')?.textContent ?? ''
    expect(galat.split(pesan).length - 1).toBe(1)
    expect(document.querySelector('[data-testid="chatbot-impor-hasil"]')).toBeNull()
    expect(svc.qna.list.mock.calls.length).toBe(muatAwal)
  })
})

describe('Chatbot Manager — route & menu', () => {
  it('route /chatbot-manager/:bagian role 1; /chatbot-manager → kategori', async () => {
    for (const b of ['kategori', 'tag', 'qna', 'statistik', 'riwayat']) {
      const r = router.getRoutes().find((x) => x.name === `chatbot-${b}`)
      expect(r?.path).toBe(`/chatbot-manager/${b}`)
      expect(r?.meta.roles).toEqual([Role.SUPER_ADMIN])
    }
    expect(router.resolve('/chatbot-manager').matched.at(-1)?.redirect).toEqual({ name: 'chatbot-kategori' })
  })

  it('menu Chatbot Manager phase 8 role 1 saja, tersembunyi selama ACTIVE_PHASE < 8 (tanpa rilis)', () => {
    const ada = (role: RoleCode, fase: number) => buildNav(role, fase).some((g) => g.items.some((i) => i.key === 'chatbot-manager'))
    expect(ada(Role.SUPER_ADMIN, 7)).toBe(false)
    expect(ada(Role.SUPER_ADMIN, 8)).toBe(true)
    for (const r of [Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT, Role.PPPK, Role.PIMPINAN]) expect(ada(r, 8)).toBe(false)
    const item = buildNav(Role.SUPER_ADMIN, 8)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'chatbot-manager')
    expect(item?.children?.map((c) => c.label)).toEqual(['Kategori', 'Tag', 'QnA', 'Statistik', 'Riwayat'])
  })
})
