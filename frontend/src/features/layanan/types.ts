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
  /** Server (`AturanCuti`): jenis diisi lewat `durasi` (bulan); `tgl_akhir` dihitung server. */
  pakai_durasi: boolean
  /** Server (`AturanCuti`): lampiran bukti wajib pada jenis ini. */
  lampiran_wajib: boolean
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
  /** Dihitung server: saldo − menunggu − diajukan (negatif = kekurangan). */
  sisa_setelah_tahun_ini: number
  sisa_setelah_tahun_depan: number
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

/** Mode daftar pengajuan PMK (`GET pmk/pengajuan?mode=`). */
export type ModePmk = 'saya' | 'pic' | 'admin'

/** Aksi proses PMK (`POST pmk/pengajuan/{id}/process`). */
export type AksiProsesPmk = 'proses' | 'setujui' | 'tolak'

export type KolomBerkasPmk = 'file_kontrak' | 'file_paklaring' | 'file_surat_phk' | 'file_slip_gaji'

/** Pengajuan PMK (`riwayat_pmk_req`) setelah normalisasi; kolom berkas hanya penanda ada/tidak (path internal tidak dikirim). */
export interface PmkItem extends TampilanStatus {
  id: number
  nip: string
  /** Nama pegawai dari server (join `pegawai`); kosong bila pegawai tak ditemukan. Dipakai judul dialog proses. */
  nama: string
  jenis_pmk: string
  instansi: string
  tgl_awal: string
  tgl_akhir: string
  mker_th: number
  mker_bl: number
  status: number
  reason_note: string | null
  berkas: Record<KolomBerkasPmk, boolean>
  /** Server: aksi proses yang boleh pada status sekarang (kosong bila bukan PIC atau status final). */
  aksi_tersedia: AksiProsesPmk[]
  created_at: string | null
  approved_at: string | null
}

export interface PmkListResponse {
  items: PmkItem[]
  total: number
  page: number
  per_page: number
  mode: ModePmk
  can_create: boolean
  can_process: boolean
}

export interface ArsipPmk {
  id_attachment: number
  display_name: string | null
}

/** Riwayat PMK final (`riwayat_pmk`, dari impor/SIASN). */
export interface RiwayatPmkItem {
  id: number
  nip: string
  no_sk: string
  tgl_sk: string
  instansi: string | null
  tgl_awal: string | null
  tgl_akhir: string | null
  mker_th: number
  mker_bl: number
  arsip: ArsipPmk[]
}

export interface RiwayatPmkResponse {
  items: RiwayatPmkItem[]
  total: number
  page: number
  per_page: number
  can_edit: boolean
}

export interface PmkOpsi {
  jenis_pmk: string[]
  pegawai: { nip: string; nama: string } | null
  tmt_cpns: string | null
  tgl_awal_maks: string | null
  /** kolom berkas → label (server). */
  berkas: Record<string, string>
  can_create: boolean
  /** Server (`PetugasLayananService`): pemanggil PIC PMK aktif → tab Perlu Persetujuan ditampilkan. */
  adalah_pic: boolean
}

export interface HitungMker {
  mker_th: number
  mker_bl: number
  peringatan: string | null
}

/** Isian pengajuan PMK; `mker_th`/`mker_bl` dari kalkulator server (`pmk/hitung-mker`). */
export interface PmkIsian {
  jenis_pmk: string
  instansi: string
  tgl_awal: string
  tgl_akhir: string
  mker_th: string
  mker_bl: string
  /** Hanya role 1. */
  nip?: string
}

export interface PmkQuery {
  mode: ModePmk
  page: number
  per_page: number
  status?: string
  search?: string
}

/** Jenis layanan pada Status Layanan (`GET layanan/status` → `jenis`). */
export type JenisLayananKode = 'cuti' | 'tb' | 'ib' | 'pmk'

/** Petugas/approver yang ditampilkan pada kolom PIC (server: atasan + pejabat untuk cuti, semua PIC aktif untuk TB/IB/PMK). */
export interface PetugasStatus {
  peran: string
  nip: string
  nama: string
}

/** Tahap stepper Status Layanan: `tahap[]` modul + `waktu` (server). */
export interface TahapStatus extends TahapLayanan {
  waktu: string | null
}

/** Satu baris `GET layanan/status` setelah normalisasi (gabungan cuti, TB, IB, PMK). */
export interface LayananStatusItem extends Omit<TampilanStatus, 'aksi' | 'tahap'> {
  jenis: JenisLayananKode
  jenis_layanan: string
  id: number
  nip: string
  nama: string
  unit: string | null
  satker: string | null
  tanggal_pengajuan: string | null
  status: number
  ringkasan: string
  tahap: TahapStatus[]
  petugas: PetugasStatus[]
  catatan: string | null
  /** Rute halaman FE modul (null = pemanggil tidak punya halaman detail modul itu). */
  url_detail: string | null
  lihat_detail: boolean
}

export interface LayananStatusResponse {
  items: LayananStatusItem[]
  total: number
  page: number
  per_page: number
}

export interface LayananStatusQuery {
  page: number
  per_page: number
  jenis?: string
  /** `berjalan` | `selesai` (kode angka per jenis tidak ditawarkan di UI). */
  status?: string
  id_unit?: string
  id_satker?: string
  search?: string
}
