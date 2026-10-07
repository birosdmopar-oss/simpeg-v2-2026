# Modul B — Kepegawaian Core (Fase 3)

Controller REST API modul ini (`App\Controllers\Api\Kepegawaian\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` (lihat `Config\Routes`).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## Kontrak API Modul B (S0-A, MAKE-002)

Bagian ini **mengikat** kedua workstream Fase 3 setelah MAKE-002 masuk `main`: WS-1 (mesin riwayat) dan WS-2 (pegawai,
lingkup, lampiran, frontend). Setelah beku, kontrak di sini hanya boleh berubah **secara aditif** dan **oleh pemiliknya**
(lihat [Kepemilikan & perubahan](#kepemilikan--perubahan)). Runbook: `docs/fase3/SPRINT0_1_backend.md`.

### Aturan umum

| Aturan | Isi |
|---|---|
| Prefix | Semua path di bawah relatif `api/v1/` |
| Autentikasi | Semua endpoint butuh JWT (`Authorization: Bearer …`); tanpa token → **401** |
| Envelope | Sukses `{ "status": "success", "data": … }`; gagal `{ "status": "error", "message": "…", "errors"?: { "<field>": ["…"] } }` (ADR-001) |
| Nama field | Payload & respons **snake_case = nama kolom DDL** tabelnya (mis. `tgl_lulus`, `id_jenjang_pendidikan`, `reason_note`). Tidak ada alias camelCase |
| Waktu | Disimpan UTC (`created_at`, `updated_at`); tanggal bisnis (`tmtsk`, `tgl_lulus`, …) `YYYY-MM-DD` apa adanya |
| Audit | Tulis riwayat dicatat `audit_logs`; approve/reject dicatat sebagai aksi **`update`** (before/after) |
| `{nip}` | NIP pegawai (PK `pegawai.nip`), segmen tunggal |
| `{jenis}` | Slug jenis riwayat dari [daftar slug beku](#daftar-slug-jenis-beku) |
| `{id}` | PK baris riwayat (`id_riwayat_*`) atau `id_attachment` |

### Kode status

| Kode | Arti |
|---|---|
| 200 / 201 | Sukses (201 untuk `POST` yang membuat baris) |
| 401 | Tanpa token / token tidak valid |
| **403** | Role tidak berhak atas aksi itu (izin Definisi riwayat), **atau** NIP di luar lingkup pemanggil (`PegawaiScope`) |
| **404** | NIP, baris, atau `{jenis}` tidak ada; juga path yang belum didaftarkan (envelope error, bukan 500) |
| **422** | Validasi gagal — `errors` per field (nama field = kolom DDL) |
| 501 | Fitur belum tersedia (service masih stub S0-A) |

### Endpoint

| Method & path | Pemilik | Keterangan |
|---|---|---|
| `GET pegawai` | WS-2 | Daftar pegawai, disaring lingkup (`PegawaiScope::terapkanKeQuery`) |
| `GET pegawai/{nip}` | WS-2 | Detail pegawai + `tabs` (array [descriptor tab](#descriptor-tab-beku)) |
| `GET pegawai/{nip}/riwayat/{jenis}` | WS-1 | Daftar baris riwayat (semua status kecuali 10 Dihapus) |
| `GET pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Satu baris |
| `POST pegawai/{nip}/riwayat/{jenis}` | WS-1 | Tambah. Status awal **0 Menunggu** untuk UL_PEGAWAI (role 2/6/7) pada alur self-service, **1 Disetujui** untuk admin |
| `PUT pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Ubah. Baris berstatus 1 Disetujui terkunci untuk UL_PEGAWAI (`RiwayatDefinisi::kunciBarisDisetujui()`, default ya) |
| `DELETE pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Hapus lunak → status **10**; snapshot dihitung ulang bila baris itu aktif |
| `POST pegawai/{nip}/riwayat/{jenis}/{id}/process` | WS-1 | Body `{ "aksi": "setujui" \| "tolak", "reason_note": "…" }`; `reason_note` wajib saat `tolak` (422). Snapshot `pegawai_*` disinkron di sini (approval final, ADR-006) |
| `GET pegawai/{nip}/lampiran` | WS-2 | Daftar lampiran; query `id_riwayat` (kode `jenis_rwy`), `id_entri` (PK baris riwayat) |
| `POST pegawai/{nip}/lampiran` | WS-2 | Unggah (`multipart/form-data`: `berkas`, `id_riwayat`, `id_entri`); batas MB & ekstensi dari `AturanLampiran` jenisnya (1/2/5 MB ikut legacy) |
| `GET pegawai/{nip}/lampiran/{id}/unduh` | WS-2 | Unduh berkas |
| `DELETE pegawai/{nip}/lampiran/{id}` | WS-2 | Hapus keras + audit |

Konket dan LKH **bukan** jenis engine riwayat — endpoint sendiri milik WS-2 (didokumentasikan WS-2 di README ini saat
dibuat). `karpeg` dan `kariskarsu` dipakai halaman **usulan mandiri**, bukan tab Detail Pegawai.

Hak akses per role per jenis disimpan sebagai data di Definisi riwayat (`RiwayatDefinisi::izin()`), mengikuti Matriks v2
(`docs/fase3/MATRIKS_ROLE_MODUL_B.md`). Karena role berbeda per jenis, route riwayat memakai filter `jwt` saja dan
service yang menolak dengan 403; lingkup unit/satker/milik sendiri ditegakkan `PegawaiScope`.

Contoh daftar riwayat:

```json
{ "status": "success",
  "data": [ { "id_riwayat_pendidikan": 12, "nip": "199001012015011001", "tgl_lulus": "2012-08-30",
              "institusi_pendidikan": "…", "status": 0, "reason_note": null } ] }
```

Contoh 422:

```json
{ "status": "error", "message": "Validasi gagal.", "errors": { "tgl_lulus": ["Tanggal lulus wajib diisi."] } }
```

### Descriptor tab (beku)

`GET pegawai/{nip}` mengembalikan `data.tabs`: array descriptor, urut `urutanTab()` Definisi lalu slug. Frontend merender
tab dan tombol aksi **hanya** dari descriptor ini.

```json
{ "jenis": "pendidikan", "label": "Riwayat Pendidikan",
  "can_view": true, "can_create": true, "can_edit": true, "can_delete": false, "can_process": false }
```

Dihitung `RiwayatRegistryInterface::descriptorUntuk(AuthContext, nip)`:

- `can_view` = role pemanggil ada di izin `lihat` Definisi **dan** `PegawaiScope::bolehLihat`.
- `can_create` / `can_edit` / `can_delete` / `can_process` = izin `tambah` / `ubah` / `hapus` / `proses` **dan**
  `PegawaiScope::bolehUbah`.
- Jenis yang `can_view`-nya false **tidak** dimasukkan; jenis ber-alur `usulan` (`karpeg`, `kariskarsu`) tidak pernah
  menjadi tab.
- `can_edit`/`can_delete` adalah hak umum atas jenis itu; penguncian per baris (baris Disetujui untuk UL_PEGAWAI)
  ditegakkan server per baris.

### Daftar slug `{jenis}` (beku)

Konstanta `App\Libraries\Kepegawaian\Riwayat\JenisRiwayat::SLUG`; dipakai juga sebagai nama berkas registry frontend
`riwayat/jenis/<slug>.ts`.

| Slug | Jenis | Tabel riwayat | Task |
|---|---|---|---|
| `jabatan` | Jabatan / mutasi | `riwayat_mutasi_jabatan` | B-07 (WS-2) |
| `kp` | Kenaikan pangkat | `riwayat_kp` | B-08 |
| `kgb` | Kenaikan gaji berkala | `riwayat_kgb` | B-09 |
| `pendidikan` | Pendidikan | `riwayat_pendidikan` | B-10 |
| `diklat` | Diklat | `riwayat_diklat` | B-11 |
| `seminar` | Kursus / seminar | `riwayat_seminar` | B-11 |
| `skp` | SKP tahunan | `riwayat_skp` | B-12a |
| `skp-periodik` | SKP periodik | `riwayat_skp_periodik` | B-12a |
| `hukdis` | Hukuman disiplin | `riwayat_hukdis` | B-14 |
| `ak` | Angka kredit | `riwayat_ak` | B-15 |
| `ak-siasn` | Angka kredit SIASN | `riwayat_ak_siasn` | B-15 |
| `keluarga` | Keluarga | `riwayat_keluarga` | B-16 |
| `alamat` | Alamat | `riwayat_alamat` | B-16 |
| `tanda-jasa` | Tanda jasa | `riwayat_tanda_jasa` | B-17 |
| `organisasi` | Organisasi | `riwayat_organisasi` | B-17 |
| `karpeg` | Kartu pegawai (usulan mandiri) | `riwayat_karpeg` | B-17 |
| `kariskarsu` | Karis/Karsu (usulan mandiri) | `riwayat_kariskarsu` | B-17 |

Nama tabel final per jenis ditetapkan Definisi jenisnya (`RiwayatDefinisi::tabel()`), mengikuti DDL B-02 di `main`.

### Status riwayat

`App\Libraries\Kepegawaian\Riwayat\StatusRiwayat` (kolom `status` tabel riwayat, ikut legacy):

| Nilai | Status | Catatan |
|---|---|---|
| 0 | Menunggu | Diajukan UL_PEGAWAI, menunggu proses |
| 1 | Disetujui | Satu-satunya status yang mengisi snapshot `pegawai_*` |
| 2 | Ditolak | `reason_note` terisi |
| 3 | Diproses | **Hanya** bila `Config\Kepegawaian::$statusDiprosesAktif` (default `false`) |
| 10 | Dihapus | Hapus lunak; tidak tampil di daftar |

### Kontrak backend (interface & service)

Didaftarkan **sekali** di `Config\Services` (S0-A); tipe kembalian = interface. Stub produksi di
`App\Libraries\Kepegawaian\Stub` **fail-closed** (`StubPegawaiScope` menolak semua; service lain melempar
`BelumTersediaException` → 501). Fake test hanya di `tests/_support/Kepegawaian` (`FakePegawaiScope`,
`FakeAttachmentService`, `FakeStorageAdapter`), disuntik `Services::injectMock('<nama>', $fake)`.

| Service | Interface (`App\Interfaces\Kepegawaian\…`) | Pemilik implementasi |
|---|---|---|
| `pegawaiScope` | `PegawaiScopeInterface`: `bolehLihat(AuthContext, nip)`, `bolehUbah(AuthContext, nip)`, `terapkanKeQuery(BaseBuilder, AuthContext, kolomNip = 'nip')` | WS-2 (MAKE-009) |
| `attachmentService` | `AttachmentServiceInterface`: `simpan(nip, idRiwayat, idEntri, UploadedFile, AturanLampiran): array`, `hapus(idAttachment)`, `daftar(nip, idRiwayat, idEntri): list` — di dalam transaksi pemanggil, kompensasi berkas bila rollback | WS-2 (MAKE-009) |
| `storageAdapter` | `StorageAdapterInterface`: `simpan`, `hapus`, `ada`, `baca` (path relatif) | WS-2 (MAKE-009) |
| `riwayatRegistry` | `RiwayatRegistryInterface`: `semua()`, `definisi(jenis)`, `descriptorUntuk(AuthContext, nip)` — sudah nyata (`RiwayatRegistry`, auto-discovery) | WS-1 |
| `riwayatService` | `RiwayatServiceInterface`: `daftar`, `detail`, `tambah`, `ubah`, `hapus`, `proses` (endpoint riwayat di atas) | WS-1 (MAKE-004) |
| `snapshotSync` | `SnapshotSyncInterface`: `sinkronkan(RiwayatDefinisi, nip)` | WS-1 (MAKE-004) |
| `pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService` | Interface penanda tanpa method; method ditetapkan pemiliknya di milestone-nya (aditif) | WS-2 (MAKE-010..014) |

**Definisi riwayat** (`App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi`, pemilik WS-1): satu berkas per jenis di
`app/Libraries/Kepegawaian/Riwayat/Definisi/`, ditemukan otomatis. Isinya data: `jenis()` (slug), `label()`, `tabel()`,
`primaryKey()`, `kolomNip()`, `kolomStatus()`, `fields()` (`MasterField`), `izin()` (aksi `lihat`/`tambah`/`ubah`/
`hapus`/`proses` → role), `alur()` (`self-service`/`admin`/`usulan`), `pemetaanStatus()`, `kunciBarisDisetujui()`,
`snapshot()` (`AturanSnapshot`: tabel target, kolom, filter, urutan, join — multi-target), `lampiran()`
(`AturanLampiran`: kode `jenis_rwy`, wajib, batas 1/2/5 MB, ekstensi), `urutanTab()`, dan hook opsional `validate`,
`beforeSave`, `afterApprove`.

**Fixture test** `Tests\Support\Kepegawaian\PegawaiFixtureTrait` (bersama, dipakai di test turunan
`Tests\Support\DatabaseTestCase` MAKE-001):
`buatPegawai(array $override = [], array $pmj = []): string`, `buatPegawaiDiSatker(int $idSatker, …): string`,
`buatAkunUntuk(string $nip, int $role, …): int`, `buatUnit()`, `buatSatker()`, `buatJabatan()`, `authUntukAkun(int)`.
Hanya INSERT (berjalan di bingkai transaksi uji MAKE-001 tanpa `db-isolasi-penuh`), NIP sintetis 18 digit, kolom FK diisi master G-02 yang
dibuat fixture (patuh FK DBV-019).

### Route

Route Fase 3 di berkas terpisah lewat `Config\Routing::$routeFiles`: `Config/RoutesRiwayat.php` (WS-1) dan
`Config/RoutesPegawai.php` (WS-2). `Config/Routes.php` tidak disentuh lagi. NIP memakai `(:segment)`, bukan `(:any)`.

### Kepemilikan & perubahan

Setelah MAKE-002 di `main`, yang berikut hanya boleh berubah aditif dan oleh pemiliknya; perubahan non-aditif butuh
persetujuan reviewer CR dan pemberitahuan ke WS lain:

1. `PegawaiScopeInterface` — WS-2
2. `AttachmentServiceInterface`, `StorageAdapterInterface`, `AturanLampiran` — WS-2
3. `RiwayatDefinisi` (+ `AturanSnapshot`, `AksiRiwayat`, `AlurRiwayat`, `StatusRiwayat`) dan API engine — WS-1
4. Descriptor tab + daftar slug `{jenis}` — WS-1
5. Bentuk endpoint & kode status di README ini

Satu-satunya perubahan `Config/Services.php` setelah S0-A: pemilik service mengganti `new Stub…` dengan kelas nyata
(dicatat di commit milestone pemilik). Pemilik interface penanda yang menambah method juga memperbarui stub-nya
(tetap fail-closed) atau menggantinya di `Services.php` pada commit yang sama.
