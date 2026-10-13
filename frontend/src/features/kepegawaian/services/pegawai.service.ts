/**
 * Klien endpoint pegawai (README kontrak Kepegawaian, "Pegawai, biodata, koreksi NIP"; pemilik WS-2, MAKE-010) lewat
 * klien `api` (`@/lib/axios`, ADR-022):
 *   GET  pegawai                     daftar, disaring lingkup pemanggil (role 1, 3, 4, 5, 8)
 *   GET  pegawai/export              ekspor csv|xlsx daftar terfilter (role 1, 3, 4, 5, 8; MAKE-014)
 *   GET  pegawai/pencarian/katalog, POST pegawai/pencarian(/export)   pencarian lanjutan (role 1, 3, 4, 5; MAKE-014)
 *   GET  pegawai/{nip}               detail + `tabs` (descriptor tab riwayat) + `aksi`
 *   PUT  pegawai/{nip}/biodata       ubah biodata dua jalur (JSON; dengan foto: POST multipart + `_method=PUT`)
 *   GET  pegawai/{nip}/foto          foto profil terkini (blob)
 *   POST pegawai/{nip}/koreksi-nip   koreksi NIP (role 1)
 *   POST pegawai / DELETE pegawai/{nip}   tambah (multipart + foto) / hapus permanen ikut legacy (role 1, B-05 MAKE-011)
 *   GET  biodata/pengajuan[/{id}[/foto]], POST biodata/pengajuan/{id}/process   approval biodata (B-04 MAKE-011)
 * Tidak ada data contoh di runtime — fixture hanya di `__tests__/`.
 */
import { api } from '@/lib/axios'

import { ambilBerkas, kirimBerkas, type BerkasUnduhan } from './berkasUnduhan'

import type { ApprovalPayload } from '@/shared/components/approvalActions'

import type {
  BiodataPayload,
  BiodataResult,
  KoreksiNipResult,
  Pegawai,
  PegawaiDetail,
  PegawaiListQuery,
  PegawaiListResponse,
  PengajuanBiodataDetail,
  PengajuanBiodataItem,
  KatalogPencarian,
  KriteriaPencarian,
  TambahPegawaiResult,
} from '../types'

const item = (nip: string): string => `/pegawai/${encodeURIComponent(nip)}`

const FILTER_KEYS = ['search', 'status', 'id_unit', 'id_satker', 'id_jenis_pegawai', 'sort', 'order'] as const

function filterParams(query: Omit<PegawaiListQuery, 'page' | 'per_page'>): Record<string, string> {
  const params: Record<string, string> = {}
  for (const key of FILTER_KEYS) {
    const value = query[key]
    if (value) params[key] = value
  }
  return params
}

export const pegawaiService = {
  async list(query: PegawaiListQuery): Promise<PegawaiListResponse> {
    const { data } = await api.get<PegawaiListResponse>('/pegawai', { params: { page: query.page, per_page: query.per_page, ...filterParams(query) } })
    return data
  },

  /** Ekspor daftar terfilter; `kolom` = kunci kolom tampil (Nama/NIP selalu ikut). */
  ekspor(format: 'csv' | 'xlsx', kolom: string[], query: Omit<PegawaiListQuery, 'page' | 'per_page'>): Promise<BerkasUnduhan> {
    return ambilBerkas('/pegawai/export', 'daftar_pegawai', { format, kolom: kolom.join(','), ...filterParams(query) })
  },

  async katalogPencarian(): Promise<KatalogPencarian> {
    const { data } = await api.get<KatalogPencarian>('/pegawai/pencarian/katalog')
    return data
  },

  async pencarian(kriteria: KriteriaPencarian[], query: Pick<PegawaiListQuery, 'page' | 'per_page' | 'sort' | 'order'>): Promise<PegawaiListResponse> {
    const { data } = await api.post<PegawaiListResponse>('/pegawai/pencarian', { kriteria, ...query })
    return data
  },

  eksporPencarian(format: 'csv' | 'xlsx', kolom: string[], kriteria: KriteriaPencarian[]): Promise<BerkasUnduhan> {
    return kirimBerkas('/pegawai/pencarian/export', { format, kolom: kolom.join(','), kriteria }, 'pencarian_pegawai')
  },

  async detail(nip: string): Promise<PegawaiDetail> {
    const { data } = await api.get<PegawaiDetail>(item(nip))
    return data
  },

  /** Ubah biodata; dengan `foto` dikirim multipart (`POST` + `_method=PUT`, PHP tidak mem-parse multipart pada PUT). */
  async updateBiodata(nip: string, payload: BiodataPayload, foto?: File | null): Promise<BiodataResult> {
    if (!foto) {
      const { data } = await api.put<BiodataResult>(`${item(nip)}/biodata`, payload)
      return data
    }
    const form = new FormData()
    for (const [key, value] of Object.entries(payload)) form.append(key, value)
    form.append('_method', 'PUT')
    form.append('foto', foto, foto.name)
    const { data } = await api.post<BiodataResult>(`${item(nip)}/biodata`, form)
    return data
  },

  async foto(nip: string): Promise<Blob> {
    const { data } = await api.get<Blob>(`${item(nip)}/foto`, { responseType: 'blob' })
    return data
  },

  /** Tambah pegawai (role 1): selalu multipart karena foto wajib. */
  async tambah(payload: BiodataPayload, foto: File): Promise<TambahPegawaiResult> {
    const form = new FormData()
    for (const [key, value] of Object.entries(payload)) form.append(key, value)
    form.append('foto', foto, foto.name)
    const { data } = await api.post<TambahPegawaiResult>('/pegawai', form)
    return data
  },

  async hapus(nip: string): Promise<{ deleted: boolean; soft_delete: boolean; item: Pegawai }> {
    const { data } = await api.delete<{ deleted: boolean; soft_delete: boolean; item: Pegawai }>(item(nip))
    return data
  },

  async antreanPengajuan(query: { page: number; per_page: number; search?: string }): Promise<{ items: PengajuanBiodataItem[]; total: number; page: number; per_page: number }> {
    const { data } = await api.get<{ items: PengajuanBiodataItem[]; total: number; page: number; per_page: number }>('/biodata/pengajuan', {
      params: { page: query.page, per_page: query.per_page, ...(query.search ? { search: query.search } : {}) },
    })
    return data
  },

  async pengajuan(id: number): Promise<PengajuanBiodataDetail> {
    const { data } = await api.get<PengajuanBiodataDetail>(`/biodata/pengajuan/${id}`)
    return data
  },

  async fotoPengajuan(id: number): Promise<Blob> {
    const { data } = await api.get<Blob>(`/biodata/pengajuan/${id}/foto`, { responseType: 'blob' })
    return data
  },

  async prosesPengajuan(id: number, payload: ApprovalPayload): Promise<PengajuanBiodataDetail> {
    const { data } = await api.post<PengajuanBiodataDetail>(`/biodata/pengajuan/${id}/process`, payload)
    return data
  },

  /** Cetak DRH PDF (MAKE-014; nama berkas dari server). */
  drh(nip: string): Promise<BerkasUnduhan> {
    return ambilBerkas(`${item(nip)}/drh`, `drh_${nip}`)
  },

  async koreksiNip(nip: string, nipBaru: string): Promise<KoreksiNipResult> {
    const { data } = await api.post<KoreksiNipResult>(`${item(nip)}/koreksi-nip`, { nip_baru: nipBaru })
    return data
  },
}
