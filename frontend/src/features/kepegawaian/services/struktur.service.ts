/**
 * Klien B-19 Struktur Organisasi (pemilik WS-2, MAKE-012):
 *   GET struktur-organisasi?id_unit=&id_satker=   pohon periode aktif + pejabat (semua pengguna login)
 */
import { api } from '@/lib/axios'

import type { StrukturOrganisasi } from '../types'

export const strukturService = {
  async pohon(filter: { id_unit?: string; id_satker?: string } = {}): Promise<StrukturOrganisasi> {
    const params: Record<string, string> = {}
    if (filter.id_satker) params.id_satker = filter.id_satker
    else if (filter.id_unit) params.id_unit = filter.id_unit
    const { data } = await api.get<StrukturOrganisasi>('/struktur-organisasi', { params })
    return data
  },
}
