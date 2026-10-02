# Modul D — Presensi & Remunerasi (Fase 5)

Service/business logic modul ini (`App\Libraries\Presensi\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

CR-037 menambahkan kalkulasi Tukin murni di `Presensi/Tukin`; belum terhubung ke DB atau endpoint.

## Slip Gaji — logika murni (`SlipGaji/`, CR-027, bagian D-10/D-11/D-13)

Jalur "Slip Gaji (a)" yang disetujui user pada 01-10-2026: perilaku Slip Gaji legacy didokumentasikan dan logikanya ditulis sebagai kelas **murni** sekarang, mendahului urutan fase. Port penuh D-10/D-11 (tabel, impor berkas, endpoint, PDF, QR, FE) tetap di Fase 5. Semua kelas `final` dengan method statis (kecuali value object `readonly`), **tanpa DB, HTTP, session, jam sistem, angka acak, atau I/O berkas** (K-CR027-1; dijaga `SlipGajiMurniTest`). Tanpa migration, controller, FE, dan dependensi composer baru.

Namespace `App\Libraries\Presensi\SlipGaji` dipilih karena Slip Gaji adalah bagian Modul D (05-Presensi D-10/D-11, controller rencana `Controllers/Api/Presensi/SlipGaji*`); subfolder memisahkannya dari kalkulasi Tukin D-04..D-07 di `Presensi/Tukin` (CR-037).

Label sumber: **[K]** = kode legacy commit `39b6b15`, path relatif ke `application/` legacy; **[V2]** = dokumen v2 (05-Presensi, FSD, SRS, Matriks_Role_x_Endpoint, MTC). Keputusan: **IKUT** legacy, **PERBAIKI** (bug legacy diperbaiki), **TUNGGU-BK** (Biro Keuangan; kode memakai nilai interim), **TUNGGU-USER** (user/reviewer CR), **FASE-5** (butuh DB/I/O). Contoh dan test memakai data sintetis saja.

| Kelas | Isi |
|---|---|
| `HasilSlip` | `valid`, `nilai`, `kode`, `pesan`, `detail`; `lemparBilaGagal($field)` → `ValidationException` 422 `errors[$field]` (pola `HasilKalkulasi` CR-025, tanpa semantik tanggal) |
| `PeriodeSlip` | value object bulan + tahun: `buat` (data sistem), `dariTeks('YYYY-MM')` (input), `kunci`, `label` ("Oktober 2026"), `sebelumnya`/`berikutnya`, `indeks`, `sama` |
| `GerbangPeriode` | `HARI_RILIS = 4`, `JAM_RILIS = '10:00:00'`, `TAHUN_AWAL = 2024`; `waktuRilis`, `sudahRilis`, `periksa`, `terbaru`, `daftarTersedia` |
| `NominalGaji` | int sen: `dariSel` (nilai mentah sel Excel), `dariInputApi` (koreksi admin), `periksaNonNegatif`, `keDesimal`/`dariDesimal` (`DECIMAL(14,2)`), `formatAngka` ("4.500.001") |
| `KolomGaji` | 28 field (`GPP`, `TK`), peta kolom Excel (`SUMBER_GPP`, `SUMBER_TK`), `PENGHASILAN_GPP`/`POTONGAN_GPP`, wajib terisi, `normalisasiHeader`, `petakanHeader`, `cariSheet`, `cariSheetWajib` |
| `NilaiSel` | perapian tepi (spasi + NBSP), deteksi sel kosong, uraian nilai mentah untuk pesan |
| `BarisImpor`, `BarisSheet` | validasi sel `nip`/`bulan`/`tahun` dan nominal satu baris (`urai`) |
| `PratinjauImpor` | `uraiSheet` (header + baris mentah), `filterDariMasukan`, `kunci`, `susun` (pasangan GPP–TK, duplikat, NIP, nama, cakupan, status), `rencanaCommit` |
| `PencocokanNama` | `normalisasi`, `cocok` (setia legacy), `denganGelar` |
| `RingkasanSlip` | `hitung` (rumus legacy), `periksaKonsistensi` (usulan, belum aktif) |
| `StatusSlip` | `periksaBuka`, `periksaReset`, `bolehCetakPegawai` |
| `TokenQr` | `valid` (64 heksadesimal huruf kecil), `dariByteAcak` (32 byte dari service) |
| `AksesSlip` | `bolehMelihatMilikSendiri`, `bolehMengelola`, `dalamCakupan` (konstanta `App\Constants\Role`) |
| `FormatSlip` | `tanggalCetak`, `waktuWib`, `labelTotal`, `namaBerkasPdf` |

### Aturan dan sumbernya

| # | Aturan | Legacy [K] | v2 [V2] | Keputusan / implementasi |
|---|---|---|---|---|
| G-1 | Slip periode (B, T) terbuka mulai **T-B-04 10:00:00 WIB** (bulan yang sama), inklusif | `libraries/hr/Lsl_gaji.php:9-12`, `:108-116` (`>=`) | FSD-FN-D-11, SRS-FR-032, 05-Presensi D-11 (unit test tepat sebelum/sesudah ambang), MTC-036 | IKUT; `GerbangPeriode::sudahRilis`, dibandingkan per detik (`09:59:59.999999` belum, `10:00:00` sudah) |
| G-2 | Periode tertua Januari 2024 | `Lsl_gaji.php:9`, `:120` | Volume histori D-01 masih TBD (SRS-NFR-050) | IKUT; kode `periode_sebelum_awal` dibedakan dari `periode_belum_rilis`; batas bisa berubah oleh keputusan D-01 (TUNGGU-USER/DBV) |
| G-3/G-4 | Daftar naik Januari 2024 → terbaru; terbaru = bulan berjalan bila sudah rilis, selain itu bulan lalu; tidak bergantung ada-tidaknya data | `Lsl_gaji.php:118-148`, `controllers/hr/Sl_gaji.php:38-50` | — | IKUT; `daftarTersedia`, `terbaru` (34 periode pada 4 Oktober 2026 10:00 WIB; 1–4 Januari sebelum 10:00 → Desember tahun lalu) |
| G-5 | Pembanding jam dinding Asia/Jakarta, apa pun zona objek waktu | `Lsl_gaji.php:76-86`, `:110-122`, `config/config.php:4` | `appTimezone = 'UTC'`; "hari ini" bisnis WIB (CR-025) | IKUT WIB tetap (bukan per satker); PERBAIKI: objek pemanggil tidak dimutasi; `$sekarang` wajib dioper service (`Time::now()`) |
| G-6 | Gerbang di semua jalur pegawai (daftar, status, buka, cetak), ditegakkan di server; periode tidak valid = galat berkode, bukan fallback diam-diam | `Sl_gaji.php:39-50`, `:106`, `:169-171` | FSD-FN-D-11 | IKUT + PERBAIKI. Jalur admin wajib menyebut periode yang valid; admin bebas memilih periode di luar gerbang = TUNGGU-USER |
| G-7 | Kunci periode API `YYYY-MM` (`^\d{4}-(0[1-9]\|1[0-2])\z`), tahun 1900–2100, tanpa trim | `Lsl_gaji.php:88-106` (`B-TTTT`) | — | PERBAIKI; `PeriodeSlip::dariTeks` → `periode_wajib`/`periode_tidak_valid` |
| N-1..N-4 | Nominal dibaca dari **nilai mentah sel** (`formatData = false`); teks hanya format Indonesia ketat (`1.234.567`, `1.234,50`; titik selalu ribuan); float tanpa pecahan di bawah sen; batas `DECIMAL(14,2)`; input API hanya kanonik titik-desimal | `Lsl_gaji.php:741`, `:794-812` (teks tampilan, titik dihapus, koma → titik), `:594-600` | SRS-NFR-011, PRD-FR-027 (tanpa silent-fail), D-10 | PERBAIKI; `NominalGaji::dariSel`/`dariInputApi`. Regresi: float `4500000.5` → 450000050 sen (legacy 45.000.005), koreksi `"4500000.00"` → 450000000 sen (legacy ×100) |
| N-5 | Nominal negatif | Impor menerima (`Lsl_gaji.php:807-811`), koreksi menolak (`:597`) | — | TUNGGU-BK; interim `nominal_negatif` di impor dan koreksi |
| N-6 | Teks `-` sebagai nol | Gagal eksplisit | — | TUNGGU-BK; interim galat. Sel numerik 0 berformat akuntansi tetap 0 karena nilai mentah |
| P-1/P-2 | Nama bulan Indonesia; label "Oktober 2026"; "Total Penghasilan Bulan Oktober 2026" | `config/constants.php:267`, `Lsl_gaji.php:133`, `views/hr/employee/sl_gaji/_slip_content.php:97` | — | IKUT; memakai ulang `TanggalBisnis::NAMA_BULAN` |
| P-3 | Bulan berkas: bulat 1–12 (int, float tanpa pecahan, teks `^\d{1,2}$`) atau nama lengkap (trim, tidak peka huruf) | `Lsl_gaji.php:685-704` (`"8.7"` → 8) | — | PERBAIKI; `bulan_tidak_valid`. Singkatan TUNGGU-BK |
| P-4 | Tahun berkas: bulat 1900–2100 (int, float tanpa pecahan, teks `^\d{4}$`) | `Lsl_gaji.php:773`, `:787-789` (`"2026abc"` → 2026) | — | PERBAIKI; `tahun_tidak_valid` |
| P-5 | Tanggal cetak "4 Oktober 2026" (WIB, tanpa jam) | `Lsl_gaji.php:366-369` | Feature Slip Gaji meminta tanggal dan waktu | IKUT; `FormatSlip::tanggalCetak($sekarang)`. Perlu jam: TUNGGU-USER |
| P-6 | Waktu dibuka disimpan stempel UTC, ditampilkan WIB + nama bulan Indonesia | Disimpan jam dinding WIB (`Lsl_gaji.php:84-86`, `:166`); tiga format tampil berbeda | Konvensi stempel UTC v2 | PERBAIKI; `FormatSlip::waktuWib` ("4 Oktober 2026 10:00 WIB", format final TUNGGU-USER); migrasi data WIB → UTC FASE-5 |
| P-7 | Nama berkas `Slip_Gaji_{NAMA}_{Bulan}_{Tahun}.pdf`, nama disanitasi `[A-Z0-9_]`, fallback NIP | `Lsl_gaji.php:411` | — | PERBAIKI; `FormatSlip::namaBerkasPdf` |
| P-8 | Label uang makan bulan−1 | Tidak ada di `39b6b15` | Feature/Brainstorm | `PeriodeSlip::sebelumnya()` disiapkan; pemakaian TUNGGU-BK |
| P-9 | Nama bergelar `"{gelar awal} {nama}, {gelar akhir}"`, bagian kosong dilewati | `helpers/function_helper.php:102-108`; PDF tanpa koma | — | `PencocokanNama::denganGelar` (usul satu format di semua keluaran, TUNGGU-USER) |
| P-10 | Rupiah 0 desimal, setengah menjauhi nol, tanpa `-0` | `number_format(x, 0, ',', '.')`, awalan berbeda per keluaran | — | IKUT; `NominalGaji::formatAngka`; awalan "Rp" diputuskan UI; sen ditampilkan atau tidak TUNGGU-BK |
| M-1..M-5 | Nama dicek bila sheet punya kolom `nama`; normalisasi `[a-z0-9]`; cocok = sama atau substring; kosong → `nama_kosong`; tidak cocok → ditolak **dan** dicatat untuk log | `Lsl_gaji.php:715-737`, `:891-952` | D-10 / FSD-FN-D-10 / MTC-035 (mismatch di-log, bukan silent-fail) | IKUT (lebih ketat dari spek: tolak + log); `susun` mengembalikan `nama_tidak_cocok`, service menulis log sekali per pratinjau. Positif palsu substring ("Ani" ~ "Daniel") TUNGGU-USER/BK |
| M-6 | Setiap sheet yang punya kolom `nama` dicek sendiri | `Lsl_gaji.php:932` (nama TK tertutup nama GPP) | — | PERBAIKI |
| I-1 | Sheet "GPP" dan "TK" (trim, tidak peka huruf) wajib ada keduanya | `Lsl_gaji.php:671-678`, `:858-860` | — | PERBAIKI; `KolomGaji::cariSheetWajib` → `sheet_tidak_ada` |
| I-2 | Header baris 1; normalisasi memadatkan whitespace/NBSP/`-`/`.`/`_` menjadi satu `_`; kolom kunci `nip`, `bulan`, `tahun` | `Lsl_gaji.php:742-760` | — | PERBAIKI; `sheet_kosong`, `kolom_ganda` (kolom dikenali), `kolom_kunci_tidak_ada`; kolom tak dikenal → info `kolom_diabaikan` |
| I-3 | 28 field: GPP 13 penghasilan + 8 potongan + `bersih` → `bersih_gpp`; TK 6 dengan `bersih` → `bersih_tk`; wajib terisi GPP `gjpokok`, `bersih`; TK `kotor`, `bersih` | `Lsl_gaji.php:15-70`, `:712-713` | — | IKUT peta; PERBAIKI: kolom peta yang hilang dari header → `kolom_tidak_ada` (legacy 0 diam-diam). Template resmi memuat 28 kolom: TUNGGU-BK |
| I-4 | Baris tanpa NIP dilewati; bila berisi nilai lain dilaporkan `baris_tanpa_nip` | `Lsl_gaji.php:766-769` (diam-diam) | — | IKUT + PERBAIKI (info, tidak memblokir) |
| I-5 | NIP wajib sel **teks**; digit + spasi (spasi dibuang); 1–18 digit; wajib terdaftar | `Lsl_gaji.php:771`, `:781-783`, `:925-926` | `PenggunaModel::NIP_MAX_DIGITS = 18` | PERBAIKI; `nip_bukan_teks` (sel numerik kehilangan presisi), `nip_tidak_valid`, `nip_tidak_terdaftar` |
| I-8 | Kunci NIP + periode ganda dalam satu sheet → baris pertama dipertahankan + galat yang menyebut kedua nomor baris | `Lsl_gaji.php:815-823` | D-10 / FSD-FN-D-10 / MTC-035 | IKUT; `baris_ganda` (`8` dan `Agustus` = kunci sama) |
| I-9 | Setiap kunci wajib ada di kedua sheet | `Lsl_gaji.php:957-962` | — | IKUT; `tidak_ada_di_gpp`/`tidak_ada_di_tk` |
| I-10 | Filter periode opsional: bulan dan tahun diisi berdua atau tidak sama sekali | `controllers/hr/Sl_gaji.php:428-431`, `Lsl_gaji.php:878-889` | — | IKUT + PERBAIKI; `filter_periode_tidak_lengkap`, `periode_tidak_valid`, `periode_tidak_ada_di_berkas`; baris yang periodenya tidak terbaca tetap dilaporkan sebagai galat (legacy membuangnya diam-diam) |
| I-11 | Status galat > duplikat (kunci sudah ada di DB) > valid; urut tahun → bulan → NIP numerik; nominal 28 field default 0 ditimpa GPP lalu TK | `Lsl_gaji.php:964-1015` | — | IKUT |
| I-12 | Commit parsial per kunci: valid → sisip; duplikat → perbarui (timpa) atau lewati; galat → gagal | `Lsl_gaji.php:1018-1131` | D-10 (3 langkah; UNIQUE tertangkap) | IKUT; `rencanaCommit`. Nilai bawaan opsi timpa TUNGGU-USER (usul: tidak tercentang) |
| I-13 | Impor oleh role 3 dibatasi satkernya | — | D-10 / Matriks: impor role 1 saja | TUNGGU-USER; `susun(..., $cakupanSatker)` → `nip_di_luar_cakupan` disiapkan |
| R-1..R-4 | `jumlah_penghasilan_gpp`, `jumlah_potongan_gpp`; **`total_penghasilan = bersih_gpp + bersih_tk`**; `bersih_2`, tukin kelas jabatan, dan uang makan tidak masuk total | `Lsl_gaji.php:217-261` | Feature Slip Gaji §6.4: gaji bersih + tunjangan kinerja + uang makan bulan−1 | Interim IKUT legacy, int sen; **[menunggu konfirmasi Biro Keuangan]** (lihat di bawah) |
| H-1..H-5 | Hak akses: lihat bagian berikut | `config/constants.php:298-301`, `Sl_gaji.php:19`, `:74`, `:137`, `:190` dst. | Matriks Modul D, FSD-FN-D-10/11 | Keputusan user 01-10-2026 |
| S-1..S-5 | `viewed_at` kosong = belum dibuka; buka hanya bila belum; buka ulang (reset) hanya bila sudah, riwayat token dipertahankan; cetak pegawai selama dibuka | `Sl_gaji.php:92-133`, `:169-171`, `Lsl_gaji.php:633-662` | Feature Slip Gaji (akses 1x) | IKUT urutan dan pesan; `StatusSlip`. Buka hanya oleh pegawai pemilik (PERBAIKI). Aturan cetak dan PDF admin TUNGGU-USER |
| S-6 | Koreksi/timpa setelah slip dibuka | Tidak mengubah status dan token | — | TUNGGU-BK/USER: (a) larang, (b) izinkan lalu reset, atau (c) sidik isi slip saat token terbit |
| Q-1..Q-3 | Token QR 64 heksadesimal huruf kecil, baru setiap kali dibuka; verifikasi publik mencari di riwayat token | `Sl_gaji.php:127`, `Lsl_gaji.php:176-186`, `:416-465`, `controllers/Verify.php:3-22` | Feature Slip Gaji (verifikasi tanpa login) | IKUT; PERBAIKI: format token divalidasi (`TokenQr::valid`) sebelum query, format salah dijawab sama dengan "tidak ditemukan". Data yang tampil TUNGGU-USER |

### Hak akses (keputusan user 01-10-2026: ikut legacy)

| Role | Akses Slip Gaji | Implementasi |
|---|---|---|
| 2 Pegawai, 6 PTT, 7 PPPK | Lihat, buka, dan cetak slip milik NIP **dari token login**; parameter NIP dari klien diabaikan | `AksesSlip::bolehMelihatMilikSendiri` (`Role::UL_PEGAWAI`) |
| 1 Super Admin | Semua pegawai, semua aksi admin (kelola, lihat, koreksi, buka ulang, impor) | `bolehMengelola`, `dalamCakupan` → selalu `true` |
| 3 Admin Satker | Hanya pegawai yang `id_satker` jabatan **saat ini** sama (string) dengan `id_satker` akun admin (klaim JWT). Admin tanpa `id_satker` ditolak untuk seluruh jalur (fail-closed); target di luar cakupan → 403, bukan 404 (pola `UserService::assertAdmin`/`findInScope`). Fallback unit dan kekhususan unit tidak dipakai | `dalamCakupan(3, $satkerAdmin, $satkerPegawai)` |
| 4, 5, 8 | Tidak ada akses (403) | `bolehMelihatMilikSendiri`/`bolehMengelola` → `false` |
| Publik | Verifikasi QR `/verify/slip/{token}` tanpa login | `TokenQr::valid` (endpoint Fase 5) |

Admin 1/3 yang juga punya NIP melihat slipnya lewat jalur admin, yang tidak mengubah status "dibuka" [I]. **Deviasi dokumen:** Matriks_Role_x_Endpoint, FSD-FN-D-10/D-11, dan 05-Presensi D-10/D-11 menyebut impor/kelola role 1 saja serta daftar/cetak role 1 dan 2; keputusan 01-10-2026 menggantinya dengan tabel di atas. Revisi matriks dan apakah role 3 boleh mengimpor (I-13) masih TUNGGU-USER.

### Aturan v2 yang wajib ditegakkan service Fase 5

Kebenaran data:
1. Satu sumber, satu aturan angka: nilai mentah sel; teks hanya format Indonesia ketat; input API hanya kanonik titik-desimal. Nilai yang tidak diubah di form koreksi tidak berubah setelah disimpan.
2. NIP dibaca sebagai teks. Kolom peta yang hilang dan header ganda adalah galat. Bulan, tahun, dan nominal tidak pernah dipotong atau ditafsirkan diam-diam.
3. Semua tanggal tampil memakai WIB dan nama bulan Indonesia; stempel disimpan UTC. Satu format nama + gelar dan satu format rupiah di semua keluaran.

Keamanan dan kerahasiaan (ditegakkan di server pada setiap endpoint terkait; FE hanya mencerminkan):
1. Identitas pemilik slip untuk role pegawai selalu dari token login; NIP dari klien hanya dipakai di jalur admin setelah cek cakupan.
2. Jalur admin wajib menyebut pegawai dan periode secara eksplisit; tidak ada slip atau periode "bawaan".
3. Status "dibuka" hanya berubah lewat aksi buka oleh pegawai pemilik slip; melihat, mencetak, mengoreksi, atau mengimpor oleh admin tidak mengubahnya. Transisi bersifat atomik (UPDATE bersyarat `viewed_at IS NULL` + cek jumlah baris).
4. Nominal hanya dikirim dalam respons aksi buka, atau di endpoint lihat/cetak setelah status dibuka; sebelum itu hanya metadata (periode, tersedia, status).
5. Gerbang waktu, status dibuka, dan cakupan satker ditegakkan di server.
6. Commit impor hanya memakai hasil validasi buatan server (pratinjau disimpan di server, atau divalidasi ulang penuh saat commit).
7. Akses DB lewat Model/Query Builder dengan binding parameter. Berkas unggahan dibatasi ekstensi, MIME, dan ukuran; diproses di `writable/` dan dihapus setelah diproses, termasuk bila gagal.
8. Pesan galat ke pengguna tanpa pesan exception mentah. PDF tidak pernah memuat QR tanpa token terdaftar.
9. Endpoint verifikasi publik: format token divalidasi lebih dulu, respons "tidak ditemukan" seragam, dibatasi laju, tanpa IP, dan hanya data yang diputuskan.
10. Audit untuk koreksi (nilai lama → baru), buka ulang, impor (ringkasan per commit), dan ketidakcocokan nama.

### Dua jenis kesalahan (K-CR027-5)

- **Input pengguna salah** → `HasilSlip::gagal(kode, pesan)`; service memanggil `->lemparBilaGagal('periode')` → 422. Kode stabil:
  - periode: `periode_wajib`, `periode_tidak_valid`, `periode_sebelum_awal`, `periode_belum_rilis`;
  - nominal: `nominal_wajib`, `nominal_tidak_valid`, `nominal_desimal_berlebih`, `nominal_terlalu_besar`, `nominal_negatif`;
  - sheet/header: `sheet_tidak_ada`, `sheet_kosong`, `kolom_kunci_tidak_ada`, `kolom_tidak_ada`, `kolom_ganda`;
  - baris: `nip_bukan_teks`, `nip_tidak_valid`, `nip_tidak_terdaftar`, `nip_di_luar_cakupan`, `bulan_tidak_valid`, `tahun_tidak_valid`, `baris_ganda`, `tidak_ada_di_gpp`, `tidak_ada_di_tk`, `nama_kosong`, `nama_tidak_cocok`;
  - info (hasil valid): `baris_tanpa_nip`, `kolom_diabaikan`;
  - filter: `filter_periode_tidak_lengkap`, `periode_tidak_ada_di_berkas`;
  - status: `slip_sudah_dibuka`, `slip_belum_dibuka`.

  `slip_tidak_ditemukan` dan `token_tidak_valid` dipetakan service Fase 5.
- **Data sistem salah** (periode/sen dari DB, nama sheet, panjang byte token, status pratinjau tak dikenal) → `InvalidArgumentException`.

### Keputusan rancangan CR-027

| # | Keputusan |
|---|---|
| K-CR027-1 | Murni tanpa DB/HTTP/session/**jam**/**acak**/I/O berkas. `DateTimeInterface $sekarang` wajib dioper; lebih ketat dari CR-025 (tanpa pengecualian `Time::now()`; `TanggalBisnis::hariIni()` hanya dengan argumen) |
| K-CR027-2 | Nominal = int sen, tanpa float di aritmetika; `keDesimal`/`dariDesimal` di batas DB `DECIMAL(14,2)` |
| K-CR027-3 | Periode = value object `PeriodeSlip`; kunci API `YYYY-MM`; label memakai `TanggalBisnis::NAMA_BULAN` |
| K-CR027-4 | Gerbang: tersedia ⇔ periode ≥ Januari 2024 ∧ sekarang_WIB ≥ tanggal 4 pukul 10:00:00 WIB bulan periode (inklusif) |
| K-CR027-5 | Input pengguna → `HasilSlip` 422; data sistem → `InvalidArgumentException` (pola K-CR025-4) |
| K-CR027-6 | Parser Excel menerima nilai mentah sel; teks hanya format Indonesia ketat; input API kanonik terpisah |
| K-CR027-7 | Pencocokan nama setia legacy; setiap sheet bernama diperiksa |
| K-CR027-8 | Ringkasan setia legacy (`bersih_gpp + bersih_tk`) sampai Biro Keuangan memutuskan |
| K-CR027-9 | Hak akses = keputusan user 01-10-2026 (tabel di atas) |
| K-CR027-10 | Fungsi murni menerima data DB sebagai argumen (baris mentah sheet, peta pegawai, kunci yang sudah ada); service Fase 5 yang mengambilnya |

### Menunggu konfirmasi Biro Keuangan (TUNGGU-BK)

Kode memakai nilai interim yang disebut sampai ada keputusan.
1. **Rumus total** — interim `total_penghasilan = bersih_gpp + bersih_tk`. Perlu diputuskan: komponen TK yang masuk total (`bersih_tk` atau `bersih_2`); baris tukin kelas jabatan (legacy menampilkan nilai saat dokumen dibuat, bukan nilai periode slip, tanpa dijumlahkan); uang makan bulan−1 dari D-09 masuk total atau tidak.
2. Invarian `bersih_gpp = Σpenghasilan − Σpotongan`, `bersih_tk = kotor − potongan`, `bersih_2 = bersih_tk − pajak + tunj_pajak` sebagai peringatan pratinjau (`RingkasanSlip::periksaKonsistensi`, belum dipanggil).
3. Nominal negatif per komponen (interim: semua ditolak) dan teks `-` sebagai nol (interim: galat).
4. Apakah ada sen dan perlu ditampilkan (interim: tampil 0 desimal).
5. Template resmi memuat ke-28 kolom; singkatan bulan di berkas.
6. Slip yang sudah dibuka boleh dikoreksi atau ditimpa atau tidak (S-6).

### Menunggu keputusan user / reviewer CR (TUNGGU-USER)

1. Role 3: satker jabatan saat ini (bukan satker pada periode slip), tanpa fallback unit; impor role 3 dengan cakupan atau impor role 1 saja (I-13); revisi Matriks/FSD/05-Presensi.
2. Admin bebas memilih periode di luar gerbang (usul: ya, untuk jalur admin).
3. Aturan cetak pegawai (usul: boleh selama dibuka) dan PDF admin tanpa token (tanpa QR dengan keterangan "salinan admin", atau admin tidak boleh mencetak sebelum dibuka).
4. Data di halaman verifikasi publik (usul: status keaslian, nama, NIP, periode, waktu terbit; nominal hanya bila diputuskan).
5. Format waktu dibuka, format nama + gelar, dan jam pada tanggal cetak.
6. Nilai bawaan opsi timpa impor (usul: tidak tercentang).
7. Positif palsu substring pada pencocokan nama (usul: tetap, evaluasi lewat log).
8. Ekstraksi pemindai kemurnian `KalkulasiMurniTest`/`SlipGajiMurniTest` ke `tests/_support/Libraries/` (saat ini disalin).
9. Periode tertua Januari 2024 terhadap keputusan histori D-01.

### Menunggu Fase 5 (bukan bagian CR-027)

- **D-01 (DB Validator):** skema `gaji_pegawai` — 28 kolom `DECIMAL(14,2) NOT NULL DEFAULT 0.00` di atas struktur produksi (`viewed_at`, `viewed_ip`, `qr_token`, `UNIQUE (nip, bulan, tahun)` sudah ada); nasib kolom generik lama; tabel riwayat token (FK, usul `UNIQUE (qr_token)`); volume histori; konversi stempel WIB → UTC.
- **D-10:** dependensi PhpSpreadsheet; pembaca nilai mentah (`formatData = false`, rumus dihitung) yang menghasilkan masukan `PratinjauImpor::uraiSheet`; unggah aman; pratinjau disimpan di server; commit per baris dengan tangkapan UNIQUE; log ketidakcocokan nama; template unduhan berdata sintetis.
- **D-11:** endpoint daftar, status, buka (atomik), cetak PDF, koreksi, buka ulang, dan verifikasi publik `/verify/slip/{token}`; generator QR; tukin kelas jabatan dan uang makan (D-08/D-09).
- **D-12:** FE pegawai dan admin — aksi baris lewat menu ⋮ (AGENTS.md §1; "Buka ulang" sebagai aksi lain setelah "Edit", `hidden` bila belum dibuka, lewat `ConfirmDialog`), modal konfirmasi buka.
- **D-13:** test integrasi impor (mismatch, duplikat, pelanggaran constraint) dan gerbang waktu di `tests/Presensi/`.

### Test

`tests/unit/Presensi/SlipGaji/` (tanpa DB; konvensi `tests/unit` untuk kelas murni, sama dengan CR-025):

```bash
cd backend && vendor/bin/phpunit --no-coverage tests/unit/Presensi
```
