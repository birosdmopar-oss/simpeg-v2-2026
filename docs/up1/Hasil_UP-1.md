# Hasil UP-1 — paritas rekap Tukin v2 vs legacy

Lembar hasil langkah 25 [`Runbook_UP-1.md`](Runbook_UP-1.md), dari templat
[`Hasil_UP-1_TEMPLATE.md`](Hasil_UP-1_TEMPLATE.md). Isinya hanya angka agregat dan id lingkup: tanpa NIP, nama,
nominal, host, atau path pribadi.

## 1. Identitas uji

| Butir | Isi |
|---|---|
| Tanggal pelaksanaan | 02-11-2026 |
| Commit v2 (`main`) yang diuji | `1bfb49fd` (alat impor MAKE-056, `RekapTukinService`, `presensi:paritas-tukin`) |
| Sumber data | DB server dev SIMPEG (salinan produksi ±20-05-2026), diambil lewat Export HeidiSQL 02-11-2026 pagi (penyesuaian P-03: tersalin penuh sementara lalu dipangkas dengan `sql/up1-pangkas.sql`) |
| Rentang data | `UP1_AWAL` 2026-02-16 … `UP1_AKHIR` 2026-05-15; cuti/izin sejak 2025-08-16; BKN 2026-02 … 2026-05 (P-05) |
| Bulan Tukin diuji (`M`) | 1. 2026-04 2. 2026-05 |
| Lingkup | `0` (kementerian); unit `6`; bulan BKN tidak standar: tidak ada (T-04/T-07: 2026-04 = 03-16…04-15, 2026-05 = 04-16…05-15, `jenis_periode` NULL) |
| Jenis pegawai | PNS (legacy `str_jp=1` = bawaan v2 `[1]`) |
| Engine DB scratch | MySQL 8.0.30 (instance uji 3307): salinan `up1_legacy_salinan`, v2 `simpeg_v2_t_up1` |
| Zona sumber (GAP-2, Runbook langkah 12) | `NOW()` − `UTC_TIMESTAMP()` = +7 jam · `@@system_time_zone` WIB · `@@global.time_zone` SYSTEM → `--zona-sumber=WIB` |
| Pelaksana | DBA: Horii (ambil alih UP1-04/UP1-06: export & ekspor XLSX legacy) · Reviewer CR: Qoder A (restore, pangkas, impor, ekspor v2, paritas, triase) |

Ekspor v2 (langkah 21) dibuat dengan memanggil `RekapTukinService::xlsx()` langsung dalam konteks role 1, sama dengan
isi `GET presensi/tukin/rekap/xlsx`, tanpa membuat akun/password di DB uji (langkah 15 tidak diperlukan). Skrip
pemanggilnya sementara dan tidak di-commit.

## 2. Ringkasan paritas per bulan × lingkup

Ringkasan `presensi:paritas-tukin` (toleransi 0.01, NIP tersamar).

| Bulan | Lingkup | Kode keluar | Baris legacy | Baris v2 | `sama` | `beda` | `hanya_legacy` | `hanya_v2` | `galat_v2` | `galat_legacy` |
|---|---|---|---|---|---|---|---|---|---|---|
| 2026-04 | 0 | 1 | 1783 | 1783 | 1779 | 4 | 0 | 0 | 0 | 0 |
| 2026-05 | 0 | 1 | 1779 | 1779 | 1772 | 7 | 0 | 0 | 0 | 0 |
| 2026-04 | 6 | 1 | 672 | 672 | 670 | 2 | 0 | 0 | 0 | 0 |
| 2026-05 | 6 | 1 | 670 | 670 | 666 | 4 | 0 | 0 | 0 | 0 |

Baris `beda` lingkup 6 adalah bagian dari baris `beda` lingkup 0 (11 pegawai unik). Tingkat sama: 99,78% (April) dan
99,61% (Mei) di lingkup 0.

**Tidak dibandingkan (keputusan Horii 02-11-2026):**

- **Rekap Instansi Lain (IL, CR-085).** Di salinan populasi IL periode ini 2 pegawai. Jalur `KalkulasiTukinIl`
  (termasuk keputusan "ikut legacy" 30-10) belum teruji dengan data nyata; diuji saat gladi cutover.
- **Lingkup unit 7 ("Unit Lain").** Ekspor legacy dari server dev untuk `unit_satker=7` berjudul dan berisi satu
  satker unit lain (79 baris), tidak sesuai kode legacy versi produksi (judul nama unit, populasi pegawai unit 7);
  dugaan kode server dev berbeda di jalur ini. Perhitungan pegawai unit 7 tetap tercakup: 145/145 pegawai unit 7
  (v2) ada di rekap lingkup 0 dan cocok, kecuali 1 baris Mei yang termasuk kelompok (a) di bawah. Yang tidak teruji
  hanya filter lingkup unit 7.

## 3. Selisih jumlah baris sumber vs impor (Runbook langkah 17)

`sql/up1-hitung-baris.sql` di salinan dan di v2: **71 angka identik, selisih 0**. Laporan impor tier 1–6: semua tabel
`ditulis` = `sumber`, `dibuang` 0. Audit DBV-019/022/023 bagian yatim: semua 0 (`psc_melebihi_batas_d8` = 303 adalah
info Q-02 yang sudah diputus).

Penyesuaian data sebelum impor (kelompok b, dikerjakan di salinan, dicatat di Trello Catatan Teknis untuk cutover):

| Tabel | Baris | Penyesuaian | Dasar |
|---|---|---|---|
| `unit`, `jabatan` | 5 unit, 35 jabatan nonaktif | Nama ganda diberi akhiran ` (nonaktif #id)`; nama aktif tidak diubah | Keputusan Horii 02-11: pertahankan UNIQUE nama [V2] (DBV-008/G-02), rapikan data |
| `unit` | 15 | `is_upt` 2 → 0 lewat berkas keputusan impor (`nilai`) | G-02 Bagian 4 #9 |
| `riwayat_mutasi_jabatan` | 119 | Karakter U+200C di NIP dibuang (legacy `unicode_ci` mengabaikannya; alat impor membandingkan persis) | Kelompok (b) |
| `riwayat_skp_periodik` | 5 (1 NIP) | Baris SKP milik NIP yang tidak ada di `pegawai` tidak diimpor (rekap legacy juga tidak membacanya) | Kelompok (b) |
| `riwayat_tb` | — | TB yang rentang aslinya lewat tetapi perpanjangannya beririsan periode ikut diekstraksi (FK CASCADE `riwayat_tb_perpan`) | Aturan ekstraksi diperbaiki (CR-088) |

## 4. Triase temuan

| Kelompok | Jumlah | Keterangan (pola, tanpa data pribadi) | Kartu |
|---|---|---|---|
| (a) deviasi [V2] tercatat | 11 pegawai (17 baris pasangan) | Pegawai `status = 2` dengan `tmt_status` di dalam periode. Legacy tidak memuat data harian/cuti mereka (`tmt_status` ≤ akhir BKN) sehingga semua hari kerja menjadi TK (0,24%/hari: 4,08% April, 4,56% Mei); v2 memakai data nyata s.d. `tmt_status` = README `Presensi/Tukin` "Perbedaan disengaja [V2]" #1. Kolom SKP tidak berbeda | — |
| (b) data salinan tidak konsisten | 0 di paritas | Penyesuaian sebelum impor di bagian 3; tidak ada selisih paritas yang berasal dari data | — |
| (c) bug v2 | 0 | — | — |

Status (c): terbuka 0 · diperbaiki & diuji ulang 0.

Butir cek riset UP1-11 (Runbook i½):

| Butir | Hasil |
|---|---|
| T-03 puasa | `rentang_puasa` v2 diisi `2026-02-19..2026-03-21` = hard-code legacy 2026 (inklusif) |
| T-04 / T-07 BKN | 2 bulan diuji punya baris BKN `jenis_periode` NULL; awal periode = tanggal 16 bulan N−1 |
| T-05 SKP backslash | 0 nilai `hasil_akhir` memuat backslash (salinan & v2) |
| T-06 SKP ganda | NIP dengan > 1 baris SKP aktif per periode: April 4 (1 dengan nilai berbeda), Mei 8 (1 dengan nilai berbeda). Paritas kolom SKP 0 selisih → v2 memilih baris yang sama dengan legacy; tidak perlu keputusan tambahan |
| T-09 pengenal PPPK | 0 baris dengan `id_jenis_pegawai = 6` ≠ teks `jenis_pegawai = 'PPPK'` |
| P-09 penyesuaian Tukin | Tidak ada untuk April–Mei 2026 (hard-code legacy terakhir Juni 2024; 0 sel "disesuaikan" di XLSX legacy); `penyesuaian_tukin` v2 kosong |
| T-01 / T-02 | Tidak muncul sebagai selisih |

## 5. Keputusan

- [x] Semua bulan × lingkup rencana (0 dan unit 6, April & Mei 2026) sudah dibandingkan; IL dan unit 7 tidak
      dibandingkan atas keputusan Horii (bagian 2)
- [x] Tidak ada temuan (c) yang masih terbuka
- **UP-1:** LOLOS
- Disetujui Horii: 02-11-2026
- Setelah LOLOS, prasyarat Fase 6 (GATE-F6) dibuka oleh reviewer CR.

## 6. Checklist keamanan data

| Objek | Pemegang | Lokasi (jenis) | Dibuat | Izin hapus Horii | Dihapus |
|---|---|---|---|---|---|
| Berkas Export HeidiSQL (pengganti dump `up1-ekstraksi.sh`) | Horii | folder kerja terbatas, lokal | 02-11-2026 | ✅ 02-11-2026 | ✅ 02-11-2026 |
| Skema salinan legacy `up1_legacy_salinan` | Horii / reviewer CR | instance MySQL lokal (3307) | 02-11-2026 | ⏳ | ⏳ |
| DB scratch v2 `simpeg_v2_t_up1` | reviewer CR | instance uji 3307 | 02-11-2026 | ⏳ | ⏳ |
| XLSX legacy (lingkup 0, 6, unit 7) | Horii | folder kerja terbatas | 02-11-2026 | ⏳ | ⏳ |
| XLSX v2 | reviewer CR | folder kerja terbatas | 02-11-2026 | ⏳ | ⏳ |
| CSV temuan (+ `--nip-penuh`) | reviewer CR | folder kerja terbatas | 02-11-2026 | ⏳ | ⏳ |

Pemeriksaan:

- [x] Akses DevSimpeg hanya baca (kueri SELECT dan Export HeidiSQL oleh Horii, tanpa kredensial di skrip); produksi
      tidak disentuh.
- [x] `constants.php` legacy tidak dibuka.
- [x] Folder kerja di luar repo.
- [ ] Folder kerja di luar folder sinkron cloud (konfirmasi Horii).
- [x] Tidak ada data nyata di repo, PR, Trello, atau Notion. Catatan: satu nama pegawai sempat tercetak di keluaran
      perintah lokal sesi reviewer saat memeriksa judul XLSX; tidak disalin ke repo, Trello, atau laporan.
- [x] `.env` dev tidak disalin (koneksi lewat variabel lingkungan); DB tujuan bukan `simpeg_v2`,
      `simpeg_v2_testing`, atau 3306.
- [ ] Semua objek di tabel atas dihapus setelah izin, termasuk Recycle Bin dan folder unduhan (langkah 26).
