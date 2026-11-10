# Impor legacy → v2 (MAKE-056)

Alat impor resmi data legacy ke DB v2 (keputusan Horii 29-10-2026, P-06 opsi 2). Alat ini dipakai untuk UP-1, dan UP-1
sekaligus menjadi gladi cutover. Prosedur operasionalnya ada di `docs/up1/Runbook_UP-1.md`.

```
php spark impor:legacy --sumber=<skema_salinan> --zona-sumber=WIB|UTC --konfirmasi=<db_tujuan>
                       [--tier=1|semua] [--dry-run] [--keputusan=<berkas.json>]
                       [--sumber-siasn=<skema_salinan_siasn_simpeg>]
```

- **Tujuan**: koneksi `default` (`.env`), yaitu DB scratch kosong hasil `php spark migrate --all`. Tujuan **wajib** DB
  scratch di mesin lokal DBA, bukan server dev atau produksi. Penjaga tidak bisa mengenali host, jadi command
  mencetak `Tujuan: host:port / nama_db` (tanpa user/password) sebelum memeriksa `--konfirmasi`. Periksa baris itu
  dulu; jalankan `--dry-run` pertama kali agar tidak ada yang tertulis bila tujuannya salah.
- **Sumber**: skema salinan legacy [K] apa adanya (hasil `docs/up1/sql/up1-ekstraksi.sh`), di server dan akun yang sama.
- **Sumber SIASN** (`--sumber-siasn`, DBV-027 K-9): salinan skema `siasn_simpeg` (`REF_UNOR` + 7 tabel referensi) dari
  **snapshot yang sama** dengan sumber utama, di server dan akun yang sama; dibaca langsung, tidak disalin ke skema
  `simpeg01`. Tanpa opsi ini `siasn_ref_unor` dan `siasn_referensi` dilaporkan `dilewati: tanpa --sumber-siasn`; tabel
  tabel tier SIASN (T14) lain tetap diimpor dari sumber utama. Salah satu dari 8 tabel tidak ada → gagal. Nama tabel sumber dicari tanpa
  beda huruf besar/kecil (`lower_case_table_names`). Skema ini juga melewati penjaga di bawah. Contoh:
  `php spark impor:legacy --sumber=up1_legacy_salinan --sumber-siasn=up1_legacy_salinan_siasn --zona-sumber=WIB
  --konfirmasi=<db_tujuan> --dry-run`. Ekstraksinya: `docs/up1/sql/up1-ekstraksi.sh` (`UP1_DB_SIASN`) atau
  `up1-ambil.sh`.
- **Penjaga** (`PenjagaTujuan`): tujuan ditolak bila namanya ada di `Config\ImporLegacy::$tujuanTerlarang`, berawalan
  `prod`, sama dengan sumber, atau `--konfirmasi` tidak sama persis. Port tidak dipakai sebagai syarat.
- **`--zona-sumber`** wajib diisi dan tidak punya nilai bawaan (GAP-2). Nilainya dikonfirmasi DBA sebelum dijalankan.
- **Opsi** boleh ditulis `--k nilai` atau `--k=nilai`; diurai `App\Libraries\Cli\OpsiCli` (CR-080): opsi tak dikenal,
  ganda, tanpa nilai, atau argumen berlebih ditolak (EXIT_USER_INPUT) sebelum koneksi dibuat.
- **Galat DB tidak boleh diam-diam** (R-1 review PR #32). Command membuat kedua koneksi dengan `DBDebug = true` apa
  pun environment-nya. Mesin juga memeriksa setiap hasil: kueri/tulis `false`, `transStatus()` gagal, atau
  `transCommit()` false → `ImporGagal` dan tier di-rollback, tanpa pesan "selesai". Di dalam transaksi, CI4 bisa
  menandai status gagal tanpa melempar exception walau `DBDebug = true`; pemeriksaan status menangkap kasus ini.
- **Keluaran** hanya berisi nama tabel, jumlah baris, dan kode aturan. Galat DB dicetak sebagai kelas + kode saja,
  karena pesannya bisa memuat isi baris.

## Cara kerja

- Kedua koneksi diawali sesi R4 (`time_zone = '+00:00'` + strict, DBV-011 §2.4 #6, §3.2). Sejak CR-086 driver aplikasi
  `App\Database\MySQLi` sudah memasang `time_zone = '+00:00'` saat connect di kedua koneksi (sumber salinan legacy dan
  tujuan); sesi R4 alat tetap dipasang sebagai jaminan dan diverifikasi (`NOW() = UTC_TIMESTAMP()`). Skrip
  `mysqldump`/`mysql` CLI UP-1 (`docs/up1/sql`) tidak memakai driver ini, jadi tidak terpengaruh.
- Impor berjalan per tier. Setiap tier punya satu transaksi tujuan; bila ada galat, tier itu di-rollback.
- Pra-cek per tabel:
  - tabel sumber ada (kecuali yang `opsional`);
  - tabel tujuan ada dan kosong (kecuali `bolehSeed`);
  - kolom tujuan NOT NULL tanpa DEFAULT ada di sumber.
- Pra-cek UNIQUE (juga saat `--dry-run`): setiap indeks UNIQUE tujuan selain PK dicek di PHP terhadap baris seed dan
  baris yang akan ditulis, dengan pembanding seperti `utf8mb4_unicode_ci` (huruf besar/kecil dan spasi akhir
  diabaikan). Dua baris master legacy yang hanya beda spasi tengah menjadi identik sesudah normalisasi nama → gagal
  `Bentrok UNIQUE <tabel>.<indeks>` (tanpa nilai baris) sebelum ditulis. Galat tulis di dalam transaksi menyebut tabel
  dan kode galat (review DBV PR #32).
- Baris dibaca per batch, berurut PK (keyset), lalu ditransformasi di PHP dan ditulis dengan `insertBatch`. Transformasi
  di PHP dipilih karena `stripslashes` versi SQL hanya benar di MySQL 8 (DBV-011 §2.2 #3), sedangkan CI juga menguji
  MariaDB.
- `--dry-run` menjalankan pra-cek dan transformasi seluruh baris (termasuk berkas keputusan dan pembanding seed), tanpa
  menulis apa pun.
- Impor tidak idempoten: tujuan yang sudah berisi membuat impor gagal-cepat. Bila impor diulang, buat DB baru atau
  lanjutkan dengan `--tier` untuk tier yang belum terisi.

## Aturan umum per kolom (`PengimporLegacy`)

Kolom yang disalin adalah irisan kolom sumber dan kolom tujuan (nama [K]). Kolom [V2] yang tidak ada di sumber diisi
DEFAULT dari DB.

| # | Aturan | Rujukan |
|---|---|---|
| 1 | Pemetaan `nilai` dari berkas keputusan didahulukan | GAP-6, log 2.8 |
| 2 | Tanggal/stempel nol: kolom nullable → NULL; NOT NULL → pengganti dari `tanggal_nol` di berkas keputusan, kalau tidak gagal | R3 |
| 3 | DATETIME tujuan: sumber DATETIME + WIB → −7 jam; sumber DATETIME + UTC dan sumber TIMESTAMP → apa adanya; DATE/TIME tidak diubah; kolom `waktuBisnis` (waktu kejadian, bukan stempel) tidak diubah | R1a, R1b, R2 |
| 4 | Kolom FK tujuan (dibaca dari `information_schema`): `0`/`''` → NULL | DBV-011 §2.10, B-01/B-02 §6 #4 |
| 5 | Teks: `stripslashes` PHP, kecuali `tanpaStripslashes` | DBV-011 §2.2 #3 |
| 6 | `kolomNama`: whitespace → satu spasi, lalu trim | DBV-011 §2.2 #2 |
| 7 | `status` di luar `statusDomain` (bawaan 1/2/10) dan kolom `domain` lain (CHECK v2) → berkas keputusan, kalau tidak gagal; NULL di kolom nullable dibiarkan | DBV-011 §2.4 #5, B-01/B-02 §6 #3 |
| 8 | `kolomKode`: karakter tak terlihat (U+200B–U+200F, U+2060, U+FEFF) dibuang lalu trim PHP, jumlah baris dicatat di laporan (`<kolom> karakter tak terlihat dibuang`); teks melebihi panjang kolom tujuan → gagal (mis. NIP > 30) | DBV-011 §2.2 #7, B-01/B-02 §6 #2, CR-089 (UP1-06: NIP berawalan U+200C, legacy `unicode_ci` mengabaikannya) |
| 9 | Rujukan FK dicek per batch terhadap tabel induk tujuan: yatim → gagal dengan jumlah, kecuali `yatimKeNull` → NULL + dicatat sebagai `koreksi`. Dry-run tidak memeriksa rujukan | DBV-019 audit = 0, B-01/B-02 §6 #6 |

## Aturan khusus tabel (`DefinisiTabel`)

- `urutan`: kolom urutan [V2] yang tidak ada di sumber diisi 1..n per lingkup, berurut PK (DBV-011 §2.4 #5).
  Contoh: `sub_group_jabatan.order` per `id_group_jabatan`.
- `kolomTunda`: FK ke tabel yang sama ditulis NULL lebih dulu, lalu diisi setelah semua baris tabel masuk.
  Contoh: `jabatan_koordinasi.id_atasan_es_3_koord`. UPDATE ini ikut menulis kolom ber-`ON UPDATE CURRENT_TIMESTAMP`
  (dibaca dari `information_schema.COLUMNS.EXTRA`) dengan nilainya sendiri, supaya `updated_at` hasil R1a tidak
  tertimpa waktu impor.
- `bolehSeed` (GAP-3): untuk master yang sudah di-seed migration. Bila id dan nama sama, baris dilewati. Bila berbeda,
  impor gagal dengan menyebut tabel dan id.
- `kunciUrut`: PK komposit untuk baca per batch, mis. `dh_online (nip, tgl)`.
- `tanpaStripslashes`: kolom teks yang disalin mentah; `['*']` = semua kolom tabel (GAP-8: `bkn_periode_ekinper`,
  `riwayat_skp_periodik`).
- `waktuBisnis`: kolom DATETIME berisi waktu kejadian, mis. `absen_ijin.date_start/date_end`,
  `riwayat_skp_periodik.waktu_dinilai` (GAP-9). Kolom ini tidak dikonversi
  R1a, karena tanggal izin akan bergeser sehari bila dikurangi 7 jam.
- `khusus`: aturan per baris; boleh mengembalikan `__koreksi` (list kode) untuk dicatat. Contoh: `web_config` lewat `WebConfigImport` (G-09 §5); hasil SKIP/REVIEW tidak diimpor
  dan dihitung sebagai `dibuang`.
- `sumber` / `skemaSiasn` / `petaKolom` / `konstanta` (B3): tabel sumber bernama lain (dibaca dari skema
  `--sumber-siasn` bila `skemaSiasn`), kolom sumber bernama lain (`siasn_referensi.id_siasn` ← `ID`), dan kolom tujuan
  bernilai tetap (`siasn_referensi.jenis`). Beberapa sumber boleh mengisi satu tujuan; laporan memakai kunci
  `tujuan<SUMBER` (mis. `siasn_referensi<GOLONGAN_ID`).
- `praPindai` (B3): lintasan baca pertama atas seluruh sumber (hanya kolom aturan), juga saat dry-run. Hasilnya daftar
  PK yang tidak diimpor beserta kode aturan; boleh menggagalkan impor dengan jumlah saja. Dipakai
  `AturanSiasn::skpPeriodik` dan `AturanSiasn::uploadArsip`.
- `pascaImpor` (B3): hitung INFO di tujuan sesudah tier di-commit (bukan dry-run), dicatat sebagai koreksi.
- Pra-cek B3 (sebelum apa pun ditulis, juga dry-run): kolom yang disebut di `petaKolom` wajib ada di sumber; kunci baca
  sumber wajib unik sesudah normalisasi untuk tabel ber-`sumber`/`skemaSiasn` atau ber-PK `kolomKode` (ganda → gagal
  dengan jumlah); `praPindai` dijalankan di fase ini. Sesudah tiap tabel, `sumber = ditulis + dilewati + dibuang`
  (rekonsiliasi) — selisih → gagal.

## Berkas keputusan

Contoh ada di `docs/up1/keputusan-impor.contoh.json`. Isi finalnya diputuskan DB Validator. Bagian yang dikenal:
`status`, `nilai`, `tanggal_nol`, `catatan`. Nilai di luar domain yang belum dipetakan → gagal-cepat.

## Tier

| Tier | Isi | Status |
|---|---|---|
| 1 | Master: `agama`, `jenis_status`, `provinsi`/`kabupaten_kota` (sentinel = seed), `pangkat`, `jenis_kp`, `jenjang_pendidikan`, `unit` (`is_upt` 2 → 0 = G-02 Bagian 4 #9, dicatat; nilai lain di luar 0/1 → berkas keputusan, CR-089), `satker` (domain `is_upt` 0/1, CR-089), `group_jabatan`, `sub_group_jabatan`, `rumpun_jabatan`, `kelas_jabatan`, `jenjang_jf` (opsional), `jabatan`, `jabatan_koordinasi`, `periode_struktur_jabatan`, `struktur_jabatan`, `jenis_pegawai`, `gol_pppk`, `jenis_libur`, `hari_libur`, `jenis_cuti`, `web_config` | M1 |
| 2 | `pegawai` (domain `flag_update` 0–3, `jenis_kelamin` 1/2; NIP & kode wilayah di-trim) | M2 |
| 3 | `riwayat_mutasi_jabatan`, `riwayat_kp` (status 0/1/2/3/10), snapshot `pegawai_mutasi_jabatan`/`pegawai_kp` (`id_riwayat_*` yatim → NULL), `pegawai_plt`, `pegawai_plh` | M2 |
| 4 | `absen_ijin` (status W/V/X/10, `affect_tukin`/`jenis_dinas` ber-domain, rentang izin = waktu bisnis), `riwayat_keluarga`, `detail_anak`, `bkn_periode_ekinper`, `riwayat_skp_periodik` (kunci UNIQUE ganda → lihat T4 DBV-027 di bawah) | M3, B3 |
| 5 | `lokasi_presensi`, `dh_online` (baris tanpa jam masuk & pulang dibuang `DBV-023 A2`; `sumber` NULL) | M3 |
| 6 | `pegawai_sisa_cuti` (duplikat `(nip, tahun)` → id terkecil, saldo negatif → 0), `riwayat_cuti` (6 kolom ber-domain), `riwayat_tb`, `riwayat_tb_perpan` | M3 |
| 7 | MAKE-069 master sisa: wilayah `kecamatan`/`kelurahan` (seed, `order` urut nama), `kantor`, kursem, `bidang_pendidikan`/`jurusan_pendidikan` (`order` urut nama), G-06 (`jenis_konket.old_id` K-M9a + audit WAJIB_0 `absen_ijin.kategori`), G-02b sisa, sertifikasi, `dm_ak_jf`, FAQ (sanitizer + `content_stripped` ulang, K-M9c), `jenis_rwy` (seed) | MAKE-069 |
| 8 | `pengguna`: `id` legacy, `password_decode` tidak di-SELECT, md5 → `password_legacy`, `must_change_password` (role 1; role 2/6 password = NIP), NIP wajib ada di `pegawai`, tujuan wajib kosong (akun sistem dibuat sesudahnya) | MAKE-069 |
| 9 | `pegawai_hist` (`flag_update = 1`, satu per NIP id terbesar, K-M9b), `pegawai_foto`, `dm_user_lokasi_presensi` (status 1, JSON wajib terurai, K-M6) | MAKE-069 |
| 10 | Riwayat non-Tukin (16 tabel) lalu `document_attachment` — **transaksi per tabel + `--jejak`/`--lanjut`** (K-M8) | MAKE-069 |
| 11 | Snapshot `pegawai_*` lain — transaksi per tabel (K-M8); `id_riwayat_*` NOT NULL yatim → gagal | MAKE-069 |
| 12 | Layanan sisa DBV-022 §5 (`jenis_layanan`/`jenis_pa` seed, `question_category`, petugas, `pa_layanan`, notifikasi KT, IB, PMK, `esign_hist`) | MAKE-069 |
| 13 | **Tiket lama Halo Simpeg (F-02, MAKE-086)**: `disiplin_ticket` ← `inbox`, `disiplin_ticket_msg` ← `inbox_msg` (rename 1:1, PK tetap). Semua isi = tiket (penulis tunggal `L_support.php`); `inbox` v2 tidak ditulis (Q7-28). Sortir & eskalasi: bagian **Tiket lama (F-02)** di bawah | MAKE-086 |
| 14 | Tabel kerja SIASN Fase 6 (DBV-027 §13.3): `siasn_ref_unor` ← `REF_UNOR`, `siasn_referensi` ← 7 tabel referensi (skema `--sumber-siasn`, disalin mentah), `double_riwayat_kp`, `siasn_upload_arsip` (semua status) | B3 |

**Aturan DBV-027 §13.3 (B3).** Pesan dan laporan hanya jumlah.

- `riwayat_skp_periodik` (K-3/K-4), UNIQUE `uq_rsp_pns_periode_skp` (`id_pns`, `periode_id`, `skp_id`): per kunci
  ganda dipertahankan baris yang dibaca Tukin (`status = 1` dan `hasil_akhir IS NOT NULL`, string kosong = terisi),
  lalu id terkecil; sisanya `dibuang` `DBV-027 K-3 kunci ganda`. Status dinilai sesudah berkas keputusan. Grup yang
  memuat NIP berbeda → gagal dengan jumlah grup (DBV memutus per kasus). Kunci ber-NULL tidak dikelompokkan.
- `siasn_upload_arsip` (K-6/K-7): semua status diimpor, `id_rw`/`id_da` apa adanya. Kunci UNIQUE `uq_sua_rw_da`
  ganda → satu per kunci, prioritas `status = 1` lalu id terbesar (`DBV-027 K-6 kunci ganda`). `id_da` NULL → gagal.
  Kolom respons BKN (`resp_*`) dan `error_msg` disalin mentah; `arsip_path`/`arsip_name` ikut `stripslashes` agar sama
  dengan `document_attachment.path` hasil impor (DBV-029).
  `status = 3` → `2` dengan `error_msg` "Terhenti saat cutover — periksa di SIASN sebelum mengantre ulang"
  (`DBV-027 K-7 status 3→2`). `id_da`/`id_rw` tanpa baris di `document_attachment` / `riwayat_mutasi_jabatan`
  (`Rw Jabatan`) / `riwayat_diklat` (`Rw Sertifikasi`) → INFO; bila tabel induknya masih kosong, kodenya menyebut
  "belum diimpor (kosong)", artinya tier SIASN (T14) dijalankan sebelum riwayat dan lampiran (MAKE-069) diimpor.
- `double_riwayat_kp` (K-8): apa adanya; NIP tanpa `pegawai` → gagal (rujukan yatim).
- Tidak diimpor: `siasn_status_pegawai` (dibangun job data-utama, K-5), `siasn_simpeg.dsp`, `firebase_token`/`fb_token`
  (PRD Fase 6 AC-01.2).
- **Urutan:** tier SIASN (T14, selalu terakhir) dijalankan sesudah `document_attachment` dan tabel riwayat sumber diimpor dengan PK tetap (MAKE-069).
  Tiket lama (F-02) = T13 (MAKE-086). T12b Halo Simpeg (DBV-028) dan tier Fase 7 lain disisipkan sebelum tier SIASN.

**Tiket lama (F-02, MAKE-086; PRD Fase 7 v2.0 §5.2, Q7-28; `AturanTiketLama`).** Kriteria dari KODE legacy (bukan data):
bentuk tiket sah = pola `insert_inbox`/`set_param_conversation` (`L_support.php:937-1095,1505-1597`). `praPindai`
mengklasifikasi seluruh baris (juga saat dry-run):

| Kelas | Syarat | Hasil |
|---|---|---|
| T-OK / M-OK | Tiket: `sender > 0`, subjek & NIP terisi, punya ≥ 1 pesan. Pesan: induk ada, `msg_sender > 0`, isi terisi | diimpor |
| T-NORM / M-NORM | Seperti OK, tetapi flag di luar CHECK (`status` 0/1, `allow_reply` 0/1, `is_read`/`is_read_off` 1/2, `notify_pic` 0/1/2; pesan `show_notif`/`is_read` 0/1) | bagian `status` berkas keputusan (aturan #7); tanpa pemetaan → gagal |
| A-ESK | Tiket pengirim ≤ 0 / subjek kosong / NIP kosong / tanpa pesan | **eskalasi per id** |
| M-ESK | Pesan tanpa induk (`id_inbox` NULL atau induk tidak ada) / pengirim ≤ 0 / isi kosong | **eskalasi per id**; tanpa induk hanya boleh `__buang` |
| M-IKUT | Pesan yang induknya diputus `__buang` | dibuang otomatis |

- Eskalasi tidak ditebak. Putusan Horii/DBV per id ada di bagian `baris` berkas keputusan:
  `"disiplin_ticket.f02": {"<id_inbox>": "impor"|"__buang"}`, `"disiplin_ticket_msg.f02": {"<id_inbox_msg>": …}`.
  Ambigu yang belum diputuskan → gagal dengan jumlah per kode, tidak ada yang ditulis.
- `php spark impor:sortir-inbox --sumber=<skema> --keluaran=<berkas.json> [--keputusan=…]` (baca-saja, tanpa DB tujuan)
  menulis manifest: jumlah per kelas + daftar eskalasi `id → kode`. Isinya hanya id dan kode, tanpa subjek, pesan,
  NIP, atau nama; simpan di folder kerja di luar repo.
- Rekonsiliasi per tabel (`sumber = ditulis + dilewati + dibuang`) = DoD "total sebelum = sesudah".
- INFO sesudah impor (`pascaImpor`): pengirim tanpa akun v2, NIP tanpa `pegawai` (disalin; tanpa FK, DQ-4/DQ-6).
- `id_question_category` yatim → gagal: FK legacy `SET NULL` tidak menyisakan yatim, jadi yatim = T12 belum dijalankan.
- Eksekusi data nyata hanya saat cutover (runbook, izin Horii). Fase 7 = dry-run atas fixture sintetis
  (`tests/Impor/ImporLegacyTiketLamaTest.php`).
- Review WS-2 (DB Validator, SETUJU tanpa blok) — tindak lanjut non-blok:
  - **N1:** keputusan untuk id yang tidak ambigu / tidak ada di snapshot dilaporkan sebagai `keputusan_tak_terpakai`
    di manifest dan INFO `F-02 INFO keputusan tiket/pesan tak terpakai` di laporan impor.
  - **N2:** tiket yang SEMUA pesannya diputus `__buang` dinilai ulang menjadi A-ESK "tanpa pesan" (eskalasi).
  - **N3:** manifest menghitung kelas T-NORM/M-NORM dan `di_luar_domain` per `<tabel>.<kolom>` → nilai → jumlah, agar
    bagian `status` berkas keputusan bisa disiapkan sejak dry-run.
  - **N4:** `stripslashes` bawaan berlaku untuk semua kolom teks tiket/pesan (bukan hanya `msg`), diterima apa adanya
    (R8-5, DBV-011 §2.2 #3).
  - **N5:** di salinan legacy `closed_by` selalu NULL (penulis `close_inbox` tidak mengisinya); catatan untuk Fase 8.
  - **N6:** INFO `F-02 INFO kategori NULL` = jumlah tiket berkategori NULL (kategori dihapus, FK legacy SET NULL).

**Transaksi per tabel (DBV-029 K-M8, `DaftarTabel::TIER_PER_TABEL`).** Seluruh tier dipra-cek dulu; tiap tabel satu
transaksi, counter dan INFO sesudah commit tabel itu, lalu dicatat di `--jejak=<berkas>` (folder kerja, satu per DB
tujuan; wajib kecuali dry-run). Gagal di tengah: tabel selesai tetap ter-commit, pesan menyebut jumlah selesai/gagal/belum.
`--tier N --lanjut` melewati tabel yang tercatat dan jumlah/PK maks/keputusan tujuannya masih sama; tabel tidak cocok →
gagal tanpa menghapus apa pun. Mengulang tabel yang sudah selesai = pulihkan cadangan DB sebelum tier (bukan TRUNCATE).

Sesudah setiap tier di-commit, counter AUTO_INCREMENT tujuan disamakan dengan sumber bila lebih kecil (DBV-011 §2.7;
tidak pernah diturunkan). PK TINYINT dengan counter ≥ 128 tidak disalin dan dicatat `PERIKSA`. Langkah ini DDL, jadi
berjalan di luar transaksi tier. Bila galat terjadi di langkah ini, data tier tetap ada; ulangi dengan `--tier`
berikutnya setelah counter dibereskan manual.

## Keputusan DBV

Dijawab DB Validator di komentar PR #32 (30-10-2026, diverifikasi di MariaDB 10.4). Isi keputusan berupa kode/nilai
masuk ke berkas keputusan lokal (contoh: `docs/up1/keputusan-impor.contoh.json`); keputusan berupa aturan sudah
diterapkan di `DaftarTabel`.

| No | Butir | Bawaan alat sekarang | Yang diminta dari DBV | Keputusan DBV (30-10-2026) |
|---|---|---|---|---|
| GAP-2 | Jam server legacy untuk kolom DATETIME (DBV-011 §3.6 #3) | Tanpa bawaan: `--zona-sumber=WIB\|UTC` wajib diisi | Konfirmasi WIB atau UTC sebelum UP-1 dijalankan | WIB, tetapi **dibuktikan dulu** di salinan (baca-saja): `SELECT NOW(), UTC_TIMESTAMP(), @@system_time_zone, @@global.time_zone;` lalu bandingkan `NOW()` dengan jam dinding WIB. `NOW()` = WIB → `--zona-sumber=WIB`; = UTC → `UTC`. Dicatat di lembar hasil bagian 1 (runbook langkah 12). Salah arah = semua stempel bergeser 14 jam |
| GAP-4 | `dm_user_lokasi_presensi` / `user_lokasi_presensi` (Mapping "tidak dimigrasi" vs G-03 §6.2 #6) | Tidak diimpor; tidak dibaca jalur Tukin | Keputusan untuk cutover (di luar UP-1) | Tidak diimpor untuk UP-1 (tidak dibaca jalur Tukin). Keputusan cutover ditunda sampai jelas siapa yang membacanya; bila dipakai, jadikan tier baru, jangan ditempel ke tier 5 |
| GAP-6 | Isi berkas keputusan: status/nilai di luar domain (`status`, `<tabel>.<kolom>` ber-CHECK, mis. `absen_ijin` `H`/angka lama, `pegawai.jenis_kelamin`), `hari_libur.id_jenis_libur` NULL, pengganti tanggal nol di kolom NOT NULL (R3) | Gagal-cepat bila belum dipetakan | Pemetaan per nilai dari hasil audit salinan (log 2.8) | Mekanisme disetujui dengan 4 syarat: (1) tidak ada pemetaan yang diisi coder; semuanya dari audit skema salinan dan dicatat di lembar hasil bagian 3; (2) status NULL/di luar domain dipetakan ke nilai yang mereproduksi perilaku legacy (kueri legacy menyaring `status = 1` → NULL → 2); `{"null": 1}` di berkas contoh hanya contoh; (3) pengganti tanggal nol di kolom NOT NULL = sentinel `2000-01-01 00:00:00`; (4) `absen_ijin.status` `H`/angka lama dipetakan hanya setelah jumlah per nilai dihitung dari salinan, dan pemetaan ke `V` disertai alasan dari kode legacy |
| GAP-8 | `stripslashes` untuk tabel T2–T6 (DBV-011 §2.2 #3 baru mendaftar master) | `stripslashes` PHP pada semua kolom teks di bawah (form legacy `addslashes` [K]); NIP dan kode tidak terpengaruh | Daftar kolom yang disimpan MENTAH (`tanpaStripslashes`). Kandidat kuat: `riwayat_skp_periodik` dan `bkn_periode_ekinper`, karena terisi dari sinkron BKN, bukan dari form | Bawaan diterima. Seluruh kolom `bkn_periode_ekinper` dan `riwayat_skp_periodik` masuk `tanpaStripslashes` (`['*']`, terisi dari sinkron BKN). Konsekuensinya dicek lewat butir T-05 runbook; bila nilai ber-backslash dipakai rekap, lapor ke DBV sebelum perbandingan |
| GAP-9 | Kolom DATETIME berisi waktu kejadian (`waktuBisnis`, tidak dikonversi R1a) | `absen_ijin.date_start`, `absen_ijin.date_end` (diterima reviewer CR 29-10-2026) | Konfirmasi daftar, termasuk apakah `riwayat_skp_periodik.waktu_dinilai` stempel (dikonversi) atau waktu bisnis | Daftar `absen_ijin.date_start/date_end` benar. `riwayat_skp_periodik.waktu_dinilai` juga `waktuBisnis` (nilai dari BKN, belum dipakai kode v2); bila kelak dipakai sebagai stempel, konversi sekali lewat migration |
| GAP-10 | Kolom domain/status yang tidak punya CHECK v2 | `bkn_periode_ekinper.status`, `detail_anak`, `pegawai_sisa_cuti`, `riwayat_tb_perpan`, snapshot: disalin apa adanya | Konfirmasi tidak perlu normalisasi | Tidak perlu normalisasi; disalin apa adanya. Syarat: bagian WAJIB_0 audit DBV-022/023 dijalankan sesudah impor (runbook langkah 16) dan nilai aneh dicatat sebagai kelompok (b) |
| GAP-11 | Pembanding seed (GAP-3) memakai id + nama, tanpa beda huruf besar/kecil | `jenis_cuti`, sentinel `provinsi` 99 / `kabupaten_kota` 9999 | Konfirmasi aturan; bila nama legacy berbeda dengan seed, pilih mana yang dipakai | Disetujui. Bila nama legacy berbeda dari seed, nama seed v2 yang dipakai dan selisihnya dicatat di lembar hasil, lewat bagian `nilai` berkas keputusan (mis. `"jenis_cuti.nama_jenis_cuti": {"Cuti Besar Lama": "Cuti Besar"}`; pemetaan nilai berjalan sebelum pembanding seed). Seed migration tidak diubah |

Kolom teks T2–T6 yang terkena `stripslashes` bawaan (GAP-8; NIP dan kode di-trim, tidak diubah). Dua baris
`bkn_periode_ekinper` dan `riwayat_skp_periodik` di bawah dicatat sebagai riwayat jawaban: sejak keputusan DBV kedua
tabel itu disalin mentah (`tanpaStripslashes: ['*']`).

| Tabel | Kolom |
|---|---|
| `pegawai` | `id_provinsi_lahir`, `id_kabupaten_kota_lahir`, `nip_lama`, `nama`, `glr_awal`, `glr_akhir`, `provinsi_lahir`, `provinsi_lahir_lain`, `kabupaten_kota_lahir`, `kabupaten_kota_lahir_lain`, `agama`, `npwp`, `nik`, `bpjs_kes`, `bpjs_ket`, `no_taspen`, `no_hp`, `jenis_kerabat`, `no_telp_kerabat`, `jenis_pegawai`, `jenis_status`, `foto`, `keterangan`, `id_pns_siasn` |
| `riwayat_mutasi_jabatan` | `no_sk`, `gol_pppk`, `nama_instansi`, `group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `atasan_es_1`, `atasan_es_2`, `atasan_es_3`, `atasan_es_3_koord`, `atasan_es_4`, `atasan_es_4_koord`, `jabatan`, `jabatan_koord`, `jabatan_lain`, `rumpun_jabatan`, `subrumpun_jabatan`, `no_induk_jft`, `ser_dosen`, `f_spmt`, `f_bapel`, `keterangan`, `id_rw_siasn`, `siasn_error`, `reason_note` |
| `riwayat_kp` | `no_sk`, `no_pertek_bkn`, `jenis_kp`, `gol`, `ruang`, `gol_ruang`, `pangkat`, `keterangan`, `id_rw_siasn`, `siasn_error`, `reason_note` |
| `pegawai_mutasi_jabatan` | `no_sk`, `gol_pppk`, `nama_instansi`, `group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `atasan_es_1`, `atasan_es_2`, `atasan_es_3`, `atasan_es_3_koord`, `atasan_es_4`, `atasan_es_4_koord`, `jabatan`, `jabatan_koord`, `jabatan_lain`, `rumpun_jabatan`, `subrumpun_jabatan`, `no_induk_jft`, `ser_dosen`, `f_spmt`, `f_bapel`, `keterangan`, `id_rw_siasn` |
| `pegawai_kp` | `no_sk`, `no_pertek_bkn`, `jenis_kp`, `gol`, `ruang`, `gol_ruang`, `pangkat`, `keterangan`, `id_rw_siasn` |
| `pegawai_plt` | `keterangan`, `lampiran` |
| `pegawai_plh` | `keterangan`, `lampiran` |
| `absen_ijin` | `jenis_konket`, `alasan`, `file_bukti`, `file_bukti_2`, `file_bukti_3`, `file_bukti_4`, `file_bukti_5`, `status`, `reason_note` |
| `riwayat_keluarga` | `kota_perkawinan`, `nama_pasangan`, `keterangan`, `reason_note` |
| `detail_anak` | `nama`, `tempat_lahir`, `keterangan` |
| `bkn_periode_ekinper` | `id`, `nama`, `bulan`, `periode_awal`, `periode_akhir`, `batas_pengisian`, `jenis_periode` |
| `riwayat_skp_periodik` | `id_pns`, `periode_id`, `skp_id`, `skp_penilaian_id`, `nama`, `skp_unor_id`, `skp_unor`, `skp_unor_induk`, `skp_jabatan`, `hasil_kerja`, `perilaku_kerja`, `hasil_akhir`, `pegawai_atasan_id`, `pegawai_atasan_nip`, `pegawai_atasan_nama`, `pegawai_atasan_unor_id`, `pegawai_atasan_unor`, `pegawai_atasan_jabatan`, `pegawai_atasan_golru`, `pegawai_penilai_id`, `golru`, `f_arsip_1`, `f_arsip_2` |
| `riwayat_cuti` | `nama`, `pangkat`, `gol_ruang`, `jabatan`, `satker`, `nama_jenis_cuti`, `satuan_lama_cuti`, `no_telp_saat_cuti`, `alamat_saat_cuti`, `file_bukti`, `keterangan`, `nip_atasan_langsung`, `nama_atasan_langsung`, `jabatan_atasan_langsung`, `reason_note_al`, `nip_yang_menyetujui`, `nama_yang_menyetujui`, `jabatan_yang_menyetujui`, `reason_note_pyb`, `file_sc_unsign`, `file_sc_signed_bef`, `file_surat_cuti`, `keterangan_petugas_approval`, `ketua_tim`, `reason_note` |
| `riwayat_tb` | `nama`, `gol_ruang`, `pangkat`, `jabatan`, `unit`, `satker`, `pendidikan_dasar_singkat`, `jenjang_pendidikan_singkat`, `institusi_pendidikan`, `jurusan_pendidikan`, `lokasi_pendidikan`, `lembaga_yang_mendanai`, `up`, `tembusan`, `file_sp`, `file_1`, `file_2`, `file_3`, `keterangan`, `rs_admin_upt`, `sp_admin_deputi`, `rs_admin_deputi`, `ds_tb`, `no_ds_tb`, `perihal_ds_tb`, `no_sk`, `file_surat_tb`, `reason_note` |
| `riwayat_tb_perpan` | `file_sperpan` |

## Akun dan berkas lampiran (MAKE-069)

Urutan sesudah impor tier (operator; tanpa alamat atau kredensial di repo):

1. `php spark integrasi:siapkan-akun-sistem` — akun sistem integrasi dibuat SESUDAH T8 `pengguna` (Z-1).
2. Bila perlu: `php spark akun:bootstrap-admin --username=<u> --nama=<n> --konfirmasi=<db>` di terminal interaktif.
   Password acak tampil sekali, tidak masuk log/berkas/audit; akun wajib ganti password saat login pertama. Admin
   legacy hasil impor tetap bisa login dengan password lamanya (lalu wajib ganti, K-M2).
3. `php spark lampiran:manifest --keluaran=<KERJA>/manifest.json --konfirmasi=<db>` — path unik dari kolom berkas
   (`ManifestLampiran::KOLOM`) sesudah impor (sudah `stripslashes`), dinormalisasi seperti `fixArsipPath`
   (`PathLegacy`); path tidak sah hanya dihitung. Manifest berisi nama berkas nyata: folder kerja di luar repo dan di
   luar folder sinkron cloud.
4. `php spark lampiran:tarik --manifest=<KERJA>/manifest.json [--sumber=arsip|aplikasi]` dengan alamat dasar diisi
   operator lewat `--dasar-arsip`/`--dasar-aplikasi` atau env `LAMPIRAN_DASAR_ARSIP`/`LAMPIRAN_DASAR_APLIKASI` (K-M4
   opsi 1). Berurutan dengan `--jeda-ms` (bawaan 200), TLS verify, redirect tidak diikuti, retry terbatas 5xx/timeout,
   404 dicatat. Respons halaman login dihitung `butuh_login`; alat berhenti di `--ambang-login`. Bisa diulang untuk
   delta saat freeze (checksum sama → dilewati). Agen/coder TIDAK menjalankan langkah ini ke server mana pun.
5. `php spark lampiran:periksa --manifest=<KERJA>/manifest.json` — jumlah ada/hilang/beda checksum per kolom.

Storage v2 (`LocalStorageAdapter`) membaca path legacy apa adanya (K-M3 opsi A); tulis berkas baru tetap memakai aturan
segmen ketat.

## Di luar cakupan

- `penyesuaian_tukin` diisi lewat CRUD (DBV-024 §5).
- `pengguna`/`token` tidak diimpor. Untuk UP-1, DBA membuat satu akun uji role 1 lewat INSERT manual dengan hash
  `PASSWORD_ARGON2ID` (GAP-5b opsi (c); langkahnya di `docs/up1/Runbook_UP-1.md` langkah 15).
- **GAP cutover (bukan MAKE-056):** jalur pembuatan akun admin awal (bootstrap) belum ada. Pertanyaan ini untuk
  Horii/DBV.
- GAP cutover `dm_user_lokasi_presensi` (GAP-4) tidak dibaca jalur Tukin. Keputusannya diserahkan ke DBV saat cutover.
- Tabel non-Tukin (riwayat lain, `document_attachment`, `pengguna`, Halo Simpeg) dan pemindahan berkas: paket MAKE-069
  (rencana: `docs/migrasi/MAKE-069-rencana.md` di branch `docs/make-069-rencana`).

## Test

- `tests/unit/Impor/AturanImporTest.php`: aturan murni (transformasi, keputusan, penjaga).
- `tests/unit/Impor/UrutanTierTest.php`: tier SIASN selalu `TIER_MAKS` dan sesudah tabel induk rujukannya.
- `tests/Impor/ImporLegacyTiketLamaTest.php` (F-02): kelas sortir, eskalasi wajib diputus, manifest seimbang, `inbox` v2 = 0, command `impor:sortir-inbox`.
- `tests/Impor/ImporLegacyTier1Test.php`, `ImporLegacyTier23Test.php`, `ImporLegacyTier456Test.php`,
  `ImporLegacySiasnTest.php` (B3: kunci ganda SKP, tier SIASN, skema `--sumber-siasn` huruf besar),
  `ImporLegacyGagalDbTest.php` (galat DB dengan `DBDebug` false/true; `db-isolasi-penuh`), di atas
  `Tests\Support\Impor\ImporLegacyTestCase`: skema sumber sintetis `<db_test>_lgc` dibuat dari struktur v2
  (`CREATE TABLE … LIKE`) lalu diubah seperti legacy (kolom dibuang, tipe diubah, CHECK dilepas). Skema itu selalu
  di-DROP di tearDown, juga saat test gagal, dan saat setUp gagal di tengah jalan.
