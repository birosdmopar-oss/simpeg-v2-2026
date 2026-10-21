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
