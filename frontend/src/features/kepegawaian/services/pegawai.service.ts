/**
 * Layanan data Kepegawaian (Modul B).
 *
 * SEMENTARA MEMAKAI DATA CONTOH: endpoint B-03 (biodata), B-19 (struktur), B-20 (daftar & detail) belum ada di
 * backend. Tanda tangan fungsi di sini adalah kontrak yang akan diisi pemanggilan `api` dari `@/lib/axios`
 * (ADR-022) — komponen dan halaman tidak perlu berubah saat backend tersedia.
 * Filtering/paginasi dikerjakan di sini hanya untuk meniru perilaku server (pencarian & filter ada di backend).
 */
import { COLUMN_BY_KEY, fullName } from '../columns'
import type { OrgNode, PegawaiDetail, PegawaiFacets, PegawaiListQuery, PegawaiListResult, SelectOptionItem } from '../types'
import { buildDetail, FACETS, flattenOrg, ORG_TREE, PEGAWAI_ROWS } from './pegawai.mock'

const includes = (haystack: string, needle: string): boolean => haystack.toLowerCase().includes(needle.trim().toLowerCase())

export function filterPegawai(query: PegawaiListQuery): PegawaiListResult {
  const rows = PEGAWAI_ROWS.filter((row) => {
    if (query.search && !includes(`${fullName(row)} ${row.nip}`, query.search)) return false
    if (query.unit && row.satuan_kerja !== query.unit) return false
    if (query.status_pegawai && row.status_pegawai !== query.status_pegawai) return false
    if (query.jenis_pegawai && row.jenis_pegawai !== query.jenis_pegawai) return false
    if (query.group_jabatan && row.group_jabatan !== query.group_jabatan) return false
    if (query.sub_group_jabatan && row.sub_group_jabatan !== query.sub_group_jabatan) return false
    for (const [key, needle] of Object.entries(query.columns ?? {})) {
      const column = COLUMN_BY_KEY[key]
      if (needle && column && !includes(column.value(row), needle)) return false
    }
    return true
  })

  const start = (Math.max(1, query.page) - 1) * query.per_page
  return { items: rows.slice(start, start + query.per_page), total: rows.length }
}

/** Subpohon dengan akar `rootId`; tidak ketemu → seluruh pohon. */
export function findOrgSubtree(rootId?: string): OrgNode {
  if (!rootId) return ORG_TREE
  return flattenOrg().find((n) => n.id === rootId) ?? ORG_TREE
}

export const pegawaiService = {
  async facets(): Promise<PegawaiFacets> {
    return FACETS
  },

  async list(query: PegawaiListQuery): Promise<PegawaiListResult> {
    return filterPegawai(query)
  },

  /** `null` = NIP tidak ditemukan (halaman menampilkan keadaan kosong, bukan error). */
  async detail(nip: string): Promise<PegawaiDetail | null> {
    return buildDetail(nip)
  },

  async orgTree(rootId?: string): Promise<OrgNode> {
    return findOrgSubtree(rootId)
  },

  /** Pilihan akar bagan (dropdown "Kementerian Pariwisata…"). */
  async orgRoots(): Promise<SelectOptionItem[]> {
    return flattenOrg()
      .filter((n) => n.children.length > 0)
      .map((n) => ({ value: n.id, label: n.jabatan }))
  },
}
