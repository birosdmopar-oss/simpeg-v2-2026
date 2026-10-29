/**
 * Peta kode status presensi → label, teks singkat, dan warna (PRD 5C §7.2, AC-FE5C.3). Satu sumber untuk chip, legenda,
 * dan latar baris tabel harian.
 *
 * - [K] LATAR BARIS tabel harian laporan per pegawai ikut legacy `laporan_tukin_skp.php:235-262, 471-495`, per `status`
 *   baris NON-presensi: akhir pekan abu, libur biru, konket jingga, cuti ungu, tugas belajar merah muda (legacy jatuh ke
 *   `bg-others`), di luar masa kerja abu [I]. Baris presensi (hadir/TK/TPM/TPP) tidak berwarna.
 * - [V2] CHIP kode kejadian (DoD 087 "color-coding TL/PSW/TK") memakai palet Q5C-03: TL/PSW kuning, TK/TPM/TPP merah,
 *   CS jingga. Rekap Tukin/kehadiran (legacy tanpa warna) hanya memakai chip/sel kode, tanpa warna baris.
 *
 * Aturan: warna TIDAK berdiri sendiri — teks kode selalu tampil (`kode`), lalu legenda menjelaskan. Pasangan teks/latar
 * token `status-*` dipilih ≥ 4,5:1 (diuji di `statusPresensi.spec.ts`). Teksnya lebih gelap daripada `UiBadge` karena
 * pasangan badge itu hanya ±3–4,2:1 untuk kuning/hijau/biru muda.
 */
import type { KodeKejadian, StatusHarian } from './types.laporan'

export type NadaStatus = 'kuning' | 'merah' | 'jingga' | 'biru' | 'abu' | 'hijau' | 'ungu' | 'pink'

/**
 * Kelas Tailwind per nada, dari token tema `status-*` (`tailwind.config.js`; nilai heks hanya di sana): `chip` (latar +
 * teks), `baris` (latar baris tabel), `titik` (penanda legenda). Ditulis lengkap per entri agar terdeteksi pemindai Tailwind.
 */
export const NADA_STATUS: Record<NadaStatus, { chip: string; baris: string; titik: string }> = {
  kuning: { chip: 'bg-status-kuning-soft text-status-kuning', baris: 'bg-status-kuning-soft/70', titik: 'bg-status-kuning-titik' },
  merah: { chip: 'bg-status-merah-soft text-status-merah', baris: 'bg-status-merah-soft/70', titik: 'bg-status-merah-titik' },
  jingga: { chip: 'bg-status-jingga-soft text-status-jingga', baris: 'bg-status-jingga-soft/70', titik: 'bg-status-jingga-titik' },
  biru: { chip: 'bg-status-biru-soft text-status-biru', baris: 'bg-status-biru-soft/70', titik: 'bg-status-biru-titik' },
  abu: { chip: 'bg-status-abu-soft text-status-abu', baris: 'bg-status-abu-soft/80', titik: 'bg-status-abu-titik' },
  hijau: { chip: 'bg-status-hijau-soft text-status-hijau', baris: '', titik: 'bg-status-hijau-titik' },
  ungu: { chip: 'bg-status-ungu-soft text-status-ungu', baris: 'bg-status-ungu-soft/80', titik: 'bg-status-ungu-titik' },
  pink: { chip: 'bg-status-pink-soft text-status-pink', baris: 'bg-status-pink-soft/80', titik: 'bg-status-pink-titik' },
}

export interface InfoStatus {
  /** Kunci unik (kode kejadian atau nama status). */
  kunci: string
  /** Teks singkat yang selalu tampil di chip. */
  kode: string
  label: string
  nada: NadaStatus
}

const KODE: Record<KodeKejadian, InfoStatus> = {
  TL1: { kunci: 'TL1', kode: 'TL1', label: 'Terlambat 1–30 menit', nada: 'kuning' },
  TL2: { kunci: 'TL2', kode: 'TL2', label: 'Terlambat 31–60 menit', nada: 'kuning' },
  TL3: { kunci: 'TL3', kode: 'TL3', label: 'Terlambat lebih dari 60 menit', nada: 'kuning' },
  PSW1: { kunci: 'PSW1', kode: 'PSW1', label: 'Pulang sebelum waktunya 1–30 menit', nada: 'kuning' },
  PSW2: { kunci: 'PSW2', kode: 'PSW2', label: 'Pulang sebelum waktunya 31–60 menit', nada: 'kuning' },
  PSW3: { kunci: 'PSW3', kode: 'PSW3', label: 'Pulang sebelum waktunya lebih dari 60 menit', nada: 'kuning' },
  TPM: { kunci: 'TPM', kode: 'TPM', label: 'Tidak presensi masuk', nada: 'merah' },
  TPP: { kunci: 'TPP', kode: 'TPP', label: 'Tidak presensi pulang', nada: 'merah' },
  TK: { kunci: 'TK', kode: 'TK', label: 'Tanpa keterangan', nada: 'merah' },
  CS: { kunci: 'CS', kode: 'CS', label: 'Cuti sakit hari ke-15 dan seterusnya (dipotong)', nada: 'jingga' },
}

const AKHIR_PEKAN: InfoStatus = { kunci: 'akhir_pekan', kode: 'Akhir pekan', label: 'Akhir pekan (Sabtu/Minggu)', nada: 'abu' }

const STATUS: Record<StatusHarian, InfoStatus | null> = {
  libur: { kunci: 'libur', kode: 'Libur', label: 'Hari libur', nada: 'biru' },
  akhir_pekan: AKHIR_PEKAN, // status eksplisit dari API (CR-076); `libur` + tanggal Sabtu/Minggu tetap dipetakan di bawah
  tugas_belajar: { kunci: 'tugas_belajar', kode: 'TB', label: 'Tugas belajar', nada: 'pink' },
  cuti: { kunci: 'cuti', kode: 'Cuti', label: 'Cuti', nada: 'ungu' },
  konket: { kunci: 'konket', kode: 'Konket', label: 'Kondisi khusus (konket) tanpa potongan', nada: 'jingga' },
  hadir: { kunci: 'hadir', kode: 'Hadir', label: 'Hadir', nada: 'hijau' },
  TK: KODE.TK,
  TPM: KODE.TPM,
  TPP: KODE.TPP,
  di_luar_masa_kerja: { kunci: 'di_luar_masa_kerja', kode: 'Di luar masa kerja', label: 'Di luar masa kerja pegawai', nada: 'abu' },
}

/** Sabtu/Minggu menurut tanggal `YYYY-MM-DD` (hitungan kalender murni, tanpa zona waktu). */
export function akhirPekan(tanggal: string): boolean {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tanggal)
  if (m === null) return false
  const hari = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))).getUTCDay()
  return hari === 0 || hari === 6
}

/** Legenda "Kode": kode kejadian potongan, dari paling berat ke paling ringan. */
export const URUTAN_LEGENDA_KODE: readonly string[] = ['TK', 'TPM', 'TPP', 'CS', 'TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3']

/** Legenda "Warna baris": status harian (hadir tanpa warna baris, jadi hanya dicantumkan sebagai keterangan chip). */
export const URUTAN_LEGENDA_BARIS: readonly string[] = ['libur', 'akhir_pekan', 'konket', 'cuti', 'tugas_belajar', 'di_luar_masa_kerja', 'hadir']

export function infoKode(kode: string): InfoStatus | null {
  return (KODE as Record<string, InfoStatus | undefined>)[kode] ?? null
}

export function infoStatusHarian(status: string, tanggal?: string): InfoStatus | null {
  if (status === 'libur' && tanggal !== undefined && akhirPekan(tanggal)) return AKHIR_PEKAN
  if (status === 'akhir_pekan') return AKHIR_PEKAN
  return (STATUS as Record<string, InfoStatus | null | undefined>)[status] ?? null
}

const dariKunci = (k: string): InfoStatus | null => infoKode(k) ?? infoStatusHarian(k)

/** Entri legenda "Kode" (chip kejadian). */
export function legendaKode(): InfoStatus[] {
  return URUTAN_LEGENDA_KODE.map(dariKunci).filter((i): i is InfoStatus => i !== null)
}

/** Entri legenda "Warna baris" (status harian). */
export function legendaBaris(): InfoStatus[] {
  return URUTAN_LEGENDA_BARIS.map(dariKunci).filter((i): i is InfoStatus => i !== null)
}

/** Status yang sudah diwakili kode kejadian (potongan presensi) sehingga tidak perlu chip status tambahan. */
const STATUS_BERKODE: readonly string[] = ['hadir', 'TK', 'TPM', 'TPP']

/**
 * Chip satu hari: bila ada kode kejadian, chip per kode (urut dari server, kode tak dikenal dilewati); cuti/TB/konket
 * dengan kode (mis. cuti sakit CS) tampil bersama chip statusnya. Tanpa kode: chip dari `status` (`hadir` → "Hadir").
 * `tanggal` (opsional) membedakan akhir pekan dari libur nasional untuk status `libur`.
 */
export function chipHarian(status: string, kode?: readonly string[] | null, tanggal?: string): InfoStatus[] {
  const dariKode = (kode ?? []).map(infoKode).filter((i): i is InfoStatus => i !== null)
  const dasar = infoStatusHarian(status, tanggal)
  if (dariKode.length > 0) return dasar !== null && !STATUS_BERKODE.includes(status) ? [dasar, ...dariKode] : dariKode
  return dasar === null ? [] : [dasar]
}

/**
 * Nada latar baris tabel harian [K] legacy: hanya baris NON-presensi berwarna (libur biru, akhir pekan abu, konket
 * jingga, cuti ungu, TB merah muda, di luar masa kerja abu); hadir/TK/TPM/TPP/tak dikenal = `null` (tanpa warna baris).
 */
export function nadaBaris(status: string, tanggal?: string): NadaStatus | null {
  if (STATUS_BERKODE.includes(status)) return null
  return infoStatusHarian(status, tanggal)?.nada ?? null
}
