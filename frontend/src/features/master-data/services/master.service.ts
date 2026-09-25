/**
 * API master data generik (Modul G). Satu service untuk seluruh master — endpoint dibedakan oleh key master.
 * Otorisasi ditegakkan backend (CRUD role 1; options UL_ALL, kecuali master FAQ yang hanya role 1).
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

/** Filter field master (CR-009) sebagai parameter query; nilai kosong = tanpa filter. */
function filterParams(filters: Record<string, string> | undefined): Record<string, string> {
  const params: Record<string, string> = {}
  for (const [key, value] of Object.entries(filters ?? {})) {
    if (value !== '') params[key] = value
  }
  return params
}
const item = (entity: string, id: string): string => `${base(entity)}/${encodeURIComponent(id)}`

export const masterService = {
  async meta(): Promise<MasterMeta[]> {
    const { data } = await api.get<MasterMeta[]>('/master/meta')
    return data
  },

  async list(entity: string, query: MasterListQuery = {}): Promise<MasterListResponse> {
    const { filters, ...rest } = query
    const params: Record<string, string | number> = {}
    for (const [key, value] of Object.entries(rest)) {
      if (value !== undefined && value !== '' && value !== null) params[key] = value
    }
    Object.assign(params, filterParams(filters))
    const { data } = await api.get<MasterListResponse>(base(entity), { params })
    return data
  },

  async get(entity: string, id: string): Promise<MasterRow> {
    const { data } = await api.get<MasterRow>(item(entity, id))
    return data
  },

  /**
   * Dropdown (entri aktif saja, urut `order`) — dipakai juga oleh modul lain. `filters` = field allowlist master
   * (meta `filters`, mis. { cpns: '1' }); backend menolak nilai yang tidak sah (422) dan mengabaikan field lain.
   */
  async options(entity: string, parent?: string | null, filters?: Record<string, string>): Promise<MasterOption[]> {
    const params: Record<string, string> = { ...filterParams(filters) }
    if (parent) params.parent = parent
    const { data } = await api.get<MasterOption[]>(`${base(entity)}/options`, { params })
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
