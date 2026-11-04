/*
 * Service worker push web SIMPEG v2 (PRD Fase 6 §7.4 F6-13, MAKE-066) — padanan `html/firebase-messaging-sw.js` legacy.
 *
 * Sengaja TANPA SDK Firebase dan tanpa konfigurasi proyek (legacy memuat SDK dari CDN): token didaftarkan oleh SPA lewat
 * SDK modular dengan registrasi service worker ini, sedangkan pesan FCM diterima sebagai event `push` biasa
 * `{notification: {title, body, icon}, data: {link, …}}` lalu ditampilkan di sini.
 *
 * Klik notifikasi membuka `data.link` [K legacy `notificationclick`]; v2 menerima tautan relatif SPA dan HANYA membuka
 * alamat se-origin (tautan ke host lain diabaikan). Bila tab SIMPEG sudah terbuka, tab itu difokuskan lalu diarahkan.
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let payload = {}
  try {
    payload = event.data ? event.data.json() : {}
  } catch {
    payload = {}
  }

  const notification = payload.notification || {}
  const data = payload.data || {}
  const judul = notification.title || data.title || 'SIMPEG'

  event.waitUntil(
    self.registration.showNotification(judul, {
      body: notification.body || data.body || '',
      icon: notification.icon || data.icon || '/apple-touch-icon.png',
      data: { link: data.link || data.url || '/' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  let tujuan
  try {
    tujuan = new URL((event.notification.data && event.notification.data.link) || '/', self.location.origin)
  } catch {
    return
  }

  if (tujuan.origin !== self.location.origin) return

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((jendela) => {
      const tab = jendela.find((c) => new URL(c.url).origin === self.location.origin && 'focus' in c)
      if (tab) {
        return tab.focus().then((c) => (c && 'navigate' in c ? c.navigate(tujuan.href) : undefined))
      }
      return self.clients.openWindow ? self.clients.openWindow(tujuan.href) : undefined
    }),
  )
})
