# Modul H — Integrasi & API Eksternal (Fase 6)

Controller REST API modul ini (`App\Controllers\Api\Integrasi\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` (lihat `Config\Routes`).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

Route Jalur B (integrasi keluar, PRD Fase 6 §6.2): `app/Config/RoutesIntegrasi.php`. Endpoint kompatibilitas legacy
(SIMAPI, `services/*`) milik Jalur A di berkas route sendiri.

## `POST api/v1/integrasi/bkn/tarik` — tarik ulang e-Kinerja (H-10, MAKE-064)

- **Role:** 1 (Super Admin); lainnya 403.
- **Body JSON:** `{"jenis": "periode" | "skp_periodik", "tahun"?: 2026, "bulan"?: 10}`. Untuk `skp_periodik`, `tahun` dan
  `bulan` berpasangan; keduanya kosong = periode terpilih seperti cron (tanggal WIB > 19 → bulan berikutnya). Untuk
  `periode`, `tahun`/`bulan` diabaikan.
- **Proses:** TIDAK memanggil BKN secara sinkron — mengantre job `bkn-tarik` di antrean `integrasi` (worker
  `php spark queue:work integrasi`), padanan pemicu URL cron legacy (`Ekin_bkn::cron_*`). Hasil tercatat di
  `integrasi_log` (layanan `ekinerja`, operasi `tarik_periode` / `tarik_skp_periodik`) dan tampil di `php spark integrasi:status`.
- **202:** `{"status": "success", "data": {"jenis": "skp_periodik", "tahun": 2026, "bulan": 10, "antre": true}}`.
- **422:** `errors.jenis` (bukan `periode`/`skp_periodik`); `errors.tahun` / `errors.bulan` (tidak berpasangan, di luar
  2000–2100 / 1–12).
