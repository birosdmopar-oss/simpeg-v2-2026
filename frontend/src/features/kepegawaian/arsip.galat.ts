/**
 * Pesan galat aksi Arsip Tahap 2 (MAKE-096; PRD §6.1/§9.3): 422/403/404 memakai pesan server (mis. kunci Disetujui
 * "Data yang sudah disetujui tidak dapat diubah.", pesan legacy "Data arsip ini telah terhubung …"), bukan JSON mentah.
 * Galat jaringan / tanpa pesan terbaca → pesan baku `describeApiError`.
 */
import { isApiError } from '@/lib/axios'

import { describeApiError } from './services/apiErrors'

/** Teks bawaan axios saat body bukan JSON server — bukan pesan untuk pengguna. */
const BAWAAN_AXIOS = /^Request failed with status code/i

export interface GalatAksi {
  /** Pesan umum (403/404/lainnya) atau pesan pertama bila 422 tanpa kunci yang dikenal. */
  umum: string
  /** Pesan per kolom 422 (`errors.<kolom>` → pesan pertama). */
  kolom: Record<string, string>
}

export function urainGalatAksi(error: unknown, kolomDikenal: readonly string[] = []): GalatAksi {
  const baku = describeApiError(error).message
  if (!isApiError(error)) return { umum: baku, kolom: {} }

  const kolom: Record<string, string> = {}
  const lain: string[] = []
  for (const [nama, pesan] of Object.entries(error.errors ?? {})) {
    const pertama = Array.isArray(pesan) ? pesan[0] : undefined
    if (typeof pertama !== 'string' || pertama === '') continue
    if (kolomDikenal.includes(nama)) kolom[nama] = pertama
    else lain.push(pertama)
  }

  const pesanServer = error.message !== '' && !BAWAAN_AXIOS.test(error.message) ? error.message : ''
  if (error.status === 422) {
    // Pesan 422 spesifik (kolom atau pesan umum server); "Periksa kembali …" hanya bila server tidak memberi apa pun.
    return { umum: lain[0] ?? (Object.keys(kolom).length > 0 ? '' : pesanServer || baku), kolom }
  }
  if (error.status === 401 || error.status === null) return { umum: baku, kolom }
  return { umum: pesanServer || baku, kolom }
}
