/**
 * Token push web (PRD Fase 6 §7.4 F6-13, MAKE-066) — `POST|DELETE api/v1/integrasi/fcm-token` (README
 * `backend/app/Controllers/Api/Integrasi/README.md`). Interceptor `@/lib/axios` sudah membuka amplop, jadi `data` di sini
 * adalah isi `data` backend (pelajaran amplop CR-059: jangan `res.data.data`).
 */
import { api } from '@/lib/axios'

export type StatusDaftarToken = 'baru' | 'diperpanjang' | 'sudah_terdaftar'

export const fcmTokenService = {
  async daftar(fcmToken: string): Promise<StatusDaftarToken> {
    const { data } = await api.post<{ status: StatusDaftarToken }>('/integrasi/fcm-token', { fcm_token: fcmToken })
    return data.status
  },

  async lepas(fcmToken: string): Promise<number> {
    const { data } = await api.delete<{ dihapus: number }>('/integrasi/fcm-token', { data: { fcm_token: fcmToken } })
    return data.dihapus
  },
}
