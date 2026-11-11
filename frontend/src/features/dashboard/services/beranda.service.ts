/**
 * Beranda (F-13, MAKE-087) — `GET api/v1/dashboard/ringkasan`. Interceptor `@/lib/axios` sudah membuka amplop, jadi
 * `data` di sini adalah isi `data` backend (pelajaran amplop CR-059: jangan `res.data.data`).
 */
import { api } from '@/lib/axios'

import type { DirekturPoliteknik, HariUlangTahun, RingkasanBeranda, RingkasanSkl, UlangTahunItem } from '../types'

export const berandaService = {
  async ringkasan(): Promise<RingkasanBeranda> {
    const { data } = await api.get<RingkasanBeranda>('/dashboard/ringkasan')
    return data
  },

  async direktur(): Promise<DirekturPoliteknik[]> {
    const { data } = await api.get<{ items: DirekturPoliteknik[] }>('/dashboard/direktur')
    return data.items
  },

  async ulangTahun(hari: HariUlangTahun): Promise<UlangTahunItem[]> {
    const { data } = await api.get<{ hari: HariUlangTahun; items: UlangTahunItem[] }>('/dashboard/ulang-tahun', { params: { hari } })
    return data.items
  },

  /** Foto hanya untuk pegawai di daftar ulang tahun hari ini/besok (selain itu 404). */
  async fotoUlangTahun(kunci: string): Promise<Blob> {
    const { data } = await api.get<Blob>(`/dashboard/ulang-tahun/${encodeURIComponent(kunci)}/foto`, { responseType: 'blob' })
    return data
  },

  /** Ringkasan SKL (Q7-26, endpoint F-11 Jalur B; role 1). Tanpa parameter = bawaan BE 12 bulan terakhir. */
  async ringkasanSkl(): Promise<RingkasanSkl> {
    const { data } = await api.get<RingkasanSkl>('/survei-layanan/ringkasan')
    return data
  },
}
