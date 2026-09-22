/**
 * Store sesi pengguna (A-11) — Pinia, satu store per domain (ADR-021).
 * State auth hilang saat refresh browser (token di cookie httpOnly), maka route guard memanggil
 * fetchCurrentUser() saat pertama kali diperlukan (ADR-024). Role di FE hanya untuk UX; otorisasi
 * sesungguhnya di RoleFilter backend.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { isApiError } from '@/lib/axios'

import { authService } from '../services/auth.service'
import { type LoginPayload, Role, type RoleCode, type SessionClaims, type User, USER_MANAGEMENT_ROLES } from '../types'

export type AuthStatus = 'unknown' | 'loading' | 'authenticated' | 'guest'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const claims = ref<SessionClaims | null>(null)
  const status = ref<AuthStatus>('unknown')

  const isAuthenticated = computed(() => status.value === 'authenticated' && user.value !== null)
  const role = computed<RoleCode | null>(() => user.value?.user_level ?? null)
  const canManageUsers = computed(
    () => role.value !== null && (USER_MANAGEMENT_ROLES as readonly number[]).includes(role.value),
  )
  /** Menu Master Data (Modul G) — role 1 saja. */
  const canManageMasterData = computed(() => role.value === Role.SUPER_ADMIN)

  function setSession(nextUser: User, nextClaims: SessionClaims | null = null): void {
    user.value = nextUser
    claims.value = nextClaims
    status.value = 'authenticated'
  }

  function clearSession(): void {
    user.value = null
    claims.value = null
    status.value = 'guest'
  }

  /**
   * Validasi cookie ke backend (GET /auth/me). Aman dipanggil berulang: hanya hit API saat status belum diketahui.
   */
  async function fetchCurrentUser(force = false): Promise<boolean> {
    if (!force && status.value === 'authenticated') return true
    if (!force && status.value === 'guest') return false

    status.value = 'loading'
    try {
      const me = await authService.me()
      setSession(me.user, me.claims)
      return true
    } catch (err) {
      clearSession()
      if (isApiError(err) && (err.status === 401 || err.status === null)) return false
      throw err
    }
  }

  async function login(payload: LoginPayload): Promise<User> {
    const result = await authService.login(payload)
    setSession(result.user, {
      role: result.user.user_level,
      id_unit: result.user.id_unit,
      id_satker: result.user.id_satker,
      exp: result.access_expires_at,
    })
    return result.user
  }

  async function logout(): Promise<void> {
    try {
      await authService.logout()
    } finally {
      clearSession()
    }
  }

  return {
    user,
    claims,
    status,
    isAuthenticated,
    role,
    canManageUsers,
    canManageMasterData,
    setSession,
    clearSession,
    fetchCurrentUser,
    login,
    logout,
  }
})
