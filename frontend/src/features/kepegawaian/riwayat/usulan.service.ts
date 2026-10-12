/**
 * Klien halaman usulan mandiri (WS-1 M4 MAKE-007, README kontrak backend):
 *   GET usulan/{jenis}/saya                              usulan milik pemanggil (role 2/7) + identitas & hak
 *   GET usulan/{jenis}?status=                           antrean (role 1/4/5, tanpa lingkup) + `can_process`
 *   GET pegawai/{nip}/riwayat/{jenis}/{id}/berkas/{kolom} unduh berkas kolom
 * Ajukan/ubah/hapus/proses memakai riwayatService (endpoint engine `{jenis}`), berkas kolom = `berkas[<kolom>]`.
 */
import { api } from '@/lib/axios'

import type { JenisEngine, RiwayatRow } from '../types'

export interface UsulanSaya {
  pegawai: { nip: string; nama: string; glr_awal: string | null; glr_akhir: string | null; jenis_kelamin: number | string }
  can_create: boolean
  can_edit: boolean
  can_delete: boolean
  /** Karis/Karsu: pegawai punya riwayat keluarga aktif (syarat legacy). */
  syarat_terpenuhi: boolean
  items: RiwayatRow[]
}

export interface UsulanAntrean {
  can_process: boolean
  /** Baris riwayat + `nama`, `glr_awal`, `glr_akhir`, `unit`, `satker`. */
  items: RiwayatRow[]
}

export const usulanService = {
  async saya(jenis: JenisEngine): Promise<UsulanSaya> {
    const { data } = await api.get<UsulanSaya>(`/usulan/${jenis}/saya`)
    return data
  },

  async antrean(jenis: JenisEngine, status = ''): Promise<UsulanAntrean> {
    const { data } = await api.get<UsulanAntrean>(`/usulan/${jenis}`, { params: status === '' ? {} : { status } })
    return data
  },

  async unduh(nip: string, jenis: JenisEngine, id: number | string, kolom: string): Promise<Blob> {
    const { data } = await api.get<Blob>(`/pegawai/${encodeURIComponent(nip)}/riwayat/${jenis}/${encodeURIComponent(String(id))}/berkas/${kolom}`, {
      responseType: 'blob',
    })
    return data
  },
}
