# Modul H — Integrasi & API Eksternal (Fase 6)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `src/router`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — Pinia store domain modul ini (ADR-021)
- `schemas/` — skema Zod untuk VeeValidate (ADR-026)

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

## Push web FCM (H-04, MAKE-066)

- `services/fcmToken.service.ts` — `POST|DELETE /integrasi/fcm-token` (amplop dibuka interceptor `@/lib/axios`).
- `composables/notifikasiPush.ts` — konfigurasi `VITE_FIREBASE_*` (kosong = nonaktif), SDK modular `@firebase/app` +
  `@firebase/messaging` dimuat dinamis, daftar token setiap aplikasi dimuat bila izin sudah diberikan, lepas saat Keluar
  (`auth.store.logout`).
- `components/NotifikasiPushShell.vue` — banner izin (`UiNotice` + `UiButton`) di `RedesignShell`; izin browser hanya diminta
  dari klik "Aktifkan".
- `public/firebase-messaging-sw.js` — service worker mandiri (tanpa SDK/CDN): tampilkan notifikasi, klik membuka `data.link`
  se-origin.
