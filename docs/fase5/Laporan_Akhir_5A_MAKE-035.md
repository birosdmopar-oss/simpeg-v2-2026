# Laporan Akhir Paket 5A — M8 MAKE-035 (verifikasi akhir)

Acuan: `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` v1.1 (§11.1 DoD, §11.4 format laporan, §12 checklist,
Lampiran C keterlacakan). Disusun 28-10-2026 dari `main` `919c822` (seluruh M1–M7 5A sudah di `main`).

> **5A DONE ≠ Fase 5 DONE** (PRD 5A §11.2). Laporan ini **tidak** menyatakan Fase 5 selesai atau siap go-live. Status
> 5A DONE menunggu (a) sign-off reviewer CR 5A (§11.1 #8) dan (b) QA Lapis 1 FE 5A lolos (§11.1 #7, Q5A-18).

## 1. Laporan (format §11.4)

```
Status: D-01 IN_PROGRESS (menunggu sign-off 5A), D-02 idem, D-03 idem, D-10 idem, D-11 idem, D-14 idem,
        5A-4 (D-04/05/07 adapter) IN_PROGRESS (selesai 5A; precedence/laporan = 5B),
        FE 5A (D-12) IN_PROGRESS (sampai QA Lapis 1 lolos, Q5A-18)
Branch/SHA/CI: fase5a/make-035-verifikasi-5a — SHA & run CI di pesan laporan ke reviewer CR (§5)
File utama: lihat §2
Test & validasi: lihat §3 (keterlacakan AC → test) dan §4 (checklist §12)
Tabrakan Fase 4: tidak ada berkas Fase 4 yang disentuh di 5A; berkas bersama hanya aditif di commit terpisah
                 (router/index.ts, nav.config.ts, Routing.php/Services.php/Config, KontrakMatriksRoleTest, NipCascade
                 registry) — rincian commit di 05-Presensi.md per milestone; M8 dari main 919c822
Deviasi [V2] & keputusan: lihat §6
Blocker / pertanyaan terbuka: lihat §7; blocker go-live: lihat §7.1
```

## 2. Berkas utama 5A (di `main`)

| Bagian | Berkas |
|---|---|
| Skema (D-01) | `backend/app/Database/Migrations/2026-10-27-100000_CreatePresensiGaji.php`, `…100100_AlterAuditLogsEventPresensi.php`; `backend/docs/db-review/DBV-023-presensi-gaji-fase5a-schema.md` |
| Impor Slip (D-10) | `Libraries/Presensi/Slip/{ImporSlipService,PembacaExcelSlip,PenyimpanPratinjau,…}.php`, `Controllers/Api/Presensi/SlipGajiImportController.php` |
| Slip pegawai/admin + verifikasi (D-11, D-14) | `Libraries/Presensi/Slip/{SlipGajiService,SlipGajiAdminService,SlipGajiData,PdfSlipGaji,TokenSlipService,VerifikasiSlipService,KopSlip}.php`, `Views/pdf/slip_gaji.php`, `Controllers/Api/Presensi/{SlipGajiController,SlipGajiAdminController,VerifikasiSlipController}.php`, filter throttle publik |
| Presensi online/manual/data (D-02, D-03) | `Libraries/Presensi/Kehadiran/*`, `Libraries/Presensi/Geo/*`, `Controllers/Api/Presensi/{PresensiController,PresensiManualController}.php` |
| Adapter Tukin (5A-4) | `Libraries/Presensi/TukinData/*` |
| Konfigurasi & route | `Config/Presensi.php`, `Config/RoutesPresensi.php` |
| FE 5A (D-12) | `frontend/src/features/presensi/**` (views `SlipGajiPage`, `SlipGajiKelolaPage`, `VerifikasiSlipPage`, `PresensiPage`, `DataPresensiPage`; komponen, service, composable, test), aditif `router/index.ts`, `shared/layouts/nav.config.ts` |
| Dokumen | `Controllers/Api/Presensi/README.md`, `Libraries/Presensi/README.md` (acuan slip `0cf7e67`), `Libraries/Presensi/TukinData/README.md`, `docs/adr/ADR-032-library-pdf-excel-qr-rute-publik.md`, `backend/docs/progress/05-Presensi.md` |

## 3. Keterlacakan AC → bukti (Lampiran C)

DoD Trello → AC mengikuti PRD Lampiran C.1 (tidak diulang). Tabel ini menutup langkah berikutnya: **setiap AC → test di
`main`**. Path test relatif ke `backend/tests/` (PHPUnit) atau `frontend/src/features/presensi/__tests__/` (Vitest).

### D-01 Skema
| AC | Bukti |
|---|---|
| AC-D01.1 tabel dibuat sesuai DBV-023 | `database/Presensi/SkemaPresensiGajiTest` |
| AC-D01.2 PK `(nip, tgl)` + 1062 | `SkemaPresensiGajiTest` (`assertDbError([1062], …)`) |
| AC-D01.3 FK RESTRICT 1452/1451, `gaji_pegawai` tanpa FK nip | `SkemaPresensiGajiTest` |
| AC-D01.4 histori `gaji_pegawai` / siklus | `MigrasiPresensiGajiSiklusTest` |
| AC-D01.5 volume histori + kartu DBA | Dokumen: DBV-023 bagian impor (ISSUE-049: produksi tanpa histori; DEPLOY-002.1 cek ulang) |
| AC-D01.6 DBV approve sebelum merge | Dokumen: `05-Presensi.md` — disetujui DB Validator 20-10-2026 (PR #28), merge M2 sesudahnya |
| AC-D01.7 registry NIP & cascade | `TabelAnakNipTest`, `NipCascadeTest` (`NipCascadeService::FASE = 5`) |
| AC-D01.8 siklus down/up + migration baru saja | `MigrasiPresensiGajiSiklusTest` (`db-isolasi-penuh`); `git diff --diff-filter=MD 3326a63 origin/main -- …/Migrations` kosong; timestamp `2026-10-27-*` > DBV-022 `2026-10-26-*` |
| AC-D01.9 ENUM `audit_logs.event` | `SkemaPresensiGajiTest`, `MigrasiPresensiGajiSiklusTest` |

### D-10 Impor Slip Gaji
| AC | Bukti |
|---|---|
| AC-D10.1 tiga langkah | `Slip/ImporSlipPratinjauTest`, `ImporSlipCommitTest`, `ImporSlipBerkasTest`, `unit/…/PenyimpanPratinjauTest` |
| AC-D10.2 mismatch nama-NIP → audit | `Slip/ImporSlipMismatchNamaTest` |
| AC-D10.3 duplikat saat pratinjau | `Slip/ImporSlipDuplikatTest` |
| AC-D10.4 UNIQUE → 409 + rollback | `Slip/ImporSlipKonflikUniqueTest` |
| AC-D10.5 valid ter-commit, timpa | `ImporSlipCommitTest`, `ImporSlipDuplikatTest` |
| AC-D10.6 role 1 & 3 (SG-2) | `Slip/ImporSlipAksesTest` |
| AC-D10.7–10.10 berkas, TTL, baris, batas | `ImporSlipBerkasTest`, `PenyimpanPratinjauTest`, `ImporSlipPratinjauTest`, `PembacaExcelSlipTest`, `ImporSlipCommitTest` |

### D-11 Slip Gaji akses & kelola
| AC | Bukti |
|---|---|
| AC-D11.1 gerbang 09:59:59 / 10:00:00 | `Slip/GerbangSlipTest`, `UangMakanSnapshotTest` |
| AC-D11.2 hanya slip sendiri / matriks role | `Slip/RoleMatriksSlipTest`, `SlipPegawaiAksesTest`, `SlipAdminTest` |
| AC-D11.3 PDF pegawai setelah gerbang & dibuka; admin kapan saja | `SlipPegawaiAksesTest::testNipDariTokenDanPdf`, `SlipAdminTest::testBukaUlangDanPdfAdmin` |
| AC-D11.4–11.6 buka sekali, atomik, token baru, hash | `Slip/BukaSlipAtomikTest`, `BukaSlipKonkurensiTest` |
| AC-D11.7 koreksi 28 field + audit, status tetap | `SlipAdminTest::testKoreksi` |
| AC-D11.8 buka ulang | `SlipAdminTest::testBukaUlangDanPdfAdmin`, `UangMakanSnapshotTest`, `VerifikasiSlipTest::testTokenSahDanTokenLama` |
| AC-D11.9 total = GPP + TK + uang makan | `unit/…/RingkasanSlipTest`, `BukaSlipAtomikTest`, `SlipAdminTest`; README acuan `0cf7e67` |
| AC-D11.10 uang makan snapshot | `Slip/UangMakanSnapshotTest` |
| AC-D11.11 PDF tanpa QR bila tanpa token, nama berkas, header | `unit/Presensi/Slip/PdfSlipGajiTest`, `unit/…/FormatSlipTest`, `SlipPegawaiAksesTest` |

### D-14 Verifikasi publik
| AC | Bukti |
|---|---|
| AC-D14.1 token sah → data AS-06 | `Slip/VerifikasiSlipTest::testTokenSahDanTokenLama` |
| AC-D14.2 404 seragam (format, huruf besar, spasi) | `VerifikasiSlipTest::testTokenTidakSah404Seragam` |
| AC-D14.3 lookup hash, tanpa token mentah | `VerifikasiSlipTest`, `BukaSlipAtomikTest`, `SkemaPresensiGajiTest` |
| AC-D14.4–14.5 throttle 30/menit, noindex/no-store | `VerifikasiSlipTest::testThrottle429`, `ThrottlePublikFilterTest`; FE `VerifikasiSlipPage.spec` |
| AC-D14.6 satu-satunya route tanpa jwt | `Presensi/RoutePresensiKontrakTest`; FE `VerifikasiSlipPage.spec` (route publik) |
| AC-D14.7 snapshot saat terbit | `VerifikasiSlipTest::testTokenSahDanTokenLama` (data terkini diubah → hasil tetap) |

### D-02 Presensi online
| AC | Bukti |
|---|---|
| AC-D02.1 batas radius inklusif tersimpan | `Kehadiran/PresensiOnlineTest::testTepatDiBatasRadiusTersimpan`, `unit/…/PenilaianGeofenceTest` |
| AC-D02.2 luar radius 422 tanpa tulis | `PresensiOnlineTest::testDiLuarRadius422TanpaTulis` |
| AC-D02.3 zonasi | `Kehadiran/PresensiZonasiTest` |
| AC-D02.4 jendela | `Kehadiran/PresensiJendelaTest` |
| AC-D02.5 first-wins / last-wins / pulang tanpa masuk | `PresensiOnlineTest::testMasukFirstWinsPulangLastWins`, `::testPulangTanpaMasukLaluMasuk` |
| AC-D02.6 tanpa aturan bebas koordinat | `PresensiOnlineTest::testTanpaAturanTanpaSyaratKoordinat` |
| AC-D02.7 resolver | `Kehadiran/ResolverLokasiPresensiTest`, `PenilaianGeofenceTest`, `PresensiOnlineTest::testSalahSatuLokasiCukupDanKoordinatWajib` |
| AC-D02.8 role 403 + cuti tetap bisa | `Kehadiran/RoleMatriksPresensiTest` (**M8: `testMatriksRouteLengkap` route × role 1–8 + 401**) |
| AC-D02.9 cek-lokasi = rekam, tidak menulis | `PresensiOnlineTest::testCekLokasiSamaDenganRekamDanTidakMenulis` |
| AC-D02.10 konkurensi | `Kehadiran/PresensiKonkurensiTest` (`db-isolasi-penuh`) |

### D-03 Presensi manual
| AC | Bukti |
|---|---|
| AC-D03.1 hanya akun khusus, override `.env` | `Kehadiran/PresensiManualAksesTest`; M8 `RoleMatriksPresensiTest::testMatriksRouteLengkap` (role 1 bukan akun khusus → `bukan_akun_presensi_manual`; role lain 403 filter) |
| AC-D03.2–03.3 wajib nip/tgl, minimal satu jam | `Kehadiran/PresensiManualValidasiTest::testValidasiTambah` |
| AC-D03.4 duplikat 422 (pra-cek + tangkap 1062) | `PresensiManualValidasiTest::testTambahSumberManualTanpaJendelaDanDuplikat` (pra-cek); **M8: `PresensiKonkurensiTest::testTambahManualBersamaanDuplikat422`** (jalur 1062; uji mutasi: konstanta 1062 diubah → test gagal) |
| AC-D03.5 audit | `Kehadiran/PresensiManualAuditTest` |
| AC-D03.6 urutan jam, tgl ≤ hari ini | `PresensiManualValidasiTest::testValidasiTambah` |
| AC-D03.7 nip/tgl tetap, whitelist, sumber 2 | `PresensiManualValidasiTest::testTambahSumberManualTanpaJendelaDanDuplikat`, `::testUbahMengosongkanBuktiLokasiJamYangBerubah` |
| AC-D03.8 data admin: aksi hanya akun khusus, lingkup | `Kehadiran/DataPresensiAdminTest` |

### 5A-4 Adapter Tukin
| AC | Bukti |
|---|---|
| AC-TK.1 periode & libur | `TukinData/PeriodeDanLiburTukinTest` |
| AC-TK.2–TK.3 jam/puasa/tarif dari `web_config` | `TukinData/KonfigurasiTukinDariWebConfigTest` |
| AC-TK.4 ubah tarif → hasil berubah | `TukinData/UbahTarifWebConfigTest` |
| AC-TK.5 adapter pegawai/harian, cuti/TB fail-closed | `TukinData/DataPegawaiTukinAdapterTest`, `HarianTukinAdapterTest`, `TukinDataServiceTest` |
| AC-TK.6 library `Tukin` tidak berubah, kemurnian | `git diff 3326a63 origin/main -- backend/app/Libraries/Presensi/Tukin` kosong; `PemindaiKemurnianTest`, `SlipGajiMurniTest`, `GeoMurniTest`, `KalkulasiMurniTest` |

### FE 5A
| AC | Bukti |
|---|---|
| AC-FE.1 status lokasi tervisualisasi sebelum submit | `PresensiPage.spec` (8 test) + cek browser M7 (c) |
| AC-FE.2 pratinjau impor | `ImporPratinjau.spec` |
| AC-FE.3 slip pegawai empat keadaan | `SlipGajiPage.spec` |
| AC-FE.4 kelola slip ⋮ | `SlipGajiKelolaPage.spec` + cek browser (CR-068) |
| AC-FE.5 data presensi ⋮ akun khusus | `DataPresensiPage.spec`, `PresensiManualDialog.spec` + cek browser |
| AC-FE.6 menu per role `rilis` | `navPresensi.spec`, `shared/layouts/__tests__/nav.config.spec` |
| AC-FE.7 verifikasi publik | `VerifikasiSlipPage.spec` |
| AC-FE.8 responsif + payload string + tanpa logika bisnis | semua spec (payload string), `*.amplop.spec` (klien axios asli, CR-059); cek browser 1440/375 |
| AC-FE.9 QA Lapis 1 vs Figma | **Belum** — checklist `docs/fase5/Checklist_QA_Lapis1_FE5A_Q5A-18.md` untuk QA (D-12 tetap IN_PROGRESS) |

## 4. Checklist §12 (centang + bukti)

- [x] DBV-023 disetujui DBV (20-10) sebelum merge migration; DBV-022 di `main` (`598a5f3`); timestamp 5A lebih baru
- [x] `dh_online` PK + 1062; FK RESTRICT; kolom F5-12; `gaji_pegawai` tanpa kolom generik; `qr_history` FK + UNIQUE — `SkemaPresensiGajiTest`
- [x] Registry NIP & `NipCascadeService::FASE = 5`; `NipCascadeTest` memakai `FASE`
- [x] Impor 3 langkah, validasi ulang di commit, mismatch → audit, 1062 → 409 + rollback, timpa bawaan tercentang, berkas di `writable/` & terhapus — §3 D-10
- [x] Gerbang 09:59:59 / 10:00:00 semua jalur pegawai — `GerbangSlipTest`
- [x] Buka sekali & atomik, token baru, hash, riwayat token, nominal hanya di buka & PDF — §3 D-11
- [x] Admin: role 1 semua, role 3 satker (fail-closed), 4/5/8 403; koreksi & buka ulang diaudit — `SlipAdminTest`, `RoleMatriksSlipTest`
- [x] Total = GPP + TK + uang makan; tanpa tukin kelas jabatan; uang makan via `UangMakanSlipInterface` (5A: belum tersedia → 5B)
- [x] PDF: tanpa QR bila tanpa token; QR gambar; nama berkas tersanitasi — `PdfSlipGajiTest`, `FormatSlipTest`
- [x] Verifikasi publik: 404 seragam, throttle 30/menit, `noindex`, `no-store`, snapshot AS-06, tanpa nominal/NIP/IP — §3 D-14
- [x] Presensi online: role 2/6/7, jendela, zonasi, haversine `≤`, salah satu lokasi cukup, tanpa aturan bebas, first/last-wins — §3 D-02
- [x] Presensi manual: hanya akun khusus `Config\Presensi`, validasi [V2], duplikat 422 (termasuk 1062), hapus fisik + audit, whitelist — §3 D-03
- [x] Adapter: `web_config`, tarif kosong → galat terkendali, ubah tarif berubah, cuti/TB fail-closed, library `Tukin` tidak berubah — §3 5A-4
- [x] Kemurnian `SlipGaji`/`Tukin`/`Geo` hijau
- [x] AGENTS §1: ⋮ `RowActionsMenu`, urutan & label baku, `hidden` dari server, `ConfirmDialog`, aria-label, testid `slipgaji-actions-<id>` / `presensi-actions-<nip>-<tgl>`, Vitest via `data-action`; posisi menu di browser nyata setelah CR-068
- [x] Menu per role §7.8.2 lewat `rilis`; `ACTIVE_PHASE` tetap 3
- [x] Normalisasi string MySQLi di service FE; Vitest payload string; responsif dicek 1440 & 375 (600 lewat breakpoint; QA mengulang 1440/600/390)
- [x] Test DB `extends DatabaseTestCase`; DDL/konkurensi `db-isolasi-penuh`; DB scratch 3307; waktu disuntik
- [x] Akun uji `must_change_password = 0`
- [x] Tidak ada `transException(true)` di modul Presensi
- [x] Migration lama di `main` tidak diubah (`--diff-filter=MD` kosong)
- [x] Berkas bersama hanya aditif di commit terpisah; tidak ada berkas Fase 4 tersentuh
- [x] Commit Bahasa Indonesia `feat|fix|docs(scope): KEY …` tanpa trailer
- [x] Tanpa rahasia/IP/host internal/data pribadi nyata; data uji sintetis
- [x] Tanpa layanan eksternal; tanpa antrean push; 5A tidak membaca `riwayat_cuti`/`riwayat_tb` (fail-closed)
- [x] Dokumentasi endpoint, README modul, ADR-032, `05-Presensi.md` diperbarui
- [x] Tanpa pekerjaan MT; tanpa klaim "Fase 5 DONE"

## 5. Perubahan di M8 (MAKE-035)

Hanya celah test + dokumen, tanpa perubahan perilaku:

1. `tests/Presensi/Kehadiran/RoleMatriksPresensiTest::testMatriksRouteLengkap` — seluruh route `presensi*` × role 1–8
   dalam satu matriks (sebelumnya tersebar dan belum mencakup `GET presensi/pegawai/{nip}` untuk 2/6/7/8,
   `PUT presensi/manual/*` untuk 403, dan 401 tanpa token). Bersama `RoleMatriksSlipTest` (slip × 1–8),
   `ImporSlipAksesTest` (impor × 1–8), dan `RoutePresensiKontrakTest` (hanya verifikasi publik tanpa `jwt`), seluruh
   route 5A kini teruji × role 1–8 (§11.1 #4).
2. `PresensiManualService` mendapat seam opsional `kaitSebelumTulis` (bawaan `null`, pola sama dengan
   `PresensiOnlineService`) + `PresensiKonkurensiTest::testTambahManualBersamaanDuplikat422` — menutup "jalur tangkap
   1062" AC-D03.4 yang sebelumnya hanya teruji lewat pra-cek. Uji mutasi dilakukan.
3. Dokumen: laporan ini, checklist QA Lapis 1 (Q5A-18), `05-Presensi.md` (status & blocker).

## 6. Deviasi [V2] & keputusan yang berlaku

Semua tercatat di `05-Presensi.md` per milestone dan README modul; ringkas: F5-1..F5-24 dan default ◻ Q5A-04..Q5A-24
(disetujui 19-10); Q5A-01 opsi (a) kolom `qr_token_enkripsi`; SG-2 role 1/3 kelola slip; acuan slip naik ke legacy
`0cf7e67` (CR-067: TK tanpa Pajak/Tunjangan Pajak di slip pegawai & PDF; modal admin tetap lengkap); respons buka slip &
detail admin memuat identitas `pegawai` (aditif, ikut tampilan legacy); FE `cek-lokasi` maks sekali per pembacaan
lokasi (toleransi 500 ms); FE edit presensi manual mengirim jam yang tidak diubah apa adanya (Q5A-16 tetap ditegakkan
server).

## 7. Blocker & pertanyaan terbuka

- **QA Lapis 1 FE 5A (Q5A-18, AC-FE.9)** — checklist disiapkan untuk QA; D-12 tetap IN_PROGRESS sampai lolos.
- **CR-069** (gulir hanya di area tabel pada desktop ≥ 1024, header/filter tetap) masuk `main` `bddd089` dan sudah
  diterapkan CR-069 ke Kelola Slip Gaji tab Monitoring dan Data Presensi (daftar & detail); tab Impor tetap menggulir
  normal. M8 me-merge `main` lalu mengecek ulang di browser desktop 1440 px: halaman tidak menggulir, area tabel
  menggulir sendiri, judul & filter tetap di tempat, header kolom sticky di dalam area tabel, menu ⋮ terbuka di dalam
  viewport. **CR-070** (header kolom sticky di mobile) masih dikerjakan sesi lain → dicek di QA Lapis 1 (butir A4).
- **Q5A-13** (cross-check rumus Tukin) — prasyarat 5B (PRD 5B).

### 7.1 Blocker go-live (§11.2; bukan blocker 5A DONE)
1. Nilai uang makan slip (F5-1) — 5B D-09a (`UangMakanSlipPresensi`); sampai itu slip menampilkan uang makan "belum tersedia".
2. Konfirmasi Biro Keuangan atas data verifikasi publik AS-06 & format nama tersamar (Q5A-08).
3. Deploy: HTTPS (geolokasi browser hanya di konteks aman; Q5A-22), rute Nginx `/verify/slip/*` ke SPA,
   `Config\App::$proxyIPs` (IP asli untuk throttle & `viewed_ip`), `encryption.key` (token terenkripsi Q5A-01) —
   README controller "Catatan deploy".
4. Uji GPS perangkat nyata (HTTPS + ponsel) pada QA Lapis 1 (Q5A-22/Q5A-18).
5. Checksum kode legacy produksi (Q5A-14); cek ulang data `gaji_pegawai` produksi (DEPLOY-002.1); nama `jenis_pegawai`
   id 6 produksi (Q5A-25).
