/**
 * F-11 SKL (MAKE-084) — klien `survei-layanan*` lewat `api` terpusat. Pegawai role 2/6: `saya`/kirim; rekap & ringkasan role 1.
 */
import { api } from '@/lib/axios'

import type { BarisSkl, KirimSkl, QuerySkl, RingkasanSkl, TagihanSkl } from '../types'

export const surveiLayananService = {
  async saya(): Promise<TagihanSkl[]> {
    const { data } = await api.get<TagihanSkl[]>('/survei-layanan/saya')
    return data ?? []
  },

  /** 201 `tercatat` atau 200 `sudah_tercatat` (kirim ulang, Q7-21) — keduanya sukses. */
  async kirim(input: KirimSkl): Promise<{ status: 'tercatat' | 'sudah_tercatat' }> {
    const { data } = await api.post<{ status: 'tercatat' | 'sudah_tercatat' }>('/survei-layanan', input)
    return data
  },

  async rekap(query: QuerySkl = {}): Promise<{ items: BarisSkl[]; total: number; page: number; per_page: number }> {
    const { data } = await api.get<{ items: BarisSkl[]; total: number; page: number; per_page: number }>('/survei-layanan', { params: query })
    return data
  },

  async unduhXlsx(query: QuerySkl = {}): Promise<Blob> {
    const { data } = await api.get<Blob>('/survei-layanan', { params: { ...query, format: 'xlsx' }, responseType: 'blob' })
    return data
  },

  async ringkasan(query: { dari?: string; sampai?: string } = {}): Promise<RingkasanSkl> {
    const { data } = await api.get<RingkasanSkl>('/survei-layanan/ringkasan', { params: query })
    return data
  },
}
