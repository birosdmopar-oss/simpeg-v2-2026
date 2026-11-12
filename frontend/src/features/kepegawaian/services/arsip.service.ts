/**
 * Klien endpoint Arsip (F-10 Tahap 1, MAKE-091; kontrak PRD F-10 §8.1, pemilik backend WS-2 MAKE-090):
 *   GET pegawai/{nip}/arsip                    folder agregat + identitas ringkas pegawai
 *   GET pegawai/{nip}/arsip/berkas             daftar berkas berhalaman (id_jenis_rwy, q, sort, order, page, per_page)
 *   GET pegawai/{nip}/arsip/berkas/{id}/buka   berkas unggahan v2 (privat) — diambil lewat `ambilBerkas` (berkasUnduhan.ts)
 * Tidak ada data contoh di runtime — fixture hanya di `__tests__/`.
 */
import { api } from '@/lib/axios'

import type { ArsipBerkasQuery, ArsipBerkasResponse, ArsipFolderResponse } from '../arsip.types'

const base = (nip: string): string => `/pegawai/${encodeURIComponent(nip)}/arsip`

/** Hanya parameter yang terisi yang dikirim. */
function paramsBerkas(query: ArsipBerkasQuery): Record<string, string | number> {
  const params: Record<string, string | number> = {}
  if (query.id_jenis_rwy !== undefined) params.id_jenis_rwy = query.id_jenis_rwy
  if (query.q) params.q = query.q
  if (query.sort) params.sort = query.sort
  if (query.order) params.order = query.order
  if (query.page) params.page = query.page
  if (query.per_page) params.per_page = query.per_page
  return params
}

export const arsipService = {
  async folder(nip: string): Promise<ArsipFolderResponse> {
    const { data } = await api.get<ArsipFolderResponse>(base(nip))
    return data
  },

  async berkas(nip: string, query: ArsipBerkasQuery = {}): Promise<ArsipBerkasResponse> {
    const { data } = await api.get<ArsipBerkasResponse>(`${base(nip)}/berkas`, { params: paramsBerkas(query) })
    return data
  },
}
