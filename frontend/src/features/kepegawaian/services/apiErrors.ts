/**
 * Pemetaan galat API Modul B ke keadaan halaman (README kontrak Kepegawaian, "Kode status & urutan pemeriksaan"):
 * 401 (sesi; ditangani interceptor axios) → 404 jenis → 403 izin/lingkup → 404 data; 422 validasi; 501 selama service
 * backend masih stub. Bagi role ber-lingkup, NIP yang tidak ada juga dijawab 403 — pesan 403 tidak boleh menyiratkan
 * bahwa NIP itu ada.
 */
import { isApiError } from '@/lib/axios'

export type ApiFailureKind = 'unauthorized' | 'forbidden' | 'not-found' | 'unavailable' | 'validation' | 'network' | 'other'

export interface ApiFailure {
  kind: ApiFailureKind
  status: number | null
  message: string
}

const MESSAGES: Record<ApiFailureKind, string> = {
  unauthorized: 'Sesi Anda berakhir. Silakan masuk kembali.',
  forbidden: 'Anda tidak berhak mengakses data ini.',
  'not-found': 'Data tidak ditemukan.',
  unavailable: 'Fitur ini belum tersedia di server.',
  validation: 'Periksa kembali isian yang ditandai.',
  network: 'Tidak dapat terhubung ke server. Periksa koneksi Anda.',
  other: 'Terjadi kesalahan. Coba lagi.',
}

function kindOf(status: number | null): ApiFailureKind {
  if (status === null) return 'network'
  if (status === 401) return 'unauthorized'
  if (status === 403) return 'forbidden'
  if (status === 404) return 'not-found'
  if (status === 422) return 'validation'
  if (status === 501) return 'unavailable'
  return 'other'
}

/** Ringkas galat apa pun menjadi jenis + pesan siap tampil (pesan baku per jenis, bukan teks server mentah). */
export function describeApiError(error: unknown): ApiFailure {
  const status = isApiError(error) ? error.status : null
  const kind = isApiError(error) ? kindOf(status) : 'other'
  return { kind, status, message: MESSAGES[kind] }
}

export interface RiwayatValidationErrors {
  /** errors.<kolom DDL> → pesan pertama. */
  fields: Record<string, string>
  /** errors["berkas.<id_riwayat>"] (endpoint riwayat) → pesan pertama, kunci = kode jenis_rwy. */
  berkas: Record<number, string>
  /** errors.berkas (endpoint lampiran) atau kunci lain yang tidak dikenali form. */
  general: string[]
}

/** Pecah `errors` 422 menjadi galat per kolom, per kode lampiran, dan umum. */
export function splitValidationErrors(error: unknown, knownFields: readonly string[] = []): RiwayatValidationErrors {
  const out: RiwayatValidationErrors = { fields: {}, berkas: {}, general: [] }
  if (!isApiError(error) || error.status !== 422 || !error.errors) return out
  for (const [key, messages] of Object.entries(error.errors)) {
    const first = messages?.[0]
    if (!first) continue
    const berkas = /^berkas\.(\d+)$/.exec(key)
    if (berkas) out.berkas[Number(berkas[1])] = first
    else if (knownFields.includes(key)) out.fields[key] = first
    else out.general.push(first)
  }
  return out
}
