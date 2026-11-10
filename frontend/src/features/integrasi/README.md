# Modul H — Integrasi & API Eksternal (Fase 6)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `src/router`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — Pinia store domain modul ini (ADR-021)
- `schemas/` — skema Zod untuk VeeValidate (ADR-026)

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

## Admin → Integrasi (MAKE-078, ISSUE-058)

- Route `/integrasi` (`routes.integrasi.ts`, role 1 `INTEGRASI_ADMIN_ROLES`), menu Pengaturan → Integrasi (`nav.config.ts`,
  `phase: 6` + `rilis`). Figma halaman ini belum ada: tata letak mengikuti halaman daftar Hari Libur (`page-fill` +
  `table-fill`).
- `services/logIntegrasi.service.ts` — `GET /integrasi/log` (filter kosong tidak dikirim) dan `POST /integrasi/bkn/tarik`.
- `views/IntegrasiAdminView.vue` — tabel log (filter layanan dari `layanan_tersedia`, status, rentang tanggal WIB,
  `UiPagination`, badge status, waktu UTC lewat `formatApiDateTime`); aksi baris ⋮ hanya "Lihat detail"
  (`components/LogIntegrasiDetailDialog.vue`) karena log baca-saja.
- `components/TarikEkinerjaDialog.vue` — tombol level halaman "Tarik Ulang e-Kinerja": `skp_periodik` (bulan opsional; kosong =
  periode terpilih otomatis) atau `periode`. 202 = job diantre di antrean `integrasi`; hasilnya muncul di log setelah diproses.

## Push web FCM (H-04, MAKE-066)

- `services/fcmToken.service.ts` — `POST|DELETE /integrasi/fcm-token` (amplop dibuka interceptor `@/lib/axios`).
- `composables/notifikasiPush.ts` — konfigurasi `VITE_FIREBASE_*` (kosong = nonaktif), SDK modular `@firebase/app` +
  `@firebase/messaging` dimuat dinamis, daftar token setiap aplikasi dimuat bila izin sudah diberikan, lepas saat Keluar
  (`auth.store.logout`).
- `components/NotifikasiPushShell.vue` — banner izin (`UiNotice` + `UiButton`) di `RedesignShell`; izin browser hanya diminta
  dari klik "Aktifkan".
- `public/firebase-messaging-sw.js` — service worker mandiri (tanpa SDK/CDN): tampilkan notifikasi, klik membuka `data.link`
  se-origin.
