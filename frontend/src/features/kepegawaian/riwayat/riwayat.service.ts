/**
 * Klien endpoint riwayat engine (README kontrak Kepegawaian; pemilik WS-1), lewat klien `api` (`@/lib/axios`, ADR-022):
 *   GET    pegawai/{nip}/riwayat/{jenis}                daftar (semua status kecuali 10)
 *   POST   pegawai/{nip}/riwayat/{jenis}                tambah — multipart: field kolom DDL + berkas[<id_riwayat>];
 *                                                         JSON hanya bila tidak ada berkas (jenis tanpa lampiran wajib)
 *   PUT    pegawai/{nip}/riwayat/{jenis}/{id}           ubah — JSON; dengan berkas: POST multipart + _method=PUT
 *   DELETE pegawai/{nip}/riwayat/{jenis}/{id}           hapus lunak (status 10)
 *   POST   pegawai/{nip}/riwayat/{jenis}/{id}/process   { aksi, reason_note }
 *
 * TODO(kontrak): apakah daftar lampiran ikut di respons riwayat belum ditetapkan — baca lampiran lewat lampiran.service.
 */
import { api } from '@/lib/axios'

import type { JenisEngine, ProcessRiwayatPayload, RiwayatRow } from '../types'

import type { FieldDef } from './riwayat.config'

/** Nilai form (string, seperti input HTML). */
export type RiwayatValues = Record<string, string>
/** Berkas per kode `jenis_rwy` (`document_attachment.id_riwayat`). */
export type BerkasMap = Record<number, File>

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
 * TODO(kontrak): representasi NULL di multipart belum ditetapkan — sementara kolom kosong dikirim sebagai string kosong.
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

  async process(nip: string, jenis: JenisEngine, id: number | string, payload: ProcessRiwayatPayload): Promise<RiwayatRow> {
    const { data } = await api.post<RiwayatRow>(`${item(nip, jenis, id)}/process`, payload)
    return data
  },
}
