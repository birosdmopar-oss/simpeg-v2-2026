# Modul G — Master Data & Pengaturan (Fase 2)

Controller REST API modul ini (`App\Controllers\Api\MasterData\*`), semua extends `App\Controllers\Api\ApiController`.
Envelope: sukses `{status:'success', data}`, gagal `{status:'error', message, errors?}` (ADR-001).
Role akses mengacu Matriks Role x Endpoint Bagian 2 Modul G. Prefix seluruh path: `/api/v1`.

## Engine CRUD master generik

Seluruh master "kode + nama (+ induk) + order + status" memakai satu engine. Menambah master = migration +
1 entri di `Config\MasterData` + daftarkan key-nya di controller grup.

| Komponen | Peran |
|---|---|
| `Config\MasterData` | Registry master (tabel, PK, kolom nama, induk, field tambahan, controller grup). Satu sumber kebenaran untuk routing, service, dan metadata form FE |
| `Libraries\MasterData\MasterField` | Definisi kolom tambahan per master (tipe, wajib/tidak, rules, opsi) + pesan validasi Indonesia |
| `Libraries\MasterData\MasterService` | Aturan G-TC: keunikan, soft delete, reorder, cache dropdown; transaksi |
| `Models\MasterData\MasterModel` | Model generik turunan `BaseAuditableModel` → audit otomatis |
| `Controllers\Api\MasterData\BaseMasterController` | Endpoint generik + validasi bentuk |

Master dengan aturan khusus punya service sendiri: `HariLiburService` (G-08) dan `WebConfigService` (G-09).

Kode master (PK) bisa **string yang diinput admin** (wilayah, agama, FAQ, …) atau **AUTO_INCREMENT** sesuai DDL legacy
(jabatan, unit, satker, pangkat, …). Kode string tidak pernah di-cast ke int (`'01'` tetap `'01'`) dan **tidak bisa diubah**
setelah dibuat.

## Endpoint generik

`{entity}` = key master (tabel di bawah). `{kode}` = PK entri.

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/master/meta` | 1 | Daftar master + metadata form/tabel (dipakai halaman Master Data FE) |
| GET | `/master/{entity}` | 1 | Daftar, urut induk → `order` → nama. Query: `search`, `status`, `parent`, `page`, `per_page` (≤100) |
| GET | `/master/{entity}/options` | **UL_ALL** | Dropdown untuk modul lain: **hanya entri aktif**, urut `order`. Query: `parent`. Di-cache, invalidasi di setiap penulisan |
| POST | `/master/{entity}` | 1 | Tambah → 201 |
| GET | `/master/{entity}/{kode}` | 1 | Detail (+ `parent_nama` untuk master berinduk) |
| PUT | `/master/{entity}/{kode}` | 1 | Ubah parsial. Kode tidak ikut diubah |
| PATCH | `/master/{entity}/{kode}/status` | 1 | Toggle aktif/non-aktif `{ "status": "0"\|"1" }` |
| PATCH | `/master/{entity}/{kode}/order` | 1 | Pindah posisi `{ "order": n }` (1-based, per induk); entri lain bergeser. 422 untuk master tanpa kolom `order` |
| DELETE | `/master/{entity}/{kode}` | 1 | **Soft delete** → `status='0'`. Tidak pernah hard delete |

Role selain 1 → `403 {status:'error', message:'Forbidden'}`; tanpa token → 401.

### Master yang tersedia

| Task | Controller | `{entity}` | Tabel | Kode | Induk | Field tambahan |
|---|---|---|---|---|---|---|
| G-02 | `JabatanController` | `group-jabatan` | group_jabatan | auto | — | kode, singkatan |
| G-02 | `JabatanController` | `sub-group-jabatan` | sub_group_jabatan | auto | group-jabatan | kode, singkatan |
| G-02 | `JabatanController` | `unit` | unit | auto | — | is_upt, alamat PDF, tembusan/lokasi KPPN |
| G-02 | `JabatanController` | `satker` | satker | auto | unit | **zonasi** (menit dari WIB), is_upt, alamat PDF, KPPN, logo |
| G-02 | `JabatanController` | `jabatan` | jabatan | auto | sub-group-jabatan | group, satker, kelas jabatan, jenjang JF, umur pensiun |
| G-03 | `LokasiController` | `lokasi-presensi` | lokasi_presensi | string(5) | — | latitude, longitude, **radius_meter (≥10)** |
| G-03 | `LokasiController` | `pegawai-lokasi-presensi` | user_lokasi_presensi | auto | lokasi-presensi | — (nama = NIP; tanpa `order`) |
| G-04 | `KpController` | `pangkat` | pangkat | auto | — | gol_ruang, gol, ruang, cpns |
| G-04 | `KpController` | `jenis-kp` | jenis_kp | auto | — | — |
| G-04 | `KpController` | `gol-pppk` | gol_pppk | string(5) | — | — |
| G-05 | `PendidikanController` | `jenjang-pendidikan` | jenjang_pendidikan | auto | — | singkatan, row_jurusan |
| G-05 | `PendidikanController` | `bidang-pendidikan` | bidang_pendidikan | auto | — | nama Inggris |
| G-05 | `PendidikanController` | `jurusan-pendidikan` | jurusan_pendidikan | auto | bidang-pendidikan | nama Inggris, gelar, flag D_I..S_3 |
| G-06 | `DiklatController` | `diklat` | diklat | auto | — | jenis diklat |
| G-06 | `HukdisController` | `tingkat-hukdis` | tingkat_hukdis | auto | — | — |
| G-06 | `HukdisController` | `jenis-hukdis` | jenis_hukdis | auto | tingkat-hukdis | — |
| G-06 | `KonketController` | `jenis-konket` | jenis_konket | string(5) | — | **affect_tukin** |
| G-06 | `TandaJasaController` | `tanda-jasa` | tanda_jasa | auto | — | — |
| G-07 | `UmumController` | `agama`, `jenis-pegawai`, `jenis-status` | idem | string(5) | — | — |
| G-07 | `UmumController` | `kantor` | kantor | string(5) | — | alamat + kode wilayah 4 level |
| G-07 | `UmumController` | `provinsi` | provinsi | char(2) | — | — |
| G-07 | `UmumController` | `kabupaten-kota` | kabupaten_kota | char(4) | provinsi | kd_area |
| G-07 | `UmumController` | `kecamatan` | kecamatan | char(7) | kabupaten-kota | — |
| G-07 | `UmumController` | `kelurahan` | kelurahan | char(10) | kecamatan | kd_pos |
| G-08 | `HariLiburController` | `jenis-libur` | jenis_libur | string(5) | — | — |
| G-10 | `FaqController` | `faq-topic` | faq_topic | string(5) | — | — |
| G-10 | `FaqController` | `faq-sub-topic` | faq_sub_topic | string(5) | faq-topic | — |
| G-10 | `FaqController` | `faq-article` | faq_article | string(5) | faq-sub-topic | isi (artikel) |

Belum tersedia (DDL legacy belum ada — Trello ISSUE-003): kelas jabatan, peta formasi, rumpun/subrumpun jabatan,
jabatan koordinasi, jabatan akademik, dm_ak_jf, rating FAQ (`faq_rate`), kursem.

**Dropdown berjenjang** (`options?parent=`): wilayah 4 level (provinsi → kabupaten-kota → kecamatan → kelurahan),
group → sub group jabatan, unit → satker, bidang → jurusan pendidikan, tingkat → jenis hukdis, topik → sub topik FAQ.

## Endpoint khusus

### G-08 Hari Libur (`HariLiburController`)

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/master/hari-libur` | 1 | Query: `search`, `status`, `id_jenis_libur`, `tahun`, `page`, `per_page` |
| GET | `/master/hari-libur/calendar?from=&to=` | **UL_ALL** | Hari libur **aktif** dalam rentang (default: tahun berjalan). Dipakai kalkulasi hari kerja Fase 5 |
| POST | `/master/hari-libur` | 1 | `{ id_jenis_libur, nama, tgl_mulai, tgl_akhir, keterangan?, status? }` → 201 |
| GET/PUT | `/master/hari-libur/{id}` | 1 | |
| PATCH | `/master/hari-libur/{id}/status` | 1 | Mengaktifkan kembali dicek ulang terhadap overlap |
| DELETE | `/master/hari-libur/{id}` | 1 | Soft delete → status '0' |

Validasi (DoD G-08): `tgl_mulai <= tgl_akhir` (422 di `tgl_akhir`) dan rentang **tidak boleh overlap** dengan hari libur
**aktif** lain (422 di `tgl_mulai`, pesannya menyebut hari libur yang bentrok). Hari libur non-aktif diabaikan saat cek
overlap sehingga tanggalnya bisa dipakai ulang.

### G-09 Web Config (`WebConfigController`)

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/master/web-config` | 1 | Query: `search`, `tipe_data` |
| GET | `/master/web-config/{config_name}` | 1 | |
| POST | `/master/web-config` | 1 | `{ config_name, config_value, tipe_data?, keterangan? }` → 201 |
| PUT | `/master/web-config/{config_name}` | 1 | `{ config_value?, tipe_data?, keterangan? }` — key tidak bisa diubah |
| DELETE | `/master/web-config/{config_name}` | 1 | **Hard delete** (web_config tidak punya kolom status) |

`tipe_data`: `string`, `text`, `integer`, `decimal`, `time`, `boolean`. Nilai divalidasi sesuai tipe (MTC-012) dan
dikembalikan sudah ter-cast di `value_casted`. Konsumen di kode memakai
`service('webConfigService')->value('tarif_uang_makan_pns')` (hasil sudah int/float/bool/string, di-cache & di-invalidate
setiap penulisan).

### G-10 FAQ view pegawai (`FaqController`)

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/faq?search=` | **UL_ALL** | Topik → sub topik → artikel (judul) yang **published** (status '1' di ketiga level) |
| GET | `/faq/{id_article}` | **UL_ALL** | Artikel lengkap; 404 kalau artikel/sub topik/topiknya non-aktif |

Rating artikel (`faq_rate`) **belum** tersedia — lihat `backend/docs/progress/02-MasterData.md`.

## Payload & response (engine generik)

### POST /master/{entity}

| Field | Aturan |
|---|---|
| PK (`id_*`) | hanya untuk master ber-kode manual: wajib, ≤ panjang PK, `^[A-Za-z0-9._-]+$`, unik; `options`/`meta` dicadangkan |
| induk | wajib, harus ada **dan aktif** |
| nama | wajib, ≤ panjang kolom; spasi dirapikan; **unik per induk, case-insensitive, termasuk entri non-aktif** |
| field tambahan | sesuai tipe di `Config\MasterData` (angka, desimal, select, textarea, tanggal) |
| `order` | opsional, bilangan ≥1 = posisi sisip; kosong = paling akhir |
| `status` | opsional `'0'`/`'1'` (default `'1'`) |

Gagal → 422 `errors: { <field>: ["pesan Indonesia"] }`.

### DELETE /master/{entity}/{kode}
`data: { deleted: true, soft_delete: true, item: {…, status:'0'} }`. Audit event `delete` (before/after).
FK `ON DELETE RESTRICT` menolak hard delete master yang direlasikan.

## G-TC → bukti otomatis

| G-TC | Test |
|---|---|
| #1 Keunikan nama/kode | `MasterGenericTcTest::testCreateSucceedsAndDuplicateCodeOrNameIsRejected`, `testSameNameIsAllowedUnderDifferentParent`, `testParentMustExistAndBeActiveAndCodeIsImmutable` |
| #2 Soft-delete only | `testDeleteIsAlwaysSoftAndReferencedMasterCannotBeHardDeleted` (termasuk FK RESTRICT), `HariLiburTest::testSoftDeleteAndAuditAndListFilters` |
| #3 Toggle status → dropdown | `testStatusToggleIsReflectedInOptionsImmediately`, `testCascadeOptionsAcrossModules` |
| #4 Re-ordering | `testReorderShiftsOtherEntriesAndKeepsDropdownLogical`, `testMovingToAnotherParentAppendsAndRenumbersOldParent`, `testMasterWithoutOrderRejectsReorder` |
| #5 RBAC | `RbacMasterEndpointsTest` (8 role × seluruh endpoint × seluruh master), `HariLiburTest::testCrudIsSuperAdminOnly`, `WebConfigTest`, `FaqTest::testFaqContentManagementIsSuperAdminOnly` |
| #6 Audit log | `testAuditLogIsRecordedForCreateUpdateDeleteWithActor`, `testLeadingZeroCodeIsPreservedInRouteAndAudit`, `WebConfigTest::testAuditIsRecordedAndEndpointsAreSuperAdminOnly` |
| #7 QA Lapis 1 (Figma) | **Belum bisa** — desain Figma belum ada (item terbuka 00-INDEX) |
| G-03 radius ≥10 m | `testLokasiPresensiRadiusMinimumIsEnforced` |
| G-08 tanggal & overlap | `HariLiburTest` (7 kasus overlap + 3 kasus berbatasan + urutan tanggal) |
| G-09 tipe data per key | `WebConfigTest` (8 nilai invalid + 6 valid + ubah tipe) |
| G-10 view publik & published-only | `FaqTest` |
