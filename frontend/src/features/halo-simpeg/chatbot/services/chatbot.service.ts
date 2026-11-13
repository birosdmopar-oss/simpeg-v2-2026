/**
 * Klien API chatbot "Vanya" — `api/v1/halo-simpeg/chatbot/*` (PRD Fase 8 v1.1 §6.5). Interceptor `@/lib/axios` sudah
 * membuka amplop, jadi `data` di sini adalah isi `data` backend (pelajaran amplop CR-059: jangan `res.data.data`). Setiap
 * respons di-parse skema Zod (`../schemas`); respons yang tidak sesuai kontrak → `KontrakChatbotError`.
 * Kontrak final: README `backend/app/Controllers/Api/HaloSimpeg/Chatbot` (MAKE-094).
 */
import type { z } from 'zod'

import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import { api } from '@/lib/axios'

import {
  balasanSchema,
  daftar,
  hasilImporSchema,
  kategoriSchema,
  KontrakChatbotError,
  opsiQnaSchema,
  pertanyaanSchema,
  qnaDetailSchema,
  qnaSchema,
  riwayatSchema,
  statistikSchema,
  tagSchema,
  topikSchema,
} from '../schemas'
import type {
  BalasanWidget,
  Daftar,
  FilterDaftar,
  FilterQna,
  FilterRiwayat,
  HasilImpor,
  KategoriForm,
  KategoriRow,
  NilaiFeedback,
  OpsiQna,
  PertanyaanWidget,
  QnaDetail,
  QnaForm,
  QnaRow,
  RiwayatRow,
  StatistikChatbot,
  StatusUbah,
  TagForm,
  TagRow,
  TopikWidget,
} from '../types'

export const BASIS_CHATBOT = '/halo-simpeg/chatbot'

function parse<S extends z.ZodTypeAny>(schema: S, data: unknown, path: string): z.output<S> {
  const hasil = schema.safeParse(data)
  if (!hasil.success) throw new KontrakChatbotError(path, hasil.error)
  return hasil.data as z.output<S>
}

/** Parameter kosong tidak dikirim. */
function params(filter: object): Record<string, string | number> {
  const hasil: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(filter)) {
    if (v === undefined || v === null) continue
    if (typeof v === 'string' && v.trim() === '') continue
    hasil[k] = typeof v === 'string' ? v.trim() : (v as number)
  }
  return hasil
}

async function get<S extends z.ZodTypeAny>(path: string, schema: S, query?: object): Promise<z.output<S>> {
  const { data } = await api.get<unknown>(`${BASIS_CHATBOT}/${path}`, query ? { params: params(query) } : undefined)
  return parse(schema, data, path)
}

// ---------------------------------------------------------------------------------------------------------------------

export const vanyaService = {
  async topik(): Promise<TopikWidget[]> {
    return get('widget/kategori', topikSchema.array())
  },
  async pertanyaan(idKategori: number): Promise<PertanyaanWidget[]> {
    return get(`widget/kategori/${idKategori}/pertanyaan`, pertanyaanSchema.array())
  },
  async populer(): Promise<PertanyaanWidget[]> {
    return get('widget/populer', pertanyaanSchema.array())
  },
  /** `id_qna` dikirim bila pesan berasal dari bubble/saran (F8-48); BE memakai pertanyaan dari DB. */
  async balas(pesan: string, idQna?: number): Promise<BalasanWidget> {
    const body = idQna === undefined ? { pesan } : { pesan, id_qna: idQna }
    const { data } = await api.post<unknown>(`${BASIS_CHATBOT}/widget/balas`, body)
    return parse(balasanSchema, data, 'widget/balas')
  },
  async feedback(idLog: number, nilai: NilaiFeedback, token: string): Promise<void> {
    await api.post(`${BASIS_CHATBOT}/widget/feedback`, { id_log: idLog, nilai, token })
  },
}

// ---------------------------------------------------------------------------------------------------------------------

function crudSederhana<Row, Form>(basis: 'kategori' | 'tag', schema: z.ZodType<Row, z.ZodTypeDef, unknown>, body: (f: Form) => object) {
  return {
    async list(filter: FilterDaftar): Promise<Daftar<Row>> {
      return get(basis, daftar(schema), filter) as Promise<Daftar<Row>>
    },
    async simpan(id: number | null, form: Form): Promise<Row> {
      const { data } = id === null ? await api.post<unknown>(`${BASIS_CHATBOT}/${basis}`, body(form)) : await api.put<unknown>(`${BASIS_CHATBOT}/${basis}/${id}`, body(form))
      return parse(schema, data, basis)
    },
    async setStatus(id: number, status: StatusUbah): Promise<void> {
      await api.patch(`${BASIS_CHATBOT}/${basis}/${id}/status`, { status })
    },
    async hapus(id: number): Promise<void> {
      await api.delete(`${BASIS_CHATBOT}/${basis}/${id}`)
    },
  }
}

export const kategoriChatbotService = crudSederhana<KategoriRow, KategoriForm>('kategori', kategoriSchema, (f) => ({
  judul: f.judul.trim(),
  tampil_bubble: f.tampil_bubble ? 1 : 0,
  status: f.status,
}))

export const tagChatbotService = {
  ...crudSederhana<TagRow, TagForm>('tag', tagSchema, (f) => ({ judul: f.judul.trim(), status: f.status })),
  /** Ekspor daftar tag xlsx (F8-47); nama berkas dari `Content-Disposition`. */
  ekspor(): Promise<BerkasUnduhan> {
    return ambilBerkas(`${BASIS_CHATBOT}/tag/ekspor`, 'Export_Tag_QnA', { format: 'xlsx' })
  },
}

const bodyQna = (f: QnaForm): object => ({
  id_kategori: f.id_kategori,
  tag: f.tag,
  is_fallback_option: f.is_fallback_option ? 1 : 0,
  status: f.status,
  pertanyaan: f.pertanyaan.trim(),
  jawaban: f.jawaban,
})

export const qnaChatbotService = {
  async list(filter: FilterQna): Promise<Daftar<QnaRow>> {
    return get('qna', daftar(qnaSchema), filter)
  },
  async detail(id: number): Promise<QnaDetail> {
    return get(`qna/${id}`, qnaDetailSchema)
  },
  async opsi(): Promise<OpsiQna> {
    return get('qna/opsi', opsiQnaSchema)
  },
  async simpan(id: number | null, form: QnaForm): Promise<QnaRow> {
    const { data } = id === null ? await api.post<unknown>(`${BASIS_CHATBOT}/qna`, bodyQna(form)) : await api.put<unknown>(`${BASIS_CHATBOT}/qna/${id}`, bodyQna(form))
    return parse(qnaSchema, data, 'qna')
  },
  async setStatus(id: number, status: StatusUbah): Promise<void> {
    await api.patch(`${BASIS_CHATBOT}/qna/${id}/status`, { status })
  },
  async hapus(id: number): Promise<void> {
    await api.delete(`${BASIS_CHATBOT}/qna/${id}`)
  },
  template(): Promise<BerkasUnduhan> {
    return ambilBerkas(`${BASIS_CHATBOT}/qna/template`, 'Template_Impor_QnA')
  },
  async impor(berkas: File): Promise<HasilImpor> {
    const fd = new FormData()
    fd.append('berkas', berkas)
    const { data } = await api.post<unknown>(`${BASIS_CHATBOT}/qna/impor`, fd)
    return parse(hasilImporSchema, data, 'qna/impor')
  },
}

export const statistikChatbotService = {
  async statistik(): Promise<StatistikChatbot> {
    return get('statistik', statistikSchema)
  },
  async riwayat(filter: FilterRiwayat): Promise<Daftar<RiwayatRow>> {
    return get('riwayat', daftar(riwayatSchema), filter)
  },
}
