/**
 * Unduhan berkas biner dari API (WS-2 MAKE-013): nama berkas dibaca dari `Content-Disposition` (CORS exposedHeaders),
 * cadangan `<dasar><ekstensi dari tipe blob>` bila header tidak ada/terbaca.
 */
import { api, isApiError, type ApiEnvelope } from '@/lib/axios'

export interface BerkasUnduhan {
  blob: Blob
  nama: string
}

const EKSTENSI: Record<string, string> = {
  'application/pdf': '.pdf',
  'text/csv': '.csv',
  'image/jpeg': '.jpg',
  'image/png': '.png',
  'image/gif': '.gif',
  'application/msword': '.doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',
  'application/vnd.ms-excel': '.xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': '.xlsx',
}

export const ekstensiTipe = (tipe: string): string => EKSTENSI[tipe.split(';')[0]?.trim() ?? ''] ?? ''

/** Nama dari header `Content-Disposition` (filename* UTF-8 atau filename), atau cadangan + ekstensi tipe blob. */
export function namaBerkas(headers: unknown, blob: Blob, dasar: string): string {
  const header = String((headers as Record<string, unknown> | undefined)?.['content-disposition'] ?? '')
  const dariHeader = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header)?.[1]
  if (dariHeader) {
    try {
      return decodeURIComponent(dariHeader)
    } catch {
      return dariHeader
    }
  }
  return `${dasar}${ekstensiTipe(blob.type)}`
}

/**
 * Galat unduhan: interceptor `api` hanya membaca `message`/`errors` dari body JSON, sedangkan `responseType: 'blob'`
 * membuat body galat berupa Blob. Body JSON ber-tipe blob diurai ulang dan diganti di respons asli, lalu `message` dan
 * `errors` diperbarui, sehingga `code` dan `errors` server ikut terbawa seperti jalur JSON biasa.
 */
async function urainGalatBlob(error: unknown): Promise<unknown> {
  if (!isApiError(error)) return error
  const respons = error.original.response
  if (!respons || !(respons.data instanceof Blob) || !respons.data.type.includes('json')) return error
  try {
    const body = JSON.parse(await respons.data.text()) as Partial<ApiEnvelope>
    respons.data = body
    return { ...error, message: body.message || error.message, errors: body.errors ?? null }
  } catch {
    return error
  }
}

/** GET biner + nama berkas. */
export async function ambilBerkas(url: string, dasar: string, params?: Record<string, string | number | string[]>): Promise<BerkasUnduhan> {
  try {
    const res = await api.get<Blob>(url, { params, responseType: 'blob' })
    return { blob: res.data, nama: namaBerkas(res.headers, res.data, dasar) }
  } catch (error) {
    throw await urainGalatBlob(error)
  }
}

/** POST JSON → biner + nama berkas (mis. ekspor hasil pencarian lanjutan). */
export async function kirimBerkas(url: string, body: unknown, dasar: string): Promise<BerkasUnduhan> {
  try {
    const res = await api.post<Blob>(url, body, { responseType: 'blob' })
    return { blob: res.data, nama: namaBerkas(res.headers, res.data, dasar) }
  } catch (error) {
    throw await urainGalatBlob(error)
  }
}

/** Simpan berkas lewat tautan sementara. */
export function simpanBerkas({ blob, nama }: BerkasUnduhan): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = nama
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
