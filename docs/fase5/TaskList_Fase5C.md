# Task List Fase 5C — pembagian paket untuk beberapa coder (SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase5/TaskList_Fase5C.md` — pasangan `docs/fase5/PRD_Fase5C_FE-Laporan-Regresi-SignOff.md` (PRD menang bila bertentangan) |
| Versi | **v1.0 — 29-10-2026** (reviewer CR, disetujui Horii) — berlaku bersama PRD 5C v1.0 |
| Basis | `origin/main` = `69be4c3` |
| Cara ambil paket | Konfirmasi ke reviewer CR (nama coder + paket) sebelum mulai; satu coder = satu paket aktif; satu worktree + satu DB scratch per paket |

## Alur umum per paket (WAJIB)

1. Baca PRD 5C seluruhnya + bagian PRD 5B yang dirujuk paket → kirim **laporan C0** singkat (sekali per coder, PRD §10).
2. Branch dari `origin/main` terbaru: `fase5c/make-0xx-<ringkas>`; worktree sendiri; `npm ci` / `composer install` di worktree.
3. Kerjakan hanya berkas di kolom "Boleh diubah"; berkas bersama = **aditif, commit terpisah**.
4. Gate cepat lokal → push → CI hijau → lapor reviewer: SHA, run CI, ringkasan, keputusan/deviasi, hal yang belum (lapor juga bila CI masih berjalan).
5. ⛔ Tunggu review & merge reviewer CR (izin Horii per paket). Perbaikan review = commit baru (tanpa force).

Larangan: membuka `constants.php` legacy; commit rahasia/data nyata; menyentuh backend `app/**` (kecuali disebut); mengubah
`ACTIVE_PHASE`/`rilis` (kecuali MAKE-053); menebak hal ambigu (eskalasi).

## Peta paket & ketergantungan

```
C0 laporan pemahaman
 └─ MAKE-047 Fondasi FE laporan ─┬─ MAKE-048 Laporan Tukin ──┐   (tunggu MAKE-041 di main)
                                 ├─ MAKE-049 Rekap Tukin ────┤   (tunggu MAKE-041 di main)
                                 ├─ MAKE-050 Penyesuaian ────┤
                                 └─ MAKE-051 Rekap Kehadiran & Uang Makan ┤
 MAKE-052 Regression D-13 (BE, paralel sejak C0) ─────────────┤
                                                             └─ MAKE-053 Penutup (ACTIVE_PHASE 5) ─ MAKE-054 Verifikasi ─ ⛔ sign-off Horii
```

Paralel maksimal: **3 coder** sesudah MAKE-047 (FE-A: 048+049, FE-B: 050+051, BE: 052 sejak C0). Map picker tidak dikoding (Q5C-01 = SVG 5A).

## Daftar paket

| No | Key | Paket | Coder | Boleh diubah | Menunggu | Selesai bila | Status |
|---|---|---|---|---|---|---|---|
| 0 | — | C0 laporan pemahaman | semua | — (tanpa commit) | — (PRD 5C v1.0 sudah di `main`) | Reviewer menerima laporan C0 | Todo |
| 1 | MAKE-047 | Fondasi FE laporan | FE-A | `types.laporan.ts`, `composables/useFilterLaporan.ts`, `statusPresensi.ts`, `components/{StatusPresensiChip,LegendaStatusPresensi,FilterLaporanBar}.vue`, util unduh blob (bila belum ada di `shared`), test | C0 | AC-FE5C.2/3/7 untuk komponen fondasi; Vitest + test keyboard/ARIA; tanpa halaman baru | Todo |
| 2 | MAKE-048 | Laporan Tukin: daftar, IL, per pegawai, "Saya", unduh PDF | FE-A | `views/LaporanTukin*Page.vue`, `services/laporanTukin.service.ts` (+ amplop spec), route + role + nav (aditif) | MAKE-047; **MAKE-041 di `main`** | PRD §7.3; AC-FE5C.1–5, 7, 8 | Todo |
| 3 | MAKE-049 | Rekap Tukin + IL + ekspor XLSX/PDF | FE-A | `views/RekapTukin*Page.vue`, service rekap (file sama MAKE-048, aditif), route + nav | MAKE-047; MAKE-041 di `main` | PRD §7.4; AC-FE5C.1–5 | Todo |
| 4 | MAKE-050 | Penyesuaian Tukin (CRUD role 1) | FE-B | `views/PenyesuaianTukinPage.vue`, `components/PenyesuaianTukinFormDialog.vue`, `services/penyesuaianTukin.service.ts`, route + nav | MAKE-047 (MAKE-045 sudah di `main`) | PRD §7.4; AC-FE5C.6; AGENTS §1 (⋮ Edit → Hapus) | Todo |
| 5 | MAKE-051 | Rekap Kehadiran (+XLSX ringkas/harian) & Rekap Uang Makan (+IL) | FE-B | `views/RekapKehadiranPage.vue`, `views/RekapUangMakanPage.vue`, `services/rekapKehadiran.service.ts`, route + nav | MAKE-047 (MAKE-042 sudah di `main`) | PRD §7.5; AC-FE5C.1–5 | Todo |
| 6 | MAKE-052 | Regression suite D-13 (12 skenario) | BE | `backend/tests/Presensi/RegresiD13/**`, `composer.json` script `test:d13` (aditif) | C0 | PRD §7.6; AC-D13.1; DB scratch 3307 sendiri | Todo |
| 7 | MAKE-053 | Penutup: `ACTIVE_PHASE` 4→5, hapus `rilis`, README/progres | FE-A | `shared/layouts/nav.config.ts` + test, README FE, `05-Presensi.md` | MAKE-047..051 di `main` | AC-5C.X1; gate penuh `./check.sh` | Todo |
| 8 | MAKE-054 | Verifikasi 5C + QA Lapis 1 + paritas UP-1 + laporan akhir Fase 5 | Reviewer CR (+ coder bantu dokumen) | `docs/fase5/Checklist_QA_Lapis1_Fase5C.md`, `docs/fase5/Laporan_Akhir_Fase5.md`, `05-Presensi.md` | MAKE-053; MAKE-043 (5B) selesai; Q5C-06 ✅ | AC-5C.X2 → ⛔ **sign-off Horii Fase 5** | Todo |

## Checklist laporan serah per paket (salin ke pesan ke reviewer)

- [ ] Key + branch + SHA + run CI (status)
- [ ] Berkas yang diubah (sesuai kolom "Boleh diubah"; berkas bersama di commit terpisah)
- [ ] AC yang dipenuhi + bukti (nama test / tangkapan layar 1440/1024/390 untuk FE)
- [ ] Deviasi [V2] / keputusan coder yang perlu disetujui
- [ ] Hal yang belum / pertanyaan terbuka
- [ ] Konfirmasi: tanpa data nyata, tanpa rahasia, tanpa penyebutan AI/tool, tanpa `constants.php`

## Status keputusan yang memblokir

| ID | Memblokir | Status |
|---|---|---|
| Q5C-01 map picker | — | ✅ Horii 29-10: tetap `IndikatorRadius` SVG 5A |
| Q5C-06 paritas UP-1 data nyata | MAKE-054 | ✅ Horii 29-10: 2 periode terakhir, reviewer + DBA, salinan data yang disetujui, hasil tanpa data pribadi |
| Q5C-02/03/04/05/07/08 | — (default berlaku) | ◻ |
| MAKE-041 di `main` | MAKE-048, MAKE-049 | Menunggu perbaikan kop G1/G2 + izin merge |
| MAKE-043 verifikasi 5B | MAKE-054 | Belum mulai |
