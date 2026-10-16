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
| K-CR025-10 | Validasi KP/KGB hanya untuk input pengguna (create, atau update yang mengubah `tmtsk` atau `id_jenis_kp`); impor legacy dan sinkron SIASN tidak melewatinya |
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

## Mesin riwayat (`Riwayat/`, WS-1 M1 MAKE-004)

Satu mesin generik untuk semua jenis riwayat; perilaku per jenis = berkas Definisi (`Riwayat/Definisi/*.php`,
auto-discovery). Kontrak endpoint dan ketetapan perilaku: `app/Controllers/Api/Kepegawaian/README.md` (bagian "Kontrak
API Modul B" dan "Rincian engine riwayat").

| Kelas | Isi |
|---|---|
| `RiwayatEngine` | `RiwayatServiceInterface` (service `riwayatService`): daftar, detail, tambah, ubah, hapus lunak, proses. Satu transaksi per tulisan (riwayat + snapshot + lampiran), baris `pegawai` dikunci `FOR UPDATE`, audit lewat `RiwayatModel`, event `riwayatNotifikasi` sesudah commit |
| `SnapshotSync` | `SnapshotSyncInterface` (service `snapshotSync`): per `AturanSnapshot` pilih ulang baris Disetujui → INSERT/UPDATE (dilewati bila sama) atau DELETE snapshot; audit manual per tulisan |
| `PemilihSnapshot` | Fungsi murni pemilih baris snapshot: status Disetujui (lewat `pemetaanStatus()`), filter dengan logika tiga nilai SQL (`!=`/`NOT IN` tidak memilih NULL), urutan berprioritas (NULL terkecil seperti MySQL, angka sebagai angka), seri → PK terbesar ([V2]) |
| `ValidasiRiwayat` | Validasi + normalisasi payload dari `RiwayatDefinisi::fields()` (rules `MasterField`, nilai tunggal, ref master ada/aktif/berjenjang) |
| `App\Models\Kepegawaian\RiwayatModel` | Model generik `riwayat_*` dari Definisi (turunan `BaseAuditableModel`, pola `MasterModel`); kolom sistem ditulis hanya bila ada di tabel; pelaku audit = `AuthContext` pemanggil (`withActor`) |

**Aturan snapshot (ADR-006, dok DBV-012 §5.1).** Snapshot hanya disentuh engine bila status lama atau baru = Disetujui:
proses `setujui`, tambah langsung oleh admin, ubah oleh role 3 (legacy menyetujui otomatis), ubah data baris yang sudah
disetujui (setara trigger `afUpd`), dan hapus/keluar dari status 1 (dihitung ulang, DELETE bila kosong). Pengajuan
pegawai dan penolakan baris yang belum disetujui tidak menyentuh snapshot. Pemecah seri PK terbesar adalah keputusan
[V2] (legacy `ORDER BY … LIMIT 1` tanpa pemecah seri tidak deterministik): PK terbesar = baris yang **dibuat**
belakangan, bukan yang disetujui belakangan. Untuk "mutasi bertanggal sama yang disetujui belakangan menang" (dok §5.1,
B-07), Definisi menaruh kolom waktu persetujuan (mis. `notif_date`/`updated_at` DESC) di urutan sebelum pemecah seri.

**Salin nama master = hook `beforeSave`** (WS1 §3.4.3). Kolom teks turunan master di tabel riwayat (mis. `jenis_kp`,
`gol`, `ruang`, `gol_ruang`, `pangkat` di `riwayat_kp`, legacy `L_kp.php:551-560`) diisi server di
`RiwayatDefinisi::beforeSave()` dari ID rujukan yang dikirim klien, bukan field yang diisi klien. Hook dipanggil di dalam
transaksi engine dan hanya menurunkan kolom yang rujukannya ada di payload (ubah parsial tanpa rujukan tidak menyentuh
kolom turunan). Contoh templat: `tests/_support/Kepegawaian/Riwayat/UjiKp.php`.

**Tanpa TOCTOU.** Setiap tulisan (ubah/hapus/proses) mengunci baris `pegawai` lalu membaca ulang baris riwayat
`FOR UPDATE` di dalam transaksi; guard kunci baris Disetujui (403), guard status proses (422), serta status lama/baru
dihitung dari baca ulang itu. Pemeriksaan sebelum transaksi hanya jalan cepat.

**Ikut legacy [K]** (data di Definisi, bisa di-override per jenis): status awal 0 untuk UL_PEGAWAI di alur
self-service/usulan, selain itu 1 (`controllers/hr/rwy/Pendidikan.php:146`); ubah oleh UL_PEGAWAI → 0, role 3 → 1, role
1 → tetap (`Pendidikan.php:255-260`, `Kp.php:196-198`), status NULL (data lama) diperlakukan seperti 0; UL_PEGAWAI tidak bisa mengubah/menghapus baris disetujui dan
role 3 tidak bisa menghapusnya (`Pendidikan.php:471-484`, `Kp.php:379-387`); `approved_by`/`updated_by`/`show_ua_*` saat
proses (`libraries/hr/rwy/L_kp.php:649-668`); `show_notif`/`notif_date` pada transisi 0 → 1/2 (trigger `<tabel>_beUpd`,
dok DBV-012 §5.2); tipe notifikasi 1/2/3 (`helpers/function_helper.php:588`).

**Deviasi [V2] dari legacy.** Hapus = lunak (status 10 + audit `delete`), bukan `DELETE` baris + lampiran; lampiran baris
yang dihapus tetap tersimpan. Penanda baca notifikasi (`show_notif = 3` saat detail dibuka dari notifikasi, legacy
`L_kp.php:84-87`) belum dibawa — milik fitur notifikasi (H-0x), bukan GET riwayat. Push FCM tidak dikirim engine:
engine hanya memicu event `riwayatNotifikasi` (token perangkat `firebase_token` = H-01). Proses hanya dari status 0/3
(422 `errors.status`) — legacy tidak menjaganya. Urutan daftar default PK DESC (`urutanDaftar()`) — legacy KP
`ORDER BY status ASC, tmtsk ASC` dan hanya status 0/1 (`L_kp.php:27`); Definisi jenis nyata meng-override sesuai legacy
jenisnya mulai M2.

**Pemakaian lampiran nyata (setelah MAKE-009).** Engine memanggil `attachmentService->simpan()` di dalam transaksi,
lalu `selesaikan()` (bila implementasinya punya) setelah commit atau rollback.

**Definisi nyata (M2, MAKE-005).**

| Definisi | Isi (sumber [K] di docblock kelas) |
|---|---|
| `Pendidikan` (B-10) | Self-service; izin 1, 2, 3, 6, 7 (proses 1, 3); jenjang di luar 1-3 → bidang & jurusan wajib, kode 98/1185 → isian "lain" wajib; tanggal ijazah tidak kembar (status selain 2/10, atas nilai akhir setiap tambah/ubah); nama jenjang/bidang/jurusan dan nem/ipk diturunkan `beforeSave` (`L_pendidikan.php` `sp()`); Ijazah (14) wajib, transkrip (40) & SK pencantuman gelar (39) opsional, 5 MB, jpg/jpeg/png/gif/pdf; snapshot jenjang tertinggi; daftar `status ASC, tgl_lulus ASC`; role 2 hanya tambah/ubah/hapus selama pegawai CPNS (`bolehAksiUntuk`, legacy `$isCPNS`); role 1 memilih status saat ubah (legacy `pendidikan/form.php:59-76`) |
| `Kp` (B-08) | Alur admin; izin lihat 1, 2, 3, 4, 5, tulis/proses 1, 3 (Matriks v2; legacy juga membuka lihat untuk 8); field wajib legacy (`validate_param()`); pengangkatan CPNS/PNS sekali, TMT tidak kembar (status selain 2/10, atas nilai akhir setiap tambah/ubah); [V2] TMT 1 April/1 Oktober kecuali pengangkatan (CR-025, hanya bila tambah atau TMT/jenis berubah); nama jenis/pangkat dari `beforeSave`; SK (11) wajib, 5 MB; snapshot `pegawai_kp`/`_cpns`/`_pns`; daftar `status ASC, tmtsk ASC`; role 1 boleh memilih `status` saat ubah (legacy `kp/form.php:59-74`) |

Hook `validate()` menerima kolom NIP pemilik di `$data` (aditif MAKE-005) agar cek per pegawai (tanggal kembar, CPNS
sekali) juga jalan saat tambah.

Hook `beforeSave()` saat tambah menerima `$data` berisi kolom NIP pemilik dan `$lama = null` (aditif MAKE-008; sebelumnya
tanpa NIP, I-1); NIP tetap ditulis engine sesudah hook. Saat ubah NIP ada di `$lama`.

### Engine-v1 beku (MAKE-005)

Sejak MAKE-005 di `main`, permukaan berikut **beku**: hanya boleh berubah **aditif** dan **oleh WS-1**; permintaan WS-2
(mis. hook baru untuk B-07 Jabatan) diajukan sebagai usulan ke WS-1.

| Permukaan | Isi beku |
|---|---|
| `RiwayatServiceInterface` / `RiwayatEngine` | `daftar`, `detail`, `tambah`, `ubah`, `hapus`, `proses`; urutan pemeriksaan (jenis → izin role → PegawaiScope `bolehLihat`/`bolehUbah`/`bolehProses` → NIP → `bolehAksiUntuk` → baris → validasi), satu transaksi + kunci pegawai + baca ulang terkunci, event `riwayatNotifikasi` |
| `SnapshotSyncInterface` / `SnapshotSync`, `PemilihSnapshot` | `sinkronkan(definisi, nip, ?pelaku)`; aturan pilih (status 1, filter tiga nilai SQL, urutan + join, seri PK terbesar) |
| `AturanSnapshot`, `AturanLampiran` | Value object (konstruktor & properti) |
| Hook `RiwayatDefinisi` | `jenis`, `label`, `tabel`, `primaryKey`, `fields`, `izin`, `alur`, `kolomNip`, `kolomStatus`, `pemetaanStatus`, `kunciBarisDisetujui`, `roleKunciHapusDisetujui`, `statusAwal`, `statusSetelahUbah`, `rolePilihStatusSaatUbah`, `bolehAksiUntuk`, `urutanDaftar`, `snapshot`, `lampiran`, `urutanTab`, `validate` (dengan NIP), `beforeSave`, `afterApprove` |
| Kontrak HTTP | README `Controllers/Api/Kepegawaian` (endpoint, descriptor, "Rincian engine riwayat") |

Perubahan sesudah beku (aditif / konsistensi, WS-1):
- MAKE-006: `RiwayatRegistry::descriptorUntuk()` mengisi `can_process` dengan `PegawaiScope::bolehProses` (jalur proses
  CR-045, sama dengan `RiwayatEngine::proses()`), bukan `bolehUbah` — perubahan konsistensi, bentuk descriptor tetap.
- MAKE-006: endpoint aditif `GET pegawai/{nip}/riwayat` → `{ tabs }` (sumber = `descriptorUntuk`).
- MAKE-007 (hook aditif, ikut beku sejak masuk `main`): `RiwayatDefinisi::lampiranWajib(aturan, nilai)`,
  `entriLampiran(kode)` (lampiran per baris tabel anak; OtorisasiLampiran WS-2 membacanya), `kolomBerkas()` +
  `kolomBerkasWajib()` (berkas di kolom tabel lewat KolomBerkas MAKE-009, `selesaikan()` sesudah commit/rollback),
  `RiwayatEngine::unduhKolomBerkas()`; endpoint aditif `…/{id}/berkas/{kolom}`, `…/keluarga/{id}/anak`, `usulan/{jenis}`.
- Isu cutover (MAKE-007): kolom berkas data impor legacy (mis. `riwayat_karpeg.file_*`) berisi path arsip remote legacy
  (diawali `/`, berspasi) yang tidak ada di storage v2 — unduh menjawab 404 (bukan 500) sampai berkas arsip dipindahkan
  saat cutover.
- [V2] Diklat (MAKE-007): `id_diklat` wajib milik `jenis_diklat` terpilih (legacy `L_diklat.php:527-536` hanya memeriksa
  keberadaannya).
- MAKE-008 (hook aditif, ikut beku sejak masuk `main`): `RiwayatDefinisi::saringDaftar(BaseBuilder)` (saring tambahan
  daftar; detail/ubah tidak ikut disaring), `beforeSave()` saat tambah menerima kolom NIP pemilik di `$data`,
  `afterProses(aksi, baris, auth)` (dipanggil di transaksi `proses` untuk setujui DAN tolak, sesudah status ditulis;
  default no-op — AK mengisi `appr_at`/`appr_by`).
- MAKE-017 (hook aditif): `RiwayatDefinisi::pratinjauTambah(nip, query)` + `RiwayatEngine::pratinjauTambah()` + endpoint
  `GET …/riwayat/{jenis}/pratinjau` (izin aksi tambah; default null → 404). AK memakainya untuk isian turunan form tambah
  (logika bersama `Ak::turunan()`/`masaKerja()`/`akTerakhir()` dengan beforeSave).
- MAKE-019 (hook aditif, untuk B-07 sub rumpun multiselect WS-2): `RiwayatDefinisi::isianMentah(): list<string>` (bawaan
  []). Kunci terdaftar yang DIKIRIM klien diteruskan engine apa adanya (tanpa ValidasiRiwayat; JSON array, form `kolom[]`
  berulang → array, form `kolom=''` → '') ke `validate()` dan `beforeSave()` saat tambah & ubah; kunci absen = tidak
  ada di `$data`. Engine membuang sisa kunci mentah sesudah beforeSave (tidak pernah ditulis ke tabel) — Definisi yang
  memindahkan/menormalkannya ke kolom nyata. RiwayatRegistry menolak (LogicException) nama tidak sah atau bentrok dengan
  field `fields()`, kolom berkas `kolomBerkas()`, kolom NIP/status, atau PK; nama sebaiknya bukan kolom DDL tabel.

**Test.** `tests/unit/Kepegawaian/Riwayat/PemilihSnapshotTest.php` (murni), `tests/Kepegawaian/Riwayat/` (ber-DB;
`PendidikanTest`, `KpTest` = B-TC + aturan per jenis, dengan PegawaiScope + AttachmentService/LocalStorageAdapter nyata
lewat `RiwayatUjiTrait::pasangEngineNyata()`; Definisi uji M1 tetap memakai fake S0 lewat `pasangEngine()`).

**Retensi lampiran baris status 10 (keputusan WS-1, M2).** Hapus riwayat = status 10; baris `document_attachment` dan
berkasnya **dipertahankan** (jejak audit/pemulihan; legacy menghapus keras keduanya). Endpoint lampiran MAKE-009
menjawab 404 untuk baris status 10, jadi berkas itu tidak lagi bisa diunduh/diubah lewat API. Pembersihan berkala (bila
perlu) diputuskan terpisah.
Trait B-TC generik `Tests\Support\Kepegawaian\RiwayatBtcTrait` + helper `RiwayatUjiTrait`: setiap jenis riwayat
nyata membuat satu kelas test `use RiwayatBtcTrait` dan mengisi `btcDefinisi()`, `btcPayload()`, `btcBaris()`.

## Registry kolom NIP dan format NIP (`Nip/`, CR-036, B-06)

Bahan murni untuk koreksi NIP (B-06), disiapkan lebih awal sebagai pengecualian urutan fase yang disetujui user pada 01-10-2026. Tidak ada migration, endpoint, atau FE. PK `pegawai` adalah `nip` VARCHAR(30) (K1), dan FK v2 memakai `ON UPDATE RESTRICT`. Karena itu B-06 (`NipCascadeService`) menjalankan koreksi dalam **satu transaksi**: salin baris `pegawai` ke NIP baru, arahkan ulang setiap kolom di registry, lalu hapus baris lama. Legacy cukup menjalankan `UPDATE pegawai SET nip`, karena FK-nya `ON UPDATE CASCADE` (`libraries/hr/L_employee.php:586-587`).

Label sumber registry: **[K]** = DDL dump struktur produksi 01-10-2026 (D1, 285 tabel), **[K-kode]** = kode legacy (path relatif ke `application/`), **[V2]** = dokumen v2. Di bagian Kalkulasi di atas, [K] berarti kode legacy.

| Kelas | Isi |
|---|---|
| `TabelAnakNip` | Registry statis 82 entri. Method: `semua`, `perFase(n)`, `sampaiFase(n)` (cakupan B-06 setelah fase n selesai), `perJenis`, `perKeberadaan` |
| `RujukanNip` | Satu entri: `tabel`, `kolom` (nama legacy), `kolomDiV2()`, `jenis`, `sumber`, `namaFkLegacy`, `nullable` (legacy), `keberadaan`, `fase`, `pemilik` (task migration), `catatan`, `kunci()` |
| `JenisRujukanNip` | `FkLegacy`, `NonFkUbahNipLegacy`, `NonFkSlipGaji`, `NonFkFkV2`, `NonFkKeputusanV2` (MAKE-010: tanpa FK, tidak diganti legacy, ikut dikoreksi atas keputusan v2 — `jabatan_koordinasi.nip`, keputusan #10), `JsonArrayNip` (MAKE-011: kolom teks berisi JSON array NIP + pasangan `*_desc` "NIP - Nama" — `dm_user_lokasi_presensi.target_peg`/`excl_peg`); `diubahLegacy()` |
| `KeberadaanV2` | `Ada` (fase dan pemilik diketahui), `TidakDiimpor`, `BelumDiputuskan` |
| `FormatNip` | `valid` (1–18 digit ASCII), `periksa` (input → `HasilKalkulasi`, kode `nip_wajib`/`nip_tidak_valid`), `periksaKoreksi` (B-06, kode `nip_lama_wajib`/`nip_baru_sama`) |

### Isi registry

| Jenis | Jumlah | Sumber | Ubah NIP legacy |
|---|---|---|---|
| FK legacy `REFERENCES pegawai (nip)` | 75 kolom, 70 tabel | [K]; semuanya `ON UPDATE CASCADE`, nama FK legacy dicatat per entri | Ikut berubah (cascade) |
| Non-FK yang diganti kode Ubah NIP | 1 (`pengguna.username`) | [K-kode] `L_employee.php:589-599`: `username` = NIP baru, ditambah reset `password` = md5(NIP baru) dan `password_decode` | Ikut berubah (kode) |
| Non-FK Slip Gaji | 1 (`gaji_pegawai.nip`) | [K] tanpa FK, `UNIQUE (nip, bulan, tahun)` | **Tidak** berubah, sehingga slip lama yatim |
| Non-FK yang wajib FK di v2 | 2 (`riwayat_cuti.nip_atasan_langsung`, `nip_yang_menyetujui`) | [K] tanpa FK, VARCHAR(50); [V2] DoD C-01 (`04-Layanan.md:21`), SRS :161 | **Tidak** berubah |
| Non-FK keputusan v2 (MAKE-010) | 1 (`jabatan_koordinasi.nip`, fase 2 G-02) | [K] tanpa FK (D1:1510-1545, DBV-018); [V2] keputusan #10 WS2.md | **Tidak** berubah (v2 ikut mengoreksi) |
| JSON array NIP (MAKE-011) | 2 (`dm_user_lokasi_presensi.target_peg`, `excl_peg`, fase 2 G-03) | [K-kode] `libraries/hr/master/Lm_lokasi.php:694-758` | **Tidak** berubah (v2 mengganti elemen array + awalan `*_desc`, keputusan user 13-10-2026) |

Status v2 per entri berasal dari `0x-*.md`, Mapping Migrasi, dan draf DBV-012/DBV-013. Draf itu baru pra-review internal dan belum disetujui DB Validator.

| Keberadaan | Jumlah | Rincian |
|---|---|---|
| `Ada` | 63 | Fase 0: 1 (`token`, F0-06) · Fase 1: 2 (`pengguna.id_pegawai` → `pengguna.nip`, `pengguna.username`; A-01) · Fase 2: 4 (`faq_rate` G-10, `jabatan_koordinasi.nip` G-02 MAKE-010, `dm_user_lokasi_presensi.target_peg`/`excl_peg` G-03 MAKE-011) · Fase 3: 40 (B-01: 16, B-02: 24, sama dengan registry draf DBV-012/013 revisi D1, termasuk `pegawai_ak_siasn` dari keputusan DBV-012 8 #6 yang belum disetujui) · Fase 4: 8 (C-01) · Fase 5: 2 (`dh_online`, `gaji_pegawai`; D-01) · Fase 6: 1 (`esign_hist`, H-01) · Fase 7: 5 (F-01) |
| `TidakDiimpor` | 12 | Tabel cadangan bertanggal (2), `riwayat_lckh_2019..2022` (8 kolom), `riwayat_layanan` yang tidak dipakai kode legacy, dan `user_lokasi_presensi` (tidak dibuat di v2, diganti JSON `dm_user_lokasi_presensi`; MAKE-011) |
| `BelumDiputuskan` | 7 | `email_pool`, `email_retry`, `email_sent`, `layanan_pegawai`, `login_mysapk`, `riwayat_cuti_notif_kt`, `user_geo` |

`sampaiFase(3)` = 47 entri, yaitu cakupan B-06 di Fase 3 (dipakai `NipCascadeService`, MAKE-010/011). Fase berikutnya menambah entri tanpa mengubah kode B-06. Kecocokan registry dengan skema nyata (`information_schema.KEY_COLUMN_USAGE` dan kolom NIP tanpa FK) **menyusul sebagai test ber-DB B-21** di `tests/Kepegawaian/` setelah tabel B-01/B-02 ada. Test unit `TabelAnakNipTest` hanya memeriksa kelengkapan data: tidak ada duplikat, label sumber, nama FK, fase/pemilik, dan jumlah per kategori.

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

1. ~~**`pengguna.username` saat koreksi NIP.**~~ **Diputus user 13-10-2026 (MAKE-010): ikut legacy penuh** — username = NIP baru dan password direset ke NIP baru (hash Argon2id v2, bukan md5); lihat bagian Koreksi NIP B-06.
2. **`gaji_pegawai.nip` dan `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui`.** Legacy tidak mengubahnya. Di v2, kedua kolom `riwayat_cuti` **wajib** ikut dikoreksi, karena FK RESTRICT akan menolak penghapusan baris pegawai lama. Untuk `gaji_pegawai`, usulannya ikut dikoreksi agar slip lama tetap terlihat pegawai (keputusan D-01: FK atau tidak).
3. **Kolom non-FK di luar registry** (daftar di atas), terutama `riwayat_skp_periodik.nip` milik pegawai sendiri. Usulan: diputuskan per kolom di B-06 bersama DBV-013. Kolom jejak (`audit_logs.nip_actor`, `token.nip`, `*_attempts.username`) tidak diubah.
4. **Tujuh tabel `BelumDiputuskan`** perlu keputusan impor: email legacy, `layanan_pegawai`, `login_mysapk`, `riwayat_cuti_notif_kt` (Mapping: masuk inbox), dan `user_geo`.
5. **Penyatuan registry.** Draf DBV-012 punya konstanta registry sendiri di `NipReferenceRegistryTest`. Usulan: setelah draf itu masuk, test tersebut membaca `TabelAnakNip::sampaiFase(3)`.
6. **Pensiun PPPK.** Legacy memakai akhir masa perjanjian kerja (`mhpk_akhir`), bukan BUP. Ini perlu dikonfirmasi untuk dashboard v2.

## Koreksi NIP B-06 (`NipCascadeService`, MAKE-010)

`NipCascadeService` (service `nipCascade`, di luar `Nip/` karena memakai DB) menjalankan koreksi NIP dalam **satu
transaksi** ikut `update_nip` legacy (`libraries/hr/L_employee.php:550-619`, keputusan #10):

1. role 1 saja (403); format `FormatNip::periksaKoreksi` (422 `nip_lama`/`nip_baru`); pegawai lama ada (404);
2. kunci `pegawai` lama `FOR UPDATE`; NIP baru belum dipakai pegawai lain [K] (:569-575, pesan memuat nama pemiliknya)
   dan bukan username/NIP akun lain (ISSUE-023) → 422 `nip_baru`;
3. salin baris `pegawai` ke NIP baru → `UPDATE <tabel> SET <kolom> = baru WHERE <kolom> = lama` untuk setiap entri
   `TabelAnakNip::sampaiFase(3)` → hapus baris lama (FK RESTRICT = jaring pengaman: tabel anak di luar registry
   menggagalkan DELETE dan seluruh koreksi di-rollback) → audit `audit_logs` (`pegawai`, `update`, before/after NIP +
   jumlah baris per `tabel.kolom`). Setiap langkah yang gagal (juga saat DBDebug mati) melempar DatabaseException →
   rollback penuh.

Perlakuan khusus:

- **Akun [K] ikut legacy penuh** (:589-599, keputusan user 13-10-2026, menutup pertanyaan CR-036 #1): `username` akun
  pegawai itu selalu = NIP baru dan password direset ke NIP baru, di-hash Argon2id (`PasswordVerifier`, bukan md5;
  `password_decode` tidak ada di v2), `password_legacy` NULL, `password_changed_at` NULL (seperti akun baru
  AccountProvisioner). Penanda "wajib ganti password" belum ada di skema v2 (kebutuhan skema, laporan MAKE-010).
- **Sesi [V2]**: refresh token menyimpan claims termasuk `nip` saat terbit, jadi seluruh sesi akun itu dicabut
  (`JwtService::revokeAllForUser`) di transaksi yang sama. `token.nip` (jejak A-01 D-7) tidak diarahkan ulang.
- **JSON array NIP** (`JsonArrayNip`, MAKE-011, keputusan user 13-10-2026): elemen = NIP lama di
  `dm_user_lokasi_presensi.target_peg`/`excl_peg` diganti NIP baru, dan awalan "NIP lama - " di `*_desc`; baris dikunci
  `FOR UPDATE`, NIP yang hanya mirip (awalan sama) tidak tersentuh. `user_lokasi_presensi` (tabel legacy) tidak dibuat di
  v2 → entri registry-nya `TidakDiimpor`.
- **Tabel registry yang belum ada di skema** dilewati dan dilaporkan di `dilewati`; `NipCascadeTest` mengunci daftarnya
  (kosong sejak MAKE-011). Keberadaan tabel dibaca dari `information_schema`, bukan `tableExists()` (cache daftar tabel
  CI4 yang terisi sebelum migrate membuat semua tabel dianggap tidak ada — temuan CI MAKE-010).
- **Audit akun**: setiap akun yang username/password-nya direset dicatat `audit_logs` entity `pengguna` (password
  dimasking; `pengguna` tidak punya kolom `updated_by`, pelaku = `id_pengguna_actor`). Username kustom ≠ NIP lama juga
  ditimpa ([K]); bentrok dengan username akun lain dicegah cek keunikan NIP baru. 1062 pada salinan `pegawai` (balapan)
  → 422 `nip_baru`.
- `jabatan_koordinasi.nip` ikut dikoreksi (keputusan #10) lewat entri registry `NonFkKeputusanV2`.

Test DoD B-06 + butir cascade B-21: `tests/Kepegawaian/NipCascadeTest.php` (data dummy di setiap tabel registry yang
ada — 40+ tabel —, rollback penuh dengan entri registry rusak di urutan terakhir, akun & login NIP baru, sesi dicabut,
registry ⊇ semua FK ke `pegawai(nip)` di skema, endpoint).

## Pegawai & biodata (`Pegawai/`, MAKE-010)

| Kelas | Isi |
|---|---|
| `PegawaiService` | `pegawaiService`: daftar (role 1, 3, 4, 5, 8; `status IN (1, 2)`; lingkup jalur daftar) dan detail (`bolehLihat`, `tabs` = `descriptorUntuk`, `aksi`, `pengajuan`) |
| `BiodataService` | `biodataService`: B-03 dua jalur dalam satu transaksi (pegawai `FOR UPDATE`) + foto profil lewat `KolomBerkas` (`pegawai.foto` + `pegawai_foto` pada jalur langsung; `pegawai_hist.foto` pada jalur pengajuan) |
| `ValidasiBiodata` | Isian per role ikut form legacy, normalisasi `set_param` (digit, nama master turunan, "Kerabat Lain"), kewajiban `validate_param`; [V2] format email/tanggal, panjang DDL, master aktif bila berubah, kabupaten milik provinsi (kecuali 9999), jenis status milik status pegawai; isian tanpa hak yang nilainya berbeda → 422 (keputusan reviewer 13-10-2026; nilai sama diterima karena form legacy mengirim isian readonly apa adanya) |
| `App\Models\Kepegawaian\PegawaiModel`, `PegawaiHistModel`, `PegawaiFotoModel` | Model auditable + `PelakuAuditTrait` (pelaku = AuthContext pemanggil, tulis gagal → DatabaseException) |

Keputusan [K]/[V2] B-03: role 1 menimpa pengajuan aktif → `flag_update` 2 TANPA alasan + `show_notif`/`notif_date`
(trigger `pegawai_hist_beUpd`, keputusan user 13-10-2026); jalur pengajuan menolak gelar > 12/24 karakter (panjang
`pegawai_hist.glr_awal`/`glr_akhir` [K], sementara sampai DBV-020 melebarkannya) dengan arahan menghubungi admin;
pengajuan ulang dengan foto baru membuang foto pengajuan sebelumnya (kecuali masih foto pegawai/galeri); kolom `status` pegawai tidak dibuka di biodata (B-05, MAKE-011);
arsip status/arsip data umum (`document_attachment` ARSIP_RWY `status_du`/`arsip_du`) ditunda ke M6; pegawai status 10
tidak bisa diubah [V2]; email akun hanya disinkron pada jalur langsung (jalur pengajuan menyimpannya di `pegawai_hist.email`
sampai disetujui B-04).

## Approval biodata B-04 & siklus pegawai B-05 (`Pegawai/`, MAKE-011)

| Kelas | Isi |
|---|---|
| `ApprovalBiodata` | B-04: antrean pengajuan menunggu (jalur PROSES), detail + `perubahan`, setujui (salin kolom biodata → `pegawai`, `flag_update` 3, email akun, galeri foto) / tolak (alasan wajib, `pegawai` tidak berubah kecuali `flag_update` 2 — bug legacy `sp_process` :1098 tidak ditiru), penanda `show_notif`/`notif_date`/`approved_by`/`show_ua_*` seperti legacy :1095-1103 + trigger `pegawai_hist_beUpd`. [V2]: hanya kolom biodata yang disalin (bukan `status`/`nip`/SIASN), lingkup role 3 diperiksa `bolehProses` (legacy `process` tidak memeriksanya) |
| `SiklusPegawai` | B-05: tambah (pegawai status 1 + foto + akun A-09 password awal = NIP, satu transaksi; NIP yang sudah tertaut akun mana pun, termasuk soft-deleted, → 422) dan hapus FISIK ikut legacy (422 bila masih ada data turunan: FK RESTRICT 1451; akun ber-NIP DILEPAS — `nip` NULL, nonaktif, sesi dicabut, diaudit [K] D1:3756 ON DELETE SET NULL; galeri `pegawai_foto` ikut dihapus [K] D1:2932 ON DELETE CASCADE, diaudit, berkas dibuang sesudah commit; audit `delete`) |
| `AkunPegawai` | Role akun dari jenis pegawai [K] (`2` → PTT, `6` → PPPK, lain → PNS); nonaktifkan akun + cabut sesi (status 1 → 2 hanya user_level 2, trigger `pegawai_afUpd`) |

**Jalur proses PegawaiScope = interface terpisah `PegawaiScopeProsesInterface`** (`terapkanKeQueryProses`, aditif
MAKE-011), bukan method baru di `PegawaiScopeInterface`: kontrak beku itu punya implementasi lain (fake test
`FakePegawaiScope`, kelas anonim di test WS-1 `RiwayatTabEndpointTest`) yang akan rusak bila interface-nya ditambah.
`PegawaiScope` dan `StubPegawaiScope` mengimplementasikan keduanya; pemanggil yang menerima scope tanpa interface ini
menyaring hasil per baris dengan `bolehProses()`. Antrean approval berikutnya (riwayat, Konket, LKH) memakai filter yang
sama, bukan `terapkanKeQuery` (yang untuk admin unit 20 memuat UPT).

**Password awal = NIP (B-05).** Jalur aditif `AccountProvisioner::provisionDenganPasswordNip()` (milik Auth/A-09)
sengaja melewati PasswordPolicy karena keputusan user 13-10-2026 ikut legacy `L_employee.php:157-166`; hash Argon2id,
bukan md5. `provisionForPegawai()` tetap password acak yang lolos kebijakan. Penanda "wajib ganti password" menunggu
DBV-020.

**Hapus pegawai [K]** ikut legacy (keputusan user 13-10-2026; DoD B-05 menyebut soft delete): praktis hanya pegawai yang
baru ditambah tanpa data turunan yang bisa dihapus — sama dengan legacy, yang gagal ("Gagal menghapus data pegawai") bila
FK menolak. Tidak ada cascade manual untuk data turunan. Akun ber-NIP dilepas (SET NULL legacy), jadi akun role 2/6/7
tanpa NIP bisa muncul — tidak lazim, tetapi itulah hasil legacy. **Catatan sesi utama:** FK masuk `pengguna.nip` →
`pegawai` yang ditahan (DBV-019, `MigrationsDitahan/`, RESTRICT) perlu diselaraskan menjadi ON DELETE SET NULL saat
diaktifkan (unit DBV terpisah); berkas migration itu tidak diubah di sini.

**Email unik (A-01 D-6, MAKE-011):** pengajuan biodata dengan email yang dipakai akun lain → 422 `errors.email`; jalur
langsung Super Admin dan approval B-04 tetap menyimpan biodata tetapi sinkron email akun dilewati (`email_skipped` di
respons).

## Riwayat jabatan B-07, Plt/Plh & struktur organisasi B-19 (MAKE-012)

**Riwayat jabatan** = `Riwayat/Definisi/Jabatan.php` di atas engine-v1 (tanpa hook baru). Sumber [K]
`L_jabatan.php` validate_param()/set_param(). Hak akses (keputusan user 13-10-2026, pola biasa): lihat Matriks v2 (1, 2,
3, 4, 5); tulis/proses ikut legacy [K] `controllers/hr/rwy/Jabatan.php` — tambah 1, ubah 1/3, hapus 1, proses 1/3.
Alur Admin, role 1 memilih status saat edit (`form.php:59`), `roleKunciHapusDisetujui` bawaan. Ringkas:

| Aturan | Isi |
|---|---|
| Jenis jabatan | 1 Nomenklatur (jenis mutasi wajib), 2 Instansi Lain (nama instansi/unit/satker/jabatan teks wajib; kolom nomenklatur dikosongkan [V2]), 3 Plt (jabatan "PLT …", tidak masuk snapshot, TMT boleh kembar) |
| Wajib bersyarat | jenis 1/3: group, sub group, unit, satker, rumpun, jabatan (satker 39 dipekerjakan → `jabatan` teks); group JFT (2): tugas koordinasi, no induk & status JFT; group JFU (3): tugas koordinasi; koordinator/subkoordinator → `id_jabatan_koord`; pegawai PPPK (`id_jenis_pegawai` 6) → golongan PPPK |
| [V2] Konsistensi | jabatan harus di sub group terpilih (dan satker terpilih bila jabatan terikat satker); `jabatan_koordinasi` harus ada (aktif bila diubah) |
| [V2] Atasan (Q3 reviewer) | Es I–IV diturunkan dari `struktur_jabatan` periode aktif saat tambah / jabatan-satker-unit-sub group berubah (legacy `list_all_atasan`): jabatan bersimpul struktur → naik lewat `id_jabatan_atasan`; tanpa simpul → kandidat tunggal per level di unit (Es.I) / satker (Es.II–IV). Nilai yang diisi klien menang dan harus kandidat atasan posisi itu (leluhur simpul jabatan terpilih, atau simpul ber-level sesuai di unit/satker yang sama untuk jabatan tanpa simpul); Es.III/IV struktural dan `*_koord` saling eksklusif (legacy `struk_`/`koord_`). Koordinator/subkoordinator tidak diturunkan |
| Salinan nama | `nama_instansi` (web_config `nama_kementerian`), group, sub group, unit, satker, atasan es I–IV & koordinasi, jabatan + `kelas_jabatan`, jabatan koordinasi, rumpun, golongan PPPK — dari master, klien tidak bisa menimpa. Non-PPPK: golongan/PMK/MHPK dikosongkan |
| TMT unik | jenis ≠ 3, status selain 2/10, baris sendiri dikecualikan |
| Snapshot | `pegawai_mutasi_jabatan`: status 1, `jenis_jabatan != 3`, `tmtsk DESC`, `updated_at DESC`, PK DESC (disetujui user 13-10-2026). Legacy afIns `>=` = baris yang ditulis belakangan menang; afUpd/afDel `LIMIT 1` tanpa pemecah seri (tidak deterministik). `notif_date` tidak dipakai karena tambah langsung admin tidak mengisinya |
| Lampiran | 9 SK (wajib), 36 SK pembebasan sementara JFT (wajib bila jenis ≠ 2 + group JFT + status JFT 2), 41 perjanjian kerja (opsional); berkas kolom `ser_dosen`, `f_spmt`, `f_bapel` (opsional); semua 5 MB jpg/jpeg/png/gif/pdf |

Sub rumpun (MAKE-013 di atas hook MAKE-018 FE + MAKE-019 `isianMentah`): isian mentah `id_subrumpun_jabatan` (daftar ID;
JSON array, multipart `id_subrumpun_jabatan[]`, atau `''` = kosong) → kolom `subrumpun_jabatan` berisi nama dipisah `***`
(legacy set_param). Wajib minimal satu untuk jenis 1/3 (legacy validate_param :1986), opsional untuk Instansi Lain;
[V2] ID angka, ada, aktif, milik `id_rumpun_jabatan`, tidak kembar, gabungan ≤ 65535 byte (diperiksa di validate()). Tidak
dikirim saat ubah = nilai lama dipertahankan, kecuali rumpun berubah (422). FE: multiselect `loadFrom` mencocokkan nama
saat Edit; teks `jabatan` tampil & wajib bila Instansi Lain ATAU (jenis 1/3 DAN satker 39) — syarat `any`/`all` MAKE-018.

**Plt/Plh** (`Struktur/PenugasanService.php`, tabel `pegawai_plt`/`pegawai_plh`, model `PegawaiPltModel`/
`PegawaiPlhModel`) ikut legacy (keputusan user 13-10-2026): CRUD tanpa approval, tulis role 1 (userAuth ['1']), lihat
1/3/4/5 (role 3 lingkup satker akun, atau unit bila tanpa satker — list_off_plt), hapus PERMANEN dengan audit "before" dan
berkas lampiran dibuang sesudah commit; lampiran kolom `lampiran` lewat KolomBerkas. Isu cutover: kolom `lampiran` impor legacy berisi path arsip remote (`arsip/off_plt/…`) yang belum ada di storage v2 → unduh 404 sampai arsip dipindahkan.

**Struktur organisasi B-19** (`Struktur/StrukturService.php`, service `strukturService`) ikut templat legacy so_012025
(keputusan user 13-10-2026): format & dataset per pilihan kementerian/unit/satker sama dengan legacy, ID hard-code legacy
(unit 6/7/16/19, satker 37/38/41/49/50/104, jabatan 2060) jadi konstanta [K]. Pejabat pada tanggal acuan (hari ini;
siap untuk parameter bulan): definitif ikut aturan legacy (riwayat jabatan status 1 jenis 1, TMT ≤ tanggal, status
pegawai), lalu Plt [V2] hanya status 1 Aktif (legacy getPLT tanpa saring status; keputusan user 13-10-2026), lalu [V2] Plh bila tidak ada definitif maupun Plt (disetujui user). Pilihan `disabled` (unit 6) dan nilai non-string ditolak 422. [V2] simpul hanya periode struktur aktif;
format `pusat` (legacy 2025 tanpa view) dirender seperti UPT. Riwayat per bulan & ekspor PDF: MAKE-013 (lihat bagian M5).

Test: `tests/Kepegawaian/Riwayat/JabatanTest.php` (`use RiwayatBtcTrait` + `pasangEngineNyata`),
`tests/Kepegawaian/PenugasanTest.php`, `tests/Kepegawaian/StrukturOrganisasiTest.php`.

## Konket B-13, LKH B-12b, PDF (MAKE-013)

**PDF (ADR-033)** — `app/Libraries/Pdf/`: `PdfRendererInterface` (`app/Interfaces/`), `DokumenPdf` (judul, HTML badan, kop
halaman pertama, kaki setiap halaman, orientasi, ukuran), `TcpdfRenderer` (TCPDF ^6.6, LGPL-3.0-or-later, via Composer;
font inti helvetica; cache `WRITEPATH/cache/tcpdf/`), `Pdf::renderer()` / `Pdf::pakai()` (ganti renderer, mis. dompdf
cadangan atau test) / `Pdf::respon()` / `Pdf::qrImg()` (QR PNG tersemat lewat `TCPDF2DBarcode`). Services.php tidak ditambah
entri (aturan kepemilikan Fase 3). Template di `app/Views/pdf/` dan wajib `esc()`. **Deploy:** TCPDF butuh ekstensi
PHP `gd` (QR/gambar PNG) dan `curl` — dideklarasikan `ext-gd` / `ext-curl` di `composer.json` (composer install gagal bila
belum aktif). Ekspor XLSX Daftar Pegawai (MAKE-014) butuh `ext-zip` (ZipArchive; sudah di `composer.json`).

Pemakaian oleh WS-1 (contoh: tanda terima Karpeg/Karis, sudah dibuat di `Usulan/TandaTerimaUsulan.php`):

```php
$html = view('pdf/nama_template', $data);                       // data sudah di-escape di template
$isi  = Pdf::renderer()->render(new DokumenPdf('Judul', $html, kopHtml: $kop, kakiHtml: 'SIMPEG v2, ...'));
return Pdf::respon($this->response, $isi, 'nama.pdf');          // controller
```

**Konket B-13** (`Konket/KonketService.php`, tabel `absen_ijin` W/V/X/10, `KonketAturan` murni): service tipis di luar
engine. Hak (keputusan user 13-10-2026): lihat 1/2/3/6/7 + [K] role 4/5 LIHAT saja (legacy list_ad ['1','3','4'] +
detail 1–7: antrean semua pegawai + detail, tanpa Tambah/ubah/hapus/proses; Matriks v2 hanya 1/2/3/6/7); tulis/proses
legacy (pegawai: diri sendiri, ubah selama W, hapus tanpa syarat status; role 1 semua; role 3 PegawaiScope; proses 1/3
jalur proses). Peserta dinas gabungan yang diisi role 3 wajib dalam lingkupnya (422 `peserta`; legacy form_ad hanya
menawarkan pegawai satker/unit); pegawai 2/6/7 boleh memilih pegawai aktif mana pun. Aturan legacy validate_param: tanggal ≤ hari ini, mulai ≥ tanggal minimal (konket_min_date, termasuk
pengecualian bulan), akhir ≥ mulai, jenis & alasan & berkas wajib, tidak beririsan dengan W/V, konket sakit (kategori 10)
selain PTT maksimal 1 hari kerja dan tidak berturut-turut (efek legacy: hanya hari kerja sebelumnya), Dinas Luar
(kategori 8) gabungan dengan baris peserta (`id_parent`) yang ikut diubah/diproses/dihapus. [V2]: jenis dibatasi per role
di server, hapus status 10 (setara trigger arsip legacy; keputusan user), alasan wajib saat tolak, baris peserta tidak diubah/diproses sendiri, tanpa pengecualian akun
pengembang.

**LKH B-12b** (`Lkh/LkhService.php`, tabel `riwayat_lckh` 0/1/2/3/10, `LkhAturan` murni, `AtasanLangsung` = port getAL):
approver = atasan langsung (`nip_atasan`, diisi server dari snapshot jabatan: subkoordinator → Es.IV → koordinator → Es.III
→ Es.II → Es.I, pemegang aktif atau Plt; Es.I → Menteri tanpa NIP sehingga tidak bisa diajukan). Rentang tanggal = 4 hari
kerja terakhir (hari libur & akhir pekan dilewati), mulai tanggal 5 tidak sebelum tanggal 1; unik per tanggal; kegiatan
JSON sejajar; kunci tukin ubah/hapus legacy; Revisi (3) → pegawai ubah → Menunggu; LKH Disetujui hanya dapat dihapus
role 1 (legacy delete :464-472; role 3 → 403) dan tidak dapat diubah/dihapus pegawai; ubah menulis ulang nama/unit/satker
terkini (legacy sp()). Keputusan user 13-10-2026: [V2] verifikator = siapa pun ber-role 1/2/3/6/7 yang NIP-nya =
`nip_atasan` (legacy userAuth(['2']); atasan berakun 4/5/8 belum dibuka); role 1 boleh menimpa atasan dengan `nip_atasan`
(NIP pegawai aktif, 422 bila tidak) — bawaan tetap atasan otomatis; PDF hanya untuk LKH Disetujui (selain itu 422
`status`; legacy file_lckh dibuat saat disetujui), dibangkitkan saat diminta, QR kode legacy (waktu WIB), kop tanpa logo;
[V2] baris kegiatan setengah terisi 422, catatan wajib tolak/revisi, hapus status 10. Rekap LKH bawahan (legacy
list_rekap) belum dibawa.

**Struktur organisasi per bulan & PDF:** `bulan` YYYY-MM mulai **2025-01** (keputusan user 13-10-2026; legacy sejak
2022-01, tetapi bulan sebelum 2025 memakai templat so_062023/so_before_062023 yang belum di-port — TODO
`StrukturService::BULAN_AWAL`); di luar rentang 422 `bulan`. Ekspor PDF = tabel hierarki jabatan–pejabat [V2] (ADR-033
menolak wkhtmltopdf yang dipakai legacy untuk bagan).

**Tanda terima usulan** (`Usulan/TandaTerimaUsulan.php`): PDF untuk usulan Karpeg/Karis Disetujui, port tt_pdf legacy
(no tiket & kode permohonan legacy, QR dengan waktu dibuat WIB), dibangkitkan saat diminta.

**Daftar periksa cutover (L6 review MAKE-013):**

- Berkas legacy yang direferensikan path arsip lama — `absen_ijin.file_bukti`, `pegawai_plt.lampiran`,
  `riwayat_lckh.file_lckh`, `file_tanda_terima` usulan — mengembalikan 404 sampai berkasnya dimigrasikan ke penyimpanan v2
  (sertakan di skrip impor berkas atau terima sebagai arsip hanya-baca di legacy).
- PDF LKH dan tanda terima v2 dibangkitkan saat diminta (tidak memakai berkas legacy): kode/QR sama dengan legacy, tetapi
  tata letak berbeda — informasikan ke pengguna; tidak perlu menyalin `file_lckh`/tanda terima lama untuk baris baru.
- Struktur organisasi sebelum 2025-01 belum tersedia (templat legacy lama belum di-port) — gunakan legacy/arsip bila
  diperlukan sampai MAKE susulan.
- Server produksi: aktifkan ekstensi PHP `gd` dan `curl` (TCPDF) serta `zip` (ekspor XLSX); folder `WRITEPATH/cache/tcpdf/` dapat ditulis.

**Struktur organisasi per bulan** (MAKE-013): `bulan` sejak 2022-01, pejabat pada tanggal acuan; Plt/Plh & golongan
dimuat sekaligus (tanpa N+1); ekspor PDF tabel hierarki. [V2] bulan sebelum Jan 2025 tetap memakai templat so_012025 +
struktur periode aktif (templat so_062023/so_before_062023 legacy belum di-port).

Test: `tests/unit/Pdf/TcpdfRendererTest.php`, `tests/unit/Kepegawaian/{Konket,Lkh}/*AturanTest.php`,
`tests/Kepegawaian/{KonketTest,LkhTest,TandaTerimaUsulanTest,StrukturOrganisasiTest}.php`.

## Lingkup akses pegawai (`Scope/`, MAKE-009)

`PegawaiScope` = implementasi nyata `PegawaiScopeInterface` (service `pegawaiScope`). Semua endpoint Modul B memeriksa
lingkup lewat service ini (403 di luar lingkup); daftar disaring dengan `terapkanKeQuery($builder, $auth, 'p.nip')`.

| Role | Lihat | Ubah | Proses (`bolehProses`, CR-045) |
|---|---|---|---|
| 1 | semua | semua | = ubah |
| 4, 5, 8 | semua | — | = ubah |
| 2, 6, 7 | NIP sendiri | NIP sendiri | = ubah |
| 3 | jalur detail atas `pegawai_mutasi_jabatan` (pmj), lihat di bawah | sama dengan lihat | jalur proses |

Role 3, admin **punya satker** → semua jalur `pmj.id_satker` = satker admin. Admin **tanpa satker** → predikat per
jalur, **[K] ikut legacy, keputusan user 12-10-2026 (CR-045)** — legacy memang memakai predikat berbeda untuk daftar,
detail, dan proses:

| Admin tanpa satker | Daftar (`terapkanKeQuery`) | Detail/ubah riwayat, arsip, lampiran (`bolehLihat`, `bolehUbah`) | Setujui/Tolak + antrean approval (`bolehProses`) |
|---|---|---|---|
| unit 20 (`UNIT_KELEMBAGAAN`) | `pmj.id_unit IN (20, 6)` — `libraries/hr/L_employee.php:6231-6239` ("APPROVAL UPT") | `pmj.id_unit = 20` — `validateAccess_AP` | `pmj.id_unit = 20` — `libraries/hr/L_notification.php:1897-1901` (`get_na_ap_unit`), `controllers/hr/rwy/Kp.php:462-484` |
| unit 21 (`UNIT_DESTINASI`) | `pmj.id_unit = 21 OR pmj.id_satker IN (37, 38, 41)` — `L_employee.php:6221-6229` ("APPROVAL BPO") | `pmj.id_unit = 21 OR (pmj.id_unit = 7 AND pmj.id_satker IN (37, 38, 41))` — `validateAccess_AP` | `pmj.id_unit = 21 OR pmj.id_satker IN (37, 38, 41)` — `L_notification.php:1899-1901`, `rwy/Kp.php:466-470` (pola sama di `rwy/*::process` lain) |
| unit lain | `pmj.id_unit` = unit admin | sama | sama |

`validateAccess_AP` = `helpers/function_helper.php:72-98` (literal `['37', '38', '41']` di :85); konstanta
`UNIT_DESTINASI` / `UNIT_KELEMBAGAAN` / `UNIT_LAIN` / `LIST_SATKER_BPO` di `config/constants.php:312-316`, unit UPT =
literal `'6'` di `L_employee.php:6232`. Konstanta [K] `PegawaiScope::UNIT_DESTINASI`, `UNIT_KELEMBAGAAN`, `UNIT_LAIN`,
`UNIT_UPT`, `SATKER_BPO`.

Konsekuensi yang **disengaja** (sama dengan legacy): pegawai UPT (unit 6) muncul di daftar admin unit 20 tetapi
detailnya 403; pegawai satker BPO dengan unit bukan 7 bisa disetujui/ditolak admin unit 21 tetapi tidak bisa dibuka atau
diubah. `RiwayatEngine::proses()` memakai `bolehProses`; descriptor tab tetap memakai `bolehUbah` untuk `can_process`
(descriptor hanya ada bila `bolehLihat`, dan untuk pegawai yang lolos detail jalur proses = jalur ubah).

Pegawai tanpa snapshot pmj (atau satker/unit NULL) tidak cocok predikat jalur mana pun untuk admin role 3. Role 3 tidak
punya pengecualian NIP sendiri (legacy juga tidak). Satker akun dianggap "ada" bila bukan NULL dan bukan `''` **tanpa
trim** (legacy `$idSatker_AP != ''`: string spasi masuk cabang satker dan tidak cocok pmj mana pun); nilai yang ada
tetapi bukan angka kanonik ditolak, tidak jatuh ke cabang unit.

Deviasi [V2]:

- Legacy meloloskan admin role 3 **tanpa satker dan tanpa unit** (perbandingan `null == ''`) ke pegawai tanpa pmj
  atau dengan `pmj.id_unit` NULL. v2 fail-closed (admin tanpa lingkup valid → tidak ada pegawai).
- Jalur proses legacy (`rwy/*::process`, mis. `rwy/Kp.php:462-484`) **meloloskan** pegawai tanpa baris pmj, serta
  pegawai dengan `pmj.id_satker` kosong (admin bersatker) atau `pmj.id_unit` kosong (admin tanpa satker). v2 tetap
  fail-closed di **semua** jalur, termasuk `bolehProses` (CR-045).
- Sementara sampai DBV-009 (tipe `id_satker`/`id_unit` akun VARCHAR vs pmj INT): claim di-cast ke int hanya bila angka
  kanonik; bentuk non-kanonik seperti `'012'` ditolak (fail-closed). Ditinjau ulang saat DBV-009 diputus.

Hak per aksi per jenis riwayat tetap di Definisi; scope hanya menjawab "pegawai ini dalam lingkup pemanggil".

## Lampiran B-18 (`Lampiran/`, MAKE-009)

| Kelas | Isi |
|---|---|
| `LocalStorageAdapter` | `StorageAdapterInterface` di disk lokal (`writable/uploads/lampiran`); path relatif `[A-Za-z0-9._-]` per segmen, tulis atomik |
| `AttachmentService` | `AttachmentServiceInterface` atas `document_attachment` (model `DocumentAttachmentModel`, audit otomatis create/delete); `path` tidak pernah dikembalikan |
| `ValidasiBerkas` | **Butuh ekstensi PHP `zip`** (`ext-zip` di `composer.json` sejak MAKE-011; checklist deploy: pastikan ekstensi aktif di server, `composer install` gagal tanpanya). Ukuran dari disk, jenis dari ISI (finfo → `Config\Mimes`), nama tampil dibersihkan; 422 berkunci `berkas` (atau field pemanggil). Dokumen Office (MAKE-010): `docx`/`xlsx` wajib paket OOXML (`[Content_Types].xml` + bagian di `word/`/`xl/`), `doc`/`xls` wajib tanda tangan OLE — ZIP biasa bernama `.docx` ditolak walau `Config\Mimes` memetakan `application/zip` ke ekstensi itu |
| `PenjagaBerkas` | Kompensasi berkas di luar transaksi DB: setelah transaksi selesai, berkas tercatat dipertahankan hanya bila masih dirujuk `tabel.kolom = path` |
| `KolomBerkas` | Helper tabel yang menyimpan berkas di kolomnya sendiri (`riwayat_karpeg.file_1..5`, LKH, Konket) |
| `OtorisasiLampiran` | Otorisasi endpoint lampiran. Daftar/unggah: kode `jenis_rwy` → izin Definisi × role → PegawaiScope → (unggah) izin per pegawai `bolehAksiUntuk()` atas aksi `tambah`/`ubah` yang diizinkan role (403, CR-046) → `id_entri` milik NIP (bukan status 10) → kunci baris Disetujui untuk UL_PEGAWAI. Unduh/hapus: PegawaiScope lihat (403, sebelum baris dibaca) → izin aksi di Definisi berlampiran mana pun (403, sebelum baris dibaca) → baris lampiran (404) → izin Definisi → (hapus) `bolehUbah` + `bolehAksiUntuk()` atas `hapus`/`ubah` (403, CR-046) → baris riwayat bukan status 10 (404) (+ kunci baris, lampiran wajib terakhir 422 untuk hapus). Tulis diulang di dalam transaksi: `kunciUnggah()`/`kunciHapus()` (pegawai `FOR UPDATE`, baca ulang terkunci, hitung ulang lampiran wajib) |

**Lampiran wajib & "ganti".** Lampiran terakhir kode ber-`wajib` pada satu baris tidak bisa dihapus (422
`errors.berkas`); "ganti" = unggah berkas baru dulu, lalu hapus yang lama. Baris riwayat berstatus 1 Disetujui terkunci
untuk UL_PEGAWAI (`kunciBarisDisetujui()`): unggah/hapus lampirannya 403; baris status 10 Dihapus → 404 (juga unduh).
Hitungan "lampiran terakhir" diulang di dalam transaksi hapus dengan baris `pegawai` terkunci, jadi dua hapus paralel
tidak bisa menghabiskan lampiran wajib.

**Izin per pegawai di endpoint lampiran (CR-046).** Unggah dan hapus memanggil `RiwayatDefinisi::bolehAksiUntuk()` sama
dengan engine, sesudah izin role dan `bolehUbah` (status pegawai di luar lingkup tidak bocor); contoh Pendidikan: role 2
hanya selama CPNS, jadi pegawai PNS tidak bisa mengunggah/menghapus lampiran pendidikannya (403), sementara daftar dan
unduh tetap boleh. Seperti izin role, pemeriksaan ini tidak diulang di `kunciUnggah()`/`kunciHapus()` (engine juga hanya
memeriksanya sekali di `siapkan()`).

**Deviasi [V2] lampiran.** Endpoint lampiran tidak mengubah status baris riwayat (legacy mengunggah lampiran hanya lewat
form edit riwayat, yang mengembalikan status baris ke 0/1); kunci baris Disetujui untuk `DELETE` lampiran lebih ketat
dari `delete_da` legacy.

**Pemakaian dari RiwayatEngine (WS-1).** Panggil `service('attachmentService')->simpan(...)`/`hapus(...)` DI DALAM transaksi
engine; tidak perlu kode kompensasi sendiri. Setelah commit/rollback, panggil `selesaikan()` bila service-nya
`AttachmentService` (opsional: tanpa itu rekonsiliasi terjadi pada operasi lampiran berikutnya atau saat proses PHP
berakhir). Otorisasi lampiran tetap tanggung jawab pemanggil (kontrak interface); `OtorisasiLampiran` boleh dipakai ulang.

**Pemakaian `KolomBerkas`.** `simpan()` mengembalikan path untuk ditulis ke kolom; `hapus()` menandai berkas lama;
`selesaikan()` WAJIB dipanggil setelah transaksi (juga di jalur rollback) — tidak ada rekonsiliasi oportunistik karena
path baru belum dirujuk sampai kolomnya ditulis. Contoh lengkap di docblock kelas.

## B-20 penutup (MAKE-014)

- `Pegawai/PencarianLanjutan` — whitelist advsearch legacy (jenis × data terakhir/riwayat × atribut × operator), terapkan ke
  builder daftar (`EXISTS` per kriteria riwayat, status pegawai 1). `Pegawai/EksporTabel` — CSV BOM & XLSX minimal tanpa
  library baru, escape formula. Keduanya dipakai `PegawaiService::pencarian/ekspor` dengan builder & lingkup daftar.
- `Pegawai/DrhPegawai` — Cetak DRH (port `L_employee::print_drh` + `drh_pdf.php`), templat `app/Views/pdf/drh.php`, hak
  UL_ALL (2/6/7 sendiri). Dibuat langsung di controller (tanpa entri Services.php).
- `Lampiran/ArsipDataUmum` — aturan arsip Data Umum kode 37/38 (cabang tanpa Definisi di `OtorisasiLampiran`).
- `Lkh/LkhService::descriptorTab/rekap/rekapBawahan` — tab LKH Detail Pegawai dan rekap LKH bawahan.
- Daftar periksa cutover tambahan: berkas arsip Data Umum legacy (`document_attachment` 37/38, `id_entri` NULL seperti
  baris v2) terbaca di daftar tetapi unduhnya 404 sampai berkas dimigrasikan (sama dengan lampiran lain).
- [V2] pencarian lanjutan: setiap kriteria riwayat dievaluasi sendiri (`EXISTS` per kriteria); legacy menggabung kriteria
  pada baris JOIN yang sama.

## Angka kredit PermenPAN-RB 1/2023 & master `dm_ak_jf` (`AngkaKredit/`, MAKE-021, ISSUE-041)

Keputusan user 16-10-2026 (Q1 b, Q2 a, Q3). Tanpa perubahan skema: `dm_ak_jf` sudah dibuat DBV-020 (migration
`2026-10-13-100100`, PK `(id_jabatan, id_pangkat)`, FK RESTRICT ke `jabatan`/`pangkat`, diisi impor).

| Kelas | Isi |
|---|---|
| `Config\AngkaKredit` | Konstanta [K] `getAKAccu()` (`helpers/function_helper.php:2049-2165`): `$akUntukKp` (AK untuk KP per jenjang × gol. ruang), `$nilaiGolongan` (II/a = 10 … IV/e = 22), `$batasGolonganJenjang` (gol. tertinggi per jenjang). Nilai bawaan persis legacy; tanpa override `.env` (aturan berubah = ubah PermenPAN-RB = ubah kode + test) |
| `AkPermenpanrb` | Port `getAKAccu()`: `hitung(nip)` → `nip`, `nama`, `jabatan`, `satker`, `pangkat`, `gol_ruang`, `jenjang`, `current_ak`, `ak_for_kp`, `ak_kurang`, `ak_maxed`. `current_ak` = SUM `riwayat_ak_siasn.kredit_baru_total` jabatan sekarang (`pegawai_mutasi_jabatan.id_jabatan`) dengan `is_konversi = '1' OR is_integrasi = '1'` (tanpa saring status, ikut legacy), tidak negatif. `akUntukKp(jenjang, gol)`: jenjang kosong/tak dikenal → 0; gol ≤ batas jenjang → konstanta (atau 0); di atas batas → nilai terbesar jenjang itu. `ak_kurang`/`ak_maxed` ikut prediksi dashboard `L_user.php:719-740` |
| `MasterAkJfService` | CRUD `dm_ak_jf` [K] (`C_jabatan.php:1380-1530`, `Lm_jabatan.php:2277-2512`): daftar (urut sub group, jenjang, urutan pangkat, jabatan), opsi form (group jabatan 2/3 aktif, sub group, jenjang JF, pangkat aktif non-CPNS `cpns = 2`), jabatan per group/sub group/jenjang, tambah, ubah AK. **Tanpa hapus** [K] (hapus legacy tidak berfungsi) |

**Validasi master.** `id_jabatan` = jabatan aktif group 2/3; `id_pangkat` = pangkat aktif `cpns = 2`; `ak` angka ≥ 0,
paling banyak 7 digit + 3 desimal (batas validasi v2; kolom `ak` bertipe `DOUBLE`, migration `2026-10-13-100100`); pasangan kembar → 422 `errors.id_pangkat` dengan pesan legacy
("Data angka kredit JF untuk jabatan … dan gol. … sudah terdaftar di sistem …"), termasuk balapan tambah paralel
(PK → DatabaseException → 422). Ubah hanya `ak` + `updated_by` (jabatan & gol. ruang terkunci, legacy form edit).
Audit `audit_logs` entitas `dm_ak_jf`, id `"{id_jabatan}-{id_pangkat}"`.

**Kenapa bukan engine MasterData.** Engine MasterData mengandalkan PK tunggal, kolom `status` (hapus lunak 10) dan urutan;
`dm_ak_jf` ber-PK komposit tanpa status/urutan, jadi dipakai service + controller kecil.

**C-3 (hapus jabatan/pangkat yang dirujuk).** Hapus master jabatan/pangkat di engine MasterData selalu lunak (status 10),
jadi FK RESTRICT `dm_ak_jf` tidak pernah terpicu dari UI; baris `dm_ak_jf` lama tetap ada dan tetap dipakai
`Ak::akKumulatif()`. Tambah baris baru menolak jabatan/pangkat tidak aktif (422 per kolom). Hapus keras hanya lewat SQL
manual, dan FK menjawabnya dengan galat basis data — bukan jalur aplikasi.

**Pemakaian.** `Ak::turunan()` membaca `dm_ak_jf` untuk AK kumulatif (master kosong → 0; `adaMasterAkJf()` tetap sebagai
penjaga). Target KP dibaca lewat `GET pegawai/{nip}/ak/target-kp`; tab AK tidak berubah.

**Test.** `tests/unit/Kepegawaian/AngkaKredit/AkPermenpanrbKonstantaTest.php` (konstanta & `akUntukKp` murni),
`tests/Kepegawaian/AngkaKreditTest.php` (target KP dari `riwayat_ak_siasn`, izin endpoint, CRUD master kosong/terisi,
master terisi dipakai `Ak::turunan`).
