/**
 * Klien master Angka Kredit JF `dm_ak_jf` (MAKE-021, role 1; legacy Data Master > Angka Kredit JF):
 *   GET  ak-jf                                daftar
 *   GET  ak-jf/opsi                           pilihan form tambah (group 2/3, sub group, jenjang JF, pangkat non-CPNS)
 *   GET  ak-jf/jabatan?id_group_jabatan=&id_sub_group_jabatan=&id_jenjang_jf=   jabatan aktif
 *   POST ak-jf                                tambah { id_jabatan, id_pangkat, ak }
 *   PUT  ak-jf/{id_jabatan}/{id_pangkat}      ubah { ak }
 * Tanpa hapus [K] (hapus legacy tidak berfungsi).
 */
import { api } from '@/lib/axios'

export interface AkJfRow {
  id_jabatan: number
  id_pangkat: number
  ak: number
  jabatan: string
  id_group_jabatan: number | null
  group_jabatan: string | null
  id_sub_group_jabatan: number | null
  sub_group_jabatan: string | null
  id_jenjang_jf: number | null
  kategori_jf: string | null
  jenjang_jf: string | null
  gol_ruang: string
  pangkat: string
  updated_at: string | null
}

export interface AkJfOpsi {
  group_jabatan: { id_group_jabatan: number | string; group_jabatan: string }[]
  sub_group_jabatan: { id_sub_group_jabatan: number | string; id_group_jabatan: number | string; sub_group_jabatan: string }[]
  jenjang_jf: { id_jenjang_jf: number | string; kategori_jf: string; jenjang_jf: string }[]
  pangkat: { id_pangkat: number | string; pangkat: string; gol_ruang: string }[]
}

export interface AkJfJabatan {
  id_jabatan: number | string
  jabatan: string
}

export const akJfService = {
  async list(): Promise<AkJfRow[]> {
    const { data } = await api.get<AkJfRow[]>('/ak-jf')
    return data
  },

  async opsi(): Promise<AkJfOpsi> {
    const { data } = await api.get<AkJfOpsi>('/ak-jf/opsi')
    return data
  },

  async jabatan(filter: { id_group_jabatan: string; id_sub_group_jabatan: string; id_jenjang_jf: string }): Promise<AkJfJabatan[]> {
    const { data } = await api.get<AkJfJabatan[]>('/ak-jf/jabatan', { params: filter })
    return data
  },

  async create(payload: { id_jabatan: string; id_pangkat: string; ak: string }): Promise<AkJfRow> {
    const { data } = await api.post<AkJfRow>('/ak-jf', payload)
    return data
  },

  async update(idJabatan: number, idPangkat: number, ak: string): Promise<AkJfRow> {
    const { data } = await api.put<AkJfRow>(`/ak-jf/${idJabatan}/${idPangkat}`, { ak })
    return data
  },
}
