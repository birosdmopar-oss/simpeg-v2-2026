# Task List Fase 5 — pelacakan & pembagian paralel (29-10-2026)

Basis: `origin/main` = `9968dc0`. Acuan: PRD 5A v1.1, PRD 5B v1.0, PRD 5C v1.0 + `docs/fase5/TaskList_Fase5C.md`.
Nama pelaksana netral: **Coder A–D** = sesi coder, **Reviewer** = reviewer CR, **Horii** = pengambil keputusan & sign-off.

## 1. Ringkasan status

| Sub-fase | Status | Bukti |
|---|---|---|
| 5A (skema, slip gaji, presensi, FE 5A) | ✅ SIGN-OFF reviewer 28-10 | M1–M8 di `main` (`54cb441`), DBV-023 |
| 5B (cuti/TB Tukin, laporan, rekap, uang makan) | ⏳ M1–M6 di `main`, M7 berjalan | MAKE-039/040/041/042/044/045 + DBV-024 (`9968dc0`) |
| 5C (FE laporan, D-13, penutup) | ⏳ Siap dikerjakan | PRD 5C v1.0 `d9ac173` |
| Fase 5 DONE | ⛔ Setelah 5B M7 + 5C + sign-off Horii | — |

## 2. Daftar task (semua pelaksana)

| No | Task | Key | Pelaksana | Menunggu | Status | Izin merge |
|---|---|---|---|---|---|---|
| 1 | 5B M7 verifikasi 5B + prosedur paritas UP-1 | MAKE-043 | Coder C | — | ⏳ Dikerjakan | Horii |
| 2 | 5C C0 laporan pemahaman | — | Coder A, B, C | — | Todo | — |
| 3 | Fondasi FE laporan (filter, status, legenda, unduh) | MAKE-047 | Coder A | C0 | Todo | Horii |
| 4 | Laporan Tukin (daftar, IL, per pegawai, Saya, PDF) | MAKE-048 | Coder A | MAKE-047 | Todo | Horii |
| 5 | Rekap Tukin (+IL, XLSX/PDF) | MAKE-049 | Coder A | MAKE-047 (bisa setelah/paralel 048) | Todo | Horii |
| 6 | Penyesuaian Tukin (CRUD role 1) | MAKE-050 | Coder B | MAKE-047 | Todo | Horii |
| 7 | Rekap Kehadiran (+XLSX) & Rekap Uang Makan (+IL) | MAKE-051 | Coder B | MAKE-047 | Todo | Horii |
| 8 | Regression suite D-13 (12 skenario, backend) | MAKE-052 | Coder C | MAKE-043 dilaporkan (DB scratch dipakai ulang) | Todo | Horii |
| 9 | Draf Checklist QA Lapis 1 5C (`docs/fase5/Checklist_QA_Lapis1_Fase5C.md`) | (bagian MAKE-054) | Coder D | PRD 5C | Todo | Reviewer |
| 10 | Cadangan CR backend: bug API 5B yang ditemukan FE 5C | CR-075+ | Coder D | Temuan dari Coder A/B | Siaga | Horii |
| 11 | Penutup: `ACTIVE_PHASE` 4→5, hapus `rilis`, README/progres | MAKE-053 | Coder A | MAKE-047..051 di `main` | Todo | Horii |
| 12 | QA Lapis 1 5C di app demo | (MAKE-054) | Reviewer | MAKE-053 | Todo | — |
| 13 | Uji paritas UP-1 data nyata (2 periode terakhir, bersama DBA) | (MAKE-054) | Reviewer + DBA | MAKE-043 (prosedur) | Todo | — |
| 14 | Verifikasi 5C + laporan akhir Fase 5 | MAKE-054 | Reviewer (+ Coder D dokumen) | No. 11–13 | Todo | Horii |
| 15 | Sign-off Fase 5 | — | Horii | No. 14 | ⛔ | — |
| 16 | Sinkron Trello DEV-006 / QASMTASK-076..088 + Notion | — | Reviewer | Tiap merge / sign-off | Berjalan | — |

## 3. Jalur paralel yang disarankan

```
Coder A : C0 → MAKE-047 → MAKE-048 → MAKE-049 ─────────────→ MAKE-053
Coder B : C0 ───(tunggu 047)→ MAKE-050 → MAKE-051 ──┘
Coder C : MAKE-043 → C0 → MAKE-052
Coder D : Draf checklist QA 5C ‖ siaga CR-075+ (bug API dari A/B)
Reviewer: review tiap paket → QA Lapis 1 + UP-1 → MAKE-054 → sign-off Horii
```

- **Puncak paralel:** 4 coder (A, B, C, D) setelah MAKE-047 masuk `main`.
- **Jalur kritis:** MAKE-047 → 048/049 → 053 → QA Lapis 1 → 054. MAKE-047 diprioritaskan, review dipercepat.

## 4. Aturan koordinasi paralel (satu mesin)

- **Satu `./check.sh` penuh per mesin pada satu waktu.** Gate cepat per paket boleh. Gate penuh hanya MAKE-053 dan merge ke `main`.
- **Satu worktree per coder**, dipakai ulang antar paket. Disk tersisa ±22 GB.
- **DB scratch:** hanya Coder C (port 3307, DB scratch 5B dipakai ulang untuk MAKE-052). Paket FE tidak memakai DB.
- **Berkas bersama** (`routes.presensi.ts`, `roles.ts`, `nav.config.ts`, README) diubah **aditif dengan commit terpisah**. Yang merge belakangan menggabungkan `origin/main`.
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
| UP-1 / F5B-9 | Paritas Tukin data nyata | 5C MAKE-054 |
