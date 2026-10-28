/**
 * Tipe bersama Modul C (Layanan & Approval). Bentuk mengikuti respons backend (PRD Fase 4 §6.2.4, §6.11.4):
 * server menyediakan `label_status`, `nada_status`, `tahap[]`, dan `aksi {}` — FE tidak menghitung status/tahap/hak.
 */

/** Nada badge dari server (`nada_status`) — sama dengan `UiBadge`. */
export type NadaStatus = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'secondary'

export type StateTahap = 'done' | 'current' | 'todo'

/** Satu langkah stepper dari `tahap[]`. */
export interface TahapLayanan {
  kode: string
  label: string
  state: StateTahap
  nama_petugas: string | null
}

/** `aksi {}` per baris: kunci → boleh/tidak (server; FE hanya menyembunyikan/menonaktifkan item ⋮). */
export type AksiLayanan = Record<string, boolean>

/** Tampilan status yang dihitung server untuk satu pengajuan. */
export interface TampilanStatus {
  label_status: string
  nada_status: NadaStatus
  tahap: TahapLayanan[]
  aksi: AksiLayanan
}

/** Aksi proses cuti (`POST cuti/{id}/process`). */
export type AksiProsesCuti = 'setujui' | 'tolak' | 'tangguhkan'

/** Mode daftar cuti (`GET cuti?mode=`). */
export type ModeCuti = 'saya' | 'persetujuan' | 'admin'

/** Satu langkah keputusan approver pada detail (`riwayat_approval`). */
export interface RiwayatApprovalCuti {
  keterangan_petugas_approval: string | null
  reason_note_al: string | null
  reason_note_pyb: string | null
}

export interface LampiranCuti {
  id_attachment: number
  display_name: string | null
}

export interface SuratCuti {
  id_attachment: number
  jenis: 'belum_tte' | 'tte'
  display_name: string | null
  created_at: string | null
}

export interface KetuaTimCuti {
  nip: string
  nama: string
}

/** Item cuti (daftar & detail) setelah normalisasi di `cuti.service.ts`. */
export interface CutiItem extends TampilanStatus {
  id: number
  nip: string
  nama: string
  id_jenis_cuti: number
  nama_jenis_cuti: string
  tgl_mulai: string
  tgl_akhir: string
  lama_cuti: number
  status: number
  keterangan: string
  no_telp_saat_cuti: string
  alamat_saat_cuti: string
  nip_atasan_langsung: string | null
  nama_atasan_langsung: string | null
  nip_yang_menyetujui: string | null
  nama_yang_menyetujui: string | null
  ada_lampiran: boolean
  ada_surat: boolean
  /** Hanya mode `admin`: approver tahap aktif tak terjangkau → admin boleh memproses atas nama. */
  perlu_atas_nama: boolean
  created_at: string | null
}

export interface CutiDetail extends CutiItem {
  ketua_tim_detail: KetuaTimCuti[]
  lampiran: LampiranCuti[]
  surat: SuratCuti[]
  riwayat_approval: RiwayatApprovalCuti
}

export interface CutiListResponse {
  items: CutiItem[]
  total: number
  page: number
  per_page: number
  mode: ModeCuti
  can_create: boolean
}

export interface CutiPengingatResponse {
  items: CutiItem[]
  total: number
}

export interface CutiListQuery {
  mode: ModeCuti
  page: number
  per_page: number
  status?: string
  search?: string
}

/** Body `POST cuti/{id}/process`. */
export interface CutiProsesPayload {
  aksi: AksiProsesCuti
  reason_note?: string
  atas_nama?: boolean
}

/** Pejabat pada `GET cuti/opsi` (atasan langsung / calon PyB). `bisa_memproses` hanya informasi (PRD §6.4.10). */
export interface PejabatCuti {
  nip: string
  nama: string
  jabatan: string | null
  bisa_memproses: boolean
}

export interface JenisCutiOpsi {
  id_jenis_cuti: number
  nama_jenis_cuti: string
}

/** Ringkasan saldo pada `GET cuti/opsi` (tahun berjalan). */
export interface SaldoCutiOpsi {
  tahun: number
  sisa_cuti: number
  sisa_cuti_n1: number
  sisa_cuti_n2: number
  saldo_tahun_ini: number
  saldo_tahun_depan: number
}

export interface CutiOpsi {
  jenis_cuti: JenisCutiOpsi[]
  atasan_langsung: PejabatCuti | null
  pyb: PejabatCuti[]
  langkah_tunggal: boolean
  eselon_i: boolean
  saldo: SaldoCutiOpsi | null
  tgl_akhir_maks: string | null
  ketua_tim: KetuaTimCuti[]
  can_create: boolean
}

/** Panel kuota `POST cuti/hitung-lama` (hanya jenis 1) — semua angka dari server, FE tidak menghitung. */
export interface KuotaHitung {
  tahun: number
  sisa_cuti: number
  sisa_cuti_n1: number
  sisa_cuti_n2: number
  saldo_tahun_ini: number
  menunggu_tahun_ini: number
  diajukan_tahun_ini: number
  saldo_tahun_depan: number
  menunggu_tahun_depan: number
  diajukan_tahun_depan: number
  cukup: boolean
  pesan: string
}

export interface HariLiburCuti {
  tanggal: string
  keterangan: string
}

export interface HitungLamaCuti {
  lama_cuti: number
  satuan_lama_cuti: string
  tgl_akhir: string | null
  hari_libur: HariLiburCuti[]
  kuota: KuotaHitung | null
}

export interface HitungLamaPayload {
  id_jenis_cuti: number
  tgl_mulai: string
  tgl_akhir?: string
  durasi?: number
  nip?: string
  id_riwayat_cuti?: number
}

/** Isian form pengajuan/ubah cuti (`POST cuti`, `PUT cuti/{id}`); `lama_cuti` dan snapshot tidak dikirim. */
export interface CutiIsian {
  id_jenis_cuti: string
  tgl_mulai: string
  tgl_akhir?: string
  durasi?: string
  no_telp_saat_cuti: string
  alamat_saat_cuti: string
  keterangan: string
  nip_atasan_langsung?: string
  nip_yang_menyetujui?: string
  ketua_tim?: string[]
  /** Hanya role 1. */
  nip?: string
  status?: string
}
