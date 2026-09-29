# QAFUNC-002-R3: QA ulang Fase 1 Auth setelah DBV-010 — QASMTASK-024, 027, 028, 029

| | |
|---|---|
| **Jenis** | QA Functional ulang (black-box API + DB + UI), dijalankan reviewer CR atas persetujuan user |
| **Tanggal eksekusi** | 29 September 2026 |
| **Commit yang diuji** | `e602205` (`main`; DBV-010/CR-013 masuk lewat PR #15, merge `6bb29b5`) |
| **Pemicu** | Identitas akun pindah dari NIP ke `id_pengguna` (JWT `sub`, tabel `token`, pelaku audit), akun non-pegawai tanpa NIP, collation `utf8mb4_unicode_ci`, strict mode |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 Laragon, `php spark serve` (8 instance port 8131–8138 untuk uji balapan; API 8089 + Vite 5173 untuk UI), DB scratch hasil `migrate --all` + `MasterDataSeeder`, captcha `mock`; akun dari seeder test |
| **Rujukan** | Kartu QASMTASK-024/027/028/029 (DoD), MTC-003/004/005, `backend/docs/db-review/A-01-auth-schema.md` Bagian 9, README Auth |
| **Hasil** | **Pass setelah perbaikan.** Semua butir DoD PASS. 1 FAIL major (027-S1: Admin Satker bisa mengambil alih akun Super Admin satu satker) diperbaiki di **CR-017** (`f862583`); 2 FAIL minor dicatat ke **ISSUE-023**. |

## Ringkasan per DoD

| Kartu | DoD | Hasil | Bukti utama |
|---|---|---|---|
| 024 (A-05) | Refresh otomatis sebelum/sesudah access token kedaluwarsa | PASS | Interceptor asli `frontend/src/lib/axios.ts` dijalankan terhadap backend dengan `accessTtl` 4 detik: 401 → satu kali `POST /auth/refresh` (single-flight untuk 3 request serentak) → retry 200. Mekanismenya reaktif (setelah 401), sesuai ADR-022 dan keputusan R1 |
| 024 | Reuse refresh token → ditolak & seluruh sesi akun ter-invalidate | PASS | Per `id_pengguna`: sesi 4 → 0 (ber-NIP) dan 3 → 0 (tanpa NIP); akun lain tanpa NIP tetap bisa refresh (reuse tidak lagi mencabut semua akun ber-NIP NULL) |
| 024 | Logout menghapus refresh token dari DB | PASS | Baris token → 0, cookie dihapus, replay → 401 "tidak dikenal"; perangkat lain tetap aktif; audit logout ber-actor (NULL `nip_actor` untuk akun tanpa NIP) |
| 024 | Sesuai MTC-003 poin 3 | PASS | Login ke-2 lewat Argon2id, MD5 lama tidak dibaca lagi |
| 027 (A-08) | Role 1 CRUD semua akun | PASS | list/show/create/update/status/delete; total daftar = COUNT DB |
| 027 | Role 3 tidak bisa akses/edit akun di luar satkernya → 403 | PASS | GET/PUT/PATCH/DELETE akun S02 → 403, `?id_satker=S02` diabaikan |
| 027 | Role lain → 403 | PASS | Role 2/4/5/6/7/8 × 6 operasi = 36/36 403, data tidak berubah |
| 027 | Akun baru langsung bisa login; akun nonaktif tidak bisa | PASS | termasuk akun tanpa NIP (login username) |
| 027 | Lolos MTC-004 dan MTC-005 | PASS | API + UI (lihat bagian UI) |
| 028 (A-09) | Pegawai baru → akun auto-generate username = NIP | PASS | `AccountProvisioner::provisionForPegawai` lewat CLI bootstrap CI4 |
| 028 | AccountProvisioner siap dipanggil Modul B | INFO | Bentuk API service dicatat; pemanggil nyata baru ada di B-01 |
| 028 | Regression lintas modul akhir Fase 3 (B-05) | SKIP | Belum waktunya |
| 029 (A-10) | Login, logout, ganti password, perubahan akun tercatat; actor & timestamp benar | PASS (5/5) | `id_pengguna_actor` + `nip_actor` benar untuk akun ber-NIP dan tanpa NIP; rekap 529 baris: actor NULL hanya 4 baris create oleh AccountProvisioner lewat CLI (proses sistem) |

## Regresi DBV-010 yang ikut diuji

| Area | Hasil |
|---|---|
| JWT `sub` = `id_pengguna` (string), `nip` null untuk akun tanpa NIP, `ver` = 2; token format lama dan 10 varian tidak sah → 401 | PASS |
| Baris refresh ber-claims format lama → 401 "tidak dikenal" (bukan reuse) | PASS |
| T-01: 8 jalur pencabutan sesi (ganti/reset password, admin ubah role/password/satker/status/tautkan NIP, hapus) + kontrol negatif | PASS |
| Balapan refresh token sama: 40/40 percobaan (8 instance HTTP ×20 + 8 proses CLI ×20) tepat 1×200 + 7×401 reuse | PASS |
| Aturan akun non-pegawai (NIP wajib 2/6/7, opsional 1/3/4/5/8 + nama wajib, NIP ≤ 18 digit, email, username ≤ 100 tanpa karakter tak terlihat, unik unicode_ci, tautkan NIP, balapan UNIQUE → 422, rename membatalkan token reset) | PASS |
| Login/lupa password dengan username bebas, tautan `#token=`, lockout, K4, CR-007 encoding | PASS |

## QA UI (browser, desktop pane)

| # | Langkah | Hasil |
|---|---|---|
| U-1 | Super Admin → Manajemen Akun | Kolom Username \| Nama \| NIP \| Role \| Status \| Login terakhir \| Satker \| Aksi; satu tombol ⋮ per baris (`Aksi untuk <username>`, testid `user-actions-<id>`) |
| U-2 | Tambah akun role 1 tanpa NIP | Label NIP tanpa `*` untuk role 1/3, `NIP *` untuk role 2/6; akun tersimpan, kolom NIP "—", menu ⋮: Edit, Nonaktifkan, Hapus (merah) |
| U-3 | Admin Satker S01 → menu | Beranda, Manajemen Akun, FAQ, Ganti Password (tanpa Master Data & Hari Libur, sesuai matriks) |
| U-4 | Admin Satker → daftar & form | Hanya akun S01; pilihan role 2/6/7; Satker read-only S01; baris sendiri: Nonaktifkan & Hapus disabled |
| U-5 | Admin Satker → baris Super Admin S01 | **FAIL (027-S1)**: menu Edit/Nonaktifkan/Hapus aktif; menonaktifkan dan mengganti password akun Super Admin uji berhasil |

## Temuan

| ID | Tingkat | Temuan | Tindak lanjut |
|---|---|---|---|
| 027-S1 | **Major** | Admin Satker bisa membaca, mengubah (termasuk menetapkan password lalu login sebagai role 1), menurunkan role, menonaktifkan, dan menghapus akun role 1/3/4/5/8 lain di satkernya. `UserService::findInScope()` hanya membandingkan `id_satker`. Legacy `User::edit/delete` menolak target selain level pegawai (dan akun sendiri). Dikonfirmasi di API (diverifikasi ulang dari nol) dan UI | **Diperbaiki CR-017** (`f862583`): Admin Satker hanya melihat/mengelola akun 2/6/7 + akunnya sendiri di satkernya; lainnya 403 dan tidak muncul di daftar. Test `UserCrudScopedTest::testAdminSatkerCannotManageNonPegawaiAccountsInOwnSatker` (gagal tanpa perbaikan, lolos dengan perbaikan) |
| 027-R10b | Minor | Isian `status`/`id_unit`/`id_satker` berbentuk array di POST/PUT `/auth/users` → 500 ("Array to string conversion" / SQL syntax), bukan 422 | ISSUE-023 |
| 028-X1 | Minor | `AccountProvisioner` → 500 (1062 `pengguna.username`) bila NIP pegawai baru sama dengan username bebas akun non-pegawai lain (kasus baru sejak username bebas DBV-010); akun pegawai tidak tercipta tanpa pesan domain | ISSUE-023 (perlu keputusan perilaku sebelum B-01) |
| INFO | — | Refresh token reaktif (setelah 401), bukan timer proaktif — sesuai ADR-022; login hanya lewat username (akun ber-username bebas tidak bisa login pakai NIP, sama dengan legacy); access token lama tetap berlaku sampai `exp` (JWT stateless, ADR-004) | — |

## Bersih-bersih

Server QA dihentikan; DB scratch QA di-drop dengan nama persis; worktree QA tidak mengubah kode yang diuji. Akun uji dibuat hanya di DB scratch.
