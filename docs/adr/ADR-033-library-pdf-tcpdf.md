# ADR-033 — Library PDF: TCPDF untuk Fase 3 (LKH dan Cetak DRH)

| | |
|---|---|
| **Status** | **Diterima 07-10-2026** (keputusan user; semula diusulkan mPDF, dikoreksi ke TCPDF setelah pengecekan kode legacy menunjukkan mesin nyata = TCPDF; keputusan #7 di `docs/fase3/PRD_WS1.md` §9 dan `docs/fase3/PRD_WS2.md` §9) |
| **Cakupan** | PDF Fase 3: LKH (B-12b) dan Cetak DRH (B-20). Modul lain yang butuh PDF menyusul lewat ADR atau addendum sendiri |
| **Pemilik pelaksanaan** | WS-2, milestone M5 (CR-057) |
| **Terkait** | ADR-005 (logika di Library/service), draf ADR-032 (library PDF/Excel/QR & rute publik untuk Slip Gaji Fase 5, di luar repo, belum diputus) |

## 1. Konteks

- Fase 3 membutuhkan pembangkit PDF untuk dua keluaran:
  - **B-12b LKH**: PDF laporan kinerja harian (DoD B-12: "generate PDF").
  - **B-20 Cetak DRH**: Daftar Riwayat Hidup standar BKN, role 1, 2, 3, 4, 5 (Matriks v2, `docs/fase3/MATRIKS_ROLE_MODUL_B.md`).
- `backend/composer.json` v2 belum memuat library PDF apa pun.
- `composer.json` legacy memuat beberapa mesin PDF: `mpdf/mpdf` ^8.0, `tecnickcom/tcpdf` ^6.6.2, `mikehaertl/phpwkhtmltopdf` ^2.5, dan `setasign/fpdf` ^1.8.
- Pemakaian nyata di kode legacy (dicek pada commit legacy `39b6b15`):
  - `application/libraries/PdfCreator.php` adalah `class PdfCreator extends TCPDF`. Kelas ini dipakai untuk PDF LKH (`libraries/hr/rwy/L_lkh.php`, template `views/hr/employee/rwy/lkh/print/lkh_pdf.php`), DRH (`libraries/hr/L_employee.php::print_drh`, template `views/hr/employee/print/drh_pdf.php`), dan cetak slip gaji (`libraries/hr/Lsl_gaji.php`).
  - Template cetak legacy berupa **view HTML** yang dirender dengan `writeHTML`.
  - wkhtmltopdf dipakai untuk cetak bagan struktur organisasi (`controllers/hr/So.php`, `controllers/hr/rwy/Jabatan.php`).
  - Pemanggilan kelas mPDF tidak ditemukan di `application/`. Paket mPDF terpasang di legacy tetapi tidak dipakai oleh jalur LKH, DRH, atau slip.
- Draf ADR-032 (Fase 5) mencatat temuan yang sama dan mengusulkan Dompdf. Draf itu belum diputus.

## 2. Keputusan

1. v2 memakai **TCPDF ^6.6** (`tecnickcom/tcpdf`) sebagai pembangkit PDF untuk LKH dan Cetak DRH — mesin yang sama dengan jalur LKH/DRH/slip legacy (`PdfCreator extends TCPDF`).
2. Template cetak legacy (view HTML LKH dan DRH untuk `writeHTML`) dipakai ulang sebagai titik awal template v2, dengan penyesuaian seperlunya (kop, data dari service v2, escape output).
3. TCPDF dibungkus **antarmuka tipis** (mis. `PdfRendererInterface` di `app/Interfaces/`, implementasi di `app/Libraries/`). Controller dan service LKH/DRH hanya bergantung pada antarmuka, sehingga penggantian library tidak menyentuh kode LKH/DRH (lihat §4).
4. Paket ditambahkan ke `backend/composer.json` oleh WS-2 di M5 (CR-057).

## 3. Lisensi

- TCPDF berlisensi **LGPL-3.0-or-later**: boleh dipakai sebagai dependensi Composer tanpa mengubah lisensi kode v2 (`backend/composer.json` mendeklarasikan `"license": "MIT"`), selama TCPDF tidak dimodifikasi dan disalin ke repo.
- SIMPEG v2 dipakai **internal pemerintah**; repo v2 publik berisi kode sumber v2 saja, TCPDF dipasang lewat Composer.

## 4. Alternatif

| Opsi | Lisensi | Status |
|---|---|---|
| **TCPDF ^6.6** | LGPL-3.0-or-later | **Dipilih** (§2) — sama dengan legacy, template dipakai ulang |
| mPDF ^8 | GPL-2.0-only | Tidak dipilih: tercantum di `composer.json` legacy tetapi tidak dipakai jalur LKH/DRH/slip; template perlu disesuaikan; lisensi GPL perlu penilaian tambahan untuk repo publik ber-deklarasi MIT |
| dompdf | LGPL-2.1 | Cadangan bila TCPDF bermasalah; dipasang di balik antarmuka yang sama, template perlu disesuaikan |
| wkhtmltopdf | Biner eksternal | Tidak dipilih: butuh biner di server di luar Composer, menambah kebutuhan deploy |

## 5. Konsekuensi

- **Satu pemilik `composer.json`**: hanya WS-2 yang menambah dependensi PDF, di M5 (CR-057). WS-1 tidak menyentuh `composer.json` untuk keperluan PDF. Bila draf ADR-032 kelak diputus berbeda untuk Fase 5, keputusan itu harus menyelaraskan diri dengan ADR ini (satu library PDF untuk seluruh aplikasi) atau mencatat alasan memakai dua library.
- **Font dan aset**: font yang dibutuhkan template (termasuk logo kop) disediakan lokal di repo/deploy, tanpa unduhan saat runtime. Path aset relatif terhadap penyimpanan aset v2 (lihat catatan logo PDF di `backend/docs/db-review/G-09-web-config-schema.md`). Direktori cache/temp TCPDF (`K_PATH_CACHE`) diarahkan ke `WRITEPATH`.
- **Pengujian PDF**: test memeriksa bahwa keluaran adalah PDF valid (header `%PDF`), tipe konten, nama berkas, dan penegakan role (Matriks v2 / atasan langsung untuk LKH). Isi visual diperiksa di QA Lapis 1, bukan di unit test.
- **Gate**: penambahan paket diuji dengan `./check.sh` penuh (PHPStan level 5 termasuk kode pembungkus).
- **Port template**: template legacy ditulis untuk `writeHTML` TCPDF sehingga bisa dipakai ulang dengan penyesuaian kecil (data dari service v2, escape output).
- **Draf ADR-032 (Fase 5, Slip Gaji)** semula mengusulkan Dompdf; saat diputus, sebaiknya diselaraskan ke TCPDF (satu library PDF untuk seluruh aplikasi, juga sama dengan slip legacy).
