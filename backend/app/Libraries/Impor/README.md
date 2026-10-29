# Impor legacy → v2 (MAKE-056)

Alat impor resmi data legacy ke DB v2 (keputusan Horii 29-10-2026, P-06 opsi 2). Alat ini dipakai untuk UP-1, dan UP-1
sekaligus menjadi gladi cutover. Prosedur operasionalnya ada di `docs/up1/Runbook_UP-1.md`.

```
php spark impor:legacy --sumber=<skema_salinan> --zona-sumber=WIB|UTC --konfirmasi=<db_tujuan>
                       [--tier=1|semua] [--dry-run] [--keputusan=<berkas.json>]
```

- **Tujuan**: koneksi `default` (`.env`), yaitu DB scratch kosong hasil `php spark migrate --all`.
- **Sumber**: skema salinan legacy [K] apa adanya (hasil `docs/up1/sql/up1-ekstraksi.sh`), di server dan akun yang sama.
- **Penjaga** (`PenjagaTujuan`): tujuan ditolak bila namanya ada di `Config\ImporLegacy::$tujuanTerlarang`, berawalan
  `prod`, sama dengan sumber, atau `--konfirmasi` tidak sama persis. Port tidak dipakai sebagai syarat.
- **`--zona-sumber`** wajib diisi dan tidak punya nilai bawaan (GAP-2). Nilainya dikonfirmasi DBA sebelum dijalankan.
- **Keluaran** hanya berisi nama tabel, jumlah baris, dan kode aturan. Galat DB dicetak sebagai kelas + kode saja,
  karena pesannya bisa memuat isi baris.

## Cara kerja

- Kedua koneksi diawali sesi R4 (`time_zone = '+00:00'` + strict, DBV-011 §2.4 #6, §3.2).
- Impor berjalan per tier. Setiap tier punya satu transaksi tujuan; bila ada galat, tier itu di-rollback.
- Pra-cek per tabel:
  - tabel sumber ada (kecuali yang `opsional`);
  - tabel tujuan ada dan kosong (kecuali `bolehSeed`);
  - kolom tujuan NOT NULL tanpa DEFAULT ada di sumber.
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
| 3 | DATETIME tujuan: sumber DATETIME + WIB → −7 jam; sumber DATETIME + UTC dan sumber TIMESTAMP → apa adanya; DATE/TIME tidak diubah | R1a, R1b, R2 |
| 4 | Kolom FK tujuan (dibaca dari `information_schema`): `0`/`''` → NULL | DBV-011 §2.10, B-01/B-02 §6 #4 |
| 5 | Teks: `stripslashes` PHP, kecuali `tanpaStripslashes` | DBV-011 §2.2 #3 |
| 6 | `kolomNama`: whitespace → satu spasi, lalu trim | DBV-011 §2.2 #2 |
| 7 | `status` di luar `statusDomain` (bawaan 1/2/10) dan kolom `domain` lain (CHECK v2) → berkas keputusan, kalau tidak gagal; NULL di kolom nullable dibiarkan | DBV-011 §2.4 #5, B-01/B-02 §6 #3 |
| 8 | `kolomKode`: trim PHP; teks melebihi panjang kolom tujuan → gagal (mis. NIP > 30) | DBV-011 §2.2 #7, B-01/B-02 §6 #2 |
| 9 | Rujukan FK dicek per batch terhadap tabel induk tujuan: yatim → gagal dengan jumlah, kecuali `yatimKeNull` → NULL + dicatat sebagai `koreksi`. Dry-run tidak memeriksa rujukan | DBV-019 audit = 0, B-01/B-02 §6 #6 |

## Aturan khusus tabel (`DefinisiTabel`)

- `urutan`: kolom urutan [V2] yang tidak ada di sumber diisi 1..n per lingkup, berurut PK (DBV-011 §2.4 #5).
  Contoh: `sub_group_jabatan.order` per `id_group_jabatan`.
- `kolomTunda`: FK ke tabel yang sama ditulis NULL lebih dulu, lalu diisi setelah semua baris tabel masuk.
  Contoh: `jabatan_koordinasi.id_atasan_es_3_koord`.
- `bolehSeed` (GAP-3): untuk master yang sudah di-seed migration. Bila id dan nama sama, baris dilewati. Bila berbeda,
  impor gagal dengan menyebut tabel dan id.
- `khusus`: aturan per baris. Contoh: `web_config` lewat `WebConfigImport` (G-09 §5); hasil SKIP/REVIEW tidak diimpor
  dan dihitung sebagai `dibuang`.

## Berkas keputusan

Contoh ada di `docs/up1/keputusan-impor.contoh.json`. Isi finalnya diputuskan DB Validator. Bagian yang dikenal:
`status`, `nilai`, `tanggal_nol`, `catatan`. Nilai di luar domain yang belum dipetakan → gagal-cepat.

## Tier

| Tier | Isi | Status |
|---|---|---|
| 1 | Master: `agama`, `jenis_status`, `provinsi`/`kabupaten_kota` (sentinel = seed), `pangkat`, `jenis_kp`, `unit`, `satker`, `group_jabatan`, `sub_group_jabatan`, `rumpun_jabatan`, `kelas_jabatan`, `jenjang_jf` (opsional), `jabatan`, `jabatan_koordinasi`, `periode_struktur_jabatan`, `struktur_jabatan`, `jenis_pegawai`, `gol_pppk`, `jenis_libur`, `hari_libur`, `jenis_cuti`, `web_config` | M1 |
| 2 | `pegawai` (domain `flag_update` 0–3, `jenis_kelamin` 1/2; NIP & kode wilayah di-trim) | M2 |
| 3 | `riwayat_mutasi_jabatan`, `riwayat_kp` (status 0/1/2/3/10), snapshot `pegawai_mutasi_jabatan`/`pegawai_kp` (`id_riwayat_*` yatim → NULL), `pegawai_plt`, `pegawai_plh` | M2 |
| 4–6 | Riwayat/keluarga/SKP-BKN/`absen_ijin`, lokasi + `dh_online`, cuti/TB/sisa cuti; counter AUTO_INCREMENT | M3 |

## GAP terbuka

- **GAP-8:** `stripslashes` untuk tabel pegawai/riwayat (T2–T3) belum tercatat per tabel di dokumen DBV. DBV-011 §2.2 #3
  baru mendaftar master. Bawaan alat adalah stripslashes, karena form legacy memakai `addslashes` [K]. Pengecualian per
  kolom (`tanpaStripslashes`) menunggu keputusan DBV.

## Di luar cakupan

- `penyesuaian_tukin` diisi lewat CRUD (DBV-024 §5).
- `pengguna`/`token` tidak diimpor. Akun role 1 sintetis dibuat DBA setelah impor (GAP-5).
- GAP cutover `dm_user_lokasi_presensi` (GAP-4) tidak dibaca jalur Tukin. Keputusannya diserahkan ke DBV saat cutover.

## Test

- `tests/unit/Impor/AturanImporTest.php`: aturan murni (transformasi, keputusan, penjaga).
- `tests/Impor/ImporLegacyTier1Test.php`, `ImporLegacyTier23Test.php` (`db-isolasi-penuh`), di atas
  `Tests\Support\Impor\ImporLegacyTestCase`: skema sumber sintetis `<db_test>_lgc` dibuat dari struktur v2
  (`CREATE TABLE … LIKE`) lalu diubah seperti legacy (kolom dibuang, tipe diubah, CHECK dilepas).
