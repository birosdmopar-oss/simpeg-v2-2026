/**
 * API G-09 Web Config (DBV-006/CR-030). Semua endpoint role 1; otorisasi ditegakkan backend.
 * `config_name` legacy boleh mengandung `/` (mis. `TL1/PSW1`): tiap segmen di-encode terpisah, garis miringnya tetap
 * (route backend `web-config/(:any)`).
 */
import { api } from '@/lib/axios'

import type { WebConfigDeleteResponse, WebConfigItem, WebConfigListResponse, WebConfigPayload } from '../webConfig.types'

const BASE = '/web-config'

export function webConfigPath(name: string): string {
  return `${BASE}/${name.split('/').map(encodeURIComponent).join('/')}`
}

export const webConfigService = {
  async list(): Promise<WebConfigItem[]> {
    const { data } = await api.get<WebConfigListResponse>(BASE)
    return data.items
  },

  async get(name: string): Promise<WebConfigItem> {
    const { data } = await api.get<WebConfigItem>(webConfigPath(name))
    return data
  },

  /** Simpan nilai (baris dibuat bila belum ada). Nilai kosong ditolak: untuk kembali ke bawaan pakai remove(). */
  async save(name: string, payload: WebConfigPayload): Promise<WebConfigItem> {
    const { data } = await api.put<WebConfigItem>(webConfigPath(name), payload)
    return data
  },

  /** Hapus baris tersimpan → nilai kembali ke bawaan katalog. */
  async remove(name: string): Promise<WebConfigDeleteResponse> {
    const { data } = await api.delete<WebConfigDeleteResponse>(webConfigPath(name))
    return data
  },
}
