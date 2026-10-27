/**
 * Axios instance terpusat (F0-09) — ADR-022.
 *
 * - `withCredentials: true`  → cookie httpOnly (access/refresh token, ADR-004) selalu ikut terkirim.
 * - Interceptor response      → unwrap envelope {status:'success', data} (ADR-001) menjadi `response.data = data`,
 *                               dan normalisasi error menjadi {status, message, errors}.
 * - Auto-refresh              → response 401 memicu POST /auth/refresh lalu request diulang SEKALI.
 *                               Kalau refresh gagal (atau retry tetap 401) → handler auth-failure (default: redirect /login).
 *                               Beberapa 401 bersamaan berbagi satu refresh in-flight (tidak ada refresh storm/loop).
 * - 403 password_change_required (MAKE-020/028) → handler wajib-ganti-password dijalankan SEKALI (bersama untuk
 *                               request paralel): pemanggil mengambil ulang /auth/me dan mengarahkan ke /ganti-password.
 *                               Endpoint yang memang dibuka selama wajib ganti (auth/me, change-password, logout,
 *                               refresh) tidak memicunya, sehingga tidak ada loop. Request yang diulang setelah
 *                               auto-refresh juga lewat jalur ini, jadi claim `must_change_password` pada token baru
 *                               (mis. password kedaluwarsa) langsung memperbarui store tanpa muat ulang halaman.
 *
 * Seluruh feature WAJIB memakai `api` dari file ini — jangan membuat axios instance lain.
 */
import axios, {
  AxiosError,
  type AxiosInstance,
  type AxiosRequestConfig,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'

export interface ApiEnvelope<T = unknown> {
  status: 'success' | 'error'
  data?: T
  message?: string
  errors?: Record<string, string[]>
}

/** Bentuk error yang konsisten untuk seluruh consumer (komponen tidak perlu tahu internal axios). */
export interface ApiError {
  status: number | null
  message: string
  errors: Record<string, string[]> | null
  isNetworkError: boolean
  original: AxiosError
}

export const API_BASE_URL: string =
  (import.meta.env?.VITE_API_BASE_URL as string | undefined) ?? 'http://localhost:8080/api/v1'

/** Endpoint refresh token (Modul A, Fase 1). Relatif terhadap baseURL. */
export const REFRESH_PATH = '/auth/refresh'

/** Route halaman login (Modul A, Fase 1). */
export const LOGIN_ROUTE = '/login'

type RetriableConfig = InternalAxiosRequestConfig & { _retry?: boolean; _isRefresh?: boolean }

export type AuthFailureHandler = () => void

/** Kode error 403 backend untuk sesi yang wajib mengganti password dulu (JwtAuthFilter, MAKE-020). */
export const PASSWORD_CHANGE_REQUIRED_CODE = 'password_change_required'

/** Endpoint yang tetap terbuka selama wajib ganti password; tidak pernah memicu handler (anti-loop). */
const PASSWORD_CHANGE_OPEN_PATHS = ['/auth/me', '/auth/change-password', '/auth/logout', REFRESH_PATH]

export type PasswordChangeRequiredHandler = () => Promise<void> | void

let passwordChangeHandler: PasswordChangeRequiredHandler | null = null
let passwordChangeInFlight: Promise<void> | null = null

/**
 * Pasang aksi saat backend menjawab 403 `password_change_required` (mis. ambil ulang user + redirect ke
 * /ganti-password). null = tanpa aksi (default).
 */
export function setPasswordChangeRequiredHandler(handler: PasswordChangeRequiredHandler | null): void {
  passwordChangeHandler = handler
}

function isPasswordChangeRequired(error: AxiosError, config: InternalAxiosRequestConfig | undefined): boolean {
  if (error.response?.status !== 403 || !config) return false
  const body = error.response.data as { code?: unknown } | undefined
  if (body?.code !== PASSWORD_CHANGE_REQUIRED_CODE) return false
  const path = (config.url ?? '').split('?')[0] ?? ''
  return !PASSWORD_CHANGE_OPEN_PATHS.some((open) => path.endsWith(open))
}

/** Jalankan handler sekali untuk semua 403 yang bersamaan; kegagalan handler tidak menutupi error aslinya. */
function runPasswordChangeHandler(): Promise<void> {
  if (passwordChangeHandler === null) return Promise.resolve()
  if (passwordChangeInFlight === null) {
    passwordChangeInFlight = Promise.resolve()
      .then(() => passwordChangeHandler?.())
      .catch(() => undefined)
      .finally(() => {
        passwordChangeInFlight = null
      })
  }
  return passwordChangeInFlight
}

const defaultAuthFailureHandler: AuthFailureHandler = () => {
  if (typeof window !== 'undefined' && window.location.pathname !== LOGIN_ROUTE) {
    window.location.assign(LOGIN_ROUTE)
  }
}

let authFailureHandler: AuthFailureHandler = defaultAuthFailureHandler

/**
 * Override aksi saat refresh gagal (mis. router.push('/login') + reset store). Default: redirect hard ke /login.
 */
export function setAuthFailureHandler(handler: AuthFailureHandler | null): void {
  authFailureHandler = handler ?? defaultAuthFailureHandler
}

export function isApiError(value: unknown): value is ApiError {
  return typeof value === 'object' && value !== null && 'isNetworkError' in value && 'original' in value
}

function normalizeError(error: AxiosError): ApiError {
  const body = error.response?.data as Partial<ApiEnvelope> | undefined
  const status = error.response?.status ?? null

  let message: string
  if (body && typeof body === 'object' && typeof body.message === 'string' && body.message !== '') {
    message = body.message
  } else if (status === null) {
    message = 'Tidak dapat terhubung ke server. Periksa koneksi Anda.'
  } else {
    message = error.message || `Request gagal (HTTP ${status})`
  }

  return {
    status,
    message,
    errors: body && typeof body === 'object' && body.errors ? body.errors : null,
    isNetworkError: status === null,
    original: error,
  }
}

function unwrapEnvelope(response: AxiosResponse): AxiosResponse {
  const body = response.data as unknown
  if (
    body !== null &&
    typeof body === 'object' &&
    'status' in body &&
    (body as ApiEnvelope).status === 'success' &&
    'data' in body
  ) {
    response.data = (body as ApiEnvelope).data
  }
  return response
}

export interface CreateApiClientOptions {
  baseURL?: string
  /** Hanya untuk test: adapter kustom tanpa network. */
  adapter?: AxiosRequestConfig['adapter']
}

/**
 * Factory instance — dipakai oleh `api` default dan oleh unit test (dengan adapter mock).
 */
export function createApiClient(options: CreateApiClientOptions = {}): AxiosInstance {
  const client = axios.create({
    baseURL: options.baseURL ?? API_BASE_URL,
    withCredentials: true,
    headers: { Accept: 'application/json' },
    ...(options.adapter ? { adapter: options.adapter } : {}),
  })

  // Satu refresh in-flight untuk seluruh request yang 401 bersamaan.
  let refreshPromise: Promise<void> | null = null

  const refreshOnce = (): Promise<void> => {
    if (refreshPromise === null) {
      const refreshConfig: AxiosRequestConfig & { _isRefresh: boolean } = { _isRefresh: true }
      refreshPromise = client
        .post(REFRESH_PATH, null, refreshConfig)
        .then(() => undefined)
        .finally(() => {
          refreshPromise = null
        })
    }
    return refreshPromise
  }

  client.interceptors.response.use(unwrapEnvelope, async (error: unknown) => {
    if (!axios.isAxiosError(error)) {
      return Promise.reject(error)
    }

    const config = error.config as RetriableConfig | undefined
    const status = error.response?.status

    if (status === 401 && config && !config._isRefresh) {
      if (!config._retry) {
        // Percobaan pertama: refresh lalu ulangi request SEKALI.
        config._retry = true
        try {
          await refreshOnce()
        } catch {
          authFailureHandler()
          return Promise.reject(normalizeError(error))
        }
        return client.request(config)
      }

      // Sudah pernah di-retry dan masih 401 → sesi benar-benar habis. Tidak ada retry ketiga.
      authFailureHandler()
    }

    if (status === 401 && config?._isRefresh) {
      // Refresh endpoint sendiri menjawab 401 → refresh token invalid/expired/reused.
      // Redirect ditangani oleh pemanggil refreshOnce() lewat catch di atas.
    }

    if (isPasswordChangeRequired(error, config)) {
      await runPasswordChangeHandler()
    }

    return Promise.reject(normalizeError(error))
  })

  return client
}

/** Instance tunggal untuk seluruh aplikasi. */
export const api: AxiosInstance = createApiClient()

export default api
