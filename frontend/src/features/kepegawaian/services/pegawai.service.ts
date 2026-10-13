/**
 * Klien endpoint pegawai (README kontrak Kepegawaian, "Pegawai, biodata, koreksi NIP"; pemilik WS-2, MAKE-010) lewat
 * klien `api` (`@/lib/axios`, ADR-022):
 *   GET  pegawai                     daftar, disaring lingkup pemanggil (role 1, 3, 4, 5, 8)
 *   GET  pegawai/{nip}               detail + `tabs` (descriptor tab riwayat) + `aksi`
 *   PUT  pegawai/{nip}/biodata       ubah biodata dua jalur (JSON; dengan foto: POST multipart + `_method=PUT`)
 *   GET  pegawai/{nip}/foto          foto profil terkini (blob)
 *   POST pegawai/{nip}/koreksi-nip   koreksi NIP (role 1)
 * Tidak ada data contoh di runtime — fixture hanya di `__tests__/`.
 */
import { api } from '@/lib/axios'

import type { BiodataPayload, BiodataResult, KoreksiNipResult, PegawaiDetail, PegawaiListQuery, PegawaiListResponse } from '../types'

const item = (nip: string): string => `/pegawai/${encodeURIComponent(nip)}`

export const pegawaiService = {
  async list(query: PegawaiListQuery): Promise<PegawaiListResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    for (const key of ['search', 'status', 'id_unit', 'id_satker', 'id_jenis_pegawai', 'sort', 'order'] as const) {
      const value = query[key]
      if (value) params[key] = value
    }
    const { data } = await api.get<PegawaiListResponse>('/pegawai', { params })
    return data
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

  async koreksiNip(nip: string, nipBaru: string): Promise<KoreksiNipResult> {
    const { data } = await api.post<KoreksiNipResult>(`${item(nip)}/koreksi-nip`, { nip_baru: nipBaru })
    return data
  },
}
