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
- Layar pemilik (`/halo-simpeg`, `/halo-simpeg/tiket/:id`): master-detail; ⋮ Lihat detail → Tutup → Unduh lampiran → Hapus (lampiran pesan terakhir lewat E-04, kolom hanya teks); "Tambah Pertanyaan"
  hanya role 2/6/7. **[V2]** kolom + filter kategori di tab "Tiket saya" (PRD §7.4; legacy hanya untuk petugas multi-kategori).
- Layar petugas (B3): tab "Antrean" hanya bila `petugas/saya` aktif (`?tab=antrean`), bawaan baca `belum` [K], kolom Pemilik [K],
  filter kategori hanya bila ≥ 2 kategori [K `VI:31-36`]; panel "Perlu ditindak" = daftar server `perlu_tindak=1` (kosong tetap tampil,
  pengganti popup login legacy F8-12); snapshot pelapor di header thread lewat `UiTooltip`; centang "Ingatkan Saya Kembali?" hanya bila
  `pengingat !== null`. Store `stores/tiket.ts` = pabrik daftar per peran (filter/halaman terpisah) + detail & aksi bersama.
- Layar petugas (lanjutan): "Lihat semua" Perlu ditindak = `perlu_tindak=1` + `baca=semua` tanpa filter lain; "Hapus filter" →
  bawaan antrean. Panel dimuat ulang saat `Ringkasan.perlu_tindak` berubah (satu pemicu per aksi). Snapshot: desktop = tombol
  "Data pelapor" + tooltip (`aria-describedby`), layar sempit = teks kecil. Pengingat aktif → petunjuk pembatalan + jatuh tempo.
- Admin master (B3, role 1): `/halo-simpeg-admin/:bagian` (Kategori | Petugas | Kontak); `:bagian` tidak dikenal → Kategori.
  ⋮ Edit → Aktifkan/Nonaktifkan → Hapus. Hapus `disabled` dari `bisa_hapus`; alasannya = `RowAction.keterangan` di item ⋮
  (`alasan_tidak_bisa_hapus` BE untuk Petugas, teks FE **[V2]** untuk Kategori); kolom Status hanya badge (AGENTS.md §1 butir 5).
  `peringatan` Nonaktifkan/PUT petugas (S8) → notice peringatan. Kandidat petugas: hasil server tanpa saring lokal, dipilih lewat
  id (`UiCombobox` `saringLokal=false` + `pilih`).
- **Tanpa frame Figma** (H8-9): layar pemilik/petugas/admin mengikuti pola redesign (Gambar 33/34, Master Data); dicatat di B5.
