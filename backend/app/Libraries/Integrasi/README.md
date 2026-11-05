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
(query builder, tanpa SQL string). `services/s_dm`: penyaji sudah ada; controller + sub-group `s_dm` di `Config/RoutesServices.php`
ditambahkan sesudah kerangka `services` (berkas bersama + `ResponsLayanan`, dibuat Fable) ada di main.

| Berkas | Isi |
|---|---|
| `Simapi/ResponsSimapi` | amplop SIMAPI HTTP 200 `{"status":"01"|"02","msg","data"}` (status STRING; `data` gagal = `[]` [K], termasuk gagal auth lewat `ResponsAuthKlien`) |
| `Simapi/Nilai` | bentuk nilai legacy: string/null seperti `row_array()`; field ber-`stripslashes` legacy → "" bila NULL |
| `Simapi/PenyajiPegawai` | 25 method `a_employee` (`METHOD` = daftar + pesan "Data … tidak tersedia"), port `fixUnitName()` |
| `Simapi/PenyajiDm` | `a_dm/group_jabatan|sub_group_jabatan|jabatan|struktur_jabatan` |
| `Simapi/PenyajiSinkron` | `a_sinkron/ambil_riwayat_mutasi` (seluruh baris kecuali status 10 [V2]) |
| `Layanan/PenyajiSDm` | `services/s_dm/*` (baris `unit`/`satker` utuh + `unit_str`/`satker_str`) |

Fixture kontrak (sintetis, diturunkan dari kode legacy, rujukan baris di tiap entri): `tests/_support/Integrasi/Simapi/*.json`.
Test: `tests/database/Integrasi/{SimapiAuthDmSinkronTest,SimapiEmployeeTest}.php`.

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
- **[V2] ⚑ MENUNGGU HORII** `foto` = `PenyajiPegawai::urlFoto()` (satu fungsi), sementara `site_url('api/v1/pegawai/{nip}/foto')` bila kolom
  `foto` terisi, selain itu "". Endpoint itu butuh JWT pengguna, jadi klien SIMAPI belum bisa mengambilnya; usul reviewer = endpoint unduh
  khusus klien layanan (pola Q6-38). Test mengunci bentuk sekarang.
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
