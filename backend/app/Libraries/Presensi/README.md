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
— **wajib sebelum go-live** (F5-1); sejak 5B M2 (MAKE-040) bawaan `Config\Presensi::$sumberUangMakan` =
`Slip\UangMakanSlipPresensi` (lihat bagian "Uang makan slip 5B").

Batas baca berkas impor (`Slip/PembacaExcelSlip` + `Slip/PemindaiXlsx`, CR-177; `Slip/PenaksirBebanRumus`, CR-181) —
pengaman supaya berkas yang tidak wajar ditolak 422 `errors.berkas`, bukan 500 karena memori atau waktu habis. Tiga lapis:
anggaran **seluruh berkas** sebelum dimuat, batas baris/kolom sheet GPP dan TK sesudah dimuat, lalu anggaran beban hitung
rumus sebelum ada sel rumus yang dihitung (BB-12).

| # | Butir | Keputusan |
|---|---|---|
| BB-1 | Reader | Dikunci ke `Xlsx`/`Xls`. **[V2]** Berkas yang hanya dikenali reader lain (HTML, CSV, …) → "Berkas tidak dapat dibaca. …" (dulu dibaca reader itu lalu ditolak "sheet tidak ada") |
| BB-2 | Pindai `.xlsx` sebelum muat | `PemindaiXlsx` membaca **semua** entri ZIP sebagai aliran (potongan 64 KB, parser SAX), tanpa mengikuti relasi, tanpa memilih sheet, dan tanpa menampung satu entri utuh; entri bernama akhiran `/` ikut dipindai. `listWorksheetInfo` reader Xlsx **tidak dipakai**: `totalRows`-nya = `r` baris terakhir (sejuta `<row>` tanpa `r` terbaca 0 baris), path part sheet dihitung berbeda dari pemuat, isi part ditampung utuh, dan hanya sel yang dihitung |
| BB-3 | Anggaran seluruh berkas `.xlsx` (sebelum muat) | Diperiksa berurutan: (1) ukuran isi sesudah dekompresi > 32 MB → "Isi berkas terlalu besar sesudah dibuka (maksimal 32 MB)."; (2) jumlah sel > (batas baris + 1) × 40 = 400.040 (batas baris di bawah 10.000 dihitung 10.000) → "Jumlah sel melebihi batas 400.040 per berkas. Hapus sheet atau kolom yang tidak dipakai, lalu unggah ulang."; (3) struktur → "Struktur berkas terlalu besar untuk diproses. Simpan ulang berkas hanya dengan sheet GPP dan TK, tanpa rumus (tempel sebagai nilai) atau teks sangat panjang.", yaitu salah satu dari: bobot XML (semua elemen + 2 × semua atribut) > batas sel × 8 = 3.200.320; langkah definisi kolom `<col>` > 200.000; `<tablePart>` > 50; byte teks yang disalin pemuat per sel > 32 MB (shared string yang dirujuk tiap sel `t="s"` + teks rumus `<f t="shared">` × sel turunannya) |
| BB-4 | Batas baris dan kolom (sesudah muat) | Hanya sheet GPP dan TK, diperiksa sebelum array baris × kolom disusun: baris data > `presensi.maksBarisImporPerSheet` → "Jumlah baris melebihi batas … per sheet." (pesan lama); kolom > 256 → "Jumlah kolom melebihi batas 256 per sheet." (baru). GPP diperiksa dulu, lalu TK, jadi pesan tidak bergantung urutan tab. Keduanya dimuat dalam **satu muatan** seperti sebelum CR-177, supaya rumus lintas sheet (TK → GPP, GPP → TK, nama terdefinisi) tetap terhitung. Sheet lain di berkas (referensi panjang, matriks lebar) dan `<row>` berformat tanpa sel **tetap diterima**; memorinya dibatasi BB-3 |
| BB-5 | Nilai batas (`Config\Presensi`, konstanta, bukan `.env`) | `MAKS_KOLOM_IMPOR = 256` (template 26 kolom GPP, 9–10 kolom TK; 256 = lebar penuh `.xls`). `SEL_PER_BARIS_IMPOR = 40` (36 kolom template + margin ±11%; mengikuti batas baris bila dinaikkan lewat `.env`, tidak turun di bawah hitungan 10.000 baris). `BOBOT_PER_SEL_IMPOR = 8` (template tulisan PhpSpreadsheet 4,6 per sel; tulisan Excel ±7). `MAKS_ITERASI_KOLOM_IMPOR = 200.000` (berkas sah puluhan sampai ±16 ribu). `MAKS_TABEL_IMPOR = 50`. `MAKS_BYTE_ISI_IMPOR = 32 MB` (±2,5× isi template penuh; juga batas byte salinan teks) |
| BB-6 | Sel kosong | Sel kosong yang hanya bergaya ikut dihitung sebagai baris/kolom, **sama seperti sebelumnya** (pemuat membuat sel untuk sel kosong bergaya) |
| BB-7 | Entri ber-encoding lain, DOCTYPE, galat urai, nama sheet kembar | Supaya yang dipindai sama dengan yang diurai pemuat (`Reader\Security\XmlScanner` lalu SimpleXML): entri yang deklarasi `encoding` pertamanya bukan UTF-8 (alias `UTF8` diterima) → "Berkas tidak dapat dibaca. …", karena pemuat mengonversi isinya sebelum diurai. Nama sheet kembar (tanpa membedakan huruf besar-kecil; Excel tidak pernah menulisnya) → "Berkas tidak dapat dibaca. …", karena pemuat memuat part yang sama berkali-kali. GPP dan TK yang berisi part yang sama (relasi keduanya menunjuk satu part) → "Berkas tidak dapat dibaca. …": `Slip/PemuatXlsxSlip` mencegat pengambilan isi part oleh pemuat (tiap part sheet diambil tepat dua kali pada PhpSpreadsheet 5.10.0) dan menolak pengambilan ketiga untuk isi ber-elemen `<sheetData>` yang sama, tanpa menebak aturan path pemuat. **Tetap diterima:** entri XML yang gagal diurai parser pemindai (VML tulisan Excel dengan `<br>` tanpa penutup, SVG dengan prefiks tak dideklarasikan; isinya dihitung kasar sebagai batas atas: tiap `<` satu elemen, tiap `=` satu atribut, tiap `<c` satu sel), entri ber-`<!DOCTYPE` (mis. logo SVG; tidak diurai, pemuat sendiri menolak part seperti itu bila dimuat), entri UTF-16 ber-BOM (diurai apa adanya), dan entri bukan XML (gambar, biner) |
| BB-8 | Pra-cek `.xls` | `listWorksheetInfo` reader Xls untuk sheet GPP dan TK (memindai rekaman sel yang sama dengan pemuat): batas baris, kolom, dan baris × kolom kedua sheet ≤ batas sel. Batas isi/bobot/definisi kolom tidak berlaku (bukan ZIP/XML) |
| BB-9 | Read filter | **Tidak dipasang, jangan ditambahkan.** Begitu ada read filter, reader Xlsx memanggilnya untuk setiap kolom `<cols>` × setiap baris (`Xlsx/ColumnAndRowAttributes`): berkas 51 KB dengan `<cols>` lebar dan 10.001 baris dimuat 28,4 detik (tanpa filter 0,2 detik); `<cols>` sampai 1025 seperti tulisan LibreOffice 2,0 detik (tanpa filter 0,2 detik). Dijaga test `testDefinisiKolomLebarDiterimaDalamWaktuWajar` |
| BB-10 | Kebutuhan server | **`memory_limit` ≥ 512M dan RAM bebas ±1 GB per impor yang berjalan bersamaan** (README-deploy §1). Template penuh berangka: puncak memori PHP +214 MB, puncak RSS proses ±612 MB (pohon XML libxml di luar `memory_limit`). Kasus sah terberat yang masih lolos anggaran, 390.039 sel **teks pendek** (`t="s"`): PHP +258 MB (fatal pada 256M), RSS ±925 MB |
| BB-11 | Sisa risiko (dicatat, tidak ditutup) | (a) **Tertutup CR-181 (BB-12):** sel rumus dengan rentang raksasa (mis. `SUM(A1:XFD1048576)`) ditolak sebelum dihitung. (b) Shared string panjang pada `.xls` (tabel SST): batas salinan teks hanya untuk `.xlsx`. (c) Faktor bobot 8 dan kolom rumus: template penuh dengan ±10 kolom rumus (`<f>` menambah elemen dan atribut per sel) bisa ditolak dengan pesan struktur; bagian waktu **tertutup CR-181 (BB-12):** 22 kolom rumus IF/ROUND yang dulu lolos dan dibaca ±150 detik kini ditolak lewat anggaran jumlah beban rumus. Jalan keluarnya tempel sebagai nilai (Paste Values), sudah disebut di pesan. (d) Teks shared lebih dari ±3,3 KB yang diulang di 10.000 baris melewati batas salinan 32 MB dan ditolak dengan pesan struktur. (e) Template penuh ditambah sheet referensi besar melewati batas sel; pesannya sudah menuntun (hapus sheet/kolom yang tidak dipakai). (f) Bagian `externalLinks` dan `pivotCache` ikut dihitung dalam anggaran; berkas yang ditolak karenanya perlu Break Links atau disimpan ulang tanpa pivot. (g) 50 `<tablePart>` (tepat di batas) dengan relasi sheet sangat panjang masih dibaca ±59 detik. (h) Part tanpa `<sheetData>` yang dipakai bersama oleh GPP dan TK tetap diurai untuk tiap sheet (yang ditolak `PemuatXlsxSlip` hanya part ber-`<sheetData>` yang sama); terukur RSS 827 MB menjadi 1.213 MB pada berkas buatan, sama seperti sebelum CR-177. (i) Pola baru sejenis (berkas buatan yang membuat PhpSpreadsheet membangun lebih banyak daripada yang dihitung pemindai) dicatat di sini, tidak ditutup satu per satu. (j) Sisa sesudah CR-181 (dicatat, tidak ditutup): fungsi yang kerjanya superlinear terhadap rentang yang masih di dalam anggaran (mis. `MMULT` pada dua rentang besar); fungsi basis data (`DSUM` dkk.) memakai pengali 20 yang belum diukur pada kriteria nyata; penghasil teks besar selain `REPT` berargumen literal (`CONCAT`/`TEXTJOIN` atas rentang, operator `&` berantai) tidak ditaksir dari ukuran hasilnya; rantai rumus mundur (sel merujuk sel rumus di bawahnya, berantai ±20–30 ribu tingkat) membuat kalkulator memanggil dirinya sedalam itu dan fatal memori pada 512M, padahal beban tiap selnya kecil. Impor hanya untuk admin (role 1/3) |
| BB-12 | Anggaran beban hitung rumus (CR-181) | Sel rumus GPP/TK **tetap dihitung ulang** (`getCalculatedValue`, sama dengan legacy `toArray(null, true, …)` dan AC-D10.9); nilai tersimpan di berkas tidak dipakai. Yang baru: sesudah muat dan batas baris/kolom, SEBELUM ada sel yang dihitung, beban semua sel rumus GPP + TK ditaksir dari **teks rumusnya** (`Slip/PenaksirBebanRumus`). Penaksir tidak menghitung, membuat, atau mengubah sel dan tidak menyentuh kalkulator workbook. **Rumus beban** (1 satuan ≈ 3–7 mikrodetik hitung): beban dasar sel = 18 + panjang teks + panjang² / 3.000; tiap rentang yang dijabarkan = 20 + baris × kolom + baris² / 6.000; bila rentang itu argumen fungsi selain `SUM`/`COUNT`/`COUNTA`/`AVERAGE`/`HLOOKUP`/`INDEX`/`ROWS`/`COLUMNS`/`ROW`/`COLUMN`/`AND`/`OR`, suku kuadratiknya baris² / 900; bila argumen fungsi berkriteria (`COUNTIF(S)`, `SUMIF(S)`, `AVERAGEIF(S)`, `MAXIFS`, `MINIFS`), luasnya × 10 (fungsi basis data `D…` × 20); `J:J` / `2:2` dipangkas ke data sheet rujukan seperti kalkulator, ditambah jumlah sel sheet itu / 200 (biaya pindai indeks sel); nama terdefinisi ditaksir dari isinya setiap kali dipakai; nama tabel dan rujukan terstruktur = seluruh rentang tabel + jumlah kolomnya; `REPT` berargumen literal = byte hasil / 4. **Dianggap tak terbatas (selalu ditolak):** teks rumus lebih dari 8.192 karakter (batas Excel), `INDIRECT`, `OFFSET`, `ANCHORARRAY` (operator `#`), `SINGLE` (operator `@`), `SEQUENCE`/`RANDARRAY`/`MUNIT`/`EXPAND`, `REPT` dengan jumlah ulang bukan literal, ujung rentang hasil hitung (`A1:INDEX(…)`, `A1:(5)`), rujukan 3D, rumus yang tidak terurai, dan nama terdefinisi berputar atau lebih dalam dari 8 tingkat. **Batas:** satu sel ≤ 400.000 (`MAKS_BEBAN_PER_RUMUS_IMPOR`), jumlah ≤ 3.000.000 (`MAKS_BEBAN_RUMUS_IMPOR`); penaksiran berhenti begitu batas terlewati. Lewat batas → 422 "Rumus di sheet GPP/TK terlalu berat untuk dihitung. Tempel sebagai nilai (Paste Values), lalu unggah ulang." Pesan baris/kolom tetap menang atas pesan rumus (urutan seperti sebelum CR-181). Teks rumus diurai dengan pengurai kalkulator sendiri (`Calculation::parseFormula` pada kalkulator tersendiri) supaya taksiran tidak menyimpang dari yang akan dikerjakan. **Perubahan perilaku yang disengaja (pagar masukan, bukan perubahan nilai):** berkas yang rumusnya melewati anggaran kini 422; sebelumnya berakhir 500/timeout di server berbatas 60 detik (legacy berbatas 300 detik mungkin sempat menghitungnya). **Yang ditolak walau murah dihitung — admin harus menempel sebagai nilai:** `INDIRECT`/`OFFSET` sekecil apa pun, `A1:INDEX(…)`, `REPT` dengan jumlah ulang dari sel (mis. `REPT("0",18-LEN(A2))`), dan rentang raksasa di cabang `IF` yang tidak terpakai (taksiran menghitung semua cabang). Rujukan 3D dan rumus tidak terurai dulu mendapat "Berkas tidak dapat dibaca", sekarang pesan rumus. Berkas yang diterima: nilainya identik dengan sebelum CR-181 (dijaga `PembacaExcelSlipAnggaranRumusTest`, dibandingkan dengan kalkulator langsung) |

Angka ukur (PHP 8.4, PhpSpreadsheet 5.10, berkas uji sintetis; "PHP" = kenaikan puncak `memory_get_peak_usage`, "RSS" = puncak
`ru_maxrss` proses yang hanya memuat berkas itu):

| Berkas | Sebelum CR-177 | Sesudah |
|---|---|---|
| Template resmi terisi penuh: 10.000 baris GPP 26 kolom + TK 10 kolom = 360.036 sel; berkas 1,5 MB, isi 12,4 MB; 780.461 elemen + 440.502 atribut (bobot 1.661.465) | dibaca ±8 detik, PHP +214 MB, RSS 612 MB | sama, ditambah pindai ±1 detik (PHP +0,1 MB) |
| Seperti di atas + 30 kolom tambahan di GPP = 660.066 sel; isi 22,1 MB | dibaca ±16 detik, PHP +338 MB | ditolak (sel), PHP +0,1 MB |
| Sel di XFD1 + 10.000 baris data (berkas < 20 KB) | fatal memori (±163 juta iterasi sel) | ditolak (kolom, sesudah muat), PHP +5 MB |
| 250.000 baris data × 2 sel | dimuat penuh ratusan MB, baru ditolak | ditolak (sel), PHP +0,1 MB |
| 300.000 `<row>` tanpa `r` × 2 sel (berkas < 200 KB) | dimuat penuh, PHP +344 MB, baru ditolak | ditolak (sel), PHP +0,1 MB |
| 3,5 juta elemen kosong di luar sel (berkas < 200 KB) | diterima; PHP +20 MB tetapi RSS ±900 MB | ditolak (struktur), PHP +0,1 MB |
| Tepat di batas bobot: 3.200.300 elemen kosong, atau 1.066.000 elemen ber-atribut | diterima | diterima; RSS 839 MB / 802 MB |
| 10.001 × 39 = 390.039 sel teks pendek `t="s"` (di dalam semua anggaran; berkas 1,1 MB) | diterima; PHP +258 MB, RSS 925 MB | sama (kasus terberat yang lolos; dasar syarat `memory_limit` 512M) |
| GPP dan TK menunjuk part yang sama, 120.000 sel | kedua salinan di memori sekaligus, PHP +131 MB | ditolak saat part diminta untuk sheet kedua |
| 1.000 `<tablePart>` + relasi sheet panjang (berkas 14 KB) | dimuat 157 detik | ditolak (struktur) |
| Satu shared string 10.000 huruf dirujuk 5.000 sel (berkas belasan KB) | diterima, 50 MB salinan; versi 32.767 huruf × 400 ribu sel +1,4 GB | ditolak (struktur) |
| Rumus shared 10.000 huruf × 5.000 sel turunan | diterima; versi besar fatal saat muat | ditolak (struktur) |
| 1.000 `<col min="1" max="16383">` (berkas 7 KB) | dimuat 16,5 detik | ditolak (struktur) |
| `<col min="5" max="2">` | perulangan pemuat tidak berhenti → fatal memori | ditolak (struktur) |

Angka ukur anggaran rumus BB-12 (PHP 8.4, PhpSpreadsheet 5.10; mesin dev, satu proses ukur, tanpa beban lain yang
disengaja; di mesin yang sedang menjalankan gate lain waktu hitung terukur sampai ±2× lebih lama). "Waktu hitung" = hanya
`getCalculatedValue` semua sel rumus itu; rentang merujuk sheet GPP 10.000 baris × 26 kolom:

| Rumus (jumlah sel rumus) | Beban taksiran | Per sel | Waktu hitung | Per satuan beban |
|---|---|---|---|---|
| `=C2-D2` (10.000) | 297.788 | 32 | 1,3 detik | 4,5 µs |
| `=ROUND(IF(C2>0,C2*0.05+D2*0.1-E2,0),0)` (10.000) | 675.576 | 72 | 4,2 detik | 6,2 µs |
| PPh progresif `ROUND(IF(…IF(…IF(…))),0)` 169 karakter (10.000) | 1.872.151 | 196 | 5,6 detik | 3,0 µs |
| `=SUM(C2:F2)` (10.000) | 587.788 | 61 | 2,0 detik | 3,4 µs |
| `=ROUNDUP(SUM(C2:F2),-2)-SUM(C2:F2)` (10.000) | 1.115.576 | 116 | 4,5 detik | 4,0 µs |
| `=MAX(C2:F2)-MIN(C2:F2)` (10.000) | 995.576 | 104 | 4,5 detik | 4,5 µs |
| `=VLOOKUP(F2,GPP!$A$1:$B$18,2,0)` (10.000) | 1.078.894 | 109 | 5,8 detik | 5,3 µs |
| `=COUNTIF(GPP!$C$1:$C$20,C2)` (3.000) | 802.893 | 268 | 3,4 detik | 4,2 µs |
| `=SUM(GPP!$C$1:$C$10000)` (50) | 1.336.350 | 26.727 | 9,2 detik | 6,9 µs |
| `=MAX(GPP!$C$1:$C$10000)` (20) | 2.423.440 | 121.172 | 16,1 detik | 6,6 µs |
| `=MATCH(A2,GPP!$A$1:$A$10000,0)` (20) | 2.423.591 | 121.180 | 16,2 detik | 6,7 µs |
| `=COUNTIF(GPP!$C$1:$C$10000,">5")` (10) | 2.111.810 | 211.181 | 14,3 detik | 6,8 µs |
| `=SUMIF(GPP!$C$1:$C$5000,"*5*",GPP!$D$1:$D$5000)` (10) | 1.556.590 | 155.659 | 7,9 detik | 5,1 µs |
| `=COUNTIFS(` dua rentang 5.000 baris `)` (8) | 1.245.344 | 155.668 | 8,4 detik | 6,7 µs |
| `=VLOOKUP(A2,GPP!$A$1:$Z$10000,5,0)` (7) | 2.598.281 | 371.183 | 19,6 detik, PHP +38 MB | 7,5 µs |
| `=SUM(GPP!$C$1:$C$40000)` (2) | 613.454 | 306.727 | 2,6 detik, PHP +23 MB | 4,2 µs |
| Rumus 1.750 karakter (250 suku) (1.000) | 2.732.763 | 3.351 | 13,6 detik | 5,0 µs |
| Rumus 8.050 karakter (1.150 suku) (110) | 2.528.360 | 29.668 | 7,6 detik | 3,0 µs |
| Rumus 7.700 karakter berisi 550 rentang (60) | 2.125.020 | 36.308 | 5,2 detik (+2,7 detik taksir) | 2,5 µs |
| `=COUNTIFS(` tiga rentang 10.000 baris `)` (29) | 18.371.297 | 633.493 | (141 detik sebelum anggaran ini) | ditolak (per sel) |
| `=MATCH(…,GPP!$C$1:$C$10000,0)` (85) | 10.300.716 | 121.184 | (lewat 60 detik sebelum anggaran ini) | ditolak (jumlah) |
| Templat 10.000 baris + 22 kolom `ROUND(IF(…))` (220.000) | ±15,8 juta | 72 | (±110–150 detik sebelum CR-181) | ditolak (jumlah) |
| `=SUM(GPP!J2:J1048576)` (1) | lewat batas per sel | | (313 detik lalu fatal memori sebelum CR-181) | ditolak < 0,1 detik |

Dasar ukur suku kuadratik (satu pemanggilan, nilai terkecil dari tiga putaran, satu kolom 2.500 / 5.000 / 10.000 baris):
`SUM` 24 / 63 / 179 ms; `MAX` 70 / 217 / 797 ms (`MEDIAN`, `STDEV`, `MATCH`, `VLOOKUP`, `CONCAT` serupa); `COUNTIF` 154 /
402 / 1.058 ms; `COUNTIFS` dua rentang 408 / 948 / 2.454 ms. Bagian linear ±7 µs per sel untuk semuanya (±55 µs untuk
fungsi berkriteria); yang berbeda adalah suku kuadratik menurut jumlah baris.

Alasan angka batas: pada 3.000.000 satuan, hitung rumus ±9–22 detik di mesin ukur, sehingga muat templat penuh (±8–13
detik) + taksir + hitung ±30–40 detik, di bawah batas request 60 detik (README-deploy §1). Batas per sel 400.000 menahan
memori penjabaran satu rentang (±0,55 KB per baris rentang satu kolom, ±0,15 KB per sel rentang lebar) di bawah ±75 MB,
masih di dalam `memory_limit` 512M bersama puncak muat, dan cukup untuk satu `VLOOKUP` ke seluruh GPP (±371 ribu) atau
satu `COUNTIF` atas 10.000 baris (±211 ribu). Biaya taksiran sendiri: ±0,3–0,5 detik untuk menyisir sel templat penuh
tanpa rumus, ditambah ±35–130 mikrodetik per sel rumus yang perlu diurai (rumus tanpa `:`, nama, `REPT`, atau fungsi
dinamis tidak diurai). Ujung ke ujung lewat `PembacaExcelSlip` (uji opt-in `RUN_VOLUME_TEST=1`,
`testTemplatPenuhDenganKolomRumusDiBawahAnggaran`): templat penuh 10.000 baris + 20.000 sel rumus `SUM` (beban ±1,3 juta)
dibaca 13,9–19,3 detik (dua kali ukur, yang kedua saat mesin dipakai proses lain), puncak memori PHP +245 MB.

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

### Uang makan slip 5B (D-09a, MAKE-040)

Port `Lsl_gaji::getUangMakan` + `_getTarifUangMakan` legacy `0cf7e67` (`libraries/hr/Lsl_gaji.php:260-414`) [K], PRD 5B
§7.2. Aturan snapshot (kapan ditulis/dipertahankan) tetap `SlipGajiService::snapshotUangMakan` 5A, tidak diubah.

| Bagian | Kelas | Aturan |
|---|---|---|
| Hari dibayar (murni) | `Rekap/HariUangMakan` (folder murni baru `Presensi/Rekap`, dijaga `RekapMurniTest`) | Per tanggal bulan kalender kehadiran, dipotong hari ini WIB: libur/Sabtu/Minggu → lewati; tanggal TB → lewati, dan bila potongan TB bulan itu > 0 penanda TB melekat sampai akhir bulan (F5B-3; potongan 0 bila TB mulai setelah tanggal 1 di bulan itu, perpanjangan memakai `tgl_mulai` induk); cuti status 1 → lewati; konket `affect_tukin = 1` dibayar hanya `kategori = 13`; selain itu dibayar bila ada jam masuk **atau** jam pulang. Dipakai ulang rekap uang makan D-09b (F5B-2) |
| Tarif | `Slip/TarifUangMakan` | PPPK (`id_jenis_pegawai` 6, F5-24): mutasi jabatan `status = 1`, `jenis_jabatan = 1`, `tmtsk` ≤ tanggal 1 terbaru (seri → id terbesar [V2]) → `gol_pppk.uang_makan`; lainnya: KP `status = 1` `tmtsk` ≤ tanggal 1 terbaru → `trim(gol)` ∈ I..IV → `web_config` `uang_makan_gol_1..4` (dibaca langsung lewat `WebConfigService`, ubah nilai langsung berlaku); golongan lain ("IV/a"), tanpa baris, tarif kosong, PTT → 0. Tarif dikembalikan sebagai rupiah desimal apa adanya; total = `totalSen()` (tarif × hari dibulatkan setengah-menjauhi-nol ke sen, desimal eksak) |
| Sumber | `Slip/UangMakanSlipPresensi` (`Config\Presensi::$sumberUangMakan`) | Pegawai tidak ada → `null`; tarif ≤ 0 → `(0, 0, 0)` tanpa menghitung hari; selain itu `totalSen(tarif, hari)` (`tarifSen` = tarif dibulatkan ke sen, hanya informasi). Pemuat `hariDibayarBanyak()` = 5 SELECT tetap per 500 NIP (`riwayat_cuti`, `riwayat_tb` + `riwayat_tb_perpan`, `absen_ijin` status `V`, `dh_online`) + libur aktif |

**[CATAT] Q5B-24 — tarif `gol_pppk.uang_makan` tidak bulat sampai sen (keputusan Horii 28-10-2026)**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| Ikut legacy: total = tarif × hari apa adanya, lalu dibulatkan **eksplisit** setengah-menjauhi-nol ke 2 desimal (sama dengan konversi ke kolom `gaji_pegawai.uang_makan` DECIMAL(14,2), DBV-023); hitungan desimal eksak dari string (`TarifUangMakan::totalSen`) | `(float) $gp['uang_makan']` (`Lsl_gaji.php:404`), `$result['total'] = $hari * $tarif` float (`:381`), ditulis ke `gaji_pegawai.uang_makan` (`:193`); pembulatan 2 desimal terjadi **implisit** di kolom DB | v2 deterministik. Pada tarif tepat setengah sen (`.xx5`) hasil legacy bisa selisih 1 sen dari v2 karena galat representasi float — gap diterima (test mengikuti hitungan eksak v2). Usulan awal M2 (galat → sumber `null`) **DITOLAK** Horii; tarif lebih dari 2 desimal tetap dicatat log warning tanpa nominal sebagai jejak data master |

Test: `tests/unit/Presensi/Rekap/{HariUangMakan,RekapMurni}Test.php`, `tests/Presensi/Slip/{TarifUangMakan,
UangMakanSlipPresensi,UangMakanSnapshotAsli}Test.php` (AC-D09a.1–09a.5). Test 5A yang menguji perilaku
`UangMakanBelumTersedia` (`UangMakanSnapshotTest`, `BukaSlipAtomikTest`) kini menyuntik sumber itu secara eksplisit.

### Laporan Tukin 5B (D-08, MAKE-041)

Port `list_lt`/`list_lt_il`, `laporan_tukin/{nip}` (`laporan_tukin_skp`), `laporan_tukin_us_skp`/`_il` legacy `0cf7e67`
[K] (`Presensi.php:76-635`, `L_presensi.php:3535-3627`, `:6510-7752`, `:11752-15450`).

| Bagian | Kelas | Aturan |
|---|---|---|
| Kalkulasi | `Laporan/KalkulasiLaporanTukin` | Satu kalkulasi rekap untuk layar per pegawai & rekap (K-1); `TukinDataService::siapkanBanyak` + `KalkulasiTukin::hitung` dipotong hari ini; galat per pegawai ber-`code`; data keterangan per batch |
| Penyesuaian (murni) | `Rekap/PenyesuaianTukin` | Baris status 1 yang mencakup bulan laporan; keterangan "Penyesuaian Tukin <alasan> <persen>% (sebelum potongan lain)." + TB/CLTN + daftar cuti; akhiran total PDF; `total_potongan` tidak diubah |
| Penanda tangan | `Rekap/PenandaTangan` (murni), `Laporan/PejabatPenandaTangan` | Enam kasus selalu dicetak (F5B-13); Plt status 1 tanggal cetak, tanpa Plh, deterministik (F2); konstanta unit F3 di `Config\Presensi` |
| Rekap & ekspor | `Laporan/RekapTukinService`, `Laporan/EksporTukinXlsx`, view `pdf/rekap_tukin` | Populasi periode Tukin; XLSX tata letak legacy; PDF berbatas (F5B-16); IL role 1 |
| Per pegawai | `Laporan/LaporanTukinService`, view `pdf/laporan_tukin_pegawai` | Akses F5B-7; PDF render legacy F5B-8 ("Rp." hanya konket kategori 13 yang dibayar aturan slip per bulan kalender) |
| Daftar | `Laporan/DaftarPegawaiTukinService` | Snapshot jabatan, paginasi server, `bulan_tersedia` |

**[CATAT] MAKE-041 — laporan Tukin v2 vs legacy (butir di luar F5B-n yang sudah ada di PRD)**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| Per pegawai, pegawai dibuat pada hari terakhir periode (T-01): angka & tampilan ikut rekap — semua hari kerja TK, tanpa jam/konket/keterangan cuti (CR-083) | `laporan_tukin_skp` tanpa saringan `created_at`; rekap `:12209`, `:12283`, `:12346`, `:12383` | Tampilan konsisten dengan angka rekap |
| Per pegawai, pegawai baru tanpa TMT (`lewatiLoopHarian`): semua tanggal `di_luar_masa_kerja` tanpa jam/konket (CR-083) | `laporan_tukin_skp` tanpa blok pegawai baru; rekap `:12703-12710`, `:12739-12743` | Tampilan konsisten dengan angka rekap |
| Per pegawai, keterangan cuti tidak dicetak untuk T-01 / `lewatiLoopHarian` (CR-083) | Rekap membangun `arrKetCuti` di dalam loop dari cuti yang dimuat | Keterangan konsisten dengan rekap |
| Layar per pegawai memakai kalkulasi rekap (K-1) | `laporan_tukin_skp` per pegawai berbeda 4 aturan dari rekap (`L_presensi.php:6510-7752` vs `:11752-13492`) dan punya aturan TMT CPNS sendiri (`:7054-7078`, `:7302-7310`) | Angka layar = baris rekap **DH**; nilai SKP sebelum batas penilaian mengikuti rekap. Pegawai IL: layar bisa berbeda dari baris rekap IL (rekap IL tanpa aturan pegawai baru, CR-083) |
| Rekap role 3 dipaksa satker akun di server | Rekap hanya dibatasi di JS (`list_lt.php:262-264`), controller tanpa cek (`Presensi.php:491`) | Celah L5B-02 ditutup |
| Pegawai galat tetap tampil dengan `galat` | Satu galat menghentikan halaman | Rekap tidak gagal total |
| XLSX: ekstensi `.xlsx`, content type xlsx, judul "TOTAL POTONGAN", legenda "Terlambat" | `.xls.xlsx`, `application/vnd.ms-excel`, "POTONGGAN", "Telambat" (`:13306-13483`) | Salah ketik & nama berkas diperbaiki |
| Penanda tangan: jabatan dari `struktur_jabatan` periode aktif, urut `urutan`/id; definitif seri → NIP naik | `row_array` tanpa urutan, tanpa saring periode (`:12018-12140`) | Pilihan deterministik |
| Kop PDF per pegawai **ikut legacy berlogo** (keputusan Horii 28-10-2026, Q5B-27): tata letak `MY_PDF_HEADER_UNIT`, logo `web_config` `logo_kementerian_pdf` / `logo_wonderful_pdf` (unit 6: `satker.logo_uns`) dari akar aset `Config\Presensi::$akarAset`, nama kementerian & unit huruf besar; alamat & logo kanan ikut cabang legacy (unit ∉ {6, 7} ber-alamat unit → alamat unit; unit 6 + alamat satker → alamat + logo satker; selain itu alamat kementerian + logo wonderful) — `Laporan\KopLaporanTukin` | `MY_PDF_HEADER_UNIT` (`config/constants.php:257`) diisi `L_presensi.php:6512-6521` + `:7105-7116` (unit/satker 6/7 hard-code) | Usulan awal M5 (kop `KopDokumen` tanpa logo) **DITOLAK**. Id 6/7 lewat konstanta F3; G1/G2 ikut legacy (keputusan Horii 29-10-2026); [V2] logo satker tidak sah → logo wonderful |
| Nama berkas PDF per pegawai tanpa angka acak | `tukin_<nip>_<bulan>_<rand>.pdf` (`:7726-7728`) | Nama stabil |
| Bawaan `jenis_pegawai[]`: rekap `[1]` (PNS), daftar pegawai semua jenis | Rekap `jenis_pegawai` bawaan `'1'` (`hr/Presensi.php:645`, `:744`, `:853`, `:952`); daftar bawaan `'0'` = semua (`:411`, `:494`) — diverifikasi reviewer CR 28-10-2026 | Sama dengan legacy |
| `penyesuaian` dari tabel DBV-024 (persen bebas ±100) | NIP & periode hard-code (`:11755-11806`, `:6526-6560`) | Lihat [CATAT] F5B-14 PRD |

Test: `tests/unit/Presensi/Rekap/{PenandaTangan,PenyesuaianTukin}Test.php`, `tests/Presensi/Laporan/{RekapTukin,
LaporanTukinPegawai, RekapTukinXlsx, RekapTukinPdf, RekapTukinIl, PenandaTanganRekap, PenyesuaianTukinEfek,
DaftarPegawaiTukin, CakupanLaporan, RoleMatriksLaporan, RekapTukinVolume, KopLaporanTukin,
RoutePresensiLaporanKontrak}Test.php`.

**Uji paritas UP-1 (5B M7 MAKE-043):** `Laporan\ParitasRekapTukin` + `php spark presensi:paritas-tukin <legacy.xlsx>
<v2.xlsx> [--toleransi=0.01] [--csv=<path>] [--nip-penuh]` membandingkan XLSX rekap Tukin legacy (`laporan_tukin_us_skp`)
dengan `GET presensi/tukin/rekap/xlsx` per NIP (kolom TL..TB & total; keterangan tidak dibandingkan), hanya membaca dua
berkas, NIP tersamar bawaan. Prosedur lengkap: `backend/docs/progress/05-Presensi.md` "Prosedur paritas UP-1". Test
`tests/unit/Presensi/Laporan/ParitasRekapTukinTest.php`.

### Rekap kehadiran & uang makan 5B (D-09b, MAKE-042)

Port `Presensi::rekap` / `rekap_uma` / `rekap_uma_il` + `L_presensi::rekap` (`:415-810`), `rekap_full_xls`
(`:824-1436`), `rekap_uma` (`:2694-3212`) dan populasi `L_employee::get_all_pegawai_dh_spe`/`_il_spe` (`:6398-6775`)
legacy `0cf7e67` [K].

| Bagian | Kelas | Aturan |
|---|---|---|
| Klasifikasi (murni) | `Rekap/KlasifikasiKehadiran`, `Rekap/JadwalKerjaRekap` | Ringkas (ikut legacy, TB tidak diperiksa): cuti → konket (8 DL, 9 CT, 10 SKT, 13 LL = hadir, lain = tidak dihitung) → TK → hadir dengan TL (`> 60`, `30 < tl ≤ 60` & `psw > −60`, `0 < tl ≤ 30` & `psw > −30`; hanya masuk: `tl > 0`) dan PSW (`psw > 0`); `tidak_hadir` = cuti + DL + sakit + TK; kolom `tb` informasi. Grid harian: TB dulu, lalu sama; `weekend`/`libur` |
| Populasi | `Laporan/PopulasiRekap` | DH: status 1 atau (2 & `tmt_status >` awal), `DATE(created_at) ≤` akhir, jenis pegawai; jabatan berlaku `riwayat_mutasi_jabatan` jenis 1 `tmtsk ≤` akhir; pangkat `riwayat_kp` `tmtsk ≤` awal; urut kelas ↓, gol ↓, TMT pangkat ↑, satker ↑, NIP ↑; PPPK (jenis 6) yang punya jabatan memakai `gol_pppk` jabatan sebagai gol (kosong tetap kosong, tidak jatuh ke `riwayat_kp`), khusus DH juga `tmtsk` jabatan sebagai TMT (CR-178). IL: ⋈ `pegawai_mutasi_jabatan` jenis 2, `created_at ≤` awal |
| Lingkup | `Laporan/CakupanLaporan`, `Laporan/LingkupLaporan` | `unit_satker` `0`/unit/`sat_<id>`; role 3 dipaksa satker akun (unit bila tanpa satker, 403 bila keduanya kosong) — dipakai juga laporan Tukin M5 |
| Data | `Laporan/DataRekapBulan` | 3 SELECT per batch 500 NIP (cuti status 1, konket `V` semua `affect_tukin`, `dh_online`) + TB Fase 4 massal + libur aktif; presensi hanya dibaca untuk pegawai yang sudah ada sebelum bulan itu (Q5B-26) |
| Uang makan | `Laporan/RekapUangMakanService` | `UangMakanSlipPresensi::hariDibayarBanyak` (aturan slip F5B-2) → baris `{nip, tgl_absen}` |
| Ekspor | `Laporan/EksporRekapXlsx` | PhpSpreadsheet; NIP & tanggal teks eksplisit |

**[CATAT] MAKE-042 — rekap kehadiran & uang makan v2 vs legacy**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| **Ringkas ikut legacy (keputusan Horii 28-10-2026, Q5B-25):** hari TB tidak diperiksa di ringkas — tetap melalui cuti → konket → presensi (praktis TK + tidak hadir bila tanpa presensi, hadir bila ada presensi); kolom `tb` hanya informasi jumlah hari TB. Grid harian memeriksa TB lebih dulu → kode `TB` | Layar `rekap` tidak mengenal TB (`L_presensi.php:642-810`) sehingga hari TB tanpa presensi = TK + tidak hadir; ekspor harian `rekap_full_xls` memeriksa TB lebih dulu (`:1087-1099`) | Usulan v2 awal (hari TB hanya di kolom `tb`, tidak masuk TK/`tidak_hadir`) **DITOLAK**. Dampak: pegawai TB tetap tampil TK & tidak hadir pada hari TB-nya (angka sama dengan legacy); kolom `tb` [V2] tambahan tidak mengurangi kolom lain |
| Jam kerja & puasa dari `web_config` F5-16 + `rentang_puasa` | Jam & Ramadan 2026 di-hard-code (`:614-622`, `:679-684`) | Sama selama nilai `web_config` sama; tahun lain ikut konfigurasi |
| **Ikut legacy (keputusan Horii 28-10-2026, Q5B-26):** presensi hanya dibaca bila pegawai sudah ada sebelum bulan itu — ringkas JSON/XLSX `DATE(peg.created_at) <= awal bulan` (`L_presensi.php:599`), grid harian `peg.created_at < awal bulan` (`:1053`); populasi tetap `DATE(created_at) ≤` akhir bulan | Sama: populasi `≤` akhir bulan (`L_employee.php:6412-6416`), kueri presensi `rekap` `:599` dan `rekap_full_xls` `:1053` (operator berbeda per fungsi) | Usulan v2 awal (presensi pegawai baru dihitung, pola F5B-2) **DITOLAK**. Dampak: pegawai yang dibuat setelah tanggal 1 tampil TK seluruh hari kerja di rekap kehadiran; pegawai dibuat tanggal 1 di atas jam 00:00 dibaca di ringkas tetapi tidak di grid harian. Rekap uang makan **tetap** F5B-2 (MAKE-040, di `main`) sehingga untuk pegawai baru kedua rekap berbeda |
| Hari libur = `hari_libur` status 1 (`HariLiburService`) | `hari_libur` tanpa saring status (`:461-473`) | Libur yang dinonaktifkan tidak lagi dihitung libur |
| XLSX dibangun server: ringkas (judul + 15 kolom), harian `.xlsx`, uang makan 2 kolom | Ringkas & uang makan: ekspor DataTables di browser; harian `.xls` Excel 97 bernama `laporan_tukin_<jenis>_<unit_satker>_<n-Y>.xls`, lalu HTML ikut terlampir di berkas (bug `:1431-1435`) | Format berkas modern, nama berkas `rekap_*`; bug lampiran HTML tidak di-port |
| Bawaan `unit_satker = 0`; role 3 tanpa satker → unit akun | Bawaan `sat_47` hard-code untuk role 1/4; role 3 tanpa satker bawaan satker pertama unitnya dan boleh memilih satker lain di unit itu (`Presensi.php:747-775`) | Lingkup role 3 lebih ketat (PRD 5B §7.3.6) |
| Bulan boleh sampai bulan berjalan (dipotong hari ini) | Daftar bulan 2020-07 s.d. bulan lalu, bawaan bulan berjalan (`Presensi.php:786-807`) | Bulan berjalan dapat dipilih eksplisit |
| Urutan populasi [K] + NIP naik sebagai penentu akhir; jabatan seri `tmtsk` → id terbesar | `array_multisort` tanpa penentu akhir (`L_employee.php:6607-6611`) | Urutan stabil untuk paginasi |
| Rekap uang makan IL memakai aturan slip (konket `affect_tukin = 1`, kategori 13 dibayar) | `rekap_uma_il` tanpa saring `affect_tukin` (`:3115`) | Sama dengan varian DH (F5B-2) |

Test: `tests/unit/Presensi/Rekap/KlasifikasiKehadiranTest.php`, `tests/Presensi/Rekap/{RekapKehadiran, RekapUangMakan,
RoleMatriksRekap}Test.php` (XLSX ringkas, harian, dan uang makan dibaca ulang di dua test pertama).

### Test

`tests/unit/Presensi/SlipGaji/` (tanpa DB; konvensi `tests/unit` untuk kelas murni, sama dengan CR-025):

```bash
cd backend && vendor/bin/phpunit --no-coverage tests/unit/Presensi
```
