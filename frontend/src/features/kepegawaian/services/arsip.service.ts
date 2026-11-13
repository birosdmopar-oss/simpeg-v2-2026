/**
 * Klien endpoint Arsip (F-10 Tahap 1 MAKE-091 + Tahap 2 MAKE-096; kontrak PRD F-10 §8.1, backend WS-2 MAKE-090/092):
 *   GET pegawai/{nip}/arsip                    folder agregat + identitas ringkas pegawai
 *   GET pegawai/{nip}/arsip/berkas             daftar berkas berhalaman (id_jenis_rwy, q, sort, order, page, per_page)
 *   GET pegawai/{nip}/arsip/berkas/{id}/buka   berkas unggahan v2 (privat) — diambil lewat `ambilBerkas` (berkasUnduhan.ts)
 *   Tahap 2: PATCH berkas/{id} {tag}, POST berkas/{id}/hubungkan, DELETE berkas/{id}, GET opsi-hubungkan, GET ekspor
 * Tidak ada data contoh di runtime — fixture hanya di `__tests__/`.
 */
import { api } from '@/lib/axios'

import { ambilBerkas, type BerkasUnduhan } from './berkasUnduhan'

import type {
  ArsipBerkasQuery,
  ArsipBerkasResponse,
  ArsipFolderResponse,
  ArsipHubungkanPayload,
  ArsipOpsiHubungkanResponse,
  FormatEksporArsip,
} from '../arsip.types'

const base = (nip: string): string => `/pegawai/${encodeURIComponent(nip)}/arsip`

/** Hanya parameter yang terisi yang dikirim. */
function paramsBerkas(query: ArsipBerkasQuery): Record<string, string | number> {
  const params: Record<string, string | number> = {}
  if (query.id_jenis_rwy !== undefined) params.id_jenis_rwy = query.id_jenis_rwy
  if (query.belum_terhubung) params.belum_terhubung = 1
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

  // ---- Tahap 2 (MAKE-096; backend MAKE-092) ----

  /** Ubah nama arsip (kolom `tag`). 422 `errors.tag`. */
  async ubahNama(nip: string, id: number, tag: string): Promise<void> {
    await api.patch(`${base(nip)}/berkas/${id}`, { tag })
  },

  /** Hubungkan arsip yatim (kode 0) ke riwayat Disetujui; kode 38 tanpa `id_entri`. */
  async hubungkan(nip: string, id: number, payload: ArsipHubungkanPayload): Promise<void> {
    await api.post(`${base(nip)}/berkas/${id}/hubungkan`, payload)
  },

  /** Hapus baris arsip yatim (kode 0); berkas fisik tertinggal (F10-09). */
  async hapus(nip: string, id: number): Promise<void> {
    await api.delete(`${base(nip)}/berkas/${id}`)
  },

  /** Calon data riwayat tujuan Hubungkan untuk satu jenis. */
  async opsiHubungkan(nip: string, idRiwayat: number): Promise<ArsipOpsiHubungkanResponse> {
    const { data } = await api.get<ArsipOpsiHubungkanResponse>(`${base(nip)}/opsi-hubungkan`, { params: { id_riwayat: idRiwayat } })
    return data
  },

  /** Ekspor Excel / CSV mengikuti filter yang aktif (kolom Nama Arsip, Jenis Riwayat, Data Riwayat; tanpa URL berkas). */
  ekspor(nip: string, format: FormatEksporArsip, query: ArsipBerkasQuery = {}): Promise<BerkasUnduhan> {
    return ambilBerkas(`${base(nip)}/ekspor`, 'arsip', { format, ...paramsBerkas({ ...query, page: undefined, per_page: undefined, sort: undefined, order: undefined }) })
  },
}
