/**
 * Klien B-13 Konfirmasi Ketidakhadiran (pemilik WS-2, MAKE-013):
 *   GET    konket?ds=&de=&status=&search=&page=&per_page=   daftar (pegawai: miliknya; role 1/3: antrean)
 *   GET    konket/opsi                                       jenis per role, tanggal minimal, mode
 *   GET    konket/{id}                                       detail + peserta + aksi
 *   POST   konket                                            tambah (multipart: isian, `peserta[]`, berkas `file_bukti`)
 *   POST   konket/{id}                                       ubah (multipart + `_method=PUT`)
 *   DELETE konket/{id}                                       hapus (dinas gabungan bersama pesertanya)
 *   POST   konket/{id}/process                               `{ aksi, reason_note }` (role 1, 3)
 *   GET    konket/{id}/berkas                                unduh berkas bukti
 */
import { api } from '@/lib/axios'

import type { ApprovalPayload } from '@/shared/components/approvalActions'

import type { KonketItem, KonketListResponse, KonketOpsi } from '../types'

export interface KonketQuery {
  page: number
  per_page: number
  ds?: string
  de?: string
  status?: string
  search?: string
}

/** Isian form konket; `peserta` = daftar NIP (dinas gabungan). */
export interface KonketIsian {
  nip?: string
  date_start: string
  date_end: string
  kategori: string
  jenis_dinas?: string
  affect_tukin?: string
  alasan: string
  peserta?: string[]
}

function formData(isian: KonketIsian, berkas: File | null, method?: 'PUT'): FormData {
  const fd = new FormData()
  for (const [k, v] of Object.entries(isian)) {
    if (k === 'peserta' || v === undefined) continue
    fd.append(k, String(v))
  }
  for (const nip of isian.peserta ?? []) fd.append('peserta[]', nip)
  if (berkas) fd.append('file_bukti', berkas)
  if (method) fd.append('_method', method)
  return fd
}

export const konketService = {
  async list(query: KonketQuery): Promise<KonketListResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    for (const key of ['ds', 'de', 'status', 'search'] as const) {
      const v = query[key]
      if (v) params[key] = v
    }
    const { data } = await api.get<KonketListResponse>('/konket', { params })
    return data
  },

  async opsi(): Promise<KonketOpsi> {
    const { data } = await api.get<KonketOpsi>('/konket/opsi')
    return data
  },

  async detail(id: number): Promise<KonketItem> {
    const { data } = await api.get<KonketItem>(`/konket/${id}`)
    return data
  },

  async create(isian: KonketIsian, berkas: File | null): Promise<KonketItem> {
    const { data } = await api.post<KonketItem>('/konket', formData(isian, berkas))
    return data
  },

  async update(id: number, isian: KonketIsian, berkas: File | null): Promise<KonketItem> {
    const { data } = await api.post<KonketItem>(`/konket/${id}`, formData(isian, berkas, 'PUT'))
    return data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/konket/${id}`)
  },

  async process(id: number, payload: ApprovalPayload): Promise<KonketItem> {
    const { data } = await api.post<KonketItem>(`/konket/${id}/process`, payload)
    return data
  },

  async berkas(id: number): Promise<Blob> {
    const { data } = await api.get<Blob>(`/konket/${id}/berkas`, { responseType: 'blob' })
    return data
  },
}
