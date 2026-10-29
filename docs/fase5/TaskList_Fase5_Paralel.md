# Task List Fase 5 — pelacakan & pembagian paralel (29-10-2026)

Basis: `origin/main` = `6c116ac` (pembaruan MAKE-054; sebelumnya `7eac943`). Acuan: PRD 5A v1.1, PRD 5B v1.0, PRD 5C v1.1 +
`docs/fase5/TaskList_Fase5C.md`. Nama pelaksana netral: **Coder A–D** = sesi coder, **Reviewer** = reviewer CR, **Horii** =
pengambil keputusan & sign-off.

## 1. Ringkasan status

| Sub-fase | Status | Bukti |
|---|---|---|
| 5A (skema, slip gaji, presensi, FE 5A) | ✅ SIGN-OFF 28-10 | M1–M8 di `main` (`54cb441`), DBV-023 |
| 5B (cuti/TB Tukin, laporan, rekap, uang makan) | ✅ SIGN-OFF Horii 29-10 | M1–M7 di `main` (MAKE-043 `87f77c8`), DBV-024 |
| 5C (FE laporan, D-13, penutup) | ✅ QA Lapis 1 5C lolos 29-10; MAKE-054 laporan akhir | MAKE-047..053, CR-075..081 di `main` (`6c116ac`) |
| Fase 5 DONE | ⛔ Sign-off Horii setelah MAKE-054 + QA Lapis 1 FE 5A (Q5A-18, `Laporan_Akhir_Fase5.md` §7) | **Tidak** menunggu UP-1 (§6) |
| Tahap UP-1 | ⏳ Persiapan | §6, Trello list "Tahap UP-1" |

## 2. Daftar task (semua pelaksana)

| No | Task | Key | Pelaksana | Menunggu | Status | Izin merge |
|---|---|---|---|---|---|---|
| 1 | 5B M7 verifikasi 5B + prosedur paritas UP-1 | MAKE-043 | Coder C | — | ✅ Di `main` `87f77c8` | Horii |
| 2 | 5C C0 laporan pemahaman | — | Coder A, B, C | — | ✅ | — |
| 3 | Fondasi FE laporan (filter, status, legenda, unduh) | MAKE-047 | Coder A | C0 | ✅ Di `main` `b1aa2ea` | Horii |
| 4 | Laporan Tukin (daftar, IL, per pegawai, Saya, PDF) | MAKE-048 | Coder A | MAKE-047 | ✅ Di `main` `2b5903e` | Horii |
| 5 | Rekap Tukin (+IL, XLSX/PDF) | MAKE-049 | Coder A | MAKE-047 | ✅ Di `main` `fde58a3` | Horii |
| 6 | Penyesuaian Tukin (CRUD role 1) | MAKE-050 | Coder B | MAKE-047 | ✅ Di `main` `52e6bf4` | Horii |
| 7 | Rekap Kehadiran (+XLSX) & Rekap Uang Makan (+IL) | MAKE-051 | Coder B | MAKE-047 | ✅ Di `main` `7387e71` | Horii |
| 8 | Regression suite D-13 (12 skenario, backend) | MAKE-052 | Coder C | MAKE-043 | ✅ Di `main` `d86a04a` | Horii |
| 9 | Checklist QA Lapis 1 5C (`docs/fase5/Checklist_QA_Lapis1_Fase5C.md`) | (bagian MAKE-054) | Coder D | PRD 5C | ✅ Hasil QA diisi, masuk `main` bersama MAKE-054 | Reviewer |
| 10 | CR backend dari FE 5C: kode kejadian harian, baris non-kerja harian | CR-075, CR-076 | Coder D | — | ✅ Di `main` `a477fc5`, `74a2e35` | Horii |
| 11 | Penutup: `ACTIVE_PHASE` 4→5, hapus `rilis`, README/progres | MAKE-053 | Coder A | MAKE-047..051 | ✅ Di `main` `12f2f46` | Horii |
| 12 | QA Lapis 1 5C di app demo (role 1–8) | (MAKE-054) | Reviewer | MAKE-053 | ✅ Selesai 29-10: role 2–8 lolos, role 1 → temuan T-01..T-11 | — |
| 13 | Perbaikan temuan QA: tata letak page-fill/table-fill, warna sel sticky, z-index (T-02, T-03, T-10, T-11) | CR-077 | Coder D | No. 12 | ✅ Di `main` `7eac943` | Horii |
| 14 | Perbaikan temuan QA: bulan bawaan Tukin 16–15, sinkron rute, galat unduhan blob, fokus ⋮, label TB, Predikat SKP (T-01, T-04..T-07, T-09) | CR-078 | Coder B | No. 12 | ✅ Di `main` `7eac943` | Horii |
| 15 | QA ulang setelah CR-077/078 + `IndikatorRadius` (I1, pegawai wajib geofence + izin lokasi) | (MAKE-054) | Reviewer | No. 13–14 | ✅ Lolos; temuan T-12..T-15 → CR-079 `e9027e5`, CR-081 `6c116ac` | — |
| 16 | Verifikasi 5C + laporan akhir Fase 5 (status Tahap UP-1 dicatat, bukan syarat) | MAKE-054 | Reviewer (+ Coder D dokumen) | No. 15 | ✅ `docs/fase5/Laporan_Akhir_Fase5.md` | Horii |
| 16a | QA Lapis 1 FE 5A (`Checklist_QA_Lapis1_FE5A_Q5A-18.md`; GPS perangkat nyata → blocker go-live) | (MAKE-054) | Reviewer | — | Todo | — |
| 17 | Sign-off Fase 5 | — | Horii | No. 16, 16a | ⛔ | — |
| 18 | Sinkron Trello DEV-006 / QASMTASK-076..088 + Notion | — | Reviewer | Tiap merge / sign-off | Berjalan | — |

## 3. Jalur paralel (sisa)

```
Reviewer: MAKE-054 (No. 16) + QA Lapis 1 FE 5A (No. 16a) → sign-off Horii Fase 5 (No. 17)
Tahap UP-1 (§6) berjalan paralel, terpisah dari penutupan Fase 5
```

## 4. Aturan koordinasi paralel (satu mesin)

- **Satu `./check.sh` penuh per mesin pada satu waktu.** Gate cepat per paket boleh. Gate penuh hanya sebelum merge ke `main`.
  Gate lokal meniru CI: **tanpa** `backend/.env`; konfigurasi lewat env `database_tests_*` (DB scratch 3307 bernama eksplisit)
  + `auth_captchaDriver=mock`. Jangan menimpa nilai di akhir `.env` (DotEnv CI4: definisi pertama menang).
- **Satu worktree per coder**, dipakai ulang antar paket. Disk tersisa ±22 GB.
- **DB scratch:** hanya port 3307, nama eksplisit; hapus dengan izin Horii per objek.
- **Berkas bersama** (`routes.presensi.ts`, `roles.ts`, `nav.config.ts`, `main.css`, `shared/*`, README) diubah **aditif dengan
  commit terpisah**. Yang merge belakangan menggabungkan `origin/main`.
- **Lapor ke reviewer** setiap push (SHA + run CI), juga bila CI masih berjalan. Bila tertahan kuota API GitHub, langsung kabari reviewer.
- **Dilarang:** membuka `constants.php` legacy, data nyata atau rahasia di repo, menyebut AI/tool di commit, force push.

## 5. Di luar Fase 5 tetapi wajib dilacak (blocker go-live)

| ID | Butir | Pemilik |
|---|---|---|
| QC-1 | Uji koreksi NIP pada volume `dh_online` realistis | Pra-cutover |
| QC-2 | Akar aset v2 final + logo kop bawaan | Cutover |
| QC-3 | Konfirmasi Biro Keuangan data verifikasi QR (AS-06) | Pra-go-live |
| QC-4 | `proxyIPs` bila di belakang proxy | Deploy |
| QC-5 | `encryption.key` + HTTPS | Deploy |
| Q5A-14 / Q5A-25 | Checksum legacy produksi; nama id 6 PPPK | Cutover |
| UP-1 / F5B-9 | Paritas Tukin data nyata | **Tahap UP-1** (§6) |

## 6. Tahap UP-1 — antara Fase 5 dan Fase 6 (keputusan Horii 29-10-2026)

**Aturan tahap:**
1. UP-1 (uji paritas Tukin v2 vs legacy pada data nyata) adalah **tahap tersendiri** di antara Fase 5 dan Fase 6.
2. **Fase 6 tidak boleh dimulai atau dilanjutkan sebelum UP-1 selesai** (sign-off UP-1 oleh Horii).
3. **Fase 5 di-sign-off setelah QA + semua item clear, kecuali UP-1.** UP-1 tidak menahan sign-off Fase 5.

**Keputusan yang sudah ada:** sumber data = DB server dev SIMPEG, **bukan** produksi. Server dev adalah salinan produksi,
jadi datanya tetap diperlakukan sebagai data pribadi nyata. DBA UP-1 = **DBV** (P-01). DBV sudah punya akses DB server dev
(P-02, sebagian). Jalur impor = **alat impor resmi** yang sekaligus gladi cutover (P-06 opsi 2, MAKE-056). Paket runbook:
`docs/up1/` (PR MAKE-055).

| No | Task | Key | Pelaksana | Menunggu | Status |
|---|---|---|---|---|---|
| U1 | Keputusan P-01..P-09 (`docs/up1/Pertanyaan_Terbuka_UP-1.md`) | UP1-01 | Horii | — | ⏳ P-01, P-06, sebagian P-02 terjawab |
| U2 | Paket siap-jalan UP-1 (runbook, skrip ekstraksi baca-saja, template hasil) | MAKE-055 | Coder (ReadOne) → Reviewer | U1 dikunci → merge | ⏳ PR terbuka, jangan merge |
| U3 | Alat impor resmi legacy → v2 (±35 tabel jalur Tukin, gladi cutover) | MAKE-056 | Coder (ReadOne), review CR + DBV | P-06 ✅ | ⏳ PR #32: review CR selesai, CI hijau; menunggu review DBV + izin Horii |
| U4 | Ekstraksi salinan dari DB server dev + audit jumlah baris | UP1-04 | DBV | P-02..P-05 | Todo |
| U5 | Ekstraktor redaksi daftar penyesuaian Tukin legacy (P-09) | UP1-05 | Reviewer, izin Horii | P-09 | Todo |
| U6 | Impor → penyesuaian (CRUD) → ekspor legacy & v2 → `presensi:paritas-tukin` → triase | UP1-06 | DBV + Reviewer | U3, U4, U5 | Todo |
| U7 | Perbaikan bug kelompok (c) lewat kartu CR + uji ulang | UP1-07 | Coder → Reviewer | U6 | Todo |
| U8 | Lembar hasil anonim + **sign-off UP-1** | UP1-08 | Reviewer → Horii | U7 | Todo |
| U9 | Bersih-bersih salinan data & DB scratch (izin Horii per objek) | UP1-09 | DBV | U8 | Todo |
| U10 | Buka prasyarat Fase 6 (GATE-F6 Done) | UP1-10 | Horii → Reviewer | U8 | Todo |

## 7. Prasyarat Fase 6

- ⛔ **Tahap UP-1 selesai** (§6 U8 sign-off Horii). Pelacak: Trello `GATE-F6` di list Fase 6.
- PRD Fase 6 (bila dibuat sebelum UP-1 selesai) wajib mencantumkan prasyarat ini. Koding Fase 6 tidak dimulai sebelum gerbang terbuka.
