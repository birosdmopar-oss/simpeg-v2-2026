/**
 * Klien Slip Gaji pegawai (D-11, MAKE-031) dan verifikasi QR publik (D-14) lewat `api` (`@/lib/axios`, ADR-022):
 *   GET  slip-gaji/saya                       periode tersedia (tanpa nominal)
 *   POST slip-gaji/saya/{periode}/buka         buka sekali (atomik) → rincian + ringkasan + uang makan + identitas
 *   GET  slip-gaji/saya/{periode}/pdf          PDF (hanya setelah dibuka)
 *   GET  publik/verifikasi-slip/{token}        verifikasi publik (tanpa jwt)
 * Interceptor `@/lib/axios` sudah membuka amplop {status, data}: `response.data` = payload (CR-059). Nominal dibiarkan
 * string desimal server; angka lain (MySQLi → string) dinormalkan di sini. Tidak ada data contoh di runtime.
 */
import { api } from '@/lib/axios'

import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'

import type { DaftarSlip, PeriodeSlip, SlipTerbuka, VerifikasiSlip } from '../types'

const teks = (v: unknown): string | null => (v === null || v === undefined || v === '' ? null : String(v))
const bool = (v: unknown): boolean => v === true || v === 1 || v === '1' || v === 'true'
const nominal = (v: unknown): string => (v === null || v === undefined || v === '' ? '0.00' : String(v))

function periode(p: PeriodeSlip): PeriodeSlip {
  return {
    periode: String(p.periode),
    label: String(p.label),
    ada_data: bool(p.ada_data),
    dibuka: bool(p.dibuka),
    viewed_at: teks(p.viewed_at),
    viewed_at_wib: teks(p.viewed_at_wib),
  }
}

function slipTerbuka(s: SlipTerbuka): SlipTerbuka {
  const hari = s.uang_makan?.hari
  return {
    ...s,
    viewed_at: teks(s.viewed_at),
    viewed_at_wib: teks(s.viewed_at_wib),
    rincian: Object.fromEntries(Object.entries(s.rincian ?? {}).map(([k, v]) => [k, nominal(v)])),
    ringkasan: Object.fromEntries(Object.entries(s.ringkasan ?? {}).map(([k, v]) => [k, nominal(v)])) as unknown as SlipTerbuka['ringkasan'],
    uang_makan: {
      nominal: nominal(s.uang_makan?.nominal),
      hari: hari === null || hari === undefined || (hari as unknown) === '' ? null : Number(hari),
      periode_kehadiran: teks(s.uang_makan?.periode_kehadiran),
      tersedia: bool(s.uang_makan?.tersedia),
    },
    pegawai: {
      nip: String(s.pegawai?.nip ?? ''),
      nama: teks(s.pegawai?.nama),
      pangkat: teks(s.pegawai?.pangkat) ?? '-',
      jabatan: teks(s.pegawai?.jabatan) ?? '-',
      unit: teks(s.pegawai?.unit) ?? '-',
      satker: teks(s.pegawai?.satker) ?? '-',
    },
  }
}

export const slipGajiService = {
  async daftar(): Promise<DaftarSlip> {
    const { data } = await api.get<DaftarSlip>('/slip-gaji/saya')
    return { items: (data.items ?? []).map(periode), periode_terbaru: teks(data.periode_terbaru) }
  },

  async buka(kunci: string): Promise<SlipTerbuka> {
    const { data } = await api.post<SlipTerbuka>(`/slip-gaji/saya/${encodeURIComponent(kunci)}/buka`)
    return slipTerbuka(data)
  },

  pdf(kunci: string): Promise<BerkasUnduhan> {
    return ambilBerkas(`/slip-gaji/saya/${encodeURIComponent(kunci)}/pdf`, `slip_gaji_${kunci}`)
  },

  async verifikasi(token: string): Promise<VerifikasiSlip> {
    const { data } = await api.get<VerifikasiSlip>(`/publik/verifikasi-slip/${encodeURIComponent(token)}`)
    return {
      status_keaslian: 'asli',
      periode: String(data.periode),
      label_periode: String(data.label_periode),
      satker_penerbit: teks(data.satker_penerbit),
      waktu_terbit: String(data.waktu_terbit),
      nama: teks(data.nama),
    }
  },
}

/**
 * Format rupiah 0 desimal dari string desimal server ("4500000.00" → "4.500.000"), dibulatkan setengah ke atas seperti
 * `number_format($v, 0)` legacy; dihitung dalam sen (BigInt), tanpa aritmetika float. Nilai tidak sah → "0".
 */
export function formatRupiah(desimal: string): string {
  const m = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(desimal.trim())
  if (!m) return '0'
  const sen = BigInt(m[2] ?? '0') * 100n + BigInt((m[3] ?? '').padEnd(2, '0'))
  const rupiah = ((sen + 50n) / 100n).toString()
  return (m[1] === '-' && rupiah !== '0' ? '-' : '') + rupiah.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
}
