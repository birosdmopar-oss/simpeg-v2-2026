/**
 * Layanan Portal Berita. SEMENTARA memakai data contoh: kontrak fungsi = yang akan diisi `api` (`@/lib/axios`,
 * ADR-022) saat endpoint hr/news tersedia. Pencarian/filter/paginasi di sini hanya meniru server.
 */
import { BERITA_ITEMS } from './berita.mock'
import { BERITA_KATEGORI, type BeritaItem, type BeritaKategoriCount, type BeritaListQuery, type BeritaListResult } from './types'

const includes = (h: string, n: string): boolean => h.toLowerCase().includes(n.trim().toLowerCase())

export function filterBerita(q: BeritaListQuery): BeritaListResult {
  const rows = BERITA_ITEMS.filter((b) => {
    if (q.kategori && b.kategori !== q.kategori) return false
    if (q.search && !includes(`${b.judul} ${b.ringkasan} ${b.penulis}`, q.search)) return false
    return true
  })
  const start = (Math.max(1, q.page) - 1) * q.per_page
  return { items: rows.slice(start, start + q.per_page), total: rows.length }
}

export const beritaService = {
  async list(query: BeritaListQuery): Promise<BeritaListResult> {
    return filterBerita(query)
  },

  /** Berita terbaru (sidebar & dashboard). */
  async latest(count: number): Promise<BeritaItem[]> {
    return BERITA_ITEMS.slice(0, count)
  },

  async kategori(): Promise<BeritaKategoriCount[]> {
    return BERITA_KATEGORI.map((kategori) => ({ kategori, jumlah: BERITA_ITEMS.filter((b) => b.kategori === kategori).length }))
  },
}
