/**
 * Klien endpoint pegawai (README kontrak Kepegawaian; pemilik WS-2) lewat klien `api` (`@/lib/axios`, ADR-022):
 *   GET pegawai          daftar, disaring lingkup pemanggil (role 1, 3, 4, 5, 8)
 *   GET pegawai/{nip}    detail + `tabs` (descriptor tab riwayat)
 * Selama endpoint masih stub, backend menjawab 404/501; halaman menampilkan keadaan kosong/galat (apiErrors.ts).
 * Tidak ada data contoh di runtime — fixture hanya di `__tests__/`.
 */
import { api } from '@/lib/axios'

import type { PegawaiDetail, PegawaiListQuery, PegawaiListResponse } from '../types'

export const pegawaiService = {
  async list(query: PegawaiListQuery): Promise<PegawaiListResponse> {
    const { data } = await api.get<PegawaiListResponse>('/pegawai', {
      params: { page: query.page, per_page: query.per_page, search: query.search || undefined },
    })
    return data
  },

  async detail(nip: string): Promise<PegawaiDetail> {
    const { data } = await api.get<PegawaiDetail>(`/pegawai/${encodeURIComponent(nip)}`)
    return data
  },
}
