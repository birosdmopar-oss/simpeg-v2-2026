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
| 3 | DATETIME tujuan: sumber DATETIME + WIB → −7 jam; sumber DATETIME + UTC dan sumber TIMESTAMP → apa adanya; DATE/TIME tidak diubah; kolom `waktuBisnis` (waktu kejadian, bukan stempel) tidak diubah | R1a, R1b, R2 |
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
- `kunciUrut`: PK komposit untuk baca per batch, mis. `dh_online (nip, tgl)`.
- `waktuBisnis`: kolom DATETIME berisi waktu kejadian, mis. `absen_ijin.date_start/date_end`. Kolom ini tidak dikonversi
  R1a, karena tanggal izin akan bergeser sehari bila dikurangi 7 jam.
- `khusus`: aturan per baris; boleh mengembalikan `__koreksi` (list kode) untuk dicatat. Contoh: `web_config` lewat `WebConfigImport` (G-09 §5); hasil SKIP/REVIEW tidak diimpor
  dan dihitung sebagai `dibuang`.

## Berkas keputusan

Contoh ada di `docs/up1/keputusan-impor.contoh.json`. Isi finalnya diputuskan DB Validator. Bagian yang dikenal:
`status`, `nilai`, `tanggal_nol`, `catatan`. Nilai di luar domain yang belum dipetakan → gagal-cepat.

## Tier

| Tier | Isi | Status |
|---|---|---|
| 1 | Master: `agama`, `jenis_status`, `provinsi`/`kabupaten_kota` (sentinel = seed), `pangkat`, `jenis_kp`, `jenjang_pendidikan`, `unit`, `satker`, `group_jabatan`, `sub_group_jabatan`, `rumpun_jabatan`, `kelas_jabatan`, `jenjang_jf` (opsional), `jabatan`, `jabatan_koordinasi`, `periode_struktur_jabatan`, `struktur_jabatan`, `jenis_pegawai`, `gol_pppk`, `jenis_libur`, `hari_libur`, `jenis_cuti`, `web_config` | M1 |
| 2 | `pegawai` (domain `flag_update` 0–3, `jenis_kelamin` 1/2; NIP & kode wilayah di-trim) | M2 |
| 3 | `riwayat_mutasi_jabatan`, `riwayat_kp` (status 0/1/2/3/10), snapshot `pegawai_mutasi_jabatan`/`pegawai_kp` (`id_riwayat_*` yatim → NULL), `pegawai_plt`, `pegawai_plh` | M2 |
| 4 | `absen_ijin` (status W/V/X/10, `affect_tukin`/`jenis_dinas` ber-domain, rentang izin = waktu bisnis), `riwayat_keluarga`, `detail_anak`, `bkn_periode_ekinper`, `riwayat_skp_periodik` | M3 |
| 5 | `lokasi_presensi`, `dh_online` (baris tanpa jam masuk & pulang dibuang `DBV-023 A2`; `sumber` NULL) | M3 |
| 6 | `pegawai_sisa_cuti` (duplikat `(nip, tahun)` → id terkecil, saldo negatif → 0), `riwayat_cuti` (6 kolom ber-domain), `riwayat_tb`, `riwayat_tb_perpan` | M3 |

Sesudah setiap tier di-commit, counter AUTO_INCREMENT tujuan disamakan dengan sumber bila lebih kecil (DBV-011 §2.7;
tidak pernah diturunkan). PK TINYINT dengan counter ≥ 128 tidak disalin dan dicatat `PERIKSA`. Langkah ini DDL, jadi
berjalan di luar transaksi tier. Bila galat terjadi di langkah ini, data tier tetap ada; ulangi dengan `--tier`
berikutnya setelah counter dibereskan manual.

## Keputusan DBV yang dibutuhkan

Bagian ini dijawab DB Validator sekali untuk semua butir. Isi keputusan berupa kode/nilai masuk ke berkas keputusan
lokal (contoh: `docs/up1/keputusan-impor.contoh.json`). Keputusan berupa aturan akan diterapkan coder di `DaftarTabel`.

| No | Butir | Bawaan alat sekarang | Yang diminta dari DBV |
|---|---|---|---|
| GAP-2 | Jam server legacy untuk kolom DATETIME (DBV-011 §3.6 #3) | Tanpa bawaan: `--zona-sumber=WIB\|UTC` wajib diisi | Konfirmasi WIB atau UTC sebelum UP-1 dijalankan |
| GAP-4 | `dm_user_lokasi_presensi` / `user_lokasi_presensi` (Mapping "tidak dimigrasi" vs G-03 §6.2 #6) | Tidak diimpor; tidak dibaca jalur Tukin | Keputusan untuk cutover (di luar UP-1) |
| GAP-6 | Isi berkas keputusan: status/nilai di luar domain (`status`, `<tabel>.<kolom>` ber-CHECK, mis. `absen_ijin` `H`/angka lama, `pegawai.jenis_kelamin`), `hari_libur.id_jenis_libur` NULL, pengganti tanggal nol di kolom NOT NULL (R3) | Gagal-cepat bila belum dipetakan | Pemetaan per nilai dari hasil audit salinan (log 2.8) |
| GAP-8 | `stripslashes` untuk tabel T2–T6 (DBV-011 §2.2 #3 baru mendaftar master) | `stripslashes` PHP pada semua kolom teks di bawah (form legacy `addslashes` [K]); NIP dan kode tidak terpengaruh | Daftar kolom yang disimpan MENTAH (`tanpaStripslashes`). Kandidat kuat: `riwayat_skp_periodik` dan `bkn_periode_ekinper`, karena terisi dari sinkron BKN, bukan dari form |
| GAP-9 | Kolom DATETIME berisi waktu kejadian (`waktuBisnis`, tidak dikonversi R1a) | `absen_ijin.date_start`, `absen_ijin.date_end` | Konfirmasi daftar, termasuk apakah `riwayat_skp_periodik.waktu_dinilai` stempel (dikonversi) atau waktu bisnis |
| GAP-10 | Kolom domain/status yang tidak punya CHECK v2 | `bkn_periode_ekinper.status`, `detail_anak`, `pegawai_sisa_cuti`, `riwayat_tb_perpan`, snapshot: disalin apa adanya | Konfirmasi tidak perlu normalisasi |
| GAP-11 | Pembanding seed (GAP-3) memakai id + nama, tanpa beda huruf besar/kecil | `jenis_cuti`, sentinel `provinsi` 99 / `kabupaten_kota` 9999 | Konfirmasi aturan; bila nama legacy berbeda dengan seed, pilih mana yang dipakai |

Kolom teks T2–T6 yang terkena `stripslashes` bawaan (GAP-8; NIP dan kode di-trim, tidak diubah):

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

## Di luar cakupan

- `penyesuaian_tukin` diisi lewat CRUD (DBV-024 §5).
- `pengguna`/`token` tidak diimpor. Akun role 1 sintetis dibuat DBA setelah impor (GAP-5).
- GAP cutover `dm_user_lokasi_presensi` (GAP-4) tidak dibaca jalur Tukin. Keputusannya diserahkan ke DBV saat cutover.

## Test

- `tests/unit/Impor/AturanImporTest.php`: aturan murni (transformasi, keputusan, penjaga).
- `tests/Impor/ImporLegacyTier1Test.php`, `ImporLegacyTier23Test.php`, `ImporLegacyTier456Test.php` (`db-isolasi-penuh`), di atas
  `Tests\Support\Impor\ImporLegacyTestCase`: skema sumber sintetis `<db_test>_lgc` dibuat dari struktur v2
  (`CREATE TABLE … LIKE`) lalu diubah seperti legacy (kolom dibuang, tipe diubah, CHECK dilepas).
