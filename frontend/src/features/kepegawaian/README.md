# Modul B — Kepegawaian Core (Fase 3)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `routes.pegawai.ts` / `routes.riwayat.ts`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — Pinia store domain modul ini (ADR-021)
- `schemas/` — skema Zod untuk VeeValidate (ADR-026)

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

## Fondasi Sprint 0-2 (MAKE-003)

Kontrak backend yang diikuti: `backend/app/Controllers/Api/Kepegawaian/README.md` (MAKE-002, beku).

| Berkas | Isi | Pemilik |
|---|---|---|
| `routes.pegawai.ts` | Daftar/Detail Pegawai, Struktur Organisasi, Usulan Konket, Verifikasi LKH | WS-2 |
| `routes.riwayat.ts` | Usulan Karpeg/Karis | WS-1 |
| `roles.ts` | Role menu/route per Matriks v2 | WS-2 |
| `types.ts` | Tipe = kolom DDL (pegawai, `riwayat_*`, `document_attachment` dengan kunci `NIP`), slug `{jenis}` (engine ∪ non-engine), `RiwayatTabDescriptor`, `StatusRiwayat` | bersama (aditif) |
| `riwayat/jenis/<slug>.ts` | Satu konfigurasi per jenis tab (kolom & isian = kolom DDL, lampiran per kode `jenis_rwy`) | WS-1 (kecuali `jabatan.ts`, `lkh.ts`: WS-2) |
| `riwayat/registry.ts` | Loader `import.meta.glob('./jenis/*.ts')`; tab dirender dari descriptor `GET pegawai/{nip}` → `tabs` | WS-1 |
| `riwayat/RiwayatTabHost.vue` | Props beku `nip`, `descriptor`, `config`; engine → `RiwayatListSection`, non-engine → "belum tersedia" | WS-1 |
| `riwayat/riwayat.service.ts` | Riwayat: tambah multipart (`berkas[<id_riwayat>]`) / JSON tanpa berkas; ubah PUT JSON atau POST multipart + `_method=PUT`; hapus; process | WS-1 |
| `services/lampiran.service.ts` | Lampiran baris yang sudah ada: daftar, unggah, unduh, hapus | WS-2 |
| `services/pegawai.service.ts` | `GET pegawai`, `GET pegawai/{nip}` | WS-2 |
| `services/apiErrors.ts` | 401 → 404 jenis → 403 izin/lingkup → 404 data; 422 `errors.<kolom>` / `errors["berkas.<id>"]` / `errors.berkas`; 501 stub | bersama |
| `views/DetailPegawaiPage.vue` | Tab Data Umum (hanya-lihat sampai B-03/B-04) + tab dari descriptor | WS-2 |

Tidak ada data contoh di runtime: fixture hanya di `__tests__/fixtures.ts` (dijaga `__tests__/noRuntimeMock.spec.ts`).

Belum ditetapkan kontrak (ditandai `TODO(kontrak)` di kode, jangan diasumsikan): representasi NULL di multipart
(sementara string kosong), wajib-tidaknya query `GET lampiran` (sementara keduanya dikirim), apakah lampiran ikut di
respons riwayat, semantik "ganti" lampiran (belum ada fungsi ganti; ditetapkan WS-2 MAKE-009). Aturan lampiran per
jenis (kode, wajib, batas MB, ekstensi) disalin dari Definisi backend oleh pemilik jenis; sampai itu `lampiran: []`. Bentuk respons `GET pegawai`
(kolom snapshot, filter) dan endpoint Struktur Organisasi ditetapkan WS-2 di B-19/B-20.
