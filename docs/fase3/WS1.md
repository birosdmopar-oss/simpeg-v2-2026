# WS-1 — Mesin Riwayat & Riwayat Karier/Administrasi (Fase 3, Modul B) — Qoder-1

**Cara pakai:** baca berkas ini dari atas, kerjakan langkah §0 berurutan, berhenti di setiap **⛔ TITIK TUNGGU**
(ℹ️ = info koordinasi, tidak menghentikan kerja); rincian tiap milestone ada di §3 (PRD).

| | |
|---|---|
| Urutan | Dikerjakan **setelah Sprint 0 selesai**: `SPRINT0_1_backend.md` (MAKE-002) dan `SPRINT0_2_frontend.md` (MAKE-003) sudah di `main` |
| Pelaksana | Qoder-1 |
| Reviewer | Reviewer CR = sesi utama (review kode, memantau CI, memasukkan paket ke `main`). DB Validator hanya untuk perubahan skema (DBV) |
| Mitra | WS-2 / Qoder-2 — `docs/fase3/WS2.md` |
| Key | M1–M5 = **MAKE-004..MAKE-008** (peta lengkap §3.8.1) |
| Estimasi | ≈23,5 hari-agen setelah Sprint 0 (PRD: ≈24,5 termasuk S0-A) — relatif, bukan tanggal |
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
   yang perlu diketahui Qoder-2.
5. Temuan review diperbaiki di branch yang sama; key perbaikan mengikuti arahan reviewer (AGENTS.md §2: `CR-###` untuk
   perbaikan hasil review/QA). Push ulang, ulangi butir 3–4.

### 0.A Gerbang mulai WS-1

- [ ] 1. **⛔ TITIK TUNGGU 1 — Sprint 0 selesai.** Cek:
  `git fetch origin && git log origin/main --oneline | grep -E "MAKE-002|MAKE-003"` — **keduanya** harus muncul. Bila
  belum, berhenti. Sejak titik ini kontrak beku (`SPRINT0_1_backend.md` §2.6, `SPRINT0_2_frontend.md` §2.6): perubahan
  hanya aditif dan hanya oleh pemiliknya.
- [ ] 2. Siapkan worktree untuk WS-1: pakai worktree Sprint 0-1 (`git fetch origin`, lalu branch baru dari
  `origin/main` per milestone) atau buat worktree baru. DB scratch tetap `simpeg_v2_ws1_dev` / `simpeg_v2_ws1_testing`;
  `.env` sendiri, **jangan menyalin `.env` dev** (§2).
- [ ] 3. Baca §1 (tabel tunggu) dan §3 (PRD), terutama §3.4, §3.6, §3.7.

### 0.B M1 — SnapshotSync + RiwayatEngine BE (MAKE-004, ±h1–6,5)

- [ ] 4. Branch baru dari `main` terbaru: `git worktree add -b ws1/make-004-m1-engine-be <folder> origin/main`
  (atau `git switch -c ws1/make-004-m1-engine-be origin/main` di worktree WS-1 setelah `git fetch`). Pasang ulang dependensi bila `composer.lock`/
  `package-lock.json` berubah.
- [ ] 5. SnapshotSync (§3.4.2): pemilih baris status 1 sebagai fungsi murni, multi-target, DELETE bila kosong, di
  transaksi pemanggil + audit, dipicu di setiap transisi masuk/keluar status 1.
- [ ] 6. RiwayatEngine BE (§3.4.3): model generik, service list/get/create/update/hapus/process, status awal 0/1,
  guard baris disetujui, `show_ua_*`, notifikasi, BaseRiwayatController, loop route di `RoutesRiwayat.php`, trait test
  B-TC generik. Ganti baris `new Stub…` → kelas nyata di `Services.php` hanya untuk service milik WS-1
  (`riwayatRegistry`, `riwayatService`, `snapshotSync`) dan catat di commit.
  ℹ️ PegawaiScope dan Lampiran nyata disiapkan WS-2 (Scope ±h3, B-18 ±h5, keduanya masuk `main` sebagai MAKE-009).
  M1 cukup memakai fake dari S0 — tidak perlu menunggu.
- [ ] 7. DoD M1: test unit pemilih murni + multi-target hijau; alur draft → approve/reject → snapshot → audit →
  lingkup hijau dengan fake. Gate cepat hijau.
- [ ] 8. Commit `feat(kepegawaian): MAKE-004 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 2 — review M1.** Tunggu review CR + CI hijau + merge MAKE-004. (Selama menunggu boleh menyiapkan M2
  secara lokal di branch baru dari branch M1, tanpa push; rebase ke `origin/main` setelah M1 masuk.)

### 0.C M2 — Pilot B-10 + B-08, FREEZE engine-v1 (MAKE-005, ±h6,5–9,5) — jalur kritis

- [ ] 9. Branch `ws1/make-005-m2-pilot-freeze` dari `origin/main` (yang sudah memuat MAKE-004).
- [ ] 10. B-10 Pendidikan (pilot, §3.4.4): self-service role 1,2,3,6,7; snapshot jenjang tertinggi; status INT NULL;
  Definisi `pendidikan`.
- [ ] 11. B-08 KP (§3.4.4): multi-target kp/cpns/pns; `KenaikanPangkat::validasiTmt` (CR-025); test DoD B-08.
- [ ] 12. **⛔ TITIK TUNGGU 3 — Scope & Lampiran nyata.** DoD M2 mensyaratkan B-10 + B-08 lolos dengan PegawaiScope
  dan lampiran **nyata**. Cek: `git fetch origin && git log origin/main --oneline | grep MAKE-009`. Bila belum ada,
  kerjakan dengan fake dulu, lalu rebase dan jalankan ulang test B-10/B-08 setelah MAKE-009 masuk.
- [ ] 13. Gate cepat hijau; commit `feat(kepegawaian): MAKE-005 …`; serah sesuai **0.S** dan sebutkan "freeze
  engine-v1" di laporan.
  **⛔ TITIK TUNGGU 4 — review M2 / freeze.** Tunggu review CR + CI hijau + merge MAKE-005.
- [ ] 14. ℹ️ **Engine-v1 BEKU (target ±h9,5).** Setelah MAKE-005 masuk `main`, beri tahu Qoder-2: B-07 Jabatan di WS-2
  boleh mulai. Sejak titik ini perubahan engine hanya **aditif** dan hanya oleh WS-1; permintaan hook dari WS-2 diterima
  sebagai usulan, dikerjakan WS-1. Bila freeze diperkirakan lewat ±h12,5, lapor reviewer CR (katup: WS-2 menukar B-07
  dengan Konket/LKH, §3.9).

### 0.D M3 — FE mesin riwayat + B-09 + B-14 (MAKE-006, ±h9,5–14)

- [ ] 15. Branch `ws1/make-006-m3-fe-kgb-hukdis` dari `origin/main`.
- [ ] 16. FE mesin riwayat (§3.4.5): klien API per jenis, tab dari registry `riwayat/jenis/<slug>.ts` + descriptor,
  aksi baris lewat ⋮ (Edit → … → Setujui/Tolak → Hapus), `ApprovalDialog`, master-select/cascade, field berkas, field
  hitung, pemetaan 422, mode halaman usulan mandiri. Route hanya di `routes.riwayat.ts`.
  ℹ️ Detail pegawai live (B-03) datang dari WS-2 M2 (MAKE-010, ±h9,5). Bila belum di `main`, tab memakai `nip` dari
  route.
- [ ] 17. B-09 KGB dan B-14 Hukdis (§3.4.6 #7–#8) + test DoD B-09/B-14.
- [ ] 18. DoD M3: tab B-10/B-08/B-09/B-14 tersambung API di Detail Pegawai; test DoD B-09/B-14 hijau. Gate cepat
  (`composer analyse && composer cs-check`, PHPUnit terarah, `cd frontend && npm run check`).
- [ ] 19. Commit `feat(kepegawaian): MAKE-006 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 5 — review M3.** Tunggu review CR + CI hijau + merge MAKE-006.

### 0.E M4 — B-16 + B-11 + B-17 + halaman Usulan Karpeg/Karis (MAKE-007, ±h14–20,5)

- [ ] 20. Branch `ws1/make-007-m4-keluarga-diklat-karpeg` dari `origin/main`.
- [ ] 21. B-16 Keluarga & Alamat, B-11 Diklat & Seminar, B-17 Tanda Jasa/Organisasi/Karpeg/Karis (§3.4.6 #9–#11).
- [ ] 22. Halaman Usulan Karpeg/Karis (§3.4.7): halaman sendiri, layout legacy, style redesign, ⋮; berkas `file_1..5`
  memakai helper kolom berkas B-18 (sudah di `main` lewat MAKE-009). Ganti placeholder S0-B; jangan menyentuh
  `router/index.ts`/`nav.config.ts`.
- [ ] 23. DoD M4: tab tersambung; halaman Usulan Karpeg/Karis tersambung. Gate cepat hijau.
- [ ] 24. Commit `feat(kepegawaian): MAKE-007 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 6 — review M4.** Tunggu review CR + CI hijau + merge MAKE-007.

### 0.F M5 — B-12a SKP + B-15 AK + butir snapshot B-21 (MAKE-008, ±h20,5–24,5)

- [ ] 25. Branch `ws1/make-008-m5-skp-ak-snapshot` dari `origin/main`.
- [ ] 26. B-12a SKP, B-15 AK, butir snapshot B-21 (§3.4.6 #12–#14).
  ℹ️ B-15 membaca `riwayat_mutasi_jabatan` yang diisi WS-2 B-07 (MAKE-012). Tidak perlu menunggu: isi data dari fixture.
  ℹ️ Bila WS-1 tertinggal, katup: B-12a SKP pindah ke WS-2 (keputusan reviewer CR, §3.9).
- [ ] 27. DoD M5: B-12a, B-15, butir snapshot B-21 tersambung/hijau; perbarui baris WS-1 di
  `backend/docs/progress/03-Kepegawaian.md`. Gate cepat hijau.
- [ ] 28. Commit `feat(kepegawaian): MAKE-008 …`; serah sesuai **0.S**.
  **⛔ TITIK TUNGGU 7 — review M5 (akhir WS-1).** Tunggu review CR + CI hijau + merge MAKE-008. WS-1 selesai; QA
  Lapis 1, review independen, dan sesi QA di luar cakupan build.

---


---

## §1 Status prasyarat (tabel tunggu)

| No | Item | Key | Dikerjakan | Menunggu | Membuka jalan |
|---|---|---|---|---|---|
| 1 | Sprint 0-1 kontrak backend (`SPRINT0_1_backend.md`) | MAKE-002 | Qoder-1 | — | Sprint 0-2 |
| 2 | Sprint 0-2 fondasi frontend (`SPRINT0_2_frontend.md`) | MAKE-003 | Qoder-2 | MAKE-002 di `main` | Gerbang WS (⛔1) |
| 3 | Base case test + FK G-02 | MAKE-001, DBV-019 | Sesi utama (DBV-019 → review DB Validator) | — | Fixture/test ber-DB memakai base case dan patuh FK |
| 4 | WS-1 M1 → M2 (engine-v1 beku) | MAKE-004, MAKE-005 | Qoder-1 | ⛔1; M2 juga menunggu MAKE-009 (⛔3) | WS-2 B-07 Jabatan (MAKE-012) |
| 5 | PegawaiScope + Lampiran B-18 nyata | MAKE-009 | Qoder-2 (WS-2 M1: Scope ±h3, B-18 ±h5) | ⛔1 | DoD WS-1 M2 |
| 6 | Detail pegawai live (B-03) | MAKE-010 | Qoder-2 (WS-2 M2, ±h9,5) | — | Tab WS-1 M3 memakai detail nyata (sementara `nip` dari route) |
| 7 | `riwayat_mutasi_jabatan` terisi (B-07) | MAKE-012 | Qoder-2 (WS-2 M4) | engine-v1 beku | B-15 AK (cukup fixture, tidak menunggu) |
| 8 | WS-1 M3 → M4 → M5 | MAKE-006..008 | Qoder-1 | milestone sebelumnya di `main` | WS-1 selesai |

Istilah:
- **hari-agen** — ukuran beban kerja satu sesi coder (relatif), bukan tanggal kalender. "±h9,5" = sekitar hari-agen
  ke-9,5 dihitung dari awal Sprint 0 (angka PRD). Karena Sprint 0 kini dikerjakan berurutan (0-1 lalu 0-2), semua angka
  ±h di berkas ini bergeser sekitar +1,5 hari-agen.
- **fixture** — data contoh untuk test (pegawai, akun, master G-02) yang dibuat oleh helper test, bukan data asli
  (`tests/_support/Kepegawaian/PegawaiFixtureTrait.php` dari Sprint 0-1).
- **kontrak beku** — setelah Sprint 0 masuk `main`, interface/API/route/registry bersama hanya boleh **ditambah**
  (aditif) oleh pemiliknya; perubahan non-aditif butuh persetujuan reviewer CR dan pemberitahuan ke WS lain.

---

## §2 Aturan kerja wajib (semua milestone WS-1)

1. **Worktree sendiri** dari `origin/main` (jangan bekerja di checkout milik orang lain). Setelah membuat worktree atau
   bila `composer.lock`/`package-lock.json` berubah: `cd backend && composer install`, `cd frontend && npm ci`.
2. **DB scratch sendiri**: `simpeg_v2_ws1_dev` dan `simpeg_v2_ws1_testing` (collation `utf8mb4_unicode_ci`). `.env`
   worktree sendiri dari `backend/.env.example`; **jangan menyalin `.env` dev**. Pastikan `database.default` dan
   `database.tests` menunjuk DB scratch sebelum `spark migrate` atau PHPUnit.
3. **Gate cepat** selama kerja (±5–10 menit): `cd backend && composer analyse && composer cs-check`; PHPUnit hanya
   berkas/folder yang disentuh dan area yang bersinggungan (mis. `vendor/bin/phpunit --no-coverage tests/Kepegawaian`);
   bila frontend berubah: `cd frontend && npm run check`.
4. **Gate penuh = CI** (AGENTS.md §3): push branch, tunggu workflow `quality-gate` hijau untuk SHA itu. Gate penuh lokal
   (`./check.sh`) tidak wajib; bila dijalankan, jalankan sebagai proses lepas dengan log ke berkas
   (`nohup ./check.sh > gate.log 2>&1 &`, atau `Start-Process` di PowerShell), satu gate penuh per mesin.
5. **Commit**: Bahasa Indonesia, gaya `feat(kepegawaian): MAKE-00N …` / `test(kepegawaian): MAKE-00N …` dengan key
   paket (§3.8.1). **Tanpa** trailer `Co-Authored-By` dan tanpa menyebut AI/assistant/tool apa pun di pesan commit,
   komentar, atau dokumen.
6. **Rahasia & repo publik**: jangan commit `.env`, kredensial, token, password, API key; jangan menulis IP/host
   internal, nama server, atau detail celah keamanan.
7. **Tidak push ke `main`.** Serahkan dengan push branch fitur `ws1/…` ke `origin` (0.S); reviewer CR yang memasukkan ke
   `main`. Paket berikut dimulai dari `origin/main` yang sudah memuat paket sebelumnya (rebase bila perlu).
8. **Tanpa migration.** Butuh perubahan skema? Catat di laporan serah; sesi utama mengalokasikan key DBV baru.
9. **UI**: aksi baris tabel lewat satu tombol ⋮ (`RowActionsMenu`), urutan Edit → ubah status → urutan → aksi lain
   (Setujui/Tolak) → Hapus (`danger: true`), aksi berisiko memakai `ConfirmDialog` (AGENTS.md §1).
10. Hanya mengedit berkas milik WS-1 (§3.6). Bila ada aturan di berkas ini yang bertentangan dengan AGENTS.md atau kode
    di `main`, **tanyakan reviewer CR** sebelum menyimpang.

---

## §3 PRD WS-1 — Mesin Riwayat & Riwayat Karier/Administrasi

| | |
|---|---|
| Status | **DISETUJUI 07-10-2026** — turunan dari usulan pembagian Fase 3; keputusan user dicatat di §3.10 |
| Estimasi | ≈24,5 hari-agen |
| Cakupan | Build saja (BE + FE + test yang diwajibkan DoD + gate). QA Lapis 1, review independen, dan sesi QA di luar cakupan |
| Basis & alur merge | Branch sendiri dari `main`; masuk `main` **per milestone** setelah review CR + CI hijau. Tidak ada branch integrasi/trunk |

### 3.1 Prasyarat & basis build

| # | Prasyarat | Status 07-10-2026 |
|---|---|---|
| P-1 | Fase 2 sign-off | **Selesai 07-10-2026** |
| P-2 | Skema B-01/B-02 (PR #20, DBV-012/013) dan G-02 (PR #17/#18) di `main` | **Selesai** — build berangkat dari `main`, bukan dari draf |
| P-3 | CR-044 — CI GitHub Actions | **Selesai** (di `main`); CI = gate penuh resmi |
| P-4 | MAKE-001 — PHPUnit cepat + base case test DB baru (migrate sekali + transaksi per test) | Dikerjakan sesi utama paralel. Fixture Fase 3 **wajib** memakai base case ini begitu masuk `main` |
| P-5 | DBV-019 — FK G-02 ↔ pegawai/riwayat (PR #23) | Dikerjakan sesi utama paralel, review DB Validator. Fixture Fase 3 **wajib** mematuhi FK ini (baris master unit/satker/jabatan disediakan fixture) |

Alur: setiap WS bekerja di branch sendiri dari `main` dan rebase ke `main` saat paket milestone lain (WS-1 atau WS-2)
sudah masuk. Serah-terima antar-WS terjadi **lewat `main`** (paket milestone yang sudah di-merge), bukan lewat branch
integrasi.

### 3.2 Latar belakang & tujuan

Sebagian besar data Modul B berpola sama: **baris riwayat ber-approval yang menyinkronkan tabel snapshot**. WS-1
membangun satu mesin generik (RiwayatEngine + SnapshotSync), membuktikannya lewat dua pilot, membekukannya, lalu
memakainya untuk semua jenis riwayat karier/administrasi.

**Tujuan**
1. Satu engine riwayat yang dipakai 11 jenis riwayat tanpa kode khusus per jenis di luar berkas Definisi.
2. Snapshot hanya berubah pada approval final dan selalu konsisten dengan baris status 1.
3. Engine dibekukan (engine-v1) paling lambat ±h9,5 agar WS-2 bisa membangun B-07 Jabatan di atasnya.
4. Semua tab riwayat WS-1 tersambung API di Detail Pegawai, plus halaman usulan Karpeg/Karis.

**Bukan tujuan**
- Biodata, akun, koreksi NIP, lampiran, lingkup akses, jabatan/struktur, Konket, LKH, halaman daftar pegawai (milik
  WS-2).
- Mengubah skema PR #20 atau migration yang sudah ada di `main`.

### 3.3 Pengguna & hak akses

| Role | Kebutuhan |
|---|---|
| UL_PEGAWAI (pegawai) | Mengajukan riwayat milik sendiri (status 0), melihat status dan alasan tolak |
| Admin (1, dan role lain sesuai Definisi) | Input langsung (status 1), menyetujui/menolak usulan, menghapus (status 10) |
| Role 3 | Sama dengan admin, terbatas lingkup unit/satker via `PegawaiScope` (disediakan WS-2) |
| Karpeg & Karis/Karsu | Role 1, 2, 4, 5, 7 (DoD B-17, Matriks v2); pembagian per aksi (ajukan, daftar/antrian, proses) mengikuti Matriks v2 Bagian 2 Modul B |

Hak akses mengikuti **Matriks v2** (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; keputusan #4): Hukdis role 1/3, AK sesuai
Matriks, Karpeg/Karis role 1, 2, 4, 5, 7 sesuai DoD, Tanda Jasa role 1, 3 (admin) dan 2, 4, 5 (lihat), Organisasi role
1, 2, 3, 7. Izin per aksi per role disimpan **sebagai data di Definisi** tiap jenis, sehingga perubahan cukup satu baris
Definisi. Pengecualian (approver LKH = atasan langsung, ikut legacy) ada di WS-2.

### 3.4 Kebutuhan fungsional

#### 3.4.1 Sprint 0 — kontrak backend (S0-A, MAKE-002, ±h0–1)
Sudah dikerjakan lewat `docs/fase3/SPRINT0_1_backend.md` (routing terpisah, `Services.php` sekali, interface + fake, `RiwayatDefinisi` auto-discovery,
`StatusRiwayat`, `RiwayatRegistry` stub, `PegawaiFixtureTrait`, README kontrak API, progres per task).

#### 3.4.2 SnapshotSync (M1, ±h1–2,5)
- Memilih ulang baris status 1 dengan filter + urutan per target (dok DBV-012 §5.1); pemilih = fungsi murni.
- Multi-target: `riwayat_kp`→kp/cpns/pns, `riwayat_alamat`→alamat/alamat_kantor.
- Snapshot di-DELETE bila tidak ada baris yang memenuhi.
- Berjalan di transaksi pemanggil dengan audit manual; dipicu di **setiap** transisi masuk/keluar status 1.

#### 3.4.3 RiwayatEngine BE (M1, ±h2,5–6,5)
- Model generik (pola MasterModel); service list/get/create/update/hapus (status 10)/process.
- Status awal: 0 untuk UL_PEGAWAI, 1 untuk admin. Guard baris yang sudah disetujui. `approved_by`, `reason_note`.
- `show_ua_*` per role (pola `L_kp` 649-668); `show_notif`/`notif_date` UTC + antrean notifikasi; salin nama master.
- BaseRiwayatController, loop route di `RoutesRiwayat.php`, trait test B-TC generik.
- Isi Definisi: tabel/PK, field (pola MasterField), izin, alur (self-service/admin/usulan), domain status, aturan
  snapshot, aturan lampiran (wajib, `jenis_rwy`, MB, ekstensi), hook (validate/beforeSave/afterApprove).
- Status 3 "Diproses" hanya aktif bila flag dinyalakan (keputusan #8); default nonaktif sehingga domain efektif
  0/1/2/10.

#### 3.4.4 Pilot & freeze (M2)
| Task | Isi | ±Hari |
|---|---|---|
| B-10 Pendidikan (pilot) | Self-service 1,2,3,6,7; snapshot jenjang tertinggi; status INT NULL (DBV #8); lampiran nyata dari WS-2 | 6,5–8 |
| B-08 KP | Multi-target kp/cpns/pns; `KenaikanPangkat::validasiTmt` (CR-025). Setelah lolos → **FREEZE engine-v1**, masuk `main` sebagai paket M2 (MAKE-005) | 8–9,5 |

Setelah freeze, perubahan engine hanya **aditif** dan hanya oleh WS-1. Permintaan hook baru dari WS-2 diajukan ke WS-1.

#### 3.4.5 Frontend mesin riwayat (M3, ±h9,5–12)
- Klien API per jenis; tab dirender dari registry `riwayat/jenis/<slug>.ts` dan izin dari descriptor.
- Aksi baris lewat ⋮ (`RowActionsMenu`, AGENTS.md §1): Edit → … → Setujui/Tolak (grup aksi lain) → Hapus;
  `ApprovalDialog` (alasan wajib saat tolak).
- Master-select/cascade, field berkas, field hitung, pemetaan error 422.
- Mode **halaman usulan mandiri** (dipakai Karpeg/Karis).

#### 3.4.6 Jenis riwayat (fan-out, ±h12–24)
| # | Jenis | Catatan khusus | Est | Milestone |
|---|---|---|---|---|
| 7 | B-09 KGB | `validasiJarak`; acuan jarak = **KP/KGB terakhir** (ikut legacy, keputusan #10 — menutup CR-025 #3) | 1 | M3 |
| 8 | B-14 Hukdis | `hitungTanggalBerakhir`; status ditulis 1 (DBV #10); masa hukdis = `masa_sanksi_bulan` (keputusan #10, menutup K5b); izin role 1/3 (Matriks v2) | 1 | M3 |
| 9 | B-16 Keluarga & Alamat | `detail_anak`; alamat multi-baris → 2 snapshot; wilayah berjenjang | 2 | M4 |
| 10 | B-11 Diklat & Seminar | Snapshot diklat jenis 1; dropdown `id_sub_group_jabatan` (G-02); seminar tanpa snapshot | 1,5 | M4 |
| 11 | B-17 TJ, Organisasi, Karpeg, Karis/Karsu | TJ snapshot 26/27/28. **Karpeg/Karis = usulan mandiri** (§3.4.7) | 3 | M4 |
| 12 | B-12a SKP *(katup)* | `riwayat_skp` lewat engine; `riwayat_skp_periodik`/`bkn_periode_ekinper` diedit role 1 tanpa approval | 1,5 | M5 |
| 13 | B-15 AK | `riwayat_ak`→`pegawai_ak`, `riwayat_ak_siasn`→`pegawai_ak_siasn` (DBV #6), `konv_ak`/`ignore_konv_ak`; izin sesuai Matriks v2; role 6 diblok; baca `riwayat_kp` + `riwayat_mutasi_jabatan` | 2 | M5 |
| 14 | B-21 butir snapshot | Test ber-DB "snapshot hanya di approval final" | 0,5 | M5 |

#### 3.4.7 Halaman Usulan Karpeg/Karis (keputusan #5, 05-10)
- **Halaman sendiri**, bukan tab di Detail Pegawai (tab redesign tidak di-port).
- **Layout ikut legacy** (`Karpeg.php`): menu sendiri, daftar/antrian `list_ad`, form usulan, alur proses.
- **Style ikut redesign**: token + komponen shared, aksi baris lewat ⋮.
- Hak akses role 1, 2, 4, 5, 7 (DoD B-17); pembagian per aksi dari Matriks v2, disimpan di Definisi.
- Berkas `file_1..5` memakai helper kolom berkas B-18 (WS-2).
- Route di `routes.riwayat.ts`; entri nav sudah dibuat WS-2 di S0 — WS-1 tidak menyentuh `router/index.ts`/
  `nav.config.ts`.

### 3.5 Kebutuhan non-fungsional
- Semua tulis riwayat + snapshot + lampiran dalam **satu transaksi**; rollback penuh bila gagal.
- Audit untuk create/update/hapus/approve/reject.
- Lingkup akses wajib lewat `PegawaiScope` di setiap endpoint (403 bila di luar lingkup).
- Waktu disimpan UTC.
- Batas lampiran **per jenis 1/2/5 MB ikut legacy** (keputusan #6), disimpan di aturan lampiran Definisi.
- Kualitas: gate lolos (PHPStan 5, PHP-CS-Fixer, PHPUnit, ESLint, vue-tsc, Vitest, build — CI hijau). Test wajib DoD
  (bagian dari build, keputusan #9): B-08, B-09, B-14, dan butir snapshot B-21.
- Test ber-DB memakai base case MAKE-001 (bila sudah di `main`) dan fixture yang mematuhi FK DBV-019.

### 3.6 Kepemilikan berkas

| Milik WS-1 | Tidak boleh disentuh WS-1 |
|---|---|
| `RoutesRiwayat.php`, `Libraries/Kepegawaian/Riwayat/**` (engine, SnapshotSync, Definisi selain Jabatan), `BaseSnapshotModel`, `BaseRiwayatController`, `routes.riwayat.ts`, `riwayat/jenis/<slug>.ts` milik WS-1, loader registry + `RiwayatTabHost` + komponen `riwayat/*.vue` (setelah S0-B), halaman Usulan Karpeg/Karis | `Routes.php`/`Services.php` (setelah S0, kecuali ganti stub service milik WS-1), `RoutesPegawai.php`, `composer.json`, `TabelAnakNip`, router/nav, `DetailPegawaiPage`, `StatusBadge`/`ApprovalDialog` (perubahan lewat WS-2), **semua migration yang sudah ada di `main`**, `_support` milik PR #20 (`LepasMigrationKepegawaianTrait.php`, `SkemaKepegawaianTestTrait.php`, `Kepegawaian/SkemaD1*.php`), berkas base case MAKE-001 |

### 3.7 Dependency

| WS-1 butuh | Dari | Butuh / siap | Cara melepas |
|---|---|---|---|
| PegawaiScope | WS-2 (paket WS-2 M1, MAKE-009) | h2,5 / h3–5 | Interface + fake S0; versi nyata masuk lewat `main` (⛔3) |
| Lampiran B-18 | WS-2 (paket WS-2 M1, MAKE-009) | h6,5 / h5 | Fake S0; versi nyata masuk `main` sebelum pilot |
| Port FE (S0-B) + detail B-03 (MAKE-010) | WS-2 | h9,5 / h1,5 & h9,5 | Sementara tab pakai nip dari route |
| Tabel `riwayat_mutasi_jabatan` (B-15) | WS-2 B-07 (MAKE-012) | h22 / `main` | Hanya baca; isi dari fixture |
| Base case test (MAKE-001), FK DBV-019 | Sesi utama | S0 | Fixture ditulis hanya-INSERT; rebase saat masuk `main` (Sprint 0-1) |

| WS-2 butuh dari WS-1 | Siap |
|---|---|
| Descriptor tab (kontrak) | S0 (stub) |
| **engine-v1 beku** (untuk B-07) | **h9,5** (paket WS-1 M2 di `main`) — jalur kritis lintas WS |

### 3.8 Milestone & kriteria selesai
| Milestone | Key | Branch | ±Hari | Kriteria |
|---|---|---|---|---|
| S0-A kontrak beku | MAKE-002 | `ws1/make-002-s0a-kontrak-backend` | h1 | Interface, fake, README API, fixture tersedia di `main` |
| M1 SnapshotSync + Engine BE | MAKE-004 | `ws1/make-004-m1-engine-be` | h6,5 | Test unit pemilih murni + multi-target hijau; alur draft→approve/reject→snapshot→audit→lingkup hijau dengan fake |
| **M2 engine-v1 FREEZE** | MAKE-005 | `ws1/make-005-m2-pilot-freeze` | **h9,5** | B-10 + B-08 lolos dengan lampiran & scope nyata; masuk `main` |
| M3 FE mesin riwayat + B-09/B-14 | MAKE-006 | `ws1/make-006-m3-fe-kgb-hukdis` | h14 | Tab B-10/B-08/B-09/B-14 tersambung API di Detail Pegawai; test DoD B-09/B-14 hijau |
| M4 B-16/B-11/B-17 + Karpeg/Karis | MAKE-007 | `ws1/make-007-m4-keluarga-diklat-karpeg` | h20,5 | Tab tersambung; halaman Usulan Karpeg/Karis tersambung |
| M5 Selesai | MAKE-008 | `ws1/make-008-m5-skp-ak-snapshot` | h24,5 | B-12a, B-15, butir snapshot B-21 tersambung/hijau; CI hijau |

#### 3.8.1 Peta key Fase 3 (keputusan #11: satu key per paket milestone per WS)

Key yang sudah terpakai sebelum Fase 3: CR-044 (CI), MAKE-001 (PHPUnit cepat + MySQL test), DBV-019 (FK G-02↔pegawai,
PR #23).

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

`MAKE-###` untuk pekerjaan baru; `CR-###` **hanya** untuk perbaikan hasil review/QA (AGENTS.md §2) — nomornya
ditetapkan reviewer CR. Bila katup dipakai (mis. B-12a pindah ke WS-2), task ikut key paket tujuan. Kebutuhan skema
baru di luar build memakai key DBV baru (DBV-020 dst.) yang dialokasikan sesi utama.

### 3.9 Risiko
| Risiko | Mitigasi |
|---|---|
| Engine jadi titik gagal tunggal | Dua pilot (B-10, B-08 multi-target) sebelum freeze; perubahan pasca-freeze hanya aditif |
| Skema PR #20 perlu koreksi | Skema sudah di `main`; koreksi hanya lewat migration ALTER baru dengan key DBV baru (di luar build); aturan disimpan sebagai data di Definisi; WS rebase ke `main` |
| Freeze terlambat → B-07 WS-2 tertahan | Katup: WS-2 menukar B-07 dengan Konket/LKH (+±4 hari slack) |
| WS-1 tertinggal | Katup: B-12a SKP pindah ke WS-2 |
| Antrian merge per milestone menunda serah-terima | CI = gate penuh resmi; gate penuh lokal tidak diulang (AGENTS.md §3); MAKE-001 mempercepat PHPUnit |
| PHPUnit flaky di DB bersama | DB scratch `simpeg_v2_ws1*`, tanpa salin `.env` dev; base case MAKE-001 |

### 3.10 Keputusan (disetujui user 07-10-2026)

| # | Keputusan | Dampak ke WS-1 |
|---|---|---|
| 1 | Build berangkat dari `main` (PR #20 & G-02 sudah di `main`, Fase 2 sign-off 07-10) | Tidak ada pengecualian urutan fase; tidak ada build di atas draf |
| 2 | Pembagian WS-1/WS-2: B-18 di WS-2, B-15 di WS-1, B-12 dipecah (SKP WS-1, LKH WS-2), B-20 dipecah (dasar di B-03, penutup di akhir WS-2) | Cakupan §3.4 |
| 3 | Tanpa branch integrasi: tiap WS branch sendiri dari `main`, masuk `main` per milestone setelah review CR + CI hijau | §3.1, §3.8 |
| 4 | Hak akses ikut Matriks v2 (Hukdis 1/3, Jabatan & AK sesuai Matriks, Karpeg/Karis 1,2,4,5,7 sesuai DoD), kecuali approver LKH = atasan langsung (legacy). Izin disimpan sebagai data di Definisi per jenis | §3.3, Definisi |
| 5 | (05-10) Konket & Karpeg/Karis = halaman usulan mandiri, layout legacy, style redesign | §3.4.7 |
| 6 | Batas lampiran per jenis 1/2/5 MB ikut legacy | §3.5, aturan lampiran Definisi |
| 7 | PDF: TCPDF (ikut legacy) via ADR-033 (lisensi LGPL-3.0-or-later); cadangan dompdf bila TCPDF bermasalah | Milik WS-2 (composer) |
| 8 | Status 3 "Diproses" di belakang flag, nonaktif default | `StatusRiwayat`, engine |
| 9 | Unit/feature test yang diwajibkan DoD (B-06/08/09/14, B-21) + gate = bagian build; QA Lapis 1/review/sesi QA tidak | §3.5 |
| 10 | Default ikut legacy: acuan KGB = KP/KGB terakhir; cascade NIP ikut `update_nip` legacy (+ `jabatan_koordinasi.nip`); masa hukdis = `masa_sanksi_bulan`; lingkup unit destinasi 21 / unit lain 7 ikut legacy | B-09, B-14 |
| 11 | Satu key per paket milestone per WS; key WS mulai MAKE-002 | §3.8.1 |

---

## Lampiran — rujukan

| Dokumen | Isi |
|---|---|
| `docs/fase3/README.md` | Urutan perintah Fase 3, tabel tunggu ringkas, reviewer |
| `docs/fase3/SPRINT0_1_backend.md` | Sprint 0-1 (kontrak backend beku: interface, descriptor, slug, API, fixture) |
| `docs/fase3/SPRINT0_2_frontend.md` | Sprint 0-2 (fondasi frontend beku: registry tab, `RiwayatTabHost`, shell, route/nav) |
| `docs/fase3/WS2.md` | Runbook + PRD mitra (Qoder-2) |
| `docs/fase3/MATRIKS_ROLE_MODUL_B.md` | Matriks role × endpoint Modul B (hak akses, keputusan #4) |
| `docs/fase3/03-Kepegawaian.md` | Kontrak task Fase 3 (B-01..B-21, DoD per task) |
| `docs/adr/ADR-033-library-pdf-tcpdf.md` | Library PDF TCPDF (milik WS-2, informasi) |
| `AGENTS.md` | Aturan proyek: menu ⋮ (§1), commit/key/rahasia/merge (§2), gate cepat & CI (§3) |
| `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md` | Skema B-01/B-02, aturan snapshot §5.1 |
| `backend/docs/progress/03-Kepegawaian.md` | Progres per task (tiap WS hanya mengubah barisnya sendiri) |
| `backend/app/Libraries/Kepegawaian/README.md` | Library Kepegawaian yang sudah ada (Kalkulasi, Nip) |
