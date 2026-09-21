/**
 * Pemanggilan API autentikasi (Modul A) lewat instance axios terpusat (ADR-022).
 * Cookie httpOnly dikelola browser; access token di body tidak disimpan di localStorage.
 */
import { api } from '@/lib/axios'

import type { LoginPayload, LoginResponse, MeResponse } from '../types'

export const authService = {
  async login(payload: LoginPayload): Promise<LoginResponse> {
    const { data } = await api.post<LoginResponse>('/auth/login', payload)
    return data
  },

  async me(): Promise<MeResponse> {
    const { data } = await api.get<MeResponse>('/auth/me')
    return data
  },

  async logout(): Promise<void> {
    await api.post('/auth/logout')
  },

  async changePassword(payload: {
    old_password: string
    new_password: string
    new_password_confirmation: string
  }): Promise<void> {
    await api.post('/auth/change-password', payload)
  },
}
