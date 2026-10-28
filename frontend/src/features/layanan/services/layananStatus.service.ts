/**
 * Klien Status Layanan (PRD Fase 4 §5.5, §6.10, README controller Layanan "C-10"). `api` sudah membuka amplop
 * `{status, data}` (interceptor): service membaca `res.data` langsung (CR-059) dan menormalkan string MySQLi (CR-049).
 *   GET layanan/status?jenis=&status=&id_unit=&id_satker=&search=&page=&per_page=   gabungan cuti, TB, IB, PMK (read-only)
 */
import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import { api } from '@/lib/axios'

import type { FormatEksporStatus, JenisLayananKode, LayananStatusItem, LayananStatusQuery, LayananStatusResponse, PetugasStatus, TahapStatus } from '../types'

import { flag, normalisasiNada, normalisasiTahap, num, teksOrNull } from './normalisasi'

const JENIS: readonly JenisLayananKode[] = ['cuti', 'tb', 'ib', 'pmk']

function daftarRecord(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? (value as Record<string, unknown>[]) : []
}

/** Hanya tautan internal (diawali `/`, bukan `//`) yang dipakai untuk navigasi; selain itu dianggap tidak ada. */
function tautanInternal(value: unknown): string | null {
  const s = teksOrNull(value)
  return s !== null && s.startsWith('/') && !s.startsWith('//') ? s : null
}

export function normalisasiStatusItem(raw: Record<string, unknown>): LayananStatusItem {
  const waktuTahap = daftarRecord(raw.tahap)
  const aksi = (raw.aksi ?? {}) as Record<string, unknown>
  const url = tautanInternal(raw.url_detail)
  return {
    jenis: JENIS.includes(raw.jenis as JenisLayananKode) ? (raw.jenis as JenisLayananKode) : 'cuti',
    jenis_layanan: String(raw.jenis_layanan ?? ''),
    id: num(raw.id),
    nip: String(raw.nip ?? ''),
    nama: String(raw.nama ?? ''),
    unit: teksOrNull(raw.unit),
    satker: teksOrNull(raw.satker),
    tanggal_pengajuan: teksOrNull(raw.tanggal_pengajuan),
    status: num(raw.status),
    ringkasan: String(raw.ringkasan ?? ''),
    label_status: String(raw.label_status ?? ''),
    nada_status: normalisasiNada(raw.nada_status),
    tahap: normalisasiTahap(raw.tahap).map((t, i): TahapStatus => ({ ...t, waktu: teksOrNull(waktuTahap[i]?.waktu) })),
    petugas: daftarRecord(raw.petugas).map((p): PetugasStatus => ({ peran: String(p.peran ?? ''), nip: String(p.nip ?? ''), nama: String(p.nama ?? '') })),
    catatan: teksOrNull(raw.catatan),
    url_detail: url,
    lihat_detail: url !== null && flag(aksi.lihat_detail),
  }
}

export const layananStatusService = {
  async list(query: LayananStatusQuery): Promise<LayananStatusResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    for (const k of ['jenis', 'status', 'id_unit', 'id_satker', 'search'] as const) {
      const v = query[k]
      if (v) params[k] = v
    }
    const { data } = await api.get<Record<string, unknown>>('/layanan/status', { params })
    return {
      items: daftarRecord(data.items).map(normalisasiStatusItem),
      total: num(data.total),
      page: num(data.page, 1),
      per_page: num(data.per_page),
      can_export: flag(data.can_export),
    }
  },

  /** `GET layanan/status/ekspor` [V2]: data sesuai filter aktif (tanpa paginasi), CSV/XLSX dari server. */
  ekspor(format: FormatEksporStatus, filter: Pick<LayananStatusQuery, 'jenis' | 'status' | 'id_unit' | 'id_satker' | 'search'>): Promise<BerkasUnduhan> {
    const params: Record<string, string> = { format }
    for (const k of ['jenis', 'status', 'id_unit', 'id_satker', 'search'] as const) {
      const v = filter[k]
      if (v) params[k] = v
    }
    return ambilBerkas('/layanan/status/ekspor', 'status_layanan', params)
  },

}
