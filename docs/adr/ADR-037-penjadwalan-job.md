# ADR-037 — Penjadwalan job terjadwal: command `php spark` + crontab sistem

| | |
|---|---|
| **Status** | **Diusulkan 04-11-2026** (Fase 6 Jalur A, MAKE-062; disetujui lewat review CR) |
| **Cakupan** | Semua job berkala Fase 6: `layanan:status-harian`, `lkh:auto-approval`, `bkn:tarik-periode`, `bkn:tarik-skp-periodik`, `siasn:tarik`, `siasn:dorong`, `siasn:unggah-arsip`, `integrasi:bersihkan-log` (jadwal di PRD Fase 6 §6.4) |
| **Pemilik pelaksanaan** | Fase 6 Jalur A (pola, command harian dan LKH) dan Jalur B (command integrasi keluar) |
| **Terkait** | PRD Fase 6 F6-02, F6-20, BP-6, BP-7, §7.0.3; `README-deploy.md` bagian 1e; pola `cuti:saldo-tahunan` (Fase 4) dan worker `queue:work` (bagian 1a/1b) |

## 1. Konteks

- Legacy menjalankan job lewat endpoint HTTP `hr/services/cron/*` yang dipanggil crontab dengan HTTP Basic (kredensial `CRON_*`
  di konfigurasi), ditambah skrip luar yang memanggil antrean tiap beberapa detik. Endpoint itu dapat dipicu siapa pun yang
  mengetahui kredensialnya, berbagi batas waktu request web, dan tidak punya kode keluar yang bermakna.
- v2 sudah punya pola command terjadwal: `cuti:saldo-tahunan` dan worker `queue:work` lewat `cron.d` + `flock` di
  `README-deploy.md` (bagian 1a/1b).

## 2. Keputusan

1. **Job = command `php spark <nama>`** di `app/Commands/`, bukan endpoint HTTP. Endpoint `hr/services/cron/*` **tidak di-port**
   dan tidak ada route cron di v2 (menutup jalur kredensial `CRON_*`).
2. **Pemicu = crontab sistem** (`/etc/cron.d/simpeg-v2-<nama>`, user `deploy`, dari symlink `current`), dibungkus `flock -n`
   per command agar run yang terlambat tidak menumpuk. Jadwal dan isi job mengikuti legacy (PRD §6.4); jadwal ditulis di
   runbook (`README-deploy.md`), bukan di kode.
3. **Kunci aplikasi** (`GET_LOCK`, tunggu 0, nama dari skema + prefix) wajib bagi job yang menulis dan bisa berjalan bersamaan
   dengan run manual. `flock` melindungi dari cron ganda; `GET_LOCK` melindungi dari cron + run manual dan dari dua host
   aplikasi. Kunci sibuk = gagal dengan pesan jelas, bukan menunggu.
4. **Kode keluar bermakna:** `0` sukses; `EXIT_USER_INPUT` (opsi salah, `--tanggal` terlarang); `EXIT_ERROR` bila ada kegagalan
   (galat basis data, prasyarat belum siap seperti akun sistem, kunci sibuk, atau ≥ 1 baris gagal diproses). Cron lalu dapat
   mengirim peringatan dari kode keluar tanpa membaca log.
5. **Idempoten dan aman diulang** (BP-6): setiap job stateless terhadap jadwal (tidak mengandalkan "terakhir jalan"), punya
   `--dry-run`, dan run yang terlewat cukup dijalankan ulang. Job yang bergantung tanggal menerima `--tanggal` hanya untuk
   `--dry-run` lampau; run nyata selalu hari ini WIB (Q6-27).
6. **Log:** keluaran command diarahkan cron ke `writable/logs`/`/var/www/simpeg-v2/logs/<nama>.log`, berisi jumlah saja (tanpa
   NIP, nama, token). Command juga menulis satu baris ringkasan lewat `log_message('info', …)`. Kegagalan per baris tidak
   menghentikan baris lain (BP-7) tetapi menaikkan kode keluar.
7. **Pelaku tulis = akun sistem** (`AkunSistemIntegrasi::id()`, DBV-027 Z-1). Belum disiapkan = job gagal sebelum menulis.
   `--dry-run` tidak butuh akun.
8. **Run pertama di lingkungan baru wajib `--dry-run`** untuk job yang mengubah data massal; jumlahnya direview sebelum run
   nyata (mis. `layanan:status-harian`, Q6-26).

## 3. Alternatif

| Alternatif | Alasan ditolak |
|---|---|
| Endpoint HTTP cron + Basic seperti legacy | Memperluas permukaan serang, batas waktu web, tanpa kode keluar (F6-02) |
| Penjadwal di dalam aplikasi (antrean tertunda) | Menambah mesin penjadwal baru; crontab + `flock` sudah dipakai dan diuji (Fase 4/6) |
| Hanya `flock`, tanpa `GET_LOCK` | Tidak melindungi dari run manual atau dari lebih dari satu host |
| Hanya `GET_LOCK`, tanpa `flock` | Proses PHP baru tetap dimulai tiap menit dan menumpuk bila basis data lambat |

## 4. Konsekuensi

- Menambah job = command baru + satu baris `cron.d` di runbook; tidak ada perubahan route atau filter.
- Operator memantau lewat kode keluar dan log jumlah; tidak ada dasbor baru.
- Zona waktu cron mengikuti server; jadwal WIB ditulis eksplisit di runbook (PRD §6.4).
