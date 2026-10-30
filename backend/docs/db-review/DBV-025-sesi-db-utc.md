# DB Validator Review — DBV-025 zona sesi database UTC untuk koneksi aplikasi

**Key review:** `DBV-025` (kode: `CR-086`). Judul PR: `[CR][DBV] Zona sesi DB UTC untuk koneksi aplikasi (CR-086, DBV-025)`.
Branch `fix/cr-086-created-at-utc`. Alur: PR `[CR]+[DBV]` — DB Validator me-review/approve, reviewer CR yang merge dengan izin Horii.

**Status:** ⏳ menunggu review DB Validator. Tidak ada migration dan tidak ada perubahan skema/data; yang berubah hanya
perilaku sesi koneksi dan pembaca stempel.

**Keputusan Horii 30-10:** sesi DB UTC **dan** pengisian eksplisit oleh aplikasi (bukan callback model). Callback
`BaseAuditableModel` hasil percobaan pertama (88d1523) dicabut: ia mengisi `updated_at` walau nilai tidak berubah dan
tidak menjangkau penulis Query Builder.

**Rujukan:** `DBV-011-runbook-impor-master-zona-waktu.md` §3.3 (usulan `SET time_zone = '+00:00'` per koneksi, opsi A), §3.5
dan butir R4–R6; `DBV-022` G2 (kolom audit UTC diisi aplikasi); GAP-2 (zona server legacy — menunggu DBV).

**Label:** **[V2]** deviasi v2 yang disengaja.

---

## 1. Latar

Kontrak proyek: kolom audit DATETIME (`created_at`, `updated_at`) berisi **UTC**, diisi aplikasi; default DB hanya cadangan.
Review berlapis CR-083 menemukan pelanggaran pra-ada: beberapa insert tidak mengirim `created_at` (mis. `riwayat_tb`,
`riwayat_ib`, `riwayat_pmk_req`, `riwayat_lckh`), sehingga terisi `DEFAULT CURRENT_TIMESTAMP` dalam zona **sesi** server
(lokal/dev = WIB). Penulis Query Builder ke kolom `ON UPDATE CURRENT_TIMESTAMP` (mis. `CutiService`, `NipCascadeService`)
punya masalah serupa. Satu kolom akhirnya berisi dua zona.

## 2. Penerapan [V2]

| Butir | Isi |
|---|---|
| Driver | `App\Database\MySQLi\Connection` (turunan `CodeIgniter\Database\MySQLi\Connection`): `connect()` = parent + `SET time_zone = '+00:00'`. Berlaku untuk web, `spark` (migrate/seed/CLI), worker, dan setiap reconnect/failover karena semuanya lewat `connect()` |
| Kelas pendamping | `Builder`, `Forge`, `PreparedQuery`, `Result`, `Utils` di `App\Database\MySQLi` — turunan kosong (CI4 menurunkan nama kelas dari kelas koneksi / FQCN driver) |
| Konfigurasi | `Config\Database`: `DBDriver = App\Database\MySQLi` untuk grup `default` dan `tests`. Konstruktor memaksa driver polos (`MySQLi`) dari `.env` lama kembali ke driver aplikasi; driver kelas lain dibiarkan (mis. `Tests\Support\Database\Isolasi`, kini turunan driver aplikasi, di `phpunit.dist.xml`). `.env.example` tidak lagi menyetel `DBDriver` |
| `getPlatform()` | Konstruktor mengembalikan `DBDriver` ke `'MySQLi'` setelah konstruksi (pola driver uji MAKE-001), jadi pemeriksaan platform/queue dan `Forge`/`Utils` bawaan tidak berubah |
| Offset | `+00:00` (offset angka, bukan nama zona): tidak butuh tabel zona (nama zona memberi galat 1298 bila kosong), valid di MySQL 8 dan MariaDB 10.4 (batas MariaDB −12:59..+13:00) |
| `sql_mode` | Tidak berubah (`strictOn` tetap lewat init command bawaan). DBV-011 §3.4 di luar cakupan |
| Global server | `time_zone`/`sql_mode` GLOBAL tidak diubah (DBV-011 1.4 butir a); instance dipakai bersama legacy tidak terpengaruh |

## 3. Pembaca (UTC → WIB) — bagian CR

`StempelUtc::keWib/tanggalWib/keIsoWib/epoch` untuk titik yang memotong atau mem-parse stempel sebagai tanggal/jam bisnis:
dashboard Status Layanan (`tanggal_pengajuan` ISO `+07:00`; urutan lintas modul tetap atas UTC), kode/QR tanda terima dan
kunci QR LKH (epoch dari UTC = jam dinding WIB legacy; tahun `yy` WIB), view PDF tanda terima dan surat TB/IB. Bergantung
pada GAP-2.

## 4. Inventarisasi dampak sesi UTC

Grep `backend/app` (di luar `Database/Migrations`): `NOW()`, `CURDATE()`, `CURRENT_DATE`, `CURTIME()`, `UTC_*`,
`FROM_UNIXTIME`, `UNIX_TIMESTAMP`, `SYSDATE`, `LOCALTIME`, `CONVERT_TZ`, serta tipe kolom `TIMESTAMP`.

| Titik | Temuan | Keputusan |
|---|---|---|
| Kode aplikasi (`app/` selain Migrations) | **Tidak ada** pemakaian `NOW()/CURDATE()/CURRENT_DATE/CURTIME()/UTC_*/FROM_UNIXTIME/UNIX_TIMESTAMP/SYSDATE/CONVERT_TZ` dalam SQL. Hanya komentar (`Config\Auth` dan `MasterModel`) dan satu `TIMESTAMPDIFF(YEAR, tgl_lahir, <hariIni dari PHP>)` di `KueriStatistik:164` (argumen tanggal dari PHP, bukan zona sesi) | Tidak ada perubahan. Tanggal bisnis dihitung di PHP (`TanggalBisnis`, zona WIB) |
| Migration/seed | Hanya `UTC_TIMESTAMP()` (sudah UTC, tak bergantung sesi) dan `DEFAULT/ON UPDATE CURRENT_TIMESTAMP` pada DDL | Tidak ada perubahan; default kini berisi UTC |
| Kolom `TIMESTAMP` | **Tidak ada** di skema (query `information_schema.COLUMNS WHERE DATA_TYPE = 'timestamp'` kosong pada DB hasil migrate). Semua `DATETIME` | Nilai tersimpan tak bergeser. R6 (DBV-011) tetap berlaku untuk tabel baru |
| `DEFAULT/ON UPDATE CURRENT_TIMESTAMP` (108 tabel) | Terisi zona sesi → kini UTC; sama dengan nilai eksplisit aplikasi | Dikehendaki |
| Penulisan di luar aplikasi (HeidiSQL, skrip impor, DBA) | Tetap `time_zone` global | R4 DBV-011 (sesi impor memasang `+00:00` sendiri) |
| Baris yang sudah tertulis zona sesi | Hanya ada di DB lokal/demo/scratch; v2 **belum di-deploy** ke server mana pun | Tanpa normalisasi. Runbook: reset DB lokal/demo (`reset_demo.sh`) setelah merge |

Kueri audit (tanpa data pribadi) untuk menemukan baris campur zona di DB lokal: baris dengan `created_at` lebih baru dari
`UTC_TIMESTAMP()` pasti tertulis zona sesi di timur UTC, mis.
`SELECT COUNT(*) FROM riwayat_tb WHERE created_at > UTC_TIMESTAMP();` per tabel berstempel.

## 5. Uji

- `tests/database/SesiUtcTest`: `@@session.time_zone = '+00:00'`, `NOW() = UTC_TIMESTAMP()`; `DEFAULT CURRENT_TIMESTAMP` ≈ UTC;
  `ON UPDATE` tanpa perubahan nilai **tidak** menggeser `updated_at`, dengan perubahan = UTC; koneksi baru dan `reconnect()` tetap UTC.
- `tests/unit/Config/DatabaseConfigTest`: grup `default` selalu driver aplikasi; grup `tests` driver aplikasi atau turunannya.
- `LkhTest` (created_at/updated_at UTC), `LayananStatusTest` (batas 16:59:59 vs 17:00:00 UTC), `ViewPembacaWibTest` (PDF tanda
  terima, surat TB/IB), `KodeStempelLegacyTest` (kode/no tiket/QR = legacy).
- Uji mutasi: `SET time_zone` dinonaktifkan → `SesiUtcTest` 3 test gagal.
- Matriks: MySQL 8 (lokal, server zona SYSTEM = WIB) dan MariaDB 10.4 (CI GitHub Actions, shard mariadb).

## 6. Rollback

Kembalikan `DBDriver` ke `MySQLi` di `Config\Database` (dan hapus pemaksaan di konstruktor); tidak ada data/skema yang diubah, jadi
tidak ada langkah data. Pembaca UTC→WIB bersifat independen dan boleh tetap.

## 7. Pertanyaan untuk DB Validator

1. Setuju `+00:00` per koneksi aplikasi sebagai penerapan DBV-011 §3.3 opsi A (tanpa `sql_mode` sesi)?
2. Setuju tidak ada normalisasi data (v2 belum di-deploy; baris campur zona hanya di DB lokal/demo/scratch)?
3. GAP-2 (zona server legacy) tetap terbuka untuk pembaca kode/QR yang bergantung pada kesetaraan epoch dengan legacy.
