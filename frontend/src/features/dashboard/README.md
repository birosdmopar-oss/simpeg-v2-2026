# Modul F — Dashboard & News (Fase 7)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `src/router`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — Pinia store domain modul ini (ADR-021)
- `schemas/` — skema Zod untuk VeeValidate (ADR-026)

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

## Beranda per role (F-13, MAKE-087, Jalur D)

- `views/HomeView.vue` (route `home`): sapaan + `data-testid="home-role"` (MTC-001), lalu `AdminDashboard` (role 1/3/4/5/8)
  atau `PegawaiDashboard` (role 2/6/7) sesuai `ADMIN_DASHBOARD_ROLES` (`types.ts`). Data `services/beranda.service.ts`
  (`GET /dashboard/ringkasan`); galat tampil sebagai pesan tanpa merusak halaman.
- `components/KomposisiPegawaiCard.vue` — saklar Q7-36 dari backend: `donut` (donut jenis pegawai + tile status hari ini)
  atau `angka` (11 tile berurutan legacy + bar persen, tanpa chart). Rollback ke tampilan legacy cukup lewat
  `Config\Dashboard::$tampilanKomposisi` di backend.
- M2a: `PengingatCards` (Pensiun → `/dokumen/pensiun`; KP/TJ/KGB tanpa tautan, Q7-37), `ButuhApprovalCard` (role 1/3 di
  keluarga admin; adapter SEMENTARA `composables/ringkasanNotifikasi.ts` berbentuk kontrak Jalur C — hapus dan impor
  `@/features/notifikasi` begitu F-08 di `main`), `DirekturCard` (role 1/4, `GET /dashboard/direktur`).
- Kerangka M1: slot banner/popup/berita (Jalur A `@/features/konten`), Butuh Approval (Jalur C `@/features/notifikasi`),
  pengingat/prediksi/ulang tahun/direktur diisi M2–M3. Figma beranda belum ada; tata letak mengikuti mockup redesign.
