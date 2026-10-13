# WS-2 — Pegawai Inti, Organisasi & Alur Khusus (Fase 3, Modul B) — Qoder-2

**Cara pakai:** baca berkas ini dari atas, kerjakan langkah §0 berurutan, berhenti di setiap **⛔ TITIK TUNGGU**
(ℹ️ = info koordinasi, tidak menghentikan kerja); rincian tiap milestone ada di §3 (PRD).

| | |
|---|---|
| Urutan | Dikerjakan **setelah Sprint 0 selesai**: `SPRINT0_1_backend.md` (MAKE-002) dan `SPRINT0_2_frontend.md` (MAKE-003) sudah di `main` |
| Pelaksana | Qoder-2 |
| Reviewer | Reviewer CR = sesi utama (review kode, memantau CI, memasukkan paket ke `main`). DB Validator hanya untuk perubahan skema (DBV) |
| Mitra | WS-1 / Qoder-1 — `docs/fase3/WS1.md` |
| Key | M1–M6 = **MAKE-009..MAKE-014** (peta lengkap §3.8.1) |
| Estimasi | ≈22,5 hari-agen setelah Sprint 0 (PRD: ≈24 termasuk S0-B) — relatif, bukan tanggal |
| Status | PRD disetujui user 07-10-2026 |

---

## §0 MULAI DI SINI — Runbook langkah demi langkah

Aturan kerja untuk setiap langkah: §2. Cara serah yang sama dipakai di setiap milestone, lihat **0.S**.

### 0.S Cara serah (dipakai di setiap milestone)

1. Pastikan gate cepat hijau (§2 butir 3) dan tidak ada berkas terlarang yang berubah:
   `git diff --stat origin/main...HEAD` (cocokkan dengan kepemilikan berkas §3.6).
2. Push **branch fitur** ke `origin` — **bukan** `main`: `git push -u origin <branch>`.
3. Tunggu workflow CI `quality-gate` untuk SHA itu sampai hijau (GitHub Actions; berjalan otomatis pada push branch,
   kecuali bila yang berubah hanya `*.md`/`docs/**`). CI = gate penuh resmi (AGENTS.md §3).
4. Lapor ke reviewer CR: nama branch, SHA (`git rev-parse HEAD`), key, ringkasan perubahan, tautan run CI untuk SHA
   itu, hasil gate cepat, keputusan desain yang Anda ambil, kebutuhan skema (bila ada — jangan buat migration), dan hal
   yang perlu diketahui Qoder-1.
5. Temuan review diperbaiki di branch yang sama; key perbaikan mengikuti arahan reviewer (AGENTS.md §2: `CR-###` untuk
   perbaikan hasil review/QA). Push ulang, ulangi butir 3–4.

### 0.A Gerbang mulai WS-2

- [ ] 1. **⛔ TITIK TUNGGU 1 — Sprint 0 selesai.** Cek:
  `git fetch origin && git log origin/main --oneline | grep -E "MAKE-002|MAKE-003"` — **keduanya** harus muncul. Bila
  belum, berhenti. Sejak titik ini kontrak beku (`SPRINT0_1_backend.md` §2.6, `SPRINT0_2_frontend.md` §2.6): perubahan
  hanya aditif dan hanya oleh pemiliknya.
- [ ] 2. Siapkan worktree untuk WS-2: pakai worktree Sprint 0-2 (`git fetch origin`, lalu branch baru dari
  `origin/main` per milestone) atau buat worktree baru. DB scratch `simpeg_v2_ws2_dev` / `simpeg_v2_ws2_testing`
  (collation `utf8mb4_unicode_ci`); `.env` sendiri dari `backend/.env.example`, **jangan menyalin `.env` dev** (§2).
- [ ] 3. Baca §1 (tabel tunggu) dan §3 (PRD), terutama §3.4.2, §3.6, §3.7.

### 0.B M1 — PegawaiScope + Lampiran B-18 nyata (MAKE-009, ±h1,5–5) — dibutuhkan WS-1

- [ ] 4. Branch `ws2/make-009-m1-scope-lampiran` dari `origin/main`:
  `git worktree add -b ws2/make-009-m1-scope-lampiran <folder> origin/main` (atau `git switch -c … origin/main`).
- [ ] 5. PegawaiScope nyata (§3.4.2 #2): UL_PEGAWAI hanya NIP sendiri; 4/5/8 sesuai matriks; role 3 = port
  `validateAccess_AP` atas pmj; lingkup unit destinasi 21 / unit lain 7; cast VARCHAR→INT sampai DBV-009. Ganti baris
  stub `pegawaiScope` di `Services.php` ke kelas nyata (catat di commit).
  ℹ️ **±h3:** beri tahu Qoder-1 bahwa Scope selesai di branch Anda; versi resmi masuk `main` bersama paket M1.
- [ ] 6. B-18 Lampiran (§3.4.2 #3): `LocalStorageAdapter`, `document_attachment`, batas per jenis 1/2/5 MB dari
  Definisi, simpan/hapus di transaksi pemanggil + kompensasi berkas, hapus keras + audit, helper kolom berkas, endpoint +
  `ArsipTable`. Ganti stub `attachmentService`/`storageAdapter` di `Services.php`.
- [ ] 7. DoD M1: test lingkup role 2/3/4/5/8 + UL_PEGAWAI hijau; simpan/hapus transaksional + kompensasi berkas hijau.
  Gate cepat hijau.
- [ ] 8. Commit `feat(kepegawaian): MAKE-009 …`; serah sesuai **0.S** (sebutkan bahwa WS-1 M2 menunggu paket ini).
  **⛔ TITIK TUNGGU 2 — review M1.** Tunggu review CR + CI hijau + merge MAKE-009. ℹ️ Setelah merge, beri tahu Qoder-1
  (Scope + Lampiran nyata tersedia di `main`). (Selama menunggu boleh menyiapkan M2 secara lokal, tanpa push.)

### 0.C M2 — B-06 Koreksi NIP + B-03 Biodata + backend dasar B-20 (MAKE-010, ±h5–9,5)

- [ ] 9. Branch `ws2/make-010-m2-nip-biodata` dari `origin/main`.
- [ ] 10. B-06 Koreksi NIP (§3.4.2 #4): `NipCascadeService` satu transaksi ikut `update_nip` legacy (+
  `jabatan_koordinasi.nip`, registry `TabelAnakNip` diperbarui), cek keunikan, rollback penuh, role 1, dialog FE. Test
  DoD B-06 + butir cascade B-21.
- [ ] 11. B-03 Biodata dua jalur + backend dasar B-20 (§3.4.2 #5): role 1 langsung, role 2/3/6/7 lewat `flag_update`
  + `pegawai_hist`; `pegawai_foto`; GET list/detail + `tabs` (descriptor dari `riwayatRegistry`); DataUmumForm
  tersambung API.
- [ ] 12. DoD M2: cascade + rollback penuh teruji; list/detail + descriptor tab tersambung. Gate cepat hijau
  (backend + `cd frontend && npm run check`).
- [ ] 13. Commit `feat(kepegawaian): MAKE-010 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 3 — review M2.** Tunggu review CR + CI hijau + merge MAKE-010. ℹ️ Setelah merge, beri tahu Qoder-1:
  detail pegawai live tersedia untuk tab WS-1 M3.

### 0.D M3 — B-04 Approval biodata + B-05 Tambah/Hapus pegawai (MAKE-011, ±h9,5–12,5)

- [ ] 14. Branch `ws2/make-011-m3-approval-tambah-hapus` dari `origin/main`.
- [ ] 15. B-04 Approval biodata (§3.4.2 #6): approve flag 3 + salin ke `pegawai` + sinkron `pengguna.email`; reject
  flag 2 + alasan wajib, `pegawai` tidak berubah; cek lingkup; `ApprovalDialog` dengan slot diff.
- [ ] 16. B-05 Tambah & Hapus (§3.4.2 #7): `AccountProvisioner`, hapus = status 10 + akun nonaktif, status 1→2
  menonaktifkan akun.
- [ ] 17. DoD M3: B-04/B-05 tersambung; regresi A-09 hijau. Gate cepat hijau.
- [ ] 18. Commit `feat(kepegawaian): MAKE-011 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 4 — review M3.** Tunggu review CR + CI hijau + merge MAKE-011.

### 0.E M4 — B-07 Jabatan/Mutasi + B-19 Struktur Organisasi (MAKE-012, ±h12,5–17,5)

- [ ] 19. **⛔ TITIK TUNGGU 5 — engine-v1 beku.** B-07 dibangun di atas engine WS-1. Cek:
  `git fetch origin && git log origin/main --oneline | grep MAKE-005`. Bila belum ada pada ±h12,5, lapor reviewer CR
  dan pakai **katup**: kerjakan M5 (Konket + LKH, langkah 25–29) lebih dulu, lalu kembali ke sini setelah MAKE-005 di
  `main`.
- [ ] 20. Branch `ws2/make-012-m4-jabatan-struktur` dari `origin/main`.
- [ ] 21. B-07 Jabatan/Mutasi + Plt/Plh (§3.4.2 #8): hanya `Definisi/Jabatan.php` + controller di atas engine-v1;
  aturan snapshot pmj (`jenis_jabatan≠3`, seri ≥); dropdown & salinan nama G-02; Plt/Plh tanpa approval; izin Matriks
  v2; tab FE `jabatan.ts`. Butuh hook baru? Ajukan ke Qoder-1 — **jangan** mengedit engine.
- [ ] 22. B-19 Struktur Organisasi (§3.4.2 #9): tree unit→satker→jabatan→pegawai (+Plt/Plh); halaman tersambung.
- [ ] 23. DoD M4: B-07 di atas engine-v1, snapshot pmj benar; B-19 tersambung. Gate cepat hijau.
- [ ] 24. Commit `feat(kepegawaian): MAKE-012 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 6 — review M4.** Tunggu review CR + CI hijau + merge MAKE-012. ℹ️ `riwayat_mutasi_jabatan` kini
  terisi lewat B-07 (dibaca B-15 AK di WS-1).

### 0.F M5 — B-13 Konket + B-12b LKH + PDF TCPDF (MAKE-013, ±h17,5–21,5)

- [ ] 25. Branch `ws2/make-013-m5-konket-lkh-pdf` dari `origin/main`.
- [ ] 26. B-13 Konket (§3.4.3): service tipis di luar engine (`absen_ijin` W/V/X/10, `affect_tukin`, `hari_libur`);
  halaman usulan mandiri, layout legacy, style redesign, ⋮; ganti placeholder Sprint 0-2.
- [ ] 27. B-12b LKH (§3.4.2 #11) + PDF (§3.4.4): approver atasan langsung; status 3 = Revisi; minimum 4 hari kerja;
  tambah `tecnickcom/tcpdf` ke `backend/composer.json` sesuai ADR-033, dibungkus antarmuka tipis; halaman Verifikasi
  LKH tersambung.
- [ ] 28. DoD M5: halaman Konket & Verifikasi LKH tersambung; PDF LKH memakai TCPDF. Gate cepat hijau.
- [ ] 29. Commit `feat(kepegawaian): MAKE-013 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 7 — review M5.** Tunggu review CR + CI hijau + merge MAKE-013.

### 0.G M6 — B-20 penutup (MAKE-014, ±h21,5–24)

- [ ] 30. Branch `ws2/make-014-m6-penutup` dari `origin/main`.
- [ ] 31. B-20 penutup (§3.4.2 #12): advanced search (role 1,3,4,5; panel filter disembunyikan untuk role 8), export,
  Cetak DRH PDF (TCPDF; role 1,2,3,4,5), integrasi semua tab, naikkan `ACTIVE_PHASE` ke 3 di `nav.config.ts`.
  ℹ️ DRH membaca tabel riwayat WS-1 (hanya baca). Integrasi tab paling lengkap setelah WS-1 M5 (MAKE-008) di `main`;
  cek `git log origin/main --oneline | grep MAKE-008` dan catat di laporan serah bila belum.
- [ ] 32. DoD M6: B-20 penutup tersambung, `ACTIVE_PHASE` = 3; perbarui baris WS-2 di
  `backend/docs/progress/03-Kepegawaian.md` (termasuk catatan deviasi arti Konket). Gate cepat hijau.
- [ ] 33. Commit `feat(kepegawaian): MAKE-014 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 8 — review M6 (akhir WS-2).** Tunggu review CR + CI hijau + merge MAKE-014. WS-2 selesai; QA
  Lapis 1, review independen, dan sesi QA di luar cakupan build.

---

## §1 Status prasyarat (tabel tunggu)

| No | Item | Key | Dikerjakan | Menunggu | Membuka jalan |
|---|---|---|---|---|---|
| 1 | Sprint 0-1 kontrak backend (`SPRINT0_1_backend.md`) | MAKE-002 | Qoder-1 | — | Sprint 0-2 |
| 2 | Sprint 0-2 fondasi frontend (`SPRINT0_2_frontend.md`) | MAKE-003 | Qoder-2 | MAKE-002 di `main` | Gerbang WS (⛔1) |
| 3 | Base case test + FK G-02 | MAKE-001, DBV-019 | Sesi utama (DBV-019 → review DB Validator) | — | Fixture/test ber-DB memakai base case dan patuh FK |
| 4 | WS-2 M1 Scope + Lampiran nyata | MAKE-009 | Qoder-2 (Scope ±h3, B-18 ±h5) | ⛔1 | DoD WS-1 M2 |
| 5 | WS-2 M2 → M3 | MAKE-010, MAKE-011 | Qoder-2 | milestone sebelumnya di `main` | Detail live untuk tab WS-1 M3 |
| 6 | Engine-v1 beku (WS-1 M2) | MAKE-005 | Qoder-1 (±h9,5) | MAKE-009 | WS-2 M4 B-07 (⛔5; katup: M5 lebih dulu) |
| 7 | WS-2 M4 → M5 → M6 | MAKE-012..014 | Qoder-2 | milestone sebelumnya di `main`; M4 juga ⛔5 | WS-2 selesai; `ACTIVE_PHASE` = 3 |

Istilah:
- **hari-agen** — ukuran beban kerja satu sesi coder (relatif), bukan tanggal kalender. "±h5" = sekitar hari-agen ke-5
  dihitung dari awal Sprint 0 (angka PRD). Karena Sprint 0 kini dikerjakan berurutan (0-1 lalu 0-2), semua angka ±h di
  berkas ini bergeser sekitar +1 hari-agen.
- **fixture** — data contoh untuk test (pegawai, akun, master G-02) yang dibuat oleh helper test, bukan data asli
  (`tests/_support/Kepegawaian/PegawaiFixtureTrait.php` dari Sprint 0-1).
- **kontrak beku** — setelah Sprint 0 masuk `main`, interface/API/route/registry bersama hanya boleh **ditambah**
  (aditif) oleh pemiliknya; perubahan non-aditif butuh persetujuan reviewer CR dan pemberitahuan ke WS lain.

---

## §2 Aturan kerja wajib (semua milestone WS-2)

1. **Worktree sendiri** dari `origin/main` (jangan bekerja di checkout milik orang lain). Setelah membuat worktree atau
   bila `composer.lock`/`package-lock.json` berubah: `cd backend && composer install`, `cd frontend && npm ci`.
2. **DB scratch sendiri**: `simpeg_v2_ws2_dev` dan `simpeg_v2_ws2_testing` (collation `utf8mb4_unicode_ci`). `.env`
   worktree sendiri dari `backend/.env.example`; **jangan menyalin `.env` dev**. Pastikan `database.default` dan
   `database.tests` menunjuk DB scratch sebelum `spark migrate` atau PHPUnit.
3. **Gate cepat** selama kerja (±5–10 menit): `cd backend && composer analyse && composer cs-check`; PHPUnit hanya
   berkas/folder yang disentuh dan area yang bersinggungan (mis. `vendor/bin/phpunit --no-coverage tests/Kepegawaian`);
   bila frontend berubah: `cd frontend && npm run check`.
4. **Gate penuh = CI** (AGENTS.md §3): push branch, tunggu workflow `quality-gate` hijau untuk SHA itu. Gate penuh lokal
   (`./check.sh`) tidak wajib; bila dijalankan, jalankan sebagai proses lepas dengan log ke berkas
   (`nohup ./check.sh > gate.log 2>&1 &`, atau `Start-Process` di PowerShell), satu gate penuh per mesin.
5. **Commit**: Bahasa Indonesia, gaya `feat(kepegawaian): MAKE-0NN …` / `test(kepegawaian): MAKE-0NN …` dengan key
   paket (§3.8.1). **Tanpa** trailer `Co-Authored-By` dan tanpa menyebut AI/assistant/tool apa pun di pesan commit,
   komentar, atau dokumen.
6. **Rahasia & repo publik**: jangan commit `.env`, kredensial, token, password, API key; jangan menulis IP/host
   internal, nama server, atau detail celah keamanan. Arsip remote legacy tidak disalin; hanya `LocalStorageAdapter`.
7. **Tidak push ke `main`.** Serahkan dengan push branch fitur `ws2/…` ke `origin` (0.S); reviewer CR yang memasukkan ke
   `main`. Paket berikut dimulai dari `origin/main` yang sudah memuat paket sebelumnya (rebase bila perlu).
8. **Tanpa migration.** Butuh perubahan skema? Catat di laporan serah; sesi utama mengalokasikan key DBV baru.
9. **UI**: aksi baris tabel lewat satu tombol ⋮ (`RowActionsMenu`), urutan Edit → ubah status → urutan → aksi lain
   (Setujui/Tolak) → Hapus (`danger: true`), aksi berisiko memakai `ConfirmDialog` (AGENTS.md §1).
10. Hanya mengedit berkas milik WS-2 (§3.6); engine dan Definisi selain Jabatan milik WS-1. Bila ada aturan di berkas
    ini yang bertentangan dengan AGENTS.md atau kode di `main`, **tanyakan reviewer CR** sebelum menyimpang.

---

## §3 PRD WS-2 — Pegawai Inti, Organisasi & Alur Khusus

| | |
|---|---|
| Status | **DISETUJUI 07-10-2026** — turunan dari usulan pembagian Fase 3; keputusan user dicatat di §3.10 |
| Estimasi | ≈24 hari-agen |
| Cakupan | Build saja (BE + FE + test yang diwajibkan DoD + gate). QA Lapis 1, review independen, dan sesi QA di luar cakupan |
| Basis & alur merge | Branch sendiri dari `main`; masuk `main` **per milestone** setelah review CR + CI hijau. Tidak ada branch integrasi/trunk |

### 3.1 Prasyarat & basis build

| # | Prasyarat | Status 07-10-2026 |
|---|---|---|
| P-1 | Fase 2 sign-off | **Selesai 07-10-2026** |
| P-2 | Skema B-01/B-02 (PR #20, DBV-012/013), G-02 (PR #17/#18), G-03 (PR #21), G-09 (PR #19) di `main` | **Selesai** — build berangkat dari `main`, bukan dari draf |
| P-3 | CR-044 — CI GitHub Actions | **Selesai** (di `main`); CI = gate penuh resmi |
| P-4 | MAKE-001 — PHPUnit cepat + base case test DB baru (migrate sekali + transaksi per test) | Dikerjakan sesi utama paralel. Fixture/test Fase 3 **wajib** memakai base case ini begitu masuk `main` |
| P-5 | DBV-019 — FK G-02 ↔ pegawai/riwayat (PR #23) | Dikerjakan sesi utama paralel. Fixture/test Fase 3 **wajib** mematuhi FK ini (baris master unit/satker/jabatan disediakan fixture) |

Alur: setiap WS bekerja di branch sendiri dari `main` dan rebase ke `main` saat paket milestone lain sudah masuk. Serah-terima antar-WS terjadi **lewat `main`**, bukan lewat branch integrasi.

---

### 3.2 Latar belakang & tujuan

Di luar pola riwayat standar, Modul B punya data inti pegawai dan alur dengan mesin status sendiri. WS-2 memegang fondasi yang dipakai bersama (lingkup akses, lampiran, fondasi frontend), data inti pegawai, organisasi, serta alur non-standar.

**Tujuan**
1. Fondasi bersama siap lebih dulu: PegawaiScope nyata (h3) dan Lampiran B-18 nyata (h5) untuk WS-1.
2. Koreksi NIP aman: cascade seluruh rujukan NIP Fase 3 dalam satu transaksi, rollback penuh.
3. Biodata dua jalur (langsung vs approval) dengan mesin `flag_update` sendiri.
4. Jabatan/Mutasi, Struktur Organisasi, Konket, LKH, dan halaman pegawai (daftar, detail, pencarian, export, DRH) tersambung API.

**Bukan tujuan**
- RiwayatEngine, SnapshotSync, dan jenis riwayat karier (milik WS-1).
- Antrean Butuh Approval (Modul F), `user_lokasi_presensi`, `aa_lkh`, adapter penyimpanan remote (Fase 6).

---

### 3.3 Pengguna & hak akses

| Role | Kebutuhan |
|---|---|
| 1 (admin) | Ubah biodata langsung, approve/reject biodata, tambah/hapus pegawai, koreksi NIP |
| 2/3/6/7 | Ubah biodata lewat approval (`flag_update`) |
| 3 | Admin terbatas lingkup unit/satker (`validateAccess_AP` atas `pegawai_mutasi_jabatan`) |
| 4/5/8 | Akses sesuai matriks; role 8 tanpa panel filter pencarian |
| UL_PEGAWAI | Hanya data NIP sendiri; mengajukan Konket dan LKH |
| Atasan (`nip_atasan`) | Approver LKH (ikut legacy) |

Hak akses mengikuti **Matriks v2** (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; keputusan #4), termasuk Jabatan tambah/hapus sesuai Matriks, **kecuali approver LKH = atasan langsung** (ikut legacy). Izin disimpan sebagai data di Definisi per jenis (Jabatan) atau di konfigurasi service masing-masing alur non-engine.

---

### 3.4 Kebutuhan fungsional

#### 3.4.1 Sprint 0 — fondasi frontend (S0-B, MAKE-003, ±h0–1,5)
Sudah dikerjakan lewat `docs/fase3/SPRINT0_2_frontend.md` (setelah kontrak backend `SPRINT0_1_backend.md` beku). Ringkasan:
1. Port **per berkas** dari commit `3420e1a` (branch `origin/feat/frontend-ui-redesign`) ke branch di atas `main`: `shared/layouts/*` dan `features/kepegawaian/**`; AppShell dipensiunkan, 6 pembungkus halaman dipindah. Merge langsung dilarang (3 commit bertrailer terlarang 9dd8b1e/265aba1/ad2fc91, banyak konflik).
2. Pecah `riwayat.config.ts`, `RIWAYAT_MENUS`, `riwayat.spec.ts` menjadi `riwayat/jenis/<key>.ts` dengan registry `import.meta.glob`; `DetailPegawaiPage` merender tab dari registry. **Tab Konket dan Karpeg/Karis tidak di-port** (keputusan #5).
3. `routes.pegawai.ts` (WS-2) dan `routes.riwayat.ts` (WS-1), diimpor sekali dari `router/index.ts`. Daftarkan semua menu Fase 3 di `nav.config`: Daftar Pegawai, Struktur, Konket, Usulan Karpeg/Karis, Verifikasi LKH — plus semua menu yang sudah ada di `main` (termasuk Web Config dan Lokasi Presensi dari PR #19/#21).
4. Samakan `types.ts` dengan kolom DDL PR #20.
5. `StatusBadge` dan `ApprovalDialog` di shared (alasan wajib saat tolak, slot diff untuk B-04). Item ⋮ "Setujui"/"Tolak" di grup aksi lain, sebelum "Hapus".
6. `useCascadeOptions` dan `masterService.options` di-re-export lewat `src/shared` (tidak dipindah).
7. Data contoh tidak masuk `main` sebelum tersambung API: berkas `*.mock.ts` tidak dipakai runtime, dan menu Fase 3 disembunyikan lewat `ACTIVE_PHASE` sampai B-20 penutup.

#### 3.4.2 Task berurutan
| # | Task | Isi | Est | ±Hari |
|---|---|---|---|---|
| 2 | **PegawaiScope nyata** → serah ke WS-1 | UL_PEGAWAI hanya NIP sendiri; 4/5/8 sesuai matriks; role 3 = port `validateAccess_AP` atas pmj (satker, atau unit bila admin tanpa satker); aturan lingkup unit destinasi 21 / unit lain 7 ikut legacy (keputusan #10); cast VARCHAR→INT sampai DBV-009 | 1,5 | 1,5–3 |
| 3 | **B-18 Lampiran** → serah ke WS-1 | `LocalStorageAdapter`; `document_attachment(NIP, id_riwayat, id_entri)` dengan kode dari `jenis_rwy`; batas ukuran/ekstensi **per jenis 1/2/5 MB ikut legacy** dari Definisi (keputusan #6); simpan/hapus di transaksi pemanggil (kompensasi berkas saat rollback); hapus keras + audit (DBV #9); helper kolom berkas; endpoint + `ArsipTable` | 2 | 3–5 |
| 4 | **B-06 Koreksi NIP** (+ butir B-21 cascade) | `NipCascadeService` 1 transaksi ikut `update_nip` legacy (keputusan #10): salin `pegawai` → arahkan ulang `TabelAnakNip::sampaiFase(3)` + `jabatan_koordinasi.nip` (registry CR-036 diperbarui agar memuatnya) + `pengguna` + `faq_rate` → hapus baris lama; cek keunikan; rollback penuh; role 1; dialog FE | 2 | 5–7 |
| 5 | **B-03 Biodata dua jalur** + backend dasar B-20 | Role 1 langsung ke `pegawai`; role 2/3/6/7 → `flag_update=1` + satu `pegawai_hist` pending per NIP; `pegawai_foto`; role 3 di luar lingkup → 403. GET list/detail + descriptor tab; DataUmumForm tersambung API | 2,5 | 7–9,5 |
| 6 | B-04 Approval biodata | Approve: flag 3, salin ke `pegawai`, sinkron `pengguna.email`. Reject: flag 2, alasan wajib, `pegawai` tidak berubah (bug legacy `L_employee.php:1098` tidak ditiru). Cek lingkup; dialog diff | 1,5 | 9,5–11 |
| 7 | B-05 Tambah & Hapus pegawai | `AccountProvisioner` (UserLevel dari `id_jenis_pegawai`, jaga regresi A-09); hapus = status 10 + akun nonaktif; status 1→2 menonaktifkan akun; `pegawai_sisa_cuti` dicatat untuk C-01 | 1,5 | 11–12,5 |
| 8 | **B-07 Jabatan/Mutasi + Plt/Plh** | `Definisi/Jabatan.php` di atas **engine-v1** + aturan snapshot pmj (`jenis_jabatan≠3`, seri ≥); dropdown & salinan nama G-02 (termasuk `jabatan_koordinasi`/`rumpun_jabatan`); Plt/Plh tanpa approval; izin sesuai Matriks v2; tab FE. Hanya menulis Definisi + controller; hook baru diajukan ke WS-1 | 3 | 12,5–15,5 |
| 9 | B-19 Struktur Organisasi | Tree unit→satker→jabatan→pegawai (+Plt/Plh); `so/full` = sesi login; `jabatan/so` = UL_ALL; halaman tersambung | 2 | 15,5–17,5 |
| 10 | B-13 Konket *(katup)* | Lihat §3.4.3 | 1,5 | 17,5–19 |
| 11 | B-12b LKH | Approver atasan langsung (`nip_atasan`, ikut legacy — pengecualian keputusan #4); status 3 = Revisi; tanggal minimum 4 hari kerja (HariLiburService); unit/satker/atasan dari pmj; PDF dengan **TCPDF** (keputusan #7, ADR-033); `aa_lkh` ditunda (DBV #13) | 2,5 | 19–21,5 |
| 12 | B-20 penutup | Advanced search (role 1,3,4,5; panel filter disembunyikan untuk 8), export, Cetak DRH PDF (1,2,3,4,5; TCPDF), integrasi semua tab; naikkan `ACTIVE_PHASE` ke 3 | 2,5 | 21,5–24 |

#### 3.4.3 Halaman Usulan Konket (keputusan #5, 05-10)
- Service tipis **di luar engine**: `absen_ijin` status W/V/X/10, `affect_tukin` (dependency Fase 5), `hari_libur`.
- **Halaman sendiri**, bukan tab di Detail Pegawai.
- **Layout ikut legacy** (`Konket.php`): index untuk UL_PEGAWAI, daftar/antrian `list_ad`, alur proses; izin per aksi mengikuti Matriks v2 (keputusan #4).
- **Style ikut redesign**: token + komponen shared, aksi baris lewat ⋮.
- Catatan: `docs/fase3/03-Kepegawaian.md` menyebut B-13 "Kondisi Kerja: Dinas Luar/WFH/WFO"; build mengikuti arti legacy (`absen_ijin`), deviasi dicatat di progres.

#### 3.4.4 Library PDF (keputusan #7)
- **TCPDF** (mesin yang dipakai legacy, `PdfCreator extends TCPDF`), ditambahkan ke `composer.json` oleh WS-2 dengan **ADR-033** (`docs/adr/ADR-033-library-pdf-tcpdf.md`) (diterima 07-10-2026) yang mencatat lisensi LGPL-3.0-or-later dan dasar pemakaiannya.
- Cadangan: **dompdf** bila TCPDF bermasalah; antarmuka pembangkit PDF dibuat tipis agar penggantian tidak menyentuh LKH/DRH.

---

### 3.5 Kebutuhan non-fungsional
- Koreksi NIP, biodata, tambah/hapus pegawai, dan lampiran: **satu transaksi**, rollback penuh (termasuk kompensasi berkas).
- Lingkup akses diperiksa di setiap endpoint; di luar lingkup → 403.
- Audit untuk semua perubahan data dan approval.
- Arsip remote legacy tidak disalin (repo publik); hanya `LocalStorageAdapter`.
- Kualitas: gate lolos (CI hijau). Test wajib DoD (bagian dari build, keputusan #9): B-06 dan butir cascade B-21.
- Test ber-DB memakai base case MAKE-001 (bila sudah di `main`) dan fixture yang mematuhi FK DBV-019.

---

### 3.6 Kepemilikan berkas

| Milik WS-2 | Tidak boleh disentuh WS-2 |
|---|---|
| `RoutesPegawai.php`, `PegawaiScope`, `Attachment*`/`StorageAdapter`, `NipCascadeService`, `TabelAnakNip`, `composer.json` (termasuk library PDF), Auth, `Definisi/Jabatan.php`, layanan biodata/struktur/LKH/Konket, `routes.pegawai.ts`, `router/index.ts`, `nav.config.ts`, `DetailPegawaiPage`, `StatusBadge`, `ApprovalDialog`, halaman Daftar Pegawai/Struktur/Konket/LKH | Engine, SnapshotSync, `BaseSnapshotModel`, Definisi selain Jabatan, `RoutesRiwayat.php`, `routes.riwayat.ts`, `Routes.php`/`Services.php` (setelah S0), **semua migration yang sudah ada di `main`**, `_support` milik PR #20 (`LepasMigrationKepegawaianTrait.php`, `SkemaKepegawaianTestTrait.php`, `Kepegawaian/SkemaD1*.php`), berkas base case MAKE-001 |

---

### 3.7 Dependency

| WS-2 butuh | Dari | Butuh / siap | Cara melepas |
|---|---|---|---|
| Interface Scope/Lampiran + fixture | Sprint 0-1 (MAKE-002) | S0 / S0 | Kontrak S0 di `main` (⛔1) |
| Descriptor tab | WS-1 | h7 / S0 (stub) | Kontrak beku; tab muncul otomatis saat WS-1 mendaftarkan jenis |
| **engine-v1 beku** (B-07) | WS-1 M2 (MAKE-005) | **h12,5 / h9,5** | Slack 3 hari; fallback tukar B-07 dengan Konket/LKH (⛔5) |
| Tabel riwayat WS-1 (DRH) | WS-1 | h21,5 / `main` | Hanya baca |
| Snapshot pmj | `main` | h1,5 | Fixture S0-A |
| Base case test (MAKE-001), FK DBV-019 | Sesi utama | S0 | Fixture hanya-INSERT; rebase saat masuk `main` |

| WS-1 butuh dari WS-2 | Siap |
|---|---|
| PegawaiScope nyata | h3 (masuk `main` bersama paket WS-2 M1) |
| B-18 Lampiran nyata + helper kolom berkas | h5 (paket WS-2 M1) |
| Port FE + router/nav + detail B-03 | h1,5 (S0-B) / h9,5 (WS-2 M2) |

**Eksternal:** G-02 (#17/#18), G-03 (#21), G-09 (#19) — semuanya sudah di `main`; DBV-009 (tipe `id_satker`, cast sementara); DBV-019 (PR #23, FK G-02↔pegawai); library PDF (ADR-033 sudah diterima: TCPDF).

---

### 3.8 Milestone & kriteria selesai
| Milestone | Key | ±Hari | Kriteria |
|---|---|---|---|
| S0-B fondasi FE (`SPRINT0_2_frontend.md`) | MAKE-003 | h1,5 | Port per berkas, registry tab, route/nav, StatusBadge/ApprovalDialog di `main` tanpa data contoh runtime |
| M1 Scope + Lampiran nyata | MAKE-009 | h5 | Test lingkup role 2/3/4/5/8 + UL_PEGAWAI hijau; simpan/hapus transaksional + kompensasi berkas hijau; masuk `main` untuk WS-1 |
| M2 Koreksi NIP + detail pegawai live | MAKE-010 | h9,5 | Cascade + rollback penuh teruji (B-21); list/detail + descriptor tab tersambung |
| M3 Approval biodata + tambah/hapus | MAKE-011 | h12,5 | B-04/B-05 tersambung; regresi A-09 hijau |
| M4 Jabatan + Struktur live | MAKE-012 | h17,5 | B-07 di atas engine-v1, snapshot pmj benar; B-19 tersambung |
| M5 Konket + LKH | MAKE-013 | h21,5 | Halaman Konket & Verifikasi LKH tersambung; ADR-033 (TCPDF) disetujui |
| M6 Selesai | MAKE-014 | h24 | B-20 penutup tersambung, `ACTIVE_PHASE` = 3; CI hijau |

#### 3.8.1 Peta key Fase 3 (keputusan #11: satu key per paket milestone per WS)

Key yang sudah terpakai sebelum Fase 3: CR-044 (CI), MAKE-001 (PHPUnit cepat + MySQL test), DBV-019 (FK G-02↔pegawai, PR #23).

| Paket | WS | Isi | Key |
|---|---|---|---|
| S0-A | WS-1 | Kontrak backend | MAKE-002 |
| S0-B | WS-2 | Fondasi frontend | MAKE-003 |
| WS-1 M1 | WS-1 | SnapshotSync + RiwayatEngine BE | MAKE-004 |
| WS-1 M2 | WS-1 | B-10 + B-08 + freeze engine-v1 | MAKE-005 |
| WS-1 M3 | WS-1 | FE mesin riwayat + B-09 + B-14 | MAKE-006 |
| WS-1 M4 | WS-1 | B-16 + B-11 + B-17 (+ halaman Karpeg/Karis) | MAKE-007 |
| WS-1 M5 | WS-1 | B-12a SKP + B-15 AK + B-21 butir snapshot | MAKE-008 |
| WS-2 M1 | WS-2 | PegawaiScope + B-18 Lampiran | MAKE-009 |
| WS-2 M2 | WS-2 | B-06 Koreksi NIP + B-03 Biodata (+ backend dasar B-20) | MAKE-010 |
| WS-2 M3 | WS-2 | B-04 Approval biodata + B-05 Tambah/Hapus | MAKE-011 |
| WS-2 M4 | WS-2 | B-07 Jabatan + B-19 Struktur | MAKE-012 |
| WS-2 M5 | WS-2 | B-13 Konket + B-12b LKH + ADR-033 PDF (TCPDF) | MAKE-013 |
| WS-2 M6 | WS-2 | B-20 penutup | MAKE-014 |

`MAKE-###` untuk pekerjaan baru; `CR-###` **hanya** untuk perbaikan hasil review/QA (AGENTS.md §2) — nomornya ditetapkan reviewer CR. Bila katup dipakai (mis. Konket pindah ke WS-1), task ikut key paket tujuan. Kebutuhan skema baru di luar build memakai key DBV baru (DBV-020 dst.) yang dialokasikan sesi utama.

---

### 3.9 Risiko
| Risiko | Mitigasi |
|---|---|
| G-02 perlu koreksi | Sudah di `main`; koreksi lewat migration baru (DBV baru); hanya paruh kedua WS-2 terdampak; katup Konket/LKH |
| engine-v1 terlambat | Tukar B-07 dengan Konket/LKH (+±4 hari slack) |
| WS-2 tertinggal | Katup: Konket pindah ke WS-1; DRH digeser paling akhir |
| TCPDF bermasalah saat port template | ADR-033 sudah diputus; cadangan dompdf di balik antarmuka tipis; LKH/DRH di ekor |
| Kontrak beda dengan skema/legacy (`status 0` vs `flag_update`, `id_parent` vs `id_entri`+`id_riwayat`, arti Konket) | Build ikut skema/legacy; deviasi dicatat di progres |
| Port FE bentrok dengan halaman yang berubah di `main` sejak redesign (Web Config, Lokasi Presensi, master) | Port per berkas hanya `shared/layouts/*` + `features/kepegawaian/**`; versi `main` menang untuk berkas lain; route file terpisah |
| Antrian merge per milestone menunda serah-terima Scope/B-18 | Paket M1 diserahkan begitu siap; CI = gate penuh resmi, gate penuh lokal tidak diulang (AGENTS.md §3); MAKE-001 mempercepat PHPUnit |

---

### 3.10 Keputusan (disetujui user 07-10-2026)

| # | Keputusan | Dampak ke WS-2 |
|---|---|---|
| 1 | Gugur: build berangkat dari `main` (PR #20 & G-02 sudah di `main`, Fase 2 sign-off 07-10) | Tidak ada build di atas draf |
| 2 | Pembagian WS-1/WS-2 sesuai PRD: B-18 di WS-2, B-15 di WS-1, B-12 dipecah (SKP WS-1, LKH WS-2), B-20 dipecah (dasar di B-03, penutup di akhir) | Cakupan §3.4 |
| 3 | Tanpa branch integrasi: tiap WS branch sendiri dari `main`, masuk `main` per milestone setelah review CR + CI hijau | §3.1, §3.8 |
| 4 | Hak akses ikut Matriks v2 (Hukdis 1/3, Jabatan & AK sesuai Matriks, Karpeg/Karis 1,2,4,5,7 sesuai DoD), **kecuali approver LKH = atasan langsung** (legacy). Izin disimpan sebagai data | §3.3, B-07, LKH |
| 5 | (05-10) Konket & Karpeg/Karis = halaman usulan mandiri, layout legacy, style redesign | §3.4.3; tab tidak di-port |
| 6 | Batas lampiran per jenis 1/2/5 MB ikut legacy | B-18 |
| 7 | PDF: TCPDF via ADR-033 (`docs/adr/ADR-033-library-pdf-tcpdf.md`; lisensi LGPL-3.0, sama dengan legacy); fallback dompdf | §3.4.4 |
| 8 | Status 3 "Diproses" di belakang flag, nonaktif default | Engine (WS-1); LKH memakai status 3 = Revisi sesuai legacy |
| 9 | Unit/feature test yang diwajibkan DoD (B-06/08/09/14, B-21) + gate = bagian build; QA Lapis 1/review/sesi QA tidak | §3.5 |
| 10 | Default ikut legacy: acuan KGB = KP/KGB terakhir; cascade NIP ikut `update_nip` legacy + `jabatan_koordinasi.nip` (TabelAnakNip CR-036 memuatnya); masa hukdis = `masa_sanksi_bulan`; lingkup unit destinasi 21 / unit lain 7 ikut legacy | B-06, PegawaiScope |
| 11 | Satu key per paket milestone per WS; key WS mulai MAKE-002 | §3.8.1 |

---

## Lampiran — rujukan

| Dokumen | Isi |
|---|---|
| `docs/fase3/README.md` | Urutan perintah Fase 3, tabel tunggu ringkas, reviewer |
| `docs/fase3/SPRINT0_1_backend.md` | Sprint 0-1 (kontrak backend beku: interface, descriptor, slug, API, fixture) |
| `docs/fase3/SPRINT0_2_frontend.md` | Sprint 0-2 (fondasi frontend beku: registry tab, `RiwayatTabHost`, shell, route/nav) |
| `docs/fase3/WS1.md` | Runbook + PRD mitra (Qoder-1) |
| `docs/fase3/MATRIKS_ROLE_MODUL_B.md` | Matriks role × endpoint Modul B (hak akses, keputusan #4) |
| `docs/fase3/03-Kepegawaian.md` | Kontrak task Fase 3 (B-01..B-21, DoD per task) |
| `docs/adr/ADR-033-library-pdf-tcpdf.md` | Library PDF TCPDF (dipakai di M5/M6) |
| `AGENTS.md` | Aturan proyek: menu ⋮ (§1), commit/key/rahasia/merge (§2), gate cepat & CI (§3) |
| `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md` | Skema B-01/B-02 (kolom DDL, aturan snapshot §5.1) |
| `backend/docs/progress/03-Kepegawaian.md` | Progres per task (tiap WS hanya mengubah barisnya sendiri) |
| `backend/app/Libraries/Kepegawaian/README.md` | Library Kepegawaian yang sudah ada (Kalkulasi, Nip — `TabelAnakNip` untuk B-06) |
