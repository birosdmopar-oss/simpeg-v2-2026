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

Kode master (PK) adalah **string** (mis. `'01'`, `'3171'`), tidak pernah di-cast ke int, dan **tidak bisa diubah** setelah dibuat.

## Endpoint

`{entity}` = key master (tabel di bawah). `{kode}` = PK entri.

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/master/meta` | 1 | Daftar master + metadata form/tabel (dipakai halaman Master Data FE) |
| GET | `/master/{entity}` | 1 | Daftar, urut induk → `order` → nama. Query: `search`, `status` (`0`/`1`), `parent` (id induk), `page`, `per_page` (≤100) |
| GET | `/master/{entity}/options` | **UL_ALL** | Dropdown untuk modul lain: **hanya entri aktif**, urut `order`. Query: `parent`. Di-cache, invalidasi di setiap penulisan |
| POST | `/master/{entity}` | 1 | Tambah → 201 |
| GET | `/master/{entity}/{kode}` | 1 | Detail (+ `parent_nama` untuk master berinduk) |
| PUT | `/master/{entity}/{kode}` | 1 | Ubah parsial (nama, induk, order, status). Kode tidak ikut diubah |
| PATCH | `/master/{entity}/{kode}/status` | 1 | Toggle aktif/non-aktif `{ "status": "0"\|"1" }` |
| PATCH | `/master/{entity}/{kode}/order` | 1 | Pindah posisi `{ "order": n }` (1-based, per induk); entri lain bergeser |
| DELETE | `/master/{entity}/{kode}` | 1 | **Soft delete** → `status='0'`. Tidak pernah hard delete |

Role selain 1 → `403 {status:'error', message:'Forbidden'}`; tanpa token → 401.

### Master yang tersedia

| Task | Controller | `{entity}` | Tabel | PK | Nama | Induk |
|---|---|---|---|---|---|---|
| G-07 | `UmumController` | `agama` | agama | id_agama (≤5) | nama_agama | — |
| G-07 | `UmumController` | `jenis-pegawai` | jenis_pegawai | id_jenis_pegawai (≤5) | nama_jenis_pegawai | — |
| G-07 | `UmumController` | `jenis-status` | jenis_status | id_jenis_status (≤5) | nama_jenis_status | — |
| G-07 | `UmumController` | `provinsi` | provinsi | id_provinsi (≤10) | nama_provinsi | — |
| G-07 | `UmumController` | `kabupaten-kota` | kabupaten_kota | id_kabupaten_kota (≤10) | nama_kabupaten_kota | `id_provinsi` → provinsi |
| G-07 | `UmumController` | `kecamatan` | kecamatan | id_kecamatan (≤10) | nama_kecamatan | `id_kabupaten_kota` → kabupaten-kota |
| G-07 | `UmumController` | `kelurahan` | kelurahan | id_kelurahan (≤10) | nama_kelurahan | `id_kecamatan` → kecamatan |

Master lain (jabatan, lokasi presensi, KP, pendidikan, diklat/hukdis/konket/tanda jasa, kantor, hari libur, web config, FAQ) menyusul setelah skemanya disetujui DB Validator — lihat `backend/docs/progress/02-MasterData.md`.

**Dropdown berjenjang wilayah 4 level:** `provinsi/options` → `kabupaten-kota/options?parent={id_provinsi}` → `kecamatan/options?parent={id_kabupaten_kota}` → `kelurahan/options?parent={id_kecamatan}`.

## Payload & response

### POST /master/{entity}
Contoh kelurahan: `{ "id_kelurahan": "3171011003", "id_kecamatan": "317101", "nama_kelurahan": "Petojo Utara", "order": 2 }`

| Field | Aturan |
|---|---|
| PK (`id_*`) | wajib, ≤ panjang PK, `^[A-Za-z0-9._-]+$`, unik; `options`/`meta` dicadangkan |
| induk (kalau ada) | wajib, harus ada **dan aktif** |
| nama | wajib, ≤ panjang kolom; spasi dirapikan; **unik per induk, case-insensitive, termasuk entri non-aktif** |
| `order` | opsional, bilangan ≥1 = posisi sisip; kosong = paling akhir |
| `status` | opsional `'0'`/`'1'` (default `'1'`) |

| Status | Kapan | Body |
|---|---|---|
| 201 | sukses | `data: { <kolom tabel>, order, status, parent_nama? }` |
| 422 | kode dipakai / nama duplikat / induk tidak ada atau non-aktif / format salah | `errors: { <field>: ["..."] }` — duplikat nama menyebut kode entri yang sudah ada (dan saran aktifkan kembali kalau entri itu non-aktif) |

### PUT /master/{entity}/{kode}
Parsial; field yang dikirim wajib terisi. Pindah induk → entri ditaruh di akhir induk baru, urutan induk lama dirapikan. `order` (kalau dikirim) memindah posisi.

### DELETE /master/{entity}/{kode}
`data: { deleted: true, soft_delete: true, item: {…, status:'0'} }`. Audit dicatat sebagai event `delete` (before/after). Relasi dari data lain tetap utuh; FK `ON DELETE RESTRICT` di DB menolak hard delete master yang direlasikan.

### GET /master/{entity}/options
`data: [ { "id": "3171011001", "nama": "Gambir", "parent": "317101" }, … ]` — hanya `status='1'`.

## G-TC → bukti otomatis

| G-TC | Test |
|---|---|
| #1 Keunikan nama/kode | `tests/MasterData/MasterGenericTcTest::testCreateSucceedsAndDuplicateCodeOrNameIsRejected`, `testSameNameIsAllowedUnderDifferentParent` |
| #2 Soft-delete only | `testDeleteIsAlwaysSoftAndReferencedMasterCannotBeHardDeleted` (termasuk FK RESTRICT) |
| #3 Toggle status → dropdown | `testStatusToggleIsReflectedInOptionsImmediately`, `testFourLevelCascadeOptions` |
| #4 Re-ordering | `testReorderShiftsOtherEntriesAndKeepsDropdownLogical`, `testMovingToAnotherParentAppendsAndRenumbersOldParent` |
| #5 RBAC | `tests/MasterData/RbacMasterEndpointsTest` (8 role × seluruh endpoint × seluruh master) |
| #6 Audit log | `testAuditLogIsRecordedForCreateUpdateDeleteWithActor`, `testLeadingZeroCodeIsPreservedInRouteAndAudit` |
| #7 QA Lapis 1 (Figma) | **Belum bisa** — desain Figma belum ada (item terbuka 00-INDEX) |
