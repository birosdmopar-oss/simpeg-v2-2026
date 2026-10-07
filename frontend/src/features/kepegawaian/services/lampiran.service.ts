/**
 * Klien endpoint lampiran untuk baris riwayat yang SUDAH ada (README kontrak Kepegawaian, B-18; pemilik WS-2):
 *   GET    pegawai/{nip}/lampiran?id_riwayat=&id_entri=   daftar lampiran satu baris
 *   POST   pegawai/{nip}/lampiran                        multipart: berkas, id_riwayat, id_entri
 *   GET    pegawai/{nip}/lampiran/{id}/unduh             unduh berkas
 *   DELETE pegawai/{nip}/lampiran/{id}                   hapus keras
 * Lampiran milik NIP lain dijawab 404. Baris lampiran = `document_attachment` (kunci `NIP` huruf besar, tanpa `path`).
 * Galat 422 endpoint ini berkunci `errors.berkas`.
 */
import { api } from '@/lib/axios'

import type { DocumentAttachment } from '../types'

export interface LampiranTarget {
  /** Kode `jenis_rwy`. */
  id_riwayat: number
  /** PK baris riwayat pemilik lampiran. */
  id_entri: number | string
}

const base = (nip: string): string => `/pegawai/${encodeURIComponent(nip)}/lampiran`

export const lampiranService = {
  /** TODO(kontrak): wajib-tidaknya query `id_riwayat`/`id_entri` belum ditetapkan — sementara selalu dikirim keduanya. */
  async list(nip: string, target: LampiranTarget): Promise<DocumentAttachment[]> {
    const { data } = await api.get<DocumentAttachment[]>(base(nip), {
      params: { id_riwayat: target.id_riwayat, id_entri: String(target.id_entri) },
    })
    return data
  },

  async upload(nip: string, target: LampiranTarget, berkas: File): Promise<DocumentAttachment> {
    const form = new FormData()
    form.append('berkas', berkas, berkas.name)
    form.append('id_riwayat', String(target.id_riwayat))
    form.append('id_entri', String(target.id_entri))
    const { data } = await api.post<DocumentAttachment>(base(nip), form)
    return data
  },

  async download(nip: string, idAttachment: number): Promise<Blob> {
    const { data } = await api.get<Blob>(`${base(nip)}/${idAttachment}/unduh`, { responseType: 'blob' })
    return data
  },

  async remove(nip: string, idAttachment: number): Promise<void> {
    await api.delete(`${base(nip)}/${idAttachment}`)
  },

  // TODO(kontrak, WS-2 MAKE-009): semantik "ganti" lampiran belum ditetapkan — sengaja belum ada fungsi ganti.
}
