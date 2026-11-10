# Laporan Akhir Fase 6 — Modul H Integrasi & API Eksternal (MAKE-077)

Acuan: PRD Fase 6 **v1.0o** (`docs/fase6/PRD_Fase6_Integrasi.md`, §11 DoD, §12 register Q6-01..Q6-42), `6_1.md` (Jalur B),
`6_2.md` (Jalur A), progres `backend/docs/progress/06-Integrasi.md`, checklist `docs/fase6/Checklist_QA_Lapis1_Fase6.md`.
Disusun reviewer CR 10-11-2026 dari `main` `75361d0d` (seluruh paket H-01..H-12 sudah di `main`) ditambah CR-111.

> **Status:** **FASE 6 DONE — sign-off Horii 10-11-2026** (`main` `f10fd0af`); Trello & Notion disinkron 10-11-2026 (§9).
> H-01..H-12 di `main`; regresi gate penuh lolos; QA Lapis 1 Fase 6 (mode mock)
> **lolos** — satu temuan (T1) diperbaiki CR-111 sebelum sign-off (keputusan Horii 10-11-2026). Satu deviasi tercatat:
> **K1** (log integrasi via API + halaman Admin → Integrasi) ditunda ke fase berikutnya (keputusan Horii 10-11-2026).
> Uji endpoint real (BSrE, SIASN, e-Kinerja, FCM) **bukan** syarat sign-off (F6-23, Q6-13 = A) tetapi **wajib lolos
> sebelum deploy** (MT-010, DEPLOY-001.1, §8).

## 1. Laporan

```
Status: H-01..H-12 DONE di main; QA Lapis 1 Fase 6 LOLOS (55 lolos, 2 N/A deviasi K1; T1 diperbaiki CR-111)
        Deviasi: K1 (GET api/v1/integrasi/log + FE Admin → Integrasi) → fase berikutnya
Branch/SHA/CI: main 75361d0d — regresi gate penuh ./check.sh lolos (3.813 PHPUnit / 68.003 asersi, 3 skip;
               1.485 Vitest; build) — log wt/fase6b/gate-regresi-f6.log, EXIT=0
               CR-111 fe6af105 — CI Quality gate #665 hijau; gate penuh lokal lolos (3.815 PHPUnit, 1.485 Vitest, build)
               Checklist + laporan ini: docs saja (branch docs/fase6-qa-lapis1)
Berkas utama: §2
Test & validasi: §3 (DoD §11 → bukti), §4 (QA Lapis 1), §5 (regresi)
Deviasi [V2] & keputusan: §6
Blocker / pertanyaan terbuka: §7; blocker deploy/go-live: §8
```

## 2. Paket Fase 6 di `main`

Pelaksana: Jalur A (API masuk & job) — Worker Fix Bug (lead), ReadOne, Worker Fable, Worker Haiku (A2b); Jalur B (klien
keluar) — Handling Case, WS-2 (B5); DB Validator WS-2; reviewer CR; setiap merge atas izin Horii.

| Task | Isi | Key | SHA di `main` |
|---|---|---|---|
| Gerbang | UP-1 LOLOS, GATE-F6 dibuka 02-11-2026 | UP1-10 | `dbe9175d` |
| H-01 | Skema klien layanan & SIMAPI (PR #37) | DBV-026 | `7185bdd5` |
| H-01 | Skema integrasi keluar (PR #35) + akun sistem integrasi | DBV-027, CR-096 | `c3c6213f` |
| H-01 | Token surat cuti (QR TTE / verifikasi publik) | DBV-030 | `4c2d96a5` |
| H-01 | Koreksi SIMAPI hasil D1: `simapi_update`/`_hist`, seed `simapi_jd` (PR #38) | DBV-031 | `e2f91113` |
| Fondasi B | Klien HTTP keluar + `integrasi_log` + token BKN | MAKE-063 | `74039dc7` |
| Fondasi A | Auth antar-sistem (`klienLayanan`, `simapiToken`, ADR-036) | MAKE-058 | `c926770f` |
| H-02 | Adapter BSrE + surat cuti ber-TTE (B4a) | MAKE-065 | `07ac6711` |
| H-02 | `services/s_esign` untuk MuDig (B4b, F6-16) | MAKE-065 | `c3af17a1` |
| H-02 | TTE surat cuti atas nama pejabat (Q6-40, ISSUE-055) | MAKE-075 | `07a5df0c` |
| H-03 | SIASN tarik/dorong/unggah arsip ikut legacy (F6-14) | MAKE-067 | `6cd50e69` |
| H-04 | Push FCM HTTP v1, kanal web + Android, topik presensi | MAKE-066 | `9debb366` |
| H-05..H-07 | SIMAPI `a_auth`, `a_employee` (25 method), `a_dm`, `ambil_riwayat_mutasi`, `services/s_dm` | MAKE-059 | `d115e50d` |
| H-06 | `change_history` ack-on-read (A5a) | MAKE-060 | `67b46538` |
| H-06 | Penulis `simapi_ch` = port trigger D1 (A5b, opsi B savepoint) | MAKE-060 | `2c72332f` |
| H-06 | API SIMAPI lama `simapi/a_pegawai` + penulis `simapi_update` (Q6-41) | MAKE-076 | `b6e74d23` |
| H-08 | `services/s_stat` rekomendasi KP/KGB/pensiun + daftar TB/IB/karpeg | MAKE-061 | `539f4c7c` |
| H-09 | Pemetaan `Local.php` (56 method) + penutup Jalur A + runbook | A7 | `1b097ddc` |
| H-10 | e-Kinerja BKN (`bkn_periode_ekinper`, `riwayat_skp_periodik`) | MAKE-064 | `74039dc7` |
| H-11 | Status TB/CLTN harian + `pengguna:sinkron-nama` + ADR-037 | MAKE-062 | `c2b8e2d7` |
| H-12 | Auto-approval LKH tanggal 5, PDF on-demand | MAKE-068 | `5a8764c4` |
| Penutup B | Progres, README modul, README-deploy §1e, `integrasi:uji-koneksi` | MAKE-067 B7, CR-101 | `158e4b98`, `67f172fb` |
| Impor | Impor tabel Fase 6 + tahap 2 cutover | MAKE-056 B3, MAKE-069 | `7c5258aa`, `22389c0b` |
| Pengaman | Berkas root `backend/` terhapus saat gate dihentikan (`ImporSlipUjiTrait`, `check.sh`) | CR-108 | `5d7d5778` |
| PRD | v1.0a..v1.0o (Q6-17..Q6-42) | CR-090..CR-110 | terakhir `75361d0d` |
| Temuan QA | Nama item cache pembatas aman untuk nama berkas (T1) | CR-111 | `fe6af105` (masuk `main` bersama laporan ini, izin Horii 10-11-2026) |

## 3. DoD §11 → bukti

| DoD PRD §11 | Bukti |
|---|---|
| H-01..H-12 DONE, AC terpenuhi, di `main` | §2; status per task di progres `06-Integrasi.md` §Status; AC per task diuji test otomatis modul (`tests/database/Integrasi/*`, `tests/unit/Integrasi/*`, Layanan/Esign, Kepegawaian/Lkh) |
| Semua integrasi lolos test dengan mock/FakeTransport | Regresi §5 (driver `mock` + transport tiruan; tanpa jaringan keluar) |
| Regresi C-05/C-06 + Tukin Fase 5 hijau | §5: C-05 85 test, C-06 96 test, Tukin 616 test — lolos di gate penuh `75361d0d` |
| QA Lapis 1 Fase 6 lolos | §4 |
| Runbook cron + Nginx + daftar re-handshake klien | `README-deploy.md` §1b–§1j (QA N4 ✅): jadwal command, worker `push`/`integrasi`, Nginx `/simapi/`, `/services/`, header `Authorization`, real IP; daftar klien re-handshake (PN-4) |
| Laporan akhir | Dokumen ini |
| Sign-off Horii | ✅ 10-11-2026 (`main` `f10fd0af`) |
| Trello DEV-007, QASMTASK-089..097, QASMTASK-120..122 disinkron | ✅ 10-11-2026 (§9) |

## 4. QA Lapis 1 Fase 6 (mode mock)

Checklist `docs/fase6/Checklist_QA_Lapis1_Fase6.md` (hasil per butir + §T). Dijalankan reviewer CR 10-11-2026 di app demo
lokal `main` `75361d0d` (DB demo direset, data `seed_demo.php` → `qa_fase4_data.php` → `qa_fase6_data.php`, data
sintetis, semua driver eksternal `mock`). A5 diuji ulang pada CR-111 `fe6af105`.

| Bagian | Hasil |
|---|---|
| A auth antar-sistem | ✅ 7/7 (A5 ✅ sesudah CR-111; uji pertama ❌ → T1) |
| B skema | ✅ 2/2 |
| C e-sign BSrE (mock) termasuk TTE atas nama Q6-40, QR/verifikasi publik, `s_esign` MuDig | ✅ 10/10 |
| D SIASN [mock-terbatas] | ✅ 2/2 (penolakan eksplisit + bukti test otomatis) |
| E push FCM (mock) | ✅ 4/4 (E3 diuji dengan driver `fcm` sementara di `.env` demo, dipulihkan) |
| F SIMAPI v1 | ✅ 6/6 |
| G API SIMAPI lama + penulis log Q6-42 | ✅ 4/4 |
| H `s_stat` + `s_dm` | ✅ 5/5 |
| I pemetaan H-09 | ✅ 1/1 |
| J e-Kinerja [mock-terbatas] | ✅ 4/4 |
| L status TB/CLTN harian | ✅ 3/3 |
| M auto-approval LKH | ✅ 3/3 |
| N operasional log & runbook | ✅ 4/4; N5/N6 N/A (deviasi K1) |

**Temuan (§T checklist):**

| # | Temuan | Status |
|---|---|---|
| T1 | Kunci cache `PembatasKegagalan` memakai `\|`; di Windows tidak sah sebagai nama berkas → handler cache berkas gagal diam-diam → throttle 429 tidak aktif | **Diperbaiki CR-111** (nama item = awalan + `_` + md5; test handler berkas nyata gagal di kode lama, lolos di CR-111); A5 diuji ulang ✅ |
| T2 | Koreksi teks checklist (kunci `fcm_token`, `updated_at` saat jatuh tempo refresh, 404 vs 403 C3c, baris TLS C6x, E3 driver mock, data demo tanpa foto/status 10, `reset_demo.sh` hilang) | Dicatat; kode sesuai PRD/legacy |
| T3 | Allowlist `127.0.0.1` menolak akses `localhost` (sumber `::1`) | Catatan deploy (§8 butir 4) |

Keamanan (N3): rahasia lima klien uji, passphrase uji, NIK sintetis, dan token Bearer tidak ditemukan di log berkas
maupun `integrasi_log`.

## 5. Regresi gate penuh

| SHA | Hasil |
|---|---|
| `75361d0d` (main, seluruh H-01..H-12) | `./check.sh` EXIT=0 — PHPStan level 5 tanpa galat, CS-Fixer bersih, PHPUnit 3.813 test / 68.003 asersi (3 skip), Vitest 135 berkas / 1.485 test, build; regresi terarah C-05 85, C-06 96, Tukin Fase 5 616 test lolos |
| `fe6af105` (CR-111) | CI Quality gate #665 hijau (MySQL 8.0 + MariaDB 10.4); gate penuh lokal `./check.sh` lolos — PHPStan, CS-Fixer, PHPUnit 3.815 test / 68.010 asersi (3 skip; +2 test CR-111), ESLint, vue-tsc, Vitest 135 berkas / 1.485 test, build |

## 6. Deviasi & keputusan

Register lengkap PRD §12 (Q6-01..Q6-42). Ringkas:

- **K1 — deviasi (Horii 10-11-2026):** `GET api/v1/integrasi/log` (filter layanan/status/tanggal, paginasi, role 1) dan
  halaman FE **Admin → Integrasi** (log + tombol tarik ulang e-Kinerja per periode) dari PRD §6.2/F6-24 belum dibuat;
  ditunda ke fase berikutnya. Pemantauan Fase 6: `php spark integrasi:status` + log berkas; tarik ulang e-Kinerja tersedia
  lewat API `POST api/v1/integrasi/bkn/tarik` (role 1).
- **Ikut legacy (kontrak klien eksternal tetap):** SIMAPI 1:1 (Q6-02) termasuk bug B-9 `unit_es_3/4` (Q6-36) dan filter
  `rekomendasi_kp_uns` (Q6-37); API SIMAPI lama `simapi/a_pegawai` dengan cek IP wajib dan `password` = hash akun berlaku
  (Q6-41, Q6-42); SIASN ikut legacy penuh (Q6-07) dengan perbaikan bug legacy tarik (Q6-31); TLS BSrE ikut legacy (Q6-18,
  jalur upgrade `esign.caBundle`); QR TTE ikut legacy dengan token acak (Q6-22); kanal Android ikut legacy (Q6-28);
  auto-approval LKH (Q6-15); `s_esign` status string + `msg` null [K].
- **[V2] (lebih ketat dari legacy):** semua endpoint antar-sistem wajib auth (Q6-01) dengan rahasia ber-hash, throttle
  kegagalan per IP + per klien, log tanpa rahasia (ADR-036); `ambil_seluruh_pengguna` tidak di-port (Q6-03); alur TTE
  dua langkah PyB (Q6-19) + pembatas passphrase 5/15 menit (Q6-20) + passphrase hanya Setujui/Penangguhan (Q6-21); QR kaki
  surat = halaman verifikasi publik tanpa NIP (Q6-23); URL arsip/foto lewat endpoint unduh klien, bukan path server
  (Q6-38, Q6-39); pengaman aksi destruktif SIASN (Q6-30) dan anti-duplikat dorong BKN (Q6-32); log SIMAPI ditulis di
  dalam transaksi pemilik dengan savepoint (Q6-42 opsi B).
- **Tidak dimigrasi:** riwayat & antrean SIMAPI legacy (`simapi_ch` ±12 juta baris, `simapi_auth_ch`, `simapi_update`,
  `_hist`) dan token legacy (Q6-42 opsi a); token FCM (AC-01.2, terdaftar ulang otomatis); SAPK lama dan Siasn generasi 1
  (F6-21).
- **Di luar scope (PRD §1.2):** inbox notifikasi in-app (Fase 7 F-08), popup Android (Q6-29, Fase 7 F-04), adapter
  penyimpanan remote (F6-22), view kompatibilitas mudig/E-Talenta (cutover).

## 7. Butir terbuka sebelum sign-off

| No | Butir | Status |
|---|---|---|
| 1 | Merge CR-111 (`fe6af105`) ke `main` | Izin Horii 10-11-2026; masuk `main` bersama laporan ini |
| 2 | Merge checklist QA + laporan ini ke `main` | Checklist di `main` `edd8206e`; pembaruan A5 + laporan ini masuk bersama CR-111 (izin Horii 10-11-2026) |

Tidak ada pertanyaan ⛔ Fase 6 lain yang terbuka.

## 8. Blocker deploy / go-live (bukan blocker sign-off Fase 6)

1. **Uji endpoint real** (F6-23): e-sign cuti BSrE di jaringan internal (minimal), SIASN, e-Kinerja BKN, FCM — MT-010 dan
   DEPLOY-001.1. Termasuk uji SPA + service worker push web dengan proyek Firebase (K2 checklist).
2. **Prasyarat non-teknis PRD §0.2:** PN-1 akun & akses BSrE; PN-2 akun SIASN/e-Kinerja dan token SSO produksi (Q6-08);
   PN-3 proyek Firebase legacy + service account FCM dan penulis token Android sesudah cutover (Q6-12); PN-4 daftar klien
   SIMAPI/MuDig + jadwal re-handshake (rahasia klien baru, header `Authorization`, kontrak `s_esign`); PN-5 crontab server
   legacy untuk mencocokkan jadwal command (README-deploy §1e, §1j, ADR-037).
3. **Cutover:** antrean `siasn_upload_arsip` wajib diimpor semua status (cegah unggah ulang ke BKN); klien SIMAPI menarik
   antrean `change_history`/`pegawai_update` legacy sampai habis sebelum freeze (Q6-42 opsi a); `integrasi:siapkan-akun-sistem`.
4. **Konfigurasi server:** allowlist IP klien memuat alamat sumber sebenarnya — IPv6, IP di balik Nginx/proxy, `real_ip`
   (T3, runbook §1h); ambang throttle auth antar-sistem (120 kegagalan/IP, 600/klien per 60 detik) disetel dari trafik
   nyata; TLS BSrE dinaikkan ke verifikasi penuh bila CA tersedia (`esign.caBundle`, Q6-18).
5. **Konfirmasi PIC:** `Ekinerja.php` (API masuk) tetap tidak di-port kecuali PIC menyatakan masih dipakai (Q6-14).

## 9. Setelah sign-off

- Sinkron Trello DEV-007 + QASMTASK-089..097 + QASMTASK-120..122 (H-10/H-11/H-12), ISSUE-055..057, kartu K1 di fase
  berikutnya; komentar penutup QASMTASK-097 dari tabel pemetaan H-09 (progres A7). Notion baris Fase 6 → selesai.
- Bersih-bersih worktree dan DB scratch Fase 6 (izin Horii 10-11-2026, hanya sesudah sign-off).

**Terlaksana 10-11-2026:** Trello DEV-007, QASMTASK-089..097, QASMTASK-120..122, ISSUE-055, ISSUE-056 → Done (butir akses/uji endpoint real, "Lolos MTC-0xx", dan `record_geolocation` sengaja tidak dicentang — alasan di baris Status kartu); kartu baru ISSUE-058 (deviasi K1, Todo); komentar blocker deploy §8 di MT-010 dan DEPLOY-001.1. Notion baris H.1–H.4 Selesai, Ringkasan Fase 6 dan Konteks per 10-11-2026. Worktree dan DB scratch Fase 6 dihapus.
