# Modul D — Presensi & Remunerasi (Fase 5)

Controller REST API modul ini (`App\Controllers\Api\Presensi\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` (lihat `Config\Routes`).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## D-10 Impor Slip Gaji (MAKE-030, PRD 5A §6.2, §7.2)

Controller `SlipGajiImportController`; aturan & akses di `Libraries/Presensi/Slip/ImporSlipService` (logika murni CR-027
`PratinjauImpor`/`KolomGaji`/`BarisImpor` dipakai apa adanya). Envelope ADR-001 + field `code` opsional (snake_case, level atas, urutan
`status, code, message, errors`; `ApiException` parameter `kode`, konstanta `Libraries/Presensi/Slip/KodeGalatSlip`).

| Method & path | Role | Isi |
|---|---|---|
| `POST api/v1/slip-gaji/impor/pratinjau` (multipart `berkas`, `filter_bulan?`, `filter_tahun?`) | 1, 3 | Langkah 1+2: validasi berkas → simpan pratinjau → baca Excel (sheet `GPP` + `TK`) → pratinjau. 200 `{token_pratinjau, kedaluwarsa_at, timpa_bawaan: true, filter: 'YYYY-MM'\|null, ringkasan {valid, duplikat, galat}, info {baris_tanpa_nip, kolom_diabaikan}, baris[] {nomor, sheet, nip, nama, periode, status: valid\|duplikat\|galat, rencana_bila_timpa, rencana_bila_tidak_timpa, galat[] {kode, pesan, sheet, baris}, info[], nilai {28 field: desimal kanonik}}}` |
| `POST api/v1/slip-gaji/impor/pratinjau/{token}/commit` (JSON `{timpa?: bool}`, bawaan `true`) | 1, 3 | Langkah 3: validasi ulang penuh (data & cakupan terkini) dalam satu transaksi. 200 `{disisip, diperbarui, dilewati, gagal, baris[] {nip, periode, hasil: sisip\|perbarui\|lewati\|gagal, galat[]}}` |

Akses: role 1 semua NIP; role 3 hanya NIP yang `pegawai_mutasi_jabatan.id_satker` = `id_satker` akun (lainnya baris galat
`nip_di_luar_cakupan`); role 3 tanpa `id_satker` → 403 (pratinjau dan commit); role 2/4/5/6/7/8 → 403 (route); tanpa
token → 401.

| Status | Kondisi |
|---|---|
| 422 `errors.berkas` | "Berkas wajib diunggah." / "Format berkas harus .xlsx atau .xls." (ekstensi atau jenis isi lain, tanpa ekstensi) / "Isi berkas tidak sesuai dengan ekstensinya." / "Ukuran berkas maksimal 5 MB." / sheet `GPP`/`TK` tidak ada, kosong, header tidak sah / "Jumlah baris melebihi batas 10.000 per sheet." / "Berkas tidak dapat dibaca. …" (tanpa pesan exception mentah) |
| 422 `errors.filter_bulan` + `code` | `filter_periode_tidak_lengkap`, `periode_tidak_valid`, `periode_tidak_ada_di_berkas` |
| 422 `errors.timpa` | `timpa` bukan boolean |
| 404 `code: pratinjau_tidak_ditemukan` | token tidak sah/tidak ada, bukan milik pemanggil, kedaluwarsa (TTL), atau isi berkas di disk berubah — pesan sama "Pratinjau tidak ditemukan atau sudah kedaluwarsa. Unggah ulang berkas." |
| 409 `code: konflik_data`, `errors.nip_periode` | 1062 saat commit (kunci disisip proses lain); seluruh transaksi di-rollback |
| 500 | galat DB lain saat commit: "Impor gagal diproses. Tidak ada data yang disimpan." (rollback, detail hanya di log tanpa nominal) |

Penyimpanan: berkas + sidecar JSON di `Config\Presensi::$direktoriPratinjau` (bawaan `writable/uploads/slip-impor/`) dengan
nama `sha256(token)`; satu pratinjau aktif per pengguna; dihapus setelah commit (sukses/409/500), saat kedaluwarsa, dan saat
unggahan baru. Audit: `audit_logs.entity = gaji_pegawai_impor` — `warning` sekali per kunci bernama tidak cocok per pratinjau
(`entity_id = {nip}:{YYYY-MM}`), `import` ringkasan commit tanpa nominal (`entity_id` = 8 karakter awal hash token).

Contoh (data sintetis):

```json
POST api/v1/slip-gaji/impor/pratinjau/<token>/commit
{"timpa": true}

200 {"status": "success", "data": {"disisip": 1, "diperbarui": 0, "dilewati": 0, "gagal": 1,
  "baris": [{"nip": "199001012020011001", "periode": "2026-09", "hasil": "sisip", "galat": []},
            {"nip": "199001012020011002", "periode": "2026-09", "hasil": "gagal",
             "galat": [{"kode": "nip_di_luar_cakupan", "pesan": "NIP 199001012020011002 berada di luar satker Anda.", "sheet": "GPP", "baris": 3}]}]}}
```

## D-11/D-14 Akses & verifikasi Slip Gaji (MAKE-031, PRD 5A §6.1–6.3, §7.3–7.4)

Controller `SlipGajiController` (pegawai), `SlipGajiAdminController` (admin), `VerifikasiSlipController` (publik); aturan
di `Libraries/Presensi/Slip/{SlipGajiService, SlipGajiAdminService, VerifikasiSlipService, TokenSlipService, SlipGajiData,
PdfSlipGaji, KopSlip}` + logika murni `Libraries/Presensi/SlipGaji` (`GerbangPeriode`, `StatusSlip`, `AksesSlip`,
`RingkasanSlip`, `FormatSlip`, `TokenQr`, `NominalGaji`). Kode galat = konstanta `KodeGalatSlip` / kode `HasilSlip`.

| Method & path | Role | Isi |
|---|---|---|
| `GET api/v1/slip-gaji/saya` | 2, 6, 7 | `{items[] {periode, label, ada_data, dibuka, viewed_at, viewed_at_wib}, periode_terbaru}` — periode tersedia (Januari 2024 → terbaru, terbaru dulu), **tanpa nominal** |
| `GET api/v1/slip-gaji/saya/{periode}` | 2, 6, 7 | `{periode, label, ada_data, dibuka, viewed_at, viewed_at_wib}` — metadata saja |
| `POST api/v1/slip-gaji/saya/{periode}/buka` | 2, 6, 7 | Buka sekali (atomik). 200 `{periode, label, viewed_at, viewed_at_wib, rincian {28 field desimal}, ringkasan {jumlah_penghasilan_gpp, jumlah_potongan_gpp, bersih_gpp, bersih_tk, bersih_2, uang_makan, total_penghasilan}, uang_makan {nominal, hari, periode_kehadiran, tersedia}, pegawai {nip, nama, pangkat, jabatan, unit, satker}}` (identitas kartu slip [K `_slip_content.php`], MAKE-034); token mentah tidak dikirim |
| `GET api/v1/slip-gaji/saya/{periode}/pdf` | 2, 6, 7 | PDF (hanya bila dibuka); `Content-Disposition: attachment`, `nosniff`, `no-store` |
| `GET api/v1/slip-gaji?periode=YYYY-MM&unit_satker=&status_view=semua\|dibuka\|belum&search=&page=&per_page=` | 1, 3 | `{items[] {id, nip, nama, unit_satker, periode, dibuka, viewed_at, viewed_at_wib, viewed_ip, aksi {lihat, cetak, koreksi, buka_ulang}}, total, page, per_page, ringkasan {jumlah, dibuka, belum_dibuka}, periode_tersedia[]}`; `periode` wajib; role 3 dipaksa satker akun (`unit_satker` = id satker, hanya role 1) |
| `GET api/v1/slip-gaji/{id}` | 1, 3 | detail + rincian + ringkasan + uang makan + `aksi` + `pegawai {nip, nama, pangkat, jabatan, unit, satker}` (identitas modal admin, MAKE-034); tidak mengubah status |
| `PUT api/v1/slip-gaji/{id}` (JSON 28 field, desimal titik, mis. `"4500000.00"`) | 1, 3 | koreksi; status, token, `uang_makan*` tidak berubah; audit before/after lewat `GajiPegawaiModel` |
| `POST api/v1/slip-gaji/{id}/buka-ulang` | 1, 3 | reset status & token aktif; riwayat token dan snapshot uang makan dipertahankan |
| `GET api/v1/slip-gaji/{id}/pdf` | 1, 3 | PDF kapan saja tanpa gerbang & tanpa ubah status; tanpa QR bila tidak ada token aktif |
| `GET api/v1/publik/verifikasi-slip/{token}` | publik (tanpa jwt, filter `throttle` 30/60 dtk/IP) | 200 `{status_keaslian: "asli", periode, label_periode, satker_penerbit, waktu_terbit, nama (tersamar)}` dari snapshot saat terbit |

| Status | Kondisi |
|---|---|
| 401 / 403 | tanpa token; role di luar kolom; akun pegawai tanpa NIP; role 3 tanpa `id_satker` (seluruh jalur admin); target di luar satker role 3 (403, bukan 404) |
| 404 `code: slip_tidak_ditemukan` | periode tersedia tetapi slip tidak ada: "Slip gaji periode {label} belum tersedia." |
| 404 publik | semua alasan (format, tidak ada, huruf besar, spasi): "Dokumen tidak ditemukan atau tidak valid." — header `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store` di 200/404/429 |
| 422 `errors.periode` + `code` | `periode_wajib`, `periode_tidak_valid`, `periode_sebelum_awal`, `periode_belum_rilis` (gerbang tanggal 4 pukul 10:00 WIB, tanpa zonasi); `slip_sudah_dibuka`; `slip_belum_dibuka` (PDF pegawai) |
| 422 koreksi | per field: `Nilai wajib diisi.`; `code: nominal_negatif` "Nilai harus berupa angka non-negatif."; `nominal_tidak_valid` |
| 422 `errors.status` + `code: slip_belum_dibuka` | buka ulang slip yang belum dibuka |
| 429 | verifikasi publik melebihi 30 permintaan/60 detik per IP (`Retry-After`) |

Token QR: `random_bytes(32)` → 64 heks; DB menyimpan `hash('sha256')` (`qr_token`, riwayat `gaji_pegawai_qr_history`) dan
token terenkripsi Encrypter CI4 (`qr_token_enkripsi`, Q5A-01 opsi a) untuk QR PDF; URL QR =
`Config\Presensi::$urlVerifikasiSlip` + token. Snapshot uang makan N−1 lewat `Config\Presensi::$sumberUangMakan` (bawaan
5B `UangMakanSlipPresensi`, MAKE-040, menutup F5-1; `UangMakanBelumTersedia` 5A → `uang_makan.tersedia = false`). Audit `update`
`gaji_pegawai` saat buka/buka ulang tanpa IP, token, nominal; koreksi lewat model (token & IP dimasking).

Contoh (data sintetis):

```json
GET api/v1/publik/verifikasi-slip/<64 heks>

200 {"status": "success", "data": {"status_keaslian": "asli", "periode": "2026-09", "label_periode": "September 2026",
     "satker_penerbit": "Sekretariat Uji", "waktu_terbit": "27 Oktober 2026, 09:00 WIB", "nama": "B*** S***"}}
404 {"status": "error", "message": "Dokumen tidak ditemukan atau tidak valid."}
```

Catatan deploy M4: kunci enkripsi aplikasi (`encryption.key`) wajib terisi (token QR terenkripsi); key `.env` opsional
`presensi.urlVerifikasiSlip` (URL absolut http/https; bawaan `app.baseURL` + `verify/slip/`) dan `presensi.akarAset`.

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

## Penyesuaian Tukin (MAKE-045, PRD 5B §6.1, §7.3.10, F5B-14, DBV-024)

Route di `Config/RoutesPresensiLaporan.php` (berkas 5B), `PenyesuaianTukinController` → `Laporan\PenyesuaianTukinService`.
Pengganti daftar NIP & periode yang di-hard-code legacy (`L_presensi.php:11755-11806`); efek ke laporan/rekap Tukin
dibuat di M5 (keterangan + kolom `penyesuaian`, `total_potongan` tidak berubah).

| Method & path | Role | Isi |
|---|---|---|
| `GET api/v1/presensi/tukin/penyesuaian?nip=&bulan=&status=&page=&per_page=` | 1 | Daftar; `bulan` `YYYY-MM` = baris yang mencakup bulan itu; `status` 1/2/10 (bawaan 1 dan 2); urut NIP, bulan mulai terbaru |
| `POST api/v1/presensi/tukin/penyesuaian` | 1 | Tambah (201) |
| `PUT api/v1/presensi/tukin/penyesuaian/{id}` | 1 | Ubah rentang, persen, alasan, status 1/2; NIP tidak bisa diubah (422 `errors.nip`) |
| `DELETE api/v1/presensi/tukin/penyesuaian/{id}` | 1 | Status 10 (pola master), audit `delete`; baris status 10 → 404 untuk ubah/hapus |

- Body: `nip`, `bulan_mulai`, `bulan_akhir` (`YYYY-MM`), `persen`, `alasan`, `status` (opsional, bawaan 1). Respons baris:
  `{id, nip, nama, bulan_mulai, bulan_akhir, persen ("-20.00"), alasan, status, created_at, updated_at}` (stempel UTC).
- Validasi 422 per field: NIP ada di `pegawai`; bulan ≥ 2024-04 (F5B-4), mulai ≤ akhir; persen paling banyak dua desimal,
  ≠ 0, −100..100; alasan wajib ≤ 255 karakter; status 1/2.
- **Tumpang tindih (syarat DB Validator C-1):** baris status 1 tidak boleh beririsan dengan baris status 1 lain milik NIP
  yang sama (422 `errors.bulan_mulai`, menyebut rentang yang bentrok). Kunci `SELECT … FROM pegawai WHERE nip = ? FOR UPDATE`
  diambil sebelum cek; cek + tulis satu transaksi, jadi permintaan bersamaan untuk NIP yang sama berjalan berurutan
  (`PenyesuaianTukinKonkurensiTest`). Kunci gagal karena permintaan lain (1205 lock wait timeout, 1213 deadlock) →
  **409** `code: konflik_data` "Data sedang diubah oleh permintaan lain, coba lagi." (rollback; preseden impor Slip Gaji,
  keputusan reviewer CR 28-10-2026); galat lain diteruskan, tidak pernah lanjut tanpa kunci.
- Audit lewat `PenyesuaianTukinModel` (`BaseAuditableModel`): `create`, `update`, `delete` (status 10) dengan before/after.

## Catatan deploy (PRD Fase 5A §8.5, Q5A-22)

Dieksekusi tim infra saat cutover; tanpa nama host/IP di repo.

- **Ekstensi PHP wajib** (`backend/composer.json` `require`; `composer install` gagal bila tidak ada): `ctype`, `curl`,
  `dom`, `fileinfo`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`.
  `gd` **wajib, bukan opsional**: sudah diwajibkan sebelum Fase 5A, juga diwajibkan `phpoffice/phpspreadsheet` sendiri,
  dan dipakai gambar QR PDF slip (`Pdf::qrImg`); PRD 5A melarang cadangan QR berupa teks token (ADR-032).
- **Direktori tulis:** `writable/uploads/slip-impor/` (pratinjau impor Slip Gaji, dibuat otomatis bila belum ada) harus
  dapat ditulis proses PHP dan **tidak** dilayani web server.
- **Rute SPA publik:** path `/verify/slip/*` diarahkan ke SPA frontend sejak switch produksi (SG-1; QR PDF lama dan baru
  menunjuk path ini), dengan header `X-Robots-Tag: noindex, nofollow`.
- **HTTPS wajib** (geolokasi browser untuk presensi online hanya jalan di konteks aman).
- **Key `.env` opsional** (`presensi.*`, tanpa nilai rahasia): `presensi.maksUkuranImporByte`,
  `presensi.maksBarisImporPerSheet`, `presensi.ttlPratinjauDetik` (bilangan bulat positif; nilai tidak sah diabaikan).
  Key lain per milestone: `presensi.urlVerifikasiSlip`, `presensi.akarAset` (M4), `presensi.akunPresensiManual` (M5).
- **Di belakang reverse proxy / load balancer:** isi `Config\App::$proxyIPs` (lewat `.env`) dengan alamat proxy
  agar IP klien dibaca dari header proxy; tanpa itu throttle verifikasi publik (30/60 dtk per IP) menghitung semua
  permintaan sebagai satu IP proxy.
