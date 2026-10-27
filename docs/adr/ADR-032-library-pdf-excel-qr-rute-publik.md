# ADR-032 — Library PDF/Excel/QR & pola rute publik (Slip Gaji Fase 5A)

| | |
|---|---|
| **Status** | **Diterima 19-10-2026** (keputusan user F5-17, dikunci di PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` §8.4) |
| **Cakupan** | Fase 5A: impor Slip Gaji (D-10), PDF slip + QR (D-11), verifikasi QR publik (D-14) |
| **Pemilik pelaksanaan** | Paket 5A, milestone M3 (MAKE-030, dependensi Excel + ADR ini) dan M4 (MAKE-031, PDF/QR/rute publik) |
| **Terkait** | ADR-033 (TCPDF, Fase 3), ADR-005 (logika di Library/service), ADR-025 (FE tidak menentukan aturan bisnis) |

## 1. Konteks

- Slip Gaji legacy (`771f8b4`) mencetak PDF lewat `PdfCreator extends TCPDF` (`libraries/hr/Lsl_gaji.php`), menaruh QR
  verifikasi di PDF, dan membaca berkas Excel impor (sheet `GPP` dan `TK`).
- Draf lama ADR-032 (di luar repo) mengusulkan Dompdf; ADR-033 (07-10-2026) sudah memilih TCPDF untuk seluruh aplikasi
  dan menyarankan ADR-032 diselaraskan.
- Verifikasi keaslian slip harus dapat dibuka tanpa login dari QR yang tercetak, termasuk QR pada PDF lama yang menunjuk
  path legacy `/verify/slip/{token}` (SG-1).

## 2. Keputusan

| Butir | Keputusan |
|---|---|
| PDF | TCPDF lewat `App\Libraries\Pdf\Pdf` (ADR-033, sudah di `main`); satu generator `PdfSlipGaji` |
| QR | `Pdf::qrImg` (TCPDF2DBarcode) di backend; tanpa paket QR baru; ekstensi `gd` wajib — tanpa cadangan berupa teks token |
| Excel | `phpoffice/phpspreadsheet` **^5.10** (5.10.0 = rilis stabil terbaru saat M3, mendukung PHP ^8.2; CI PHP 8.4; Q5A-19). Ekstensi PHP yang diwajibkan paket dicantumkan di `backend/composer.json` (ctype, dom, fileinfo, gd, iconv, libxml, mbstring, simplexml, xml, xmlreader, xmlwriter, zip, zlib) dan terverifikasi di CI quality-gate. Hanya dipakai di `Libraries/Presensi/Slip` (`PembacaExcelSlip`); **dilarang** di folder murni `Libraries/Presensi/SlipGaji` dan `Libraries/Presensi/Tukin` (dijaga pemindai kemurnian) |
| Rute publik | Halaman SPA `/verify/slip/:token` (di luar `api/v1`, path legacy) + endpoint data `GET api/v1/publik/verifikasi-slip/{token}` di grup **tanpa** `jwt` dengan filter `throttle` (Throttler CI4, 30 permintaan/menit per IP; kunci bucket = hash IP; IP tidak disimpan di DB, log, maupun audit). Satu-satunya route 5A tanpa `jwt` |

## 3. Lisensi

| Paket | Lisensi | Catatan |
|---|---|---|
| `tecnickcom/tcpdf` | LGPL-3.0-or-later | Sudah dipakai (ADR-033) |
| `phpoffice/phpspreadsheet` | MIT | Dependensinya `maennchen/zipstream-php`, `markbaker/complex`, `markbaker/matrix`, `psr/simple-cache` juga MIT |

Semua dipasang lewat Composer; tidak ada kode pihak ketiga yang disalin ke repo.

## 4. Alternatif

| Opsi | Status |
|---|---|
| Dompdf untuk PDF slip | Tidak dipilih: dua library PDF; TCPDF sama dengan legacy dan ADR-033 |
| Paket QR terpisah (mis. `endroid/qr-code`) | Tidak dipilih: TCPDF2DBarcode sudah tersedia lewat TCPDF |
| Parser Excel ringan (mis. SimpleXLSX) | Tidak dipilih: tidak mendukung `.xls` lama dan perhitungan rumus; legacy menerima `.xlsx` dan `.xls` |
| Verifikasi lewat endpoint ber-login | Tidak dipilih: QR dipindai pihak luar tanpa akun (SG-1) |

## 5. Konsekuensi

- Pembaca Excel membaca **nilai mentah** sel (`setReadDataOnly(true)`, rumus dihitung, tanpa format tampilan) sebagai
  masukan `PratinjauImpor::uraiSheet` murni; berkas rusak → pesan generik tanpa exception mentah.
- Berkas impor divalidasi `ValidasiBerkas::periksaImpor` (MIME dari isi, ekstensi ≙ isi, OOXML/OLE, ukuran dari disk)
  dan disimpan sementara di `writable/` bernama acak (bukan nama klien), dihapus setelah diproses.
- Endpoint publik: validasi format token sebelum query, respons "tidak ditemukan" seragam, `X-Robots-Tag: noindex,
  nofollow`, `Cache-Control: no-store`, data minimum AS-06 dari snapshot saat token terbit.
- Catatan deploy (rute Nginx `/verify/slip/*` ke SPA, HTTPS, ekstensi PHP, direktori `writable/uploads/slip-impor/`)
  ditulis di README controller Presensi (PRD 5A §8.5), tanpa nama host/IP.
