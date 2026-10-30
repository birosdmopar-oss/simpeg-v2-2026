# Waktu — stempel UTC dan tanggal bisnis WIB

## Aturan stempel audit (CR-086, DBV-025; keputusan Horii 30-10)

- Kolom audit DATETIME (`created_at`, `updated_at`) berisi **UTC**. Aplikasi mengisinya eksplisit (`gmdate()`); default DB
  (`DEFAULT CURRENT_TIMESTAMP`, `ON UPDATE CURRENT_TIMESTAMP`) hanya cadangan.
- **Zona sesi DB = UTC** untuk setiap koneksi aplikasi (web, `spark`, worker, test): driver `App\Database\MySQLi`
  menjalankan `SET time_zone = '+00:00'` saat connect/reconnect (penerapan DBV-011 §3.3). Dengan begitu default/ON UPDATE
  yang terlewat tetap UTC. `Config\Database` memaksa driver ini untuk grup `default`/`tests` (jangan menyetel
  `database.*.DBDriver = MySQLi` di `.env`). Offset angka dipilih (tanpa tabel zona; valid di MySQL 8 dan MariaDB 10.4).
  Tidak ada callback model yang mengisi stempel: model tetap hanya menulis kolom yang dikirim pemanggil.
- Pembaca yang memotong/mem-parse stempel sebagai tanggal atau jam bisnis memakai `StempelUtc`:
  `tanggalWib()`, `keWib()`, `keIsoWib()`, `epoch()` (dan `awalHari()`/`awalHariBerikut()` untuk batas rentang kueri, CR-083).
  Payload API tetap UTC tanpa zona (klien mem-parse sebagai UTC) kecuali `tanggal_pengajuan` Status Layanan yang dikirim
  ISO-8601 dengan offset `+07:00` agar potongan 10 karakter = tanggal WIB.
- Kode/QR yang memakai `strtotime(created_at)` legacy (tanda terima usulan, LKH): legacy menyimpan jam dinding WIB di server
  WIB; epoch dari stempel UTC v2 = epoch yang sama, tahun `yy` = tahun WIB (`TandaTerimaUsulan::kode/noTiket`,
  `LkhService::kunciQr`). Bergantung pada **GAP-2** (zona server legacy; menunggu DBV).
- Tanggal bisnis dihitung di PHP (`TanggalBisnis`, WIB), bukan `CURDATE()`/`NOW()` di SQL.

## Baris yang sudah tertulis zona sesi

v2 belum di-deploy ke server mana pun; baris campur zona hanya ada di DB lokal/demo/scratch. Tidak ada perintah normalisasi:
**reset DB lokal/demo** setelah perubahan ini (demo: `reset_demo.sh`). Kueri audit (tanpa data pribadi) bila perlu memeriksa:
baris dengan `created_at` lebih baru dari `UTC_TIMESTAMP()` pasti tertulis zona sesi di timur UTC, mis.
`SELECT COUNT(*) FROM riwayat_tb WHERE created_at > UTC_TIMESTAMP();` untuk tiap tabel berstempel.
