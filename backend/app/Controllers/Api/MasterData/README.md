# Modul G — Master Data & Pengaturan (Fase 2)

Controller REST API modul ini (`App\Controllers\Api\MasterData\*`), semua extends `App\Controllers\Api\ApiController`.
Envelope: sukses `{status:'success', data}`, gagal `{status:'error', message, errors?}` (ADR-001).
Role akses mengacu Matriks Role x Endpoint Bagian 2 Modul G. Prefix seluruh path: `/api/v1`.

## Engine CRUD master generik

Seluruh master memakai satu engine. Menambah master = migration + 1 entri di `Config\MasterData` + key di controller grupnya.

| Komponen | Peran |
|---|---|
| `Config\MasterData` | Registry master (tabel, PK, kolom nama, induk, controller grup). Satu sumber kebenaran untuk routing, service, dan metadata form FE |
| `Libraries\MasterData\MasterService` | Seluruh aturan G-TC (keunikan, soft delete, reorder, cache dropdown), transaksi |
| `Models\MasterData\MasterModel` | Model generik turunan `BaseAuditableModel` → audit otomatis |
| `Controllers\Api\MasterData\BaseMasterController` | Endpoint generik + validasi bentuk. Controller grup (`UmumController`, dst.) hanya mendaftarkan key master |

Skema mengikuti SIMPEG legacy (DBV-001, G-01 Bagian 8): nama tabel & kolom legacy, status **`1` Aktif / `2` Tidak Aktif / `10` Dihapus**, kolom audit legacy (`created_at`, `updated_at`, `updated_by` = `id_pengguna` aktor; `agama` + `deleted_at`).

Kode master (PK) **tidak bisa diubah** setelah dibuat. Dua bentuk:
- **kode diinput admin** (wilayah): string tepat 2/4/7/10 digit angka (`CHAR(N)`), tidak pernah di-cast ke int (`'09'` ≠ `'9'`);
- **AUTO_INCREMENT** (agama, jenis pegawai, jenis status): diberikan DB, kode dari input diabaikan.

## Endpoint

`{entity}` = key master (tabel di bawah). `{kode}` = PK entri.

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/master/meta` | 1 | Daftar master + metadata form/tabel (dipakai halaman Master Data FE) |
| GET | `/master/{entity}` | 1 | Daftar, urut induk → `order` → nama. Default **tanpa** status `10` (seperti legacy). Query: `search`, `status` (`1`/`2`/`10`), `parent` (id induk), `page`, `per_page` (≤100) |
| GET | `/master/{entity}/options` | **UL_ALL** | Dropdown untuk modul lain: **hanya entri aktif**, urut `order`. Query: `parent`. Di-cache, invalidasi di setiap penulisan |
| POST | `/master/{entity}` | 1 | Tambah → 201 |
| GET | `/master/{entity}/{kode}` | 1 | Detail (+ `parent_nama` untuk master berinduk) |
| PUT | `/master/{entity}/{kode}` | 1 | Ubah parsial (nama, induk, order, status). Kode tidak ikut diubah |
| PATCH | `/master/{entity}/{kode}/status` | 1 | Toggle `{ "status": "1"\|"2" }`; juga **memulihkan** entri berstatus `10` (kosongkan `deleted_at`) |
| PATCH | `/master/{entity}/{kode}/order` | 1 | Pindah posisi `{ "order": n }` (1-based, per induk); entri lain bergeser |
| DELETE | `/master/{entity}/{kode}` | 1 | **Soft delete** → `status=10` (+ `deleted_at` bila ada). Tidak pernah hard delete |

Role selain 1 → `403 {status:'error', message:'Forbidden'}`; tanpa token → 401.

### Master yang tersedia

| Task | Controller | `{entity}` | Tabel | PK | Nama | Induk | Kolom tambahan |
|---|---|---|---|---|---|---|---|
| G-07 | `UmumController` | `agama` | agama | id_agama (AUTO_INCREMENT) | agama (≤30) | — | — |
| G-07 | `UmumController` | `jenis-pegawai` | jenis_pegawai | id_jenis_pegawai (AUTO_INCREMENT) | jenis_pegawai (≤50) | — | — |
| G-07 | `UmumController` | `jenis-status` | jenis_status | id_jenis_status (AUTO_INCREMENT) | jenis_status (≤50) | — | `status_pegawai` wajib `1`/`2`; nama unik per status_pegawai |
| G-07 | `UmumController` | `provinsi` | provinsi | id_provinsi (2 digit) | provinsi (≤255) | — | — |
| G-07 | `UmumController` | `kabupaten-kota` | kabupaten_kota | id_kabupaten_kota (4 digit) | kabupaten_kota | `id_provinsi` → provinsi | `kd_area` (≤4) |
| G-07 | `UmumController` | `kecamatan` | kecamatan | id_kecamatan (7 digit) | kecamatan | `id_kabupaten_kota` → kabupaten-kota | — |
| G-07 | `UmumController` | `kelurahan` | kelurahan | id_kelurahan (10 digit) | kelurahan | `id_kecamatan` → kecamatan | `kd_pos` (kode pos 5 digit, boleh beberapa dipisah koma) |

Master lain (jabatan, lokasi presensi, KP, pendidikan, diklat/hukdis/konket/tanda jasa, kantor, hari libur, web config, FAQ) menyusul setelah skemanya disetujui DB Validator — lihat `backend/docs/progress/02-MasterData.md`.

**Dropdown berjenjang wilayah 4 level:** `provinsi/options` → `kabupaten-kota/options?parent={id_provinsi}` → `kecamatan/options?parent={id_kabupaten_kota}` → `kelurahan/options?parent={id_kecamatan}`.

## Payload & response

### POST /master/{entity}
Contoh kelurahan: `{ "id_kelurahan": "3171010003", "id_kecamatan": "3171010", "kelurahan": "Petojo Utara", "kd_pos": "10130", "order": 2 }`
Contoh agama (kode otomatis): `{ "agama": "Kepercayaan" }`

| Field | Aturan |
|---|---|
| PK (`id_*`) | wilayah: wajib, **tepat N digit angka** (2/4/7/10), unik; master AUTO_INCREMENT: tidak dikirim (diabaikan). `options`/`meta` dicadangkan |
| induk (kalau ada) | wajib, harus ada **dan aktif** |
| nama | wajib, ≤ panjang kolom; spasi dirapikan; **unik per induk (+ `status_pegawai` untuk jenis status), case-insensitive, termasuk entri tidak aktif & dihapus** — ditegakkan juga oleh UNIQUE index DB |
| kolom tambahan | sesuai tabel master di atas (`kd_area`, `kd_pos`, `status_pegawai`) |
| `order` | opsional, bilangan ≥1 = posisi sisip; kosong = paling akhir |
| `status` | opsional `'1'`/`'2'` (default `'1'`); `10` hanya lewat DELETE |

| Status | Kapan | Body |
|---|---|---|
| 201 | sukses | `data: { <kolom tabel>, order, status, parent_nama? }` |
| 422 | kode dipakai / nama duplikat / induk tidak ada atau tidak aktif / format salah | `errors: { <field>: ["..."] }` — duplikat nama menyebut kode entri yang sudah ada (dan saran aktifkan kembali / pulihkan kalau entri itu tidak aktif / dihapus) |

### PUT /master/{entity}/{kode}
Parsial; field yang dikirim wajib terisi. Pindah induk → entri ditaruh di akhir induk baru, urutan induk lama dirapikan. `order` (kalau dikirim) memindah posisi.

### DELETE /master/{entity}/{kode}
`data: { deleted: true, soft_delete: true, item: {…, status:'10'} }`. Audit dicatat sebagai event `delete` (before/after). Relasi dari data lain tetap utuh; FK `ON DELETE RESTRICT` di DB menolak hard delete master yang direlasikan.

### GET /master/{entity}/options
`data: [ { "id": "3171010001", "nama": "Gambir", "parent": "3171010" }, … ]` — hanya `status=1`.

## G-TC → bukti otomatis

| G-TC | Test |
|---|---|
| #1 Keunikan nama/kode | `tests/MasterData/MasterGenericTcTest::testCreateSucceedsAndDuplicateCodeOrNameIsRejected`, `testSameNameIsAllowedUnderDifferentParent`, `testJenisStatusNameIsUniquePerStatusPegawai`, `testKodeWilayahMustBeExactDigits` |
| #2 Soft-delete only | `testDeleteIsAlwaysSoftAndReferencedMasterCannotBeHardDeleted` (termasuk FK RESTRICT), `testDeletedEntriesAreHiddenByDefaultAndCanBeRestored` |
| #3 Toggle status → dropdown | `testStatusToggleIsReflectedInOptionsImmediately`, `testFourLevelCascadeOptions` |
| #4 Re-ordering | `testReorderShiftsOtherEntriesAndKeepsDropdownLogical`, `testMovingToAnotherParentAppendsAndRenumbersOldParent` |
| #5 RBAC | `tests/MasterData/RbacMasterEndpointsTest` (8 role × seluruh endpoint × seluruh master) |
| #6 Audit log | `testAuditLogIsRecordedForCreateUpdateDeleteWithActor`, `testLegacyAuditColumnsAreFilledWithActor`, `testLeadingZeroCodeIsPreservedInRouteAndAudit` |
| Skema DBV-001 | `tests/MasterData/Batch1LegacySchemaTest` (kolom legacy, collation, UNIQUE, FK legacy, rollback, tolak jalan saat tabel berisi data) |
| #7 QA Lapis 1 (Figma) | **Belum bisa** — desain Figma belum ada (item terbuka 00-INDEX) |
