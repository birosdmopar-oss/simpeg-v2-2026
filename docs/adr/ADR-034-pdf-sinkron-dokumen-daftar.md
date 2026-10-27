# ADR-034 — PDF sinkron untuk dokumen daftar (F-09 Generator Dokumen)

| | |
|---|---|
| **Status** | **Diusulkan 21-10-2026** (MAKE-037 M3; fallback F7-15 sudah LOCKED di PRD Fase 7, angka batas menunggu konfirmasi reviewer CR — PRD §13 Q-6, Q-9) |
| **Cakupan** | PDF dokumen daftar Fase 7 F-09: DUK, Nominatif, Proyeksi Pensiun, Baperjakat (`GET api/v1/dokumen/{jenis}/pdf`, `POST api/v1/dokumen/baperjakat/pdf`) |
| **Pemilik pelaksanaan** | Fase 7 paket pertama, milestone M3 (MAKE-037) |
| **Terkait** | ADR-033 (TCPDF + `PdfRendererInterface`), PRD `docs/fase7/PRD_Fase7_Statistik-GeneratorDokumen.md` F7-15/F7-16, §7.2.7 |

## 1. Konteks

- Legacy menampilkan DUK/Nominatif/Pensiun/Baperjakat sebagai tabel HTML dan ekspor Excel. Cetak PDF Baperjakat legacy tidak berfungsi. F7-15 [V2] menambah PDF lanskap A4 untuk keempat dokumen.
- Dokumen daftar bisa memuat seluruh pegawai kementerian. PRD §7.2.7 mensyaratkan uji volume ≥ 5.000 baris. Targetnya PDF ≤ 30 detik dan puncak memori ≤ 256 MB, XLSX ≤ 10 detik dan ≤ 128 MB. Bila PDF gagal, berlaku fallback LOCKED: PDF hanya per unit/satker, XLSX tetap untuk semua lingkup.
- Pembangkit PDF memakai pembungkus ADR-033 (`Pdf::renderer()` → `TcpdfRenderer`). Badan dokumen ditulis dengan satu `writeHTML`. Pembungkus itu milik Fase 3 dan tidak diubah paket ini.

## 2. Keputusan

1. **PDF sinkron** (dibangun di dalam request, tanpa antrean/job). HTML tabel dibangun bertahap di `RenderDokumenPdf` lalu diserahkan ke `DokumenPdf` dan `Pdf::renderer()`. Isinya subset kolom inti (F7-16), kop dari `KopDokumen` (`web_config`, tanpa logo), dan kaki berisi pencetak. Satu view generik `Views/pdf/dokumen_daftar.php` dipakai keempat jenis, sebagai pengganti empat view yang direncanakan PRD §7.2.1.
2. **Fallback F7-15 diterapkan**, karena uji volume PDF lingkup kementerian gagal (lihat §3):
   - PDF hanya untuk `lingkup=unit|satker`. Lingkup kementerian → 422 `errors.lingkup` "PDF hanya tersedia per unit/satker.".
   - PDF maksimal **1.700 baris** (`DokumenService::PDF_MAKS_BARIS`). Di atas batas → 422 `errors.lingkup` "Persempit lingkup atau unduh XLSX.". Untuk Baperjakat PDF, batas yang sama berlaku pada jumlah NIP (`errors.nip`).
   - Jumlah baris dihitung dengan `COUNT` sebelum baris diambil. Pengecualiannya Pensiun, yang disaring di PHP menurut BUP per jabatan. Penolakan terjadi sebelum throttle dan render, jadi tidak memakan jatah unduhan dan tidak diaudit.
   - XLSX tetap untuk seluruh lingkup, tanpa batas baris.
3. Uji volume ada di `tests/Dashboard/DokumenVolumeTest.php` (`#[Group('volume')]`). Test ini di-skip kecuali env `RUN_VOLUME_TEST=1`, karena berat dan tidak perlu di setiap CI. `phpunit.dist.xml` (berkas bersama) tidak diubah. Jalankan ulang setiap kali `PDF_MAKS_BARIS`, kolom PDF, atau `TcpdfRenderer` berubah.

## 3. Hasil uji volume (21-10-2026)

Mesin lokal: Intel Core i7-11370H, RAM 15,7 GB, Windows 11, PHP 8.4.2, MySQL 8.0.30 (instance uji port 3307). PHPUnit dijalankan dengan `-d memory_limit=1G`. Memori = `memory_get_peak_usage(true)` per permintaan (`memory_reset_peak_usage()` sebelum tiap permintaan). Angkanya sudah termasuk memori dasar proses uji, yaitu 34 MB sebelum XLSX dan 62 MB sebelum PDF.

| Skenario | Baris | Waktu | Memori puncak | Hasil |
|---|---|---|---|---|
| PDF DUK lingkup kementerian (percobaan awal, sebelum fallback) | 5.000 | — | > 512 MB (habis) | Gagal → fallback |
| PDF DUK satker | 2.000 | 13,2 s | 270 MB | Melewati 256 MB |
| PDF DUK satker | 1.500 | 7,0–8,6 s | 210–214 MB | Lolos |
| **PDF DUK satker (batas)** | **1.700** | **10,2–10,6 s** | **238 MB** | **Lolos**; 82 halaman, ±512 KB |
| XLSX DUK kementerian | 5.000 | 0,9–1,0 s | 104 MB | Lolos; ±672 KB |

- Memori PDF naik sekitar 112 MB per 1.000 baris. Batas 1.700 baris mencakup satker sekitar 1.667 pegawai (pembagian rata 5.000 pegawai ke 3 satker uji). Marginnya sekitar 7% terhadap 256 MB di proses uji. Request web mulai dari dasar memori yang lebih kecil, jadi marginnya lebih besar.
- XLSX semula memuncak 242 MB. Penyebabnya, tiga salinan penuh baris hidup bersamaan: hasil kueri, baris dokumen, dan sel XLSX. Baris sekarang dilepas satu per satu saat dipetakan, sehingga puncaknya turun ke 104 MB.

## 4. Alternatif yang ditolak

- **PDF seluruh kementerian lewat antrean/job (asinkron).** Ditolak untuk M3: butuh status job, penyimpanan berkas, dan UI unduh tertunda. PRD menetapkan PDF sinkron, dan XLSX sudah melayani lingkup kementerian.
- **Memecah `writeHTML` per potongan baris di `TcpdfRenderer`.** Berpotensi menurunkan memori, tetapi `TcpdfRenderer` milik Fase 3 (ADR-033) dan dipakai DRH/LKH. Ditunda sampai ada kebutuhan PDF lintas kementerian.
- **Menaikkan `memory_limit` server.** Tidak mengatasi pertumbuhan linear dan membebani server bersama.

## 5. Konsekuensi

- Pengguna yang butuh dokumen seluruh kementerian memakai XLSX. FE M4 menampilkan pesan 422 `errors.lingkup` apa adanya dan menawarkan tombol XLSX.
- `memory_limit` PHP produksi untuk endpoint ini perlu ≥ 256 MB. Angka produksi belum diketahui; dicatat untuk runbook deploy.
- Angka batas dapat disesuaikan reviewer bila mesin produksi jauh berbeda (PRD Q-9). Cukup ubah `PDF_MAKS_BARIS` dan jalankan ulang uji volume.
