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
| `routes.riwayat.ts` | Usulan Karpeg/Karis; **sementara** `/pegawai/:nip/riwayat` (`views/RiwayatPegawaiPage.vue`) sampai MAKE-010 | WS-1 |
| `roles.ts` | Role menu/route per Matriks v2 | WS-2 |
| `types.ts` | Tipe = kolom DDL (pegawai, `riwayat_*`, `document_attachment` dengan kunci `NIP`), slug `{jenis}` (engine ∪ non-engine), `RiwayatTabDescriptor`, `StatusRiwayat` | bersama (aditif) |
| `riwayat/jenis/<slug>.ts` | Satu konfigurasi per jenis tab (kolom & isian = kolom DDL, lampiran per kode `jenis_rwy`) | WS-1 (kecuali `jabatan.ts`, `lkh.ts`: WS-2) |
| `riwayat/registry.ts` | Loader `import.meta.glob('./jenis/*.ts')`; tab dirender dari descriptor `GET pegawai/{nip}` → `tabs` | WS-1 |
| `riwayat/RiwayatTabHost.vue` | Props beku `nip`, `descriptor`, `config`; engine → `RiwayatListSection`, non-engine → "belum tersedia" | WS-1 |
| `riwayat/riwayat.config.ts`, `riwayat.schema.ts`, `riwayatCascade.ts`, `RiwayatFieldForm.vue` | Mesin form (M3, MAKE-006): isian ref berjenjang `dependsOn` lewat `useCascadeOptions`, isian bersyarat `showWhen` (tersembunyi = dikirim kosong), isian hitung `hint` + `resetOn` (dikosongkan saat isian pemicu diubah), isian `status` (+ `reason_note` wajib bila Ditolak) untuk role `pilihStatusSaatUbah`, Edit/Hapus baris Disetujui nonaktif untuk `kunciUbahDisetujuiRole`/`kunciHapusDisetujuiRole` (salinan Definisi), lampiran wajib saat tambah dan saat ubah bila baris belum punya lampiran kode itu | WS-1 |
| `riwayat/RiwayatLampiranDialog.vue` | Item ⋮ "Lampiran": satu `ArsipTable` per kode `jenis_rwy` jenis itu | WS-1 |
| `riwayat/KeluargaAnakDialog.vue`, `riwayat/anak.service.ts` | Item ⋮ "Anak" (config `kelolaAnak`): anak `detail_anak` lewat `…/keluarga/{id}/anak`, akte kode 3 per anak (MAKE-007) | WS-1 |
| `riwayat/UsulanMandiri.vue`, `UsulanFormDialog.vue`, `usulan.config.ts`, `usulan.service.ts` | Mode halaman usulan mandiri (MAKE-007, dipakai `views/UsulanKarpegKarisPage.vue`): pengusul 2/7 → `usulan/{jenis}/saya`, role 1/4/5 → antrean `usulan/{jenis}`; berkas kolom `berkas[file_*]`, unduh `…/{id}/berkas/{kolom}` | WS-1 |
| Opsi isian ref (MAKE-007) | `optionsParent` (induk tetap), `optionsFilter` (filter dari isian lain, dimuat ulang saat pemicu berubah), `extraOptions` (mis. LAIN-LAIN wilayah), teks `suggestEntity` (datalist saran master; prop aditif `list` di `UiTextField`), lampiran `wajibBila` | WS-1 |
| `riwayat/riwayat.service.ts` | Riwayat: `tabs(nip)` (`GET pegawai/{nip}/riwayat`, aditif MAKE-006); tambah multipart (`berkas[<id_riwayat>]`) / JSON tanpa berkas; ubah PUT JSON atau POST multipart + `_method=PUT`; hapus; process | WS-1 |
| `services/lampiran.service.ts` | Lampiran baris yang sudah ada: daftar, unggah, unduh, hapus | WS-2 |
| `components/ArsipTable.vue` | Tabel arsip satu baris riwayat (B-18, MAKE-009): props `nip`, `target` `{id_riwayat, id_entri}`, `aturan`, `canUpload`, `canDelete`, `title`; event `changed`. Menu ⋮ Unduh → Hapus (ConfirmDialog; disabled untuk lampiran terakhir kode `wajib`), testid `lampiran-actions-<id>` | WS-2 |
| `services/pegawai.service.ts` | `GET pegawai` (filter `search`/`status`/`id_unit`/`id_satker`/`id_jenis_pegawai`/`sort`), `GET pegawai/{nip}`, `PUT pegawai/{nip}/biodata` (dengan foto: POST multipart + `_method=PUT`), `GET …/foto` (blob), `POST …/koreksi-nip` (MAKE-010) | WS-2 |
| `dataUmum.config.ts`, `components/DataUmumForm.vue` | Form Data Umum B-03 (MAKE-010): isian per role salinan `ValidasiBiodata` backend, dirender lewat `RiwayatFieldForm` (dipakai, tidak diubah); foto = slot berkas 0 → berkas `foto`; Super Admin langsung, role lain "Ajukan Perubahan" | WS-2 |
| `components/KoreksiNipDialog.vue` | Koreksi NIP B-06 (MAKE-010, role 1): isi → tinjau (peringatan reset username/password + sesi dicabut) → konfirmasi; sukses → URL NIP baru | WS-2 |
| `services/apiErrors.ts` | 401 → 404 jenis → 403 izin/lingkup → 404 data; 422 `errors.<kolom>` / `errors["berkas.<id>"]` / `errors.berkas`; 501 stub | bersama |
| `views/DetailPegawaiPage.vue` | Tersambung `GET pegawai/{nip}` (MAKE-010): tab Data Umum (lihat + "Ubah Data Umum" bila `aksi.ubah_biodata`, status pengajuan dari `pengajuan`) + tab dari descriptor; foto profil dari `GET …/foto`; ⋯ "Koreksi NIP" bila `aksi.koreksi_nip` | WS-2 |
| `views/RiwayatPegawaiPage.vue` | **SEMENTARA sampai MAKE-010** (disetujui reviewer CR 12-10-2026): tab riwayat dari `GET pegawai/{nip}/riwayat` (descriptor sama dengan `data.tabs`), tanpa entri menu. Setelah MAKE-010 di `main`: dialihkan ke Detail Pegawai (tab yang sama) atau dihapus oleh WS-1 | WS-1 |

Tidak ada data contoh di runtime: fixture hanya di `__tests__/fixtures.ts` (dijaga `__tests__/noRuntimeMock.spec.ts`).

Kontrak engine (README backend, MAKE-004): string kosong = NULL di JSON/form/multipart; lampiran TIDAK ikut respons
riwayat (baca lewat `GET lampiran`). Ditetapkan MAKE-009: query `GET lampiran`
`id_riwayat` + `id_entri` wajib; unggah selalu menambah, "ganti" = unggah baru dulu, lalu hapus yang lama (lampiran
terakhir kode `wajib` tidak bisa dihapus, 422 `errors.berkas`). Aturan lampiran per
jenis (kode, wajib, batas MB, ekstensi) disalin dari Definisi backend oleh pemilik jenis (sudah: `pendidikan`, `kp`, `kgb`,
`hukdis`); jenis lain `lampiran: []` sampai Definisi-nya ada. Bentuk respons `GET pegawai`
ditetapkan MAKE-010 (kolom snapshot pmj + `pegawai_kp`, filter, paginasi server); endpoint Struktur Organisasi
ditetapkan WS-2 di B-19.
