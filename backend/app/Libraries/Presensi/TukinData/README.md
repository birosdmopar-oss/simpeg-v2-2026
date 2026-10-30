# Adapter data Tukin (`Libraries/Presensi/TukinData`) — Fase 5A M6 (MAKE-033), 5B M1 (MAKE-039)

Kode ber-DB yang menyiapkan argumen `KalkulasiTukin::hitung()` (library murni `Presensi/Tukin`, tidak diubah) dari data
v2. Acuan: PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` §7.7. Legacy = rekap aktif `laporan_tukin_us_skp`
(`libraries/hr/L_presensi.php`, K-1) dan pengambil data pegawainya `L_employee::get_all_pegawai_dh_spe`
(`libraries/hr/L_employee.php`), salinan legacy `771f8b4`. 5A **tidak** membuat endpoint Tukin (D-08 = 5B).

## Kontrak

`TukinDataService::siapkan(nip, tahun, bulan, ?hariIni, tanpaPegawaiBaru = false)` → `ArgumenTukin {periode, hariLibur,
harian, konfigurasi, pegawai, tanpaDataHarian}`. `tanpaPegawaiBaru` = varian rekap IL (tanpa aturan pegawai baru, CR-083);
`tanpaDataHarian` = T-01. `siapkan()` tidak dipakai jalur produksi (laporan & rekap memakai `siapkanBanyak`), tetapi
dipertahankan sebagai implementasi acuan per NIP: test kesetaraan membandingkan `siapkanBanyak()` dengan `siapkan()`,
jadi parameternya sama (`siapkanBanyak(nip[], tahun, bulan, ?hariIni, ?ukuranBatch, tanpaPegawaiBaru = false)`). Di bawahnya
`SumberHarianTukin::untuk(nip, awal, akhir, hanyaCutiTb = false)` / `untukBanyak(nip[], awal, akhir, hanyaCutiTb[] = [])`
(pegawai `lewatiLoopHarian`: presensi & konket tidak dimuat) dan `SumberDataPegawaiTukin::untuk/untukBanyak(...,
tanpaPegawaiBaru = false)`. Rentang hari libur & harian = awal periode **sebelumnya** s.d. akhir periode berjalan (lookback cuti sakit,
README `Presensi/Tukin` "Kontrak data"). Galat terkendali = turunan `GalatDataTukin`:

| Kelas | Kapan |
|---|---|
| `KonfigurasiTukinTidakLengkap` | Tarif wajib `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK` belum diatur di Web Config (tidak pernah diganti 0) |
| `RentangPuasaGanda` | Lebih dari satu `rentang_puasa` beririsan dengan periode |
| `DataTukinTidakSah` | Baris `bkn_periode_ekinper` ganda/tidak berformat `MM-DD`, periode BKN tidak berakhir di bulan itu, pegawai tidak ada, nilai ditolak library (mis. tarif 0,01% → faktor 0,2 tidak bulat), atau jam `dh_online` tidak sah |
| `DataTukinBelumTersedia` | Sumber cuti/TB fail-closed 5A (`SumberCutiTbBelumTersedia`; tidak lagi bawaan sejak 5B M1) |

Sumber cuti/TB dipilih `Config\Presensi::$sumberCutiTb`: sejak **5B M1 (MAKE-039)** = `SumberCutiTbFase4` (data Fase 4,
bagian "Cuti & tugas belajar (5B)" di bawah); sebelumnya `SumberCutiTbBelumTersedia` (5A dilarang membaca
`riwayat_cuti`/`riwayat_tb`).

**Jalur massal (5B M1, PRD 5B §7.1.4):** `TukinDataService::siapkanBanyak(list<nip>, tahun, bulan, ?hariIni, ?ukuranBatch)`
→ `array<nip, ArgumenTukin|GalatDataTukin>`. Periode, libur, dan konfigurasi dihitung sekali; harian & data pegawai dimuat
per batch `Config\Presensi::$tukinBatchNip` (bawaan 500, override `.env` `presensi.tukinBatchNip`) lewat varian massal
aditif `SumberHarianTukin::untukBanyak()` dan `SumberDataPegawaiTukin::untukBanyak()` (keputusan reviewer CR M0 F1 —
deviasi aditif PRD 5B §3.3; metode tunggal tidak berubah perilaku, aturan pemetaan memakai helper bersama). Jumlah kueri
**tetap** terhadap jumlah NIP; galat satu pegawai (jam tidak sah, jenis cuti di luar 1..6, pegawai tidak ada) menjadi
nilai NIP itu saja; konfigurasi tarif belum lengkap → semua NIP `KonfigurasiTukinTidakLengkap`. Urutan galat per NIP =
`siapkan()` (harian → konfigurasi → data pegawai). Test: `TukinDataMassalTest` (kesetaraan tunggal vs massal + hitung
kueri lewat event `DBQuery`).

## Cuti & tugas belajar (5B M1, `SumberCutiTbFase4`, PRD 5B §7.1)

| Butir | Aturan | Label / rujukan |
|---|---|---|
| Cuti | `riwayat_cuti` **`status = 1` saja** (0/2/3/4 diabaikan, Fase 4 §8) yang beririsan [awal, akhir]; setiap tanggal kalender `tgl_mulai..tgl_akhir` di dalam rentang (termasuk akhir pekan/libur) → `cuti {jenis, mulai, akhir, lama}` | [K] `L_presensi.php:12201-12267` |
| Jenis | `id_jenis_cuti` 1..6 → `tahunan`, `besar`, `sakit`, `melahirkan`, `alasan_penting`, `cltn` (kebalikan `StatusHarian::JENIS_CUTI`); di luar 1..6 → `DataTukinTidakSah` | [V2] F5B-17 (legacy diam-diam membebaskan) |
| Tumpang tindih | Tanggal yang sama dari dua cuti → `id_riwayat_cuti` terbesar | [V2] F5B-17 (legacy: `tgl_mulai` terbesar, seri tak pasti) |
| Tugas belajar | Tanggal `service('statusTugasBelajar')->rentangBebasPresensiBanyak()` (Fase 4 MAKE-044: TB status 1 + perpanjangan, celah tidak bebas) → `tugas_belajar = true`; aturan TB tidak disalin | PRD 5B §7.1.3, F5B-18 |
| Massal | `untukBanyak(list<nip>)`: satu kueri `riwayat_cuti` per 500 NIP + kueri TB Fase 4; `untuk()` = `untukBanyak([nip])[nip]` | PRD 5B §7.1.1 |

Test: `SumberCutiTbFase4Test`, `PrecedenceKombinasiTest` (AC-D06.1/.2/.6), `CutiSakitLookbackTest` (AC-D06.3),
`CutiBesarMelahirkanPeriodeTest` (AC-D06.4/.5), `TukinDataMassalTest` (AC-D06.9).

## Aturan per sumber

| Sumber | Aturan | Label / rujukan |
|---|---|---|
| Konfigurasi | Tarif dari `WebConfigService::values()`; decimal (float) dikembalikan ke string kanonik ≤ 2 desimal karena `KonfigurasiTukin` menolak float. Tarif opsional = `KonfigurasiTukin::TARIF_BAWAAN`; `LKH` tidak dipakai. Jam: 6 key `time` grup "Jam Kerja & Puasa" (F5-16). Puasa: rentang `rentang_puasa` (`date_range_list`) yang beririsan dengan periode | PRD §7.7.2–7.7.3 |
| Periode | `bkn_periode_ekinper` `tahun` = T, `bulan` = MM dua digit, `jenis_periode IS NULL` (tanpa saring `status`); awal/akhir = `T-{periode_awal}`/`T-{periode_akhir}`; Januari mulai 16 Desember; tanpa baris → 16 bulan lalu s.d. 15 | [K] `L_presensi.php:11852-11876` |
| Hari libur | `HariLiburService::tanggalLibur` (status aktif), DATE tanpa konversi zona; akhir pekan oleh `HariKerja` | PRD §7.7.4 |
| Predikat SKP | `riwayat_skp_periodik` `status = 1`, `periode_id` = id baris BKN, `hasil_akhir IS NOT NULL`, huruf kecil; tanpa baris BKN/data → null | [K] `L_presensi.php:12397-12401` |
| Jumlah anak | `detail_anak` `tgl_lahir` ≤ akhir periode milik `riwayat_keluarga` `status = 1` **dan `tgl_perkawinan` ≤ akhir periode** (syarat perkawinan tidak disebut PRD; disetujui reviewer CR 20-10-2026 sebagai perilaku legacy) | [K] `L_presensi.php:12170-12198` |
| TMT keluar | `pegawai.tmt_status` bila `pegawai.status = 2` | [V2] README Tukin |
| TMT masuk | Lihat bagian berikut | [K] + satu [V2] |
| Konket | `absen_ijin` `status = 'V'`, `affect_tukin = 1`, rentang `DATE(date_start)`..`DATE(date_end)` beririsan; setiap tanggal mendapat `{affect_tukin, kategori}`; tanggal tumpang tindih → baris terakhir menurut `date_start` naik | [K] `L_presensi.php:12343-12373` |
| **T-01** pegawai dibuat pada hari terakhir periode | Cuti, TB, konket, dan presensi hanya dimuat bila `peg.created_at < '<akhir periode BKN>'` — DATETIME dibanding string tanggal (= pukul 00:00:00 jam dinding WIB legacy) — padahal populasi rekap `DATE(created_at) <= akhir` (`L_employee.php:6414`). Pegawai yang dibuat pada hari terakhir periode (**jam dinding WIB** pukul berapa pun) masuk rekap tanpa data harian: `harian = []`, semua hari kerja TK; data tidak sah miliknya juga tidak dibaca. `created_at` v2 = **UTC** (`appTimezone` UTC; impor DBV-011 R1a WIB → UTC), jadi hari terakhir `akhir` WIB = rentang UTC `[akhir−1 17:00:00, akhir 17:00:00)` (`App\Libraries\Waktu\StempelUtc`, ramah indeks; populasi `PopulasiRekap` memakai batas yang sama). `SumberDataPegawaiTukin::tanpaDataHarian` (satu kueri per 500 NIP) dipakai `TukinDataService::siapkan`/`siapkanBanyak`; `ArgumenTukin::tanpaDataHarian` meneruskannya ke laporan per pegawai (tanpa jam/konket/keterangan cuti). Saringan dibatasi irisan populasi dan saringan legacy (hari terakhir saja); pegawai yang dibuat setelah akhir periode tidak masuk populasi rekap legacy, jadi laporan per pegawai v2 untuknya tidak diubah. **Bergantung GAP-2** (nilai `created_at` impor = WIB legacy yang dikonversi ke UTC; menunggu DBV). CR-083, keputusan Horii 29-10 & 30-10 | [K] `L_presensi.php:12209`, `:12283`, `:12346`, `:12383` |
| Presensi | `dh_online` per `tgl` dalam [awal periode sebelumnya, akhir periode], **tanpa saring `sumber`** (online, manual, impor legacy sama); `jam_masuk`/`jam_pulang` lokal apa adanya → `{masuk, pulang}`, NULL → null; tanggal tanpa baris → tanpa kunci `presensi` (library: TK); jam di luar 00:00:00–23:59:59 → `DataTukinTidakSah`. Legacy memuat bulan kalender N−1 dan N (`MONTH/YEAR(tgl)`); periode berjalan 16–15 tercakup penuh, hari periode sebelumnya hanya dipakai lookback cuti sakit yang tidak membaca presensi | [K] `L_presensi.php:12376-12391`, `:12850-12851` |

## TMT masuk pegawai baru (keputusan reviewer CR 20-10-2026)

1. `gol_ruang` dan `tmtsk_pangkat` = baris `riwayat_kp` `status = 1` terakhir (`tmtsk DESC`) dengan `tmtsk` ≤ **awal**
   periode. Bila tidak ada dan pegawai dibuat (`created_at`) setelah akhir periode, batasnya ≤ **akhir** periode.
   [K] `L_employee.php:6439`, `:6453`, `:6555-6560`.
2. PPPK (`id_jenis_pegawai` = `Jabatan::JENIS_PEGAWAI_PPPK` = 6): kedua nilai ditimpa `gol_pppk` dan `tmtsk` dari
   `riwayat_mutasi_jabatan` (bukan snapshot `pegawai_mutasi_jabatan`) `status = 1`, `jenis_jabatan = 1` terakhir
   (`tmtsk DESC`) dengan `tmtsk` ≤ akhir periode. Batas ini = `$maxDateCreatedPeg` legacy (`$strCM_end_BKN`), bukan
   `$maxRwyDate` yang dikomentari di kueri itu. [K] `L_employee.php:6470-6482` (kueri), `:6580-6584` (penimpaan memakai
   `id_jenis_pegawai == JP_PPPK`); dikonfirmasi reviewer CR 20-10-2026.
3. Rekap: `gol_ruang` lebih dari 5 karakter diawali "CPNS" → `tmtMasuk = tmtsk_pangkat`; selain itu PPPK →
   `tmtMasuk = tmtsk_pangkat` bila **setelah** hari kerja pertama periode (`$firstDayHK_CM`, dihitung `HariKerja` atas
   periode + libur); lainnya null. [K] `L_presensi.php:12703-12716`.
4. **[V2]** Cabang PPPK di rekap (langkah 3) mengenali PPPK lewat **id** (`Jabatan::JENIS_PEGAWAI_PPPK`), bukan teks
   `jenis_pegawai == 'PPPK'` seperti legacy `L_presensi.php:12707-12708`. Teks yang dibandingkan legacy adalah kolom
   **per baris** `pegawai.jenis_pegawai` (disalin ke data pegawai di `L_employee.php:6550`, ditulis saat biodata disimpan
   `:993-996`), bukan nama di master `jenis_pegawai`. Alasan id: teks per baris bisa basi atau berbeda dari master (diubah
   lewat CRUD/biodata), sehingga cek teks bisa gagal diam-diam; legacy sendiri memakai id di tempat lain (`:12621`,
   `L_employee.php:6580`). Satu pengenal (id 6) dipakai di semua langkah. **Kesetaraan dengan legacy hanya bila** setiap
   baris `pegawai` ber-`id_jenis_pegawai = 6` bertuliskan `jenis_pegawai` tepat `'PPPK'` dan tidak ada baris ber-id ≠ 6
   bertuliskan `'PPPK'` (CR-083; cek data per baris ada di runbook UP-1).
5. **T-02** [K] (CR-083, keputusan Horii 29-10): CPNS (langkah 3) dengan `tmtsk_pangkat` kosong → `tmtMasuk = null`
   dan `lewatiLoopHarian = true` — legacy `new DateTime('')` = saat ekspor, sehingga seluruh hari loop harian dilewati
   (rincian di README `Presensi/Tukin`). `L_presensi.php:12703-12705`, `:12739-12743`. Untuk pegawai ini presensi dan
   konket **tidak dimuat** (legacy tidak pernah membacanya; data tidak sah di sana bukan galat), cuti & TB tetap dimuat dan
   divalidasi (cuti sepanjang periode, `PotonganCutiPeriode`, pemutihan TB; F5B-17) — keputusan Horii 30-10. Catatan: lewat basis data cabang
   ini tidak terbentuk karena `riwayat_kp.tmtsk` `NOT NULL` dan disaring `tmtsk <=` (legacy sama, `L_employee.php:6439`),
   sehingga `gol_ruang` CPNS selalu membawa TMT; aturan ditanam agar setara bila data impor berbeda.
6. **PPPK tanpa TMT** [K] (CR-083, keputusan Horii 29-10 malam): PPPK (id 6, [V2] langkah 4) yang TMT-nya kosong
   setelah langkah 1–2 (tidak ada baris `riwayat_mutasi_jabatan` maupun `riwayat_kp` yang memberi TMT) →
   `lewatiLoopHarian = true`: legacy `new DateTime('')` = saat ekspor > hari kerja pertama → `isPegBaru`, seluruh hari loop
   dilewati. Periode tanpa hari kerja: tanda tidak dipasang (legacy `$firstDayHK_CM` kosong = saat ekspor juga; loop tanpa
   hari kerja, hasil sama). Cek cuti sepanjang periode tidak terpengaruh (`:12667`). `L_presensi.php:12707-12710`,
   `:12739-12743`.
7. **IL** [K] (CR-083, keputusan Horii 30-10): rekap IL (`laporan_tukin_us_skp_il`, `L_presensi.php:13494-15452`) tidak
   punya blok pegawai baru, jadi langkah 3–6 tidak berlaku: `tmtMasuk = null`, `lewatiLoopHarian = false`
   (`tanpaPegawaiBaru`). Ini juga menutup selisih lama `tmtMasuk` di IL. Varian IL juga menghitung jumlah anak s.d.
   `de_tukin` = min(akhir periode, hari ini) (`:13840, :13851`) dan memuat libur atas rentang penuh cuti yang dimuat
   (`:14016-14036`) — CR-085; kalkulasinya `KalkulasiTukinIl` (README `Presensi/Tukin`).

Risiko yang tercatat: hasil langkah 1–2 bergantung pada kelengkapan `riwayat_kp`/`riwayat_mutasi_jabatan` hasil impor;
butir cutover dicatat reviewer CR di PRD 5A dan runbook impor.
