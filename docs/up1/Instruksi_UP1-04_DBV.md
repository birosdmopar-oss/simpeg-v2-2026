# UP1-04 — Instruksi DBV: ekstraksi salinan DevSimpeg + audit jumlah baris

Untuk: DB Validator (DBA UP-1, P-01). Acuan lengkap: [`Runbook_UP-1.md`](Runbook_UP-1.md) langkah 2–3, 7–10, dan
pembuktian zona di langkah 12. Jawaban P-01..P-09: [`Pertanyaan_Terbuka_UP-1.md`](Pertanyaan_Terbuka_UP-1.md).

## Tujuan

Menyiapkan **salinan data nyata DevSimpeg** untuk uji paritas Tukin v2 vs legacy (Tahap UP-1), dengan:

1. zona jam server legacy terbukti (WIB atau UTC) supaya impor tidak menggeser stempel waktu;
2. salinan baca-saja untuk periode uji tersimpan aman di mesin DBV;
3. jumlah baris per tabel di sumber tercatat sebagai pembanding setelah impor (UP1-06).

UP1-04 **selesai** bila keempat butir "Yang dilaporkan" di bawah sudah dikirim ke reviewer CR.

## Ketetapan (sudah diputus Horii)

| Butir | Nilai |
|---|---|
| Akun | khusus UP-1, baca-saja (`SELECT` + `SHOW VIEW`) skema SIMPEG di server dev; dicabut segera setelah UP1-09 (P-02) |
| Lingkup data | 2 periode uji + tabel di runbook bagian c saja (P-03) |
| Penyimpanan | folder lokal mesin DBV, terenkripsi, di luar repo/cloud; DB scratch di MySQL lokal DBV (P-04) |
| Periode uji | bulan Tukin **September & Oktober 2026** → `UP1_AWAL=2026-07-16`, `UP1_AKHIR=2026-10-15`, `UP1_AWAL_CUTI=2026-01-16` (P-05) |
| Ekspor legacy | dilakukan DBV (P-07, langkah 20 nanti) |

## Langkah

- [ ] **1. Persiapan (runbook langkah 2–3).** Repo v2 di `main` terbaru + `composer install`; `.env` uji menunjuk DB
      scratch (bukan salinan `.env` dev); folder kerja `<KERJA_UP1>` terenkripsi. Isi checklist keamanan data di
      `Hasil_UP-1_TEMPLATE.md` bagian 6 **sebelum** data pertama diambil.
- [ ] **2. Buktikan zona server legacy (GAP-2, runbook langkah 12).** Di DevSimpeg (akun baca-saja):
      ```sql
      SELECT NOW(), UTC_TIMESTAMP(), @@system_time_zone, @@global.time_zone;
      ```
      `NOW()` = jam dinding WIB → `--zona-sumber=WIB`; `NOW()` = `UTC_TIMESTAMP()` → `--zona-sumber=UTC`. Salah arah =
      semua stempel bergeser 14 jam.
- [ ] **3. Cek baris BKN (butir T-04).** Pastikan `bkn_periode_ekinper` (`jenis_periode IS NULL`) punya baris untuk
      September dan Oktober 2026.
- [ ] **4. Ekstraksi (runbook langkah 7–8).** Isi parameter di sesi shell (tidak disimpan ke berkas yang di-commit), lalu
      `bash docs/up1/sql/up1-ekstraksi.sh` (password ditanya interaktif, tidak disimpan).
- [ ] **5. Hitung baris sumber (runbook langkah 9).** Pakai bentuk stdin di runbook → `hitung_sumber.txt`.
- [ ] **6. Restore ke skema salinan terpisah (runbook langkah 10)**, mis. `up1_legacy_salinan`; pastikan trigger dan
      tabel `pengguna` kosong.

## Yang dilaporkan ke reviewer CR

Tulis sebagai komentar di PR ini **tanpa** NIP, nama, host, IP, akun, atau password (repo publik):

1. hasil kueri zona (empat nilai) + pilihan `--zona-sumber`;
2. tanggal salinan diambil;
3. isi `hitung_sumber.txt` (jumlah baris per tabel saja);
4. kolom DDL DevSimpeg yang berbeda dari daftar tabel runbook (bila ada) dan perbaikan `--where` lokal yang dipakai.

## Sesudah UP1-04

Lanjut ke impor (runbook langkah 11–17, UP1-06) di DB scratch. Data nyata tidak pernah masuk repo, Trello, atau chat.
Akses DB dev dicabut setelah bersih-bersih UP1-09.
