# Modul F — Dashboard & News (Fase 7)

Controller REST API modul ini (`App\Controllers\Api\Dashboard\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` di `Config\RoutesDashboard` (dimuat lewat `Config\Routing::$routeFiles`). Acuan: `docs/fase7/PRD_Fase7_Statistik-GeneratorDokumen.md`.

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## F-03 Widget Statistik Kepegawaian (MAKE-036) — `StatistikController`

Padanan legacy `hr/chart/*` (`ChartController` di spek). Role **1, 3, 4, 5, 8** (Matriks v2); role 3 (Admin Satker) hanya satker/unit akunnya (`PegawaiScope`, PRD F7-3) dan hanya boleh memilih lingkup miliknya. Format respons ADR-001; parameter lewat `ListQuery` (bentuk array → 422).

| Method | Path | Keterangan |
|---|---|---|
| GET | `api/v1/statistik/lingkup` | Lingkup yang boleh dipilih akun: `{ kementerian: { nama } \| null, unit: [{ id, nama, satker: [{ id, nama }] }] }` (unit/satker status 1 urut `order`, lalu id) |
| GET | `api/v1/statistik/{jenis}` | Statistik; `jenis` ∈ `unit-kerja`, `jenis-kelamin`, `jabatan`, `eselon`, `pendidikan`, `usia`, `golongan` |
| GET | `api/v1/statistik/{jenis}/xlsx` | Unduh XLSX statistik yang sama; dicatat di `audit_logs` (`entity` `dokumen_unduhan`, `entity_id` `statistik_{jenis}`, `event` `create`) |

Query `statistik/{jenis}` dan `/xlsx`:

| Parameter | Nilai | Keterangan |
|---|---|---|
| `lingkup` | `kementerian` \| `unit` \| `satker` | Tanpa `lingkup` = `kementerian` (role 1/4/5/8) atau satker/unit akun (role 3). `kementerian` dirinci per unit eselon I; `unit` dirinci per satker di bawah unit; `satker` = satu baris |
| `id` | angka | Wajib untuk `unit`/`satker`; unit/satker harus ada dan status 1 (selain itu 404) |

Kode galat: 401 tanpa token; 403 role di luar matriks atau `Lingkup di luar hak akses.`; 404 jenis tidak dikenal / unit / satker tidak ada; 422 `lingkup` tidak dikenal, `id` bukan angka, parameter berbentuk array.

Respons `GET statistik/{jenis}`:

```json
{ "status": "success", "data": {
  "jenis": "unit-kerja", "judul": "Formasi Unit Kerja",
  "lingkup": { "tipe": "kementerian", "id": null, "nama": "<nama_kementerian>" },
  "label_kategori": "Unit Kerja Eselon I",
  "kategori": [ { "id": "3", "label": "Sekretariat Kementerian" } ],
  "seri":     [ { "key": "pns", "label": "PNS" }, { "key": "pppk", "label": "PPPK" } ],
  "baris":    [ { "kategori_id": "3", "label": "Sekretariat Kementerian", "nilai": [120, 15], "total": 135 } ],
  "subtotal": [120, 15], "total": 135,
  "periode": "2026-10-21",
  "cache": { "hit": false, "ttl": 900 } } }
```

Populasi: `pegawai.status = 1` AND `id_jenis_pegawai IN (1 PNS, 6 PPPK)`; sumber = snapshot `pegawai_*`; kategori tanpa data tetap tampil 0; pegawai di unit/satker nonaktif tidak dihitung. Seri per jenis: unit-kerja PNS/PPPK; jenis-kelamin Laki-laki/Perempuan (`jenis_kelamin` 1 = L, selain itu P); jabatan Struktural/Fungsional/Pelaksana/Lain-lain (`id_group_jabatan` 1/2/3/lainnya); eselon = master `sub_group_jabatan` group 1 status 1 urut id; pendidikan = master `jenjang_pendidikan` status 1 urut `order` + "Tanpa data"; usia `≤ 30`, `31–40`, `41–45`, `46–50`, `51–56`, `57–60`, `> 60`; golongan = gol ruang PNS yang muncul (urut `pangkat.order`, CPNS digabung) lalu gol PPPK.

Cache (F0-14): key `dashboard_stat_{jenis}_{lingkup}_{id}_{scope}` (scope `all` / `s<id_satker>` / `u<id_unit>` akun role 3), TTL 900 detik; dihapus oleh listener event riwayat (`Config\Events`) dan hook `PegawaiModel`; jalur tulis lain basi paling lama TTL (lihat `Libraries/Dashboard/Statistik/StatistikCache.php`). `cache.hit` di respons untuk QA.

XLSX: satu sheet — baris 1 judul (tebal), nama lingkup, `Periode: <d M Y>`, header kolom, data, baris `SUBTOTAL`; nama berkas `statistik_{jenis}_{lingkup}{id}_{YmdHis}.xlsx`, `Content-Disposition: attachment`, `Cache-Control: no-store`.

## F-09 Generator Dokumen (MAKE-037) — `DokumenController`

Padanan legacy `hr/document/*` (`DocumentController` di spek): DUK, Nominatif, Proyeksi Pensiun, Baperjakat. Role **1, 3, 4, 5, 8** (Matriks v2); role 3 hanya satker/unit akunnya (`PegawaiScope`, PRD F7-22). Lingkup `lingkup`/`id` sama dengan statistik (F-03 di atas). Sumber = snapshot `pegawai_*`; tanpa cache (selalu kueri langsung).

| Method | Path | Keterangan |
|---|---|---|
| GET | `api/v1/dokumen/{jenis}` | Daftar JSON berhalaman; `jenis` ∈ `duk`, `nominatif`, `pensiun`, `baperjakat` |
| GET | `api/v1/dokumen/{jenis}/pdf` | PDF lanskap A4 sinkron (`?inline=1` → `inline`, selain itu `attachment`); `jenis` ∈ `duk`, `nominatif`, `pensiun` |
| GET | `api/v1/dokumen/{jenis}/xlsx` | XLSX seluruh kolom legacy; `jenis` ∈ `duk`, `nominatif`, `pensiun` |
| POST | `api/v1/dokumen/baperjakat/pdf`, `api/v1/dokumen/baperjakat/xlsx` | Body JSON `{ nip: string[1..2000] unik, judul: ≤150 (wajib), subjudul?: ≤150, teks?: ≤1000 }` |

Query: `lingkup`, `id` (seperti F-03), `search` (nama/NIP), JSON `page` / `per_page` (default 20, maks 100); Pensiun + `tahun` (default tahun berjalan) dan `sampai` (≥ `tahun`, maks `tahun + 4`).

Cakupan per jenis (F7-19): DUK & Pensiun = PNS aktif (`id_jenis_pegawai = 1`, termasuk CPNS); Nominatif = semua pegawai aktif; Baperjakat = pegawai aktif. Urutan: DUK `pangkat.order` DESC → TMT pangkat → TMT CPNS → tanggal lahir → NIP (F7-17); Nominatif dan Baperjakat F7-18; Pensiun urut TMT pensiun (F7-20).

Respons `GET dokumen/{jenis}`:

```json
{ "status": "success", "data": {
  "jenis": "duk", "judul": "Daftar Urut Kepangkatan",
  "lingkup": { "tipe": "satker", "id": 12, "nama": "<satker>" },
  "parameter": {},
  "kolom": { "nama": "NAMA", "nip": "NIP", "…": "…" },
  "items": [ { "no": 1, "nama": "…", "nip": "…", "…": "…" } ],
  "total": 120, "page": 1, "per_page": 20, "periode": "2026-10-21" } }
```

`kolom` = 37 kolom legacy (DUK/Nominatif/Baperjakat) atau 11 kolom Pensiun; `parameter` Pensiun = `{tahun, sampai}`.

**PDF — fallback F7-15 (ADR-034).** PDF hanya untuk lingkup `unit`/`satker` dan maksimal **1.700 baris** (`DokumenService::PDF_MAKS_BARIS`, hasil uji volume M3); XLSX tanpa batas lingkup.

| Kondisi | Respons |
|---|---|
| PDF `lingkup` kementerian (termasuk tanpa `lingkup` untuk role 1/4/5/8) | 422 `errors.lingkup: ["PDF hanya tersedia per unit/satker."]` |
| PDF unit/satker dengan > 1.700 baris | 422 `errors.lingkup: ["Persempit lingkup atau unduh XLSX."]` |
| Baperjakat PDF dengan > 1.700 NIP | 422 `errors.nip: ["Terlalu banyak NIP untuk PDF (maksimal 1700); kurangi pilihan atau unduh XLSX."]` |

Penolakan lingkup dan batas baris terjadi sebelum throttle dan sebelum render (kecuali batas baris Pensiun, yang baru diketahui sesudah kueri), jadi tidak memakan jatah unduhan dan tidak diaudit.

Kop PDF (`Libraries/Pdf/KopDokumen.php`): nama kementerian dari `web_config` (`pdf_header_nama_kementerian`, lalu `nama_kementerian`), nama unit/satker lingkup, alamat `satker.alamat_pdf_header` / `unit.alamat_pdf_header` bila terisi, selain itu `pdf_header_alamat_kementerian`; tanpa logo. Kaki: "Dicetak <d M Y H:i> oleh <NIP/akun> — SIMPEG v2".

Nama berkas `{jenis}_{lingkup}{id}_{YmdHis}.{pdf|xlsx}`; header `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`.

**Throttle:** unduhan PDF/XLSX maks 6 per menit per akun (key `dok_unduh_<id_pengguna>`) → 429; JSON tidak dibatasi.

**Audit (F7-27):** setiap unduhan berhasil dicatat di `audit_logs` (`entity` `dokumen_unduhan`, `entity_id` = jenis, `event` `create`, `after_json` `{jenis, format, lingkup, id, parameter, jumlah_baris}`); dicatat sesudah berkas dibangun, kegagalan audit → 500 (fail-closed). 401/403/404/422/429 tidak diaudit.

Kode galat: 401 tanpa token; 403 role di luar matriks / lingkup di luar hak; 404 jenis tidak dikenal, `GET dokumen/baperjakat/{pdf|xlsx}` (harus POST), unit/satker tidak ada; 422 parameter tidak sah, rentang pensiun, body Baperjakat (termasuk NIP tidak ada / nonaktif / di luar scope → `errors.nip`, maks 20 NIP ditampilkan), fallback PDF di atas; 429 throttle.

## F-13 Beranda per role (MAKE-087, Jalur D) — `BerandaController`

PRD Fase 7 v2.0 §5.10. Config: `Config\Dashboard`. Logika: `Libraries\Dashboard\Beranda\RingkasanBeranda`.

### `GET api/v1/dashboard/ringkasan` (jwt, role 1–8)

Bentuk respons menurut keluarga role ikut legacy (`User.php:526-567`).

**Admin (role 1/3/4/5/8)** — padanan tile `views/user/dashboard_ad.php:46-200`:

```json
{"status": "success", "data": {
  "keluarga": "admin",
  "lingkup": {"jenis": "kementerian|satker|unit", "id": null},
  "tampilan_komposisi": "donut|angka",
  "tanggal": "2026-11-10",
  "total_aktif": 1200,
  "pengingat": [{"kode": "pensiun", "label": "Pensiun", "jumlah": 12}, {"kode": "kp", "label": "Naik Pangkat", "jumlah": 40}, "..."],
  "tile": [{"kode": "pns", "label": "Total PNS", "kelompok": "komposisi", "jumlah": 1000, "persen": 83}, "..."]
}}
```

- `tile` berurutan seperti view legacy: `pns`, `ptt`, `non_pns`, `staf_khusus`, `tenaga_ahli` (kelompok `komposisi`),
  `tb`, `ib`, `cltn`, `cuti`, `presensi_online` (kelompok `hari_ini`), `pppk` (`komposisi`). `persen` = round(jumlah /
  total_aktif × 100), 0 bila total 0.
- Lingkup: role 1/4/5/8 = kementerian; role 3 = satker akun (bila `id_satker` ada) atau unit akun, lewat
  `pegawai_mutasi_jabatan` persis legacy dashboard. Satker ada tetapi bukan angka kanonik → semua 0 (fail-closed).
- "Hari ini" = tanggal bisnis WIB (`tanggal`). TB kementerian termasuk periode perpanjangan; role 3 tanpa (Q7-41 [K]).
- Pengecualian NIP `dashboard.nipDisembunyikan` (Q7-41) diterapkan di tile yang sama dengan legacy (lihat docblock
  `RingkasanBeranda`); NIP tidak pernah ada di respons.
- `tampilan_komposisi` = saklar `Config\Dashboard::$tampilanKomposisi` (Q7-36): `donut` [V2] / `angka` (persis legacy).
  Data sama untuk kedua mode.
- Cache: angka komposisi/total/CLTN per lingkup `ttlRingkasanDetik` (bawaan 900); TB/IB/cuti/presensi online selalu baru.

- `pengingat` (M2): 4 kartu `dashboard_ad.php:8-37` — `pensiun`, `kp` (Naik Pangkat), `tj` (Tanda Jasa), `kgb` — ikut
  `L_user::dashboard_*` + `L_employee::get_*` per lingkup (rincian `PengingatBeranda`): Pensiun/KP tahun berjalan (PNS); KGB
  ≤ tahun berjalan (kementerian PNS+PPPK, satker/unit PNS); Tanda Jasa kementerian ≤ tahun acuan (tahun depan bila bulan ≥ 9),
  satker/unit tepat 1 Agustus tahun berjalan/depan. Tanpa pengecualian NIP. Cache per lingkup + tanggal.

**Pegawai (role 2/6/7)** — `{"keluarga": "pegawai", "tanggal": "2026-11-10", "prediksi": [...]}` (M2b). `prediksi` per kartu
`{kode, label, tanggal|null, teks ("Bulan Tahun")|null, sisa ("N Tahun M Bulan")|null}` + khusus `kp`: `jenis` `ST|JF`,
`pangkat_puncak`, `ak` `{sekarang, target, kurang}` (JF). Role 2: `pensiun` (BUP jabatan), `kp` (port `next_kp`; JF bila
`pmj.id_group_jabatan` = 2 → AK `AkPermenpanrb`), `tj` (port `next_tj`), `kgb` (`next_kgb`); role 7: `pensiun` = `pmj.mhpk_akhir`
+ `kgb`; role 6: `[]`. KP/TJ/KGB `null` bila tidak sebelum tanggal pensiun.

`bawahan` (role 2 saja, ikut `dashboard_pns`): `{atasan, presensi: [{nama, jam_masuk, jam_pulang}], ak: [{order, nama, nip,
gol_ruang, satker, jabatan, ak_now, ak_kp, kurang}]}` — bawahan dari `pmj.id_atasan_es_*` / `*_koord` milik jabatan atasan
(Eselon I tanpa bawahan, cabang mati legacy); presensi `dh_online` hari ini; AK hanya bawahan JF. Kelengkapan data: menunggu
keputusan (butir terbuka).

Kode galat: 401 tanpa token.

### `GET api/v1/dashboard/direktur` (role 1, 4)

`{"items": [{"nama": "Dr. Nama, M.Par.", "politeknik": "Politeknik …", "masa_kerja": "2 Tahun 3 Bulan"}]}` — pegawai aktif
dengan `pmj.id_jabatan` ∈ `Config\Dashboard::$idJabatanDirektur` (Q7-38), satu baris per jabatan (TMT terbaru), urut TMT
DESC; masa kerja sampai hari ini WIB ('' bila < 1 bulan). 401/403.

### `GET api/v1/dashboard/ulang-tahun?hari=ini|besok` (jwt, role 1–8) — Q7-45

`{"hari": "ini", "items": [{nip|null, nama, tanggal_lahir ("10 November 1985"), jenis_pegawai|null, jabatan, satker|null,
khusus, ada_foto, foto_kunci}]}`. Aturan pemilihan [K] `helpers/function_helper.php:1419-1475` (getTodayBday/getTomorrowBday):
bulan-hari `tgl_lahir` = hari ini/besok WIB, `pegawai.status = 1`, `pmj.id_satker` ∉ `Config\Dashboard::$idSatkerDikecualikanUlangTahun`
([171]; tanpa snapshot jabatan ikut tersaring), satu baris per NIP, seluruh kementerian; urutan [I] NIP ASC. Field = view
`dashboard_ad.php:250-298`; baris `nip = '1'` → `khusus` (nama, tanggal, `$labelJabatanNipSatu`). `hari` lain → 422.

### `GET api/v1/dashboard/ulang-tahun/{nip}/foto` (jwt, role 1–8) — Q-D19

Foto (`BiodataServiceInterface::fotoSistem`, tanpa cek lingkup PegawaiScope) HANYA bila `{nip}` ada di daftar ulang tahun hari
ini atau besok; selain itu / tanpa foto → 404 seragam. Header sama dengan `pegawai/{nip}/foto` (`no-store`, `nosniff`).

### Butir terbuka

- **Q-D18** (menunggu Horii): pengecualian NIP ulang tahun legacy (1 NIP hardcode + `NIP_TESTER` dari berkas terlarang) belum
  diterapkan; titik sambung `UlangTahunBeranda::nipDikecualikan()` (kosong).
- **Q-D12** (menunggu Horii): legacy `$whereHide_nip` tidak berlaku untuk beberapa akun tertentu (daftar id pengguna hardcode).
  Q7-41 hanya memuat daftar NIP; v2 saat ini memakai pengecualian untuk semua akun. Kunci `.env` tambahan belum dibuat.
- **Deviasi [V2] Tanda Jasa satker/unit** (Horii 10-11-2026): tahun acuan sama dengan kementerian; rollback
  `Config\Dashboard::$tandaJasaIkutLegacy = true`. Rincian (a)–(d): `app/Libraries/Dashboard/README.md`.
- **[I] KP satker/unit:** `get_kp_satker|unit` legacy menghitung percepatan juga untuk `gol_ruang` kosong; v2 memakai perilaku
  `get_all_kp` di semua lingkup (dicek saat QA).
- **Kelengkapan data** (`kd_*` legacy): bug legacy perlu keputusan sebelum di-port (kd_rdiklat selalu ≥ 100, kd_rkgb/kd_rtj
  bisa > 100, kd_rpangkat_2 butuh kode lampiran dari berkas terlarang + loop tak berujung, syarat `id_jenis_pegawai = 1`).
  Q-D13..Q-D17 menunggu Horii; belum ada kartu kelengkapan di beranda pegawai (lanjutan MAKE-087).
- **M3 (lanjutan MAKE-087):** pemasangan komponen Jalur A (popup, banner, berita) dan Jalur B (ringkasan SKL) setelah komponennya ada di `main`.
