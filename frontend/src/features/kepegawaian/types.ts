/**
 * Tipe Modul B — Kepegawaian Core (Fase 3), snake_case end-to-end (ADR-023).
 * Bentuk ini adalah TEBAKAN dari mockup redesign + Tech Spec 03-Kepegawaian.md; backend B-03/B-20 belum ada,
 * jadi harus dicocokkan ulang dengan kontrak API saat endpoint tersedia.
 */

export { PEGAWAI_LIST_ROLES } from './roles'

export type JenisPegawai = 'PNS' | 'PPPK' | 'PTT'
export type StatusPegawai = 'Aktif' | 'Tugas Belajar' | 'Pensiun'

/** Satu baris tabel Daftar Pegawai (GET hr/employee/index). */
export interface PegawaiRow {
  nip: string
  nip_lama: string
  nama: string
  gelar_awal: string
  gelar_akhir: string
  agama: string
  jenis_kelamin: 'Laki-Laki' | 'Perempuan'
  alamat: string
  email: string
  eselon: string
  golongan: string
  tmt_golongan: string
  jabatan: string
  group_jabatan: string
  sub_group_jabatan: string
  satuan_kerja: string
  unit: string
  jenis_pegawai: JenisPegawai
  status_pegawai: StatusPegawai
  /**
   * Seed avatar placeholder — HANYA ada di data contoh. Data asli tidak punya field ini sehingga avatar jatuh ke
   * inisial; jangan pernah mengisinya dari NIP/nama (seed dikirim ke layanan avatar pihak ketiga).
   */
  foto_seed?: string
}

export interface PegawaiListQuery {
  page: number
  per_page: number
  /** Pencarian bebas atas nama & NIP. */
  search?: string
  unit?: string
  status_pegawai?: string
  jenis_pegawai?: string
  group_jabatan?: string
  sub_group_jabatan?: string
  /** Filter per kolom (kunci = PegawaiColumn.key), cocok sebagian & tidak peka huruf besar/kecil. */
  columns?: Record<string, string>
}

export interface PegawaiListResult {
  items: PegawaiRow[]
  total: number
}

export interface SelectOptionItem {
  value: string
  label: string
}

/** Opsi untuk filter panel Daftar Pegawai. */
export interface PegawaiFacets {
  periode_skp: SelectOptionItem[]
  unit: SelectOptionItem[]
  status_pegawai: SelectOptionItem[]
  jenis_pegawai: SelectOptionItem[]
  group_jabatan: SelectOptionItem[]
  sub_group_jabatan: Record<string, SelectOptionItem[]>
}

/** Field "Data Umum" pada halaman Detail Pegawai (§4.1.6, Gambar 22). */
export interface DataUmum {
  nama: string
  gelar_awal: string
  gelar_akhir: string
  tanggal_lahir: string
  provinsi_lahir: string
  kota_lahir: string
  jenis_kelamin: string
  status_perkawinan: string
  nik: string
  npwp: string
  no_bpjs_kesehatan: string
  no_bpjs_ketenagakerjaan: string
  no_taspen: string
  agama: string
  email: string
  no_hp: string
  jenis_kerabat: string
  no_telp_kerabat: string
  jenis_pegawai: string
  status_pegawai: string
  jenis_status: string
  tmt_status: string
}

export interface ArsipItem {
  id: number
  jenis: string
}

export interface PegawaiDetail {
  nip: string
  /** Sumber gambar avatar placeholder — HANYA data contoh, jangan diisi NIP/nama asli. */
  foto_seed: string
  jenis_pegawai: JenisPegawai
  status_pegawai: StatusPegawai
  tanggal_lahir_label: string
  data_umum: DataUmum
  arsip: ArsipItem[]
}

/** Simpul bagan struktur organisasi (GET hr/so/full) — tree unit → satker → jabatan → pegawai (B-19). */
export interface OrgNode {
  id: string
  jabatan: string
  nama: string
  nama_lengkap: string
  foto_seed: string
  children: OrgNode[]
}

/** Daftar tab riwayat pada Detail Pegawai; hanya `data-umum` yang sudah punya isi di fase ini. */
export interface RiwayatMenu {
  key: string
  label: string
  /** Kode task Tech Spec yang akan mengisi tab ini. */
  task: string
  ready: boolean
}

/** Batas file lampiran (B-18): 5 MB. */
export const LAMPIRAN_MAX_MB = 5
