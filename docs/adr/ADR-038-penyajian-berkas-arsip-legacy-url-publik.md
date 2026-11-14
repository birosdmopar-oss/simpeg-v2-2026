# ADR-038 — Penyajian berkas arsip legacy lewat URL publik (F-10)

| | |
|---|---|
| **Status** | **Diusulkan 12-11-2026** (F-10 Arsip Digital, MAKE-093; keputusan bisnis Horii 12-11-2026, PRD F-10 v1.3 §3.1 F10-18) |
| **Cakupan** | **Hanya berkas legacy** di tabel `document_attachment`, yaitu path berpola `arsip/…` dan `assets/upload/…` hasil salinan MAKE-069. Berkas unggahan v2 (kunci `YYYY/MM/<32 hex>.<ext>`) **di luar** cakupan dan tetap mengikuti pola privat sejak Fase 3 (lihat §2 butir 2) |
| **Pemilik pelaksanaan** | MAKE-090 (`Config\Arsip`, `PenyajiUrlBerkas`, endpoint `buka`, rute dev), MAKE-091 (FE "Buka"), MAKE-093 (Nginx, `README-deploy.md` §1 dan §1l, ADR ini) |
| **Terkait** | PRD F-10 v1.3 (`docs/fase7/PRD_Fase7_F10_Arsip-Digital.md`) §3.1 F10-08/F10-18, §3.2 F10-23..F10-25, F10-32..F10-37, §7, §14 (Plan B), Lampiran A. Penyimpanan lampiran: ADR-015 (adapter di balik interface, dokumen utama `SIMPEG_v2_ADR.docx`), `StorageAdapterInterface` + `LocalStorageAdapter` (B-18 MAKE-009), dan `docs/migrasi/MAKE-069-rencana.md` §4 (K-M3 opsi A: berkas legacy disalin ke storage v2 dengan struktur relatif sama). Runbook: `README-deploy.md` §1l |

## 1. Konteks

- **Legacy.** Semua lampiran `document_attachment` dibuka lewat `pathToSrc(path)`, yaitu URL publik tanpa sesi
  (`legacy:helpers/function_helper.php:307-326`).
  - Path `arsip/…` disajikan dari server arsip.
  - Path lain (`assets/upload/…`) disajikan dari server aplikasi.
  - Halaman arsip membukanya di popup 600×600 (`legacy:views/hr/employee/arsip/list.php:81`).
  - Tidak ada cek izin per berkas dan tidak ada log unduh.
- **v2 sejak Fase 3.** Lampiran privat:
  - endpoint ber-JWT + izin Definisi + `PegawaiScope` (`GET pegawai/{nip}/lampiran/{id}/unduh`);
  - `attachment`, `nosniff`, `no-store`;
  - path tidak pernah dikirim ke klien (`AttachmentService::untukKlien()`).
- **MAKE-069 K-M3 opsi A.** Berkas legacy disalin ke akar lampiran v2 (`writable/uploads/lampiran`) dengan struktur relatif yang sama. Path di DB tetap path legacy. `LocalStorageAdapter` membaca path legacy lewat `PathLegacy::normalisasi()`, dengan hasil `realpath` wajib tetap di dalam akar. Server arsip terpisah dan J3.06 gugur (F10-01, F10-02).
- **F-10.** Membuat halaman "Arsip" yang menampilkan semua lampiran seorang pegawai dan membukanya sebagai pratinjau di browser (F10-10). Horii diberi dua rencana (Plan A: semua lewat URL publik; Plan B: penyesuaian) beserta risikonya (PRD §14).

## 2. Keputusan

Keputusan diambil Horii pada 12-11-2026 (pilihan per butir Plan B: PB-1 = B, PB-2 = B, digabung sebagai "PB-2 + audit PB-1"; PRD §14.1, F10-18):

1. **Berkas legacy dibuka persis legacy** (F10-18 (a)), yaitu path yang cocok dengan salah satu dari dua regex legacy di `Config\Arsip`:

   ```text
   ^arsip/(?:[^/.][^/]*/)*[^/.][^/]*$
   ^assets/upload/(?:[^/.][^/]*/)*[^/.][^/]*$
   ```

   - Berkas disajikan lewat URL publik `<arsip.dasarUrlBerkas>/<path ternormalisasi, ter-encode per segmen>` (bawaan `/berkas/…`).
   - Penyajinya Nginx, langsung dari akar lampiran, **tanpa** cek login atau izin per berkas, dan tanpa audit unduh.
   - Backend hanya **membentuk** URL di respons daftar arsip, yang sudah terautentikasi dan tersaring lingkup.
   - Pola privat Fase 3 **dibalik secara sadar** untuk berkas legacy saja. Endpoint lama (B-18 `unduh`, foto, Konket, Plt/Plh, layanan) tetap privat.
2. **Berkas unggahan v2 tetap privat** (F10-18 (b)). Ini batas cakupan ADR ini.
   - Berkas dibuka lewat `GET pegawai/{nip}/arsip/berkas/{id}/buka`: JWT, role 1–7, `PegawaiScope` lihat, izin sama dengan daftar arsip (F10-30).
   - Respons memakai daftar tipe tertutup, `nosniff`, `private, no-store`, `Referrer-Policy`, dan CSP (F10-34).
   - Setiap pembukaan diaudit `arsip_unduh` (F10-35) dan di-throttle per akun (F10-36).
   - Pola kunci v2 **tidak** ada di Nginx; `/berkas/YYYY/MM/<kunci>` → 404.
3. **Penguatan Nginx tanpa cek izin** (F10-25, F10-32, PRD §7.3; runbook `README-deploy.md` §1l):
   - `location ^~ /berkas/` tanpa PHP, `autoindex off`, `return 404` untuk path di luar dua pola;
   - hanya pdf/jpg/jpeg/png/gif yang `inline`, lainnya `application/octet-stream` + `attachment`;
   - header `nosniff`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, dan CSP tanpa `sandbox` (bila CSP mematahkan pratinjau, pratinjau didahulukan);
   - `access_log off` dan `log_not_found off` (URL memuat NIP);
   - `disable_symlinks on from=<akar>`, tanpa symlink di dalam akar (B10-1).
4. **Hak baca.** Hak berkas `0640`/`0750` yang ditulis PHP tidak diubah. User Nginx masuk ke grup pemilik berkas (atau keduanya memakai user yang sama).
5. **Satu sumber regex.** Kedua regex legacy didefinisikan sekali di `Config\Arsip` dan disalin apa adanya ke contoh Nginx `README-deploy.md` §1/§1l. Test pembanding string `backend/tests/unit/Deploy/ReadmeBerkasNginxTest.php` menjaga keduanya tetap sama, dan menjaga agar regex kunci v2 tidak muncul di README.
6. **Saklar rollback** khusus berkas legacy: `Config\Arsip::$penyajianBerkasLegacy` (`.env` `arsip.penyajianBerkasLegacy`).
   - `'publik'` adalah bawaan.
   - `'privat'` = berkas legacy ikut dibuka lewat endpoint `buka`, dengan audit dan throttle yang sama (F10-24).
   - Berkas unggahan v2 tidak dipengaruhi saklar.
7. **Mesin dev tanpa Nginx.** Rute `GET berkas/(:any)` (`BerkasDevController`) hanya didaftarkan bila `ENVIRONMENT === 'development'` dan `arsip.sajiBerkasDev = true`. Rute ini hanya melayani dua pola legacy, dengan tipe dan header yang sama (PRD §7.4).

## 3. Risiko yang diterima (keputusan sadar Horii)

| # | Risiko | Mitigasi yang tetap dipasang |
|---|---|---|
| R1 | URL berkas legacy bisa ditebak (memuat NIP + nama berkas) | Tanpa listing folder; log akses/not-found dimatikan. Berkas unggahan v2 tidak punya URL publik |
| R2 | KTP/NPWP/BPJS **legacy** (kode 38 hasil salinan) terbuka bagi yang tahu URL, mis. URL yang dibagikan atau tersimpan di riwayat browser | `Cache-Control: no-store`, `Referrer-Policy: no-referrer`. Kode 38 unggahan v2 privat |
| R3 | Audit unduh tidak mungkin untuk berkas legacy lewat URL publik (sebagian) | Pembukaan **daftar** dicatat (F10-17, fail-closed); pembukaan berkas lewat jalur privat dicatat (pola F-09, F10-35) + throttle (F10-36) |
| R4 | Pola privat Fase 3 dibalik, **hanya** untuk berkas legacy | Endpoint lama tetap privat; berkas unggahan v2 privat; saklar rollback (§4) |
| R5 | Berkas legacy disajikan dari alamat aplikasi (cookie sesi `SameSite=Lax`), sehingga HTML/SVG legacy bisa menjadi XSS. Di jalur privat, object URL `blob:` berada di origin aplikasi | Nginx: hanya pdf/gambar inline, lainnya octet-stream + `attachment`, `nosniff`, tanpa PHP, CSP. Jalur privat: daftar tipe tertutup + header yang sama di endpoint `buka`, tipe blob dari daftar tertutup di FE (F10-37). Opsi alamat terpisah (§5) |
| R6 | Alias `arsip/` dan `assets/upload/` membuka **seluruh** subtree salinan MAKE-069, termasuk berkas kolom lain yang mungkin ada di sana (surat cuti, berkas e-sign, bukti izin presensi, foto) | [K] Sama dengan legacy. Jumlahnya dihitung dari manifest (QF10-2) [I] |
| R7 | Berkas legacy yang barisnya dihapus (Hapus arsip yatim = baris saja, F10-09) tetap bisa dibuka lewat URL lama | Retensi berkas yatim = blocker cutover B10-1. Berkas unggahan v2 yang barisnya terhapus → 404 di `buka` |
| R9 | Lampiran + ringkasan riwayat yang tersembunyi di tab B-18 (mis. hukdis) tampil di Arsip bagi role yang boleh melihat pegawai itu (F10-30) | Tidak ada, sesuai legacy (PB-3 = A) |
| R10 | Saat rollback `'privat'`, berkas legacy (tanpa batas unggah v2) dimuat seluruhnya ke memori PHP lewat `baca()`, tanpa streaming [I] | Cek ukuran maksimum dari D1/manifest sebelum rollback dipakai; streaming = CR terpisah bila perlu |

R8 (v1.1: lampiran layanan 43–50 termasuk surat cuti ber-TTE terbuka publik) **hilang** di v1.2, karena berkas unggahan v2 privat (PB-2 = B).

## 4. Cara kembali (rollback, tanpa deploy kode dan tanpa ubah DB)

1. `arsip.penyajianBerkasLegacy = privat` di `.env` server. Respons daftar lalu memberi `url_berkas` = URL `buka` + `akses_berkas: "privat"` untuk berkas legacy, dan FE membukanya sebagai blob dengan audit + throttle. Sebelum dipakai, cek ukuran berkas legacy terbesar (R10).
2. Hapus blok Nginx `location ^~ /berkas/` (dan `map`), lalu `nginx -t && reload`. Semua URL publik lama menjadi 404.

Kedua mode saklar dijaga test (MAKE-090). Mengubah dua regex legacy (mis. mengizinkan segmen berawalan titik, F10-23) dilakukan bersamaan di `Config\Arsip`, README, dan Nginx server. Test pembanding menolak bila ketiganya tidak sama.

## 5. Opsi lanjutan (belum diputus)

- **Alamat berkas terpisah** lewat `arsip.dasarUrlBerkas`, tanpa ubah kode. Catatan: subdomain dari domain yang sama termasuk *same-site*. Akibatnya:
  - cookie `SameSite=Lax` tetap terkirim pada POST dari subdomain;
  - form-urlencoded tidak memicu preflight (`ApiController.php:120-134`).

  Jadi subdomain **tidak** menggantikan daftar tipe tertutup + `nosniff`. Isolasi penuh butuh domain terdaftar yang berbeda.
- **URL bertanda tangan berumur pendek** untuk berkas legacy, sebagai jalan tengah antara publik dan privat, bila kelak diputus Horii.
- **Streaming** di endpoint `buka`, bila rollback `'privat'` dipakai untuk berkas legacy berukuran besar (R10).

## 6. Alternatif

| Alternatif | Alasan tidak dipilih |
|---|---|
| Plan A v1.1: semua berkas (termasuk unggahan v2 dan surat cuti ber-TTE) lewat URL publik | Membuka berkas yang lahir privat di v2 (R8); Horii memilih PB-2 = B |
| Semua berkas privat lewat endpoint `buka` (PB-1 tanpa PB-2) | Tidak persis legacy untuk berkas legacy; Horii memilih berkas legacy publik. Tetap tersedia sebagai rollback (§4) |
| Server arsip terpisah seperti legacy (J3.06) | Gugur (F10-01, F10-02): MAKE-069 opsi A menyalin berkas ke storage v2 |
| PHP menyajikan berkas legacy publik tanpa Nginx di server | Membebani PHP-FPM; nama berkas di luar `permittedURIChars` CI4 tidak bisa dilayani. Rute PHP hanya untuk mesin dev (§2 butir 7) |

## 7. Konsekuensi

- `writable/` kini punya **satu** pengecualian sempit yang terjangkau web: dua pola legacy di bawah `uploads/lampiran`. Ketentuan "`writable/` tidak terjangkau web" tetap berlaku untuk isi lainnya (`README-deploy.md` §1l).
- Pasang/ubah setelan Nginx menjadi syarat QA (G10-4, server dev) dan rilis produksi (B10-3). Hasil verifikasi `curl -I` dan uji tiga browser dicatat di `README-deploy.md` §1l.
- Invarian "path tidak pernah ke klien" (`AttachmentService`) dilanggar sadar **hanya** untuk `url_berkas` berkas legacy di endpoint Arsip (`akses_berkas: "publik"`).
- QR surat cuti ber-TTE legacy yang menunjuk server arsip lama tetap blocker cutover di luar F-10 (B10-2). Alias `/berkas/arsip/…` membuka opsi redirect QR lama ke v2.
