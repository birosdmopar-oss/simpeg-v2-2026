# Runbook UP-1 — uji paritas rekap Tukin v2 vs legacy (MAKE-055)

Paket siap-jalan untuk DBA dan reviewer CR. Keputusan Horii 29-10-2026: UP-1 adalah **tahap tersendiri di antara Fase 5
dan Fase 6**. Fase 5 di-sign-off tanpa menunggu UP-1, tetapi **Fase 6 tidak boleh mulai/lanjut sebelum UP-1 lolos**.
Prosedur paritas teknis tetap mengacu `backend/docs/progress/05-Presensi.md` bagian "Prosedur paritas UP-1" (Q5C-06,
Q5B-09). Runbook ini menambahkan langkah pengambilan data, impor, dan keamanan.

**Sumber data: hanya DB server dev SIMPEG (DevSimpeg), bukan produksi.** Menurut kueri DBA 27-10-2026, server dev adalah
salinan produksi: isinya **data pribadi nyata** (NIP, nama, nominal Tukin). Semua salinan, ekspor, dan keluaran
diperlakukan seperti data produksi.

Isi paket:

| Berkas | Fungsi |
|---|---|
| `docs/up1/Runbook_UP-1.md` | Runbook ini |
| `docs/up1/sql/up1-ekstraksi.sh` | Ekstraksi baca-saja dari DevSimpeg (`mysqldump --single-transaction --where`) per rentang periode |
| `docs/up1/sql/up1-hitung-baris.sql` | Jumlah baris per tabel untuk rentang yang sama; dijalankan di sumber **dan** di DB v2 hasil impor |
| `docs/up1/Hasil_UP-1_TEMPLATE.md` | Lembar hasil anonim + checklist keamanan data |
| `docs/up1/Pertanyaan_Terbuka_UP-1.md` | Keputusan yang masih ditunggu dari Horii |

## Aturan tetap (berlaku di setiap langkah)

- Tidak ada akses tulis ke DevSimpeg; akun yang dipakai **baca-saja**. Produksi tidak disentuh sama sekali.
- `application/config/constants.php` legacy **tidak dibuka** siapa pun (kredensial). Grep kode legacy memakai
  `--exclude=constants.php`.
- Host, akun, password, dan nama server **tidak** ditulis di repo, PR, Trello, Notion, atau chat; diisi DBA saat jalan
  (variabel lingkungan / prompt password).
- Data nyata (dump, XLSX, CSV, DB scratch) disimpan di **satu folder kerja terbatas** di mesin DBA, di luar repo dan di
  luar folder yang tersinkron cloud (OneDrive/Google Drive/Dropbox). Yang dibagikan hanya ringkasan anonim.
- DB tujuan adalah **DB scratch baru bernama eksplisit**, mis. `simpeg_v2_t_up1` di instance uji 3307. Dilarang:
  `simpeg_v2`, `simpeg_v2_testing`, `simpeg01`, instance 3306, atau DB dev bersama. `.env` dev tidak disalin.
- Setiap penghapusan (DB, berkas) menunggu **izin Horii per objek** (⛔).

## Peran

| Kode | Peran | Catatan |
|---|---|---|
| **H** | Horii | Memberi izin & keputusan (titik ⛔) |
| **D** | DBA UP-1 | Belum ditetapkan (P-01). DBV diusulkan, **ditunda** sampai ada izin memegang data |
| **R** | Reviewer CR | Menjalankan pembandingan & triase; tidak memegang dump mentah bila tidak perlu |
| **A** | Agen/coder | Hanya memperbaiki bug kelompok (c) lewat kartu CR, tanpa melihat data nyata |

## Langkah

Perkiraan waktu bersifat kasar dan belum termasuk waktu menunggu izin.

### a. Prasyarat & izin (H, D, R — ±1 hari kalender)

1. ⛔ **H** menjawab `Pertanyaan_Terbuka_UP-1.md` (P-01..P-09): siapa DBA, akun baca-saja DevSimpeg, lokasi penyimpanan,
   jadwal, kesiapan aplikasi legacy di server dev, dan keputusan GAP impor (butir d).
2. **D** menyiapkan mesin kerja:
   - MySQL 8 untuk DB scratch. Instance uji `tools/mysql-test/` (port 3307) boleh dipakai.
   - Repo v2 di `main` terbaru dengan `composer install` dan `.env` uji yang menunjuk DB scratch (bukan salinan `.env`
     dev).
   - Folder kerja terbatas, misalnya `<KERJA_UP1>`.
3. **D** mengisi checklist keamanan data di lembar hasil (bagian 6) **sebelum** data pertama diambil.

### b. Pilih periode & lingkup (R, H — ±30 menit)

4. **Periode.** Ambil dua periode Tukin terakhir yang sudah tutup (Q5C-06). Periode Tukin bulan `M` = tanggal 16 bulan
   `M-1` s.d. 15 bulan `M`. Contoh: bila UP-1 dijalankan setelah 15-10-2026, `M` = September dan Oktober 2026
   (periode 16-08..15-09 dan 16-09..15-10). Periode berjalan tidak dipakai karena v2 memotongnya di hari ini (AC-D08.4).

   **`UP1_AWAL` = awal periode SEBELUM periode pertama yang diuji.** Adapter v2 membaca `dh_online` mulai awal
   periode sebelumnya sampai akhir periode, sedangkan legacy membaca bulan kalender N−1. Untuk contoh di atas,
   `UP1_AWAL` = **16-07-2026**, dan rentang ini sudah mencakup bulan kalender Agustus. `UP1_AKHIR` = **15-10-2026**.

   **`UP1_AWAL_CUTI` = cadangan lookback untuk `riwayat_cuti` dan `absen_ijin`.** Bawaannya `UP1_AWAL` − 6 bulan
   (contoh: 16-01-2026), supaya rangkaian cuti sakit lintas periode terbaca utuh. Bila lingkup memuat cuti sakit yang
   lebih panjang, perpanjang nilainya.
5. **Lingkup per bulan:**
   - `0` (kementerian);
   - minimal satu unit dengan pegawai **cuti sakit lintas periode**;
   - satu bulan dengan **baris BKN tidak standar** (`bkn_periode_ekinper`), bila ada.

   Kandidat unit dicari **D** dengan kueri di bagian C `up1-hitung-baris.sql`. Hasilnya hanya berupa id unit dan
   jumlah, tanpa NIP.
6. Catat periode dan id lingkup di lembar hasil (bagian 1).

### c. Ambil salinan dari DevSimpeg (D — ±1–2 jam)

7. Isi parameter di sesi shell. Jangan simpan ke berkas yang di-commit:
   ```bash
   export UP1_HOST=...  UP1_PORT=...  UP1_USER=...  UP1_DB=...   # akun BACA-SAJA DevSimpeg
   export UP1_AWAL=2026-07-16  UP1_AKHIR=2026-10-15             # langkah 4 (awal = periode sebelumnya)
   export UP1_AWAL_CUTI=2026-01-16                              # opsional; bawaan UP1_AWAL − 6 bulan
   export UP1_OUT=<KERJA_UP1>/dump
   ```
8. Jalankan `bash docs/up1/sql/up1-ekstraksi.sh`. Password ditanya interaktif (`-p`) dan tidak disimpan. Skrip ini:
   - hanya memanggil `mysqldump --single-transaction --skip-lock-tables --no-tablespaces`, tanpa `--skip-tz-utc`
     (R1b DBV-011), tanpa trigger, routine, atau event;
   - **menyalin penuh** tabel master/rujukan dan tabel pegawai, karena populasi rekap butuh status semua pegawai;
   - **membatasi** tabel transaksi ke rentang `UP1_AWAL..UP1_AKHIR` lewat `--where`, khusus `riwayat_cuti` dan `absen_ijin` mulai
     `UP1_AWAL_CUTI`;
   - mengambil `web_config` **hanya** untuk key tarif dan jam kerja Tukin;
   - **tidak** mengambil `pengguna`, `token`, atau tabel kredensial/log lain.
9. Jalankan `up1-hitung-baris.sql` di sumber untuk mendapat jumlah baris per tabel. Simpan sebagai
   `<KERJA_UP1>/hitung_sumber.txt`:
   ```bash
   mysql -h "$UP1_HOST" -P "$UP1_PORT" -u "$UP1_USER" -p --default-character-set=utf8mb4 -N -B \
     -e "SET @awal='$UP1_AWAL', @akhir='$UP1_AKHIR', @awal_cuti='$UP1_AWAL_CUTI';" -e "source docs/up1/sql/up1-hitung-baris.sql" "$UP1_DB" \
     > "$UP1_OUT/../hitung_sumber.txt"
   ```
10. Restore dump ke **skema salinan legacy** terpisah di mesin DBA, mis. `up1_legacy_salinan` di instance MySQL 8.
    Bersihkan trigger dan kolom `password_decode` sesuai DBV-011 §2.4 langkah 1. Skrip ekstraksi tidak mengambil
    trigger dan tidak mengambil `pengguna`, jadi langkah ini cukup verifikasi bahwa keduanya kosong.

Daftar tabel minimal (dari prosedur UP-1 dan kode jalur Tukin `Libraries/Presensi/TukinData`, `Laporan/`):

| Kelompok | Tabel | Batas |
|---|---|---|
| Pegawai & jabatan | `pegawai`, `pegawai_mutasi_jabatan`, `riwayat_mutasi_jabatan`, `pegawai_plt`, `pegawai_plh`, `pegawai_kp`, `riwayat_kp`, `riwayat_keluarga`, `detail_anak` | penuh |
| Struktur | `unit`, `satker`, `jabatan`, `group_jabatan`, `sub_group_jabatan`, `rumpun_jabatan`, `struktur_jabatan`, `periode_struktur_jabatan`, `jabatan_koordinasi`, `kelas_jabatan`, `jenjang_jf` (bila ada), `jenis_pegawai`, `gol_pppk` | penuh |
| Rujukan pegawai | `agama`, `jenis_status`, `provinsi`, `kabupaten_kota`, `pangkat`, `jenis_kp`, `jenjang_pendidikan` | penuh |
| Presensi | `dh_online` (`tgl`), `lokasi_presensi` (penuh) | rentang |
| Izin & dinas | `absen_ijin` (`date_start`/`date_end` tumpang tindih) | rentang |
| Cuti & TB | `riwayat_cuti` (`tgl_mulai`/`tgl_akhir` tumpang tindih), `jenis_cuti`, `pegawai_sisa_cuti` (tahun rentang), `riwayat_tb`, `riwayat_tb_perpan` | rentang (TB: tumpang tindih) |
| Kalender | `hari_libur` (tumpang tindih), `jenis_libur` | rentang |
| SKP/BKN | `bkn_periode_ekinper` (`periode_awal`/`periode_akhir`), `riwayat_skp_periodik` (`periode_awal_skp`/`periode_akhir_skp`) | rentang |
| Konfigurasi | `web_config` key `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK`, `jam_*_normal`, `jam_*_puasa` | key tertentu |

Nama kolom mengikuti label [K] di dokumen DBV, yaitu nama legacy. **D** mencocokkannya dengan DDL DevSimpeg sebelum
menjalankan skrip. Bila ada kolom yang berbeda, perbaiki `--where` di salinan lokal dan catat perbedaannya di lembar
hasil.

### d. Impor ke DB scratch v2 (D — ±½–1 hari; lihat GAP-1)

11. Buat DB scratch baru (`simpeg_v2_t_up1`) lalu jalankan `php spark migrate --all` dengan `.env` uji yang menunjuk
    DB tersebut.
12. **Jalur impor yang ada di repo per 29-10-2026:** baru berupa **aturan tertulis**, belum ada alat (command `spark`,
    skrip SQL transformasi) yang bisa dijalankan:

    | Aturan | Dokumen |
    |---|---|
    | Urutan tier, audit/dedupe master, R1a/R1b zona waktu, counter AUTO_INCREMENT, sesi `time_zone='+00:00'` + strict | `backend/docs/db-review/DBV-011-runbook-impor-master-zona-waktu.md` §2.4–2.12, §3.2 |
    | Pegawai & riwayat | `B-01-B-02-pegawai-riwayat-schema.md` §6 |
    | `dh_online` (baris tanpa jam dibuang, `sumber` NULL) | `DBV-023-presensi-gaji-fase5a-schema.md` §7.1 |
    | Cuti/TB/sisa cuti (normalisasi duplikat & saldo negatif) | `DBV-022-layanan-fase4-schema.md` §5, §13.1; DBV-011 §2.12 |
    | `penyesuaian_tukin` (tanpa impor, diisi lewat CRUD) | `DBV-024-penyesuaian-tukin-schema.md` §5 |

    > **GAP-1 — alat impor legacy → v2 belum ada.** Butir ini **tidak** dibuat diam-diam dalam paket ini. **D** perlu
    > keputusan Horii (P-06): (1) DBA menulis SQL `INSERT … SELECT` lokal dari skema salinan ke DB scratch mengikuti
    > aturan di atas (sekali pakai, tidak di-commit bila memuat data); atau (2) dibuat kartu MAKE terpisah untuk alat
    > impor resmi yang juga dipakai cutover; atau (3) UP-1 menunggu alat impor cutover. Tanpa keputusan ini, langkah
    > 13–14 tidak bisa jalan.

13. Setelah impor:
    - jalankan skrip audit `backend/docs/db-review/sql/DBV-023-audit-presensi-gaji.sql` dan `DBV-022-audit-layanan.sql`
      di DB scratch; bagian WAJIB_0 harus kosong;
    - jalankan `up1-hitung-baris.sql` di DB scratch dan simpan sebagai `hitung_v2.txt`.
14. Bandingkan `hitung_sumber.txt` dengan `hitung_v2.txt`. Selisih hanya boleh berasal dari aturan impor yang
    tercatat, mis. baris `dh_online` tanpa jam, duplikat `pegawai_sisa_cuti`. Setiap selisih dicatat di lembar hasil
    (bagian 3).

### e. Isi penyesuaian Tukin (D — ±30 menit)

15. Isi `penyesuaian_tukin` lewat **CRUD role 1** (F5B-21, menu Penyesuaian Tukin) untuk setiap penyesuaian yang
    berlaku di rentang uji. Daftarnya disiapkan sesuai P-09, tanpa membuka
    `constants.php`. Jangan isi lewat SQL.
16. Jalankan audit `backend/docs/db-review/sql/DBV-024-audit-penyesuaian-tukin.sql` **bagian B**. B1 dan B2 harus 0;
    B3/B4 dicatat sebagai info (syarat C-2).

### f. Ekspor per bulan × lingkup (D untuk legacy, D/R untuk v2 — ±15 menit per kombinasi)

17. **Legacy** (aplikasi legacy di server dev, terhadap data yang sama dengan salinan): Rekap Laporan Tukin varian SKP
    (`laporan_tukin_us_skp`), XLSX, bulan `M`, lingkup sama, jenis pegawai **PNS**. Jangan memakai "Tukin Lama" atau
    `tukinver`. Simpan sebagai `<KERJA_UP1>/legacy_<M>_<lingkup>.xlsx`.
    > Ekspor legacy membaca DevSimpeg live, sedangkan salinan diambil pada satu waktu. Lakukan ekspor **sedekat
    > mungkin** dengan waktu ekstraksi (langkah 8), dan pastikan tidak ada entri presensi/cuti baru untuk rentang
    > uji di antaranya. Bila ada, temuannya masuk kelompok (b).
18. **v2**: login role 1 ke aplikasi v2 yang menunjuk DB scratch, lalu
    `GET api/v1/presensi/tukin/rekap/xlsx?bulan=<M>&unit_satker=<lingkup>`. Simpan sebagai
    `<KERJA_UP1>/v2_<M>_<lingkup>.xlsx`.

### g. Bandingkan & triase (R, D — ±1–2 jam per bulan)

19. Untuk tiap pasangan:
    ```bash
    php spark presensi:paritas-tukin legacy_<M>_<lingkup>.xlsx v2_<M>_<lingkup>.xlsx --csv=temuan_<M>_<lingkup>.csv
    ```
    - Kode keluar: 0 = sama, 1 = ada temuan, 2 = masukan salah.
    - Keluaran memakai NIP tersamar.
    - `--nip-penuh` hanya untuk telaah lokal **D** dan tidak dibagikan.
20. Kelompokkan setiap temuan sesuai langkah 5 prosedur di `05-Presensi.md`:
    - **(a)** deviasi [V2] yang sudah tercatat;
    - **(b)** data salinan tidak konsisten (termasuk selisih langkah 14 dan perubahan live di langkah 17);
    - **(c)** bug v2.
21. Untuk setiap temuan (c), **R** membuat kartu ISSUE/CR. Isinya hanya pola dan kasus sintetis, tanpa NIP, nama, atau
    nominal nyata. **A** memperbaiki, lalu setelah fix di `main`, **D/R** mengulang langkah 18–20 untuk bulan dan
    lingkup terkait.

### h. Lembar hasil (R — ±30 menit)

22. Isi `Hasil_UP-1_TEMPLATE.md` dan simpan salinannya sebagai `docs/up1/Hasil_UP-1.md` atau di laporan MAKE-054.
    Isinya hanya angka agregat, tanpa NIP atau nama.

### i. Bersih-bersih (D — ±30 menit; ⛔ per objek)

23. ⛔ Minta izin **H** untuk setiap objek, satu per satu, lalu hapus dan centang di checklist bagian 6:
    - DB scratch `simpeg_v2_t_up1`;
    - skema salinan `up1_legacy_salinan`;
    - folder dump;
    - XLSX legacy dan XLSX v2;
    - CSV temuan, terutama yang dibuat dengan `--nip-penuh`.
24. Pastikan tidak ada sisa di Recycle Bin, folder unduhan browser, atau cache sinkron cloud.

### j. Kriteria lolos

25. UP-1 **LOLOS** bila semua bulan × lingkup sudah dibandingkan dan **tidak ada temuan kelompok (c) yang masih
    terbuka**. **H** menandatangani keputusan di lembar hasil, lalu reviewer membuka prasyarat Fase 6.
