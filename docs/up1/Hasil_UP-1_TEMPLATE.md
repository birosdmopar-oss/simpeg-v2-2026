# Hasil UP-1 — paritas rekap Tukin v2 vs legacy (TEMPLATE)

Salin berkas ini menjadi `Hasil_UP-1.md` atau tempelkan ke laporan MAKE-054. **Jangan isi NIP, nama, atau nominal.**
Isi hanya angka agregat dan id lingkup.

## 1. Identitas uji

| Butir | Isi |
|---|---|
| Tanggal pelaksanaan | |
| Commit v2 (`main`) yang diuji | |
| Sumber data | DB server dev SIMPEG (salinan produksi), diambil pada `<tanggal jam>` |
| Rentang data | `UP1_AWAL` … `UP1_AKHIR` |
| Bulan Tukin diuji (`M`) | 1. … 2. … |
| Lingkup | `0`; unit `<id>` (cuti sakit lintas periode); bulan BKN tidak standar: … / tidak ada |
| Engine DB scratch | MySQL … |
| Pelaksana | DBA: … · Reviewer CR: … |

## 2. Ringkasan paritas per bulan × lingkup

Salin baris ringkasan `presensi:paritas-tukin`, versi NIP tersamar.

| Bulan | Lingkup | Kode keluar | Baris legacy | Baris v2 | `beda` | `hanya_legacy` | `hanya_v2` | `galat_v2` | `galat_legacy` |
|---|---|---|---|---|---|---|---|---|---|
| | 0 | | | | | | | | |
| | | | | | | | | | |

## 3. Selisih jumlah baris sumber vs impor (Runbook langkah 14)

| Tabel | Sumber | v2 | Selisih | Penjelasan (aturan impor / kelompok b) |
|---|---|---|---|---|
| | | | | |

## 4. Triase temuan

| Kelompok | Jumlah | Keterangan (pola, tanpa data pribadi) | Kartu |
|---|---|---|---|
| (a) deviasi [V2] tercatat | | | — |
| (b) data salinan tidak konsisten | | | — |
| (c) bug v2 | | | ISSUE/CR-… |

Status (c): terbuka … · diperbaiki & diuji ulang …

## 5. Keputusan

- [ ] Semua bulan × lingkup sudah dibandingkan
- [ ] Tidak ada temuan (c) yang masih terbuka
- **UP-1:** LOLOS / BELUM LOLOS
- Disetujui Horii: … (tanggal)
- Setelah LOLOS, prasyarat Fase 6 dibuka oleh reviewer CR.

## 6. Checklist keamanan data

Diisi DBA. Untuk setiap objek, catat siapa pemegangnya, lokasinya (tanpa path pribadi atau host), dan kapan dihapus.

| Objek | Pemegang | Lokasi (jenis) | Dibuat | Izin hapus Horii | Dihapus |
|---|---|---|---|---|---|
| Dump `up1-ekstraksi.sh` | | folder kerja terbatas, lokal | | | |
| Skema salinan legacy | | instance MySQL lokal | | | |
| DB scratch v2 `simpeg_v2_t_up1` | | instance uji 3307 | | | |
| XLSX legacy | | folder kerja terbatas | | | |
| XLSX v2 | | folder kerja terbatas | | | |
| CSV temuan (+ `--nip-penuh` bila ada) | | folder kerja terbatas | | | |

Pemeriksaan:

- [ ] Akun DevSimpeg yang dipakai baca-saja; produksi tidak disentuh.
- [ ] `constants.php` legacy tidak dibuka.
- [ ] Folder kerja di luar repo dan di luar folder sinkron cloud.
- [ ] Tidak ada data nyata di repo, PR, Trello, Notion, atau chat.
- [ ] `.env` dev tidak disalin; DB tujuan bukan `simpeg_v2`, `simpeg_v2_testing`, atau 3306.
- [ ] Semua objek di tabel atas dihapus setelah izin, termasuk Recycle Bin dan folder unduhan.
