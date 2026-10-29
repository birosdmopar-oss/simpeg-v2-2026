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
 * dipilih ≥ 4,5:1 (diuji di `statusPresensi.spec.ts`). Warna teks lebih gelap daripada `UiBadge` karena pasangan
 * badge itu hanya ±3–4,2:1 untuk kuning/hijau/biru muda.
 */
import type { KodeKejadian, StatusHarian } from './types.laporan'

export type NadaStatus = 'kuning' | 'merah' | 'jingga' | 'biru' | 'abu' | 'hijau' | 'ungu' | 'pink'

/**
 * Kelas Tailwind per nada: `chip` (latar + teks), `baris` (latar baris), `titik` (penanda legenda). `teks`/`latar`
 * adalah nilai heks pasangan `chip` yang sama, dipakai hanya oleh uji kontras.
 */
export const NADA_STATUS: Record<NadaStatus, { chip: string; baris: string; titik: string; teks: string; latar: string }> = {
  kuning: { chip: 'bg-warning-soft text-[#8a5b00]', baris: 'bg-warning-soft/60', titik: 'bg-warning', teks: '#8a5b00', latar: '#FFF2E4' },
  merah: { chip: 'bg-danger-soft text-[#a82d2e]', baris: 'bg-danger-soft/70', titik: 'bg-danger', teks: '#a82d2e', latar: '#FDEBEB' },
  jingga: { chip: 'bg-[#ffe9d6] text-[#8f3f00]', baris: 'bg-[#ffe9d6]/70', titik: 'bg-[#f97316]', teks: '#8f3f00', latar: '#ffe9d6' },
  biru: { chip: 'bg-info-soft text-[#006672]', baris: 'bg-info-soft/70', titik: 'bg-info', teks: '#006672', latar: '#E0F7FA' },
  abu: { chip: 'bg-slate-100 text-slate-600', baris: 'bg-slate-100/80', titik: 'bg-slate-400', teks: '#475569', latar: '#f1f5f9' },
  hijau: { chip: 'bg-success-soft text-[#13753d]', baris: '', titik: 'bg-success', teks: '#13753d', latar: '#E6F8EF' },
  ungu: { chip: 'bg-[#efe9f7] text-[#5b3f8c]', baris: 'bg-[#efe9f7]/80', titik: 'bg-[#6f519d]', teks: '#5b3f8c', latar: '#efe9f7' },
  pink: { chip: 'bg-[#fbe6f0] text-[#9c1f5a]', baris: 'bg-[#fbe6f0]/80', titik: 'bg-[#d5317c]', teks: '#9c1f5a', latar: '#fbe6f0' },
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

/** Urutan legenda: dari paling berat ke paling ringan, lalu status tanpa potongan. */
export const URUTAN_LEGENDA: readonly string[] = ['TK', 'TPM', 'TPP', 'CS', 'TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3', 'cuti', 'tugas_belajar', 'konket', 'libur', 'akhir_pekan', 'di_luar_masa_kerja', 'hadir']

export function infoKode(kode: string): InfoStatus | null {
  return (KODE as Record<string, InfoStatus | undefined>)[kode] ?? null
}

export function infoStatusHarian(status: string, tanggal?: string): InfoStatus | null {
  if (status === 'libur' && tanggal !== undefined && akhirPekan(tanggal)) return AKHIR_PEKAN
  if (status === 'akhir_pekan') return AKHIR_PEKAN
  return (STATUS as Record<string, InfoStatus | null | undefined>)[status] ?? null
}

/** Daftar entri legenda sesuai `URUTAN_LEGENDA` (kode kejadian + status tanpa potongan). */
export function entriLegenda(): InfoStatus[] {
  return URUTAN_LEGENDA.map((k) => infoKode(k) ?? infoStatusHarian(k)).filter((i): i is InfoStatus => i !== null)
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
