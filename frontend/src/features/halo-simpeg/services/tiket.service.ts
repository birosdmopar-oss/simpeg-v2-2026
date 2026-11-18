/**
 * Klien API tiket Halo Simpeg (`api/v1/halo-simpeg/tiket`, README kontrak beku MAKE-071). Interceptor `@/lib/axios` sudah
 * membuka amplop (CR-059: jangan `res.data.data`); setiap respons di-parse skema Zod, di luar kontrak → `KontrakHaloError`.
 * - Masukan memakai nama legacy: `id_question_category`, `subject`, `msg`, `arsip`, `ingatkan_24_jam` (multipart).
 * - Lampiran lama dan baru hanya lewat endpoint E-04 (AC-E08.9): blob + nama dari `Content-Disposition`, tanpa path `/berkas/`.
 * - Kode galat bisnis (`tiket_ditutup`, `throttle`, …) dibaca lewat `kodeGalat()`; teks galat dari amplop.
 */
import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import type { AxiosResponseTransformer } from 'axios'

import { api } from '@/lib/axios'

import { eksporKosongSchema, halaman, hapusSchema, hasilBalasSchema, KontrakHaloError, ringkasanSchema, tiketBarisSchema, tiketDetailSchema } from '../schemas'
import type { BalasForm, FilterTiket, Halaman, HasilBalas, HasilBuatTiket, HasilEkspor, Ringkasan, TiketBaris, TiketBaruForm, TiketDetail } from '../types'
import { BASIS_HALO, parse, queryDaftar } from './master.service'

const BASIS_TIKET = `${BASIS_HALO}/tiket`

/**
 * Filter daftar → query. `perlu_tindak` dikirim `1` hanya bila `true` (hanya sah dengan `peran=petugas`, selain itu BE 422);
 * `baca` bawaan BE `semua` tetap dikirim bila diisi (bawaan per tab ditentukan FE, `constants.ts`).
 */
export function queryTiket(filter: FilterTiket): Record<string, string | number> {
  const { perlu_tindak, ...lain } = filter
  return queryDaftar({ ...lain, perlu_tindak: perlu_tindak ? 1 : undefined })
}

function formTiketBaru(form: TiketBaruForm): FormData {
  const fd = new FormData()
  if (form.id_kategori !== null) fd.append('id_question_category', String(form.id_kategori))
  fd.append('subject', form.subjek.trim())
  fd.append('msg', form.pesan.trim())
  if (form.arsip) fd.append('arsip', form.arsip)
  return fd
}

function formBalas(form: BalasForm): FormData {
  const fd = new FormData()
  fd.append('msg', form.pesan.trim())
  if (form.arsip) fd.append('arsip', form.arsip)
  // T8: kosong = 0; nilai 1 hanya untuk petugas tiket bukan pemilik (F8-11), jadi hanya dikirim bila dicentang.
  if (form.ingatkan_24_jam) fd.append('ingatkan_24_jam', '1')
  return fd
}

export const tiketHaloService = {
  async list(filter: FilterTiket = {}): Promise<Halaman<TiketBaris>> {
    const { data } = await api.get<unknown>(BASIS_TIKET, { params: queryTiket(filter) })
    return parse(halaman(tiketBarisSchema), data, 'tiket')
  },
  /** Angka badge menu (`badge`) dan lonceng (`total`), §6.2. */
  async ringkasan(): Promise<Ringkasan> {
    const { data } = await api.get<unknown>(`${BASIS_TIKET}/ringkasan`)
    return parse(ringkasanSchema, data, 'tiket/ringkasan')
  },
  /** Tanpa efek samping (GET tidak menandai baca, AC-E04.8); penanda baca lewat `baca()`. */
  async detail(id: number): Promise<TiketDetail> {
    const { data } = await api.get<unknown>(`${BASIS_TIKET}/${id}`)
    return parse(tiketDetailSchema, data, `tiket/${id}`)
  },
  /**
   * E-03 (role 2/6/7): 201 `TiketDetail` + `message` amplop (CR-142 T-B1 / CR-143). Interceptor membuang amplop, jadi `message`
   * ditangkap di `transformResponse` tambahan milik request ini saja (`@/lib/axios` tidak diubah); bukan string → `null`.
   */
  async buat(form: TiketBaruForm): Promise<HasilBuatTiket> {
    let pesan: string | null = null
    const tangkapPesan: AxiosResponseTransformer = (body: unknown) => {
      const m = body !== null && typeof body === 'object' ? (body as { message?: unknown }).message : undefined
      pesan = typeof m === 'string' && m.trim() !== '' ? m : null
      return body
    }
    const bawaan = api.defaults.transformResponse
    const transformResponse = [...(Array.isArray(bawaan) ? bawaan : bawaan ? [bawaan] : []), tangkapPesan]
    const { data } = await api.post<unknown>(BASIS_TIKET, formTiketBaru(form), { transformResponse })
    return { tiket: parse(tiketDetailSchema, data, 'tiket'), pesan }
  },
  /** E-04: 201 `{pesan, tiket}` (T9). */
  async balas(id: number, form: BalasForm): Promise<HasilBalas> {
    const { data } = await api.post<unknown>(`${BASIS_TIKET}/${id}/pesan`, formBalas(form))
    return parse(hasilBalasSchema, data, `tiket/${id}/pesan`)
  },
  /** Dipanggil eksplisit saat thread dibuka (BP8-7); respons `Ringkasan` langsung memperbarui badge. */
  async baca(id: number): Promise<Ringkasan> {
    const { data } = await api.post<unknown>(`${BASIS_TIKET}/${id}/baca`)
    return parse(ringkasanSchema, data, `tiket/${id}/baca`)
  },
  /** E-05: 200 `TiketBaris`. */
  async tutup(id: number): Promise<TiketBaris> {
    const { data } = await api.post<unknown>(`${BASIS_TIKET}/${id}/tutup`)
    return parse(tiketBarisSchema, data, `tiket/${id}/tutup`)
  },
  /** E-05: 200 `{id}`. */
  async hapus(id: number): Promise<number> {
    const { data } = await api.delete<unknown>(`${BASIS_TIKET}/${id}`)
    return parse(hapusSchema, data, `tiket/${id}`).id
  },
  /**
   * Ekspor rekap petugas (E-09, B4; README MAKE-074). `ds`/`de` = `YYYY-MM-DD` (tanggal kalender lokal). `ambilBerkas` TIDAK
   * diubah (8_2 §3a): hasil 200 dibedakan lewat `Content-Type` blob — `application/json` = periode kosong (amplop utuh →
   * `{jenis: 'kosong', pesan}`, tanpa unduhan, AC-E09.5); selain itu berkas xlsx (nama dari `Content-Disposition`). Galat
   * 403 `bukan_petugas` / 422 per kolom / 422 `ekspor_terlalu_besar` dilempar sebagai ApiError (blob JSON diurai `ambilBerkas`).
   */
  async ekspor(ds: string, de: string): Promise<HasilEkspor> {
    const berkas = await ambilBerkas(`${BASIS_TIKET}/ekspor`, 'Rekap_Halo_Simpeg', { ds, de, format: 'xlsx' })
    if (!berkas.blob.type.includes('json')) return { jenis: 'berkas', nama: berkas.nama, blob: berkas.blob }
    let body: unknown
    try {
      body = JSON.parse(await berkas.blob.text())
    } catch (e) {
      throw new KontrakHaloError('tiket/ekspor', e)
    }
    const hasil = eksporKosongSchema.safeParse(body)
    if (!hasil.success) throw new KontrakHaloError('tiket/ekspor', hasil.error)
    return { jenis: 'kosong', pesan: hasil.data.message }
  },
  /** Lampiran lama/baru lewat E-04 (blob); nama dari `Content-Disposition`, cadangan `lampiran-{id}-{idPesan}`. */
  lampiran(id: number, idPesan: number): Promise<BerkasUnduhan> {
    return ambilBerkas(`${BASIS_TIKET}/${id}/pesan/${idPesan}/lampiran`, `lampiran-${id}-${idPesan}`)
  },
}
