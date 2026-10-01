# Modul B — Kepegawaian Core (Fase 3)

Service/business logic modul ini (`App\Libraries\Kepegawaian\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

## Kalkulasi murni (`Kalkulasi/`, CR-025, B-21)

Aturan tanggal Kepegawaian yang **tidak butuh tabel baru**: periode KP, jarak KGB, akhir hukuman disiplin, dan masa kerja golongan. Semua kelas `final` dengan method statis, **tanpa DB, HTTP, session, atau jam**, kecuali `TanggalBisnis::hariIni()` (K-CR025-1; dijaga `KalkulasiMurniTest`). Tanpa migration dan tanpa perubahan FE. Service ber-DB (B-08/B-09/B-14/B-19) memanggil kelas ini lalu memetakan hasil gagal ke 422.

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

**Deviasi terdokumentasi dari legacy** (K-CR025-3, K-CR025-8):
- Selisih bulan penuh konsisten dengan penjepitan (`2024-02-29` → `2026-02-28` = 24 bulan; `DateTime::diff` legacy = 23). Berbeda hanya bila hari awal 29–31; TMT legacy praktis selalu tanggal 1.
- Proyeksi KGB tanpa jam: bila acuan + 4 tahun = hari ini, hasilnya hari ini (legacy meloncat 2 tahun karena membandingkan dengan jam). Acuan di masa depan → acuan + 2 tahun dengan masa 0/0 (legacy memakai selisih absolut).

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

### Test

`tests/unit/Kepegawaian/Kalkulasi/` (tanpa DB; konvensi `tests/unit` untuk kelas murni — test ber-DB B-21 seperti cascade NIP dan snapshot sync ada di `tests/Kepegawaian/`):

```bash
cd backend && vendor/bin/phpunit --no-coverage tests/unit/Kepegawaian
```
