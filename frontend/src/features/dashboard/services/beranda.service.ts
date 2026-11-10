/**
 * Beranda (F-13, MAKE-087) — `GET api/v1/dashboard/ringkasan`. Interceptor `@/lib/axios` sudah membuka amplop, jadi
 * `data` di sini adalah isi `data` backend (pelajaran amplop CR-059: jangan `res.data.data`).
 */
import { api } from '@/lib/axios'

import type { RingkasanBeranda } from '../types'

export const berandaService = {
  async ringkasan(): Promise<RingkasanBeranda> {
    const { data } = await api.get<RingkasanBeranda>('/dashboard/ringkasan')
    return data
  },
}
