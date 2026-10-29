# ADR-035 — Ekspor Laporan Tukin sinkron (rekap Tukin D-08)

| | |
|---|---|
| **Status** | **Diterima 28-10-2026** (MAKE-041 M5; batas PDF 800 baris disetujui reviewer CR berdasarkan uji volume §3) |
| **Cakupan** | Rekap Tukin Fase 5B: `GET api/v1/presensi/tukin/rekap` (JSON), `/rekap/xlsx`, `/rekap/pdf`, `/rekap-il[/xlsx]` |
| **Pemilik pelaksanaan** | Fase 5B, milestone M5 (MAKE-041) |
| **Terkait** | ADR-032 (PhpSpreadsheet), ADR-033 (TCPDF + `PdfRendererInterface`), ADR-034 (PDF sinkron dokumen daftar, pola batas baris), PRD `docs/fase5/PRD_Fase5B_Cuti-TB-LaporanTukin-UangMakan.md` §7.3.5, §7.3.7, F5B-11, F5B-16 |

## 1. Konteks

- Legacy membangun rekap Tukin (`laporan_tukin_us_skp`) di dalam request dan mengekspor XLSX dengan PhpSpreadsheet, tanpa PDF
  (cetak HTML). PRD 5B F5B-16 menambah PDF rekap [V2], dibatasi per unit/satker dan jumlah baris.
- PRD §7.3.7 mensyaratkan jumlah kueri tetap terhadap jumlah pegawai (batch 500) dan uji volume 5.000 pegawai × 2 periode
  presensi. Target F5B-11: JSON halaman 1 (100 baris) ≤ 5 detik; XLSX kementerian ≤ 60 detik & ≤ 256 MB; PDF satker
  ≤ 30 detik & ≤ 256 MB; batas PDF awal 1.700 baris (angka ADR-034).

## 2. Keputusan

1. **Ekspor sinkron** (dalam request, tanpa antrean/job), sama dengan ADR-034. Kalkulasi Tukin tetap satu jalur
   (`Laporan\KalkulasiLaporanTukin`: `TukinDataService::siapkanBanyak` + `KalkulasiTukin::hitung`) untuk JSON, XLSX, PDF,
   dan laporan per pegawai.
2. **Kalkulasi per batch** `Config\Presensi::$tukinBatchNip` (500): argumen dan hasil harian satu batch dilepas sebelum
   batch berikutnya. Uji volume pertama tanpa pemotongan ini: XLSX kementerian 364 MB (di atas target).
3. **JSON**: kalkulasi hanya untuk pegawai di halaman yang diminta (urutan populasi stabil).
4. **PDF rekap** hanya lingkup unit/satker dan maksimal `Config\Presensi::$pdfRekapMaksBaris` baris, **bawaan 800**
   (diturunkan dari 1.700 — §3). Di atas batas, lingkup kementerian (`0`), atau IL → 422 `errors.unit_satker` "Persempit
   lingkup atau unduh XLSX.", dihitung dari populasi sebelum kalkulasi dan render. Override `.env`
   `presensi.pdfRekapMaksBaris`.
5. **XLSX** tetap untuk seluruh lingkup tanpa batas baris.
6. Ekspor tidak diaudit (F5B-11).
7. Uji volume `backend/tests/Presensi/Laporan/RekapTukinVolumeTest.php` (`#[Group('volume')]`, di-skip kecuali env
   `RUN_VOLUME_TEST=1`). Satker A1 diisi **tepat** sebanyak `$pdfRekapMaksBaris`. Jalankan ulang setiap kali batas, kolom
   PDF, view `pdf/rekap_tukin`, atau `TcpdfRenderer` berubah.

## 3. Hasil uji volume (28-10-2026)

Mesin lokal: PHP 8.4.2, MySQL 8.0.30 (instance uji port 3307), PHPUnit `-d memory_limit=1G`. Data: 5.000 PNS sintetis,
presensi hari kerja 16 Jul – 15 Sep 2026 (± 44 hari per pegawai), bulan laporan 2026-09 (periode 16 Agu – 15 Sep). Memori
= `memory_get_peak_usage(true)` per permintaan setelah `memory_reset_peak_usage()`.

| Putaran | Skenario | Baris | Waktu | Memori puncak | Hasil |
|---|---|---|---|---|---|
| 1 (tanpa batch kalkulasi) | XLSX kementerian | 5.000 | < 41 detik | 364 MB | ✗ memori |
| 2 (batch 500) | JSON halaman 1 | 100 dari 5.000 | 0,64 detik | 40 MB | ✓ |
| 2 | XLSX kementerian | 5.000 | 10,47 detik | 148 MB | ✓ |
| 2 | PDF satker | 1.700 | 20,21 detik | 440 MB | ✗ memori |
| 3 (batch 500, batas 800) | JSON halaman 1 | 100 dari 5.000 | 0,26 detik | 40 MB | ✓ |
| 3 | XLSX kementerian | 5.000 | 9,70 detik | 148 MB | ✓ |
| 3 | PDF satker | 800 | 5,96 detik | 246 MB | ✓ |
| 3 | PDF unit di atas batas | 4.200 | — | — | 422 sebelum kalkulasi ✓ |

PDF rekap memuat 16 kolom per baris (lebih banyak daripada daftar ADR-034), sehingga memori TCPDF per baris lebih besar dan
batas 1.700 tidak memenuhi target 256 MB.

## 4. Konsekuensi

- Satker dengan lebih dari 800 pegawai memakai XLSX (atau FE 5C mencetak dari JSON); hal ini dicatat untuk QA dan
  pengguna admin. Nilai produksi bisa disesuaikan lewat `.env` setelah diukur di server produksi.
- Bila kebutuhan PDF besar muncul, alternatifnya ekspor asinkron (antrean) — di luar cakupan 5B.
