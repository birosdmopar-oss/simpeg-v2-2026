/**
 * Layanan Status Layanan (Fase 4). SEMENTARA memakai data contoh; kontrak fungsi = yang akan diisi `api`
 * (`@/lib/axios`) saat endpoint hr/rwy/layanan tersedia. Filter/paginasi di sini hanya meniru server.
 */
import type { LayananListQuery, LayananListResult } from '../types'
import { LAYANAN_ROWS } from './layanan.mock'

const includes = (h: string, n: string): boolean => h.toLowerCase().includes(n.trim().toLowerCase())

export function filterLayanan(q: LayananListQuery): LayananListResult {
  const rows = LAYANAN_ROWS.filter((r) => {
    if (q.unit && r.satuan_kerja !== q.unit) return false
    if (q.status && r.status !== q.status) return false
    if (q.search && !includes(`${r.nama} ${r.nip} ${r.jenis}`, q.search)) return false
    return true
  })
  const start = (Math.max(1, q.page) - 1) * q.per_page
  return { items: rows.slice(start, start + q.per_page), total: rows.length }
}

export const layananService = {
  async list(query: LayananListQuery): Promise<LayananListResult> {
    return filterLayanan(query)
  },
}
