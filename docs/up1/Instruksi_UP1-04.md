# UP1-04 — Instruksi: ekstraksi salinan DevSimpeg + audit jumlah baris

Pelaksana: **Horii** (mengambil alih dari DB Validator, 02-11-2026; punya akses & wewenang). Acuan lengkap:
[`Runbook_UP-1.md`](Runbook_UP-1.md) langkah 2–3, 7–10, dan pembuktian zona di langkah 12. Jawaban P-01..P-09:
[`Pertanyaan_Terbuka_UP-1.md`](Pertanyaan_Terbuka_UP-1.md).

## Tujuan

Menyiapkan **salinan data nyata DevSimpeg** untuk uji paritas Tukin v2 vs legacy (Tahap UP-1), dengan:

1. zona jam server legacy terbukti (WIB atau UTC) supaya impor tidak menggeser stempel waktu;
2. salinan baca-saja untuk periode uji tersimpan aman di mesin pelaksana;
3. jumlah baris per tabel di sumber tercatat sebagai pembanding setelah impor (UP1-06).

UP1-04 **selesai** bila keempat butir "Yang dilaporkan" di bawah sudah dikirim ke reviewer CR.

## Ketetapan (sudah diputus Horii)

| Butir | Nilai |
|---|---|
| Akun | khusus UP-1, baca-saja (`SELECT` + `SHOW VIEW`) skema SIMPEG di server dev; dicabut segera setelah UP1-09 (P-02) |
| Lingkup data | 2 periode uji + tabel di runbook bagian c saja (P-03); jalur Export HeidiSQL: penuh sementara lalu dipangkas (penyesuaian P-03, 02-11-2026) |
| Penyimpanan | folder lokal di luar repo/cloud; skema salinan di MySQL lokal (P-04) |
| Periode uji | bulan Tukin **April & Mei 2026** → `UP1_AWAL=2026-02-16`, `UP1_AKHIR=2026-05-15`, `UP1_AWAL_CUTI=2025-08-16` (P-05, diubah Horii 02-11-2026 dari Sep & Okt 2026) |
| Ekspor legacy | langkah 20 nanti (P-07) |

## Yang sudah dicek (02-11-2026, kueri baca-saja, tanpa data pribadi)

- **Zona (GAP-2): WIB.** `NOW()` − `UTC_TIMESTAMP()` = +7 jam, `@@system_time_zone` = WIB, `@@global.time_zone` =
  SYSTEM → impor memakai `--zona-sumber=WIB`.
- **Tabel:** semua tabel di runbook bagian c ada di sumber.
- **Kolom filter:** sesuai DDL legacy, kecuali `bkn_periode_ekinper.periode_awal/periode_akhir` yang berformat
  **`MM-DD`** (tahun & bulan di kolom `tahun`/`bulan`). Saringan BKN di `up1-ekstraksi.sh` dan `up1-hitung-baris.sql`
  dibetulkan ke `tahun*100+bulan` (CR-088).
- **Collation:** di MySQL 8, kolom VARCHAR `utf8mb4_unicode_ci` yang dibandingkan dengan `@variabel` sesi memicu
  ERROR 1267, sehingga `up1-hitung-baris.sql` berhenti di klien GUI. Saringan BKN kini numerik, jadi tidak lagi
  membandingkan VARCHAR dengan variabel.
- **Cakupan data (`sql/up1-cek-periode.sql`):** DB dev = salinan prod ±20-05-2026 (stempel `absen_ijin` terakhir
  20-05-2026). `dh_online` penuh per bulan s.d. April 2026, Mei terisi s.d. ±20-05, Juli–Oktober kosong (hanya baris
  lepas). Karena itu periode uji Sep & Okt 2026 diganti April & Mei 2026 (P-05). Baris BKN 2026-04 dan 2026-05
  masing-masing 1 baris.
- **Kueri baca tanpa terminal:** `sql/up1-baca.sql` (HeidiSQL, satu tabel hasil: zona, BKN, jumlah baris, kontrol,
  kandidat lingkup) memakai periode yang sama dengan `up1-ambil.sh`.

## Langkah (satu perintah)

- [ ] **1. Persiapan.** Akun baca-saja DB dev siap; MySQL lokal 3307 menyala; folder kerja `C:\UP1_kerja` di luar
      repo & folder sinkron cloud. Isi checklist keamanan data di `Hasil_UP-1_TEMPLATE.md` bagian 6.
- [ ] **2. Jalankan** dari Git Bash di root repo:
      ```bash
      bash docs/up1/sql/up1-ambil.sh
      ```
      Skrip menanyakan host, port, user, nama DB, dan password (tidak disimpan), lalu: mencatat zona, memastikan BKN
      bulan uji tepat satu baris per bulan, mengekstraksi (sama dengan `up1-ekstraksi.sh`), menghitung baris
      (`hitung_sumber.txt`), me-restore ke `up1_legacy_salinan`, dan memverifikasi (tanpa trigger, tanpa
      `pengguna`/`token`, jumlah baris salinan = sumber). Skrip berhenti bila skema salinan sudah ada atau folder dump
      tidak kosong. Di akhir, folder dump boleh langsung dihapus karena salinan sudah ada di 3307.
- [ ] **3. Laporkan** isi `C:\UP1_kerja\ringkasan_untuk_pr.txt` sebagai komentar di PR ini.

Cara manual (tanpa skrip satu perintah) tetap mengikuti runbook langkah 7–10.

## Jalur Export HeidiSQL (dipakai 02-11-2026, penyesuaian P-03)

Dipakai bila kredensial DB dev tidak boleh dimasukkan ke skrip. Pembagian: Horii mengekspor, reviewer CR melanjutkan
di mesin yang sama.

1. **Horii — Export.** Di sesi HeidiSQL DB dev: klik kanan DB → *Export database as SQL*; centang hanya 41 tabel di
   runbook bagian c (31 master/pegawai + `jenjang_jf` bila ada + 9 tabel transaksi + `web_config`; **tanpa**
   `pengguna`, `token`, view, trigger, routine, event); Table(s) *Create*, Data *Insert*, Database(s) kosong; Output
   *Single .sql file* ke folder kerja `C:\UP1_kerja\`.
2. **Reviewer — restore** berkas itu ke `up1_legacy_salinan` di MySQL lokal 3307 (DB baru; berhenti bila sudah ada).
3. **Reviewer — pangkas** dengan `sql/up1-pangkas.sql` (baris di luar periode uji & key non-Tukin dihapus; hanya
   berlaku di `up1_legacy_salinan`).
4. **Reviewer — verifikasi:** jumlah baris salinan = hasil `sql/up1-baca.sql` di DB dev; tanpa trigger; tanpa
   `pengguna`/`token`. Hasil (angka saja) dilaporkan di PR ini.
5. **Horii — izin hapus** berkas export penuh setelah verifikasi lolos.

Reviewer hanya menjalankan perintah yang keluarannya jumlah/status; baris data tidak pernah ditampilkan atau
disalin ke repo, PR, Trello, atau chat.

## Yang dilaporkan ke reviewer CR

Tulis sebagai komentar di PR ini **tanpa** NIP, nama, host, IP, akun, atau password (repo publik). Semuanya sudah ada di
`ringkasan_untuk_pr.txt`:

1. hasil kueri zona + pilihan `--zona-sumber`;
2. tanggal salinan diambil;
3. jumlah baris per tabel (`hitung_sumber.txt`) dan hasil verifikasi salinan;
4. kolom DDL DevSimpeg yang berbeda dari daftar tabel runbook (bila ada) dan perbaikan `--where` lokal yang dipakai.

## Sesudah UP1-04

Lanjut ke impor (runbook langkah 11–17, UP1-06) di DB scratch. Data nyata tidak pernah masuk repo, Trello, atau chat.
Akses DB dev dicabut setelah bersih-bersih UP1-09.
