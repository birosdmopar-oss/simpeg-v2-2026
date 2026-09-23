/**
 * G-09 — Web Config (key-value bertipe). Role 1. Key dipakai sebagai path dan tidak bisa diubah.
 */
import { api } from '@/lib/axios'

import type { WebConfig, WebConfigPayload, WebConfigType } from '../types'

const BASE = '/master/web-config'

export const webConfigService = {
  async list(query: { search?: string; tipe_data?: WebConfigType | '' } = {}): Promise<WebConfig[]> {
    const params: Record<string, string> = {}
    for (const [key, value] of Object.entries(query)) {
      if (value) params[key] = value
    }
    const { data } = await api.get<WebConfig[]>(BASE, { params })
    return data
  },

  async get(name: string): Promise<WebConfig> {
    const { data } = await api.get<WebConfig>(`${BASE}/${encodeURIComponent(name)}`)
    return data
  },

  async create(payload: WebConfigPayload): Promise<WebConfig> {
    const { data } = await api.post<WebConfig>(BASE, payload)
    return data
  },

  async update(name: string, payload: Omit<WebConfigPayload, 'config_name'>): Promise<WebConfig> {
    const { data } = await api.put<WebConfig>(`${BASE}/${encodeURIComponent(name)}`, payload)
    return data
  },

  /** Hard delete — web_config tidak punya kolom status. */
  async remove(name: string): Promise<{ deleted: boolean }> {
    const { data } = await api.delete<{ deleted: boolean }>(`${BASE}/${encodeURIComponent(name)}`)
    return data
  },
}
