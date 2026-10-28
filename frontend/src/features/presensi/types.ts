/**
 * Tipe & konstanta tampilan Modul D paket 5A. Bentuk data mengikuti `backend/app/Controllers/Api/Presensi/README.md`
 * (D-11, D-14). Nominal = string desimal kanonik server (`"4500000.00"`); FE hanya memformat, tanpa aritmetika.
 */

/** Satu periode di pemilih bulan Slip Gaji (`GET slip-gaji/saya`; tanpa nominal). */
export interface PeriodeSlip {
  periode: string
  label: string
  ada_data: boolean
  dibuka: boolean
  viewed_at: string | null
  viewed_at_wib: string | null
}

export interface DaftarSlip {
  items: PeriodeSlip[]
  periode_terbaru: string | null
}

export interface IdentitasSlip {
  nip: string
  nama: string | null
  pangkat: string
  jabatan: string
  unit: string
  satker: string
}

export interface RingkasanSlip {
  jumlah_penghasilan_gpp: string
  jumlah_potongan_gpp: string
  bersih_gpp: string
  bersih_tk: string
  bersih_2: string
  uang_makan: string
  total_penghasilan: string
}

export interface UangMakanSlip {
  nominal: string
  hari: number | null
  /** `YYYY-MM` periode kehadiran (N−1), null bila belum tersedia. */
  periode_kehadiran: string | null
  tersedia: boolean
}

/** Respons `POST slip-gaji/saya/{periode}/buka` (satu-satunya respons pegawai yang memuat nominal). */
export interface SlipTerbuka {
  periode: string
  label: string
  viewed_at: string | null
  viewed_at_wib: string | null
  rincian: Record<string, string>
  ringkasan: RingkasanSlip
  uang_makan: UangMakanSlip
  pegawai: IdentitasSlip
}

/** Respons verifikasi QR publik (`GET publik/verifikasi-slip/{token}`; snapshot saat terbit, tanpa nominal/NIP). */
export interface VerifikasiSlip {
  status_keaslian: 'asli'
  periode: string
  label_periode: string
  satker_penerbit: string | null
  waktu_terbit: string
  nama: string | null
}

/** Label 28 kolom slip [K `Lsl_gaji.php:15-48`, sama dengan `KolomGaji` backend]. */
export const LABEL_KOLOM: Record<string, string> = {
  gjpokok: 'Gaji Pokok',
  tjistri: 'Tunjangan Istri/Suami',
  tjanak: 'Tunjangan Anak',
  tjupns: 'Tunjangan Umum PNS',
  tjstruk: 'Tunjangan Struktural',
  tjfungs: 'Tunjangan Fungsional',
  tjdaerah: 'Tunjangan Daerah',
  tjpencil: 'Tunjangan Pencil/Pensiun',
  tjlain: 'Tunjangan Lain-lain',
  tjkompen: 'Tunjangan Kompensasi',
  pembul: 'Pembulatan',
  tjberas: 'Tunjangan Beras',
  tjpph: 'Tunjangan PPh',
  potpfkbul: 'Potongan PFK Bulanan',
  potpfk2: 'Potongan PFK 2%',
  potpfk10: 'Potongan PFK 10%',
  potpph: 'Potongan PPh',
  potswrum: 'Potongan Sewa Rumah',
  potkelbtj: 'Potongan Kelebihan Tunjangan',
  potlain: 'Potongan Lain-lain',
  pottabrum: 'Potongan Tabungan Perumahan',
  bersih_gpp: 'Gaji Bersih (GPP)',
  kotor: 'Penghasilan Kotor',
  potongan: 'Potongan',
  bersih_tk: 'Bersih (TK)',
  pajak: 'Pajak',
  tunj_pajak: 'Tunjangan Pajak',
  bersih_2: 'Tunjangan Kinerja Bersih',
}

export interface KelompokSlip {
  judul: string
  kolom: string[]
}

/** Pengelompokan tampilan GPP [K `_slip_content.php` `$gppGroups`]; nilai tetap per kolom DB. */
export const KELOMPOK_GPP: { penghasilan: KelompokSlip[]; potongan: KelompokSlip[] } = {
  penghasilan: [
    { judul: 'Gaji Pokok', kolom: ['gjpokok'] },
    { judul: 'Tunjangan Keluarga', kolom: ['tjistri', 'tjanak'] },
    { judul: 'Tunjangan Jabatan', kolom: ['tjstruk', 'tjfungs'] },
    { judul: 'Tunjangan Lainnya', kolom: ['tjupns', 'tjdaerah', 'tjberas', 'tjpencil', 'tjkompen', 'tjlain', 'tjpph', 'pembul'] },
  ],
  potongan: [
    { judul: 'Iuran & Pajak', kolom: ['potpfkbul', 'potpfk2', 'potpfk10', 'potpph'] },
    { judul: 'Potongan Lainnya', kolom: ['potswrum', 'potkelbtj', 'pottabrum', 'potlain'] },
  ],
}

/** Nama hasil akhir per bagian [K `_slip_content.php`]. */
export const LABEL_GPP_BERSIH = 'Gaji Pokok & Pendapatan (GPP) Bersih'
export const LABEL_TK_BERSIH = 'Tunjangan Kinerja (TK) Bersih'

/** Kolom per bagian modal rincian & koreksi admin [K `admin_list.php:238`, `:251` `Lsl_gaji::GPP_FIELDS` / `TK_FIELDS`]. */
export const KOLOM_GPP = [
  'gjpokok', 'tjistri', 'tjanak', 'tjupns', 'tjstruk', 'tjfungs', 'tjdaerah', 'tjpencil', 'tjlain', 'tjkompen', 'pembul', 'tjberas', 'tjpph',
  'potpfkbul', 'potpfk2', 'potpfk10', 'potpph', 'potswrum', 'potkelbtj', 'potlain', 'pottabrum', 'bersih_gpp',
] as const
export const KOLOM_TK = ['kotor', 'potongan', 'bersih_tk', 'pajak', 'tunj_pajak', 'bersih_2'] as const

/** Aksi baris dari server (`aksi {}`); FE hanya menampilkan, tidak menghitung. */
export interface AksiSlipAdmin {
  lihat: boolean
  cetak: boolean
  koreksi: boolean
  buka_ulang: boolean
}

/** Satu baris monitoring `GET slip-gaji` (role 1, 3). */
export interface SlipMonitoring {
  id: number
  nip: string
  nama: string | null
  unit_satker: string
  periode: string
  dibuka: boolean
  viewed_at: string | null
  viewed_at_wib: string | null
  viewed_ip: string | null
  aksi: AksiSlipAdmin
}

export interface DaftarMonitoring {
  items: SlipMonitoring[]
  total: number
  page: number
  per_page: number
  ringkasan: { jumlah: number; dibuka: number; belum_dibuka: number }
  /** `YYYY-MM`, terbaru dulu. */
  periode_tersedia: string[]
}

export interface MonitoringQuery {
  periode: string
  unit_satker?: string
  status_view?: 'semua' | 'dibuka' | 'belum'
  search?: string
  page?: number
  per_page?: number
}

/** Respons `GET|PUT slip-gaji/{id}` (rincian 28 kolom, tidak mengubah status). */
export interface SlipAdmin {
  id: number
  nip: string
  nama: string | null
  periode: string
  label: string
  dibuka: boolean
  viewed_at: string | null
  viewed_at_wib: string | null
  viewed_ip: string | null
  rincian: Record<string, string>
  ringkasan: RingkasanSlip
  uang_makan: UangMakanSlip
  aksi: AksiSlipAdmin
  pegawai: IdentitasSlip
}

export type StatusBarisImpor = 'valid' | 'duplikat' | 'galat'
export type RencanaImpor = 'sisip' | 'perbarui' | 'lewati' | 'gagal'

export interface GalatImpor {
  kode: string
  pesan: string
  sheet: string
  baris: number
}

/** Satu baris pratinjau impor (`POST slip-gaji/impor/pratinjau`); status & rencana dihitung server (ADR-025). */
export interface BarisPratinjau {
  nomor: number
  sheet: string
  nip: string | null
  nama: string | null
  periode: string | null
  status: StatusBarisImpor
  rencana_bila_timpa: RencanaImpor
  rencana_bila_tidak_timpa: RencanaImpor
  galat: GalatImpor[]
}

export interface PratinjauImpor {
  token_pratinjau: string
  kedaluwarsa_at: string | null
  timpa_bawaan: boolean
  filter: string | null
  ringkasan: { valid: number; duplikat: number; galat: number }
  baris: BarisPratinjau[]
}

export interface HasilImpor {
  disisip: number
  diperbarui: number
  dilewati: number
  gagal: number
  baris: { nip: string | null; periode: string | null; hasil: RencanaImpor; galat: GalatImpor[] }[]
}

/** Lokasi presensi yang berlaku untuk pegawai hari ini (`GET presensi/saya/hari-ini`). */
export interface LokasiPresensi {
  id_lokasi_presensi: number
  nama_lokasi: string
  latitude: number
  longitude: number
  radius: number
}

/** Status presensi hari ini; tanggal, jam, jendela, dan `boleh_*` dihitung server (zonasi satker). */
export interface PresensiHariIni {
  tanggal_lokal: string
  jam_lokal: string
  zonasi_menit: number
  jendela: { masuk: [string, string]; pulang: [string, string] }
  boleh_masuk: boolean
  boleh_pulang: boolean
  presensi: { jam_masuk: string | null; jam_pulang: string | null } | null
  wajib_geofence: boolean
  lokasi: LokasiPresensi[]
}

/** Putusan geofence server (`POST presensi/cek-lokasi`); sama dengan putusan saat rekam. */
export interface CekLokasi {
  wajib_geofence: boolean
  dalam_radius: boolean
  lokasi_terdekat: { id: number; nama: string; radius: number; jarak_m: number } | null
}

export interface HasilRekamPresensi {
  tgl: string
  jam_masuk: string | null
  jam_pulang: string | null
  jarak_m: number | null
}

/** `sumber` `dh_online`: 1 online, 2 manual, null baris impor legacy. */
export type SumberPresensi = 1 | 2 | null

export interface BarisPresensi {
  tgl: string
  jam_masuk: string | null
  jam_pulang: string | null
  sumber: SumberPresensi
}

export interface RiwayatPresensi {
  bulan: string
  items: BarisPresensi[]
  total: number
  page: number
  per_page: number
}

export interface PegawaiPresensi {
  nip: string
  nama: string
  unit: string | null
  satker: string | null
}

export interface DaftarPegawaiPresensi {
  items: PegawaiPresensi[]
  total: number
  page: number
  per_page: number
  can_kelola_manual: boolean
}

export interface BarisPresensiAdmin extends BarisPresensi {
  nip: string
  lokasi_masuk: string | null
  lokasi_pulang: string | null
  aksi: { ubah: boolean; hapus: boolean }
}

export interface DetailPresensiPegawai {
  pegawai: { nip: string; nama: string }
  items: BarisPresensiAdmin[]
  total: number
  page: number
  per_page: number
  can_create: boolean
}

/** Isian presensi manual (D-03); jam `HH:MM`/`HH:MM:SS`, kosong = null. */
export interface IsianPresensiManual {
  nip: string
  tgl: string
  jam_masuk: string
  jam_pulang: string
}
