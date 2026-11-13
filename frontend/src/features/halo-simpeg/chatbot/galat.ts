/**
 * Galat 422 per kolom chatbot. Kontrak MAKE-094 (fixture `galat-422-*.json`) mengirim `errors` sebagai string per kolom
 * (`{"id_kategori": "…"}`), sedangkan tipe `ApiError.errors` di `@/lib/axios` ditulis `string[]` (bentuk endpoint lain).
 * Helper ini menerima kedua bentuk agar pesan tidak terpotong menjadi satu huruf (`m[0]` pada string).
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
