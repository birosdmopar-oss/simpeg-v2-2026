# Progress Fase 5 — Modul D: Presensi & Remunerasi

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md dan PRD 5A §11.3). **Acuan utama paket 5A:**
`docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` v1.0 (BERLAKU 19-10-2026; menang atas `05-Presensi.md`, Matriks, dan
README modul). Aturan Slip Gaji (sumber legacy, hak akses): `backend/app/Libraries/Presensi/README.md`.

**Status:** paket **5A berjalan** (paralel dengan Fase 4, P-1). 5B/5C belum dimulai. 5A DONE ≠ Fase 5 DONE (PRD 5A §11.2).

**Terakhir diperbarui:** 27 Oktober 2026 — **M2 DBV-023** dikerjakan di branch PR #28 setelah DBV-022 masuk `main`
`598a5f3`: migration aktif `2026-10-27-100000/100100`, registry NIP Fase 5, test skema (bagian "5A M2"); menunggu CI +
merge reviewer CR. Sebelumnya: DBV-023 **disetujui DB Validator 20-10-2026 (jjoseph48)** (PR #28); ISSUE-049 (Q5A-02)
ditutup keputusan user 20-10-2026. Sebelumnya M1 DBV-023 (branch `fase5a/dbv-023-skema-presensi-gaji`,
dari `main` `6806c38`): dokumen DBV-023 + kueri audit + draf migration (ditahan). M0 (laporan
pemahaman, tanpa commit) diterima reviewer CR 20-10-2026. Sebelumnya: CR-039/CR-037/CR-027 (2 Oktober 2026, persiapan
tanpa skema, bagian bawah).
**Entry criteria:** Fase 2 (web_config) + Fase 3 (data jabatan) DONE. "Fase 4 DONE" **dikecualikan** untuk 5A (P-1);
diganti syarat "DBV-022 ada di `main`" untuk migration 5A (PRD 5A §3.5). 5B tetap menunggu Fase 4 M3/M5.

| Task | Paket | Milestone | Key | Status | Ringkas |
|---|---|---|---|---|---|
| D-01 Migration tabel presensi & gaji | 5A | M1/M2 | DBV-023 | **IN_PROGRESS** | M1: dokumen + kueri audit + draf migration di `MigrationsDitahan/` (Bagian "5A M1" di bawah). M2 (27-10-2026): migration aktif + registry NIP Fase 5 + test skema (bagian "5A M2"); syarat DBV approve ✅ 20-10, DBV-022 di `main` ✅ `598a5f3`, ISSUE-049 ✅; menunggu CI + merge |
| D-02 Presensi online + geofence | 5A | M5 | MAKE-032 | TODO | Logika murni `Libraries/Presensi/Geo` boleh disiapkan sebelum M2 (§3.5) |
| D-03 Presensi manual | 5A | M5 | MAKE-032 | TODO | Menunggu M2 |
| D-04..D-07 Kalkulasi Tukin | 5A (adapter) / 5B | M6 | MAKE-033 | **IN_PROGRESS** | Logika murni CR-037/CR-039 selesai; adapter `TukinData` 5A (non-skema boleh mulai, merge setelah M2); precedence/laporan di 5B |
| D-08 Laporan Tukin, D-09 rekap & uang makan | 5B | — | — | TODO | Di luar 5A |
| D-10 Slip Gaji: impor Excel | 5A | M3 | MAKE-030 | TODO | Logika murni CR-027 tersedia; menunggu M2 |
| D-11 Slip Gaji: akses & kelola admin | 5A | M4 | MAKE-031 | TODO | Menunggu M3 |
| D-12 Frontend | 5A (Presensi & Slip Gaji) / 5C | M7 | MAKE-034 | TODO | |
| D-13 Unit test lengkap | 5A (bagian) / 5C | M8 | MAKE-035 | **IN_PROGRESS** | Bagian murni CR-037/CR-027; regression suite lengkap di 5C |
| D-14 Verifikasi QR publik | 5A | M4 | MAKE-031 | TODO | Kartu QASMTASK baru (F5-20) |

**Blocker go-live (bukan blocker 5A DONE, PRD 5A §11.2):** nilai uang makan slip (5B, F5-1); konfirmasi Biro Keuangan
atas data verifikasi AS-06 (Q5A-08); checksum kode produksi (Q5A-14); pengecekan ulang data `gaji_pegawai` produksi (DEPLOY-002.1; Q5A-02 ditutup ISSUE-049).

## 5A M1 — DBV-023 dokumen skema (D-01)

- **Sudah:** `backend/docs/db-review/DBV-023-presensi-gaji-fase5a-schema.md` (status "Menunggu review DB Validator"),
  `backend/docs/db-review/sql/DBV-023-audit-presensi-gaji.sql`, draf `app/Database/MigrationsDitahan/CreatePresensiGaji.php`
  dan `AlterAuditLogsEventPresensi.php` (tidak dijalankan `spark migrate`). Verifikasi developer MySQL 8.0.30 di DB
  scratch `simpeg_v2_t_ws2_dbv023`: migrate/rollback/migrate dengan checksum identik, 32 uji constraint, `down()` ENUM
  fail-closed, sintaks skrip audit di DDL D1 (dokumen Bagian 10).
- **Keputusan reviewer CR 20-10-2026 (M0):** key DBV-023/MAKE-030..035; DB scratch `simpeg_v2_t_ws2_<milestone>`;
  `down()` ENUM fail-closed (berbeda dari pola `2026-09-17-000004`); kunci enkripsi test lewat `config()` di test (tanpa
  ubah workflow CI); ekstensi PHP CI untuk PhpSpreadsheet diverifikasi di M3.
- **Persetujuan DB Validator:** ✅ 20-10-2026 (jjoseph48, komentar PR #28; verifikasi MariaDB 10.4.27). Keputusan
  DBV-Q1..Q18 di dokumen Bagian 9, catatan C-1..C-4 di Bagian 14. Perapian yang diminta: C-1 catatan jumlah CHECK
  MariaDB, C-2 kueri prasyarat `BERHENTI:` di skrip audit, C-4 + daftar tabel tidak dimigrasi (DBV-Q7) di runbook DBV-011
  2.11.
- **ISSUE-049 (Q5A-02) ditutup keputusan user 20-10-2026:** fitur Slip Gaji legacy belum masuk DB produksi (deployment
  produksi ditahan) → histori `gaji_pegawai` produksi tidak ada (F5-2 tetap berlaku bila kelak ada data); berkas
  `import_slip_gaji_uji_2026-01_s.d_09.xlsx` = data fiktif (1 NIP, Jan–Sep 2026, GPP + TK, 28 kolom); isi `gaji_pegawai`
  v2 dari unggah Excel, uang makan dari presensi; `gaji_pegawai_qr_history` menjaga QR lama tetap valid (perbaikan bug
  legacy reset admin); B2 (STOP) tidak berlaku; DBV-Q5/A2: baris tanpa jam dibersihkan saat impor, CHECK
  `chk_dh_online_jam` tetap. Pengecekan ulang produksi → kartu DEPLOY-002.1. Dokumen DBV-023 Bagian 7.2/9/12/13.
- **Blocker M1 terbuka:** DBV-022 masuk `main` `598a5f3` (PR #29, 27-10-2026) — semua syarat M2 terpenuhi.
- **Tanggal persetujuan DB Validator:** 20-10-2026 (jjoseph48, PR #28; AC-D01.6).
- **Tindak lanjut kode (C-4):** di M5 kode presensi online/manual wajib selalu mengisi `dh_online.sumber` (1 online,
  2 manual) + test; NULL hanya baris impor legacy.

## 5A M2 — DBV-023 migration aktif, registry NIP, test skema (D-01)

- **Sudah (27-10-2026, branch `fase5a/dbv-023-skema-presensi-gaji`):** `origin/main` `598a5f3` digabung lewat merge
  (tanpa rebase/force push). Draf dipindah ke `app/Database/Migrations/2026-10-27-100000_CreatePresensiGaji.php` dan
  `2026-10-27-100100_AlterAuditLogsEventPresensi.php` (DDL tidak berubah; > migration Modul C `2026-10-26-1008xx`).
  `NipCascadeService::FASE` = 5; `gaji_pegawai.nip` di `TIDAK_DIARAHKAN` (slip lama tetap ber-NIP lama [K]);
  `NipReferenceRegistryTest` + `TabelAnakNipTest` + `NipCascadeTest` diperbarui. Test skema baru
  `tests/database/Presensi/*` + `tests/_support/Presensi/SkemaPresensiGaji.php`. Test lama disesuaikan:
  `LokasiPresensiSchemaTest` (FK `dh_online` → `lokasi_presensi`) dan `AuthCollationMigrationTest` (ENUM `event`).
- **Verifikasi MySQL 8.0.30 (3307):** `main` 120 tabel / 235 FK / 84 CHECK → + DBV-023 123 / 239 / 89; rollback kembali
  120 / 235 / 84 dan ENUM 5 nilai; migrate ulang checksum identik (dokumen DBV-023 Bagian 15).
- **Berikutnya:** CI hijau + `./check.sh` penuh → lapor reviewer CR → merge oleh reviewer (izin Horii). Setelah M2 di
  `main`: sambungkan kode non-skema MAKE-030..033 sesuai urutan PRD 5A (M3 impor → M4 akses/verifikasi → M5 presensi →
  M6 adapter Tukin).

### Q cutover 5A

| # | Butir | Asal | Status |
|---|---|---|---|
| QC-1 | Uji koreksi NIP (`NipCascadeService`) pada volume `dh_online` realistis + urutan induk/anak di bawah FK RESTRICT | DBV-023 C-3 (DB Validator 20-10-2026) | ⏳ pra-cutover |

## Persiapan tanpa skema

### CR-037 — Kalkulasi Tukin murni (bagian D-04..D-07/D-13)

CR-037 menyelesaikan fondasi logika murni periode, hari kerja, jam kerja, precedence status, klasifikasi potongan, dan agregasi Tukin beserta unit test sintetis. Tabel presensi, cuti, tugas belajar, konket, adapter `web_config`, controller, dan laporan tetap menunggu fase terkait. Rincian aturan dan perbedaan [V2]: `app/Libraries/Presensi/Tukin/README.md`.

### CR-039 — Tukin ikut jalur aktif legacy; Slip Gaji terjawab dari legacy

CR-037 mengacu `laporan_tukin` (:3628), yang ternyata jalur lama (≤ Maret 2024). Jalur aktif adalah `laporan_tukin_skp` (per pegawai) dan rekap `laporan_tukin_us_skp` (Excel pembayaran); keputusan user 02-10-2026 (K-1): bila keduanya berbeda, v2 ikut rekap. CR-039 menyelaraskan library ke rekap (aturan dan rujukan baris di README Tukin) dan menambah input `DataPegawaiTukin` (predikat SKP, jumlah anak, TMT masuk/keluar). Deviasi [V2]: hari ≥ TMT keluar dikecualikan (bug rekap), cuti sepanjang periode dihitung atas periode tukin. Tidak ada lagi pertanyaan rumus untuk Biro SDM/Keuangan.

Slip Gaji (CR-027): opsi timpa impor bawaan tercentang (`PratinjauImpor::TIMPA_BAWAAN`), `RingkasanSlip::periksaKonsistensi` dihapus (legacy tidak punya cek invarian), format waktu dibuka "4 Oktober 2026, 10:00 WIB" mengikuti halaman pegawai legacy, dan butir menunggu Biro Keuangan/user dipindah ke "Terjawab dari legacy" di README modul. Cakupan role 3 per satker berasal dari PRD SG-2 (legacy tidak membatasi).

### CR-027 — Slip Gaji: dokumen perilaku + logika murni (bagian D-10/D-11/D-13) — pra-review internal sisi CR

Key review **CR-027** (CR saja). Tidak mengubah skema, jadi tidak ada dokumen `docs/db-review/` dan tidak perlu review DB Validator. Tanpa migration, controller, endpoint, FE, atau dependensi composer baru.

**Sudah jalan**
- `app/Libraries/Presensi/SlipGaji/` (namespace `App\Libraries\Presensi\SlipGaji`), 15 kelas murni: `HasilSlip`, `PeriodeSlip`, `GerbangPeriode`, `NominalGaji`, `KolomGaji`, `NilaiSel`, `BarisImpor`, `BarisSheet`, `PratinjauImpor`, `PencocokanNama`, `RingkasanSlip`, `StatusSlip`, `TokenQr`, `AksesSlip`, `FormatSlip`.
- Aturan legacy `39b6b15` (`libraries/hr/Lsl_gaji.php`, `controllers/hr/Sl_gaji.php`, view `sl_gaji/*`, `verify/slip.php`) dipetakan ke [K]/[V2] dengan keputusan IKUT/PERBAIKI di README modul (butir TUNGGU terjawab dari legacy di CR-039), termasuk perbaikan bug salah baca angka (×10 pada float bertitik, ×100 pada form koreksi), NIP numerik yang kehilangan presisi, dan kolom/filter yang di legacy diabaikan diam-diam.
- Hak akses ikut legacy (keputusan user 01-10-2026): pegawai 2/6/7 slip milik sendiri, admin 1 semua, admin 3 hanya satker sendiri (fail-closed), role 4/5/8 tanpa akses; deviasi dari Matriks/FSD/05-Presensi dicatat di README.
- Test `tests/unit/Presensi/SlipGaji/` (tanpa DB, data sintetis): `GerbangPeriodeTest`, `PeriodeSlipTest`, `NominalGajiTest`, `KolomGajiTest`, `BarisImporTest`, `PratinjauImporTest`, `PencocokanNamaTest`, `RingkasanSlipTest`, `StatusSlipTest`, `TokenQrTest`, `AksesSlipTest`, `FormatSlipTest`, `HasilSlipTest`, dan penjaga arsitektur `SlipGajiMurniTest` (tanpa DB/HTTP/session/jam/acak/I/O; semua `Time::`, `::createFromFormat`, dan `hariIni()` tanpa argumen dilarang; nama fungsi berawalan \ juga terdeteksi). 149 test, 909 assertion.
- Uji mutasi: 27 mutasi pada kode Slip Gaji (batas inklusif rilis, hari/jam rilis, zona WIB, periode awal, rumus total, toleransi float, batas digit, pembulatan rupiah, NBSP, NIP teks, kolom wajib, baris ganda, nama per sheet, cakupan satker, prioritas status, pola token/periode) semuanya membuat test gagal. Putaran review mandiri: 41 mutasi tambahan (batas bulan 1/12 dan tahun 1900/2100, filter periode, prioritas status, header/sheet wajib, rencana commit, penjaga int sen, jam sebelum periode awal, pemindai kemurnian); 38 membuat test gagal setelah test batas ditambah, 3 sisanya ekuivalen (penjaga luapan cast float, batas digit yang juga dijaga batas sen, panjang NIP pada urutan yang sudah numerik).

**Belum / di luar CR-027** (rincian di README modul, bagian "Menunggu Fase 5")
- D-01: skema `gaji_pegawai` (28 kolom nominal), tabel riwayat token, volume histori, konversi stempel WIB → UTC (DB Validator).
- D-10: pembaca berkas PhpSpreadsheet nilai mentah, unggah aman, pratinjau di server, commit per baris dengan tangkapan UNIQUE, log ketidakcocokan nama, template.
- D-11: endpoint daftar/status/buka (atomik)/cetak PDF/koreksi/buka ulang, verifikasi publik `/verify/slip/{token}`, generator QR, tukin kelas jabatan dan uang makan (D-08/D-09).
- D-12 FE (menu ⋮, `ConfirmDialog` untuk buka ulang) dan D-13 test integrasi di `tests/Presensi/`.

**Keputusan**
- Butir Biro Keuangan dan user/reviewer CR terjawab dari kode legacy (CR-039; README modul, bagian "Terjawab dari legacy").
- U-8 selesai di CR-040: pemindai kemurnian bersama `tests/_support/Libraries/PemindaiKemurnian.php` (daftar larangan gabungan ketiga penjaga, tanpa pelonggaran) dipakai `SlipGajiMurniTest`, `KalkulasiMurniTest` Tukin, dan `KalkulasiMurniTest` Kepegawaian.

**Catatan lokasi test:** kontrak D-13 menyebut `tests/Presensi/`. Test logika murni ditaruh di `tests/unit/Presensi/` mengikuti konvensi repo (sama dengan CR-025); test integrasi ber-DB D-13 tetap di `tests/Presensi/`.
