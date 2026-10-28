# Modul D — Presensi & Remunerasi (Fase 5)

Service/business logic modul ini (`App\Libraries\Presensi\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

CR-037 menambahkan kalkulasi Tukin murni di `Presensi/Tukin`; CR-039 menyelaraskannya ke rekap legacy jalur aktif `laporan_tukin_us_skp` (lihat `Tukin/README.md`). Belum terhubung ke DB atau endpoint.

## Slip Gaji — logika murni (`SlipGaji/`, CR-027, bagian D-10/D-11/D-13)

Jalur "Slip Gaji (a)" yang disetujui user pada 01-10-2026: perilaku Slip Gaji legacy didokumentasikan dan logikanya ditulis sebagai kelas **murni** sekarang, mendahului urutan fase. Port penuh D-10/D-11 (tabel, impor berkas, endpoint, PDF, QR, FE) tetap di Fase 5. Semua kelas `final` dengan method statis (kecuali value object `readonly`), **tanpa DB, HTTP, session, jam sistem, angka acak, atau I/O berkas** (K-CR027-1; dijaga `SlipGajiMurniTest`). Tanpa migration, controller, FE, dan dependensi composer baru.

Namespace `App\Libraries\Presensi\SlipGaji` dipilih karena Slip Gaji adalah bagian Modul D (05-Presensi D-10/D-11, controller rencana `Controllers/Api/Presensi/SlipGaji*`); subfolder memisahkannya dari kalkulasi Tukin D-04..D-07 di `Presensi/Tukin` (CR-037).

Label sumber: **[K]** = kode legacy; acuan perilaku Slip Gaji **`771f8b4`** (08-10-2026, F5-1, PRD Fase 5A
`docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` §7.3.8) menggantikan baseline CR-027 `39b6b15`. Seluruh rujukan baris
di README ini sudah dicocokkan ulang ke `771f8b4` (M4 MAKE-031; penanda `@771f8b4` pada baris yang isinya berubah oleh
F5-1). **Acuan dinaikkan ke `0cf7e67`** (26-10-2026, CR-067, keputusan Horii): selisih `771f8b4..0cf7e67` hanya
`1c3142f` (snapshot uang makan tidak ditimpa 0, logo PDF disematkan data URI — v2 sudah setara: `SlipGajiService::snapshotUangMakan`,
`PdfSlipGaji::logo`) dan `0cf7e67` (baris Pajak/Tunjangan Pajak dihapus dari bagian TK, P-10 / PDF-7); rujukan `@771f8b4`
yang lain tetap berlaku untuk isinya, tetapi nomor baris `Lsl_gaji.php` sesudah `:157` bergeser di `0cf7e67` (mis.
`hitungRingkasan` `:416-446`, rumus total `:430-434`). Rujukan baris di docblock kelas `SlipGaji/*.php` tetap baseline `39b6b15` (folder murni hanya diubah sebatas
§7.3.8). Path relatif ke `application/` legacy; **[V2]** = dokumen v2 (05-Presensi, FSD, SRS, Matriks_Role_x_Endpoint, MTC). Keputusan: **IKUT** legacy, **PERBAIKI** (bug legacy diperbaiki), **FASE-5** (butuh DB/I/O). Butir yang dulu berlabel TUNGGU-BK/TUNGGU-USER sudah terjawab dari kode legacy (CR-039, 02-10-2026; lihat "Terjawab dari legacy"). Contoh dan test memakai data sintetis saja.

| Kelas | Isi |
|---|---|
| `HasilSlip` | `valid`, `nilai`, `kode`, `pesan`, `detail`; `lemparBilaGagal($field)` → `ValidationException` 422 `errors[$field]` (pola `HasilKalkulasi` CR-025, tanpa semantik tanggal) |
| `PeriodeSlip` | value object bulan + tahun: `buat` (data sistem), `dariTeks('YYYY-MM')` (input), `kunci`, `label` ("Oktober 2026"), `sebelumnya`/`berikutnya`, `indeks`, `sama` |
| `GerbangPeriode` | `HARI_RILIS = 4`, `JAM_RILIS = '10:00:00'`, `TAHUN_AWAL = 2024`; `waktuRilis`, `sudahRilis`, `periksa`, `terbaru`, `daftarTersedia` |
| `NominalGaji` | int sen: `dariSel` (nilai mentah sel Excel), `dariInputApi` (koreksi admin), `periksaNonNegatif`, `keDesimal`/`dariDesimal` (`DECIMAL(14,2)`), `formatAngka` ("4.500.001") |
| `KolomGaji` | 28 field (`GPP`, `TK`), peta kolom Excel (`SUMBER_GPP`, `SUMBER_TK`), `PENGHASILAN_GPP`/`POTONGAN_GPP`, wajib terisi, `normalisasiHeader`, `petakanHeader`, `cariSheet`, `cariSheetWajib` |
| `NilaiSel` | perapian tepi (spasi + NBSP), deteksi sel kosong, uraian nilai mentah untuk pesan |
| `BarisImpor`, `BarisSheet` | validasi sel `nip`/`bulan`/`tahun` dan nominal satu baris (`urai`) |
| `PratinjauImpor` | `uraiSheet` (header + baris mentah), `filterDariMasukan`, `kunci`, `susun` (pasangan GPP–TK, duplikat, NIP, nama, cakupan, status), `rencanaCommit`, `TIMPA_BAWAAN = true` |
| `PencocokanNama` | `normalisasi`, `cocok` (setia legacy), `denganGelar` |
| `RingkasanSlip` | `hitung` (rumus legacy; legacy tidak punya cek invarian, jadi tidak ada peringatan konsistensi) |
| `StatusSlip` | `periksaBuka`, `periksaReset`, `bolehCetakPegawai` |
| `TokenQr` | `valid` (64 heksadesimal huruf kecil), `dariByteAcak` (32 byte dari service) |
| `AksesSlip` | `bolehMelihatMilikSendiri`, `bolehMengelola`, `dalamCakupan` (konstanta `App\Constants\Role`) |
| `FormatSlip` | `tanggalCetak`, `waktuWib`, `labelTotal`, `namaBerkasPdf` |

### Aturan dan sumbernya

| # | Aturan | Legacy [K] | v2 [V2] | Keputusan / implementasi |
|---|---|---|---|---|
| G-1 | Slip periode (B, T) terbuka mulai **T-B-04 10:00:00 WIB** (bulan yang sama), inklusif | `libraries/hr/Lsl_gaji.php:9-12`, `:108-116` (`>=`) | FSD-FN-D-11, SRS-FR-032, 05-Presensi D-11 (unit test tepat sebelum/sesudah ambang), MTC-036 | IKUT; `GerbangPeriode::sudahRilis`, dibandingkan per detik (`09:59:59.999999` belum, `10:00:00` sudah) |
| G-2 | Periode tertua Januari 2024 | `Lsl_gaji.php:9`, `:120` | Volume histori D-01 masih TBD (SRS-NFR-050) | IKUT; kode `periode_sebelum_awal` dibedakan dari `periode_belum_rilis`. Terjawab: AS-05, semua baris produksi diimpor |
| G-3/G-4 | Daftar naik Januari 2024 → terbaru; terbaru = bulan berjalan bila sudah rilis, selain itu bulan lalu; tidak bergantung ada-tidaknya data | `Lsl_gaji.php:118-148`, `controllers/hr/Sl_gaji.php:38-50` | — | IKUT; `daftarTersedia`, `terbaru` (34 periode pada 4 Oktober 2026 10:00 WIB; 1–4 Januari sebelum 10:00 → Desember tahun lalu) |
| G-5 | Pembanding jam dinding Asia/Jakarta, apa pun zona objek waktu | `Lsl_gaji.php:76-86`, `:110-122`, `config/config.php:4` | `appTimezone = 'UTC'`; "hari ini" bisnis WIB (CR-025) | IKUT WIB tetap (bukan per satker); PERBAIKI: objek pemanggil tidak dimutasi; `$sekarang` wajib dioper service (`Time::now()`) |
| G-6 | Gerbang di semua jalur pegawai (daftar, status, buka, cetak), ditegakkan di server; periode tidak valid = galat berkode, bukan fallback diam-diam | `Sl_gaji.php:39-50`, `:106`, `:194-196` | FSD-FN-D-11 | IKUT + PERBAIKI. Jalur admin wajib menyebut periode yang valid dan **tidak digerbang** di server [K] (`Sl_gaji.php:275-277`, `:168-196`, `:336-388`; impor bebas periode); daftar pilihan UI admin = periode tersedia (`Sl_gaji.php:219`, `:394`) |
| G-7 | Kunci periode API `YYYY-MM` (`^\d{4}-(0[1-9]\|1[0-2])\z`), tahun 1900–2100, tanpa trim | `Lsl_gaji.php:88-106` (`B-TTTT`) | — | PERBAIKI; `PeriodeSlip::dariTeks` → `periode_wajib`/`periode_tidak_valid` |
| N-1..N-4 | Nominal dibaca dari **nilai mentah sel** (`formatData = false`); teks hanya format Indonesia ketat (`1.234.567`, `1.234,50`; titik selalu ribuan); float tanpa pecahan di bawah sen; batas `DECIMAL(14,2)`; input API hanya kanonik titik-desimal | `Lsl_gaji.php:899`, `:952-970` (teks tampilan, titik dihapus, koma → titik), `:752-758` | SRS-NFR-011, PRD-FR-027 (tanpa silent-fail), D-10 | PERBAIKI; `NominalGaji::dariSel`/`dariInputApi`. Regresi: float `4500000.5` → 450000050 sen (legacy 45.000.005), koreksi `"4500000.00"` → 450000000 sen (legacy ×100) |
| N-5 | Nominal negatif | Impor menerima tanpa sengaja (`Lsl_gaji.php:965-969`), koreksi menolak eksplisit "harus berupa angka non-negatif" (`:755-756`) | — | IKUT aturan eksplisit + PERBAIKI celah impor: `nominal_negatif` di impor dan koreksi |
| N-6 | Teks `-` sebagai nol | Galat "bukan angka valid" (`Lsl_gaji.php:961-967`) | — | IKUT; galat. Sel numerik 0 berformat akuntansi tetap 0 karena nilai mentah |
| P-1/P-2 | Nama bulan Indonesia; label "Oktober 2026"; "Total Penghasilan Bulan Oktober 2026" | `config/constants.php:267`, `Lsl_gaji.php:133`, `print/cetak.php:422` (PDF; layar `views/hr/employee/sl_gaji/_slip_content.php:218` kini tanpa kata "Bulan") | — | IKUT label PDF; memakai ulang `TanggalBisnis::NAMA_BULAN` |
| P-3 | Bulan berkas: bulat 1–12 (int, float tanpa pecahan, teks `^\d{1,2}$`) atau nama lengkap (trim, tidak peka huruf) | `Lsl_gaji.php:843-862` (`"8.7"` → 8) | — | PERBAIKI; `bulan_tidak_valid`. Singkatan ditolak seperti legacy (singkatan → 0 → "Bulan tidak valid", `:942-944`) |
| P-4 | Tahun berkas: bulat 1900–2100 (int, float tanpa pecahan, teks `^\d{4}$`) | `Lsl_gaji.php:931`, `:945-947` (`"2026abc"` → 2026) | — | PERBAIKI; `tahun_tidak_valid` |
| P-5 | Tanggal cetak "4 Oktober 2026" (WIB, tanpa jam) | `Lsl_gaji.php:528-531` | Feature Slip Gaji meminta tanggal dan waktu | IKUT tanpa jam (`print/cetak.php:246`); `FormatSlip::tanggalCetak($sekarang)` |
| P-6 | Waktu dibuka disimpan stempel UTC, ditampilkan WIB + nama bulan Indonesia | Disimpan jam dinding WIB (`Lsl_gaji.php:84-86`, `:166`); tiga format tampil: admin dan verifikasi `d-m-Y H:i WIB` (`Sl_gaji.php:298`, `views/verify/slip.php:108`), halaman pegawai `d F Y, H:i WIB` dengan nama bulan Inggris (`views/hr/employee/sl_gaji/list.php:208`) | Konvensi stempel UTC v2 | IKUT format halaman pegawai + PERBAIKI nama bulan Indonesia dan satu format di semua keluaran: `FormatSlip::waktuWib` → "4 Oktober 2026, 10:00 WIB"; migrasi data WIB → UTC FASE-5 |
| P-7 | Nama berkas `Slip_Gaji_{NAMA}_{Bulan}_{Tahun}.pdf`, nama disanitasi `[A-Z0-9_]`, fallback NIP | `Lsl_gaji.php:573` | — | PERBAIKI; `FormatSlip::namaBerkasPdf` |
| P-8 | Label uang makan bulan−1 | `@771f8b4`: layar `views/hr/employee/sl_gaji/_slip_content.php:59-66` (bagian "C. Uang Makan", keterangan "Presensi {Bulan} {Tahun} · {n} hari"); PDF `print/cetak.php:362-365`, `:412-420` (label "Uang Makan (Presensi {Bulan} {Tahun})", baris "Uang Makan" di Ringkasan) | Feature/Brainstorm; F5-1 | IKUT `771f8b4` (revisi F5-1): uang makan bulan−1 ditampilkan dengan label di atas; bulan kehadiran = `PeriodeSlip::sebelumnya()`; nilai dari snapshot `uang_makan`/`uang_makan_hari`/`uang_makan_periode` (`m-YYYY`) yang ditulis saat slip dibuka (PRD 5A §7.3.7; nilai dihitung 5B) |
| P-9 | Nama bergelar `"{gelar awal} {nama}, {gelar akhir}"`, bagian kosong dilewati | `helpers/function_helper.php:102-108` (`formatNamaGelar`, dipakai admin, verifikasi, pratinjau); hanya PDF tanpa koma (`print/cetak.php:12`) | — | IKUT helper aplikasi di semua keluaran; `PencocokanNama::denganGelar` |
| P-10 | Bagian B (TK) hanya "Penghasilan Kotor" (Penghasilan) dan "Potongan" (Potongan); baris "Pajak" dan "Tunjangan Pajak" tidak ditampilkan | `@0cf7e67`: layar `_slip_content.php:43-46` (`$tkGroups`); PDF `print/cetak.php:274-277` (`$tkGroups`), `:360-361` (jumlah per kolom TK = `kotor` / `potongan` saja), `:408`. Bagian A "Iuran & Pajak" dan modal detail/edit admin tidak berubah | Keputusan Horii 28-10 (CR-067) | IKUT `0cf7e67`: kartu FE (`SlipGajiKartu`) dan PDF (`pdf/slip_gaji`). Kolom `pajak`/`tunj_pajak` tetap di DB dan tetap diimpor (I-3, tanpa migration); total slip tidak berubah (R-1..R-4 tidak memakai keduanya) |
| P-10 | Rupiah 0 desimal, setengah menjauhi nol, tanpa `-0` | `number_format(x, 0, ',', '.')`, awalan berbeda per keluaran | — | IKUT; `NominalGaji::formatAngka`; awalan "Rp" diputuskan UI. Sen disimpan (`DECIMAL(14,2)`), tampil 0 desimal seperti semua keluaran legacy |
| M-1..M-5 | Nama dicek bila sheet punya kolom `nama`; normalisasi `[a-z0-9]`; cocok = sama atau substring; kosong → `nama_kosong`; tidak cocok → ditolak **dan** dicatat untuk log | `Lsl_gaji.php:873-895`, `:1049-1110` | D-10 / FSD-FN-D-10 / MTC-035 (mismatch di-log, bukan silent-fail) | IKUT (lebih ketat dari spek: tolak + log); `susun` mengembalikan `nama_tidak_cocok`, service menulis log sekali per pratinjau. Positif palsu substring ("Ani" ~ "Daniel") disengaja longgar seperti legacy: tetap |
| M-6 | Setiap sheet yang punya kolom `nama` dicek sendiri | `Lsl_gaji.php:1090` (nama TK tertutup nama GPP) | — | PERBAIKI |
| I-1 | Sheet "GPP" dan "TK" (trim, tidak peka huruf) wajib ada keduanya | `Lsl_gaji.php:829-836`, `:1016-1018` | — | PERBAIKI; `KolomGaji::cariSheetWajib` → `sheet_tidak_ada` |
| I-2 | Header baris 1; normalisasi memadatkan whitespace/NBSP/`-`/`.`/`_` menjadi satu `_`; kolom kunci `nip`, `bulan`, `tahun` | `Lsl_gaji.php:900-918` | — | PERBAIKI; `sheet_kosong`, `kolom_ganda` (kolom dikenali), `kolom_kunci_tidak_ada`; kolom tak dikenal → info `kolom_diabaikan` |
| I-3 | 28 field: GPP 13 penghasilan + 8 potongan + `bersih` → `bersih_gpp`; TK 6 dengan `bersih` → `bersih_tk`; wajib terisi GPP `gjpokok`, `bersih`; TK `kotor`, `bersih` | `Lsl_gaji.php:15-70`, `:870-871` | — | IKUT peta; PERBAIKI: kolom peta yang hilang dari header → `kolom_tidak_ada` (legacy 0 diam-diam). Template resmi = 28 kolom tersebut [K] `downloadTemplate` (`Lsl_gaji.php:1291-1319`; `nama` opsional) |
| I-4 | Baris tanpa NIP dilewati; bila berisi nilai lain dilaporkan `baris_tanpa_nip` | `Lsl_gaji.php:924-927` (diam-diam) | — | IKUT + PERBAIKI (info, tidak memblokir) |
| I-5 | NIP wajib sel **teks**; digit + spasi (spasi dibuang); 1–18 digit; wajib terdaftar | `Lsl_gaji.php:929`, `:939-941`, `:1083-1084` | `PenggunaModel::NIP_MAX_DIGITS = 18` | PERBAIKI; `nip_bukan_teks` (sel numerik kehilangan presisi), `nip_tidak_valid`, `nip_tidak_terdaftar` |
| I-8 | Kunci NIP + periode ganda dalam satu sheet → baris pertama dipertahankan + galat yang menyebut kedua nomor baris | `Lsl_gaji.php:973-981` | D-10 / FSD-FN-D-10 / MTC-035 | IKUT; `baris_ganda` (`8` dan `Agustus` = kunci sama) |
| I-9 | Setiap kunci wajib ada di kedua sheet | `Lsl_gaji.php:1115-1120` | — | IKUT; `tidak_ada_di_gpp`/`tidak_ada_di_tk` |
| I-10 | Filter periode opsional: bulan dan tahun diisi berdua atau tidak sama sekali | `controllers/hr/Sl_gaji.php:457-460`, `Lsl_gaji.php:1036-1047` | — | IKUT + PERBAIKI; `filter_periode_tidak_lengkap`, `periode_tidak_valid`, `periode_tidak_ada_di_berkas`; baris yang periodenya tidak terbaca tetap dilaporkan sebagai galat (legacy membuangnya diam-diam) |
| I-11 | Status galat > duplikat (kunci sudah ada di DB) > valid; urut tahun → bulan → NIP numerik; nominal 28 field default 0 ditimpa GPP lalu TK | `Lsl_gaji.php:1122-1173` | — | IKUT |
| I-12 | Commit parsial per kunci: valid → sisip; duplikat → perbarui (timpa) atau lewati; galat → gagal | `Lsl_gaji.php:1176-1289` | D-10 (3 langkah; UNIQUE tertangkap) | IKUT; `rencanaCommit`. Opsi timpa bawaan **tercentang** [K] (`views/hr/employee/sl_gaji/admin_import.php:54`) → `PratinjauImpor::TIMPA_BAWAAN = true`, dipakai FE/service Fase 5 sebagai nilai awal |
| I-13 | Impor oleh role 3 dibatasi satkernya | Role 3 boleh impor tanpa cakupan (`UL_ADMIN`, `Sl_gaji.php:390-489`) | PRD SG-2 (impor role 3 hanya pegawai di satkernya); Matriks lama: role 1 saja | [K] role 3 boleh impor + [V2] dibatasi satker (PRD SG-2): `susun(..., $cakupanSatker)` → `nip_di_luar_cakupan` |
| R-1..R-4 | `jumlah_penghasilan_gpp`, `jumlah_potongan_gpp`; **`total_penghasilan = bersih_gpp + bersih_tk + uang_makan`**; `bersih_2` tidak masuk total; baris tukin kelas jabatan **dihapus** | `@771f8b4` `Lsl_gaji.php:397-425` (`hitungRingkasan`) | Feature Slip Gaji §6.4: gaji bersih + tunjangan kinerja + uang makan bulan−1 | IKUT `771f8b4` (revisi F5-1), int sen. Kunci masukan `uang_makan` opsional (tidak ada = 0); bentuk keluaran `RingkasanSlip::hitung` tidak berubah |
| H-1..H-5 | Hak akses: lihat bagian berikut | `config/constants.php:298-301`, `Sl_gaji.php:19`, `:74`, `:162`, `:215` dst. | Matriks Modul D, FSD-FN-D-10/11 | Keputusan user 01-10-2026 |
| S-1..S-5 | `viewed_at` kosong = belum dibuka; buka hanya bila belum; buka ulang (reset) hanya bila sudah, riwayat token dipertahankan; cetak pegawai selama dibuka | `Sl_gaji.php:92-158`, `:194-196`, `Lsl_gaji.php:791-820` | Feature Slip Gaji (akses 1x) | IKUT urutan dan pesan; `StatusSlip`. Buka hanya oleh pegawai pemilik (PERBAIKI). Pegawai boleh cetak selama dibuka [K] (`Sl_gaji.php:194-196`); admin boleh cetak kapan saja tanpa mengubah status [K] (`:168-196`) + [V2] tanpa QR bila belum ada token (QR `no-token` legacy, `Lsl_gaji.php:491`, menyesatkan) |
| S-6 | Koreksi/timpa setelah slip dibuka | Diizinkan tanpa syarat; status dan token tetap (`saveGajiEdit` `Lsl_gaji.php:743-783`, timpa `:1199-1229`); koreksi diaudit lama → baru (`:770-779`), impor per commit (`:1267-1279`) | — | IKUT: boleh koreksi/timpa, status dan token tetap, diaudit. Service Fase 5 tidak mereset atau memblokir. Aman karena verifikasi v2 tidak menampilkan nominal (AS-06) |
| Q-1..Q-3 | Token QR 64 heksadesimal huruf kecil, baru setiap kali dibuka; verifikasi publik mencari di riwayat token | `Sl_gaji.php:127`, `Lsl_gaji.php:188-198`, `:578-625`, `controllers/Verify.php:3-22` | Feature Slip Gaji (verifikasi tanpa login) | IKUT; PERBAIKI: format token divalidasi (`TokenQr::valid`) sebelum query, format salah dijawab sama dengan "tidak ditemukan". Data yang tampil [V2] PRD AS-06 (lihat "Terjawab dari legacy" U-4) |

### Hak akses (keputusan user 01-10-2026: ikut legacy)

| Role | Akses Slip Gaji | Implementasi |
|---|---|---|
| 2 Pegawai, 6 PTT, 7 PPPK | Lihat, buka, dan cetak slip milik NIP **dari token login**; parameter NIP dari klien diabaikan | `AksesSlip::bolehMelihatMilikSendiri` (`Role::UL_PEGAWAI`) |
| 1 Super Admin | Semua pegawai, semua aksi admin (kelola, lihat, koreksi, buka ulang, impor) | `bolehMengelola`, `dalamCakupan` → selalu `true` |
| 3 Admin Satker | **[V2] PRD SG-2** (legacy role 3 tidak dibatasi satker: semua method admin hanya `userAuth(UL_ADMIN)`, `Sl_gaji.php:214-495`; filter satker monitoring dipilih bebas, `Lsl_gaji.php:652-659`). Hanya pegawai yang `id_satker` jabatan **saat ini** sama (string) dengan `id_satker` akun admin (klaim JWT). Admin tanpa `id_satker` ditolak untuk seluruh jalur (fail-closed); target di luar cakupan → 403, bukan 404 (pola `UserService::assertAdmin`/`findInScope`). Fallback unit dan kekhususan unit tidak dipakai | `dalamCakupan(3, $satkerAdmin, $satkerPegawai)` |
| 4, 5, 8 | Tidak ada akses (403) | `bolehMelihatMilikSendiri`/`bolehMengelola` → `false` |
| Publik | Verifikasi QR `/verify/slip/{token}` tanpa login | `TokenQr::valid` (endpoint Fase 5) |

Admin 1/3 yang juga punya NIP melihat slipnya lewat jalur admin, yang tidak mengubah status "dibuka" [I]. **Deviasi dokumen:** Matriks_Role_x_Endpoint, FSD-FN-D-10/D-11, dan 05-Presensi D-10/D-11 menyebut impor/kelola role 1 saja serta daftar/cetak role 1 dan 2; keputusan 01-10-2026 menggantinya dengan tabel di atas. Role 3 boleh mengimpor dalam cakupan satkernya (I-13, PRD SG-2); revisi Matriks/FSD/05-Presensi adalah tugas dokumen, bukan pertanyaan terbuka.

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
| K-CR027-8 | Ringkasan setia legacy (`bersih_gpp + bersih_tk`); tanpa cek invarian karena legacy tidak punya (CR-039 menghapus `periksaKonsistensi`) |
| K-CR027-9 | Hak akses = keputusan user 01-10-2026 (tabel di atas) |
| K-CR027-10 | Fungsi murni menerima data DB sebagai argumen (baris mentah sheet, peta pegawai, kunci yang sudah ada); service Fase 5 yang mengambilnya |

### Terjawab dari legacy (CR-039, 02-10-2026)

Semua butir yang dulu menunggu Biro Keuangan (TUNGGU-BK) atau user/reviewer CR (TUNGGU-USER) terjawab dari kode legacy
`39b6b15`; U-8 (teknis, reviewer CR) selesai di CR-040. Rujukan rinci ada di tabel aturan di atas.

| # | Butir | Jawaban |
|---|---|---|
| BK-1 | Rumus total | [K] `@771f8b4` `bersih_gpp + bersih_tk + uang_makan` (`Lsl_gaji.php:414-415`); `bersih_2` hanya tampil; tukin kelas jabatan tidak ditampilkan lagi (F5-1). Baseline `39b6b15` (tanpa uang makan, tukin kelas jabatan tampil terpisah) tidak berlaku lagi |
| BK-2 | Invarian `bersih_*` sebagai peringatan pratinjau | [K] tidak ada di legacy → `RingkasanSlip::periksaKonsistensi` dan test-nya dihapus |
| BK-3 | Nominal negatif; teks `-` | [K] koreksi menolak negatif (impor diperketat sama); `-` = galat |
| BK-4 | Sen | [K] disimpan `DECIMAL(14,2)`, tampil 0 desimal |
| BK-5 | Template 28 kolom; singkatan bulan | [K] `downloadTemplate` 28 kolom; singkatan ditolak |
| BK-6 / S-6 | Koreksi/timpa slip yang sudah dibuka | [K] boleh; status dan token tetap; diaudit |
| P-8 | Label uang makan bulan−1 | [K] `@771f8b4` ditampilkan (lihat tabel aturan P-8) |
| U-1 | Cakupan role 3 | [V2] PRD SG-2 (legacy tidak membatasi); sumber satker [K] jabatan saat ini; tanpa fallback unit [V2] fail-closed |
| U-1b / I-13 | Impor role 3 | [K] boleh + [V2] dibatasi satker (PRD SG-2) |
| U-2 | Admin memilih periode di luar gerbang | [K] jalur admin tidak digerbang di server |
| U-3 | Cetak pegawai; PDF admin sebelum dibuka | [K] pegawai selama dibuka; admin kapan saja + [V2] tanpa QR bila belum ada token |
| U-4 | Data halaman verifikasi publik | [V2] PRD AS-06: status keaslian, periode, satker penerbit, waktu terbit; nama tersamar opsional; tanpa nominal dan NIP utuh (legacy menampilkan NIP utuh dan Total Penghasilan, `views/verify/slip.php:96-108`) |
| U-5 | Format waktu dibuka; nama + gelar; jam pada tanggal cetak | [K] "4 Oktober 2026, 10:00 WIB" (halaman pegawai, nama bulan Indonesia); `formatNamaGelar`; tanpa jam |
| U-6 | Nilai bawaan opsi timpa impor | [K] **tercentang** → `PratinjauImpor::TIMPA_BAWAAN = true` |
| U-7 | Positif palsu substring pencocokan nama | [K] tetap (disengaja longgar) |
| U-9 / G-2 | Periode tertua Januari 2024 | [K] `PERIODE_START_DATE = '2024-01-01'`; AS-05 semua baris produksi diimpor |

**U-8 selesai (CR-040):** pemindai kemurnian ketiga penjaga (`KalkulasiMurniTest` Kepegawaian, `SlipGajiMurniTest`,
`KalkulasiMurniTest` Tukin) diekstrak ke `tests/_support/Libraries/PemindaiKemurnian.php` dengan daftar larangan gabungan
(tidak ada yang dilonggarkan); uji dirinya di `tests/unit/Libraries/PemindaiKemurnianTest.php`. Slip Gaji memakai
konfigurasi bawaan tanpa pengecualian.

### Pelaksanaan Fase 5A (pengganti bagian "Menunggu Fase 5")

Port D-01/D-10/D-11/D-14 dan FE Slip Gaji kini diatur PRD Fase 5A (`docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md`,
LOCKED): skema DBV-023 (§7.1), impor 3 langkah (§7.2), akses pegawai & kelola admin + PDF/QR (§7.3), verifikasi publik
(§7.4), FE (§7.8). Kode ber-DB/IO 5A berada di `Libraries/Presensi/Slip` (bukan folder murni ini); folder ini hanya
diubah sebatas §7.3.8 (`RingkasanSlip`). Nilai uang makan slip disambung D-09 (5B) lewat `UangMakanSlipInterface`
— **wajib sebelum go-live** (F5-1).

PDF slip (`Slip/PdfSlipGaji`, view `pdf/slip_gaji`, port `print/cetak.php` @771f8b4, bagian TK @0cf7e67) — keputusan reviewer CR 20-10:

| # | Butir | Keputusan |
|---|---|---|
| PDF-1 | Logo kop | `web_config` `logo_kementerian_pdf` (kiri) / `logo_wonderful_pdf` (kanan), ASSET_PATH relatif dari akar aset v2 `Config\Presensi::$akarAset` (bawaan `WRITEPATH . 'aset/'`, override `.env` `presensi.akarAset` tervalidasi). Di-resolve service lewat `Slip/KopSlip::resolveAset` (tolak `..`, path absolut, keluar akar); kosong / tidak ada / bukan PNG-JPEG → PDF tanpa logo (seperti PDF LKH). Akar aset final ditetapkan saat cutover bersama G-09 |
| PDF-2 | Baris "Dibuka: {waktu WIB}" | **[V2]** PRD §7.3.6 (legacy tidak punya); di bawah "Jakarta, {tanggal cetak}" di kolom QR; hanya bila slip sudah dibuka |
| PDF-3 | Nama instansi "adalah benar pegawai …" | `web_config` `nama_kementerian`; kosong → literal legacy [K] "Kementerian Pariwisata" (`print/cetak.php:201`; konstanta tunggal `App\Libraries\Pdf\KopDokumen::NAMA_INSTANSI_BAWAAN`, CR-066) |
| PDF-4 | QR | wajib gambar; keluaran bukan `<img>` (tanpa gd/imagick) → cetak gagal, token tidak dicetak sebagai teks (§7.3.6); tanpa token → tanpa QR (U-3) |
| PDF-5 | Logo bawaan v2 (katalog `web_config`: "kosong = logo bawaan v2", kontrak Fase 2) | logo bawaan v2 belum tersedia → tanpa logo, sama dengan PDF LKH; katalog tidak diubah. Akar aset v2 final + berkas logo bawaan ditetapkan saat cutover bersama G-09 |
| PDF-6 | Kaki halaman "Halaman x dari y" + username (pola CR-064 `DokumenPdf::TOKEN_HALAMAN`/`TOKEN_TOTAL`) | **Tidak dipakai** di slip [K] `print/cetak.php` @771f8b4 + PRD §7.3.6: slip satu halaman per pegawai dengan QR verifikasi; `PdfSlipGaji` tidak mengirim `kakiHtml` (keputusan reviewer CR 22-10-2026) |
| PDF-7 | Bagian B (TK) | [K] `print/cetak.php` @0cf7e67 `:274-277`, `:360-361`: tanpa baris "Pajak"/"Tunjangan Pajak"; "Jumlah Penghasilan"/"Jumlah Potongan" TK = `kotor` / `potongan` (sebelumnya + `tunj_pajak` / + `pajak`), sehingga Jumlah Penghasilan − Jumlah Potongan = TK Bersih bila data konsisten. CR-067 |

### Test

`tests/unit/Presensi/SlipGaji/` (tanpa DB; konvensi `tests/unit` untuk kelas murni, sama dengan CR-025):

```bash
cd backend && vendor/bin/phpunit --no-coverage tests/unit/Presensi
```
