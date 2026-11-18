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

// --- tiket (README kontrak beku MAKE-071, di `main` @64d68377) ----------------------------------------------------------
// Nullability mengikuti README (fixture hanya menjamin bentuk kunci + tipe JSON, bukan kemungkinan null).

export type StatusTiket = 'open' | 'closed'

export interface KategoriTiket {
  id: number
  nama: string
}

/** `nama` = `lampiran-{id_tiket}-{id_pesan}[.ext]`; `tipe` = MIME dari ekstensi; path tidak pernah dikirim. */
export interface LampiranRingkas {
  nama: string
  tipe: string
}

/** T4: `ukuran` selalu `null`; FE tidak bergantung pada ukuran. */
export interface Lampiran extends LampiranRingkas {
  ukuran: null
}

export interface PesanTerakhir {
  id_inbox_msg: number
  waktu: string
  /** 100 karakter pertama isi. */
  potongan: string
  /** Aturan daftar D7 (a): nama bila pengirim = pemilik, selain itu "Admin" bagi semua peninjau. */
  pengirim_label: string
  lampiran: LampiranRingkas | null
}

export interface TiketBaris {
  id: number
  subjek: string
  /** `null` = tiket berkategori NULL (H8-16 (a1)), tampil "—". */
  kategori: KategoriTiket | null
  status: StatusTiket
  dibuat_pada: string
  pemilik_nama: string
  sebagai_petugas: boolean
  belum_dibaca: boolean
  perlu_tindak: boolean
  /** `null` = tiket tanpa pesan (B-20), diurutkan di akhir. */
  pesan_terakhir: PesanTerakhir | null
  bisa_balas: boolean
  bisa_tutup: boolean
  bisa_hapus: boolean
}

export interface SnapshotPelapor {
  nip: string
  jabatan: string | null
  satker: string | null
}

export interface PengingatTiket {
  aktif: boolean
  jatuh_tempo: string | null
}

export interface Pesan {
  id_inbox_msg: number
  waktu: string
  isi: string
  /** Aturan detail D7 (a): nama bila pengirim = pemilik atau peninjau role 1, selain itu "Admin". */
  pengirim_label: string
  milik_saya: boolean
  dari_pemilik: boolean
  /** 0 = pemilik, petugas 1..n menurut kemunculan pertama (B-23). */
  urutan_pengirim: number
  lampiran: Lampiran | null
}

export interface TiketDetail extends TiketBaris {
  allow_reply: boolean
  /** Hanya petugas tiket bukan pemilik (T3); selain itu `null`. */
  snapshot: SnapshotPelapor | null
  /** `null` bila Open atau Closed hasil impor (`closed_by` kosong) → tampil Closed tanpa nama penutup. */
  ditutup_oleh_label: string | null
  /** Hanya petugas tiket bukan pemilik; selain itu `null`. */
  pengingat: PengingatTiket | null
  pesan: Pesan[]
}

/** §6.2: `badge` = distinct(belum dibaca ∪ perlu_tindak) untuk menu, bisa > `total`; `total` = angka lonceng `tiket_belum_dibaca`. */
export interface Ringkasan {
  belum_dibaca_saya: number
  belum_dibaca_petugas: number
  perlu_tindak: number
  badge: number
  total: number
}

/** T9: POST pesan 201. */
/** E-03 POST tiket: detail tiket baru + `message` amplop (CR-142 T-B1 / CR-143; `null` bila BE tidak mengirim). */
export interface HasilBuatTiket {
  tiket: TiketDetail
  pesan: string | null
}

export interface HasilBalas {
  pesan: Pesan
  tiket: TiketBaris
}

export type PeranTiket = 'saya' | 'petugas'
export type FilterBaca = 'semua' | 'belum' | 'sudah'

export interface FilterTiket {
  peran?: PeranTiket
  status?: StatusTiket | null
  kategori?: number | null
  q?: string
  baca?: FilterBaca
  /** Hanya dengan `peran=petugas` (selain itu 422). */
  perlu_tindak?: boolean
  page?: number
  per_page?: number
}

export interface TiketBaruForm {
  id_kategori: number | null
  subjek: string
  pesan: string
  arsip: File | null
}

export interface BalasForm {
  pesan: string
  arsip: File | null
  /** Hanya petugas tiket bukan pemilik (F8-11); selain itu 422. */
  ingatkan_24_jam: boolean
}

/** Hasil ekspor rekap petugas (B4, README MAKE-074): berkas xlsx, atau periode kosong (200 JSON, tanpa unduhan). */
export type HasilEkspor = { jenis: 'berkas'; nama: string; blob: Blob } | { jenis: 'kosong'; pesan: string }

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
