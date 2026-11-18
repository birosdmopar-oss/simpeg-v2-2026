# Modul E — Halo Simpeg (Fase 8)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `src/router`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — Pinia store domain modul ini (ADR-021)
- `schemas/` — skema Zod untuk VeeValidate (ADR-026)

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

## Helpdesk (MAKE-073) — catatan sementara (dilengkapi di B5)

- Kontrak: README `backend/app/Controllers/Api/HaloSimpeg` (master MAKE-070, tiket MAKE-071). Fixture `__tests__/fixtures/` =
  salinan `backend/tests/_support/HaloSimpeg/*.json` dari `main`; salin ulang bila kontrak berubah lewat CR.
- State tingkat modul (`stores/haloSimpeg.ts`, `stores/tiket.ts`), dikosongkan `resetHaloSimpeg()` dari `clearSession()`.
- Layar pemilik (`/halo-simpeg`, `/halo-simpeg/tiket/:id`): master-detail; ⋮ Lihat detail → Tutup → Hapus; "Tambah Pertanyaan"
  hanya role 2/6/7. **[V2]** kolom + filter kategori di tab "Tiket saya" (PRD §7.4; legacy hanya untuk petugas multi-kategori).
- **Tanpa frame Figma** (H8-9): layar pemilik/petugas/admin mengikuti pola redesign (Gambar 33/34, Master Data); dicatat di B5.
