/**
 * Klien B-12b Laporan Kerja Harian (pemilik WS-2, MAKE-013):
 *   GET    lkh?nip=&page=&per_page=          LKH milik sendiri (atau pegawai lain untuk role 1/3)
 *   GET    lkh/opsi                          rentang tanggal & atasan langsung
 *   GET    lkh/verifikasi?status=&page=      antrean atasan langsung
 *   POST   lkh                               `{ tgl_laporan, kegiatan: [...] }`
 *   GET    lkh/{id}                          detail + kegiatan
 *   PUT    lkh/{id}                          ubah
 *   DELETE lkh/{id}                          hapus
 *   POST   lkh/{id}/process                  `{ aksi: setujui|tolak|revisi, catatan }`
 *   GET    lkh/{id}/pdf                      PDF (TCPDF)
 */
import { api } from '@/lib/axios'

import { ambilBerkas, type BerkasUnduhan } from './berkasUnduhan'

import type { LkhItem, LkhKegiatan, LkhListResponse, LkhOpsi } from '../types'

export type AksiLkh = 'setujui' | 'tolak' | 'revisi'

export interface LkhIsian {
  /** Pegawai sasaran (role 1/3 untuk pegawai lain); bawaan diri sendiri. */
  nip?: string
  tgl_laporan: string
  kegiatan: LkhKegiatan[]
  /** Hanya Super Admin: timpa atasan otomatis (NIP pegawai aktif). */
  nip_atasan?: string
}

export const lkhService = {
  /** `nip` = pegawai lain (role 1/3 dalam lingkup; tab LKH Detail Pegawai, MAKE-014); bawaan diri sendiri. */
  async list(query: { page: number; per_page: number; nip?: string }): Promise<LkhListResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    if (query.nip) params.nip = query.nip
    const { data } = await api.get<LkhListResponse>('/lkh', { params })
    return data
  },

  async verifikasi(query: { page: number; per_page: number; status?: string }): Promise<LkhListResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    if (query.status !== undefined) params.status = query.status
    const { data } = await api.get<LkhListResponse>('/lkh/verifikasi', { params })
    return data
  },

  async opsi(nip?: string): Promise<LkhOpsi> {
    const { data } = await api.get<LkhOpsi>('/lkh/opsi', { params: nip ? { nip } : {} })
    return data
  },

  async detail(id: number): Promise<LkhItem> {
    const { data } = await api.get<LkhItem>(`/lkh/${id}`)
    return data
  },

  async create(isian: LkhIsian): Promise<LkhItem> {
    const { data } = await api.post<LkhItem>('/lkh', isian)
    return data
  },

  async update(id: number, isian: LkhIsian): Promise<LkhItem> {
    const { data } = await api.put<LkhItem>(`/lkh/${id}`, isian)
    return data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/lkh/${id}`)
  },

  async process(id: number, aksi: AksiLkh, catatan: string): Promise<LkhItem> {
    const { data } = await api.post<LkhItem>(`/lkh/${id}/process`, { aksi, catatan })
    return data
  },

  /** PDF LKH Disetujui + nama dari `Content-Disposition` (cadangan `lkh_<id>.pdf`). */
  pdf(id: number): Promise<BerkasUnduhan> {
    return ambilBerkas(`/lkh/${id}/pdf`, `lkh_${id}`)
  },
}
