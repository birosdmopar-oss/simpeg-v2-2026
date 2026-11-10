/**
 * Admin → Integrasi (MAKE-078, ISSUE-058): `GET api/v1/integrasi/log` dan `POST api/v1/integrasi/bkn/tarik` (README
 * `backend/app/Controllers/Api/Integrasi/README.md`). Interceptor `@/lib/axios` sudah membuka amplop, jadi `data` di sini
 * adalah isi `data` backend (pelajaran amplop CR-059: jangan `res.data.data`).
 */
import { api } from '@/lib/axios'

import type { DaftarLogIntegrasi, LogIntegrasiFilter, TarikEkinerjaHasil, TarikEkinerjaPayload } from '../integrasi.types'

/** Parameter kosong tidak dikirim (backend mengabaikannya juga, tetapi URL jadi bersih). */
function params(filter: LogIntegrasiFilter): Record<string, string | number> {
  const hasil: Record<string, string | number> = { page: filter.page, per_page: filter.per_page }
  if (filter.layanan) hasil.layanan = filter.layanan
  if (filter.status) hasil.status = filter.status
  if (filter.tanggal_mulai) hasil.tanggal_mulai = filter.tanggal_mulai
  if (filter.tanggal_selesai) hasil.tanggal_selesai = filter.tanggal_selesai
  return hasil
}

export const logIntegrasiService = {
  async list(filter: LogIntegrasiFilter): Promise<DaftarLogIntegrasi> {
    const { data } = await api.get<DaftarLogIntegrasi>('/integrasi/log', { params: params(filter) })
    return data
  },

  async tarikEkinerja(payload: TarikEkinerjaPayload): Promise<TarikEkinerjaHasil> {
    const { data } = await api.post<TarikEkinerjaHasil>('/integrasi/bkn/tarik', payload)
    return data
  },
}
