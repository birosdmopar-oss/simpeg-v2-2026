/**
 * Klien endpoint generator dokumen F-09 (MAKE-037) lewat `api` (`@/lib/axios`, ADR-022):
 *   GET  dokumen/{jenis}                 daftar JSON berhalaman (`lingkup`, `id`, `search`, `page`, `per_page`; pensiun + `tahun`, `sampai`)
 *   GET  dokumen/{jenis}/{pdf|xlsx}      unduh (blob; nama dari Content-Disposition)
 *   POST dokumen/baperjakat/{pdf|xlsx}   unduh Baperjakat dari NIP terpilih + judul/subjudul/teks
 * Lingkup memakai pemilih yang sama dengan laporan statistik (`statistik/lingkup`). Tidak ada data contoh di runtime.
 */
import { api, isApiError } from '@/lib/axios'

import { ambilBerkas, kirimBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { lingkupKeQuery } from '@/features/laporan/laporan.service'
import type { LingkupPilihan } from '@/features/laporan/types'

import { DOKUMEN_JENIS, type BaperjakatBody, type DokumenData, type DokumenFormat, type DokumenJenis, type DokumenQuery } from './types'

export const isDokumenJenis = (v: unknown): v is DokumenJenis => (DOKUMEN_JENIS as readonly unknown[]).includes(v)

/** Pemilih lingkup + filter → params query (nilai kosong tidak dikirim). */
export function paramsDokumen(pilihan: LingkupPilihan, query: DokumenQuery = {}): Record<string, string> {
  const params: Record<string, string> = {}
  const l = lingkupKeQuery(pilihan)
  if (l.lingkup) params.lingkup = l.lingkup
  if (l.id) params.id = l.id
  for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== null && String(v).trim() !== '') params[k] = String(v).trim()
  }
  return params
}

/**
 * Pesan galat unduhan yang bisa ditampilkan. Unduhan memakai `responseType: 'blob'`, jadi body JSON galat (mis. 422
 * fallback PDF `errors.lingkup` "PDF hanya tersedia per unit/satker.") datang sebagai Blob dan tidak terbaca
 * interceptor: dibaca di sini. Pesan per kolom (`errors.*`) didahulukan dari `message` umum.
 */
export async function pesanGalatUnduhan(error: unknown): Promise<string> {
  const data = isApiError(error) ? (error.original as { response?: { data?: unknown } } | undefined)?.response?.data : undefined

  if (data instanceof Blob) {
    try {
      const body = JSON.parse(await data.text()) as { message?: unknown; errors?: Record<string, unknown> }
      const pertama = Object.values(body.errors ?? {})
        .flatMap((v) => (Array.isArray(v) ? v : [v]))
        .find((v): v is string => typeof v === 'string' && v !== '')
      if (pertama) return pertama
      if (typeof body.message === 'string' && body.message !== '') return body.message
    } catch {
      // Bukan JSON (mis. galat proxy): pakai pesan baku di bawah.
    }
  }

  if (isApiError(error) && error.errors) {
    const pertama = Object.values(error.errors).flat()[0]
    if (pertama) return pertama
  }

  if (isApiError(error) && error.status === 429) return 'Terlalu banyak unduhan dokumen. Coba lagi dalam satu menit.'

  return describeApiError(error).message
}

export const dokumenService = {
  async daftar(jenis: DokumenJenis, pilihan: LingkupPilihan, query: DokumenQuery = {}): Promise<DokumenData> {
    // Interceptor sudah membuka amplop: `response.data` = payload (CR-059).
    const { data } = await api.get<DokumenData>(`/dokumen/${jenis}`, { params: paramsDokumen(pilihan, query) })
    return { ...data, total: Number(data.total), page: Number(data.page), per_page: Number(data.per_page) }
  },

  unduh(jenis: Exclude<DokumenJenis, 'baperjakat'>, format: DokumenFormat, pilihan: LingkupPilihan, query: DokumenQuery = {}): Promise<BerkasUnduhan> {
    const { search, tahun, sampai } = query
    return ambilBerkas(`/dokumen/${jenis}/${format}`, `dokumen_${jenis}`, paramsDokumen(pilihan, { search, tahun, sampai }))
  },

  baperjakat(format: DokumenFormat, body: BaperjakatBody): Promise<BerkasUnduhan> {
    return kirimBerkas(`/dokumen/baperjakat/${format}`, body, 'dokumen_baperjakat')
  },
}
