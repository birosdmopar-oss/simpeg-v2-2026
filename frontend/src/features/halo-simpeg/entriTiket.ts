/**
 * Tujuan entri "Tiket Halo Simpeg (n)" di Pusat Notifikasi (✔ H8-15 (b), 8_2 §1a): tab antrean bila sisi petugas punya
 * angka (`belum_dibaca_petugas` atau `perlu_tindak` dari ringkasan helpdesk yang sudah dimuat), selain itu `/halo-simpeg`.
 * Ringkasan helpdesk SEMENTARA kosong sampai README MAKE-071 beku, sehingga tujuan bawaan = `/halo-simpeg`.
 */
import type { RouteLocationRaw } from 'vue-router'

import { TAB_ANTREAN } from './constants'
import type { RingkasanHalo } from './types'

export function tujuanEntriTiket(ringkasan: Readonly<RingkasanHalo> | null): RouteLocationRaw {
  const sisiPetugas = (ringkasan?.belum_dibaca_petugas ?? 0) + (ringkasan?.perlu_tindak ?? 0)
  return sisiPetugas > 0 ? { name: 'halo-simpeg', query: { tab: TAB_ANTREAN } } : { name: 'halo-simpeg' }
}
