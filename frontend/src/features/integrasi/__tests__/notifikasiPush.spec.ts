/**
 * MAKE-066 (F6-13) — composable push web: nonaktif tanpa konfigurasi/dukungan browser, izin hanya lewat aksi pengguna,
 * pendaftaran token memakai service worker `/firebase-messaging-sw.js` + VAPID dari env, lepas token saat Keluar, dan
 * kegagalan tidak pernah dilempar ke pemanggil. SDK Firebase dan API browser dipalsukan (tanpa jaringan).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const fb = vi.hoisted(() => ({
  initializeApp: vi.fn((_opsi: unknown, nama: string) => ({ name: nama })),
  getApps: vi.fn((): { name: string }[] => []),
  getApp: vi.fn((nama: string) => ({ name: nama })),
  getMessaging: vi.fn(() => ({ messaging: true })),
  getToken: vi.fn(async (): Promise<string> => 'tok-perangkat'),
}))

vi.mock('@firebase/app', () => ({ initializeApp: fb.initializeApp, getApps: fb.getApps, getApp: fb.getApp }))
vi.mock('@firebase/messaging', () => ({ getMessaging: fb.getMessaging, getToken: fb.getToken }))

const svc = vi.hoisted(() => ({ daftar: vi.fn(async () => 'baru'), lepas: vi.fn(async () => 1) }))
vi.mock('../services/fcmToken.service', () => ({ fcmTokenService: svc }))

import {
  _resetNotifikasiPush,
  aktifkanNotifikasiPush,
  daftarkanTokenPush,
  izinNotifikasi,
  konfigurasiFirebase,
  lepasTokenPush,
  SW_PATH,
} from '../composables/notifikasiPush'

const registrasi = { scope: '/' }
let izin: NotificationPermission = 'default'
const requestPermission = vi.fn(async (): Promise<NotificationPermission> => izin)

function pasangEnv(lengkap = true): void {
  vi.stubEnv('VITE_FIREBASE_API_KEY', lengkap ? 'kunci-web-uji' : '')
  vi.stubEnv('VITE_FIREBASE_PROJECT_ID', 'proyek-uji')
  vi.stubEnv('VITE_FIREBASE_MESSAGING_SENDER_ID', '123')
  vi.stubEnv('VITE_FIREBASE_APP_ID', '1:123:web:abc')
  vi.stubEnv('VITE_FIREBASE_VAPID_KEY', 'vapid-uji')
}

beforeEach(() => {
  _resetNotifikasiPush()
  izin = 'default'
  vi.clearAllMocks()
  pasangEnv()
  vi.stubGlobal('Notification', {
    get permission() {
      return izin
    },
    requestPermission,
  })
  vi.stubGlobal('PushManager', function PushManager() {})
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { register: vi.fn(async () => registrasi) } })
})

afterEach(() => {
  vi.unstubAllEnvs()
  vi.unstubAllGlobals()
  Reflect.deleteProperty(navigator, 'serviceWorker')
})

describe('konfigurasi & dukungan', () => {
  it('konfigurasi kosong → nonaktif dan tidak memanggil apa pun', async () => {
    pasangEnv(false)
    izin = 'granted'

    expect(konfigurasiFirebase()).toBeNull()
    expect(izinNotifikasi()).toBe('tidak-didukung')
    await expect(daftarkanTokenPush()).resolves.toBe(false)
    await expect(aktifkanNotifikasiPush()).resolves.toBe('tidak-didukung')
    expect(requestPermission).not.toHaveBeenCalled()
    expect(svc.daftar).not.toHaveBeenCalled()
  })

  it('browser tanpa service worker → tidak didukung', () => {
    Reflect.deleteProperty(navigator, 'serviceWorker')
    expect(izinNotifikasi()).toBe('tidak-didukung')
  })
})

describe('pendaftaran token', () => {
  it('izin belum diberikan → tidak mendaftar dan tidak meminta izin sendiri', async () => {
    await expect(daftarkanTokenPush()).resolves.toBe(false)
    expect(requestPermission).not.toHaveBeenCalled()
    expect(fb.getToken).not.toHaveBeenCalled()
  })

  it('izin diberikan → SW didaftarkan, getToken dengan VAPID + registrasi, token dikirim ke backend', async () => {
    izin = 'granted'

    await expect(daftarkanTokenPush()).resolves.toBe(true)

    expect(navigator.serviceWorker.register).toHaveBeenCalledWith(SW_PATH)
    expect(fb.initializeApp).toHaveBeenCalledWith(
      { apiKey: 'kunci-web-uji', authDomain: undefined, projectId: 'proyek-uji', messagingSenderId: '123', appId: '1:123:web:abc' },
      'simpeg-push',
    )
    expect(fb.getToken).toHaveBeenCalledWith({ messaging: true }, { vapidKey: 'vapid-uji', serviceWorkerRegistration: registrasi })
    expect(svc.daftar).toHaveBeenCalledWith('tok-perangkat')
  })

  it('aktifkan dari klik: minta izin lalu daftar; ditolak → tidak daftar', async () => {
    izin = 'granted'
    await expect(aktifkanNotifikasiPush()).resolves.toBe('granted')
    expect(requestPermission).toHaveBeenCalledOnce()
    expect(svc.daftar).toHaveBeenCalledOnce()

    izin = 'denied'
    await expect(aktifkanNotifikasiPush()).resolves.toBe('denied')
    expect(svc.daftar).toHaveBeenCalledOnce()
  })

  it('kegagalan FCM/backend ditelan dan token tidak dicatat ke konsol', async () => {
    izin = 'granted'
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    svc.daftar.mockRejectedValueOnce(new Error('jaringan'))

    await expect(daftarkanTokenPush()).resolves.toBe(false)
    expect(JSON.stringify(warn.mock.calls)).not.toContain('tok-perangkat')
    warn.mockRestore()
  })
})

describe('lepas saat Keluar', () => {
  it('melepas token terdaftar sekali; tanpa token tidak memanggil backend; gagal ditelan', async () => {
    await lepasTokenPush()
    expect(svc.lepas).not.toHaveBeenCalled()

    izin = 'granted'
    await daftarkanTokenPush()
    svc.lepas.mockRejectedValueOnce(new Error('401'))
    await expect(lepasTokenPush()).resolves.toBeUndefined()
    expect(svc.lepas).toHaveBeenCalledWith('tok-perangkat')

    await lepasTokenPush()
    expect(svc.lepas).toHaveBeenCalledOnce()
  })
})
