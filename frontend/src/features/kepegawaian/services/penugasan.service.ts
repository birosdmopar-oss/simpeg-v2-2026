/**
 * Klien penugasan Plt/Plh (B-07, pemilik WS-2, MAKE-012); `{jenis}` = plt | plh:
 *   GET    penugasan/{jenis}                daftar (search, status, page, per_page)
 *   POST   penugasan/{jenis}                tambah (multipart; berkas `lampiran` opsional)
 *   POST   penugasan/{jenis}/{id}           ubah (multipart + `_method=PUT`)
 *   DELETE penugasan/{jenis}/{id}           hapus PERMANEN (ikut legacy)
 *   GET    penugasan/{jenis}/{id}/lampiran  unduh lampiran
 */
import { api } from '@/lib/axios'

import type { JenisPenugasan, PenugasanItem, PenugasanListResponse } from '../types'

export interface PenugasanQuery {
  page: number
  per_page: number
  search?: string
  status?: string
}

const base = (jenis: JenisPenugasan): string => `/penugasan/${jenis}`

const EKSTENSI: Record<string, string> = {
  'application/pdf': '.pdf',
  'image/jpeg': '.jpg',
  'image/png': '.png',
  'image/gif': '.gif',
  'application/msword': '.doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',
  'application/vnd.ms-excel': '.xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': '.xlsx',
}

const ekstensi = (tipe: string): string => EKSTENSI[tipe.split(';')[0]?.trim() ?? ''] ?? ''

function formData(values: Record<string, string>, lampiran: File | null, method?: 'PUT'): FormData {
  const fd = new FormData()
  for (const [k, v] of Object.entries(values)) fd.append(k, v ?? '')
  if (lampiran) fd.append('lampiran', lampiran)
  if (method) fd.append('_method', method)
  return fd
}

export const penugasanService = {
  async list(jenis: JenisPenugasan, query: PenugasanQuery): Promise<PenugasanListResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    if (query.search) params.search = query.search
    if (query.status) params.status = query.status
    const { data } = await api.get<PenugasanListResponse>(base(jenis), { params })
    return data
  },

  async create(jenis: JenisPenugasan, values: Record<string, string>, lampiran: File | null): Promise<PenugasanItem> {
    const { data } = await api.post<PenugasanItem>(base(jenis), formData(values, lampiran))
    return data
  },

  async update(jenis: JenisPenugasan, id: number, values: Record<string, string>, lampiran: File | null): Promise<PenugasanItem> {
    const { data } = await api.post<PenugasanItem>(`${base(jenis)}/${id}`, formData(values, lampiran, 'PUT'))
    return data
  },

  async remove(jenis: JenisPenugasan, id: number): Promise<void> {
    await api.delete(`${base(jenis)}/${id}`)
  },

  /** Berkas + nama dari `Content-Disposition` (fallback `lampiran_<jenis>_<id>.<ekstensi dari tipe blob>`). */
  async lampiran(jenis: JenisPenugasan, id: number): Promise<{ blob: Blob; nama: string }> {
    const res = await api.get<Blob>(`${base(jenis)}/${id}/lampiran`, { responseType: 'blob' })
    const header = String((res.headers as Record<string, unknown> | undefined)?.['content-disposition'] ?? '')
    const dariHeader = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header)?.[1]
    return { blob: res.data, nama: dariHeader ? decodeURIComponent(dariHeader) : `lampiran_${jenis}_${id}${ekstensi(res.data.type)}` }
  },
}
