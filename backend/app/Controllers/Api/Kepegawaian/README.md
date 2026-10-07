# Modul B — Kepegawaian Core (Fase 3)

Controller REST API modul ini (`App\Controllers\Api\Kepegawaian\*`). Semua controller extends `App\Controllers\Api\ApiController`. Route Fase 3 ada di `Config/RoutesRiwayat.php` (WS-1) dan `Config/RoutesPegawai.php` (WS-2), grup `api/v1` dengan filter `jwt` di level grup; filter `role:*` per route bila role endpoint seragam, izin per jenis riwayat dicek service (lihat [Route](#route)).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## Kontrak API Modul B (S0-A, MAKE-002)

Bagian ini **mengikat** kedua workstream Fase 3 setelah MAKE-002 masuk `main`: WS-1 (mesin riwayat) dan WS-2 (pegawai,
lingkup, lampiran, frontend). Setelah beku, kontrak di sini hanya boleh berubah **secara aditif** dan **oleh pemiliknya**
(lihat [Kepemilikan & perubahan](#kepemilikan--perubahan)). Runbook: `docs/fase3/SPRINT0_1_backend.md`. Revisi hasil
review CR 07-10-2026 (lampiran satu request, lampiran terikat NIP, filter `jwt` grup) sudah termasuk.

### Aturan umum

| Aturan | Isi |
|---|---|
| Prefix | Semua path di bawah relatif `api/v1/` |
| Autentikasi | Semua endpoint butuh JWT (`Authorization: Bearer …`); tanpa token → **401** |
| Envelope | Sukses `{ "status": "success", "data": … }`; gagal `{ "status": "error", "message": "…", "errors"?: { "<field>": ["…"] } }` (ADR-001) |
| Nama field | Payload & respons **= nama kolom DDL** tabelnya (snake_case, mis. `tgl_lulus`, `id_jenjang_pendidikan`, `reason_note`). Tidak ada alias camelCase. Pengecualian eksplisit: baris lampiran memakai kunci **`NIP`** huruf besar, karena nama kolom DDL `document_attachment.NIP` memang begitu |
| Waktu | Disimpan UTC (`created_at`, `updated_at`); tanggal bisnis (`tmtsk`, `tgl_lulus`, …) `YYYY-MM-DD` apa adanya |
| Audit | Tulis riwayat dicatat `audit_logs`; approve/reject dicatat sebagai aksi **`update`** (before/after) |
| `{nip}` | NIP pegawai (PK `pegawai.nip`), segmen tunggal |
| `{jenis}` | Slug jenis riwayat dari [daftar slug beku](#daftar-slug-jenis-beku) |
| `{id}` | PK baris riwayat (`id_riwayat_*`) atau `id_attachment` — **selalu milik `{nip}`**: dicari `WHERE <pk> = {id} AND <kolom nip> = {nip}`; milik NIP lain = 404 (bukan IDOR) |

### Kode status & urutan pemeriksaan

| Kode | Arti |
|---|---|
| 200 / 201 | Sukses (201 untuk `POST` yang membuat baris) |
| 401 | Tanpa token / token tidak valid (filter `jwt` grup) |
| **403** | Role tidak berhak atas aksi itu (izin Definisi riwayat), **atau** NIP di luar lingkup pemanggil (`PegawaiScope`) |
| **404** | `{jenis}`, NIP, baris, atau lampiran tidak ada / bukan milik `{nip}`; juga path yang belum didaftarkan (envelope error, bukan 500) |
| **422** | Validasi gagal — `errors` per field (nama field = kolom DDL; berkas: `berkas` / `berkas.<id_riwayat>`) |
| 501 | Fitur belum tersedia (service masih stub S0-A, `App\Exceptions\BelumTersediaException`) |

Urutan pemeriksaan (wajib, endpoint riwayat dan lampiran):

1. `{jenis}` terdaftar (lampiran: `id_riwayat` dimiliki Definisi terdaftar) → 404.
2. Izin Definisi × role pemanggil untuk aksinya → 403.
3. `PegawaiScope` atas `{nip}` (`bolehLihat` untuk baca, `bolehUbah` untuk tulis) → 403. Bagi pemanggil ber-lingkup, NIP
   yang tidak ada dan NIP di luar lingkup dijawab **sama (403)** — keberadaan NIP tidak bocor.
4. Keberadaan data: NIP (404 bila lingkup pemanggil mencakupnya), baris `{id}` milik `{nip}` → 404.

### Endpoint

| Method & path | Pemilik | Keterangan |
|---|---|---|
| `GET pegawai` | WS-2 | Daftar pegawai, disaring lingkup (`PegawaiScope::terapkanKeQuery`) |
| `GET pegawai/{nip}` | WS-2 | Detail pegawai + `tabs` (array [descriptor tab](#descriptor-tab-beku)) |
| `GET pegawai/{nip}/riwayat/{jenis}` | WS-1 | Daftar baris riwayat (semua status kecuali 10 Dihapus) |
| `GET pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Satu baris |
| `POST pegawai/{nip}/riwayat/{jenis}` | WS-1 | Tambah, **satu request**: `multipart/form-data` berisi field data (kolom DDL) + berkas `berkas[<id_riwayat>]` per kode lampiran; JSON boleh bila jenis tanpa lampiran wajib. Status awal **0 Menunggu** untuk UL_PEGAWAI (role 2/6/7) pada alur self-service, **1 Disetujui** untuk admin. Lampiran ber-`wajib` yang tidak dikirim → 422 `errors.berkas.<id_riwayat>` |
| `PUT pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Ubah. JSON; dengan berkas: `POST` multipart + field `_method=PUT` (PHP tidak mem-parse multipart pada PUT; CI4 method spoofing). Berkas menambah lampiran baris itu. Baris berstatus 1 Disetujui terkunci untuk UL_PEGAWAI (`RiwayatDefinisi::kunciBarisDisetujui()`, default ya) |
| `DELETE pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Hapus lunak → status **10**; snapshot dihitung ulang bila baris itu aktif |
| `POST pegawai/{nip}/riwayat/{jenis}/{id}/process` | WS-1 | Body `{ "aksi": "setujui" \| "tolak", "reason_note": "…" }`; `reason_note` wajib saat `tolak` (422). Snapshot `pegawai_*` disinkron di sini (approval final, ADR-006) |
| `GET pegawai/{nip}/lampiran?id_riwayat=&id_entri=` | WS-2 | Daftar lampiran satu baris riwayat (izin `lihat`) |
| `POST pegawai/{nip}/lampiran` | WS-2 | Tambah/ganti lampiran pada baris yang **sudah ada**: `multipart/form-data` `berkas`, `id_riwayat`, `id_entri` (izin `tambah` atau `ubah`) |
| `GET pegawai/{nip}/lampiran/{id}/unduh` | WS-2 | Unduh berkas lampiran milik `{nip}` (izin `lihat`) |
| `DELETE pegawai/{nip}/lampiran/{id}` | WS-2 | Hapus keras lampiran milik `{nip}` + audit (izin `hapus` atau `ubah`) |

Konket dan LKH **bukan** jenis engine riwayat — endpoint sendiri milik WS-2 (didokumentasikan WS-2 di README ini saat
dibuat). `karpeg` dan `kariskarsu` dipakai halaman **usulan mandiri**, bukan tab Detail Pegawai; endpoint antrean
usulan Karpeg/Karis (WS-1, M4) ditambahkan secara aditif dengan prefix `usulan/karpeg`, `usulan/kariskarsu`
(dicadangkan).

Hak akses per role per jenis disimpan sebagai data di Definisi riwayat (`RiwayatDefinisi::izin()`), mengikuti Matriks v2
(`docs/fase3/MATRIKS_ROLE_MODUL_B.md`); lingkup unit/satker/milik sendiri ditegakkan `PegawaiScope`.

Contoh daftar riwayat:

```json
{ "status": "success",
  "data": [ { "id_riwayat_pendidikan": 12, "nip": "199001012015011001", "tgl_lulus": "2012-08-30",
              "institusi_pendidikan": "…", "status": 0, "reason_note": null } ] }
```

Contoh 422 (tambah riwayat tanpa lampiran wajib):

```json
{ "status": "error", "message": "Validasi gagal.",
  "errors": { "tgl_lulus": ["Tanggal lulus wajib diisi."], "berkas.14": ["Lampiran wajib diunggah."] } }
```

### Lampiran

- **Satu request** (keputusan reviewer CR 07-10-2026): data riwayat + berkas dikirim bersama ke `POST/PUT riwayat`
  dan disimpan dalam **satu transaksi engine** (riwayat + snapshot + lampiran, WS1.md). Endpoint `pegawai/{nip}/lampiran*`
  tetap ada untuk menambah/mengganti/menghapus lampiran pada baris yang sudah ada.
- **Per kode `jenis_rwy`**: satu Definisi memuat **daftar** `AturanLampiran` (`RiwayatDefinisi::lampiran(): list`), satu per
  kode — mis. `riwayat_pendidikan` = 14 pendidikan, 39 pencantuman gelar, 40 transkrip nilai (masing-masing 5 MB, legacy
  `L_pendidikan`); `riwayat_mutasi_jabatan` = 9 jabatan, 36 jabatan_pjft, 41 perjanjian_kerja. Satu kode hanya milik satu
  Definisi (registry menolak kode ganda). `RiwayatRegistryInterface::definisiUntukLampiran(int)` /
  `aturanLampiran(int)` memetakan kode → Definisi/aturan.
- **`wajib`** ditegakkan `RiwayatService::tambah()/ubah()`: setelah request, baris wajib punya minimal satu lampiran
  untuk setiap kode ber-`wajib`.
- **Otorisasi endpoint lampiran** (ditegakkan pemanggil sebelum `AttachmentService`): `id_riwayat` → Definisi pemilik
  (tidak terdaftar → 404); izin Definisi × role: `GET`/unduh = `lihat`, `POST` = `tambah` atau `ubah`, `DELETE` = `hapus`
  atau `ubah` (403); `PegawaiScope` `bolehLihat`/`bolehUbah` (403); `id_entri` wajib baris milik `{nip}` di
  `tabel()` Definisi itu (404). Contoh: role 2 tidak bisa membaca/mengunggah lampiran `hukdis` miliknya sendiri karena
  izin `hukdis` hanya role 1, 3.
- **Berkas**: ekstensi/MIME divalidasi dari **isi** berkas (bukan nama kiriman), batas MB dari `AturanLampiran`; nama &
  path simpan dibangkitkan server, nama asli hanya `display_name`. Error 422 berkunci `berkas` (endpoint lampiran) atau
  `berkas.<id_riwayat>` (endpoint riwayat).
- **Respons lampiran** = baris `document_attachment` apa adanya (kunci `NIP` huruf besar, `id_riwayat`, `id_entri`,
  `display_name`, `file_size`, `file_ext`, …); `path` internal tidak dikirim ke klien.

### Descriptor tab (beku)

`GET pegawai/{nip}` mengembalikan `data.tabs`: array descriptor. Frontend merender tab dan tombol aksi **hanya** dari
descriptor ini.

```json
{ "jenis": "pendidikan", "label": "Riwayat Pendidikan",
  "can_view": true, "can_create": true, "can_edit": true, "can_delete": false, "can_process": false }
```

`data.tabs` = descriptor **engine** (dari `RiwayatRegistryInterface::descriptorUntuk(AuthContext, nip)`, urut
`urutanTab()` Definisi lalu slug) **diikuti** descriptor **non-engine** yang ditambahkan WS-2 dengan bentuk sama
(mis. `{ "jenis": "lkh", "label": "Laporan Kerja Harian", … }`). Nilai `jenis` di `tabs` ⊇ slug engine; `jenis`
non-engine tidak boleh sama dengan slug engine.

Descriptor engine:

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

Tabel yang CHECK status-nya `IN (0, 1, 2, 10)` di DDL main (`riwayat_ak`, `riwayat_ak_siasn`, `riwayat_alamat`,
`riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_skp_periodik`): Definisi-nya **wajib** override `pemetaanStatus()`
tanpa 3.

### Kontrak backend (interface & service)

Didaftarkan **sekali** di `Config\Services` (S0-A); tipe kembalian = interface. Stub produksi di
`App\Libraries\Kepegawaian\Stub` **fail-closed** (`StubPegawaiScope` menolak semua; setiap method stub lain melempar
`App\Exceptions\BelumTersediaException` → 501). Fake test hanya di `tests/_support/Kepegawaian` (`FakePegawaiScope`,
`FakeAttachmentService`, `FakeStorageAdapter`), disuntik `Services::injectMock('<nama>', $fake)`. `riwayatRegistry`
me-resolve `pegawaiScope` setiap dipakai, jadi scope yang disuntik berlaku juga untuk registry shared yang sudah
ter-resolve (tanpa `resetSingle`).

| Service | Interface (`App\Interfaces\Kepegawaian\…`) | Pemilik implementasi |
|---|---|---|
| `pegawaiScope` | `PegawaiScopeInterface`: `bolehLihat(AuthContext, nip)`, `bolehUbah(AuthContext, nip)`, `terapkanKeQuery(BaseBuilder, AuthContext, kolomNip = 'nip')` | WS-2 (MAKE-009) |
| `attachmentService` | `AttachmentServiceInterface`: `simpan(nip, idRiwayat, idEntri, UploadedFile, AturanLampiran): array`, `ambil(nip, idAttachment): array{lampiran, isi}`, `hapus(nip, idAttachment)`, `daftar(nip, idRiwayat, idEntri): list` — lampiran bukan milik `nip` → 404; di dalam transaksi pemanggil, kompensasi berkas bila rollback | WS-2 (MAKE-009) |
| `storageAdapter` | `StorageAdapterInterface`: `simpan`, `hapus`, `ada`, `baca` (path relatif) | WS-2 (MAKE-009) |
| `riwayatRegistry` | `RiwayatRegistryInterface`: `semua()`, `definisi(jenis)`, `definisiUntukLampiran(idRiwayat)`, `aturanLampiran(idRiwayat)`, `descriptorUntuk(AuthContext, nip)` — sudah nyata (`RiwayatRegistry`, auto-discovery) | WS-1 |
| `riwayatService` | `RiwayatServiceInterface`: `daftar`, `detail`, `tambah(…, array $data, array $berkas = [])`, `ubah(…, array $data, array $berkas = [])`, `hapus`, `proses` — `$berkas` = kode `jenis_rwy` ⇒ `UploadedFile` | WS-1 (MAKE-004) |
| `snapshotSync` | `SnapshotSyncInterface`: `sinkronkan(RiwayatDefinisi, nip)` | WS-1 (MAKE-004) |
| `pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService` | Interface penanda tanpa method; method ditetapkan pemiliknya di milestone-nya (aditif) | WS-2 (MAKE-010..014) |

**Definisi riwayat** (`App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi`, pemilik WS-1): satu berkas per jenis di
`app/Libraries/Kepegawaian/Riwayat/Definisi/`, ditemukan otomatis. Isinya data: `jenis()` (slug), `label()`, `tabel()`,
`primaryKey()`, `kolomNip()`, `kolomStatus()`, `fields()` (`MasterField`), `izin()` (aksi `lihat`/`tambah`/`ubah`/
`hapus`/`proses` → role), `alur()` (`self-service`/`admin`/`usulan`), `pemetaanStatus()`, `kunciBarisDisetujui()`,
`snapshot()` (`AturanSnapshot`: tabel target, kolom, filter, urutan, join — multi-target), `lampiran()` (**daftar**
`AturanLampiran`, satu per kode `jenis_rwy`: wajib, batas 1/2/5 MB, ekstensi), `urutanTab()`, dan hook opsional
`validate`, `beforeSave`, `afterApprove`.

**Fixture test** `Tests\Support\Kepegawaian\PegawaiFixtureTrait` (bersama, dipakai di test turunan
`Tests\Support\DatabaseTestCase` MAKE-001): `buatPegawai(array $override = [], array $pmj = []): string`,
`buatPegawaiDiSatker(int $idSatker, …): string`, `buatAkunUntuk(string $nip, int $role, …): int` (pegawai wajib ada),
`buatRiwayatMutasiJabatan(string $nip, array $override = []): int`, `buatUnit()`, `buatSatker()`, `buatJabatan()`,
`buatJabatanKoordinasi()`, `buatRumpunJabatan()`, `authUntukAkun(int)`. Hanya INSERT (bingkai transaksi uji MAKE-001
tanpa `db-isolasi-penuh`), NIP sintetis 18 digit, kolom FK diisi master G-02 yang dibuat fixture (patuh FK DBV-019),
kolom teks snapshot diisi nama master. Pemilik setelah S0: berkas bersama — WS mana pun boleh **menambah** helper
(satu helper per commit); mengubah perilaku helper yang ada butuh persetujuan reviewer CR.

### Route

Route Fase 3 di berkas terpisah lewat `Config\Routing::$routeFiles`: `Config/RoutesRiwayat.php` (WS-1) dan
`Config/RoutesPegawai.php` (WS-2). `Config/Routes.php` tidak disentuh lagi. NIP memakai `(:segment)`, bukan `(:any)`.

- Filter **`jwt` dipasang di level grup** pada kedua berkas, jadi route yang ditambahkan WS tidak pernah terbuka tanpa
  token; filter per route (mis. `Role::filter(...)`) digabung dengan `jwt`, bukan menggantinya. Dijaga
  `tests/Kepegawaian/RouteRiwayatKontrakTest.php` dan `RoutePegawaiKontrakTest.php`.
- Route riwayat memakai `jwt` saja (tanpa `role:*`); izin per role per jenis dicek service dari Definisi (403), karena
  role berbeda per jenis. Ini **penyimpangan dari §2.3.1 SPRINT0_1_backend.md yang disetujui reviewer CR 07-10-2026**.
  Route WS-2 yang role-nya seragam (mis. `GET pegawai` = 1, 3, 4, 5, 8) memakai `Role::filter(...)` per route.

### Kepemilikan & perubahan

Setelah MAKE-002 di `main`, yang berikut hanya boleh berubah aditif dan oleh pemiliknya; perubahan non-aditif butuh
persetujuan reviewer CR dan pemberitahuan ke WS lain:

1. `PegawaiScopeInterface` — WS-2
2. `AttachmentServiceInterface`, `StorageAdapterInterface` — WS-2
3. `RiwayatDefinisi` (+ `AturanLampiran`, `AturanSnapshot`, `AksiRiwayat`, `AlurRiwayat`, `StatusRiwayat`,
   `JenisRiwayat`) dan API engine — WS-1. `AturanLampiran` tetap di `Libraries/Kepegawaian/Riwayat/` dengan pemilik
   WS-1 (keputusan reviewer CR 07-10-2026); WS-2 memakainya, permintaan perubahan diajukan ke WS-1.
4. Descriptor tab + daftar slug `{jenis}` — WS-1 (descriptor non-engine: WS-2, bentuk sama)
5. Bentuk endpoint & kode status di README ini

Satu-satunya perubahan `Config/Services.php` setelah S0-A: pemilik service mengganti `new Stub…` dengan kelas nyata
(dicatat di commit milestone pemilik). Pemilik interface penanda yang menambah method juga memperbarui stub-nya
(tetap fail-closed, dijaga `tests/unit/Kepegawaian/Stub/StubFailClosedTest.php`) atau menggantinya di `Services.php`
pada commit yang sama.
