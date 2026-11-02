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
| Lingkup data | 2 periode uji + tabel di runbook bagian c saja (P-03) |
| Penyimpanan | folder lokal di luar repo/cloud; skema salinan di MySQL lokal (P-04) |
| Periode uji | bulan Tukin **September & Oktober 2026** → `UP1_AWAL=2026-07-16`, `UP1_AKHIR=2026-10-15`, `UP1_AWAL_CUTI=2026-01-16` (P-05) |
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
- **Kesegaran data:** `dh_online` dan `riwayat_cuti` terisi melewati 15-10-2026; baris BKN tersedia per bulan.

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
