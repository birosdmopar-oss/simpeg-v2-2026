# Waktu — stempel UTC dan tanggal bisnis WIB

## Aturan stempel audit (CR-086, keputusan Horii 30-10)

- Kolom audit DATETIME (`created_at`, `updated_at`) berisi **UTC** dan **diisi aplikasi** (`appTimezone = UTC`). Default DB
  (`DEFAULT CURRENT_TIMESTAMP`, `ON UPDATE CURRENT_TIMESTAMP`) hanya cadangan dan mengikuti zona **sesi** DB (lokal/dev = WIB),
  jadi tidak boleh diandalkan (DBV-011 §1.2, DBV-022 G2, docblock migration LKH).
- Tidak memakai `SET time_zone` sesi dan tidak mengubah skema.
- Penulis: model yang extends `BaseAuditableModel` mengisi `created_at` (insert) dan `updated_at` (update) dengan `gmdate()`
  bila kolomnya ada di `$allowedFields` dan pemanggil tidak mengirim nilainya. Model baru dengan kolom stempel: masukkan
  `created_at`/`updated_at` ke `$allowedFields`. Penulis lewat Query Builder wajib mengirim `gmdate('Y-m-d H:i:s')` sendiri.
  `updated_at` saat insert diisi hanya bila default kolomnya memang bernilai (mis. `riwayat_tb`/`riwayat_ib`, ditulis di
  `StudiPengajuanService::tambah`); kolom `updated_at NULL DEFAULT NULL` tetap NULL saat insert.
- Pembaca yang memotong/mem-parse stempel sebagai tanggal atau jam bisnis memakai `StempelUtc`:
  `tanggalWib()`, `keWib()`, `keIsoWib()`, `epoch()` (dan `awalHari()`/`awalHariBerikut()` untuk batas rentang kueri, CR-083).
  Payload API tetap UTC tanpa zona (klien mem-parse sebagai UTC) kecuali `tanggal_pengajuan` Status Layanan yang dikirim
  ISO-8601 dengan offset `+07:00` agar potongan 10 karakter = tanggal WIB.
- Kode/QR yang memakai `strtotime(created_at)` legacy (tanda terima usulan, LKH): legacy menyimpan jam dinding WIB di server
  WIB; epoch dari stempel UTC v2 = epoch yang sama, tahun `yy` = tahun WIB (`TandaTerimaUsulan::kode/noTiket`,
  `LkhService::kunciQr`). Bergantung pada **GAP-2** (zona server legacy; menunggu DBV).

## Audit & normalisasi baris yang sudah tertulis

```bash
php spark stempel:normalisasi-utc --audit
php spark stempel:normalisasi-utc semua --dari="2026-10-01 00:00:00" --sampai="2026-10-30 12:00:00"            # dry-run (bawaan)
php spark stempel:normalisasi-utc riwayat_tb --dari="..." --sampai="..." --offset=7 --eksekusi                  # menulis
```

- Baris yang ditulis zona sesi **tidak dapat dibedakan otomatis** dari baris UTC (impor R1a sudah UTC). Karena itu jendela
  `--dari/--sampai` (atas `created_at` tersimpan) **wajib**: mulai dari pemakaian tulis v2 sesudah impor sampai waktu CR-086
  dideploy. Petunjuk: `--audit` melaporkan `masa_depan` (baris lebih baru dari UTC sekarang = pasti zona sesi di timur UTC).
- `updated_at` ikut digeser hanya bila sama persis dengan `created_at`; selainnya dilaporkan `updated_at_tak_pasti` dan
  tidak disentuh. Daftar tabel: `NormalisasiStempel::TABEL`. Jalankan hanya setelah disetujui dan di DB yang benar.
