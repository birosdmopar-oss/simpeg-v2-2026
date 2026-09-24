/**
 * API master data generik (Modul G). Satu service untuk seluruh master — endpoint dibedakan oleh key master.
 * Otorisasi ditegakkan backend (CRUD role 1; options UL_ALL).
 */
import { api } from '@/lib/axios'

import type {
  MasterDeleteResponse,
  MasterListQuery,
  MasterListResponse,
  MasterMeta,
  MasterOption,
  MasterRow,
  MasterSettableStatus,
} from '../types'

const base = (entity: string): string => `/master/${encodeURIComponent(entity)}`
const item = (entity: string, id: string): string => `${base(entity)}/${encodeURIComponent(id)}`

export const masterService = {
  async meta(): Promise<MasterMeta[]> {
    const { data } = await api.get<MasterMeta[]>('/master/meta')
    return data
  },

  async list(entity: string, query: MasterListQuery = {}): Promise<MasterListResponse> {
    const params: Record<string, string | number> = {}
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== '' && value !== null) params[key] = value
    }
    const { data } = await api.get<MasterListResponse>(base(entity), { params })
    return data
  },

  async get(entity: string, id: string): Promise<MasterRow> {
    const { data } = await api.get<MasterRow>(item(entity, id))
    return data
  },

  /** Dropdown (entri aktif saja, urut `order`) — dipakai juga oleh modul lain. */
  async options(entity: string, parent?: string | null): Promise<MasterOption[]> {
    const { data } = await api.get<MasterOption[]>(`${base(entity)}/options`, { params: parent ? { parent } : {} })
    return data
  },

  async create(entity: string, payload: Record<string, string | number>): Promise<MasterRow> {
    const { data } = await api.post<MasterRow>(base(entity), payload)
    return data
  },

  async update(entity: string, id: string, payload: Record<string, string | number>): Promise<MasterRow> {
    const { data } = await api.put<MasterRow>(item(entity, id), payload)
    return data
  },

  /** 1 Aktif / 2 Tidak Aktif; dipakai juga untuk memulihkan entri berstatus 10 (Dihapus). */
  async setStatus(entity: string, id: string, status: MasterSettableStatus): Promise<MasterRow> {
    const { data } = await api.patch<MasterRow>(`${item(entity, id)}/status`, { status })
    return data
  },

  async reorder(entity: string, id: string, order: number): Promise<MasterRow> {
    const { data } = await api.patch<MasterRow>(`${item(entity, id)}/order`, { order })
    return data
  },

  /** Soft delete — backend mengubah status menjadi 10 (Dihapus), data tidak dihapus permanen. */
  async remove(entity: string, id: string): Promise<MasterDeleteResponse> {
    const { data } = await api.delete<MasterDeleteResponse>(item(entity, id))
    return data
  },
}
