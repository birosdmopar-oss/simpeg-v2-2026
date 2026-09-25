# Modul G — Master Data & Pengaturan (Fase 2)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `src/router`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — Pinia store domain modul ini (ADR-021)
- `schemas/` — skema Zod untuk VeeValidate (ADR-026)

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

Halaman master generik (`views/MasterDataView.vue`, `components/MasterFormDialog.vue`) dibangun dari `GET /master/meta`.
Opsi engine CR-009 yang dibaca FE: field `ref` (dropdown `{entity}/options`, berjenjang lewat `depends_on`), `boolean`
(checkbox 1/0), batas angka `min`/`max`, `order_mode` (`manual` = nilai urutan tetap, tanpa panah naik/turun),
`order_scope` + `filters` (filter daftar; panah urutan hanya saat satu lingkup urutan utuh tampil), `status_chain`
(keterangan hapus induk).
