/**
 * Push web FCM (PRD Fase 6 §7.4 F6-13, MAKE-066) — pengganti `register_fb_token` legacy [K `header.php:59`]: setelah login
 * dan izin notifikasi diberikan, token FCM perangkat didaftarkan ke backend setiap aplikasi dimuat (backend
 * memperpanjang masa berlaku 1 minggu); saat Keluar token dilepas [V2].
 *
 * - Konfigurasi web Firebase dari env Vite `VITE_FIREBASE_*` (proyek yang sama dengan legacy, PN-3); bukan rahasia, tetapi
 *   tidak disalin dari `header.php` legacy. Konfigurasi kosong → fitur nonaktif tanpa galat (mis. lokal/CI).
 * - SDK modular `@firebase/app` + `@firebase/messaging` dimuat dinamis (tidak masuk bundle awal); service worker
 *   `public/firebase-messaging-sw.js` menampilkan notifikasi dan membuka `data.link`.
 * - Izin browser hanya diminta dari klik pengguna ({@link aktifkanNotifikasiPush}, banner `NotifikasiPushShell`).
 * - Kegagalan apa pun (browser tanpa dukungan, jaringan, FCM) tidak pernah menggagalkan alur aplikasi; token tidak pernah
 *   dicatat ke konsol.
 */
import { fcmTokenService } from '../services/fcmToken.service'

export interface KonfigurasiFirebaseWeb {
  apiKey: string
  authDomain?: string
  projectId: string
  messagingSenderId: string
  appId: string
  vapidKey: string
}

export type IzinNotifikasi = NotificationPermission | 'tidak-didukung'

export const SW_PATH = '/firebase-messaging-sw.js'

const NAMA_APP = 'simpeg-push'

const KUNCI_NANTI = 'integrasi-notifikasi-push-nanti'

let tokenAktif: string | null = null

function env(nama: string): string {
  return String((import.meta.env as Record<string, unknown>)[nama] ?? '').trim()
}

/** Konfigurasi web Firebase dari env Vite; null bila ada nilai wajib yang kosong. */
export function konfigurasiFirebase(): KonfigurasiFirebaseWeb | null {
  const k = {
    apiKey: env('VITE_FIREBASE_API_KEY'),
    authDomain: env('VITE_FIREBASE_AUTH_DOMAIN') || undefined,
    projectId: env('VITE_FIREBASE_PROJECT_ID'),
    messagingSenderId: env('VITE_FIREBASE_MESSAGING_SENDER_ID'),
    appId: env('VITE_FIREBASE_APP_ID'),
    vapidKey: env('VITE_FIREBASE_VAPID_KEY'),
  }

  return k.apiKey && k.projectId && k.messagingSenderId && k.appId && k.vapidKey ? k : null
}

/** Browser mendukung push web dan konfigurasi tersedia. */
export function pushDidukung(): boolean {
  return (
    konfigurasiFirebase() !== null &&
    typeof window !== 'undefined' &&
    'Notification' in window &&
    'PushManager' in window &&
    typeof navigator !== 'undefined' &&
    'serviceWorker' in navigator
  )
}

export function izinNotifikasi(): IzinNotifikasi {
  return pushDidukung() ? Notification.permission : 'tidak-didukung'
}

async function ambilToken(konfigurasi: KonfigurasiFirebaseWeb): Promise<string> {
  const [{ getApp, getApps, initializeApp }, { getMessaging, getToken }] = await Promise.all([
    import('@firebase/app'),
    import('@firebase/messaging'),
  ])
  const { vapidKey, ...opsiApp } = konfigurasi
  const app = getApps().some((a) => a.name === NAMA_APP) ? getApp(NAMA_APP) : initializeApp(opsiApp, NAMA_APP)
  const registrasi = await navigator.serviceWorker.register(SW_PATH)

  return getToken(getMessaging(app), { vapidKey, serviceWorkerRegistration: registrasi })
}

/**
 * Daftarkan token perangkat ke backend bila izin sudah diberikan (dipanggil setiap aplikasi dimuat setelah login).
 *
 * @returns true bila token terdaftar
 */
export async function daftarkanTokenPush(): Promise<boolean> {
  const konfigurasi = konfigurasiFirebase()

  if (konfigurasi === null || izinNotifikasi() !== 'granted') return false

  try {
    const token = await ambilToken(konfigurasi)
    if (!token) return false
    await fcmTokenService.daftar(token)
    tokenAktif = token
    return true
  } catch {
    console.warn('[notifikasi-push] pendaftaran token push gagal; aplikasi tetap berjalan.')
    return false
  }
}

/** Minta izin notifikasi (WAJIB dari klik pengguna) lalu daftarkan token bila diizinkan. */
export async function aktifkanNotifikasiPush(): Promise<IzinNotifikasi> {
  if (!pushDidukung()) return 'tidak-didukung'

  let izin: NotificationPermission
  try {
    izin = await Notification.requestPermission()
  } catch {
    return Notification.permission
  }

  if (izin === 'granted') await daftarkanTokenPush()
  return izin
}

/** Lepas token perangkat ini dari akun (dipanggil saat Keluar, sebelum sesi diakhiri). Gagal → diabaikan. */
export async function lepasTokenPush(): Promise<void> {
  const token = tokenAktif
  tokenAktif = null

  if (token === null) return

  try {
    await fcmTokenService.lepas(token)
  } catch {
    // Sesi tetap diakhiri; token tetap tercatat untuk akun ini sampai dilepas pada logout berikutnya atau dihapus karena
    // dilaporkan tidak sah oleh FCM.
  }
}

/** Pengguna memilih "Nanti" pada banner izin — disimpan per browser; gagal akses penyimpanan → tidak fatal. */
export function sudahDitunda(): boolean {
  try {
    return window.localStorage.getItem(KUNCI_NANTI) === '1'
  } catch {
    return false
  }
}

export function tandaiDitunda(): void {
  try {
    window.localStorage.setItem(KUNCI_NANTI, '1')
  } catch {
    // Penyimpanan tidak tersedia → banner bisa tampil lagi pada muat berikutnya.
  }
}

/** Untuk test. */
export function _resetNotifikasiPush(): void {
  tokenAktif = null
}
