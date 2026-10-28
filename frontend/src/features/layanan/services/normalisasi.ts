/**
 * Normalisasi respons Modul C di lapisan service (PRD Fase 4 §6.11.6, CR-049): MySQLi mengembalikan angka dan flag
 * sebagai string (`"1"`), jadi semua kode/angka dinormalkan di sini SEBELUM dibandingkan. Halaman dan komponen
 * hanya menerima nilai yang sudah bertipe.
 */
import type { AksiLayanan, NadaStatus, StateTahap, TahapLayanan, TampilanStatus } from '../types'

const NADA: readonly NadaStatus[] = ['neutral', 'primary', 'success', 'warning', 'danger', 'info', 'secondary']
const STATE: readonly StateTahap[] = ['done', 'current', 'todo']

/** Angka dari nilai server (`"3"` → 3); bukan angka hingga → `fallback`. */
export function num(value: unknown, fallback = 0): number {
  if (value === null || value === undefined || value === '') return fallback
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}

/** Angka atau `null` (kolom NULL tetap NULL, bukan 0). */
export function numOrNull(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

/** Flag server (`true`, `1`, `"1"`) → boolean; selainnya false. */
export function flag(value: unknown): boolean {
  return value === true || value === 1 || value === '1'
}

/** Teks atau `null` bila kosong/bukan teks. */
export function teksOrNull(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

export function normalisasiNada(value: unknown): NadaStatus {
  return NADA.includes(value as NadaStatus) ? (value as NadaStatus) : 'secondary'
}

export function normalisasiAksi(value: unknown): AksiLayanan {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return {}
  return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([k, v]) => [k, flag(v)]))
}

export function normalisasiTahap(value: unknown): TahapLayanan[] {
  if (!Array.isArray(value)) return []
  return value.map((t) => {
    const row = (t ?? {}) as Record<string, unknown>
    return {
      kode: String(row.kode ?? ''),
      label: String(row.label ?? ''),
      state: STATE.includes(row.state as StateTahap) ? (row.state as StateTahap) : 'todo',
      nama_petugas: teksOrNull(row.nama_petugas),
    }
  })
}

/** Gabungan `label_status` + `nada_status` + `tahap[]` + `aksi {}` dari satu item respons. */
export function normalisasiTampilan(item: Record<string, unknown>): TampilanStatus {
  return {
    label_status: String(item.label_status ?? ''),
    nada_status: normalisasiNada(item.nada_status),
    tahap: normalisasiTahap(item.tahap),
    aksi: normalisasiAksi(item.aksi),
  }
}
