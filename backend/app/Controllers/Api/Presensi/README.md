# Modul D — Presensi & Remunerasi (Fase 5)

Controller REST API modul ini (`App\Controllers\Api\Presensi\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` (lihat `Config\Routes`).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## D-02/D-03 Presensi online, manual, data presensi (MAKE-032, PRD 5A §6.4–6.5, §7.5–7.6)

Controller `PresensiController` (online + data admin) dan `PresensiManualController`; aturan di
`Libraries/Presensi/Kehadiran/{PresensiOnlineService, PresensiManualService, DataPresensiService, ResolverLokasiPresensi,
ZonaSatker}` + logika murni `Libraries/Presensi/Geo`. Kode galat mesin = konstanta `KodeGalatPresensi` (field `code`
opsional envelope).

| Method & path | Role | Isi |
|---|---|---|
| `GET api/v1/presensi/saya/hari-ini` | 2, 6, 7 | `{tanggal_lokal, jam_lokal, zonasi_menit, jendela {masuk: [mulai, selesai], pulang: [...]}, boleh_masuk, boleh_pulang, presensi {jam_masuk, jam_pulang}\|null, wajib_geofence, lokasi[] {id_lokasi_presensi, nama_lokasi, latitude, longitude, radius}}` |
| `POST api/v1/presensi/cek-lokasi` (JSON `{latitude, longitude, akurasi?}`) | 2, 6, 7 | `{wajib_geofence, dalam_radius, lokasi_terdekat {id, nama, radius, jarak_m}\|null}` — penilaian sama dengan rekam, tidak menulis |
| `POST api/v1/presensi/masuk` (JSON `{latitude?, longitude?, akurasi?}`) | 2, 6, 7 | 201 baris baru / 200 baris diperbarui: `{tgl, jam_masuk, jam_pulang, id_lokasi_masuk, jarak_m}` (first-wins) |
| `POST api/v1/presensi/pulang` (body sama) | 2, 6, 7 | 201/200 `{tgl, jam_masuk, jam_pulang, id_lokasi_pulang, jarak_m}` (last-wins, menimpa jam & koordinat pulang) |
| `GET api/v1/presensi/saya?bulan=YYYY-MM&page=&per_page=` | 2, 6, 7 | `{bulan, items[] {tgl, jam_masuk, jam_pulang, sumber}, total, page, per_page}`; bawaan bulan lokal berjalan, urut `tgl` turun |
| `GET api/v1/presensi/pegawai?search=&id_unit=&id_satker=&page=&per_page=` | 1, 3, 4, 5 | `{items[] {nip, nama, unit, satker}, total, page, per_page, can_kelola_manual}`; role 3 lingkup satker (`PegawaiScope`) |
| `GET api/v1/presensi/pegawai/{nip}?tgl_mulai=&tgl_akhir=&page=&per_page=` | 1, 3, 4, 5 | `{pegawai {nip, nama}, items[] {nip, tgl, jam_masuk, jam_pulang, sumber, id_lokasi_masuk, lokasi_masuk, id_lokasi_pulang, lokasi_pulang, aksi {ubah, hapus}}, total, page, per_page (bawaan 100), can_create}`; di luar lingkup → 403; tanpa koordinat |
| `POST api/v1/presensi/manual` (JSON `{nip, tgl, jam_masuk?, jam_pulang?}`) | 1 + akun khusus | 201 baris `dh_online` (`sumber = 2`) |
| `PUT api/v1/presensi/manual/{nip}/{tgl}` (JSON `{jam_masuk, jam_pulang}`) | 1 + akun khusus | 200 baris; `nip`/`tgl` dari path; jam yang berubah mengosongkan bukti lokasi jam itu |
| `DELETE api/v1/presensi/manual/{nip}/{tgl}` | 1 + akun khusus | 200; hapus fisik, audit `delete` berisi baris lengkap |

| Status | Kondisi |
|---|---|
| 403 | role di luar kolom; akun pegawai tanpa NIP; `code: bukan_akun_presensi_manual` (role 1 bukan `Config\Presensi::$akunPresensiManual`, dicek sebelum 404/422) |
| 422 `errors.jam` + `code` | `di_luar_jendela` (masuk 04:00:00–12:30:59, pulang 12:31:00–23:59:59 jam lokal, inklusif); `sudah_presensi_masuk` |
| 422 `errors.lokasi` + `code` | `di_luar_radius` (pesan menyebut lokasi terdekat, jarak ±m, radius); `lokasi_tidak_valid` (semua lokasi berlaku berkoordinat tidak sah) |
| 422 `errors.latitude` + `code` | `koordinat_wajib` (tercakup aturan, koordinat tidak dikirim); `koordinat_tidak_valid` |
| 422 `errors.akurasi` | akurasi bukan angka 0–100000 |
| 422 manual | `nip`, `tgl`, `jam_masuk`, `jam_pulang` per field (§7.6.2), duplikat `errors.tgl` "Presensi pegawai ini pada tanggal tersebut sudah ada. Gunakan Edit." |
| 404 | pegawai/baris presensi tidak ditemukan |

Waktu = jam server Asia/Jakarta + zonasi satker jabatan saat ini (`satker.zonasi`, tanpa filter status); waktu klien
diabaikan. Geofence: aturan `dm_user_lokasi_presensi` dinamis per permintaan; jarak ≤ radius (inklusif), salah satu
lokasi cukup; akurasi tidak dipakai menolak; tanpa aturan → koordinat opsional (bila dikirim disimpan). `sumber` baris baru
selalu 1 (online) / 2 (manual) — NULL hanya baris impor legacy (DBV-023 C-4). Audit `dh_online` `{nip}:{tgl}`
`create`/`update`/`delete` di transaksi yang sama.

Contoh (data sintetis):

```json
POST api/v1/presensi/masuk
{"latitude": -6.2005, "longitude": 106.8166667, "akurasi": 12.5}

201 {"status": "success", "data": {"tgl": "2026-10-27", "jam_masuk": "07:15:00", "jam_pulang": null, "id_lokasi_masuk": 3, "jarak_m": 56}}

422 {"status": "error", "code": "di_luar_radius", "message": "Anda berada di luar radius lokasi presensi. Lokasi terdekat: Kantor Pusat (jarak ±1112 m, radius 56 m).",
     "errors": {"lokasi": ["Anda berada di luar radius lokasi presensi. Lokasi terdekat: Kantor Pusat (jarak ±1112 m, radius 56 m)."]}}
```

Catatan deploy M5: key `.env` opsional `presensi.akunPresensiManual` (daftar `id_pengguna` dipisah koma; tidak sah →
bawaan 4675, 4676 dipakai). HTTPS wajib (geolokasi browser).
