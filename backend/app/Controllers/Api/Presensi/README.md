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
| `POST api/v1/slip-gaji/saya/{periode}/buka` | 2, 6, 7 | Buka sekali (atomik). 200 `{periode, label, viewed_at, viewed_at_wib, rincian {28 field desimal}, ringkasan {jumlah_penghasilan_gpp, jumlah_potongan_gpp, bersih_gpp, bersih_tk, bersih_2, uang_makan, total_penghasilan}, uang_makan {nominal, hari, periode_kehadiran, tersedia}}`; token mentah tidak dikirim |
| `GET api/v1/slip-gaji/saya/{periode}/pdf` | 2, 6, 7 | PDF (hanya bila dibuka); `Content-Disposition: attachment`, `nosniff`, `no-store` |
| `GET api/v1/slip-gaji?periode=YYYY-MM&unit_satker=&status_view=semua\|dibuka\|belum&search=&page=&per_page=` | 1, 3 | `{items[] {id, nip, nama, unit_satker, periode, dibuka, viewed_at, viewed_at_wib, viewed_ip, aksi {lihat, cetak, koreksi, buka_ulang}}, total, page, per_page, ringkasan {jumlah, dibuka, belum_dibuka}, periode_tersedia[]}`; `periode` wajib; role 3 dipaksa satker akun (`unit_satker` = id satker, hanya role 1) |
| `GET api/v1/slip-gaji/{id}` | 1, 3 | detail + rincian + ringkasan + uang makan + `aksi`; tidak mengubah status |
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
5A `UangMakanBelumTersedia` → `uang_makan.tersedia = false`; **5B wajib mengganti sebelum go-live**, F5-1). Audit `update`
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
  Key lain ditambahkan milestone berikutnya (mis. URL verifikasi QR, akun presensi manual).
