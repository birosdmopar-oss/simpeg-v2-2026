/**
 * Klien API chatbot memakai `api` asli (interceptor amplop v2) dengan adapter tiruan — pelajaran amplop CR-059: service tidak
 * boleh membaca `res.data.data`. Fixture di `fixtures/` = contoh respons sintetis bentuk §6.5 PRD Fase 8 v1.1; diganti salinan
 * `backend/tests/_support/HaloSimpeg/Chatbot/*.json` sesudah MAKE-094 di main.
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

import { KontrakChatbotError } from '../schemas'
import { kategoriChatbotService, qnaChatbotService, statistikChatbotService, vanyaService } from '../services/chatbot.service'
import adminQna from './fixtures/admin_qna_daftar.json'
import adminRiwayat from './fixtures/admin_riwayat.json'
import adminStatistik from './fixtures/admin_statistik.json'
import balasCocok from './fixtures/widget_balas_cocok.json'
import balasFallback from './fixtures/widget_balas_fallback.json'
import widgetKategori from './fixtures/widget_kategori.json'

const jawab = (body: unknown, status = 200): void => {
  server.balasan = { status, body }
}

beforeEach(() => {
  server.panggilan = []
  jawab({ status: 'success', data: null })
})

describe('vanyaService (amplop asli)', () => {
  it('widget/kategori → daftar topik', async () => {
    jawab(widgetKategori)
    await expect(vanyaService.topik()).resolves.toEqual([
      { id: 3, judul: 'Cuti' },
      { id: 5, judul: 'Kinerja' },
    ])
    expect(server.panggilan[0]).toMatchObject({ method: 'get', url: '/halo-simpeg/chatbot/widget/kategori' })
  })

  it('widget/balas cocok: id_qna dikirim bila dari bubble; token_feedback ikut', async () => {
    jawab(balasCocok)
    const r = await vanyaService.balas('Berapa jatah cuti?', 7)
    expect(server.panggilan[0]).toEqual({ method: 'post', url: '/halo-simpeg/chatbot/widget/balas', params: null, body: { pesan: 'Berapa jatah cuti?', id_qna: 7 } })
    expect(r).toMatchObject({ cocok: true, id_log: 41, bisa_feedback: true, token_feedback: 'MTcwMDAwMDAwMA.c2ludGV0aXM', saran: [] })
  })

  it('widget/balas fallback tanpa token_feedback → null; ketikan tanpa id_qna', async () => {
    jawab(balasFallback)
    const r = await vanyaService.balas('asdf')
    expect(server.panggilan[0]?.body).toEqual({ pesan: 'asdf' })
    expect(r.token_feedback).toBeNull()
    expect(r.cocok).toBe(false)
    expect(r.saran).toHaveLength(2)
  })

  it('widget/feedback mengirim id_log, nilai, token; 429 → ApiError dengan pesan server', async () => {
    jawab({ status: 'success', data: { tersimpan: true } })
    await vanyaService.feedback(41, -1, 'tok')
    expect(server.panggilan[0]).toMatchObject({ method: 'post', url: '/halo-simpeg/chatbot/widget/feedback', body: { id_log: 41, nilai: -1, token: 'tok' } })

    jawab({ status: 'error', message: 'Terlalu banyak permintaan.' }, 429)
    const galat = await vanyaService.feedback(41, 1, 'tok').catch((e: unknown) => e)
    expect(isApiError(galat) && galat.status === 429 && galat.message).toBe('Terlalu banyak permintaan.')
  })

  it('respons di luar kontrak → KontrakChatbotError', async () => {
    jawab({ status: 'success', data: { salah: true } })
    await expect(vanyaService.balas('x')).rejects.toBeInstanceOf(KontrakChatbotError)
  })
})

describe('admin (amplop asli)', () => {
  it('qna: daftar berhalaman + filter terkirim; status/bendera dikoersi', async () => {
    jawab(adminQna)
    const r = await qnaChatbotService.list({ page: 1, per_page: 20, status: 1, kategori: 3, q: '  cuti ' })
    expect(server.panggilan[0]).toMatchObject({ url: '/halo-simpeg/chatbot/qna', params: { page: 1, per_page: 20, status: 1, kategori: 3, q: 'cuti' } })
    expect(r.items[0]).toMatchObject({ id: 12, is_fallback_option: true, status: 1 })
    expect(r.items[0]?.tag.map((t) => t.status)).toEqual([1, 2, 10])
    expect(r.items[1]).toMatchObject({ status: 2, is_fallback_option: false, kategori: { status: 10 } })
  })

  it('kategori: simpan (POST/PUT) dan PATCH status', async () => {
    jawab({ status: 'success', data: { id: 3, judul: 'Cuti', tampil_bubble: 1, status: 1 } })
    await kategoriChatbotService.simpan(null, { judul: ' Cuti ', tampil_bubble: true, status: 1 })
    await kategoriChatbotService.simpan(3, { judul: 'Cuti', tampil_bubble: false, status: 2 })
    await kategoriChatbotService.setStatus(3, 2)
    await kategoriChatbotService.hapus(3)
    expect(server.panggilan.map((p) => [p.method, p.url, p.body])).toEqual([
      ['post', '/halo-simpeg/chatbot/kategori', { judul: 'Cuti', tampil_bubble: 1, status: 1 }],
      ['put', '/halo-simpeg/chatbot/kategori/3', { judul: 'Cuti', tampil_bubble: 0, status: 2 }],
      ['patch', '/halo-simpeg/chatbot/kategori/3/status', { status: 2 }],
      ['delete', '/halo-simpeg/chatbot/kategori/3', null],
    ])
  })

  it('qna/impor multipart → ringkasan', async () => {
    jawab({ status: 'success', data: { ditambah: 2, kategori_baru: 1, tag_baru: 3, dilewati: [{ baris: 4, alasan: 'Pertanyaan kosong.' }], galat: [] } })
    const r = await qnaChatbotService.impor(new File(['x'], 'qna.xlsx'))
    expect(server.panggilan[0]).toMatchObject({ method: 'post', url: '/halo-simpeg/chatbot/qna/impor', body: 'FormData' })
    expect(r).toEqual({ ditambah: 2, kategori_baru: 1, tag_baru: 3, dilewati: [{ baris: 4, alasan: 'Pertanyaan kosong.' }], galat: [] })
  })

  it('statistik dan riwayat', async () => {
    jawab(adminStatistik)
    const s = await statistikChatbotService.statistik()
    expect(s.kepuasan.persen_puas).toBe(75)
    expect(s.top_qna_negatif[0]).toEqual({ id_qna: 99, pertanyaan: null, jumlah: 2 })

    jawab(adminRiwayat)
    const r = await statistikChatbotService.riwayat({ page: 1, per_page: 25, dari: '2026-11-01', cocok: 0, feedback: -1 })
    expect(server.panggilan[1]).toMatchObject({ url: '/halo-simpeg/chatbot/riwayat', params: { page: 1, per_page: 25, dari: '2026-11-01', cocok: 0, feedback: -1 } })
    expect(r.items.map((x) => [x.cocok, x.feedback])).toEqual([
      [true, 1],
      [false, null],
    ])
  })
})
