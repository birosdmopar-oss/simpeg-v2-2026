/**
 * Klien sub-resource anak riwayat keluarga (WS-1 M4 MAKE-007, README kontrak backend):
 *   GET    pegawai/{nip}/riwayat/keluarga/{id}/anak              daftar (urut `urutan`)
 *   POST   pegawai/{nip}/riwayat/keluarga/{id}/anak              tambah — multipart: kolom DDL `detail_anak` + `berkas` (akte)
 *   PUT    pegawai/{nip}/riwayat/keluarga/{id}/anak/{idAnak}     ubah — JSON; dengan berkas: POST multipart + _method=PUT
 *   DELETE pegawai/{nip}/riwayat/keluarga/{id}/anak/{idAnak}     hapus (hapus keras + akte)
 * Galat 422: `errors.<kolom>`, `errors.berkas` (akte). Akte = lampiran kode 3 (`id_entri` = id anak).
 */
import { api } from '@/lib/axios'

import type { RiwayatRow } from '../types'

import type { FieldDef } from './riwayat.config'
import { toFormData, toJsonPayload, type RiwayatValues } from './riwayat.service'

/** Kode `jenis_rwy` akte lahir anak (Keluarga::LAMPIRAN_AKTE_ANAK). */
export const LAMPIRAN_AKTE_ANAK = 3

const base = (nip: string, idKeluarga: number | string): string =>
  `/pegawai/${encodeURIComponent(nip)}/riwayat/keluarga/${encodeURIComponent(String(idKeluarga))}/anak`

function multipart(fields: readonly FieldDef[], values: RiwayatValues, akte: File, method?: 'PUT'): FormData {
  const form = toFormData(fields, values, {}, method)
  form.append('berkas', akte, akte.name)
  return form
}

export const anakService = {
  async list(nip: string, idKeluarga: number | string): Promise<RiwayatRow[]> {
    const { data } = await api.get<RiwayatRow[]>(base(nip, idKeluarga))
    return data
  },

  async create(nip: string, idKeluarga: number | string, fields: readonly FieldDef[], values: RiwayatValues, akte: File | null): Promise<RiwayatRow> {
    const body = akte ? multipart(fields, values, akte) : toJsonPayload(fields, values)
    const { data } = await api.post<RiwayatRow>(base(nip, idKeluarga), body)
    return data
  },

  async update(
    nip: string,
    idKeluarga: number | string,
    idAnak: number | string,
    fields: readonly FieldDef[],
    values: RiwayatValues,
    akte: File | null,
  ): Promise<RiwayatRow> {
    const url = `${base(nip, idKeluarga)}/${encodeURIComponent(String(idAnak))}`
    const { data } = akte ? await api.post<RiwayatRow>(url, multipart(fields, values, akte, 'PUT')) : await api.put<RiwayatRow>(url, toJsonPayload(fields, values))
    return data
  },

  async remove(nip: string, idKeluarga: number | string, idAnak: number | string): Promise<void> {
    await api.delete(`${base(nip, idKeluarga)}/${encodeURIComponent(String(idAnak))}`)
  },
}
