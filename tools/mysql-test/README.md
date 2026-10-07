# Instance MySQL khusus test (MAKE-001)

Instance `mysqld` kedua untuk PHPUnit, **terpisah** dari MySQL dev Laragon (port 3306). Instance ini memakai binari
Laragon (`C:\laragon\bin\mysql\mysql-8.0.30-winx64`) tanpa mengubah `my.ini`, datadir, atau proses Laragon.

| | Instance dev Laragon | Instance test (ini) |
|---|---|---|
| Port | 3306 | **3307**, bind `127.0.0.1` saja |
| Datadir | `C:\laragon\data\mysql-8` | `%LOCALAPPDATA%\simpeg-mysql-test\data` (di luar repo) |
| Durability | default (fsync per commit, binlog) | `innodb_flush_log_at_trx_commit=2`, `innodb_doublewrite=0`, `skip-log-bin`, `sync_binlog=0` |
| Lain-lain | | `innodb_buffer_pool_size=1G`, `performance_schema=OFF`, collation `utf8mb4_unicode_ci`, root tanpa password |

Data di instance test boleh hilang kapan saja (mis. mati listrik). Jangan simpan data dev di sini, dan jangan
pakai `my-test.ini` untuk instance lain.

## Menjalankan

```powershell
# start: inisialisasi datadir sekali (--initialize-insecure), lalu mysqld dijalankan DETACHED (Start-Process)
powershell -ExecutionPolicy Bypass -File tools\mysql-test\start.ps1

# stop: shutdown bersih lewat mysqladmin; datadir dibiarkan untuk dipakai lagi
powershell -ExecutionPolicy Bypass -File tools\mysql-test\stop.ps1
```

- mysqld tetap hidup walau terminal/agen yang menjalankan `start.ps1` ditutup; hentikan dengan `stop.ps1`.
- Kedua skrip menolak port 3306 — instance dev Laragon tidak pernah di-start/stop/diubah oleh skrip ini.
- Parameter opsional: `-Port`, `-DataRoot`, `-MysqlHome`.
- Log: `%LOCALAPPDATA%\simpeg-mysql-test\mysqld-test.log`. Reset total: `stop.ps1`, lalu hapus folder
  `%LOCALAPPDATA%\simpeg-mysql-test` (start berikutnya menginisialisasi ulang).

## Mengarahkan PHPUnit ke instance test

Di `backend/.env` **uji** (bukan `.env` dev; lihat AGENTS.md §3), arahkan grup `tests`:

```ini
database.tests.hostname = 127.0.0.1
database.tests.port = 3307
database.tests.database = simpeg_v2_t_<nama>
database.tests.username = root
database.tests.password =
```

Database test dibuat sendiri sebelum PHPUnit jalan, mis.
`mysql -uroot --host=127.0.0.1 --port=3307 -e "CREATE DATABASE simpeg_v2_t_<nama> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`.
Pakai `127.0.0.1`, bukan `localhost`, agar koneksi lewat TCP ke port 3307. Override yang sama bisa diberikan lewat
environment proses (`database.tests.port=3307` dst.) — proses worker test (`tests/_support/Scripts/*`) membaca `.env`,
jadi `.env` uji adalah cara paling aman.

## Hasil uji coba (MAKE-001, 07-10-2026, mesin lokal, MySQL 3306 sedang dipakai proses lain)

Sesudah isolasi transaksi MAKE-001, I/O bukan lagi hambatan utama, jadi selisih 3307 vs 3306 kecil:

| Subset PHPUnit | 3306 (Laragon) | 3307 (instance test) |
|---|---|---|
| `tests/database` + LoginTest + HariLiburTest + KantorTest (110 test, 4546 assertion) | 1,33 menit | 1,10 menit |
| `tests/Auth` (237 test, 2390 assertion) | 3,48 menit | 3,65 menit |

Manfaat utamanya: beban test (DDL, refresh skema) tidak lagi berebut dengan MySQL dev dan agen/gate lain di 3306.
