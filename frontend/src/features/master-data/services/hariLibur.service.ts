/**
 * API G-08 Hari Libur (DBV-003/CR-010). Otorisasi ditegakkan backend: baca role 1/4/5/8, tulis role 1.
 * Pilihan jenis libur = dropdown master generik `master/jenis-libur/options` (UL_ALL, hanya entri aktif).
 */
import { api } from '@/lib/axios'

import type {
  HariLiburDeleteResponse,
  HariLiburListQuery,
  HariLiburListResponse,
  HariLiburPayload,
  HariLiburRow,
} from '../hariLibur.types'
import type { MasterOption, MasterSettableStatus } from '../types'

const BASE = '/hari-libur'
const item = (id: string): string => `${BASE}/${encodeURIComponent(id)}`

export const hariLiburService = {
  async list(query: HariLiburListQuery = {}): Promise<HariLiburListResponse> {
    const params: Record<string, string | number> = {}
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') params[key] = value
    }
    const { data } = await api.get<HariLiburListResponse>(BASE, { params })
    return data
  },

  async get(id: string): Promise<HariLiburRow> {
    const { data } = await api.get<HariLiburRow>(item(id))
    return data
  },

  async create(payload: HariLiburPayload): Promise<HariLiburRow> {
    const { data } = await api.post<HariLiburRow>(BASE, payload)
    return data
  },

  async update(id: string, payload: Partial<HariLiburPayload>): Promise<HariLiburRow> {
    const { data } = await api.put<HariLiburRow>(item(id), payload)
    return data
  },

  /** 1 Aktif / 2 Tidak Aktif; juga memulihkan entri berstatus 10 (Dihapus). */
  async setStatus(id: string, status: MasterSettableStatus): Promise<HariLiburRow> {
    const { data } = await api.patch<HariLiburRow>(`${item(id)}/status`, { status })
    return data
  },

  /** Soft delete — status menjadi 10 (Dihapus); tanggalnya tetap terpakai sampai dipulihkan atau diubah. */
  async remove(id: string): Promise<HariLiburDeleteResponse> {
    const { data } = await api.delete<HariLiburDeleteResponse>(item(id))
    return data
  },

  async jenisOptions(): Promise<MasterOption[]> {
    const { data } = await api.get<MasterOption[]>('/master/jenis-libur/options')
    return data
  },
}
