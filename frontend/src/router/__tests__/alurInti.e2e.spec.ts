/**
 * Regresi "E2E ringan" alur inti (MAKE-038): router asli (`router/index.ts`), Pinia asli, komponen halaman asli,
 * service & store asli. Yang ditiru HANYA lapisan HTTP: adapter axios pada instance `api` (`@/lib/axios`) diganti
 * backend tiruan, sehingga interceptor (unwrap envelope ADR-001, auto-refresh 401, normalisasi error) ikut teruji.
 * Bentuk `data` mengikuti type FE (LoginResponse, MeResponse, MasterMeta/MasterListResponse, PegawaiListResponse,
 * PegawaiDetail) dan fixture Modul B yang sudah ada.
 *
 * Alur: tamu → login (captcha mode mock) → beranda; Super Admin membuka master Agama, daftar & detail pegawai;
 * kasus negatif role Pegawai ditolak guard ke /403.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { RouterView } from 'vue-router'

import { Role, type MeResponse, type RoleCode, type User } from '@/features/auth/types'
import { listItem, pegawaiDetail } from '@/features/kepegawaian/__tests__/fixtures'
import type { PegawaiListResponse } from '@/features/kepegawaian/types'
import type { MasterListResponse, MasterMeta } from '@/features/master-data/types'
import { api, setAuthFailureHandler } from '@/lib/axios'

import router from '../index'

// ------------------------------------------------------------------
// Backend tiruan di level adapter axios
// ------------------------------------------------------------------

type Jawaban = { status: number; body: unknown }
type Handler = (config: InternalAxiosRequestConfig) => Jawaban

/** Rute tiruan: kunci `METHOD /path` (path relatif baseURL, tanpa query). */
let handlers: Record<string, Handler> = {}
/** Seluruh request yang sampai ke backend tiruan, untuk asersi. */
let requests: string[] = []

const sukses = (data: unknown): Jawaban => ({ status: 200, body: { status: 'success', data } })
const gagal = (status: number, message: string): Jawaban => ({ status, body: { status: 'error', message } })

const adapterAsli = api.defaults.adapter

function backendTiruan(config: InternalAxiosRequestConfig): Promise<AxiosResponse> {
  const path = (config.url ?? '').split('?')[0] ?? ''
  const key = `${(config.method ?? 'get').toUpperCase()} ${path}`
  requests.push(key)
  // Endpoint yang tidak didaftarkan test menjawab 404 (bukan data contoh), seperti backend tanpa route itu.
  const { status, body } = handlers[key]?.(config) ?? gagal(404, `Tidak ada route tiruan untuk ${key}`)
  const response: AxiosResponse = { data: body, status, statusText: String(status), headers: {}, config }
  if (status >= 200 && status < 300) return Promise.resolve(response)
  return Promise.reject(new AxiosError(`HTTP ${status}`, AxiosError.ERR_BAD_REQUEST, config, null, response))
}

// ------------------------------------------------------------------
// Data backend tiruan (bentuk dari type FE)
// ------------------------------------------------------------------

function user(role: RoleCode, extra: Partial<User> = {}): User {
  return {
    id_pengguna: 1,
    nip: null,
    username: 'admin.e2e',
    name: 'Admin E2E',
    email: null,
    user_level: role,
    id_unit: null,
    id_satker: null,
    status: '1',
    last_login_at: null,
    created_at: null,
    updated_at: null,
    must_change_password: false,
    ...extra,
  }
}

function me(role: RoleCode): MeResponse {
  return { user: user(role), claims: { role, id_unit: null, id_satker: null, exp: null } }
}

/** Meta master Agama mengikuti backend Config\MasterData (`agama`: PK id_agama, nameField agama). */
const META_AGAMA: MasterMeta = {
  key: 'agama',
  label: 'Agama',
  primary_key: 'id_agama',
  id_max_length: 3,
  id_digits: null,
  auto_increment: true,
  name_field: 'agama',
  name_label: 'Agama',
  name_max_length: 30,
  has_order: true,
  has_status: true,
  parent: null,
  fields: [],
  order_mode: 'shift',
}

const DAFTAR_AGAMA: MasterListResponse = {
  items: [{ id_agama: '1', agama: 'Islam', order: 1, status: '1' }],
  total: 1,
  page: 1,
  per_page: 20,
}

const PEGAWAI = listItem(1)
const DAFTAR_PEGAWAI: PegawaiListResponse = { items: [PEGAWAI], total: 1, page: 1, per_page: 10 }
const DETAIL_PEGAWAI = pegawaiDetail([], { nip: PEGAWAI.nip, nama: 'Sari Pengujian', glr_awal: null, glr_akhir: 'S.Kom.' })

/** Backend untuk sesi yang sudah login dengan `role` (cookie dianggap valid → /auth/me sukses). */
function backendSesi(role: RoleCode): void {
  handlers = {
    'GET /auth/me': () => sukses(me(role)),
    'GET /master/meta': () => sukses([META_AGAMA]),
    'GET /master/agama': () => sukses(DAFTAR_AGAMA),
    'GET /pegawai': () => sukses(DAFTAR_PEGAWAI),
    [`GET /pegawai/${PEGAWAI.nip}`]: () => sukses(DETAIL_PEGAWAI),
    'GET /master/unit/options': () => sukses([]),
    'GET /master/jenis-pegawai/options': () => sukses([]),
  }
}

// ------------------------------------------------------------------
// Pemasangan aplikasi
// ------------------------------------------------------------------

/** Akar aplikasi setara App.vue: hanya RouterView. */
const Root = defineComponent({ render: () => h('div', [h(RouterView)]) })

let pinia: Pinia

async function pasangAplikasi(path: string) {
  await router.push(path)
  await router.isReady()
  const wrapper = mount(Root, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await muatTuntas()
  return wrapper
}

/** Tunggu import halaman (lazy route), request tiruan, dan debounce daftar pegawai (250 ms). */
async function muatTuntas(): Promise<void> {
  await flushPromises()
  await new Promise((resolve) => setTimeout(resolve, 300))
  await flushPromises()
}

/**
 * Navigasi yang dipicu UI (submit login, klik tautan) tidak bisa di-await langsung: tunggu sampai route tujuan aktif
 * (import lazy halaman pertama kali bisa lebih dari ratusan ms di Vitest), lalu tunggu data halaman termuat.
 */
async function tungguRoute(name: string): Promise<void> {
  await vi.waitFor(() => expect(router.currentRoute.value.name).toBe(name), { timeout: 5000, interval: 20 })
  await muatTuntas()
}

enableAutoUnmount(afterEach)

beforeEach(async () => {
  pinia = createPinia()
  setActivePinia(pinia)
  requests = []
  handlers = {}
  api.defaults.adapter = backendTiruan
  // Setara main.ts: refresh gagal → kosongkan sesi (redirect ditangani guard), tanpa window.location di jsdom.
  setAuthFailureHandler(() => undefined)
  vi.stubEnv('VITE_TURNSTILE_SITE_KEY', '')
  // Mulai dari halaman tamu (tanpa /auth/me) agar setiap test benar-benar bernavigasi.
  await router.replace('/login')
})

afterEach(() => {
  vi.unstubAllEnvs()
})

afterAll(() => {
  api.defaults.adapter = adapterAsli
  setAuthFailureHandler(null)
})

// ------------------------------------------------------------------

describe('alur inti — tamu login sampai beranda (MAKE-038)', () => {
  it('tamu membuka / → dialihkan ke login; isi form + captcha → login Super Admin → beranda', async () => {
    let payloadLogin: unknown = null
    handlers = {
      // Cookie belum ada: /auth/me 401, refresh 401 → sesi tamu.
      'GET /auth/me': () => gagal(401, 'Unauthenticated'),
      'POST /auth/refresh': () => gagal(401, 'Refresh token tidak valid'),
      'POST /auth/login': (config) => {
        payloadLogin = JSON.parse(String(config.data))
        return sukses({ user: user(Role.SUPER_ADMIN), access_token: 'tiruan', access_expires_at: 0, refresh_expires_at: 0 })
      },
    }

    const wrapper = await pasangAplikasi('/')
    expect(router.currentRoute.value.name).toBe('login')
    expect(requests).toEqual(['GET /auth/me', 'POST /auth/refresh'])

    await wrapper.get('input[name="username"]').setValue('admin.e2e')
    await wrapper.get('input[name="password"]').setValue('rahasia-uji')
    await wrapper.get('[data-testid="captcha-mock"] input').setValue(true)
    await wrapper.get('[data-testid="login-form"]').trigger('submit')
    await tungguRoute('home')

    expect(payloadLogin).toEqual({ username: 'admin.e2e', password: 'rahasia-uji', captcha_token: 'mock-dev-token' })
    expect(wrapper.get('[data-testid="home-role"]').text()).toContain('1 — ')
    expect(wrapper.text()).toContain('Selamat datang, admin.e2e')
  })
})

describe('alur inti — Super Admin membuka master & pegawai (MAKE-038)', () => {
  it('/master/agama → meta + daftar dari backend tiruan tampil sebagai baris tabel', async () => {
    backendSesi(Role.SUPER_ADMIN)
    const wrapper = await pasangAplikasi('/master/agama')

    expect(router.currentRoute.value.name).toBe('master-data')
    expect(requests).toContain('GET /master/meta')
    expect(requests).toContain('GET /master/agama')
    expect(wrapper.get('[data-testid="master-row-1"]').text()).toContain('Islam')
  })

  it('/pegawai → baris pegawai tampil; klik nama → detail pegawai menampilkan nama dari GET pegawai/{nip}', async () => {
    backendSesi(Role.SUPER_ADMIN)
    const wrapper = await pasangAplikasi('/pegawai')

    expect(router.currentRoute.value.name).toBe('pegawai-list')
    const baris = wrapper.get(`[data-testid="pegawai-row-${PEGAWAI.nip}"]`)
    expect(baris.text()).toContain('Pegawai 1')
    expect(baris.text()).toContain(PEGAWAI.nip)

    await baris.get(`a[href="/pegawai/${PEGAWAI.nip}"]`).trigger('click')
    await tungguRoute('pegawai-detail')

    expect(router.currentRoute.value.params.nip).toBe(PEGAWAI.nip)
    expect(requests).toContain(`GET /pegawai/${PEGAWAI.nip}`)
    expect(wrapper.get('[data-testid="pegawai-name"]').text()).toBe('Sari Pengujian, S.Kom.')
  })
})

describe('alur inti — kasus negatif role Pegawai (MAKE-038)', () => {
  it.each(['/pegawai', '/master/agama'])('role 2 membuka %s → ditolak guard ke /403 tanpa memanggil endpoint halaman', async (path) => {
    backendSesi(Role.PEGAWAI)
    const wrapper = await pasangAplikasi(path)

    expect(router.currentRoute.value.name).toBe('forbidden')
    expect(wrapper.text()).toContain('Akses ditolak')
    expect(requests).toEqual(['GET /auth/me'])
  })
})
