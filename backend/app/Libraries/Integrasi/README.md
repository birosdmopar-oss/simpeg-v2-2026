# Modul H — Integrasi & API Eksternal (Fase 6)

Service/business logic modul ini (`App\Libraries\Integrasi\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

Jalur B (integrasi **keluar**, `docs/fase6/6_1.md`) memakai fondasi di folder ini; modul per layanan punya README sendiri:

| Layanan | Task | Key | README | Runbook |
|---|---|---|---|---|
| BKN e-Kinerja | H-10 | MAKE-064 | `Libraries/Bkn/README.md` | `README-deploy.md` §1e |
| SIASN BKN | H-03 | MAKE-067 | `Libraries/Siasn/README.md` | `README-deploy.md` §1e |
| E-sign BSrE | H-02 | MAKE-065 | `Libraries/Esign/README.md` | `README-deploy.md` §1c |
| Push FCM HTTP v1 | H-04 | MAKE-066 | `Libraries/Push/README.md` | `README-deploy.md` §1b, §1d |

Jalur A (API masuk SIMAPI/`services/*`, job terjadwal H-11/H-12) didokumentasikan Jalur A.

## Fondasi klien keluar (MAKE-063, PRD Fase 6 §7.0.2)

- `Http\KlienHttp` + `Http\Permintaan`/`Respons`/`CurlTransport` (`FakeTransport` untuk test): satu-satunya jalan panggilan
  HTTP keluar. Retry **hanya GET** (galat transport, 429, 5xx; backoff eksponensial + jitter); POST/PUT/DELETE tidak pernah
  di-retry (BP-3). Redirect tidak diikuti. Panggilan tidak boleh di dalam transaksi DB bisnis (BP-4).
- `Http\Redaktor`: sensor kunci/header sensitif, token Bearer/Basic, nilai literal `Permintaan::$rahasia` (NIK, passphrase,
  NIP, id PNS — juga bentuk ter-encode), dan angka 18 digit. Parameter rahasia diberi `#[\SensitiveParameter]` agar tidak
  tercetak di backtrace.
- `Log\IntegrasiLog` (`IntegrasiLogInterface::catat(CatatanIntegrasi)`, kontrak dikunci CR-091): satu baris `integrasi_log`
  per percobaan + satu ringkasan per proses. URL, query, header, dan body tidak dicatat; `created_at` UTC. Gagal menulis log
  tidak menggagalkan operasi (BP-7).
- `KonfigurasiIntegrasi::pastikan()`: validasi kunci `.env` per pemakaian (AC-K.4); pesan hanya menyebut nama kunci.
- `AkunSistemIntegrasi` (DBV-027 §4, Z-1 opsi c): akun `sistem.integrasi` untuk pelaku `updated_by`; dibuat command, bukan
  seed; `id()` dipanggil di awal setiap job penulis (akun belum siap → gagal eksplisit).

## Command

| Command | Isi |
|---|---|
| `integrasi:siapkan-akun-sistem` | Buat akun sistem integrasi (idempoten; akun menyimpang ditolak, tidak diperbaiki). Sesudah impor `pengguna`, sebelum cron integrasi aktif. |
| `integrasi:status [--jam=24]` | Agregat sukses/gagal per layanan dari `integrasi_log` + peringatan periode Tukin tanpa SKP periodik (F6-15) + status TLS BSrE. Exit 1 bila ada kegagalan/peringatan (pemantauan cron). |
| `integrasi:bersihkan-log [--hari=180] [--dry-run]` | Hapus `integrasi_log` lebih tua dari retensi (`integrations.logRetensiHari`). Mingguan. |
| `integrasi:uji-koneksi <bkn\|siasn\|esign\|fcm>` | Uji koneksi NYATA, manual (bukan bagian `check.sh`). `fcm` = token OAuth baru + `messages:send` `validate_only` ke topik uji, tanpa kirim ke perangkat (CR-101). |

Opsi CLI diurai `Libraries\Cli\OpsiCli` (`--k=v` dan `--k v`; opsi salah → exit 7). Koneksi DB command berjalan lama dibuka
lewat `Libraries\Cli\KoneksiCli`.

## Aturan bersama Jalur B

- Tanpa panggilan jaringan nyata di test; fixture sintetis (NIP/NIK/nama fiktif).
- Kredensial dan alamat hanya di `.env` server; `backend/.env.example` berisi kunci kosong.
- Command penulis memakai `GET_LOCK` per jenis (run kedua → exit 1 tanpa panggilan API), transaksi pola proyek, dan
  ringkasan `integrasi_log` berisi jumlah saja (tanpa NIP/nama/token).

## Auth antar-sistem — klien layanan dan token SIMAPI (`Klien/`, MAKE-058, ADR-036)

Fondasi Jalur A untuk SIMAPI dan `services/*` (PRD Fase 6 §7.0.1). Filter `klienLayanan:<hak>` (HTTP Basic) dan `simapiToken` ada di
`app/Filters/`; konfigurasi di `Config\KlienLayanan`; identitas klien request berjalan di service `klienSaatIni`.

| Berkas | Lapisan | Isi |
|---|---|---|
| `AllowlistIp`, `HakKlien` | murni | normalisasi/pencocokan IP·CIDR (kosong ditolak, gagal tertutup); jenis ↔ hak (audit B4) |
| `PembangkitKredensial` | acak | rahasia (`random_bytes`), token (`random_int`), `password_hash`, SHA-256 token |
| `PenyimpanKlien` | ber-DB | buat/rotasi/status/daftar/bersihkan-token; satu transaksi + audit satu koneksi; pencabutan token |
| `PengesahKlien` | ber-DB | Basic: `password_verify` selalu dijalankan, status, allowlist, hak |
| `TokenSimapi` | ber-DB | terbit (SHA-256 PK) dan validasi (elemen kedua header, bentuk dibatasi sebelum hash, kedaluwarsa/dicabut) |
| `KlienSaatIni`, `ResponsAuthKlien` | — | identitas klien per request; amplop gagal per grup (SIMAPI 200 "02", services 401 int 2) |

Command: `php spark integrasi:klien buat|rotasi|nonaktif|aktifkan|hapus|daftar|bersihkan-token`. Rahasia ditampilkan sekali. Test:
`tests/unit/Integrasi/Klien/*`, `tests/database/Integrasi/{KlienLayananTest,FilterAuthKlienTest,IntegrasiKlienCommandTest}.php`.

## Kompatibilitas legacy — SIMAPI dan `services/s_dm` (`Simapi/`, `Layanan/`, MAKE-059)

Endpoint masuk dengan path dan kontrak legacy (PRD Fase 6 §6.1/§6.3, H-05..H-07). Route: `Config/RoutesSimapi.php`
(`simapi/v1/*`, di luar `api/v1`, tanpa `jwt`/`cors`); controller tipis `Controllers/Simapi/*`; logika di kelas penyaji di bawah ini
(query builder, tanpa SQL string). `services/s_dm`: sub-group `s_dm` (`klienLayanan:dm`) di `Config/RoutesServices.php` bersama,
controller `Controllers/Services/SDmController`, amplop `ResponsLayanan` (A6; status INT, `data: []` eksplisit saat gagal = legacy).

| Berkas | Isi |
|---|---|
| `Simapi/ResponsSimapi` | amplop SIMAPI HTTP 200 `{"status":"01"|"02","msg","data"}` (status STRING; `data` gagal = `[]` [K], termasuk gagal auth lewat `ResponsAuthKlien`) |
| `Simapi/Nilai` | bentuk nilai legacy: string/null seperti `row_array()`; field ber-`stripslashes` legacy → "" bila NULL |
| `Simapi/PenyajiPegawai` | 25 method `a_employee` (`METHOD` = daftar + pesan "Data … tidak tersedia"), port `fixUnitName()` |
| `Simapi/PenyajiDm` | `a_dm/group_jabatan|sub_group_jabatan|jabatan|struktur_jabatan` |
| `Simapi/PenyajiSinkron` | `a_sinkron/ambil_riwayat_mutasi` (seluruh baris kecuali status 10 [V2]) |
| `Layanan/PenyajiSDm` | `services/s_dm/*` (baris `unit`/`satker` utuh + `unit_str`/`satker_str`) |

Fixture kontrak (sintetis, diturunkan dari kode legacy, rujukan baris di tiap entri): `tests/_support/Integrasi/Simapi/*.json`.
Test: `tests/database/Integrasi/{SimapiAuthDmSinkronTest,SimapiEmployeeTest,ServicesSDmTest}.php`.

Keputusan dan penyimpangan:

- **Auth.** `a_auth/token` = filter `klienLayanan:simapi` lalu `TokenSimapi::terbitkan($idKlien, KlienSaatIni::sidikRahasia())`; token baru
  per handshake (F6-05), `data` hanya `{"token"}` (Q6-02); `KlienTidakAktif` (balapan nonaktif/rotasi) → "Authentication failed"
  seragam (ADR-036 §4). Endpoint lain `simapiToken` — termasuk `a_dm/jabatan` yang di legacy tanpa auth (F6-03, AC-07.1) dan
  `ambil_riwayat_mutasi` ([V2] pesan token legacy endpoint itu "Invalid Token or IP Address"/"Token Expired" diganti "Token is not valid"
  seragam filter, AC-05.1; masuk daftar pemberitahuan re-handshake PN-4). `services/s_dm/*` seluruhnya
  `klienLayanan:dm` (legacy `p_*` publik).
- **Type klien** = `klien_layanan.jenis`. `a_dm` selain `jabatan` hanya type 2; type lain menerima `{"status":"01","msg":"","data":[]}` [K].
  `a_employee` tanpa batas type (batas type `change_history` milik MAKE-060).
- **Tidak dibuat:** `a_sinkron/ambil_seluruh_pengguna` (Q6-03 = A), `services/s_dm/p_web_config` (F6-10), `a_employee/change_history`
  (MAKE-060) → 404.
- **NIP** [K]: kosong → "Invalid paramater" (ejaan legacy; legacy tanpa segmen NIP = galat PHP), tidak ada di `pegawai` → "NIP not valid";
  status 10 TIDAK ditolak (ikut legacy).
- **Nilai** [K]: pemetaan teks (jenis kelamin, status pernikahan, Aktif/Tidak Aktif, jenis jabatan "Nomenlaktur" ejaan legacy, status JFT,
  jenis kursus/seminar, jenis diklat), kode "lain" (99/9999/9999999/9999999999/98/1185, tanda jasa `LAIN-LAIN`), NEM untuk jenjang 1/2/3.
  `pegawai_diklat` tidak punya `jenis_diklat` (sama dengan D1), jadi `pelatihan_struktural_terakhir` selalu memakai nama diklat.
- **[V2]** `email` = akun aktif dengan `id_pengguna` terkecil (`pengguna.nip` di v2 UNIQUE, legacy GROUP BY acak). Perhatikan
  `pengguna.status` ENUM('0','1') dibandingkan sebagai string.
- **[V2] `foto` (keputusan Horii = A, pola Q6-38):** `PenyajiPegawai::urlFoto()` = URL absolut `simapi/v1/a_employee/foto/{nip}` bila kolom
  `foto` terisi DAN berkasnya ada di storage v2, selain itu "". Endpoint itu memakai `simapiToken` (token salah → amplop "02" "Token is not
  valid") dan sumber foto yang sama dengan `api/v1/pegawai/{nip}/foto` (`BiodataService::fotoSistem`, tanpa lingkup pengguna). NIP asing,
  tanpa foto, berkas tidak ada, atau bukan gambar → 404 seragam (body kosong); mime dari isi, `nosniff`, `no-store`, nama generik
  `foto_{nip}.{ext}`; path tidak pernah keluar; log hanya kode hasil. Cakupan NIP = aturan "NIP not valid" (termasuk status 10).
  Gap (daftar re-handshake PN-4): klien wajib mengirim token SIMAPI saat mengambil foto, bentuk URL berubah dari legacy; foto legacy yang
  belum dimigrasi ke storage v2 tidak tersedia (URL "").
- **[V2]** urutan baris: riwayat tanpa ORDER BY di legacy (`riwayat_pendidikan`, `riwayat_pelatihan`, `riwayat_kp`) diurut PK ASC; setiap
  urutan diberi kunci sekunder PK (legacy bergantung urutan fisik). Baris status 10 tidak dikirim: `struktur_jabatan` (`A_dm.php:274` tanpa
  filter; legacy tak punya kolom status dan menghapus keras, jadi status 10 v2 = baris yang di legacy sudah tidak ada) dan
  `ambil_riwayat_mutasi` (legacy tanpa filter, hapus keras `L_jabatan.php:1712`; v2 hapus lunak). `p_get_unit`/`p_get_satker` (`S_dm.php:175`,
  `:247`): legacy JUGA hapus lunak status 10 (`L_jabatan.php:2580-2593`, `:2818-2831`) dan mengirimnya — menyaring status 10 di v2 adalah
  DEVIASI [V2] (unit/satker terhapus tidak dikirim ke klien). Daftar lain memfilter `status = 1` persis legacy.
- **[K]** daftar riwayat dan `ambil_riwayat_mutasi` tanpa LIMIT, ikut legacy (riwayat per NIP kecil; `ambil_riwayat_mutasi` memang
  seluruh tabel untuk sinkron E-Talenta). Bila volume menjadi masalah, paginasi = perubahan kontrak (perlu keputusan).
- **[V2]** teks tidak di-`stripslashes` ulang (impor UP-1 sudah menormalkan, Impor README #5).
- **[K] bug legacy B-9 (keputusan Horii 04-11, Q6-36): ikut legacy.** `detil` tidak memilih nama `atasan_es_3(_koord)`/`atasan_es_4(_koord)`
  (`AE:278`), jadi bagian `unit_es_3`/`unit_es_4` yang berasal dari atasan selalu ""; hanya unit dari jabatan sendiri (sub group 3/4 atau
  koordinator/subkoordinator, lewat `fixUnitName`) yang terisi. Dikunci `SimapiEmployeeTest::testDetilUnitEs34IkutLegacyB9`.
  *Opsi perbaikan v2 (TIDAK diimplementasi, menunggu keputusan):* pilih juga `pmj.atasan_es_3_koord, pmj.atasan_es_3, pmj.atasan_es_4_koord,
  pmj.atasan_es_4` di kueri `detil` (versi `_koord` didahulukan, sama dengan `AE:306/312/316`) lalu terapkan `fixUnitName`.

## `services/s_stat` — rekomendasi KP/KGB/pensiun & daftar TB/IB/Karpeg/Kariskarsu (`Stat/`, MAKE-061, H-08)

API tarik MuDig (PRD Fase 6 §7.6, F6-09 **ikut legacy persis**), port `controllers/services/S_stat.php` + `libraries/hr/L_employee.php`
(`get_all_kp` :2416-2720, `get_all_pensiun` :1324-1376, `get_all_kgb` :4095-4220) + `helpers/function_helper.php` (`getEselon` :164,
`dateToUser` :972, `getTanggalPensiun` :2400-2418). Endpoint & kolom: `Controllers/Services/README.md`.

| Berkas | Lapisan | Isi |
|---|---|---|
| `KalenderStat` | murni | parse `!Y-m-d`; semantik legacy `strtotime('')` → 1970-01-01, `new DateTime('')` → hari ini; selisih bulan `y*12+m` |
| `RekomendasiKp` | murni | deteksi reguler (`% 4`, plafon pendidikan/eselon, periode 1 Apr/1 Okt) dan percepatan struktural (TMT struktural terawal + 1 th); kolom turunan MuDig (`mker_*_new`, `pangkat_new` = `order+1`) |
| `RekomendasiKgb` | murni | acuan = maks(KGB terakhir, TMT CPNS) PNS / maks(KGB, TMT jabatan) PPPK; > 36 bln → +4 th lalu +2 sampai ≥ hari ini; 24–36 → hari ini; < 24 → +2 th |
| `RekomendasiPensiun` | murni | BUP = `Kalkulasi\Pensiun::batasUsia()` (identik); tanggal = legacy `lahir + BUP th + 1 bln` → `Y-m-01` **dengan luapan bulan** |
| `PenyajiStat` | murni | format legacy `d M Y` / `M Y` / `F` (Inggris, locale C), eselon, nama bergelar, **satu-satunya pembangun URL berkas** (`urlBerkas`), daftar putih jenis/kolom unduh, nama unduhan generik |
| `StatService` | ber-DB | populasi lewat query builder (prefix-aman), `WHERE IN` per 500 NIP, TMT struktural terawal berbatch, arsip `document_attachment` yang **ada di storage v2** (bukan HTTP fetch), `list_*`, pembacaan berkas untuk endpoint unduh (404 seragam) |
| `Layanan/ResponsLayanan` | — | amplop bisnis INT `services/*` (bersama A4/B4b): sukses 1, gagal 2, HTTP 200; `tidakDitemukan()` 404 |

"Hari ini" diinjeksi (tanggal bisnis WIB, Q4); kelas murni dijaga `tests/unit/Integrasi/Stat/StatMurniTest.php` (daftar eksplisit).

Keputusan & anomali legacy yang DIPERTAHANKAN (prinsip Horii "ikut legacy"; reviewer 05-11-2026):
- **Format tanggal** keluaran = `dateToUser()` tanpa format → `date("d M Y")` ("01 Apr 2026"), `periode_kp` "Apr 2026", `kp_bl`
  "April" (bulan Inggris). Keluaran MuDig (`rekomendasi_kp_uns`) dan KGB memakai tanggal mentah `Y-m-d` seperti legacy.
- **`[K] perilaku legacy get_all_kp`**: `gol_ruang` kosong/tak dikenal memberi urutan `null` dan `null < n` = true → pegawai
  tanpa golongan ikut terdeteksi reguler bila plafon pendidikannya ada, dan yang golongannya tak dikenal ikut percepatan bila
  bereselon; TMT pangkat kosong dibaca tahun 1970 (siklus `% 4` bisa pas). Dikunci test, tidak "diperbaiki".
- **`[K] bug legacy Q6-37`**: `rekomendasi_kp_uns` dengan `id_unit`/`id_satker` tidak kosong → `data: []` (`status` 1, `msg` "").
  Opsi B ditolak; opsi upgrade C hanya dicatat di PRD.
- `rekomendasi_kgb` hanya mengeluarkan `arsip_last_kgb` (arsip KP diambil legacy tetapi tidak dikeluarkan); `minMonth/maxMonth`
  legacy tidak dipakai dan tidak di-port.
- `mker_new` saat masa kerja lama 0 dihitung dari digit NIP ke-9..14 sebagai tahun-bulan CPNS [K]; NIP tak membentuk tanggal
  sah → hari ini ([V2] penjaga; legacy fatal).

Deviasi [V2] (teknis/keamanan, F6-03/F6-09/Q6-38):
- Auth wajib (`klienLayanan:stat`) untuk semua endpoint, termasuk yang publik di legacy; 401 amplop INT.
- Arsip dicek di storage v2 (`StorageAdapterInterface::ada()`), bukan `exif_imagetype` + HTTP 200; URL = endpoint unduh klien
  `services/s_stat/berkas/…` (Q6-38 opsi A, Horii 05-11-2026). Kolom `file_*` pada `list_*` tidak mengeluarkan path internal
  (URL atau kosong). Path impor legacy yang belum ada di storage v2 → kosong / 404.
- Urutan KGB `tmtsk_kgb` ASC lalu NIP (legacy `array_multisort` pada kolom `tgl_pensiun` yang tidak ada → tak terurut); `list_tb`/
  `list_ib` urut PK ASC (legacy tanpa ORDER BY).
- Waktu "sekarang" tanpa jam (legacy `new DateTime()` berjam): hanya berbeda bila tanggal acuan KGB + 4/+2 tahun jatuh tepat
  hari ini.
- Nilai `id_unit`/`id_satker`/`id_jenis_kp` dsb. dikeluarkan sebagai string (seperti CI3); NULL → "".

Perbedaan dengan kalkulasi v2 yang ada (PRD §7.6 — dicatat, TIDAK disamakan):

| Topik | `Kepegawaian/Kalkulasi/*` (input riwayat per pegawai) | `Integrasi/Stat/*` (deteksi populasi legacy) |
|---|---|---|
| KP | `KenaikanPangkat::proyeksiReguler()`: TMT terakhir + 4 th → periode 1 Apr/1 Okt berikutnya; `proyeksiPercepatan()`: TMT struktural + 1 th | tahun TMT berjarak kelipatan 4 dari **tahun berjalan** (bukan +4 dari TMT), plafon pendidikan/eselon, bulan TMT → periode tahun ini/depan; percepatan dibandingkan dengan reguler |
| KGB | `KenaikanGajiBerkala::proyeksiBerikutnya()`: acuan pendahulu per TMT, jatuh tempo + 2 th, toleransi "hari ini" | tiga cabang bulan (>36 → +4/+2 bertingkat, 24–36 → hari ini, <24 → +2), acuan maks(KGB, CPNS/jabatan) |
| Pensiun | `Pensiun::tanggalPensiun()` menjepit ke awal bulan (31-01-1968 → 2026-02-01) | luapan bulan legacy dipertahankan (→ 2026-03-01); BUP sama (`batasUsia` dipakai ulang) |

Perbaikan review MAKE-061 (05-11-2026): `sp_admin_deputi` `riwayat_tb/ib` adalah path berkas legacy (`L_tb.php:799`,
`L_ib.php:619`) → masuk daftar putih kolom berkas (URL/kosong), bukan path mentah (B1); `rekomendasi_kp_uns` diurut
`gol_ruang_old` DESC, `id_satker` ASC [K S_stat:477-479] (P1); `lu_ppen` kp_uns selalu `null` [K S_stat:722] (P2); urutan kunci
`arsip_*` mengikuti assignment berantai legacy (kanan → kiri) dan `arsip_skp` {Y-2, Y-1} (P3); kolom `file_*` `list_tb`/`list_ib`
yang kosong diisi URL lampiran v2 `document_attachment` kode 46–50 (`BerkasStudi`, tag = nama kolom; `file_surat_*` kode 47/50)
agar MuDig menerima berkas pasca-cutover — jenis unduh `lampiran_tb`/`surat_tb`/`lampiran_ib`/`surat_ib` (P4); filter
`id_unit`/`id_satker` tidak di-trim dan parameter array dianggap terisi (S4); NULL kolom tetap `null` seperti CI3 (S5);
`pegawai_ak` tanpa `id_riwayat_ak` dilewati (S3); arsip disaring per entri sebelum cek storage (S6); endpoint unduh mencatat
jenis + kode hasil saja (S7). Batas ukuran: isi berkas dibaca utuh ke memori lewat `StorageAdapterInterface::baca()` (tanpa
streaming; adapter belum punya API ukuran) — berkas unduhan s_stat adalah lampiran ≤ 5 MB sesuai `AturanLampiran`/`BerkasStudi`;
batas/stream dicatat sebagai tindak lanjut bila adapter mendukung (S8 reviewer). Tambahan review ulang: pemecah tie urutan
`nama` ASC lalu `nip` dan ORDER BY deterministik pada query pensiun/KGB (S1); `arsip_skp` format MuDig hanya memakai baris SKP
terakhir per tahun [K S_stat:581] (S2); log endpoint unduh hanya mencatat jenis dalam daftar putih (`lain` untuk lainnya) (N2).
Batasan yang diterima (N1): untuk slot TB/IB yang punya lebih dari satu lampiran v2, hanya lampiran terbaru per slot yang
dikeluarkan di `file_*` (legacy memang satu path per kolom).

Gap untuk re-handshake PN-4 / runbook (dicatat, bukan dikerjakan di sini): MuDig wajib mengirim Basic auth juga saat mengunduh
arsip dan bentuk URL arsip berubah (dulu URL berkas terbuka); berkas impor yang belum dimigrasi ke storage v2 tidak dapat diunduh
(kolom kosong/404); hak `stat` hanya untuk klien jenis layanan (MuDig); per satker/unit tetap kosong (Q6-37).

