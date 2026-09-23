/**
 * G-08 — Hari libur. CRUD = role 1; `calendar` (hari libur aktif dalam rentang) terbuka untuk semua role login
 * dan dipakai kalender presensi / kalkulasi hari kerja.
 */
import { api } from '@/lib/axios'

import type { HariLibur, HariLiburListResponse, HariLiburPayload, HariLiburQuery, MasterStatus } from '../types'

const BASE = '/master/hari-libur'

export const hariLiburService = {
  async list(query: HariLiburQuery = {}): Promise<HariLiburListResponse> {
    const params: Record<string, string | number> = {}
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== '' && value !== null) params[key] = value
    }
    const { data } = await api.get<HariLiburListResponse>(BASE, { params })
    return data
  },

  async calendar(from?: string, to?: string): Promise<HariLibur[]> {
    const { data } = await api.get<HariLibur[]>(`${BASE}/calendar`, { params: { from, to } })
    return data
  },

  async create(payload: HariLiburPayload): Promise<HariLibur> {
    const { data } = await api.post<HariLibur>(BASE, payload)
    return data
  },

  async update(id: number, payload: Partial<HariLiburPayload>): Promise<HariLibur> {
    const { data } = await api.put<HariLibur>(`${BASE}/${id}`, payload)
    return data
  },

  async setStatus(id: number, status: MasterStatus): Promise<HariLibur> {
    const { data } = await api.patch<HariLibur>(`${BASE}/${id}/status`, { status })
    return data
  },

  /** Soft delete — backend mengubah status menjadi '0'. */
  async remove(id: number): Promise<{ deleted: boolean; soft_delete: boolean; item: HariLibur }> {
    const { data } = await api.delete<{ deleted: boolean; soft_delete: boolean; item: HariLibur }>(`${BASE}/${id}`)
    return data
  },
}
