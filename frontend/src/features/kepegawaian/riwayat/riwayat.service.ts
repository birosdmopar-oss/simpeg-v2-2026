/**
 * Klien endpoint riwayat engine (README kontrak Kepegawaian; pemilik WS-1), lewat klien `api` (`@/lib/axios`, ADR-022):
 *   GET    pegawai/{nip}/riwayat/{jenis}                daftar (semua status kecuali 10)
 *   POST   pegawai/{nip}/riwayat/{jenis}                tambah — multipart: field kolom DDL + berkas[<id_riwayat>];
 *                                                         JSON hanya bila tidak ada berkas (jenis tanpa lampiran wajib)
 *   PUT    pegawai/{nip}/riwayat/{jenis}/{id}           ubah — JSON; dengan berkas: POST multipart + _method=PUT
 *   DELETE pegawai/{nip}/riwayat/{jenis}/{id}           hapus lunak (status 10)
 *   POST   pegawai/{nip}/riwayat/{jenis}/{id}/process   { aksi, reason_note }
 *
 * Kontrak engine (README backend `Controllers/Api/Kepegawaian`, "Rincian engine riwayat"): respons riwayat = kolom DDL
 * tabel riwayat saja, lampiran TIDAK ikut (baca lewat lampiran.service / ArsipTable); galat 422 berkunci kolom DDL dan
 * `berkas.<id_riwayat>`; `reason_note` opsional saat setujui, wajib saat tolak (maks 255 byte).
 */
import { api } from '@/lib/axios'

import type { JenisEngine, ProcessRiwayatPayload, RiwayatRow, RiwayatTabDescriptor } from '../types'

import type { FieldDef } from './riwayat.config'

/** Nilai form (string, seperti input HTML). */
export type RiwayatValues = Record<string, string>
/**
 * Berkas per kode `jenis_rwy` (`document_attachment.id_riwayat`), atau per nama kolom berkas Definisi (`berkas[file_1]`,
 * aditif MAKE-007, mis. usulan Karpeg).
 */
export type BerkasMap = Record<number | string, File>

const base = (nip: string, jenis: JenisEngine): string => `/pegawai/${encodeURIComponent(nip)}/riwayat/${jenis}`
const item = (nip: string, jenis: JenisEngine, id: number | string): string => `${base(nip, jenis)}/${encodeURIComponent(String(id))}`

/** Payload JSON: kolom kosong → null (kolom DDL nullable), angka → number. */
export function toJsonPayload(fields: readonly FieldDef[], values: RiwayatValues): Record<string, string | number | null> {
  const payload: Record<string, string | number | null> = {}
  for (const f of fields) {
    const raw = (values[f.name] ?? '').trim()
    if (raw === '') payload[f.name] = null
    else payload[f.name] = f.type === 'number' ? Number(raw) : raw
  }
  return payload
}

/**
 * Payload multipart: field kolom DDL + `berkas[<id_riwayat>]`. `method` = 'PUT' menambah `_method=PUT` (spoofing CI4).
 * Kolom kosong dikirim sebagai string kosong = NULL (kontrak engine: '' pada field opsional = NULL di JSON/form/multipart).
 */
export function toFormData(fields: readonly FieldDef[], values: RiwayatValues, berkas: BerkasMap, method?: 'PUT'): FormData {
  const form = new FormData()
  if (method) form.append('_method', method)
  for (const f of fields) form.append(f.name, (values[f.name] ?? '').trim())
  for (const [idRiwayat, file] of Object.entries(berkas)) form.append(`berkas[${idRiwayat}]`, file, file.name)
  return form
}

const hasBerkas = (berkas: BerkasMap): boolean => Object.keys(berkas).length > 0

export const riwayatService = {
  /**
   * `GET pegawai/{nip}/riwayat` → descriptor tab (aditif MAKE-006; sumber sama dengan `data.tabs` detail pegawai).
   * Tidak lagi dipakai halaman (halaman sementara dialihkan ke Detail Pegawai, MAKE-016); dipertahankan sebagai klien
   * kontrak endpoint aditif.
   */
  async tabs(nip: string): Promise<RiwayatTabDescriptor[]> {
    const { data } = await api.get<{ tabs: RiwayatTabDescriptor[] }>(`/pegawai/${encodeURIComponent(nip)}/riwayat`)
    return data.tabs
  },

  /**
   * `GET pegawai/{nip}/riwayat/{jenis}/pratinjau?<query>` → isian turunan yang akan diisi server saat tambah (aditif
   * MAKE-017; izin = aksi tambah).
   */
  async pratinjau(nip: string, jenis: JenisEngine, query: Record<string, string>): Promise<Record<string, unknown>> {
    const params = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== ''))
    const { data } = await api.get<Record<string, unknown>>(`${base(nip, jenis)}/pratinjau`, { params })
    return data
  },

  async list(nip: string, jenis: JenisEngine): Promise<RiwayatRow[]> {
    const { data } = await api.get<RiwayatRow[]>(base(nip, jenis))
    return data
  },

  async create(nip: string, jenis: JenisEngine, fields: readonly FieldDef[], values: RiwayatValues, berkas: BerkasMap = {}): Promise<RiwayatRow> {
    const body = hasBerkas(berkas) ? toFormData(fields, values, berkas) : toJsonPayload(fields, values)
    const { data } = await api.post<RiwayatRow>(base(nip, jenis), body)
    return data
  },

  async update(
    nip: string,
    jenis: JenisEngine,
    id: number | string,
    fields: readonly FieldDef[],
    values: RiwayatValues,
    berkas: BerkasMap = {},
  ): Promise<RiwayatRow> {
    // PHP tidak mem-parse multipart pada PUT: dengan berkas → POST multipart + _method=PUT (huruf besar).
    const { data } = hasBerkas(berkas)
      ? await api.post<RiwayatRow>(item(nip, jenis, id), toFormData(fields, values, berkas, 'PUT'))
      : await api.put<RiwayatRow>(item(nip, jenis, id), toJsonPayload(fields, values))
    return data
  },

  async remove(nip: string, jenis: JenisEngine, id: number | string): Promise<void> {
    await api.delete(item(nip, jenis, id))
  },

  /** `GET pegawai/{nip}/riwayat/{jenis}/{id}/berkas/{kolom}` — unduh berkas kolom (aditif MAKE-007). */
  async unduhKolomBerkas(nip: string, jenis: JenisEngine, id: number | string, kolom: string): Promise<Blob> {
    const { data } = await api.get<Blob>(`${item(nip, jenis, id)}/berkas/${encodeURIComponent(kolom)}`, { responseType: 'blob' })
    return data
  },

  async process(nip: string, jenis: JenisEngine, id: number | string, payload: ProcessRiwayatPayload): Promise<RiwayatRow> {
    const { data } = await api.post<RiwayatRow>(`${item(nip, jenis, id)}/process`, payload)
    return data
  },
}
