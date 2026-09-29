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
`order_scope` + `filters` (filter daftar; panah urutan hanya saat satu lingkup urutan utuh tampil — field `order_scope`
yang tidak ada di `filters` membuat panah tidak pernah tampil, ubah urutan lewat Edit), filter field `ref` (pilihan dari
`{entity}/options`), `status_chain`
(keterangan hapus induk).

CR-010 (DBV-003): field `ref` ber-`allow_system` (kolom wilayah kantor) mendapat pilihan LAIN-LAIN dari `system_ids`
master rujukan; LAIN-LAIN berjenjang ke semua level turunan (level bawah terkunci, tanpa memanggil API), dan field ber-
`other_for` (`*_lain`) hanya tampil & wajib saat field ref-nya LAIN-LAIN (tersembunyi = dikosongkan). Halaman Hari Libur
(`views/HariLiburView.vue`, route `/hari-libur`, menu untuk role 1/4/5/8) memakai `services/hariLibur.service.ts`
(`/hari-libur`), `schemas/hariLibur.schema.ts`, `components/HariLiburFormDialog.vue`, dan tipe di `hariLibur.types.ts`;
tombol Tambah, menu aksi baris ⋮ (`RowActionsMenu`: Edit, Nonaktifkan/Aktifkan, Pulihkan, Hapus), dan filter status hanya
untuk role 1 (role 4/5/8 hanya menerima status Aktif, tanpa kolom audit); kolom Status hanya badge.
