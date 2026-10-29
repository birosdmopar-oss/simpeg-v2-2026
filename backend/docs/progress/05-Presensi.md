# Progress Fase 5 — Modul D: Presensi & Remunerasi

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md dan PRD 5A §11.3). **Acuan utama paket 5A:**
`docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` v1.0 (BERLAKU 19-10-2026; menang atas `05-Presensi.md`, Matriks, dan
README modul). Aturan Slip Gaji (sumber legacy, hak akses): `backend/app/Libraries/Presensi/README.md`.

**Status:** paket **5A DONE (backend & dokumen)** — sign-off Horii 28-10-2026 (PRD 5A §11.1 #8) setelah M8 MAKE-035
masuk `main` `54cb441`. **FE 5A (D-12) tetap IN_PROGRESS** sampai QA Lapis 1 lolos (Q5A-18, AC-FE.9); blocker go-live
5A tetap berlaku (di bawah). **5B berjalan** (PRD 5B v1.0, sesi WS-2): M0 diterima reviewer CR 28-10-2026; M1a MAKE-044
di `main` `7c40229`. 5A DONE ≠ Fase 5 DONE (PRD 5A §11.2).

**Terakhir diperbarui:** 28 Oktober 2026 — status 5A pasca sign-off (commit docs di branch 5B pertama
`fase5b/dbv-024-penyesuaian-tukin`, keputusan reviewer CR M0 F4). Sebelumnya: M8 MAKE-035 di `main` `54cb441`; riwayat
merge 5A: M2 DBV-023 `e8d08dc`; M3–M6 s.d. `3a12909`; M7 (a) `5279b82`, (b) `5a3a35c`, (c) `919c822`; CR-067 `08b6c89`.
DBV-023 **disetujui DB Validator 20-10-2026 (jjoseph48)** (PR #28); ISSUE-049 (Q5A-02) ditutup keputusan user 20-10-2026.
**Entry criteria:** Fase 2 (web_config) + Fase 3 (data jabatan) DONE. "Fase 4 DONE" **dikecualikan** untuk 5A (P-1);
diganti syarat "DBV-022 ada di `main`" untuk migration 5A (PRD 5A §3.5). 5B tetap menunggu Fase 4 M3/M5.

| Task | Paket | Milestone | Key | Status | Ringkas |
|---|---|---|---|---|---|
| D-01 Migration tabel presensi & gaji | 5A | M1/M2 | DBV-023 | **DONE** (sign-off 5A 28-10) | Di `main` (`e8d08dc`); DBV approve 20-10; registry NIP & cascade hijau; AC-D01 → laporan M8 |
| D-02 Presensi online + geofence | 5A | M5 | MAKE-032 | **DONE** (sign-off 5A 28-10) | Di `main`; AC-D02.1–02.10 berbukti test (laporan M8); GPS perangkat nyata di QA (Q5A-22) |
| D-03 Presensi manual | 5A | M5 | MAKE-032 | **DONE** (sign-off 5A 28-10) | Di `main`; AC-D03.1–03.8 berbukti test, termasuk jalur tangkap 1062 (M8) |
| D-04..D-07 Kalkulasi Tukin | 5A (adapter) / 5B | M6 | MAKE-033 | **IN_PROGRESS** | Murni CR-037/039 + adapter `TukinData` 5A di `main`; sumber cuti/TB, precedence, laporan di 5B (PRD 5B) |
| D-08 Laporan Tukin, D-09 rekap & uang makan | 5B | M2/M5/M6 | MAKE-040/041/042 | TODO | PRD 5B v1.0; M0 diterima 28-10; urutan M3 DBV-024 → M1 → M2 → M6 → M4 → M5 → M7 |
| D-10 Slip Gaji: impor Excel | 5A | M3 | MAKE-030 | **DONE** (sign-off 5A 28-10) | Di `main`; AC-D10.1–10.10 berbukti test |
| D-11 Slip Gaji: akses & kelola admin | 5A | M4 | MAKE-031 | **DONE** (sign-off 5A 28-10) | Di `main`; AC-D11.1–11.11 berbukti test; acuan slip `0cf7e67` (CR-067) |
| D-12 Frontend | 5A (Presensi & Slip Gaji) / 5C | M7 | MAKE-034 | **IN_PROGRESS** (sampai QA Lapis 1 lolos) | FE 5A (a/b/c) di `main` `919c822`; AC-FE.1–FE.8 berbukti Vitest + cek browser; AC-FE.9 = QA Lapis 1 (Q5A-18) |
| D-13 Unit test lengkap | 5A (bagian) / 5C | M8 | MAKE-035 | **IN_PROGRESS** | Bagian 5A lengkap (laporan M8); regression suite D-13 penuh di 5C |
| D-14 Verifikasi QR publik | 5A | M4/M7 | MAKE-031/034 | **DONE** (sign-off 5A 28-10) | Di `main`; AC-D14.1–14.7 berbukti test; FE `/verify/slip/:token` |

**Blocker go-live (bukan blocker 5A DONE, PRD 5A §11.2):** nilai uang makan slip (5B, F5-1); konfirmasi Biro Keuangan
atas data verifikasi AS-06 (Q5A-08); checksum kode produksi (Q5A-14); pengecekan ulang data `gaji_pegawai` produksi
(DEPLOY-002.1; Q5A-02 ditutup ISSUE-049); deploy HTTPS + rute Nginx `/verify/slip/*` + `Config\App::$proxyIPs`
(geolokasi browser hanya di konteks aman; IP asli untuk throttle & `viewed_ip`, Q5A-22); nama `jenis_pegawai` id 6
produksi (Q5A-25); QA Lapis 1 FE 5A (Q5A-18).

## 5B M0 — laporan pemahaman PRD 5B (28-10-2026, tanpa commit)

Diterima reviewer CR 28-10-2026: F1 varian massal aditif `untukBanyak()` di `SumberHarianTukin` &
`SumberDataPegawaiTukin` (M1, deviasi §3.3 dicatat di laporan M1; test kesetaraan + hitung kueri); F2 sumber Plt blok tanda
tangan (status 1 tanpa Plh vs legacy tanpa saring status) **diajukan ke Horii** (hanya berdampak M5); F3 unit legacy '6'/'7'
= konstanta bernama `Config\Presensi` + QC cutover (tanpa key `web_config` baru); F4 pembaruan status 5A = commit docs di
branch 5B pertama; urutan M3 → M1 → M2 → M6 → M4 → M5 → M7; DB scratch tunggal 3307 `simpeg_v2_t_ws2_fase5b`. M1a MAKE-044
(TB massal Fase 4) sudah di `main` `7c40229`.

## 5B M3 — DBV-024 tabel `penyesuaian_tukin` (28-10-2026, disetujui DB Validator)

Branch `fase5b/dbv-024-penyesuaian-tukin` (dari `main` `988c1ed`), PR `[DBV-024]`. Isi: dokumen
`backend/docs/db-review/DBV-024-penyesuaian-tukin-schema.md` (DDL, deviasi, registry NIP, cutover tanpa impor, DBV-Q1..Q8),
kueri audit baca-saja `backend/docs/db-review/sql/DBV-024-audit-penyesuaian-tukin.sql`, draf migration **ditahan**
`app/Database/MigrationsDitahan/CreatePenyesuaianTukin.php`. Verifikasi developer di MySQL 8.0.30 (scratch 3307): DDL,
1452/1451/3819, EXPLAIN dua indeks, siklus `down()/up()`, skrip audit A/B (dokumen Bagian 8). Setelah approve (masih M3):
migration dipindah + registry NIP `TabelAnakNip` + `SkemaPenyesuaianTukinTest`. **⛔** M4 MAKE-045 menunggu DBV-024 di `main`.

**Disetujui DB Validator 28-10-2026** (PR #30; verifikasi MariaDB 10.4.27; DBV-Q1..Q8 disetujui, Q7 dengan syarat C-1/C-2).
Selesai M3: migration `2026-10-28-100000_CreatePenyesuaianTukin` (DDL tidak berubah; tiga draf lain di
`MigrationsDitahan` tetap), registry NIP `TabelAnakNip` (`NonFkFkV2`, fase 5, `D-08` — deviasi kecil dari baris
`FK_LEGACY` di dokumen, alasan di dokumen Bagian 4), `NipReferenceRegistryTest`/`TabelAnakNipTest` diperbarui,
`tests/database/Presensi/SkemaPenyesuaianTukinTest` (`db-isolasi-penuh`: skema, constraint sampai nilai batas,
`down()/up()`). Syarat berikutnya: **C-1** di M4 MAKE-045 (kunci `FOR UPDATE` per NIP sebelum cek tumpang tindih, cek +
tulis satu transaksi, test konkurensi dua permintaan); **C-2** audit B1 berkala (tutup bulan Tukin) di runbook 5B.

## 5B M5 — D-08 laporan Tukin (MAKE-041, 28-10-2026)

Branch `fase5b/make-041-laporan-tukin` (dari `main` `70a24b6`). Daftar pegawai (+IL), laporan per pegawai JSON + PDF
render legacy (F5B-8), rekap JSON + XLSX tata letak legacy + PDF berbatas (F5B-16) + IL (F5B-6); satu kalkulasi untuk per
pegawai & rekap (K-1); penanda tangan enam kasus (F5B-13) dengan Plt status 1 deterministik (F2); efek penyesuaian Tukin
DBV-024 tanpa mengubah `total_potongan` (F5B-14); lingkup role lewat `CakupanLaporan` (M6). Berkas bersama, commit
terpisah: `Config\Presensi` (`$pdfRekapMaksBaris`, `$unitTtdSatkerTerpilih`, `$unitTtdSatkerKepegawaian` — F3),
baris `KontrakMatriksRoleTest` bersama route. Uji volume `RekapTukinVolumeTest` (group `volume`) + ADR-035. Rincian di
README `Libraries/Presensi` bagian "Laporan Tukin 5B" dan README controller.

**CR-075 (29-10-2026):** `harian[].kode` aditif di `GET presensi/tukin/laporan/{nip}` (kode kejadian `HasilHarian::kategori` untuk color-coding FE 5C MAKE-048); field lain, PDF, dan XLSX tidak berubah.

## 5B M6 — D-09b rekap kehadiran & rekap uang makan (MAKE-042, 28-10-2026)

Branch `fase5b/make-042-rekap-kehadiran-uang-makan` (dari `main` `79641be` + sinkron `139898f`). Murni:
`Rekap/KlasifikasiKehadiran` + `Rekap/JadwalKerjaRekap` (penjaga `RekapMurniTest`). Ber-DB di `Laporan/`:
`CakupanLaporan`/`LingkupLaporan` (lingkup role, dipakai ulang M5), `PopulasiRekap`, `ParameterRekap`, `DataRekapBulan`,
`RekapKehadiranService`, `RekapUangMakanService` (aturan hari slip F5B-2 lewat `UangMakanSlipPresensi::hariDibayarBanyak`),
`EksporRekapXlsx` (PhpSpreadsheet); `RekapController`; 7 route di `Config/RoutesPresensiLaporan.php` (berkas 5B) + baris
`KontrakMatriksRoleTest` (berkas bersama, commit terpisah). Test: `KlasifikasiKehadiranTest` (AC-D09b.5),
`RekapKehadiranTest`, `RekapUangMakanTest` (AC-D09b.1–.4, termasuk jumlah baris = `uang_makan_hari` snapshot slip M+1),
`RoleMatriksRekapTest` (AC-D09b.6). Deviasi [V2] dirinci di README `Libraries/Presensi` bagian "Rekap kehadiran & uang
makan 5B". Keputusan Horii 28-10-2026 (Q5B-25/26): ringkas ikut legacy — hari TB tidak diperiksa (TK + tidak hadir bila
tanpa presensi, kolom `tb` informasi) dan presensi pegawai yang dibuat setelah awal bulan tidak dibaca (`L_presensi.php:599`
ringkas, `:1053` harian); rekap uang makan tetap F5B-2. `Libraries/Presensi/Tukin`, `SlipGaji`, `SlipGajiService`, berkas Fase 4 tidak tersentuh.

## 5B M4 — CRUD penyesuaian Tukin (MAKE-045, 28-10-2026)

Branch `fase5b/make-045-penyesuaian-tukin` (dari `main` `e9f4616`, DBV-024 sudah di `main`). `PenyesuaianTukinModel`
(`BaseAuditableModel`, hapus = status 10 audit `delete`), `Laporan\PenyesuaianTukinService`, `PenyesuaianTukinController`,
route role 1 di berkas 5B baru `Config/RoutesPresensiLaporan.php`. Berkas bersama aditif, commit terpisah:
`Config/Routing.php` (entri berkas route) + baris kontrak di `KontrakMatriksRoleTest`. Syarat DB Validator **C-1**
dipenuhi: kunci `FOR UPDATE` baris pegawai per NIP sebelum cek tumpang tindih, cek + tulis satu transaksi, kunci gagal
1205/1213 → 409 `konflik_data` (keputusan reviewer CR 28-10-2026; tidak lanjut tanpa kunci); test
`PenyesuaianTukinKonkurensiTest` (`db-isolasi-penuh`, koneksi kedua tertahan 1205 → 409, lalu 422 setelah commit; NIP
lain tidak tertahan). Test CRUD `PenyesuaianTukinCrudTest` (validasi per field, nilai batas,
tumpang tindih hanya antar-baris aktif NIP sama, NIP tidak bisa diubah [V2], audit, daftar/filter, 404, route 1 saja).
Efek ke laporan/rekap (`Rekap\PenyesuaianTukin`, AC-D08.11 bagian efek) = M5. Syarat **C-2** (audit B1 berkala) dicatat
untuk runbook 5B (M7).

## 5B M1 — D-06 sumber cuti & TB nyata (MAKE-039, 28-10-2026)

Branch `fase5b/make-039-sumber-cuti-tb` (dari `main` `988c1ed`). `SumberCutiTbFase4` (+ `SumberCutiTbMassalInterface`):
cuti `status = 1` jenis 1..6 (di luar → `DataTukinTidakSah`; tumpang tindih → id terbesar, F5B-17), TB lewat
`rentangBebasPresensiBanyak` Fase 4 (MAKE-044). Jalur massal `TukinDataService::siapkanBanyak()` + varian massal aditif
`SumberHarianTukin::untukBanyak()` / `SumberDataPegawaiTukin::untukBanyak()` (**deviasi PRD 5B §3.3 disetujui reviewer CR
M0 F1**; metode tunggal tidak berubah). Berkas bersama aditif, commit terpisah: `Config\Presensi::$tukinBatchNip` (500,
`.env` `presensi.tukinBatchNip`) dan switch `$sumberCutiTb = SumberCutiTbFase4::class`. Library `Presensi/Tukin`,
`SlipGaji`, dan berkas Fase 4 tidak tersentuh. Test: `SumberCutiTbFase4Test`, `PrecedenceKombinasiTest`,
`CutiSakitLookbackTest`, `CutiBesarMelahirkanPeriodeTest`, `TukinDataMassalTest` (AC-D06.1–06.9); `TukinDataServiceTest`
diperbarui (rakitan bawaan kini memakai sumber Fase 4). D-04/D-05/D-07 → DONE (backend) setelah M1 merged + review
(PRD 5B §11.3).

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

## 5A M3 — D-10 impor Slip Gaji (MAKE-030)

- **Sudah (27-10-2026, branch `fase5a/make-030-impor-slip-gaji`):** PhpSpreadsheet 5.10 + ekstensi (cc373bf), ADR-032,
  `ValidasiBerkas::periksaImpor`, `PembacaExcelSlip`, `PenyimpanPratinjau`, `Config\Presensi` (batas impor) — persiapan
  non-skema sebelumnya; lalu setelah M2: `ImporSlipService` (pratinjau + commit 1 transaksi, 1062 → 409 `konflik_data`,
  audit `warning`/`import`), `GajiPegawaiModel`, `SlipGajiImportController`, `Config/RoutesPresensi.php` (+
  `Routing::$routeFiles`), kontrak role `slip-gaji` di `KontrakMatriksRoleTest`, kode galat mesin opsional di envelope
  (`ApiException` parameter terakhir `kode` → field `code` envelope, konstanta `KodeGalatSlip`; berkas bersama aditif, commit terpisah), dokumentasi endpoint di README controller.
- **Test (AC-D10.1–10):** `tests/Presensi/Slip/ImporSlip{Pratinjau,Commit,MismatchNama,Duplikat,KonflikUnique,Akses,Berkas}Test.php`
  + `tests/unit/Presensi/Slip/{PembacaExcelSlip,PenyimpanPratinjau}Test.php`; berkas Excel sintetis dibangkitkan test
  (`tests/_support/Presensi/ImporSlipUjiTrait.php`). Konflik 1062 lewat seam `kaitSebelumTulis` (PRD §7.2.6).
- **Catatan perilaku:** berkas tanpa ekstensi ditolak "Isi berkas tidak sesuai dengan ekstensinya." dan isi ber-jenis lain
  (mis. PDF bernama `.xlsx`) "Format berkas harus .xlsx atau .xls." — sama dengan aturan `ValidasiBerkas` jalur lampiran
  (CR-054); galat filter periode dipetakan ke `errors.filter_bulan` + `code`.

## 5A M4 — D-11/D-14 akses & verifikasi Slip Gaji (MAKE-031)

- **Sudah (branch `fase5a/make-031-akses-verifikasi-slip`; jeda 27-10-2026 atas instruksi Horii, dilanjutkan setelah
  "Lanjutkan"):** persiapan non-skema (RingkasanSlip + uang makan F5-1, kontrak `UangMakanSlip*`, `ThrottlePublikFilter`,
  `PdfSlipGaji` + view, `KopSlip`, README 771f8b4); merge main 2e0aabd (M3); `TokenSlipService` (hash + terenkripsi Q5A-01),
  `SlipGajiData`, `SlipGajiService` (daftar/status/buka atomik/PDF, snapshot uang makan §7.3.7), `SlipGajiAdminService`
  (monitoring/detail/koreksi/buka ulang/PDF), `VerifikasiSlipService`, `GajiPegawaiQrHistoryModel`, 3 controller, route M4
  + grup publik `throttle` (alias `Config/Filters`, aditif), kontrak role `slip-gaji`/`publik`, `Config\Presensi` blok M4
  (`$sumberUangMakan`, `$urlVerifikasiSlip`), README endpoint.
- **Test (AC-D11.1–11, AC-D14.1–7):** `tests/Presensi/Slip/{GerbangSlip, SlipPegawaiAkses, BukaSlipAtomik,
  BukaSlipKonkurensi (db-isolasi-penuh), SlipAdmin, UangMakanSnapshot, RoleMatriksSlip, VerifikasiSlip}Test.php`,
  `tests/Presensi/RoutePresensiKontrakTest.php`, unit `PdfSlipGaji`/`KopSlip`/`RingkasanSlip`/`ThrottlePublikFilter`.
- **Blocker go-live (bukan blocker 5A DONE):** `Config\Presensi::$sumberUangMakan` masih `UangMakanBelumTersedia` sampai 5B
  (F5-1; **ditutup 5B M2 MAKE-040** setelah merged, lihat bagian 5B M2); kunci `encryption.key` wajib terisi di produksi.

## 5A M5 — D-02/D-03 presensi online, manual, data admin (MAKE-032)

- **Sudah (27-10-2026, branch `fase5a/make-032-presensi-online-manual`):** logika murni `Libraries/Presensi/Geo` (fc3451f,
  sebelum M2); setelah M2: `Kehadiran/{PresensiOnlineService, PresensiManualService, DataPresensiService,
  ResolverLokasiPresensi, ZonaSatker, KodeGalatPresensi, DuplikatPresensi}`, `Models/Presensi/DhOnlineModel`,
  `PresensiController`, `PresensiManualController`, `Config/RoutesPresensi.php` (blok M5) + `Routing::$routeFiles`, kontrak
  role prefiks `presensi` di `KontrakMatriksRoleTest`, `Config\Presensi` blok M5 (jendela waktu, `$akunPresensiManual` +
  override `.env`), README endpoint. Kode galat envelope `ApiException(kode:)` dibawa dari MAKE-030 (isi identik, ed1d951).
- **Test (AC-D02.1–10, AC-D03.1–8):** `tests/Presensi/Kehadiran/{PresensiOnline, ResolverLokasiPresensi, PresensiZonasi,
  PresensiJendela, PresensiKonkurensi (db-isolasi-penuh), RoleMatriksPresensi, PresensiManualAkses, PresensiManualValidasi,
  PresensiManualAudit, DataPresensiAdmin}Test.php` + unit `Geo` (Haversine, PenilaianGeofence, kemurnian). Konkurensi:
  koneksi DB kedua menulis & commit lewat seam `kaitSebelumTulis` (setelah pemeriksaan awal, sebelum transaksi tulis).
- **DBV-023 C-4 terpenuhi:** baris baru `dh_online` selalu `sumber` 1 (online) / 2 (manual, melekat); diuji.

## 5A M6 — MAKE-033 adapter Tukin (D-04/D-05/D-07 sisi integrasi)

Branch `fase5a/make-033-adapter-tukin` (dari `main` `6806c38`); acuan PRD 5A §7.7. Merge setelah M2 (DBV-023) karena
harian presensi membaca `dh_online`.

- **Sudah:** `Libraries/Presensi/TukinData` (konfigurasi `web_config`, periode `bkn_periode_ekinper`, hari libur, data
  pegawai, konket `absen_ijin`, sumber cuti/TB fail-closed `SumberCutiTbBelumTersedia` + `Config\Presensi::$sumberCutiTb`,
  `TukinDataService::siapkan()` → `ArgumenTukin`) + README folder (rujukan legacy, [K]/[V2]); test
  `tests/Presensi/TukinData` (AC-TK.1–TK.5 bagian non-skema).
- **Keputusan reviewer CR 20-10-2026:** `tmtMasuk` ikut legacy `get_all_pegawai_dh_spe` + rekap [K], kecuali cabang PPPK
  rekap mengenali PPPK lewat id (`Jabatan::JENIS_PEGAWAI_PPPK`) [V2]; syarat `tgl_perkawinan` pada jumlah anak [K].
- **Berkas bersama (commit terpisah):** `Libraries/MasterData/WebConfigTypes.php` + `WebConfigTypesTest` (tipe
  `date_range_list`); `Config/WebConfig.php` (grup "Jam Kerja & Puasa", 7 key F5-16) dengan angka katalog 35 → 42 di
  `tests/MasterData/WebConfigTest.php` dan `WebConfigTypesTest` (disetujui reviewer); **tambahan disetujui reviewer:**
  FE `features/master-data/webConfig.types.ts`, `schemas/webConfig.schema.ts`, `components/WebConfigFormDialog.vue`
  (tipe `date_range_list` di form Web Config, agar `rentang_puasa` dapat dikelola admin lewat UI).
- **Sudah (27-10-2026, setelah M2 di `main`):** `presensi {masuk, pulang}` harian dari `dh_online`
  (`SumberHarianTukin::presensi`, tanpa saring `sumber`, jam lokal apa adanya [K `L_presensi.php:12376-12391`,
  `:12850-12851`]; jam tidak sah → `DataTukinTidakSah`). Test: `HarianTukinAdapterTest` (online/manual/impor, gabung
  presensi+konket+cuti, jam tidak sah) dan `TukinDataServiceTest` (baris `dh_online` → status `hadir`, tanpa baris → `TK`).
  AC-TK.1–TK.6 terpenuhi; `Libraries/Presensi/Tukin` tidak diubah.
- **Sisa untuk 5B (di luar 5A):** sumber cuti/TB nyata (`Config\Presensi::$sumberCutiTb`), endpoint/laporan Tukin
  (D-08), cross-check rumus resmi Q5A-13.
- **5B M1a (MAKE-044):** metode massal TB `StatusTugasBelajarInterface::rentangBebasPresensiBanyak` di modul Fase 4 —
  rincian di `docs/progress/04-Layanan.md` bagian "Fase 5B M1a"; acuan `docs/fase5/PRD_Fase5B_Cuti-TB-LaporanTukin-UangMakan.md`.

## 5B M2 — D-09a uang makan slip (MAKE-040, 28-10-2026)

Branch `fase5b/make-040-uang-makan-slip` (dari `main` `4548718`). Implementasi asli `UangMakanSlipInterface`:
`Slip/UangMakanSlipPresensi` + `Slip/TarifUangMakan` + aturan murni `Rekap/HariUangMakan` (folder murni baru, penjaga
`RekapMurniTest`), port `Lsl_gaji.php:260-414` `0cf7e67` [K] — rincian aturan di README `Libraries/Presensi` bagian
"Uang makan slip 5B". Berkas bersama, commit terpisah: switch `Config\Presensi::$sumberUangMakan =
UangMakanSlipPresensi::class`. `SlipGajiService` (aturan snapshot 5A), `Presensi/Tukin`, `SlipGaji`, dan berkas Fase 4
tidak tersentuh. Test: `HariUangMakanTest`, `RekapMurniTest`, `TarifUangMakanTest`, `UangMakanSlipPresensiTest`,
`UangMakanSnapshotAsliTest` (AC-D09a.1–09a.5); `UangMakanSnapshotTest`/`BukaSlipAtomikTest` menyuntik
`UangMakanBelumTersedia` secara eksplisit. **Blocker go-live F5-1 ditutup** setelah branch ini merged (AC-D09a.5).
Usulan awal M2 (`gol_pppk.uang_makan` tidak bulat sampai sen → sumber `null`) **ditolak Horii 28-10-2026** (Q5B-24):
ikut legacy, total = tarif × hari dibulatkan setengah-menjauhi-nol ke sen seperti kolom DECIMAL(14,2), dihitung desimal
eksak (`TarifUangMakan::totalSen`); `DataUangMakanTidakSah` dihapus; tarif > 2 desimal dicatat log warning tanpa nominal.

## CR-067 — Slip Gaji TK tanpa Pajak / Tunjangan Pajak (ikut legacy `0cf7e67`)

Keputusan Horii 28-10-2026: ikut legacy terbaru `0cf7e67` (26-10). Branch `fase5a/cr-067-slip-tanpa-pajak`.

| Butir | v2 sebelum | Legacy `771f8b4` → `0cf7e67` | v2 sesudah | Gap |
|---|---|---|---|---|
| Baris TK di layar | Kartu FE sudah hanya Penghasilan Kotor, Potongan, Bersih (TK) | `_slip_content.php:43-46`: `kotor, tunj_pajak` / `potongan, pajak` → `kotor` / `potongan` | Tetap (ditambah test & rujukan) | — |
| Baris TK di PDF | Pajak & Tunjangan Pajak tampil | `print/cetak.php:274-277` sama seperti layar | Dihapus (`pdf/slip_gaji`) | — |
| Jumlah per kolom TK (PDF) | `kotor + tunj_pajak` / `potongan + pajak` | `:360-361` → `kotor` / `potongan` | Ikut | — |
| Total slip | `bersih_gpp + bersih_tk + uang_makan` | Tidak berubah (`Lsl_gaji.php:430-434` @0cf7e67) | Tidak berubah | — |
| Kolom DB & impor | `pajak`, `tunj_pajak` disimpan | Tidak berubah | Tidak berubah (tanpa migration) | — |
| Bagian A "Iuran & Pajak", detail/edit admin | — | Tidak berubah | Tidak berubah (tahap (b) menampilkan kolom lengkap seperti legacy) | — |
| `1c3142f` (di rentang yang sama) | Snapshot uang makan tidak ditimpa 0; logo tersemat | Snapshot dipertahankan, logo data URI | Sudah setara sejak M4 | — |

Acuan slip di README Presensi dinaikkan ke `0cf7e67` (P-10, PDF-7). Test: `PdfSlipGajiTest::testBagianTkTanpaPajakDanTunjanganPajak`,
`SlipGajiPage.spec.ts` (bagian TK tanpa Pajak).

## 5A M7 — FE 5A Presensi & Slip Gaji (MAKE-034) — IN_PROGRESS

Branch `fase5a/make-034-frontend-presensi-slip` (dari `main` `bb6cb90`); merge bertahap (a) → (b) → (c) atas izin Horii,
tiap tahap gate cepat + CI + `./check.sh` sekali. Status FE 5A tetap IN_PROGRESS sampai QA Lapis 1 lolos (Q5A-18).

- **Tahap (a) (27-10-2026):** `features/presensi/{roles,types,routes.presensi}.ts`, `services/slipGaji.service.ts`
  (amplop sudah dibuka interceptor; nominal string server, format rupiah BigInt tanpa float), `SlipGajiPage` (empat
  keadaan legacy, modal sekali buka + centang, kartu slip + Cetak PDF hanya di sesi buka), `SlipGajiKartu`,
  `BukaSlipDialog`, `VerifikasiSlipPage` (publik, tanpa layout login, meta robots `noindex`, hanya endpoint publik).
  Berkas bersama aditif, commit terpisah: `shared/layouts/nav.config.ts` (`NavItem.rilis`, `NavChild.roles` — satu
  definisi lintas fase, grup Presensi `rilis: true`, ACTIVE_PHASE tetap 3) + spec; `router/index.ts` (`presensiRoutes`).
  Backend aditif: respons buka slip memuat `pegawai {nip, nama, pangkat, jabatan, unit, satker}` untuk identitas kartu
  [K `_slip_content.php`]. Test: `features/presensi/__tests__/{services.amplop,SlipGajiPage,VerifikasiSlipPage,navPresensi}.spec.ts`
  (klien axios asli untuk service, payload string), `nav.config.spec.ts`.
- **Tahap (a) di `main` `5279b82`** (28-10-2026, izin Horii).
- **Tahap (b) (28-10-2026):** `/presensi/slip-gaji/kelola` (`SlipGajiKelolaPage`, role 1, 3) [K `admin_list.php`,
  `admin_import.php`]:
  - Tab **Monitoring** (`SlipGajiMonitoring`): periode wajib, bawaan periode terbaru yang tersedia [K
    `Sl_gaji.php:219-221`]; filter satker hanya role 1 (opsi `master/satker/options`; role 3 dipaksa server); status
    pembukaan dan pencarian; ringkasan jumlah/dibuka/belum. Kolom tabel: NIP, Nama, Unit/Satker, Periode, badge
    Status, Waktu dibuka WIB, IP. Menu ⋮ `slipgaji-actions-<id>`: Edit → Lihat detail → Cetak PDF → Buka ulang
    (dari `aksi` server; Buka ulang `hidden` bila belum dibuka, lewat `ConfirmDialog`).
  - `SlipGajiDetailDialog`: rincian admin berisi identitas dan seluruh kolom GPP/TK termasuk Pajak/Tunjangan Pajak
    (modal admin legacy tidak diubah `0cf7e67`), uang makan, total, dan Cetak PDF.
  - `SlipGajiKoreksiDialog`: 28 kolom desimal titik, 422 per kolom, uang makan tidak dapat dikoreksi; dua kolom, satu
    kolom ≤ 600 px.
  - Tab **Impor** (`SlipGajiImpor`): unggah .xlsx/.xls (`UiFileField`) + filter periode opsional (`UiMonthPicker` →
    `filter_bulan`/`filter_tahun`); tabel pratinjau berisi status per baris dari server dan baris galat ditandai warna
    beserta pesannya; "Timpa data yang sudah ada" bawaan dari server (tercentang); "Simpan ke Database" lewat
    `ConfirmDialog`; hasil commit ditampilkan; 409 menampilkan ajakan unggah ulang.
  - Backend aditif: `GET|PUT slip-gaji/{id}` memuat `pegawai {nip, nama, pangkat, jabatan, unit, satker}` untuk
    identitas modal [K `admin_list.php:459-470`].
  - Berkas bersama aditif (commit terpisah): `nav.config.ts` menambah anak "Kelola Slip Gaji" (role 1, 3) beserta spec.
  - Test: `slipGajiAdmin.amplop.spec.ts` (klien asli), `SlipGajiKelolaPage.spec.ts`, `ImporPratinjau.spec.ts`,
    `navPresensi.spec.ts`.
- **Temuan di luar lingkup (dilaporkan ke reviewer CR 28-10):** sejak CR-060 (`9d212f0`), menu ⋮ `RowActionsMenu`
  tidak terposisi di browser nyata (wrapper popper tetap `translate(0, -200%)`). Penyebabnya trigger dropdown dibungkus
  `UiTooltip`; dibuktikan dengan melepas pembungkus secara lokal. Komponen bersama tidak diubah di MAKE-034 (PRD 5A
  §7.8); perbaikan menunggu kartu CR.
- **Figma:** acuan hanya Slip Gaji (PNG); halaman verifikasi dan Kelola Slip Gaji tanpa frame Figma → pola
  `AuthCardLayout` / komponen Fase 3 (AGENTS §3.6).
- **Tahap (b) di `main` `5a3a35c`** (28-10-2026, izin Horii).
- **Tahap (c) (28-10-2026):**
  - `/presensi` (`PresensiPage`, role 2, 6, 7) [K `dashboard_pns.php` panel presensi], mobile-first:
    - Tanggal, jam lokal, jendela, dan `boleh_*` dari server; jam berjalan hanya tampilan.
    - Tombol Rekam Masuk/Pulang dengan alasan nonaktif berupa teks.
    - Panel status lokasi sebelum submit: izin, koordinat, akurasi, putusan `cek-lokasi` server, dan
      `IndikatorRadius` (SVG lingkaran radius + posisi relatif, tanpa peta tile, ◻ Q5A-10).
    - `useGeolokasi`: `getCurrentPosition` tiap 10 detik (`enableHighAccuracy`, `timeout` 10000, `maximumAge` 0).
      `cek-lokasi` maks sekali per pembacaan (toleransi 500 ms agar pembacaan yang datang beberapa ms lebih awal
      tidak terlewat).
    - Koordinat dikirim di body.
    - Riwayat pribadi per bulan (`UiMonthPicker`) + paginasi + badge Sumber.
  - `/presensi/data` (`DataPresensiPage`, role 1, 3, 4, 5) [K `list_ad.php`, `list.php`]:
    - Daftar pegawai (lingkup server, filter unit/satker berjenjang, pencarian) → ⋮ "Lihat presensi" → detail `?nip=`
      (rentang tanggal, bawaan 100 terbaru, badge Sumber Online/Manual/Impor, lokasi masuk/pulang).
    - Akun khusus (dari `can_*`/`aksi`) mendapat "Tambah Presensi" + ⋮ `presensi-actions-<nip>-<tgl>` Edit → Hapus
      (`danger`, `ConfirmDialog`).
  - `PresensiManualDialog`: tambah/edit, `UiDatePicker`/`UiTimePicker`, 422 per kolom. Saat edit, jam yang tidak
    diubah dikirim apa adanya (detik tidak hilang) agar server tidak mengosongkan bukti lokasi (◻ Q5A-16).
  - Berkas bersama aditif (commit terpisah): `nav.config.ts` menambah anak Presensi Saya (2, 6, 7) dan Data Presensi
    (1, 3, 4, 5). Menu Presensi kini lengkap sesuai §7.8.2; role 4 dan 5 kini melihat grup Presensi.
  - Test: `presensi.amplop.spec.ts` (klien asli), `PresensiPage.spec.ts` (geolokasi tiruan + timer palsu),
    `DataPresensiPage.spec.ts`, `PresensiManualDialog.spec.ts`, `navPresensi.spec.ts`, `nav.config.spec.ts`.
  - Cek browser nyata (pratinjau + API tiruan): izin lokasi ditolak (state asli browser pane), lalu geolokasi tiruan
    dalam/luar radius; rekam masuk; menu ⋮ desktop + 375 px; dialog edit di 375 px.
- **Figma:** halaman Presensi Saya dan Data Presensi tanpa frame Figma → pola komponen Fase 3 (AGENTS §3.6).
- **Berikutnya:** M8 MAKE-035 (verifikasi 5A) setelah tahap (c) di `main`; checklist Q5A-18 disiapkan menjelang QA.

### Q cutover 5A

| # | Butir | Asal | Status |
|---|---|---|---|
| QC-1 | Uji koreksi NIP (`NipCascadeService`) pada volume `dh_online` realistis + urutan induk/anak di bawah FK RESTRICT | DBV-023 C-3 (DB Validator 20-10-2026) | ⏳ pra-cutover |
| QC-2 | Akar aset v2 final (`presensi.akarAset`) + berkas logo kop slip bawaan ditetapkan | MAKE-031, bersama G-09 | ⏳ cutover |
| QC-3 | Konfirmasi Biro Keuangan atas data yang tampil di verifikasi QR publik (AS-06) | Q5A-08, review CR M4 27-10-2026 | ⏳ pra-go-live |
| QC-4 | Isi `Config\App::$proxyIPs` bila backend di belakang reverse proxy/load balancer, agar throttle verifikasi publik per IP klien (bukan per IP proxy) | review CR M4 27-10-2026 | ⏳ deploy |
| QC-5 | `encryption.key` terisi di produksi (token QR slip terenkripsi) dan HTTPS aktif (geolokasi presensi online) | MAKE-031, MAKE-032 | ⏳ deploy |

### Uji paritas QA Fase 5

| # | Butir | Asal | Status |
|---|---|---|---|
| UP-1 | Paritas adapter Tukin vs rekap legacy `laporan_tukin_us_skp` pada data nyata: legacy memuat `dh_online` per bulan kalender N−1 dan N (`L_presensi.php:12376-12391`), adapter v2 per rentang [awal periode sebelumnya, akhir periode] (`SumberHarianTukin`). Bandingkan hasil per pegawai untuk periode dengan baris BKN tidak standar dan pegawai dengan **cuti sakit lintas periode** (lookback urutan cuti sakit, `KalkulasiTukin::sakitSebelumPeriode`) | MAKE-033, review CR M6 27-10-2026 | ⏳ QA Fase 5 (setelah 5B: cuti/TB nyata) |

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
