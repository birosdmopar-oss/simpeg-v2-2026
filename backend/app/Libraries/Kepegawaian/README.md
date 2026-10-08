# Modul B — Kepegawaian Core (Fase 3)

Service/business logic modul ini (`App\Libraries\Kepegawaian\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

## Kalkulasi murni (`Kalkulasi/`, CR-025 + CR-036, B-21)

Aturan tanggal Kepegawaian yang **tidak butuh tabel baru**: periode KP, jarak KGB, akhir hukuman disiplin, dan masa kerja golongan (CR-025), ditambah BUP dan TMT pensiun (CR-036). Semua kelas `final` dengan method statis, **tanpa DB, HTTP, session, atau jam**, kecuali `TanggalBisnis::hariIni()` (K-CR025-1; dijaga `KalkulasiMurniTest`). Tanpa migration dan tanpa perubahan FE. Service ber-DB (B-08/B-09/B-14/B-19) memanggil kelas ini lalu memetakan hasil gagal ke 422.

Label sumber: **[K]** = kode legacy (path relatif ke `application/` legacy), **[V2]** = aturan baru dari dokumen v2 (SRS/FSD/MTC), yang tidak ada di legacy.

| Kelas | Isi |
|---|---|
| `TanggalBisnis` | `valid`, `periksa` (input pengguna → hasil gagal), `wajibValid` (data sistem → exception), `hariIni` (WIB), `tambahBulan`/`tambahTahun` (dijepit akhir bulan), `selisihBulanPenuh`, `awalBulan`, `formatIndonesia` ("1 April 2027") |
| `HasilKalkulasi` | `valid`, `tanggal`, `kode`, `pesan`, `detail`; `lemparBilaGagal($field)` → `ValidationException` 422 `errors[$field]` |
| `KenaikanPangkat` | `BULAN_PERIODE = [4, 10]`, `isTanggalPeriode`, `periodeBerikutnya`/`periodeSebelumnya`, `validasiTmt`, `proyeksiReguler`, `proyeksiPercepatan` |
| `JenisKp` | ID legacy `jenis_kp` 1–5, `wajibTmtPeriode`, `masaRegulerBerikutnya` |
| `KenaikanGajiBerkala` | `referensiTerakhir`, `validasiJarak`, `jatuhTempo`, `proyeksiBerikutnya` |
| `JenisReferensiTmt`, `ReferensiTmt` | acuan jarak KGB (KGB > KP > CPNS > awal PPPK bila TMT sama) |
| `HukumanDisiplin` | `masaBerlaku`, `hitungTanggalBerakhir`, `validasiTanggalBerakhir`, `masaTeks` |
| `MasaKerja` | `selisih`, `tambah` (MKG), `tmtCpnsDariNip`, `format` ("02 Tahun 05 Bulan") |
| `Pensiun` (CR-036) | `batasUsia` (BUP 58/60/`umur_pensiun` jabatan), `tanggalPensiun` (tanggal 1 bulan sesudah ulang tahun ke-BUP) |

### Aturan tanggal bisnis dan zona waktu (wajib untuk B-08/B-09/B-14/B-19)

1. TMT, tanggal SK, dan akhir hukdis adalah **tanggal kalender tanpa zona**: DB `DATE` ↔ PHP `string` `Y-m-d` ↔ JSON `"2026-04-01"` ↔ FE string. Jangan dikonversi ke stempel UTC atau timestamp.
2. "Hari ini" hanya lewat `TanggalBisnis::hariIni()`, yang memakai zona **Asia/Jakarta** [K] (`config/config.php:4`). v2 memakai `appTimezone = 'UTC'`, dan antara 00:00–06:59 WIB tanggal UTC masih kemarin, sehingga `Time::now()` atau `date('Y-m-d')` keliru satu hari. Zonasi per satker (G-02) hanya untuk presensi Fase 5, tidak untuk tanggal SK.
3. FE (B-19): jangan mem-parse tanggal bisnis dengan `new Date('YYYY-MM-DD')` (dibaca sebagai UTC tengah malam); tampilkan dengan memecah string. Helper stempel UTC CR-023 hanya untuk kolom audit/stempel waktu.
4. Penambahan bulan/tahun **dijepit ke akhir bulan**, sama dengan MySQL `DATE_ADD` (`2024-02-29` + 2 tahun = `2026-02-28`), bukan `DateTime::modify` legacy yang meluap (`2026-01-31` + 1 bulan = `2026-03-03`). Siklus berulang selalu dihitung dari tanggal acuan asli karena penambahan ini tidak asosiatif. Input divalidasi tahun 1900–2100 (`HariLiburRules::isValidDate`); hasil boleh melewati 2100.

### Aturan dan sumbernya

| Aturan | Legacy [K] | v2 [V2] | Implementasi |
|---|---|---|---|
| TMT KP 1 April/1 Oktober | Tidak divalidasi (`libraries/hr/rwy/L_kp.php:422-536`); periode hanya untuk proyeksi `next_kp` (`libraries/hr/L_employee.php:1987-1998`) | SRS-FR-025: tanggal lain ditolak | `validasiTmt($tmt, JenisKp::wajibTmtPeriode($id))`; pesan memuat periode terdekat |
| Proyeksi KP reguler | `next_kp`: masa 3 th setelah Pengangkatan PNS, selain itu 4 th (:1987); hanya **bulan** TMT dipakai (:1988-1998) | — | `proyeksiReguler` setara legacy (TMT 15-04-2022 → 01-04-2026) |
| Proyeksi KP pilihan/percepatan | TMT jabatan struktural + 1 th, periode berdasarkan tanggal (:2005-2041) | — | `proyeksiPercepatan`; kelayakan (golongan/eselon) diputuskan di H-08/B-19 |
| Jarak KGB ≥ 2 tahun | Tidak divalidasi (`libraries/hr/rwy/L_kgb.php:429-506`); legacy mengakui KGB berjarak 1 tahun (`L_employee.php:10408-10419`) | SRS-FR-026: dari TMT KGB/KP terakhir | `validasiJarak($tmt, list<ReferensiTmt>)`, acuan = pendahulu terakhir (TMT ≤ TMT baru); TMT sama → ditolak |
| Proyeksi KGB | `next_kgb` (`L_employee.php:4023-4045`): < 24 bl → acuan + 2 th; 24–36 bl → hari ini; > 36 bl → acuan + 4 th lalu + 2 th sampai ≥ sekarang. Acuan = KGB terakhir, diganti TMT CPNS (PNS) atau TMT jabatan (PPPK) bila lebih baru (:3984-4020); **KP tidak dipakai** | — | `proyeksiBerikutnya($acuan, TanggalBisnis::hariIni())`, `detail.status` ∈ `belum_jatuh_tempo`/`jatuh_tempo`/`siklus_terlewat` |
| Akhir hukdis | Manual: `masa_hukuman` teks + `akhir_hukdis` wajib diisi (`libraries/hr/rwy/L_hukdis.php:400-407`); SIASN mengisi dari SK (`controllers/hr/services/Siasn.php:2940-2946`) | SRS-FR-027 + MTC-021: TMT + masa sanksi; K5b: masa SK diutamakan, lalu `jenis_hukdis.masa_sanksi_bulan` | `hitungTanggalBerakhir($tmt, $masaSk, $masaBawaan)`; keduanya NULL → `tanpa_masa` (akhir manual, `validasiTanggalBerakhir`: harus > TMT) |
| Masa kerja golongan | Kalender murni: MKG lama + bulan penuh TMT lama → baru (`L_employee.php:2673-2698`); tanpa MKG lama dari NIP digit 9–14 | — | `MasaKerja::tambah`, `tmtCpnsDariNip`, `format` (`helpers/function_helper.php:270-275`) |
| BUP dan TMT pensiun (CR-036) | `getTanggalPensiun` (`helpers/function_helper.php:2400-2418`): `jabatan.umur_pensiun` > 0, selain itu Struktural Eselon I/II 60, selain itu 58; hasil = lahir + BUP tahun + 1 bulan, format `Y-m-01`. Hanya PNS (`L_employee.php:1226-1273`, dashboard `L_user.php:705-720`); PPPK memakai `mhpk_akhir` (:2207-2225) | SRS-FR-033, FSD-FN-H-08: proyeksi usia 58/60/65 sesuai jenis jabatan | `Pensiun::batasUsia`, `tanggalPensiun` (dari awal bulan lahir) |

**Deviasi terdokumentasi dari legacy** (K-CR025-3, K-CR025-8):
- Selisih bulan penuh konsisten dengan penjepitan (`2024-02-29` → `2026-02-28` = 24 bulan; `DateTime::diff` legacy = 23). Berbeda hanya bila hari awal 29–31; TMT legacy praktis selalu tanggal 1.
- Proyeksi KGB tanpa jam: bila acuan + 4 tahun = hari ini, hasilnya hari ini (legacy meloncat 2 tahun karena membandingkan dengan jam). Acuan di masa depan → acuan + 2 tahun dengan masa 0/0 (legacy memakai selisih absolut).
- TMT pensiun (CR-036, K-CR036-6): legacy meluap untuk tanggal lahir 29–31 dan terlambat satu bulan (31-01-1968 + 58 → legacy 01-03-2026, v2 01-02-2026; 29-02-1968 → legacy 01-04-2026, v2 01-03-2026; 31-03-1968 → legacy 01-05-2026, v2 01-04-2026). Untuk tanggal lahir 1–28 hasilnya identik (diuji 1950–1975 × BUP 58/60/65).

### Dua jenis kesalahan (K-CR025-4)

- **Input pengguna salah** → `HasilKalkulasi::gagal(kode, pesan)`; service memanggil `->lemparBilaGagal('tmtsk')` → 422. Kode stabil: `tanggal_wajib`, `tanggal_tidak_valid`, `tmt_kp_bukan_periode`, `jarak_kgb_kurang_dari_2_tahun`, `masa_sanksi_tidak_valid`, `akhir_tidak_setelah_tmt`, plus `tanpa_masa` (hasil valid, informasi).
- **Data sistem salah** (TMT riwayat dari DB seperti `0000-00-00`, parameter "hari ini", masa ≤ 0 pada fungsi hitung) → `InvalidArgumentException` (fail loud).

### Keputusan rancangan CR-025 (pra-review internal sisi CR)

| # | Keputusan |
|---|---|
| K-CR025-1 | Murni tanpa DB/HTTP/session/jam kecuali `hariIni()`; tanpa migration dan FE |
| K-CR025-2 | Tanggal bisnis = string `Y-m-d`; validasi format didelegasikan ke `HariLiburRules::isValidDate` |
| K-CR025-3 | Penambahan dijepit akhir bulan (= MySQL `DATE_ADD`); `selisihBulanPenuh` konsisten dengannya |
| K-CR025-4 | Input pengguna → `HasilKalkulasi` 422; data sistem → `InvalidArgumentException` |
| K-CR025-5 | `BULAN_PERIODE` satu konstanta; kewajiban periode per jenis KP lewat `JenisKp::wajibTmtPeriode` |
| K-CR025-6 | Proyeksi KP reguler setia legacy `next_kp` |
| K-CR025-7 | Acuan jarak KGB = pendahulu terakhir (TMT ≤ TMT baru), seri → KGB > KP > CPNS > awal PPPK; isi daftar acuan ditentukan pemanggil (baris yang diedit dikeluarkan; status `NOT IN (2, 10)` seperti cek TMT kembar legacy) |
| K-CR025-8 | Proyeksi KGB setia legacy dengan dua deviasi di atas |
| K-CR025-9 | Masa hukdis = masa SK ?? masa bawaan jenis; akhir = TMT + masa (literal SRS-FR-027); masa 1–255 (CHECK `chk_jenis_hukdis_masa_sanksi_bulan`, TINYINT UNSIGNED) |
| K-CR025-10 | Validasi KP/KGB hanya untuk input pengguna (create, atau update yang mengubah `tmtsk`); impor legacy dan sinkron SIASN tidak melewatinya |
| K-CR025-11 | "Hari ini" = Asia/Jakarta; hanya `TanggalBisnis::hariIni()` yang membaca jam |
| K-CR025-12 | Hari kerja (Senin–Jumat dikurangi `hari_libur`, legacy `helpers/function_helper.php:2175-2213`) ditunda ke Fase 5; signature rencana `HariKerja::hitung(string $mulai, string $akhir, list<string> $tanggalLibur): int` dengan `HariLiburService::tanggalLibur()` |

### Pertanyaan terbuka (keputusan user/SME; kode mengikuti usulan sampai diputuskan)

1. **Validasi [V2] tanpa padanan legacy.** SRS-FR-025/026 menolak TMT yang di legacy selalu diterima. Usulan: terapkan untuk input pengguna saja (K-CR025-10).
2. **Jenis KP tanpa kewajiban periode.** Usulan: Pengangkatan CPNS (1) dan PNS (2) bebas tanggal; Reguler (3), Pilihan (4), Penyesuaian Ijazah (5), dan id lain wajib 1 April/1 Oktober. Perlu konfirmasi untuk 5 dan 6.
3. **TMT KP sebagai acuan jarak KGB.** SRS-FR-026 menyebut "KGB/KP terakhir", tetapi legacy tidak memakai KP dan mengakui KGB berjarak 1 tahun (MKG ganjil setelah KP/PMK). Kalkulasi mendukung keduanya; pemanggil B-09 yang menentukan isi daftar acuan. Usulan: ikut SRS, dengan konfirmasi SME karena bisa menolak KGB sah 1 tahun setelah KP.
4. **Akhir hukdis = TMT + masa atau TMT + masa − 1 hari.** Usulan: literal SRS-FR-027/MTC-021 (1 Januari 2026 + 12 bulan = 1 Januari 2027).
5. **Impor/sinkron SIASN tidak divalidasi.** Data lama memuat TMT KP non-periode dan KGB berjarak 1 tahun. Usulan: ya, validasi hanya jalur input pengguna.

### Integrasi berikutnya (bukan bagian CR-025)

- **B-08 KP** (create, atau update yang mengubah `tmtsk`): `KenaikanPangkat::validasiTmt($tmt, JenisKp::wajibTmtPeriode($idJenisKp))->lemparBilaGagal('tmtsk')`. Cek TMT kembar dan keunikan pengangkatan CPNS/PNS (legacy `L_kp.php:471-492`) tetap di service ber-DB.
- **B-09 KGB**: bangun `list<ReferensiTmt>` dari `riwayat_kgb`/`riwayat_kp` (`status NOT IN (2, 10)`, tanpa baris yang diedit) + TMT CPNS atau awal PPPK sesuai jawaban #3, lalu `KenaikanGajiBerkala::validasiJarak(...)->lemparBilaGagal('tmtsk')`.
- **B-14 hukdis**: `akhir_hukdis` kosong → `HukumanDisiplin::hitungTanggalBerakhir($tmt, $masaSk, $jenis->masa_sanksi_bulan)`; hasil `tanpa_masa` → akhir wajib manual atau boleh NULL (keputusan B-14/B-02); diisi manual → `validasiTanggalBerakhir`. Kolom masa numerik per SK di `riwayat_hukdis` (K5b) adalah keputusan skema B-02 (DB Validator).
- **B-19/H-08**: `proyeksiReguler`, `proyeksiPercepatan`, `proyeksiBerikutnya` untuk dashboard/notifikasi. Syarat kelayakan KP (maks. golongan per pendidikan/eselon, `L_employee.php:1888-1906`) dan aturan cron kelipatan 4 tahun `get_all_kp` (:2560-2568) diputuskan di H-08.
- ID `jenis_kp` 4 = "Pilihan" (`L_employee.php:2647`) belum tercatat di G-04 6.4; dicatat di dokumen B-08/B-01.
- **Pensiun (CR-036)**: hanya untuk PNS (`id_jenis_pegawai = 1`). `Pensiun::tanggalPensiun($tglLahir, Pensiun::batasUsia($jabatan->umur_pensiun, $pmj->id_group_jabatan, $pmj->id_sub_group_jabatan))`, dengan jabatan dari snapshot `pegawai_mutasi_jabatan`. Sisa masa sampai pensiun: `MasaKerja::selisih(TanggalBisnis::hariIni(), $tmtPensiun)`. Dipakai prediksi dashboard (B-19/B-20), cron `rekomendasi_pensiun` (H-08), dan daftar pensiun (F-09). Pegawai tanpa jabatan aktif tidak diberi tanggal pensiun, sama dengan legacy `get_pensiun`.

### Test

`tests/unit/Kepegawaian/Kalkulasi/` dan `tests/unit/Kepegawaian/Nip/` berjalan tanpa DB, sesuai konvensi `tests/unit` untuk kelas murni. Test ber-DB B-21, seperti cascade NIP dan snapshot sync, ada di `tests/Kepegawaian/`. `KalkulasiMurniTest` menjaga kedua folder murni.

```bash
cd backend && vendor/bin/phpunit --no-coverage tests/unit/Kepegawaian
```

## Registry kolom NIP dan format NIP (`Nip/`, CR-036, B-06)

Bahan murni untuk koreksi NIP (B-06), disiapkan lebih awal sebagai pengecualian urutan fase yang disetujui user pada 01-10-2026. Tidak ada migration, endpoint, atau FE. PK `pegawai` adalah `nip` VARCHAR(30) (K1), dan FK v2 memakai `ON UPDATE RESTRICT`. Karena itu B-06 (`NipCascadeService`) menjalankan koreksi dalam **satu transaksi**: salin baris `pegawai` ke NIP baru, arahkan ulang setiap kolom di registry, lalu hapus baris lama. Legacy cukup menjalankan `UPDATE pegawai SET nip`, karena FK-nya `ON UPDATE CASCADE` (`libraries/hr/L_employee.php:586-587`).

Label sumber registry: **[K]** = DDL dump struktur produksi 01-10-2026 (D1, 285 tabel), **[K-kode]** = kode legacy (path relatif ke `application/`), **[V2]** = dokumen v2. Di bagian Kalkulasi di atas, [K] berarti kode legacy.

| Kelas | Isi |
|---|---|
| `TabelAnakNip` | Registry statis 79 entri. Method: `semua`, `perFase(n)`, `sampaiFase(n)` (cakupan B-06 setelah fase n selesai), `perJenis`, `perKeberadaan` |
| `RujukanNip` | Satu entri: `tabel`, `kolom` (nama legacy), `kolomDiV2()`, `jenis`, `sumber`, `namaFkLegacy`, `nullable` (legacy), `keberadaan`, `fase`, `pemilik` (task migration), `catatan`, `kunci()` |
| `JenisRujukanNip` | `FkLegacy`, `NonFkUbahNipLegacy`, `NonFkSlipGaji`, `NonFkFkV2`; `diubahLegacy()` |
| `KeberadaanV2` | `Ada` (fase dan pemilik diketahui), `TidakDiimpor`, `BelumDiputuskan` |
| `FormatNip` | `valid` (1–18 digit ASCII), `periksa` (input → `HasilKalkulasi`, kode `nip_wajib`/`nip_tidak_valid`), `periksaKoreksi` (B-06, kode `nip_lama_wajib`/`nip_baru_sama`) |

### Isi registry

| Jenis | Jumlah | Sumber | Ubah NIP legacy |
|---|---|---|---|
| FK legacy `REFERENCES pegawai (nip)` | 75 kolom, 70 tabel | [K]; semuanya `ON UPDATE CASCADE`, nama FK legacy dicatat per entri | Ikut berubah (cascade) |
| Non-FK yang diganti kode Ubah NIP | 1 (`pengguna.username`) | [K-kode] `L_employee.php:589-599`: `username` = NIP baru, ditambah reset `password` = md5(NIP baru) dan `password_decode` | Ikut berubah (kode) |
| Non-FK Slip Gaji | 1 (`gaji_pegawai.nip`) | [K] tanpa FK, `UNIQUE (nip, bulan, tahun)` | **Tidak** berubah, sehingga slip lama yatim |
| Non-FK yang wajib FK di v2 | 2 (`riwayat_cuti.nip_atasan_langsung`, `nip_yang_menyetujui`) | [K] tanpa FK, VARCHAR(50); [V2] DoD C-01 (`04-Layanan.md:21`), SRS :161 | **Tidak** berubah |

Status v2 per entri berasal dari `0x-*.md`, Mapping Migrasi, dan draf DBV-012/DBV-013. Draf itu baru pra-review internal dan belum disetujui DB Validator.

| Keberadaan | Jumlah | Rincian |
|---|---|---|
| `Ada` | 61 | Fase 0: 1 (`token`, F0-06) · Fase 1: 2 (`pengguna.id_pegawai` → `pengguna.nip`, `pengguna.username`; A-01) · Fase 2: 2 (`faq_rate` G-10, `user_lokasi_presensi` G-03) · Fase 3: 40 (B-01: 16, B-02: 24, sama dengan registry draf DBV-012/013 revisi D1, termasuk `pegawai_ak_siasn` dari keputusan DBV-012 8 #6 yang belum disetujui) · Fase 4: 8 (C-01) · Fase 5: 2 (`dh_online`, `gaji_pegawai`; D-01) · Fase 6: 1 (`esign_hist`, H-01) · Fase 7: 5 (F-01) |
| `TidakDiimpor` | 11 | Tabel cadangan bertanggal (2), `riwayat_lckh_2019..2022` (8 kolom), dan `riwayat_layanan` yang tidak dipakai kode legacy |
| `BelumDiputuskan` | 7 | `email_pool`, `email_retry`, `email_sent`, `layanan_pegawai`, `login_mysapk`, `riwayat_cuti_notif_kt`, `user_geo` |

`sampaiFase(3)` = 45 entri, yaitu cakupan B-06 di Fase 3. Fase berikutnya menambah entri tanpa mengubah kode B-06. Kecocokan registry dengan skema nyata (`information_schema.KEY_COLUMN_USAGE` dan kolom NIP tanpa FK) **menyusul sebagai test ber-DB B-21** di `tests/Kepegawaian/` setelah tabel B-01/B-02 ada. Test unit `TabelAnakNipTest` hanya memeriksa kelengkapan data: tidak ada duplikat, label sumber, nama FK, fase/pemilik, dan jumlah per kategori.

### Kolom NIP tanpa FK di luar registry (keputusan B-06)

Legacy tidak mengganti kolom-kolom ini saat Ubah NIP. Kolom ini masuk registry hanya bila B-06 memutuskan untuk ikut mengoreksinya:
- Tabel v2 Fase 3 (draf DBV-013, tetap tanpa FK): `riwayat_skp.nip_penilai`, `riwayat_skp.nip_atasan_penilai`, `riwayat_skp_periodik.nip`, `riwayat_skp_periodik.pegawai_atasan_nip`.
- Tabel v2 lain menurut Mapping Migrasi: `petugas_layanan.nip` (Tier 3, C-01 menurut draf DBV-012), `inbox.nip` (C-01), `firebase_token.nip` (H-01, disiapkan kosong), `simapi_update.nip` (H-01, log).
- Jejak di `main` (A-01): `audit_logs.nip_actor` (D-8), `login_attempts.username`, `forgot_attempts.username` (legacy `forgot_attempts.nip`), dan `token.nip` (terdaftar sebagai FK legacy, tetapi di v2 menjadi jejak D-7).
- Bukan rujukan: `pegawai.nip_lama`, `pegawai_hist.nip_lama` (NIP lama).
- Tabel legacy lain yang tidak ada di Mapping (17 kolom): `absen_real`, `andr_topic_queue`, `dh_online_coordinate`, `fd_dig`, `firebase_message_logs`, `form_kesehatan`, `ina`, `jabatan_koordinasi`, `jabatan_plt.nip_plt`, `kda_pegawai`, `pa_layanan`, `peg_email`, `pegawai_jabatan`, `riwayat_jabatan`, `riwayat_mutasi_req`, `siasn_upload_arsip`, `simapi_ch`. Ditambah 103 kolom bernama NIP di tabel cadangan, staging, dan uji (`*_YYYYMMDD`, `d_*`, `test_*`, `sapk_*_src`).

Di dump D1, cabang trigger yang bereaksi pada perubahan NIP (`IF OLD.nip != NEW.nip` di `pegawai_afUpd` dan `riwayat_*_afUpd`) hanya menentukan NIP mana yang ditulis ke log sinkron SIMAPI (`simapi_update`, `simapi_ch`). Cabang itu tidak mengubah kolom NIP di tabel lain. Log ini tidak di-port ke B-06; perannya diputuskan di H-05..H-07.

### Format NIP

Aturannya sama dengan akun DBV-010: angka ASCII saja, 1–18 digit. NIP PNS 18 digit, NIK 16 digit pegawai Non-PNS, dan nomor pendek pegawai lama semuanya diterima. Spasi di ujung dibuang. Nilai yang bukan string, misalnya angka JSON yang kehilangan nol di depan atau array, ditolak dengan 422, bukan 500. Legacy tidak memvalidasi format di server: Ubah NIP hanya memakai inputmask 18 digit (`views/hr/employee/form_nip.php:62`) dan menolak isian kosong (`L_employee.php:561-568`).

Auth sudah memegang aturan ini di `PenggunaModel::NIP_MAX_DIGITS`, `UserService::validateNip()` (private), dan `AccountProvisioner`. Tidak ada versi publik yang bisa dipanggil kelas murni. Karena itu `FormatNip` menjadi pintu Kepegawaian, dan `FormatNipTest::testSelarasDenganAturanAkunAuth` membandingkan konstanta serta perilaku `UserService::validateNip` pada kasus batas. Bila salah satu berubah, test gagal. Auth tidak diubah di CR-036.

### Keputusan rancangan CR-036 (pra-review internal sisi CR)

| # | Keputusan |
|---|---|
| K-CR036-1 | Registry = data statis murni di `Nip/`, dijaga `KalkulasiMurniTest`. Nama tabel/kolom legacy dipakai apa adanya; kecocokan dengan skema nyata diuji test ber-DB B-21 |
| K-CR036-2 | Cakupan: FK legacy (75), non-FK yang diganti Ubah NIP legacy (1), `gaji_pegawai.nip` (1), dan non-FK legacy yang wajib FK di v2 (2). Kolom NIP non-FK lain masuk setelah ada keputusan B-06 |
| K-CR036-3 | Keberadaan v2 hanya diisi dari dokumen (`0x-*.md`, Mapping Migrasi, draf DBV-012/013). Yang tidak tertulis berstatus `BelumDiputuskan`, tidak ditebak |
| K-CR036-4 | Format NIP = aturan akun DBV-010, tanpa memuat Model. Keselarasan dengan Auth dijaga test; penyatuan kode Auth ke `FormatNip` ditunda ke CR terpisah |
| K-CR036-5 | Cek murni koreksi NIP: NIP lama wajib; NIP baru wajib, formatnya valid, dan berbeda dari NIP lama. Keunikan NIP baru di `pegawai` (legacy :569-575) dan sebagai `username` akun lain (ISSUE-023) dicek service ber-DB |
| K-CR036-6 | BUP setia legacy. TMT pensiun dihitung dari awal bulan lahir, sehingga deviasi tanggal 29–31 di atas terdokumentasi. Hanya untuk PNS; pemanggil yang menyaring |

### Pertanyaan terbuka CR-036 (keputusan user/SME/DBV)

1. **`pengguna.username` saat koreksi NIP.** Legacy menimpa username tanpa syarat dan mereset password ke md5(NIP baru). Usulan: ganti username hanya bila username = NIP lama, dan jangan sentuh password (K4; `password_decode` tidak ada di v2).
2. **`gaji_pegawai.nip` dan `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui`.** Legacy tidak mengubahnya. Di v2, kedua kolom `riwayat_cuti` **wajib** ikut dikoreksi, karena FK RESTRICT akan menolak penghapusan baris pegawai lama. Untuk `gaji_pegawai`, usulannya ikut dikoreksi agar slip lama tetap terlihat pegawai (keputusan D-01: FK atau tidak).
3. **Kolom non-FK di luar registry** (daftar di atas), terutama `riwayat_skp_periodik.nip` milik pegawai sendiri. Usulan: diputuskan per kolom di B-06 bersama DBV-013. Kolom jejak (`audit_logs.nip_actor`, `token.nip`, `*_attempts.username`) tidak diubah.
4. **Tujuh tabel `BelumDiputuskan`** perlu keputusan impor: email legacy, `layanan_pegawai`, `login_mysapk`, `riwayat_cuti_notif_kt` (Mapping: masuk inbox), dan `user_geo`.
5. **Penyatuan registry.** Draf DBV-012 punya konstanta registry sendiri di `NipReferenceRegistryTest`. Usulan: setelah draf itu masuk, test tersebut membaca `TabelAnakNip::sampaiFase(3)`.
6. **Pensiun PPPK.** Legacy memakai akhir masa perjanjian kerja (`mhpk_akhir`), bukan BUP. Ini perlu dikonfirmasi untuk dashboard v2.

## Lingkup akses pegawai (`Scope/`, MAKE-009)

`PegawaiScope` = implementasi nyata `PegawaiScopeInterface` (service `pegawaiScope`). Semua endpoint Modul B memeriksa
lingkup lewat service ini (403 di luar lingkup); daftar disaring dengan `terapkanKeQuery($builder, $auth, 'p.nip')`.

| Role | Lihat | Ubah |
|---|---|---|
| 1 | semua | semua |
| 4, 5, 8 | semua | — |
| 2, 6, 7 | NIP sendiri | NIP sendiri |
| 3 | pegawai dengan `pegawai_mutasi_jabatan.id_satker` = satker akun (akun tanpa satker: `id_unit` = unit akun), plus NIP sendiri | sama |

Hak per aksi per jenis riwayat tetap di Definisi; scope hanya menjawab "pegawai ini dalam lingkup pemanggil". Pegawai
tanpa snapshot jabatan/satker tidak masuk lingkup role 3 (deviasi [V2] dari legacy yang meloloskannya). Aturan legacy
"unit destinasi 21 / unit lain 7" belum diterapkan (sumbernya belum tersedia, lihat progres 03-Kepegawaian).

## Lampiran B-18 (`Lampiran/`, MAKE-009)

| Kelas | Isi |
|---|---|
| `LocalStorageAdapter` | `StorageAdapterInterface` di disk lokal (`writable/uploads/lampiran`); path relatif `[A-Za-z0-9._-]` per segmen, tulis atomik |
| `AttachmentService` | `AttachmentServiceInterface` atas `document_attachment` (model `DocumentAttachmentModel`, audit otomatis create/delete); `path` tidak pernah dikembalikan |
| `ValidasiBerkas` | Ukuran dari disk, jenis dari ISI (finfo → `Config\Mimes`), nama tampil dibersihkan; 422 berkunci `berkas` (atau field pemanggil) |
| `PenjagaBerkas` | Kompensasi berkas di luar transaksi DB: setelah transaksi selesai, berkas tercatat dipertahankan hanya bila masih dirujuk `tabel.kolom = path` |
| `KolomBerkas` | Helper tabel yang menyimpan berkas di kolomnya sendiri (`riwayat_karpeg.file_1..5`, LKH, Konket) |
| `OtorisasiLampiran` | Urutan otorisasi endpoint lampiran: kode `jenis_rwy` → izin Definisi × role → PegawaiScope → `id_entri` milik NIP |

**Pemakaian dari RiwayatEngine (WS-1).** Panggil `service('attachmentService')->simpan(...)`/`hapus(...)` DI DALAM transaksi
engine; tidak perlu kode kompensasi sendiri. Setelah commit/rollback, panggil `selesaikan()` bila service-nya
`AttachmentService` (opsional: tanpa itu rekonsiliasi terjadi pada operasi lampiran berikutnya atau saat proses PHP
berakhir). Otorisasi lampiran tetap tanggung jawab pemanggil (kontrak interface); `OtorisasiLampiran` boleh dipakai ulang.

**Pemakaian `KolomBerkas`.** `simpan()` mengembalikan path untuk ditulis ke kolom; `hapus()` menandai berkas lama;
`selesaikan()` WAJIB dipanggil setelah transaksi (juga di jalur rollback) — tidak ada rekonsiliasi oportunistik karena
path baru belum dirujuk sampai kolomnya ditulis. Contoh lengkap di docblock kelas.
