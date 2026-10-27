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
