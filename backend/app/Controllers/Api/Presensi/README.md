# Modul D — Presensi & Remunerasi (Fase 5)

Controller REST API modul ini (`App\Controllers\Api\Presensi\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` (lihat `Config\Routes`).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## Catatan deploy (PRD Fase 5A §8.5, Q5A-22)

Dieksekusi tim infra saat cutover; tanpa nama host/IP di repo.

- **Ekstensi PHP wajib** (`backend/composer.json` `require`; `composer install` gagal bila tidak ada): `ctype`, `curl`,
  `dom`, `fileinfo`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`.
  `gd` **wajib, bukan opsional**: sudah diwajibkan sebelum Fase 5A, juga diwajibkan `phpoffice/phpspreadsheet` sendiri,
  dan dipakai gambar QR PDF slip (`Pdf::qrImg`); PRD 5A melarang cadangan QR berupa teks token (ADR-032).
- **Direktori tulis:** `writable/uploads/slip-impor/` (pratinjau impor Slip Gaji, dibuat otomatis bila belum ada) harus
  dapat ditulis proses PHP dan **tidak** dilayani web server.
- **Rute SPA publik:** path `/verify/slip/*` diarahkan ke SPA frontend sejak switch produksi (SG-1; QR PDF lama dan baru
  menunjuk path ini), dengan header `X-Robots-Tag: noindex, nofollow`.
- **HTTPS wajib** (geolokasi browser untuk presensi online hanya jalan di konteks aman).
- **Key `.env` opsional** (`presensi.*`, tanpa nilai rahasia): `presensi.maksUkuranImporByte`,
  `presensi.maksBarisImporPerSheet`, `presensi.ttlPratinjauDetik` (bilangan bulat positif; nilai tidak sah diabaikan).
  Key lain ditambahkan milestone berikutnya (mis. URL verifikasi QR, akun presensi manual).
