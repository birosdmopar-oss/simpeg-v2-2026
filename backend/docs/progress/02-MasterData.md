# Progress Fase 2 — Modul G: Master Data & Pengaturan

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `02-MasterData.md`.

**Terakhir diperbarui:** 23 September 2026
**Entry criteria:** Fase 1 sign-off dikonfirmasi user (21 Sep 2026).
**Acuan skema (keputusan Horii 22-09-2026):** DDL legacy `simpeg01` sebagai sumber utama, `status` dinormalkan ke '1'/'0'
sesuai DoD, tabel tanpa DDL tetap BLOCKED. Detail & 12 keputusan terbuka: `backend/docs/db-review/G-01-master-schema.md`.
**Blocker utama:** DDL legacy 9 tabel Tier 0/1 → Trello **ISSUE-003**.

| Task | Status | Ringkas |
|---|---|---|
| G-01 Migration tabel master | **IN_PROGRESS** | 33 tabel dibuat & jalan dari nol; 9 tabel blocked (ISSUE-003); menunggu approval DB Validator |
| G-02 Jabatan, Unit & Satker | **IN_PROGRESS** | CRUD jabatan/unit/satker/group/sub-group + zonasi satker DONE; kelas jabatan & peta formasi blocked |
| G-03 Lokasi Presensi | **DONE\*** | CRUD lokasi (GPS + radius ≥10 m) & pemetaan pegawai↔lokasi; FK ke `pegawai` di-defer ke Fase 3 |
| G-04 Kenaikan Pangkat | **DONE\*** | CRUD pangkat, jenis KP, golongan PPPK; kolom nominal uang makan `gol_pppk` menunggu DDL (review #11) |
| G-05 Pendidikan | **DONE\*** | CRUD jenjang/bidang/jurusan + dropdown berjenjang bidang → jurusan |
| G-06 Diklat, Hukdis, Konket, Tanda Jasa | **DONE\*** | 4 controller terpisah; `jenis_konket.affect_tukin` tersedia untuk kalkulasi Fase 5 |
| G-07 Data Umum & Wilayah | **DONE\*** | agama, jenis pegawai, jenis status, kantor + wilayah 4 level (dropdown berjenjang) |
| G-08 Hari Libur | **DONE\*** | CRUD + validasi `tgl_mulai<=tgl_akhir` & larangan overlap (unit test), endpoint kalender UL_ALL |
| G-09 Web Config | **DONE\*** | CRUD key-value bertipe (6 tipe), nilai ter-cast, cache invalidate; regression kalkulasi menyusul Fase 5 sesuai DoD |
| G-10 FAQ | **IN_PROGRESS** | CRUD topic→sub-topic→article + view UL_ALL DONE; **rating `faq_rate` blocked** (DDL + tabel `pegawai` Fase 3) |

**DONE\*** = seluruh DoD & G-TC otomatis terpenuhi, tetapi belum bisa ditutup sebagai DONE final karena dua gate yang
masih terbuka untuk SEMUA task: (1) approval DB Validator atas migration, (2) QA Lapis 1 visual vs Figma (desain belum ada).

## Bukti G-TC (dijalankan ke seluruh master G-02 s.d. G-10)

`backend/tests/MasterData/` — 61 test, 5231 assertion:

| Berkas | Cakupan |
|---|---|
| `MasterGenericTcTest` | G-TC #1–#4, #6 untuk 30 master terdaftar + radius G-03 + field tambahan + meta |
| `RbacMasterEndpointsTest` | G-TC #5: 8 role × 7 endpoint × 30 master; options UL_ALL |
| `HariLiburTest` | G-08: urutan tanggal, 7 kasus overlap, 3 kasus berbatasan, kalender, RBAC, audit |
| `WebConfigTest` | G-09: 8 nilai invalid & 6 valid per tipe, ubah tipe, cache, RBAC, audit |
| `FaqTest` | G-10: view UL_ALL, konten non-aktif tersembunyi, pencarian, CRUD role 1 |

`MasterGenericTcTest::testEveryRegisteredMasterHasFixture` menjaga agar master baru tidak bisa ditambahkan tanpa
fixture G-TC.

Frontend: `frontend/src/features/master-data/__tests__/` (schema generik, field tambahan, hari libur, web config, cascade).
`bash check.sh` lolos penuh (backend 224 test, frontend 67 test + build).

## Yang belum (blocked) — semua menunggu Trello ISSUE-003

| Tabel | Dibutuhkan untuk |
|---|---|
| `kelas_jabatan`, `peta_jabatan` | G-02 (CRUD kelas jabatan & peta formasi; FK `jabatan.kelas_jabatan`) |
| `faq_rate` | G-10 (rating artikel; juga butuh `pegawai` Fase 3) |
| `rumpun_jabatan`, `subrumpun_jabatan`, `jabatan_akademik`, `dm_ak_jf`, `jabatan_koordinasi` | G-01 (migrasi Tier 1) |
| `bidang_kursem`, `instansi_kursem` | G-01 (migrasi Tier 0; dipakai riwayat seminar Fase 3) |
| `jenjang_jf`, `faq_related_article`, `dm_user_lokasi_presensi` | ada di legacy, di luar daftar Tier 0/1 — perlu keputusan |

## Catatan lintas modul

- FK `pengguna.id_unit`/`id_satker` (ditunda dari A-01) **belum bisa dipasang**: `pengguna` memakai VARCHAR(10)
  sedangkan PK `unit`/`satker` legacy INT AUTO_INCREMENT → keputusan #5 di dokumen review.
- `web_config` sudah bisa dibaca modul lain lewat `service('webConfigService')->value(key)` (nilai ter-cast) —
  dipakai kalkulasi tukin & uang makan Fase 5.
- Hari libur aktif dibaca lewat `service('hariLiburService')->between($from, $to)` untuk perhitungan hari kerja Fase 5.
- Endpoint `options` (dropdown) dibuka untuk semua role yang login; endpoint ini belum ada di Matriks Role x Endpoint →
  perlu konfirmasi & pembaruan matriks.

## Perubahan di luar folder Modul G (bug yang ditemukan — scope diperluas sesuai 00-INDEX)

- `app/Models/BaseAuditableModel.php` — `rowKey()` meng-cast semua PK numerik ke int → kode `'01'` tercatat `1` di `audit_logs`. Diperbaiki: hanya integer kanonik yang di-cast.
- `app/Controllers/Api/ApiController.php` — `_remap()` meng-cast parameter route digit ke int → kode `'01'` jadi `1`. Ditambah flag `$castNumericParams` (default tetap `true`; dimatikan di controller master).
- `frontend/src/shared/components/FormField.vue` — dukungan tipe `textarea` & `date` (dipakai form master, hari libur, web config).
