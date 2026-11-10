/**
 * Tipe F-08 Pusat Notifikasi (MAKE-085) — kontrak `backend/app/Controllers/Api/Notifikasi/README.md`.
 */

/** Kontrak lonceng (PRD Fase 7 v2.0 §7 C → D). `null` = sumber belum tersedia, bukan 0. */
export interface RingkasanNotifikasi {
  notifikasi_belum_dibaca: number
  butuh_approval: number
  berita_belum_dibaca: number | null
  tiket_belum_dibaca: number | null
  diperbarui_at: string
}

export interface ItemNotifikasi {
  /** Id gabungan `<sumber>:<id>`. */
  id: string
  sumber: string
  judul: string
  keterangan: string
  status_label: string | null
  reason_note: string | null
  url_fe: string
  dibaca: boolean
  /** UTC ISO; null bila tidak diketahui. */
  waktu: string | null
  dapat_dihapus: boolean
}

export interface DaftarNotifikasi {
  items: ItemNotifikasi[]
  total: number
  belum_dibaca: number
  page: number
  per_page: number
}

export interface ItemApproval {
  id: string
  sumber: string
  judul: string
  keterangan: string
  status_label: string | null
  nip: string | null
  nama: string | null
  url_fe: string
  waktu: string | null
}

export interface GrupApproval {
  kode: string
  label: string
  jumlah: number
  url_fe: string | null
}

export interface ButuhApproval {
  total: number
  grup: GrupApproval[]
  riwayat: { items: ItemApproval[]; total: number; page: number; per_page: number } | null
}

export interface HistoriApproval {
  bulan: string
  pilihan_bulan: string[]
  items: ItemApproval[]
  total: number
  page: number
  per_page: number
}

export type StatusDaftar = 'semua' | 'belum'
