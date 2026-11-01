# UP1-11 — Riset statis paritas Tukin (legacy rekap vs v2)

Lingkup UP-1 = **C2** (keputusan Horii 29-10-2026): bukti utama = riset statis ini (B) + paritas sintetis (A, UP1-12);
paritas data nyata menjadi kriteria lulus gladi cutover. Dokumen ini hanya membaca kode; tidak memakai data nyata,
tidak menjalankan legacy, dan tidak membuka berkas konfigurasi legacy (`constants.php`, `database.php`, `config.php`).

## Acuan

- **Legacy:** `application/libraries/hr/L_presensi.php`, method rekap `laporan_tukin_us_skp` (:11752–13492), dan
  populasi `application/libraries/hr/L_employee.php::get_all_pegawai_dh_spe` (:6398). Bagian :13139–13492 hanya format
  keluaran (XLSX/PDF) dan tidak dibahas di sini.
- **v2:** library murni `backend/app/Libraries/Presensi/Tukin/` (`KalkulasiTukin`, `StatusHarian`, `PotonganCutiPeriode`,
  `HariKerja`, `JamKerja`, `PeriodeTukin`, `KonfigurasiTukin`, `DataPegawaiTukin`), adapter
  `Presensi/TukinData/`, dan rekap `Presensi/Laporan/RekapTukinService.php`, `Presensi/Rekap/PenyesuaianTukin.php`.
- Pemetaan aturan yang sudah ada: README `Presensi/Tukin` (tabel aturan, perbedaan [V2]) dan README `Presensi/TukinData`.
  Riset ini **memverifikasi ulang** pemetaan itu terhadap kode legacy dan menambah temuan yang belum tercatat.
- Label status: **SAMA** (perilaku setara), **BEDA-V2** (beda disengaja dan sudah tercatat), **BEDA-BARU** (beda yang
  belum tercatat di README/PRD), **CATAT** (setara di kode, tetapi berisiko pada data nyata atau pada uji paritas).

## 1. Tabel butir

| # | Butir | Legacy | v2 | Status |
|---|---|---|---|---|
| B-01 | Periode: baris `bkn_periode_ekinper` (`jenis_periode IS NULL`), fallback 16 bulan lalu – 15 bulan berjalan | `:11851-11878` | `PeriodeTukin::dariBulan`, `SumberPeriodeTukin` | SAMA |
| B-02 | Januari: awal selalu 16 Desember tahun lalu | `:11859-11862` | `PeriodeTukin::dariBulan` | SAMA |
| B-03 | Loop harian dipotong ke hari ini | `:11881-11885` | parameter `hariIni` | SAMA |
| B-04 | Hari kerja = bukan libur dan bukan Sabtu/Minggu | `:12749-12757` | `HariKerja::hariKerja` | SAMA |
| B-05 | Precedence harian: masa kerja → libur/akhir pekan → TB → cuti → konket → presensi | `:12739-12849` | `KalkulasiTukin::hitung`, `StatusHarian::evaluasi` | SAMA (urutan masa kerja vs libur ditukar, tetapi keduanya hanya "lewati" sehingga hasil sama) |
| B-06 | Jam standar 07:30–16:00, Jumat 16:30; puasa 08:00–15:00, Jumat 15:30 | `:12722-12725` | `JamKerja::untuk` | SAMA |
| B-07 | Rentang puasa | hard-code **satu** rentang `2026-02-19..2026-03-21` untuk semua periode (`:12600-12601`) | `rentang_puasa` per tahun dari Web Config | BEDA-V2 (README [V2] #3) — lihat T-03 |
| B-08 | Selisih menit `DateInterval` h·60+i, detik dibuang ke arah nol | `:12872-12884` | `JamKerja::selisihMenit` (`intdiv`, ke arah nol) | SAMA (dicek juga untuk selisih negatif < 1 menit: keduanya 0) |
| B-09 | Golongan TL/PSW 1–30 / 31–60 / > 60 | `:12888-12926` | `StatusHarian::golongan` | SAMA |
| B-10 | Kompensasi TL saat hadir lengkap: TL2 gugur bila `psw <= -60`, TL1 bila `psw <= -30`, TL3 tidak gugur | `:12895`, `:12902` | `StatusHarian::presensi` | SAMA |
| B-11 | TPM (tanpa masuk) = TA + PSW; TPP (tanpa pulang) = TA + TL tanpa kompensasi; tanpa keduanya = TK | `:12853-12859`, `:12933-13020` | `StatusHarian::presensi` | SAMA |
| B-12 | Faktor presensi 0,2 × tarif `web_config` | `:12854`, `:12889-13015` | `KonfigurasiTukin::potonganHarian` (basis poin) | SAMA (v2 integer, legacy float — selisih pembulatan ditangani toleransi `ParitasRekapTukin`) |
| B-13 | Tanpa potongan LKH | `:12654` (`total_lkh` tak pernah ditambah) | LKH dihapus | SAMA |
| B-14 | Konket: hanya `status='V'`, `affect_tukin='1'`; hari konket bebas potongan; `kategori=13` hanya uang makan | `:12343-12373`, `:12828-12843` | `SumberHarianTukin`, `StatusHarian::konketBebas` | SAMA |
| B-15 | Cuti sakit: 1% × 0,2 per hari kerja mulai hari ke-15 berurutan | `:12796-12804` | `StatusHarian::evaluasi` (`BATAS_HARI_CUTI_SAKIT`) | SAMA |
| B-16 | Urutan cuti sakit direset hanya oleh hari presensi; cuti lain, konket, TB, libur tidak memutus | `:12798`, `:12849` | `KalkulasiTukin::hitung` | SAMA |
| B-17 | Lookback cuti sakit periode lalu (16 dua bulan lalu – 15 bulan lalu), dibawa bila hari kerja terakhir periode lalu dan hari kerja pertama periode ini sakit | `:11889-11892`, `:12687-12698` | `KalkulasiTukin::sakitSebelumPeriode` | SAMA |
| B-18 | Cuti tahunan/besar/melahirkan/alasan penting/CLTN tanpa potongan harian | `:12807-12823` | `StatusHarian::evaluasi` | SAMA |
| B-19 | Cuti alasan penting > 14 hari: jadwal 50% dihitung tetapi tidak dipakai (`$listPotonganCuti`; juga memakai `$listHK` yang tidak terdefinisi) | `:12420-12470` | tidak di-port | SAMA (kode mati di legacy) |
| B-20 | TB: potongan 25% dihitung tetapi tidak dipakai; `$isPegTB` direset tiap hari | `:12296-12330`, `:12720`, `:12761-12778` | hari TB bebas, tanpa 25% | SAMA |
| B-21 | Rentang TB = `tgl_mulai..tgl_akhir` + baris `riwayat_tb_perpan`; celah antar rentang tidak bebas | `:12270-12338` | `statusTugasBelajar()->rentangBebasPresensiBanyak()` (Fase 4 MAKE-044) | SAMA (menurut PRD 5B §7.1.3; tidak diverifikasi ulang baris demi baris di riset ini) |
| B-22 | Pemutihan TB: hari kerja terakhir yang diproses masih TB → total 0 | `:12727-12735`, `:13026-13030` | `$akhirMasihTb` | SAMA |
| B-23 | Cuti besar 5/5/5 dan cuti melahirkan 40/70/80 (anak > 3); tanggal potong = 16 bulan mulai (hari ≥ 16) atau 16 bulan sebelumnya; ke-2/ke-3 bila `lama_cuti` > 35 / > 65; berlaku bila tanggal potong = awal periode | `:12473-12577`, `:13033-13045` | `PotonganCutiPeriode` | SAMA |
| B-24 | Jumlah anak = `detail_anak.tgl_lahir` ≤ akhir periode pada `riwayat_keluarga` status 1 dengan `tgl_perkawinan` ≤ akhir periode | `:12170-12198` | `SumberDataPegawaiTukin` | SAMA |
| B-25 | SKP periodik: sangat baik/baik/butuh perbaikan 0, kurang 16, lainnya/tidak ada 32 | `:12394-12411`, `:12636` | `DataPegawaiTukin::potonganSkp` | SAMA, dengan dua CATAT: legacy memakai `strtolower(stripslashes(...))`, v2 `strtolower` saja (T-05); baris SKP ganda per NIP (T-06) |
| B-26 | Cuti sepanjang periode → SKP 0 | `:12664-12680` (rentang `perBKN_st..perBKN_en`, memangkas dengan `tmtsk_pangkat` untuk semua pegawai) | `KalkulasiTukin::cutiSepanjangPeriode` (rentang periode tukin, hanya TMT masuk pegawai baru) | BEDA-V2 (README [V2] #2) — lihat T-04 |
| B-27 | Urutan pengganti total: pemutihan TB → cuti besar → cuti melahirkan → cuti sepanjang periode → presensi + SKP | `:13026-13058` | `KalkulasiTukin::hitung` | SAMA |
| B-28 | Total tanpa batas 100% | `:13055-13057` | tanpa batas | SAMA |
| B-29 | Pegawai baru: CPNS (`gol_ruang` > 5 karakter diawali "CPNS") atau PPPK dengan TMT setelah hari kerja pertama → hari sebelum TMT dilewati | `:12701-12717`, `:12739-12743` | `SumberDataPegawaiTukin::pegawaiBaru` (+ `DataPegawaiTukin::lewatiLoopHarian`; CR-083) | SAMA, kecuali pengenal PPPK: legacy nama `jenis_pegawai == 'PPPK'`, v2 id 6 (BEDA-V2, F5-24) dan CPNS tanpa TMT (T-02) |
| B-30 | Pegawai keluar (`status=2`) di tengah periode | populasi memakai `tmt_status > awal periode`, detail memakai `tmt_status > akhir periode` → hari kerja jadi TK | hari ≥ `tmt_status` dikecualikan | BEDA-V2 (README [V2] #1) |
| B-31 | Pegawai dibuat (`created_at`) pada hari terakhir periode | populasi `DATE(created_at) <= akhir` (`L_employee.php:6398` dst.) **tetapi** detail cuti/TB/konket/presensi hanya dimuat bila `peg.created_at < 'akhir'` (`:12209`, `:12283`, `:12346`, `:12383`) — perbandingan DATETIME vs tanggal, sehingga pegawai yang dibuat pada hari terakhir masuk rekap tanpa presensi → semua hari kerja TK | detail dimuat tanpa saring `created_at` | **BEDA-BARU** (T-01) |
| B-32 | Penyesuaian Tukin (P-09) | daftar NIP hard-code per periode (≤ Juni 2024) hanya mengubah **teks keterangan** (`:11755-11805`, `:13064-13120`); angka tidak berubah | tabel `penyesuaian_tukin` (DBV-024); `PenyesuaianTukin` tidak mengubah `total_potongan` | SAMA untuk angka; BEDA-V2 untuk teks — lihat §3 |
| B-33 | Pemilihan cuti ganda pada tanggal yang sama | baris terakhir menurut `tgl_mulai` (seri tak pasti) | `id_riwayat_cuti` terbesar | BEDA-V2 (F5B-17) |
| B-34 | `id_jenis_cuti` di luar 1..6 | diam-diam tanpa potongan | `DataTukinTidakSah` (fail-closed) | BEDA-V2 (F5B-17) |
| B-35 | Presensi dimuat per bulan kalender N−1 dan N (`MONTH/YEAR(tgl)`) | `:12376-12391` | rentang tanggal periode | SAMA selama periode BKN berada di dua bulan kalender itu (lihat T-07) |

## 2. Temuan baru dan catatan risiko

- **T-01 (BEDA-BARU, B-31).** Pegawai yang `created_at`-nya jatuh pada hari terakhir periode BKN masuk rekap legacy
  tetapi tanpa data harian, sehingga semua hari kerja dihitung TK. v2 memuat datanya. Ini bug legacy. Kasusnya jarang
  (akun pegawai dibuat tepat di hari terakhir periode). Usul: catat sebagai [V2] di README `Presensi/Tukin` (perubahan
  dokumen saja) dan masukkan ke matriks sintetis UP1-12.
- **T-02 (CATAT, B-29).** CPNS dengan `tmtsk_pangkat` kosong: legacy `new DateTime('')` = waktu sekarang, sehingga
  semua hari sebelum tanggal ekspor dilewati tanpa potongan. v2 `tmtMasuk = null`, sehingga semua hari dihitung. Hasil
  legacy bergantung pada tanggal ekspor. Ini kualitas data; usul: tetap v2, catat sebagai [V2], dan jadikan butir
  pemeriksaan di gladi cutover (hitung CPNS tanpa TMT).
- **T-03 (CATAT, B-07).** Rentang puasa legacy hanya berisi tahun 2026. XLSX legacy untuk bulan lampau yang **dibuat
  ulang sekarang** tidak memakai jam puasa tahun itu, sehingga berbeda dari yang dulu dibayarkan. Akibat untuk gladi
  cutover: bandingkan hanya bulan yang rentang puasanya sama di kedua sisi (bulan 2026, atau bulan di luar Ramadan),
  dan isi `rentang_puasa` v2 untuk 2026 = 19-02 s.d. 21-03 sebelum membandingkan Februari–April 2026.
- **T-04 (CATAT, B-26).** Bila baris BKN tidak ada untuk bulan itu, legacy menghitung "cuti sepanjang periode" atas
  rentang hari ini saja (`new DateTime('')`), sehingga hasilnya bergantung pada hari ekspor (mis. ekspor di akhir pekan
  → `0 == 0` → SKP 0 untuk semua pegawai). Sudah [V2] #2. Akibat untuk gladi: bandingkan hanya bulan yang punya baris
  `bkn_periode_ekinper`.
- **T-05 (CATAT, B-25).** `stripslashes` pada `hasil_akhir`: v2 tidak memakainya. Setara selama nilai tidak memuat
  backslash. Alat impor MAKE-056 (PR #32) menerapkan aturan stripslashes per kolom; bila `riwayat_skp_periodik.hasil_akhir`
  termasuk kolom yang dibersihkan, hasilnya setara. Butir gladi: cek nilai berbeda (`DISTINCT hasil_akhir`) setelah impor.
- **T-06 (CATAT, B-25).** Lebih dari satu baris SKP periodik aktif per NIP per periode: legacy mengambil baris
  terakhir dari urutan `nip ASC` (tak pasti), v2 baris pertama. Hasil tak pasti di kedua sisi. Butir gladi: hitung NIP
  dengan baris ganda; bila ada, putuskan aturannya (DBV/Horii), bukan diselesaikan di kode diam-diam.
- **T-07 (CATAT, B-35).** Bila periode BKN dimulai sebelum bulan N−1, presensi hari-hari itu tidak dimuat legacy (TK),
  sedangkan v2 memuatnya. Butir gladi: cek `periode_awal` semua baris BKN yang dibandingkan.
- **T-08 (CATAT).** Berkas legacy memuat daftar NIP nyata hard-code (penyesuaian tukin dan satu NIP debug). Riset ini
  tidak menyalinnya. Daftar itu bukan aturan dan tidak di-port (README [V2] #5).

## 3. P-09 (penyesuaian hard-code) — bahan keputusan Horii

- Di legacy, penyesuaian hanya menambah **teks** "Tukin disesuaikan −20%/−10% (sebelum potongan lain)" di kolom
  keterangan untuk NIP dan periode tertentu. Periode terakhir yang tercantum adalah Juni 2024. Angka TL/PSW/TK/TA/
  CUTI/TB/total tidak diubah (`:13064-13120`).
- Di v2, penyesuaian berasal dari tabel `penyesuaian_tukin`; `PenyesuaianTukin` juga tidak mengubah `total_potongan`.
- `ParitasRekapTukin` tidak membandingkan kolom keterangan.
- **Kesimpulan:** untuk paritas angka, P-09 tidak berpengaruh dan cukup diputus dari riset ini. Yang tersisa adalah
  migrasi data: apakah penyesuaian historis (≤ Juni 2024) perlu dimasukkan ke `penyesuaian_tukin` saat cutover. Itu
  keputusan isi data (Horii/Biro SDM), bukan butir paritas.

## 4. Akibat untuk UP1-12 (paritas sintetis) dan gladi cutover

- Matriks UP1-12 sebaiknya menambah skenario T-01, T-02, T-07 di samping D-13 dan X-1..X-14, karena di situlah kedua
  kode diharapkan berbeda (hasilnya harus cocok dengan label BEDA-V2/BEDA-BARU, bukan SAMA).
- Kriteria lulus gladi cutover (C2): selisih per NIP hanya boleh berasal dari butir BEDA-V2/BEDA-BARU di tabel ini;
  bulan yang dibandingkan harus punya baris BKN (T-04) dan rentang puasa yang sama di kedua sisi (T-03).

## 5. Batas riset

- Riset ini membaca kode, tidak mengeksekusinya. Interaksi antarbutir dibuktikan oleh UP1-12.
- Rujukan baris memakai salinan legacy lokal; belum dicocokkan dengan kode produksi yang sedang berjalan (asumsi AS-09).
- Uang makan, varian `laporan_tukin_us_skp_il` (Instansi Lain), dan format XLSX/PDF di luar lingkup.
