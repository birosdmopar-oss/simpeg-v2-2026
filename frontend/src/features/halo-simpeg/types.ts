/**
 * Tipe respons dan form helpdesk Halo Simpeg — bagian master (README kontrak beku MAKE-070, PRD Fase 8 v1.3 §6.1b, selisih
 * S1/S8). Bagian tiket/ringkasan ditambahkan sesudah README MAKE-071 beku. Bentuk keluaran = hasil parse `schemas.ts`.
 */

/** Status master: 0 Tidak Aktif, 1 Aktif. */
export type StatusMaster = 0 | 1

export interface Halaman<T> {
  items: T[]
  total: number
  page: number
  per_page: number
}

export interface Kategori {
  id: number
  nama: string
  status: StatusMaster
  /** Semua petugas (aktif dan nonaktif) yang ditugaskan. */
  jumlah_petugas: number
  /** false bila dipakai tiket atau petugas (hapus → 422 `kategori_dipakai`). */
  bisa_hapus: boolean
  dibuat_pada: string | null
  diubah_pada: string | null
}

export interface KategoriOpsi {
  id: number
  nama: string
}

export interface KategoriPetugas {
  id: number
  nama: string
  status: StatusMaster
}

export interface Petugas {
  id: number
  id_user: number
  label: string
  email: string
  no_hp: string | null
  status: StatusMaster
  kategori: KategoriPetugas[]
  bisa_hapus: boolean
  alasan_tidak_bisa_hapus: string | null
}

export interface PetugasKandidat {
  id_user: number
  label: string
}

/** S1: kandidat membawa `peringatan` bila satker kepegawaian belum diatur. */
export interface HasilKandidat {
  items: PetugasKandidat[]
  peringatan: string | null
}

export interface PetugasSaya {
  petugas: boolean
  aktif: boolean
  kategori: KategoriPetugas[]
  bisa_ekspor: boolean
}

export interface Kontak {
  id: number
  nama: string
  kontak: string
  email: string | null
  status: StatusMaster
  dibuat_pada: string | null
  diubah_pada: string | null
}

export interface KontakAktif {
  id: number
  nama: string
  kontak: string
  email: string | null
}

/** Respons PATCH status (ketiga master) dan PUT petugas (S8): baris + `peringatan`. */
export type DenganPeringatan<T> = T & { peringatan: string | null }

export interface FilterMaster {
  status?: StatusMaster | null
  q?: string
  page?: number
  per_page?: number
}

/**
 * SEMENTARA (bentuk PRD v1.3 §6.1b, §6.2): ringkasan tiket helpdesk. Dicocokkan ulang dengan README MAKE-071 saat beku.
 * `badge` = distinct(belum dibaca ∪ perlu_tindak) untuk menu; `total` = angka lonceng.
 */
export interface RingkasanHalo {
  belum_dibaca_saya: number
  belum_dibaca_petugas: number
  perlu_tindak: number
  badge: number
  total: number
}

// --- form (nama masukan legacy dibentuk di service) --------------------------------------------------------------------

export interface KategoriForm {
  nama: string
  status: StatusMaster
}

export interface PetugasForm {
  id_user: number | null
  kategori: number[]
  email: string
  no_hp: string
  status: StatusMaster
}

export interface KontakForm {
  nama: string
  kontak: string
  email: string
  status: StatusMaster
}
