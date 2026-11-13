/**
 * Klien API chatbot memakai `api` asli (interceptor amplop v2) dengan adapter tiruan — pelajaran amplop CR-059: service tidak
 * boleh membaca `res.data.data`. Fixture di `fixtures/` = SALINAN contoh respons sintetis BE
 * `backend/tests/_support/HaloSimpeg/Chatbot/*.json` (kontrak MAKE-094 beku @fd1e74ed; `pembanding.json` milik test mesin BE
 * tidak disalin). Salin ulang bila kontrak berubah lewat CR.
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: { status: 200, body: {} as unknown },
  panggilan: [] as { method: string; url: string; params: unknown; body: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const data = config.data
    server.panggilan.push({
      method: String(config.method),
      url: config.url ?? '',
      params: config.params ?? null,
      body: data instanceof FormData ? 'FormData' : data ? JSON.parse(String(data)) : null,
    })
    const response: AxiosResponse = { status: server.balasan.status, statusText: 'x', headers: {}, config, data: server.balasan.body }
    if (server.balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { isApiError } from '@/lib/axios'

import { galatPerKolom } from '../galat'
import { KontrakChatbotError } from '../schemas'
import { kategoriChatbotService, qnaChatbotService, statistikChatbotService, tagChatbotService, vanyaService } from '../services/chatbot.service'
import galat403 from './fixtures/galat-403-feedback.json'
import galat422Feedback from './fixtures/galat-422-feedback-tidak-cocok.json'
import galat422Pesan from './fixtures/galat-422-pesan.json'
import galat422Qna from './fixtures/galat-422-qna.json'
import galat429 from './fixtures/galat-429.json'
import kategoriDaftar from './fixtures/kategori-daftar.json'
import kategoriDetail from './fixtures/kategori-detail.json'
import qnaDaftar from './fixtures/qna-daftar.json'
import qnaDetail from './fixtures/qna-detail.json'
import qnaImporGalat from './fixtures/qna-impor-galat.json'
import qnaImporSukses from './fixtures/qna-impor-sukses.json'
import qnaOpsi from './fixtures/qna-opsi.json'
import riwayat from './fixtures/riwayat.json'
import statistik from './fixtures/statistik.json'
import tagDaftar from './fixtures/tag-daftar.json'
import balasCocok from './fixtures/widget-balas-cocok.json'
import balasFallback from './fixtures/widget-balas-fallback.json'
import balasKorpusKosong from './fixtures/widget-balas-korpus-kosong.json'
import widgetFeedback from './fixtures/widget-feedback.json'
import widgetKategori from './fixtures/widget-kategori.json'
import widgetPertanyaan from './fixtures/widget-pertanyaan.json'
import widgetPopuler from './fixtures/widget-populer.json'

const jawab = (body: unknown, status = 200): void => {
  server.balasan = { status, body }
}

async function galatDari(p: Promise<unknown>): Promise<unknown> {
  return p.then(
    () => {
      throw new Error('seharusnya gagal')
    },
    (e: unknown) => e,
  )
}

beforeEach(() => {
  server.panggilan = []
  jawab({ status: 'success', data: null })
})

describe('widget (fixture kontrak MAKE-094)', () => {
  it('widget/kategori, pertanyaan, populer', async () => {
    jawab(widgetKategori)
    await expect(vanyaService.topik()).resolves.toEqual(widgetKategori.data)
    jawab(widgetPertanyaan)
    await expect(vanyaService.pertanyaan(1)).resolves.toEqual(widgetPertanyaan.data)
    jawab(widgetPopuler)
    await expect(vanyaService.populer()).resolves.toEqual(widgetPopuler.data)
    expect(server.panggilan.map((p) => `${p.method} ${p.url}`)).toEqual([
      'get /halo-simpeg/chatbot/widget/kategori',
      'get /halo-simpeg/chatbot/widget/kategori/1/pertanyaan',
      'get /halo-simpeg/chatbot/widget/populer',
    ])
  })

  it('widget/balas cocok (bubble): body {pesan, id_qna}; token_feedback ikut', async () => {
    jawab(balasCocok)
    const r = await vanyaService.balas('Bagaimana cara mengajukan cuti tahunan?', 10)
    expect(server.panggilan[0]).toEqual({
      method: 'post',
      url: '/halo-simpeg/chatbot/widget/balas',
      params: null,
      body: { pesan: 'Bagaimana cara mengajukan cuti tahunan?', id_qna: 10 },
    })
    expect(r).toEqual({ ...balasCocok.data, saran: [] })
  })

  it('widget/balas fallback: tanpa token_feedback → null, saran terisi; ketikan tanpa id_qna', async () => {
    jawab(balasFallback)
    const r = await vanyaService.balas('asdf')
    expect(server.panggilan[0]?.body).toEqual({ pesan: 'asdf' })
    expect(r.cocok).toBe(false)
    expect(r.bisa_feedback).toBe(false)
    expect(r.token_feedback).toBeNull()
    expect(r.saran.length).toBeGreaterThan(0)
  })

  it('widget/balas korpus kosong: id_log null', async () => {
    jawab(balasKorpusKosong)
    await expect(vanyaService.balas('cuti')).resolves.toMatchObject({ balasan: 'Database QnA masih kosong.', id_log: null, cocok: false })
  })

  it('widget/feedback: body {id_log, nilai, token}', async () => {
    jawab(widgetFeedback)
    await vanyaService.feedback(101, 1, 'tok')
    expect(server.panggilan[0]).toMatchObject({ method: 'post', url: '/halo-simpeg/chatbot/widget/feedback', body: { id_log: 101, nilai: 1, token: 'tok' } })
  })

  it.each([
    ['403 feedback', galat403, 403, 'Sesi penilaian tidak ditemukan. Silakan ajukan pertanyaan lagi.'],
    ['422 feedback tidak cocok', galat422Feedback, 422, 'Jawaban ini tidak dapat dinilai.'],
    ['429 throttle', galat429, 429, 'Terlalu banyak permintaan ke Vanya. Coba lagi dalam satu menit.'],
  ])('galat %s → ApiError membawa pesan server', async (_n, body, status, pesan) => {
    jawab(body, status)
    const e = await galatDari(vanyaService.feedback(101, 1, 'tok'))
    expect(isApiError(e) && e.status).toBe(status)
    expect(isApiError(e) && e.message).toBe(pesan)
  })

  it('422 errors string per kolom (errors.pesan) terbaca utuh oleh galatPerKolom', async () => {
    jawab(galat422Pesan, 422)
    const e = await galatDari(vanyaService.balas(''))
    expect(galatPerKolom(e)).toEqual({ pesan: galat422Pesan.errors.pesan })
  })

  it('respons di luar kontrak → KontrakChatbotError', async () => {
    jawab({ status: 'success', data: { salah: true } })
    await expect(vanyaService.balas('x')).rejects.toBeInstanceOf(KontrakChatbotError)
  })
})

describe('admin (fixture kontrak MAKE-094)', () => {
  it('kategori: daftar + detail; tampil_bubble 0/1 → boolean', async () => {
    jawab(kategoriDaftar)
    const d = await kategoriChatbotService.list({ page: 1, per_page: 20, status: 1, q: '' })
    expect(server.panggilan[0]).toMatchObject({ url: '/halo-simpeg/chatbot/kategori', params: { page: 1, per_page: 20, status: 1 } })
    expect(d.items).toEqual([
      { id: 1, judul: 'Cuti', status: 1, tampil_bubble: true },
      { id: 2, judul: 'Kinerja', status: 2, tampil_bubble: false },
    ])
    jawab(kategoriDetail)
    await expect(kategoriChatbotService.simpan(1, { judul: 'Cuti', tampil_bubble: true, status: 1 })).resolves.toMatchObject({ id: kategoriDetail.data.id })
  })

  it('kategori/tag: POST tanpa status, PUT dengan status, PATCH status, DELETE', async () => {
    jawab(kategoriDetail)
    await kategoriChatbotService.simpan(null, { judul: ' Cuti ', tampil_bubble: true, status: 1 })
    await kategoriChatbotService.simpan(3, { judul: 'Cuti', tampil_bubble: false, status: 2 })
    await kategoriChatbotService.setStatus(3, 2)
    jawab({ status: 'success', data: null })
    await kategoriChatbotService.hapus(3)
    jawab({ status: 'success', data: { id: 5, judul: 'sandi', status: 1 } })
    await tagChatbotService.simpan(null, { judul: 'sandi', status: 1 })
    await tagChatbotService.simpan(5, { judul: 'sandi', status: 2 })
    expect(server.panggilan.map((p) => [p.method, p.url, p.body])).toEqual([
      ['post', '/halo-simpeg/chatbot/kategori', { judul: 'Cuti', tampil_bubble: 1 }],
      ['put', '/halo-simpeg/chatbot/kategori/3', { judul: 'Cuti', tampil_bubble: 0, status: 2 }],
      ['patch', '/halo-simpeg/chatbot/kategori/3/status', { status: 2 }],
      ['delete', '/halo-simpeg/chatbot/kategori/3', null],
      ['post', '/halo-simpeg/chatbot/tag', { judul: 'sandi' }],
      ['put', '/halo-simpeg/chatbot/tag/5', { judul: 'sandi', status: 2 }],
    ])
  })

  it('tag daftar', async () => {
    jawab(tagDaftar)
    const d = await tagChatbotService.list({ page: 1, per_page: 20 })
    expect(d.total).toBe(tagDaftar.data.total)
    expect(d.items.map((t) => t.id)).toEqual(tagDaftar.data.items.map((t) => t.id))
  })

  it('qna: daftar (bendera/ status induk), detail (+jawaban), opsi, simpan QnaSimpan', async () => {
    jawab(qnaDaftar)
    const d = await qnaChatbotService.list({ page: 1, per_page: 20, status: 1, kategori: 1, tag: 5, q: ' cuti ' })
    expect(server.panggilan[0]).toMatchObject({ url: '/halo-simpeg/chatbot/qna', params: { page: 1, per_page: 20, status: 1, kategori: 1, tag: 5, q: 'cuti' } })
    expect(d.items[1]).toMatchObject({ id: 10, is_fallback_option: true, status: 1, kategori: { id: 1, status: 1 } })
    expect(d.items[0]).toMatchObject({ is_fallback_option: false, status: 2, kategori: { status: 2 } })

    jawab(qnaDetail)
    const det = await qnaChatbotService.detail(qnaDetail.data.id)
    expect(det.jawaban).toBe(qnaDetail.data.jawaban)

    jawab(qnaOpsi)
    await expect(qnaChatbotService.opsi()).resolves.toEqual(qnaOpsi.data)

    jawab(qnaDetail)
    const form = { id_kategori: 1, tag: [5], is_fallback_option: true, status: 2 as const, pertanyaan: ' Cuti? ', jawaban: '<p>x</p>' }
    await qnaChatbotService.simpan(null, form)
    await qnaChatbotService.simpan(10, form)
    expect(server.panggilan.slice(-2).map((p) => [p.method, p.url, p.body])).toEqual([
      ['post', '/halo-simpeg/chatbot/qna', { pertanyaan: 'Cuti?', jawaban: '<p>x</p>', id_kategori: 1, tag: [5], is_fallback_option: 1 }],
      ['put', '/halo-simpeg/chatbot/qna/10', { pertanyaan: 'Cuti?', jawaban: '<p>x</p>', id_kategori: 1, tag: [5], is_fallback_option: 1, status: 2 }],
    ])
  })

  it('qna simpan 422: errors string per kolom (id_kategori)', async () => {
    jawab(galat422Qna, 422)
    const e = await galatDari(qnaChatbotService.simpan(11, { id_kategori: 9, tag: [], is_fallback_option: false, status: 1, pertanyaan: 'x', jawaban: 'y' }))
    expect(galatPerKolom(e)).toEqual({ id_kategori: 'Kategori QnA sudah dihapus. Pilih kategori lain.' })
  })

  it('qna/impor multipart field berkas → ringkasan sukses dan galat', async () => {
    jawab(qnaImporSukses)
    await expect(qnaChatbotService.impor(new File(['x'], 'qna.xlsx'))).resolves.toEqual(qnaImporSukses.data)
    expect(server.panggilan[0]).toMatchObject({ method: 'post', url: '/halo-simpeg/chatbot/qna/impor', body: 'FormData' })
    jawab(qnaImporGalat)
    const g = await qnaChatbotService.impor(new File(['x'], 'qna.xlsx'))
    expect(g.ditambah).toBe(0)
    expect(g.galat).toEqual(qnaImporGalat.data.galat)
  })

  it('statistik: top_pesan.pesan_user; pertanyaan null untuk QnA yang sudah tidak ada', async () => {
    jawab(statistik)
    const s = await statistikChatbotService.statistik()
    expect(s).toEqual(statistik.data)
  })

  it('riwayat: waktu ISO berzona WIB, filter feedback=0 (belum dinilai) dikirim', async () => {
    jawab(riwayat)
    const r = await statistikChatbotService.riwayat({ page: 1, per_page: 25, dari: '2026-11-01', sampai: '2026-11-13', cocok: 0, feedback: 0 })
    expect(server.panggilan[0]).toMatchObject({
      url: '/halo-simpeg/chatbot/riwayat',
      params: { page: 1, per_page: 25, dari: '2026-11-01', sampai: '2026-11-13', cocok: 0, feedback: 0 },
    })
    expect(r.items).toEqual(riwayat.data.items)
  })
})
