/**
 * Klien B-19 Struktur Organisasi (pemilik WS-2, MAKE-012/013):
 *   GET struktur-organisasi?id_unit_satker=&bulan=       bagan ikut templat legacy so_012025 ('0' kementerian,
 *                                                        '<id_unit>', 'sat_<id_satker>'; bulan YYYY-MM sejak 2022-01)
 *   GET struktur-organisasi/pdf?id_unit_satker=&bulan=   ekspor PDF (TCPDF)
 */
import { api } from '@/lib/axios'

import type { StrukturOrganisasi } from '../types'

const params = (idUnitSatker: string, bulan?: string): Record<string, string> => ({ id_unit_satker: idUnitSatker, ...(bulan ? { bulan } : {}) })

export const strukturService = {
  async bagan(idUnitSatker = '0', bulan?: string): Promise<StrukturOrganisasi> {
    const { data } = await api.get<StrukturOrganisasi>('/struktur-organisasi', { params: params(idUnitSatker, bulan) })
    return data
  },

  async pdf(idUnitSatker = '0', bulan?: string): Promise<Blob> {
    const { data } = await api.get<Blob>('/struktur-organisasi/pdf', { params: params(idUnitSatker, bulan), responseType: 'blob' })
    return data
  },
}
