# Fase 3 — Modul B Kepegawaian Core: pintu masuk coder

Setiap coder cukup membaca **satu berkas** dan menjalankannya dari atas. Urutan perintah:

| Urutan | Coder | Perintah | Kapan |
|---|---|---|---|
| 1 | Qoder-1 | `Kerjakan docs/fase3/SPRINT0_1_backend.md` | Sekarang (Sprint 0-1, kontrak backend, MAKE-002) |
| 2 | Qoder-2 | `Kerjakan docs/fase3/SPRINT0_2_frontend.md` | Setelah Sprint 0-1 di-merge (langkah bertanda [boleh duluan] boleh dimulai lebih awal) |
| 3a | Qoder-1 | `Kerjakan docs/fase3/WS1.md` | Setelah MAKE-002 **dan** MAKE-003 di `main` |
| 3b | Qoder-2 | `Kerjakan docs/fase3/WS2.md` | Setelah MAKE-002 **dan** MAKE-003 di `main` (paralel dengan 3a) |

Setiap berkas berisi runbook bernomor dengan **⛔ TITIK TUNGGU** (berhenti sampai syarat terpenuhi), briefing/PRD
lengkap, dan cara serah (push branch `ws1/…`/`ws2/…` ke `origin`, bukan `main`; lapor SHA + tautan run CI).

## Tabel tunggu ringkas

| No | Item | Key | Dikerjakan | Menunggu | Membuka jalan |
|---|---|---|---|---|---|
| 1 | PHPUnit cepat + MySQL test (base case) | MAKE-001 | Sesi utama | — | Fixture Sprint 0-1 |
| 2 | FK G-02 ↔ pegawai/riwayat (PR #23) | DBV-019 | Sesi utama → review DB Validator | Review DB Validator | Fixture patuh FK sejak awal (tidak menunggu merge) |
| 3 | Sprint 0-1 kontrak backend | MAKE-002 | Qoder-1, ±1 hari-agen | Fixture menunggu MAKE-001 | Sprint 0-2 |
| 4 | Review CR + CI hijau + merge S0-A → kontrak backend beku | MAKE-002 | Sesi utama, ±1–2 jam setelah diserahkan | No 3 | No 5 |
| 5 | Sprint 0-2 fondasi frontend | MAKE-003 | Qoder-2, ±1,5 hari-agen | S0-A di `main` | No 6 |
| 6 | Review CR + CI hijau + merge S0-B → fondasi frontend beku | MAKE-003 | Sesi utama, ±1–2 jam setelah diserahkan | No 5 | No 7 |
| 7 | WS-1 (M1–M5) dan WS-2 (M1–M6) paralel | MAKE-004..008 / MAKE-009..014 | Qoder-1 / Qoder-2 | MAKE-002 **dan** MAKE-003 di `main` | Fase 3 selesai |

Istilah: **hari-agen** = beban kerja satu sesi coder (relatif, bukan tanggal); **fixture** = data contoh untuk test;
**kontrak beku** = setelah merge, interface/API/route/registry bersama hanya boleh ditambah (aditif) oleh pemiliknya.

## Reviewer

- **Reviewer CR = sesi utama**: mereview setiap paket (`MAKE-###`), memantau CI `quality-gate`, memasukkan paket ke
  `main`, dan menetapkan key `CR-###` untuk perbaikan hasil review/QA.
- **DB Validator**: hanya untuk perubahan skema (`DBV-###`, mis. DBV-019). Coder tidak membuat migration; kebutuhan
  skema dicatat di laporan serah.

## Berkas lain di folder ini

| Berkas | Isi |
|---|---|
| `MATRIKS_ROLE_MODUL_B.md` | Matriks role × endpoint Modul B (hak akses) |
| `03-Kepegawaian.md` | Kontrak task Fase 3 (B-01..B-21) |
| `../adr/ADR-033-library-pdf-tcpdf.md` | Library PDF TCPDF (LKH, Cetak DRH) |
