/**
 * CRUD akun pengguna (A-08 / A-12). Scoping satker ditegakkan backend; FE hanya menampilkan.
 */
import { api } from '@/lib/axios'

import type { User, UserCreatePayload, UserListQuery, UserListResponse, UserUpdatePayload } from '../types'

export const usersService = {
  async list(query: UserListQuery = {}): Promise<UserListResponse> {
    const params: Record<string, string | number> = {}
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== '' && value !== null) params[key] = value
    }
    const { data } = await api.get<UserListResponse>('/auth/users', { params })
    return data
  },

  async get(id: number): Promise<User> {
    const { data } = await api.get<User>(`/auth/users/${id}`)
    return data
  },

  async create(payload: UserCreatePayload): Promise<User> {
    const { data } = await api.post<User>('/auth/users', payload)
    return data
  },

  async update(id: number, payload: UserUpdatePayload): Promise<User> {
    const { data } = await api.put<User>(`/auth/users/${id}`, payload)
    return data
  },

  async setStatus(id: number, status: '0' | '1'): Promise<User> {
    const { data } = await api.patch<User>(`/auth/users/${id}/status`, { status })
    return data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/auth/users/${id}`)
  },
}
