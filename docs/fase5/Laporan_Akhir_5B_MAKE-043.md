# Laporan Akhir Paket 5B — M7 MAKE-043 (verifikasi 5B)

Acuan: `docs/fase5/PRD_Fase5B_Cuti-TB-LaporanTukin-UangMakan.md` v1.0 + log Q5B-24..27 (§11.1 DoD, §11.4 format laporan,
§12 checklist, §13 register, Lampiran C keterlacakan). Disusun 29-10-2026 dari `main` `9968dc0` (seluruh M1a–M6 5B sudah
di `main`).

> **5B DONE ≠ Fase 5 DONE** (PRD 5B §11.2). Laporan ini **tidak** menyatakan Fase 5 selesai atau siap go-live. 5B DONE
> menunggu sign-off reviewer CR setelah M7 (§11.1 #7). Fase 5 DONE hanya setelah 5C (FE laporan, D-13 suite lengkap, QA
> Lapis 1, paritas UP-1 data nyata) dan sign-off Horii.

## 1. Laporan (format §11.4)

```
Status: D-06 DONE (backend; D-04/D-05/D-07 → DONE backend setelah M1, §11.3),
        D-08 backend lengkap di main (menunggu sign-off 5B; FE = 5C),
        D-09a DONE (backend; blocker go-live F5-1 ditutup),
        D-09b backend lengkap di main (menunggu sign-off 5B; FE = 5C)
Branch/SHA/CI: fase5b/make-043-verifikasi-5b <SHA M7> — CI Quality gate <run> (lihat laporan ke reviewer)
               Milestone di main: M1a MAKE-044 7c40229, M1 MAKE-039 df10c0f, M2 MAKE-040 79641be, M3 DBV-024 e9f4616,
               M4 MAKE-045 ccb81f3, M6 MAKE-042 70a24b6, M5 MAKE-041 9968dc0 (CI #360 hijau); gate penuh ./check.sh
               terakhir lolos pada 8b1a374 (M5: 2.923 PHPUnit, 1.284 Vitest)
File utama: lihat §2
Test & validasi: §3 (AC → test); precedence/kombinasi: PrecedenceKombinasiTest, CutiSakitLookbackTest;
                 uang makan aturan hari/tarif: HariUangMakanTest, TarifUangMakanTest, UangMakanSlipPresensiTest;
                 laporan = rekap: RekapTukinTest::testBarisRekapSamaDenganLaporanPerPegawai;
                 cakupan role 3: CakupanLaporanTest, RekapTukinTest::testLingkupRole3DanJenisPegawai,
                 RekapUangMakanTest::testLingkupRole3DanUrutanPopulasi;
                 403 per role: RoutePresensiLaporanKontrakTest (M7, seluruh route 5B × role 1–8 + 401);
                 volume: ADR-035 (JSON 0,26 s / 40 MB; XLSX 5.000 baris 9,70 s / 148 MB; PDF 800 baris 5,96 s / 246 MB)
Berkas bersama: Config\Presensi (switch $sumberCutiTb, $sumberUangMakan, $tukinBatchNip, $pdfRekapMaksBaris,
                $unitTtd*), Config/Routing.php (berkas route 5B), KontrakMatriksRoleTest, TabelAnakNip — aditif, commit
                terpisah per milestone (rincian 05-Presensi.md); M7 dari main 9968dc0, tanpa berkas bersama
Deviasi [V2] & keputusan: §5
Blocker / pertanyaan terbuka: §6; blocker go-live: §6.1
```

## 2. Berkas utama 5B (di `main`)

| Bagian | Berkas |
|---|---|
| D-06 sumber cuti/TB (M1a, M1) | `Libraries/Presensi/TukinData/{SumberCutiTbFase4,SumberCutiTbMassalInterface}.php`, massal `SumberHarianTukin::untukBanyak`, `SumberDataPegawaiTukin::untukBanyak`, `TukinDataService::siapkanBanyak`; Fase 4 aditif `Layanan/Tb/{StatusTugasBelajar,BebasPresensiTb}.php` (`rentangBebasPresensiBanyak`, MAKE-044) |
| D-09a uang makan slip (M2) | `Libraries/Presensi/Rekap/HariUangMakan.php` (murni), `Libraries/Presensi/Slip/{UangMakanSlipPresensi,TarifUangMakan}.php` |
| DBV-024 (M3) | `Database/Migrations/2026-10-28-100000_CreatePenyesuaianTukin.php`, `docs/db-review/DBV-024-penyesuaian-tukin-schema.md`, `docs/db-review/sql/DBV-024-audit-penyesuaian-tukin.sql` |
| Penyesuaian Tukin CRUD (M4) | `Models/Presensi/PenyesuaianTukinModel.php`, `Libraries/Presensi/Laporan/PenyesuaianTukinService.php`, `Controllers/Api/Presensi/PenyesuaianTukinController.php` |
| D-08 laporan Tukin (M5) | `Libraries/Presensi/Rekap/{PenandaTangan,PenyesuaianTukin}.php` (murni), `Libraries/Presensi/Laporan/{KalkulasiLaporanTukin,RekapTukinService,LaporanTukinService,DaftarPegawaiTukinService,PejabatPenandaTangan,EksporTukinXlsx,KopLaporanTukin}.php`, `Views/pdf/{rekap_tukin,laporan_tukin_pegawai}.php`, `Controllers/Api/Presensi/LaporanTukinController.php` |
| D-09b rekap (M6) | `Libraries/Presensi/Rekap/{KlasifikasiKehadiran,JadwalKerjaRekap}.php` (murni), `Libraries/Presensi/Laporan/{CakupanLaporan,LingkupLaporan,PopulasiRekap,ParameterRekap,DataRekapBulan,RekapKehadiranService,RekapUangMakanService,EksporRekapXlsx}.php`, `Controllers/Api/Presensi/RekapController.php` |
| Route & konfigurasi | `Config/RoutesPresensiLaporan.php` (23 route), `Config/Presensi.php` |
| Paritas UP-1 (M7) | `Libraries/Presensi/Laporan/ParitasRekapTukin.php`, `Commands/PresensiParitasTukin.php` (`php spark presensi:paritas-tukin`) |
| Dokumen | `Controllers/Api/Presensi/README.md`, `Libraries/Presensi/README.md` (Uang makan slip, Laporan Tukin, Rekap 5B), `Libraries/Presensi/TukinData/README.md`, `docs/adr/ADR-035-ekspor-laporan-tukin-sinkron.md`, `backend/docs/progress/05-Presensi.md` (5B M0–M7, runbook 5B, prosedur UP-1) |

## 3. Keterlacakan AC → bukti

DoD Trello → AC mengikuti PRD Lampiran C.1 (tidak diulang). Tabel ini menutup langkah berikutnya: **setiap AC → test di
`main`** (atau di branch M7 bila ditandai **M7**). Path relatif ke `backend/tests/`.

### D-06 Sumber cuti & TB (QASMTASK-081)
| AC | Bukti |
|---|---|
| AC-D06.1 TB penuh pemutihan; TB berakhir di tengah; celah perpanjangan tidak bebas | `Presensi/TukinData/PrecedenceKombinasiTest::testTbPenuhPemutihan`, `::testTbBerakhirDiTengahLaluPresensiNormalDanCelahPerpanjangan`; `SumberCutiTbFase4Test::testTugasBelajarLewatAturanFase4DanCelahPerpanjangan` |
| AC-D06.2 cuti tahunan/AP/melahirkan/CLTN tanpa potongan harian | `PrecedenceKombinasiTest::testCutiTanpaPotonganHarian` (data provider per jenis) |
| AC-D06.3 cuti sakit 14/15, lookback lintas periode, tidak terputus cuti lain/libur, terputus presensi | `TukinData/CutiSakitLookbackTest` (4 test) |
| AC-D06.4 cuti besar 35/36/65/66 | `TukinData/CutiBesarMelahirkanPeriodeTest::testCutiBesarPeriodePotong` |
| AC-D06.5 melahirkan anak 3 / anak 4 40-70-80 | `CutiBesarMelahirkanPeriodeTest::testCutiMelahirkanAnakKeEmpat` |
| AC-D06.6 kombinasi bertumpuk satu status | `PrecedenceKombinasiTest::testKombinasiSatuStatusTanpaPotonganGanda` (per baris) |
| AC-D06.7 hanya `status = 1` | `SumberCutiTbFase4Test::testHanyaStatusSatuDanEkspansiDipotongRentang` |
| AC-D06.8 jenis di luar 1..6 → `DataTukinTidakSah` | `SumberCutiTbFase4Test::testJenisDiLuarSatuSampaiEnamGagalTerkendali` (+ `::testTumpangTindihIdTerbesarMenang` F5B-17) |
| AC-D06.9 `untukBanyak` = gabungan `untuk`, kueri tetap | `SumberCutiTbFase4Test::testUntukSamaDenganUntukBanyakDanGalatPerNip`, `TukinData/TukinDataMassalTest` (4 test, termasuk `::testJumlahKueriTetapTerhadapJumlahNip`), `Layanan/Tb/RentangBebasPresensiBanyakTest` |
| AC-D06.10 library `Tukin` tidak berubah; Fase 4 hanya massal aditif | `git diff --stat bb0648b origin/main -- backend/app/Libraries/Presensi/Tukin backend/app/Libraries/Presensi/SlipGaji` **kosong** (diperiksa M7); perubahan `Libraries/Layanan` dari 5B hanya `dddb266` (MAKE-044, commit tersendiri) |

### D-09a Uang makan slip (QASMTASK-086, F5-1)
| AC | Bukti |
|---|---|
| AC-D09a.1 switch sumber asli, snapshot tiga cabang | `Presensi/Slip/UangMakanSnapshotAsliTest::testBawaanKonfigurasiSumberAsli`, `::testNilaiPositifDitulis`, `::testNolMempertahankanNilaiLamaAtauMenulisNol` |
| AC-D09a.2 aturan hari (satu test per butir) | `unit/Presensi/Rekap/HariUangMakanTest` (10 test), `Slip/UangMakanSlipPresensiTest::testDipotongHariIniWib`, `::testTbBulanPenuhMenutupSisaBulanDanTbMulaiTengahBulanTidak` |
| AC-D09a.3 tarif PNS `web_config`, PPPK `gol_pppk`, "IV/a" → 0 | `Slip/TarifUangMakanTest` (7 test, termasuk `'IV/a tidak cocok'`, `::testKpTerbaruSampaiTanggalSatuDanUbahTarifLangsungBerlaku`, Q5B-24 `::testPppkTarifTidakBulatIkutLegacyTanpaGalat`) |
| AC-D09a.4 slip Januari = Desember tahun lalu | `UangMakanSnapshotAsliTest::testSlipJanuariMemakaiDesemberTahunSebelumnya` |
| AC-D09a.5 blocker go-live F5-1 ditandai selesai | `backend/docs/progress/05-Presensi.md` paragraf "Blocker go-live" (**M7**) |

### D-08 Laporan Tukin (QASMTASK-083)
| AC | Bukti |
|---|---|
| AC-D08.1 route × role 1–8; role 3 tidak bocor; tanpa satker/unit 403; 2/6/7 NIP lain 403; 404 | **M7 `Presensi/Laporan/RoutePresensiLaporanKontrakTest`** (seluruh 23 route 5B × role 1–8 + 401; router = matriks); `RoleMatriksLaporanTest`, `LaporanTukinPegawaiTest::testAksesPerRole`, `CakupanLaporanTest::testLingkup`, `RekapTukinTest::testLingkupRole3DanJenisPegawai` |
| AC-D08.2 laporan per pegawai = baris rekap | `RekapTukinTest::testBarisRekapSamaDenganLaporanPerPegawai` |
| AC-D08.3 filter & validasi 422 | `RekapTukinTest::testLingkupRole3DanJenisPegawai`, `::testValidasiBulan`, `DaftarPegawaiTukinTest::testDaftarLingkupFilterDanItem`, `CakupanLaporanTest` |
| AC-D08.4 periode berjalan dipotong; periode BKN tidak standar | `RekapTukinTest::testPeriodeBerjalanDipotongHariIni`; **M7 `RekapTukinTest::testPeriodeBknTidakStandarDipakai`** (rekap & laporan per pegawai memakai `bkn_periode_ekinper`, 21 hari kerja bukan 22) |
| AC-D08.5 XLSX header persis, nama berkas, content type | `RekapTukinXlsxTest::testTataLetakDanBlokPenandaTangan`, `RoleMatriksLaporanTest::testMatriksRoute` (unduhan HTTP) |
| AC-D08.6 PDF per pegawai (tanpa "UANG MAKAN", Rp hanya konket 13 dibayar) & PDF rekap (batas, lingkup `0`) | `LaporanTukinPegawaiTest::testJsonPersenTanpaUangMakan`, `::testPdfRupiahHanyaKonketKategori13YangDibayar`, `::testKopBerlogoIkutLegacy`, `KopLaporanTukinTest` (6 test, G1/G2); `RekapTukinPdfTest::testLingkupSatkerDanBatasBaris` |
| AC-D08.7 galat per pegawai tidak menggagalkan; tarif belum lengkap → semua baris galat | `RekapTukinTest::testTarifBelumLengkapSemuaBarisGalatDanUbahTarifBerlaku`, `RekapTukinXlsxTest::testBlokPenandaTanganSelaluAdaDanBarisGalat` |
| AC-D08.8 ubah tarif TL1/PSW1 → hasil berubah | `RekapTukinTest::testTarifBelumLengkapSemuaBarisGalatDanUbahTarifBerlaku` |
| AC-D08.9 uji volume + ADR-035, kueri tetap | `RekapTukinVolumeTest` (`RUN_VOLUME_TEST=1`), hasil di `docs/adr/ADR-035-ekspor-laporan-tukin-sinkron.md` §3; kueri tetap `TukinDataMassalTest::testJumlahKueriTetapTerhadapJumlahNip` |
| AC-D08.10 blok tanda tangan satu test per kasus | `unit/Presensi/Rekap/PenandaTanganTest` (6 test), `Laporan/PenandaTanganRekapTest` (5 test), `RekapTukinIlTest` (menteri), `RekapTukinXlsxTest::testBlokPenandaTanganSelaluAdaDanBarisGalat` |
| AC-D08.11 penyesuaian: efek tanpa ubah `total_potongan`, status 2/10 diabaikan, CRUD role 1 + audit, ≤ 2024-03 422 | `PenyesuaianTukinEfekTest::testEfekTanpaMengubahTotalPotongan`, `unit/Presensi/Rekap/PenyesuaianTukinTest`, `PenyesuaianTukinCrudTest` (8 test), `PenyesuaianTukinKonkurensiTest` (C-1), `database/Presensi/SkemaPenyesuaianTukinTest` |

### D-09b Rekap kehadiran & uang makan (QASMTASK-084)
| AC | Bukti |
|---|---|
| AC-D09b.1 lingkup role 3 dipaksa; role 1/4 `0`/unit/satker | `Presensi/Rekap/RekapKehadiranTest::testKolomRingkasDanLingkup`, `RekapUangMakanTest::testLingkupRole3DanUrutanPopulasi` |
| AC-D09b.2 rekap uang makan per baris [CATAT] F5B-2 | `RekapUangMakanTest::testKonketKategori13DibayarKategoriLainTidak`, `::testTbPenandaMelekatSampaiAkhirBulan`, `::testPerpanjanganTbTerbacaDanTbMulaiTengahBulan`, `::testPegawaiBaruDalamBulanDihitung` |
| AC-D09b.3 bentuk `NIP \| TGL_ABSEN` tanpa nominal | `RekapUangMakanTest::testBentukTanpaNominalDanSamaDenganSnapshotSlip`, `::testVarianIlDanXlsx` |
| AC-D09b.4 jumlah baris = `uang_makan_hari` snapshot slip M+1 | `RekapUangMakanTest::testBentukTanpaNominalDanSamaDenganSnapshotSlip` |
| AC-D09b.5 klasifikasi per cabang + boundary TL/PSW 30/60 (normal/puasa, Jumat/bukan) | `unit/Presensi/Rekap/KlasifikasiKehadiranTest` (5 test), `RekapKehadiranTest::testJamWebConfigDanPuasaBerlaku` |
| AC-D09b.6 route × role 1–8; XLSX dibaca ulang | **M7 `RoutePresensiLaporanKontrakTest`**, `RoleMatriksRekapTest` (2 test), `RekapKehadiranTest::testXlsxRingkasDanHarianDibacaUlang`, `RekapUangMakanTest::testVarianIlDanXlsx` |

### AC-5B.X1 & kemurnian
| Butir | Bukti |
|---|---|
| `Libraries/Presensi/Tukin/**`, `SlipGaji/**` tidak berubah | `git diff --stat bb0648b origin/main -- …/Tukin …/SlipGaji` kosong (M7) |
| Folder murni `Rekap` | `unit/Presensi/Rekap/RekapMurniTest::testFolderRekapMurni` (+ penjaga kemurnian 5A `PemindaiKemurnianTest`) |
| Route 5B lengkap di matriks | **M7** `RoutePresensiLaporanKontrakTest::testRouterSamaDenganMatriks` (+ baris `feature/KontrakMatriksRoleTest`) |

## 4. Checklist §12 (centang + bukti)

- [x] `Libraries/Presensi/Tukin/**` dan `SlipGaji/**` tidak berubah; berkas Fase 4 hanya metode massal aditif M1a (`dddb266`)
- [x] Cuti hanya `status = 1`; jenis 1..6; di luar fail-closed; tumpang tindih deterministik (id terbesar) — §3 D-06
- [x] TB lewat aturan Fase 4 (`BebasPresensiTb` / `rentangBebasPresensiBanyak`), tanpa salinan aturan; celah perpanjangan tidak bebas
- [x] Massal: jumlah kueri tetap terhadap jumlah pegawai; galat per pegawai tidak menggagalkan rekap
- [x] Uang makan: bulan N−1, aturan hari §7.2.2, tarif PNS `web_config` / PPPK `gol_pppk`, integer sen (Q5B-24); snapshot 5A tidak berubah
- [x] Laporan per pegawai = baris rekap; periode berjalan dipotong hari ini; galat adapter → 422 ber-`code`
- [x] Role 3 dipaksa satkernya di server untuk semua laporan/rekap; 2/6/7 hanya NIP sendiri; 8 → 403
- [x] XLSX header persis; nama berkas & content type benar; PDF batas baris & lingkup
- [x] Uji volume tercatat (ADR-035, Diterima 28-10-2026)
- [x] Blok tanda tangan selalu ada dengan teks kasus; satu test per kasus
- [x] DBV-024 disetujui DB Validator (28-10-2026, PR #30); `penyesuaian_tukin.nip` di registry NIP; CRUD role 1 + audit; `total_potongan` tidak berubah oleh penyesuaian
- [x] Rekap uang makan `NIP | TGL_ABSEN` dengan aturan hari slip; tanpa nominal
- [x] Parameter via binding (satu-satunya `query()` mentah 5B memakai `?` + identifier ter-escape: `PenyesuaianTukinService` `FOR UPDATE`); tidak ada `display_errors` / `transException(true)` di `Libraries/Presensi`, `Controllers/Api/Presensi`, `Models/Presensi`, `Commands` (grep M7)
- [x] Test DB `extends DatabaseTestCase`; waktu disuntik; data sintetis; DB scratch 3307; `.env` dev tidak disalin (grep M7: tidak ada test 5B ber-DB di luar `DatabaseTestCase`)
- [x] Commit Bahasa Indonesia `feat|fix|docs|test(scope): KEY …`, tanpa trailer & tanpa penyebutan AI
- [x] Tidak ada rahasia/IP/host internal/data pribadi nyata/detail celah di diff; alat paritas menyamarkan NIP
- [x] README endpoint, README modul, progres, ADR-035 diperbarui; tidak ada klaim "Fase 5 DONE"

## 5. Perubahan di M7 (MAKE-043)

Hanya celah test, alat paritas, dan dokumen; **tanpa perubahan perilaku endpoint**:

1. `tests/Presensi/Laporan/RoutePresensiLaporanKontrakTest` — seluruh 23 route 5B × role 1–8 dalam satu matriks HTTP
   (role berhak 200, atau 404/422 untuk tulis penyesuaian tanpa isi; role lain 403; tanpa token 401) dan daftar route 5B
   di router harus sama persis dengan matriks. Menutup `GET presensi/tukin/rekap/pdf` dan
   `GET presensi/tukin/laporan/{nip}/pdf` yang sebelumnya belum teruji per role lewat HTTP.
2. `RekapTukinTest::testPeriodeBknTidakStandarDipakai` — AC-D08.4 bagian "periode BKN tidak standar dipakai" pada lapisan
   laporan (sebelumnya hanya teruji di adapter 5A `PeriodeDanLiburTukinTest`).
3. Alat paritas UP-1: `Laporan\ParitasRekapTukin` + `php spark presensi:paritas-tukin <legacy.xlsx> <v2.xlsx>` (hanya
   membaca dua berkas, NIP tersamar, CSV opsional, kode keluar 0/1/2) + `unit/Presensi/Laporan/ParitasRekapTukinTest`.
4. Dokumen: laporan ini; `05-Presensi.md` (status D-04..D-09, blocker F5-1 selesai, blocker go-live 5B, bagian M7,
   runbook operasional 5B R5B-1..4 termasuk **C-2 audit B1**, prosedur paritas UP-1); README modul (alat paritas); log PRD
   Q5B-27 lanjutan (G1/G2).

## 6. Deviasi [V2] & keputusan yang berlaku

Semua tercatat di README `Libraries/Presensi` (bagian Uang makan slip, Laporan Tukin, Rekap 5B), README `TukinData`, PRD
5B §5.3 dan §13, serta `05-Presensi.md` per milestone. Ringkas: F5B-1..F5B-23 (Horii 28-10-2026); F1 varian massal aditif
`untukBanyak` (deviasi §3.3, disetujui M0); F2 Plt status 1 tanpa Plh, urutan deterministik; F3 unit legacy '6'/'7' →
konstanta `Config\Presensi`; Q5B-24 tarif `gol_pppk` tak bulat ikut legacy; Q5B-25/26 rekap kehadiran ringkas ikut legacy
(TB tidak diperiksa; presensi pegawai baru tidak dibaca); Q5B-27 kop PDF per pegawai berlogo, **lanjutan 29-10-2026: cabang
alamat & logo kanan G1/G2 ikut legacy** (`L_presensi.php:6512-6521`, `:7105-7116`; [V2] logo satker tidak sah → logo
wonderful); ADR-035 batas PDF rekap 800 baris (diturunkan dari 1.700 menurut uji volume); DBV-024 registry `NonFkFkV2`
(deviasi kecil dari dokumen, diterima reviewer); C-1 kunci `FOR UPDATE`, 1205/1213 → 409 `konflik_data`.

## 7. Blocker & pertanyaan terbuka

- Tidak ada pertanyaan ⛔ 5B yang belum terjawab (register §13 seluruhnya diputus Horii 28-10-2026 + log Q5B-24..27).
- **Sign-off reviewer CR 5B** (§11.1 #7) — menunggu laporan ini.
- 5C: FE laporan/rekap/penyesuaian, D-13 suite lengkap, QA Lapis 1, paritas UP-1 data nyata (MAKE-054 memakai prosedur &
  alat M7).

### 7.1 Blocker go-live (PRD 5B §11.2; bukan blocker 5B DONE)

Q5A-08 (AS-06), Q5A-14 / Q5B-15 (checksum kode produksi), Q5A-25 (nama `jenis_pegawai` id 6 produksi), **Q5B-01** (hasil
cek Biro SDM/Keuangan X-1, X-5, X-6, X-10, X-12), **Q5B-03** (konfirmasi penanda TB uang makan), **Q5B-09** (paritas UP-1
data nyata — prosedur & alat siap, `05-Presensi.md`), **Q5B-19** (uang makan PTT/golongan di luar I–IV), isi tabel
`penyesuaian_tukin` (F5B-21, lewat CRUD) dan tarif uang makan/`gol_pppk` produksi, runbook 5B R5B-1 (audit C-2), R5B-3
(batas PDF diukur di produksi), R5B-4 (id unit penanda tangan); ditambah blocker go-live 5A yang masih berlaku
(`05-Presensi.md`).
