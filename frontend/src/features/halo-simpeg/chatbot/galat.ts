/**
 * Galat 422 per kolom chatbot. Kontrak MAKE-094 mengikuti amplop v2: `errors: {kolom: [pesan, …]}` (fixture `galat-422-*.json`,
 * keputusan reviewer 13-11-2026) → diambil pesan pertama. Tetap toleran terhadap string tunggal agar pesan tidak pernah
 * terpotong menjadi satu huruf (`m[0]` pada string).
 */
import { isApiError } from '@/lib/axios'

export function pesanKolom(nilai: unknown): string {
  if (Array.isArray(nilai)) return typeof nilai[0] === 'string' ? nilai[0] : ''
  return typeof nilai === 'string' ? nilai : ''
}

/** `errors` → `{kolom: pesan}`; kunci dipetakan lewat `peta` bila nama kolom BE berbeda dengan nama field form. */
export function galatPerKolom(e: unknown, peta: (kolom: string) => string = (k) => k): Record<string, string> {
  if (!isApiError(e) || !e.errors) return {}
  const hasil: Record<string, string> = {}
  for (const [kolom, nilai] of Object.entries(e.errors as Record<string, unknown>)) {
    const pesan = pesanKolom(nilai)
    if (pesan !== '') hasil[peta(kolom)] = pesan
  }
  return hasil
}
