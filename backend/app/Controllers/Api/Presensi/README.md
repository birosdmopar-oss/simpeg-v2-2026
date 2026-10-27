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
