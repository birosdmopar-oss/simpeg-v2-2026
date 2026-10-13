/**
 * Klien B-19 Struktur Organisasi (pemilik WS-2, MAKE-012):
 *   GET struktur-organisasi?id_unit_satker=   bagan ikut templat legacy so_012025 ('0' kementerian, '<id_unit>',
 *                                             'sat_<id_satker>'); semua pengguna login
 */
import { api } from '@/lib/axios'

import type { StrukturOrganisasi } from '../types'

export const strukturService = {
  async bagan(idUnitSatker = '0'): Promise<StrukturOrganisasi> {
    const { data } = await api.get<StrukturOrganisasi>('/struktur-organisasi', { params: { id_unit_satker: idUnitSatker } })
    return data
  },
}
