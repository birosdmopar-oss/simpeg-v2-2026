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
| D-02 Presensi online + geofence | 5A | M5 | MAKE-032 | **IN_PROGRESS** | Service/controller/test AC-D02 di branch `fase5a/make-032-presensi-online-manual` (bagian "5A M5"); menunggu review/merge |
| D-03 Presensi manual | 5A | M5 | MAKE-032 | **IN_PROGRESS** | Akun khusus + data presensi admin + test AC-D03 (bagian "5A M5"); menunggu review/merge |
| D-04..D-07 Kalkulasi Tukin | 5A (adapter) / 5B | M6 | MAKE-033 | **IN_PROGRESS** | Logika murni CR-037/CR-039 selesai; adapter `TukinData` 5A (non-skema boleh mulai, merge setelah M2); precedence/laporan di 5B |
| D-08 Laporan Tukin, D-09 rekap & uang makan | 5B | — | — | TODO | Di luar 5A |
| D-10 Slip Gaji: impor Excel | 5A | M3 | MAKE-030 | **IN_PROGRESS** | Impor 3 langkah + test AC-D10 di branch `fase5a/make-030-impor-slip-gaji` (bagian "5A M3"); menunggu review/merge |
| D-11 Slip Gaji: akses & kelola admin | 5A | M4 | MAKE-031 | **IN_PROGRESS** | Kode + test AC-D11 di branch `fase5a/make-031-akses-verifikasi-slip` (bagian "5A M4"); menunggu review/merge (izin Horii) |
| D-12 Frontend | 5A (Presensi & Slip Gaji) / 5C | M7 | MAKE-034 | TODO | |
| D-13 Unit test lengkap | 5A (bagian) / 5C | M8 | MAKE-035 | **IN_PROGRESS** | Bagian murni CR-037/CR-027; regression suite lengkap di 5C |
| D-14 Verifikasi QR publik | 5A | M4 | MAKE-031 | **IN_PROGRESS** | Kode + test AC-D14 (bagian "5A M4"); FE halaman `/verify/slip/:token` di M7 |

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
  (F5-1); kunci `encryption.key` wajib terisi di produksi.

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
- **Figma:** acuan hanya Slip Gaji (PNG); halaman verifikasi tanpa frame Figma → pola `AuthCardLayout` (AGENTS §3.6).
- **Berikutnya:** tahap (b) Kelola Slip Gaji (monitoring, impor, koreksi); tahap (c) Presensi Saya + Data Presensi + manual.

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
