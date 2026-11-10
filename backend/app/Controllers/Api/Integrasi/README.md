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

## `GET api/v1/integrasi/log` — daftar log integrasi (F6-24, MAKE-078 / ISSUE-058)

- **Role:** 1 (Super Admin); lainnya 403. Dipakai halaman FE Admin → Integrasi (`/integrasi`). Baca-saja; penulis log tetap
  `Libraries\Integrasi\Log\IntegrasiLog`. Kueri: `Libraries\Integrasi\Log\DaftarLogIntegrasi`.
- **Query (opsional):** `layanan` (kode penulis log, `[a-z0-9_]{1,30}`, cocok persis — mis. `bkn`, `ekinerja`, `bsre`,
  `siasn`, `fcm`, `simapi`, `services`), `status` (`1` sukses / `2` gagal), `tanggal_mulai` / `tanggal_selesai`
  (`YYYY-MM-DD` tanggal **WIB** inklusif; `created_at` disimpan UTC sehingga rentangnya [mulai 00:00 WIB, selesai+1 00:00
  WIB) dalam UTC), `page`, `per_page` (pola `ListQuery`: bawaan 25, maks 100 — dijepit). Parameter kosong diabaikan.
- **Urutan:** `created_at` DESC lalu `id_integrasi_log` DESC (terbaru dulu).
- **200:** `{"status": "success", "data": {"items": [...], "total": 3, "page": 1, "per_page": 25, "layanan_tersedia": ["bkn", ...]}}`.
  Setiap item hanya kolom `integrasi_log` (DBV-027): `id_integrasi_log`, `arah` (1 keluar / 2 masuk), `layanan`, `operasi`,
  `status`, `http_status` (null = tanpa respons), `kode`, `pesan` (sudah teredaksi saat ditulis, BP-5), `durasi_ms`,
  `percobaan`, `ref_id`, `id_klien`, `created_at` (UTC tanpa zona). Tanpa join ke data pegawai/akun. `layanan_tersedia` =
  kode layanan yang ada di tabel (pilihan filter; tidak ikut tersaring).
- **422** (semua parameter salah sekaligus, `errors.<parameter>`): `layanan` di luar pola, `status` bukan 1/2, tanggal bukan
  `YYYY-MM-DD` / tidak ada di kalender, `tanggal_selesai` < `tanggal_mulai`, `page` bukan 1..1.000.000, parameter berbentuk
  array.

## `POST | DELETE api/v1/integrasi/fcm-token` — token push web/desktop (H-04, MAKE-066)

- **Akses:** semua akun login (`jwt`). Padanan `Local::register_fb_token` legacy; SPA memanggil `POST` setiap halaman dimuat
  setelah login dan izin notifikasi diberikan, `DELETE` saat logout.
- **Body JSON:** `{"fcm_token": "<token FCM perangkat>"}` (tanpa spasi, maks 4.096 karakter).
- **Kunci per pemilik** (keputusan Horii 03-11-2026, DBV-Q8 ikut legacy): akun + token (`platform` = 1 web). Token web
  **selalu** `nip` NULL (DBV-027, CHECK pemilik); penerima pegawai di kanal web dicari lewat `pengguna.nip` → `id_pengguna`.
  Token yang sama milik akun lain tidak disentuh.
- **POST 200:** `{"status": "success", "data": {"status": "baru" | "diperpanjang" | "sudah_terdaftar"}}` — baru disimpan
  dengan masa berlaku 1 minggu; sudah ada dan lewat masa → diperpanjang 1 minggu; selain itu tanpa perubahan.
- **DELETE 200:** `{"status": "success", "data": {"dihapus": 0 | 1}}` — hanya token akun pemanggil.
- **422:** `errors.fcm_token`. Isi token tidak pernah dikembalikan atau dicatat.

