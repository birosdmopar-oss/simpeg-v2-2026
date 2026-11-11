/**
 * F-06 Form Digital (MAKE-083) — klien `form-digital*`. Semua lewat `api` terpusat (amplop sudah dibuka interceptor).
 * Role ditegakkan backend: pegawai (2/6/7) `saya`/`isi`, kelola dan rekap role 1.
 */
import { api } from '@/lib/axios'
import type { SelectOption } from '@/shared/ui'

import type { BarisTarget, FormDetail, FormInput, FormRingkas, FormSaya, Halaman, NilaiJawaban, RekapRespons } from '../types'

export interface QueryDaftar {
  q?: string
  status?: number
  page?: number
  per_page?: number
}

export const formDigitalService = {
  /** Opsi unit/satker penargetan (endpoint bersama penargetan Jalur A, role 1). */
  async opsiUnitSatker(): Promise<SelectOption[]> {
    const { data } = await api.get<SelectOption[]>('/konten/opsi/unit-satker')
    return data ?? []
  },

  async saya(): Promise<FormSaya[]> {
    const { data } = await api.get<FormSaya[]>('/form-digital/saya')
    return data ?? []
  },

  /** multipart: `jawaban` (JSON) + `berkas_<idPertanyaan>`. 409 `sudah_diisi` bila sudah pernah diisi. */
  async isi(id: number, jawaban: Record<string, NilaiJawaban>, berkas: Record<string, File>): Promise<{ id: number }> {
    const fd = new FormData()
    fd.append('jawaban', JSON.stringify(jawaban))
    for (const [k, f] of Object.entries(berkas)) fd.append(`berkas_${k}`, f)
    const { data } = await api.post<{ id: number }>(`/form-digital/${id}/isi`, fd)
    return data
  },

  async daftar(query: QueryDaftar = {}): Promise<Halaman<FormRingkas>> {
    const { data } = await api.get<Halaman<FormRingkas>>('/form-digital', { params: query })
    return data
  },

  async detail(id: number): Promise<FormDetail> {
    const { data } = await api.get<FormDetail>(`/form-digital/${id}`)
    return data
  },

  async buat(input: FormInput): Promise<FormDetail> {
    const { data } = await api.post<FormDetail>('/form-digital', input)
    return data
  },

  async ubah(id: number, input: FormInput): Promise<FormDetail> {
    const { data } = await api.put<FormDetail>(`/form-digital/${id}`, input)
    return data
  },

  async setStatus(id: number, status: 1 | 2): Promise<void> {
    await api.patch(`/form-digital/${id}/status`, { status })
  },

  async hapus(id: number): Promise<void> {
    await api.delete(`/form-digital/${id}`)
  },

  async target(id: number, query: { isi?: 'sudah' | 'belum'; q?: string; page?: number; per_page?: number } = {}): Promise<Halaman<BarisTarget>> {
    const { data } = await api.get<Halaman<BarisTarget>>(`/form-digital/${id}/target`, { params: query })
    return data
  },

  async respons(id: number, query: { q?: string; page?: number; per_page?: number } = {}): Promise<RekapRespons> {
    const { data } = await api.get<RekapRespons>(`/form-digital/${id}/respons`, { params: query })
    return data
  },

  async unduhXlsx(id: number): Promise<Blob> {
    const { data } = await api.get<Blob>(`/form-digital/${id}/respons`, { params: { format: 'xlsx' }, responseType: 'blob' })
    return data
  },

  async unduhBerkas(id: number, idJawaban: number, idPertanyaan: string): Promise<Blob> {
    const { data } = await api.get<Blob>(`/form-digital/${id}/respons/${idJawaban}/berkas/${idPertanyaan}`, { responseType: 'blob' })
    return data
  },
}
