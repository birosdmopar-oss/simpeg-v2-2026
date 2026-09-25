# Progress Fase 2 — Modul G: Master Data & Pengaturan

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `02-MasterData.md`.

**Terakhir diperbarui:** 25 September 2026 (G-10 FAQ pilot DBV-002/CR-003 disetujui & di-merge; perluasan engine CR-009 untuk DBV-003/004/005)
**Entry criteria:** Fase 1 sign-off dikonfirmasi user (21 Sep 2026).
**Blocker utama:** DDL legacy 13 tabel Tier 0/1 belum ada → Trello **ISSUE-003** (FAQ tidak lagi: DDL-nya ada di `simpeg_prod.sql:949-1030`). Keputusan skema → `backend/docs/db-review/G-01-master-schema.md` Bagian 4; FAQ → `backend/docs/db-review/G-10-faq-schema.md` Bagian 4.

| Task | Status | Ringkas |
|---|---|---|
| G-01 Migration tabel master | **IN_PROGRESS** | Batch 1 (7 tabel Tier 0) **disetujui DB Validator 23-09-2026**; revisi ke skema legacy **DBV-001 disetujui 24-09-2026 & sudah di main** (G-01 Bagian 8); 5 tabel FAQ diajukan di **DBV-002 ⏳** (G-10); sisa tabel lain menunggu DDL legacy |
| G-02 Jabatan, Unit & Satker | TODO (blocked) | Butuh DDL `jabatan` (5 FK di legacy), `kelas_jabatan`, `peta_jabatan` |
| G-03 Lokasi Presensi | TODO (blocked) | Kolom `lokasi_presensi` ada di seed; `user_lokasi_presensi` butuh `pegawai` (Fase 3) + `dm_user_lokasi_presensi` |
| G-04 Kenaikan Pangkat | TODO (blocked) | `gol_pppk` legacy memuat nominal uang makan — tidak ada di seed |
| G-05 Pendidikan | TODO | Kolom ada di seed; perlu keputusan #5 (`order`) → bisa langsung pakai engine |
| G-06 Diklat, Hukdis, Konket, Tanda Jasa | TODO | Kolom ada di seed; perlu keputusan #5 (`order`) → bisa langsung pakai engine |
| G-07 Data Umum & Wilayah | **IN_PROGRESS** | agama, jenis_pegawai, jenis_status, wilayah 4 level SELESAI (backend + FE + G-TC). `kantor` belum (blocked DDL) |
| G-08 Hari Libur | TODO | Kolom ada di seed/Tech Spec; butuh migration `jenis_libur` + validasi overlap (tidak cocok engine generik murni) |
| G-09 Web Config | TODO (blocked) | Konflik nama kolom `config_key` (seed) vs `config_name` (legacy); daftar key + tipe data belum ada |
| G-10 FAQ | **IN_PROGRESS** (kode di main, menunggu QA) | Pilot skema legacy **DBV-002/CR-003** (branch `dbv-002/g10-faq-pilot`): 5 tabel FAQ (DDL legacy ditemukan), CRUD admin (engine generik) + baca/cari/rating pegawai (backend + FE) selesai & test hijau. ⏳ Menunggu approval DB Validator (DBV-002) dan review kode (CR-003); FK `faq_rate.nip` → `pegawai` ditunda ke B-01 |

## G-01 — IN_PROGRESS

**Sudah jalan**
- `app/Database/Migrations/2026-09-22-000001_CreateWilayah.php` — provinsi → kabupaten_kota → kecamatan → kelurahan, FK RESTRICT.
- `app/Database/Migrations/2026-09-22-000002_CreateReferensiUmum.php` — agama, jenis_pegawai, jenis_status.
- Dokumen review DB Validator: `backend/docs/db-review/G-01-master-schema.md`.
- Dijalankan HANYA di DB lokal (`simpeg_v2`) & test (`simpeg_v2_testing`).
- **DBV-001** `app/Database/Migrations/2026-09-23-000000_AlterBatch1KeSkemaLegacy.php` — ALTER ke skema legacy (nama/tipe kolom legacy, status 1/2/10, kolom audit, `utf8mb4_unicode_ci`, UNIQUE nama, kode wilayah CHAR(2/4/7/10)). ✅ Disetujui DB Validator 24-09-2026 (PR #4, merge `633dbb0`); jalankan hanya saat ketujuh tabel kosong. Menyelesaikan ISSUE-008/009/010 untuk 7 tabel ini (sisa di luar tabel ini tetap dilacak di kartu masing-masing).
- **DBV-002** (✅ disetujui 25-09-2026, merge `f958cb5`) `app/Database/Migrations/2026-09-24-000001_CreateFaq.php` — 5 tabel FAQ skema legacy. Dokumen review terpisah: `backend/docs/db-review/G-10-faq-schema.md`. Belum dijalankan di `simpeg_v2` (dev); hanya di DB test.

**Belum**
- ~~Approval DB Validator~~ → Batch 1 **DISETUJUI** 23-09-2026 (Keputusan #1, #3, #5 terisi). Keputusan #2, #4, #9 ditunda sampai seluruh Fase 2 selesai → **dikerjakan lebih awal di DBV-001** atas keputusan user 23-09-2026 (✅ disetujui 24-09-2026, G-01 Bagian 8.5; #3 dibalik); #6, #7, #8 masih menunggu DDL legacy (bagian FAQ dari #6 `faq_related_article` & #7 `faq_rate.nip` diajukan di DBV-002 ⏳, G-10 Bagian 5).
- Wajib sebelum impor data master legacy: ~~panjang kode wilayah, UNIQUE index nama, collation~~ → dikerjakan di DBV-001 (✅ disetujui 24-09-2026); tersisa strict mode koneksi (A-01) + audit duplikat data legacy + pencocokan nilai dugaan dengan dump struktur produksi (G-01 Bagian 8.3).
- ~38 tabel Tier 0/1 sisanya — menunggu hasil `mysqldump --no-data simpeg01 …` (ISSUE-003). Tabel FAQ sudah keluar dari daftar ini (DDL ada, diajukan di DBV-002).
- Berhenti di: menunggu DDL. Langkah berikut: tulis migration per grup (G-02..G-10) mengikuti DDL legacy, tambah entri di `Config\MasterData`. G-10 sudah diajukan sebagai pilot (DBV-002); polanya dipakai untuk grup berikutnya setelah disetujui.

## G-07 — IN_PROGRESS

**Sudah jalan (DoD terpenuhi untuk 7 master ini)**
- CRUD agama, jenis pegawai, jenis status, provinsi, kabupaten/kota, kecamatan, kelurahan — `UmumController` (engine generik).
- Dropdown berjenjang wilayah 4 level: backend `master/{entity}/options?parent=` + FE `useCascadeOptions` (form & filter).
- G-TC #1–#6 lolos otomatis: `tests/MasterData/MasterGenericTcTest.php`, `RbacMasterEndpointsTest.php`; skema DBV-001: `Batch1LegacySchemaTest.php`.
- DBV-001: skema legacy (kolom `provinsi`, `agama`, …; `kd_area`, `kd_pos`, `status_pegawai`), status Aktif/Tidak Aktif/Dihapus + pulihkan, kode wilayah tepat 2/4/7/10 digit, kode agama/jenis_* otomatis.
- FE: `/master/:entity?` (menu "Master Data" hanya role 1), toggle switch + badge Aktif hijau/Tidak Aktif abu/Dihapus merah, naik/turun urutan, form tambah/edit, konfirmasi hapus (soft).
- Diverifikasi manual di browser (lokal): tambah, duplikat nama ditolak di field, toggle status, reorder, edit (induk berjenjang ter-isi), hapus soft, role 2 → 403 & menu tersembunyi, tampilan mobile.

**Belum**
- `kantor` (blocked DDL: legacy FK ke 4 tabel wilayah).
- G-TC #7 QA Lapis 1 vs Figma — desain belum ada.

## G-10 — IN_PROGRESS (pilot DBV-002 / CR-003 ✅, di main; menunggu QA)

Key review `[DBV-002][CR-003]`, branch `dbv-002/g10-faq-pilot`: DB Validator hanya approve, merge oleh reviewer CR. Dokumen skema & keputusan: `backend/docs/db-review/G-10-faq-schema.md`.

**Sudah jalan (belum di main)**
- Migration `2026-09-24-000001_CreateFaq.php` — `faq_topic`, `faq_sub_topic`, `faq_article`, `faq_rate`, `faq_related_article` persis DDL legacy `simpeg_prod.sql:949-1030`, dengan deviasi terlabel: FK RESTRICT (legacy CASCADE), `faq_article.order`, 3 UNIQUE nama, status 10, `faq_rate.nip` tanpa FK (menyusul B-01).
- Kelola konten (role 1) lewat engine generik: `master/faq-topic`, `master/faq-sub-topic`, `master/faq-article` (`FaqController`). Isi artikel HTML disanitasi server (HTMLPurifier) + `content_stripped` dihitung server (rumus legacy).
- Pegawai (semua role login): `GET api/v1/faq` (pohon topik → sub topik → artikel), `GET api/v1/faq?search=` (FULLTEXT legacy + fallback judul), `GET api/v1/faq/{id}` (detail, artikel terkait, status rating). Hanya entri yang seluruh rantainya aktif; tanpa cache (MTC-014).
- Rating `POST api/v1/faq/{id}/rate` untuk role 2/6/7 (UL_PEGAWAI): sekali per artikel, alasan wajib untuk "Kurang Membantu" (maks 255 byte), audit `faq_rate`.
- Tindak lanjut review CR-003 (backend): dropdown `master/faq-*/options` hanya role 1; `faq_topic.icon` tidak dikirim di respons admin (D6); tambah dengan `order` tanpa update baris baru & saudara yang bergeser tidak di-stamp (generik); id induk wajib kanonik; `search`/`reason` bukan UTF-8 valid → 422; `CreateFaq::up()` men-drop tabel run itu bila gagal di tengah; test skema FK kini mengecek kolom induk.
- FE: menu "FAQ" untuk semua role (`/faq/:id?`, `?q=`), navigasi topik, pencarian, detail artikel (HTML lewat DOMPurify), widget rating (4 alasan baku legacy + "Lainnya"); form admin master mendukung field `html` (textarea + pratinjau) dan batas byte.
- Test: `FaqSchemaTest`, `FaqTest`, `HtmlSanitizerTest`, G-TC generik (`MasterGenericTcTest`, `RbacMasterEndpointsTest`) mencakup 3 master FAQ; Vitest FAQ (service, schema, widget rating, view, sanitizeHtml). `composer check` & `npm run check` hijau (PHPUnit 239 test / 3365 assertion setelah tindak lanjut CR-003; Vitest 94 test) — rincian di G-10 Bagian 6.

**Keputusan** (user 24-09-2026: U1 sanitasi HTML, U2 rating role 2/6/7, U3 status anak tidak ditulis ulang, U4 FK RESTRICT; usulan D1–D8 disetujui DBV 25-09-2026) → G-10 Bagian 1 & 4.

**Belum**
- ~~Approval~~ ✅ DBV-002 disetujui 25-09-2026 (10 poin, G-10 Bagian 4) + CR-003 ✅; di-merge ke main (`f958cb5`). Sisa: QA fungsional/visual (QASMTASK-042).
- Verifikasi di MariaDB 10.4 (FULLTEXT & UNIQUE 1.024 byte) tidak dilaporkan saat approval — G-10 Bagian 3 #7.
- Uji visual/end-to-end halaman FAQ & form admin di browser dengan backend sungguhan (baru unit/komponen test).
- Ditunda ke PR lain: editor WYSIWYG + upload gambar, upload ikon topik, rekap rating untuk admin, UI `faq_related_article`, FK `faq_rate.nip` (B-01), tab "Pertanyaan Umum/Panduan Pengguna" & "Chat Admin" (Redesign), migrasi data FAQ (catatan Mapping di G-10 Bagian 6.3: `stripslashes`, sanitasi ulang, gambar, `order`, duplikat, `nip` = `id_pegawai` legacy).
- G-TC #7 QA Lapis 1 — frame Figma admin FAQ belum ada (QAUI-002 #042).

## Engine CRUD master generik (fondasi G-02..G-10)

Dipakai semua master berbentuk "kode + nama (+ induk) + order + status". Lihat `app/Controllers/Api/MasterData/README.md`.
Master dengan field tambahan (satker `offset_zona_menit`, lokasi presensi lat/long/radius ≥10 m, jenis_konket `affect_tukin`,
hari libur rentang tanggal non-overlap, web config bertipe) butuh perluasan engine (field ekstra per definisi) — dikerjakan
bersama task masing-masing setelah skema disetujui.

Perluasan engine di DBV-002/CR-003 (G-10, berlaku generik, regresi Batch 1 tetap hijau):
- Kolom audit `created_by`: tabel yang memilikinya diisi `created_by` saat insert (`updated_by` NULL, seperti legacy), `updated_by` saat update. Tabel Batch 1 tidak berubah.
- Hook tulis per master (`hooks` → `App\Libraries\MasterData\MasterHooks`) untuk sanitasi & kolom turunan, dalam transaksi yang sama.
- Tipe field `html` (maks 1.000.000 byte) dan opsi `maxBytes` (rule `max_byte_length[N]`, mis. TINYTEXT 255 byte); meta field mengirim `max_bytes`.
- `listExclude`: kolom besar tidak dikirim di daftar admin (detail tetap mengirimnya).
- Tambah / pindah induk memeriksa **seluruh** rantai leluhur (ada & aktif), termasuk wilayah. Mengubah entri tanpa pindah induk tetap boleh walau leluhurnya non-aktif. Id induk dari input wajib bentuk kanonik (induk AUTO_INCREMENT: `1`, bukan `01`/`1abc`) → selain itu 422 "`<Induk>` tidak ditemukan." (CR-003).
- `publicOptions` (default `true`): `false` = dropdown `{key}/options` hanya role 1 (master FAQ, CR-003 — options tidak menyaring rantai status). Query options hanya membaca kolom kode/nama/induk.
- `hiddenColumns`: kolom tabel yang tidak dikelola engine dan dibuang dari seluruh respons admin (mis. `faq_topic.icon`, D6).
- Urutan (CR-003): tambah dengan `order` langsung meng-insert di posisi final (baris baru tidak di-update lagi → `updated_by` tetap NULL untuk tabel ber-`created_by`). Saudara yang hanya bergeser (reorder/sisip/hapus/pindah induk entri lain; pulihkan menaruh entri di akhir tanpa menggeser saudara) tidak di-stamp `updated_at`/`updated_by` (`MasterModel::shiftOrder`, `updated_at = updated_at`), tetapi tetap teraudit. Berlaku juga untuk Batch 1.

Perluasan engine di CR-009 (fondasi DBV-003/004/005, berlaku generik, tanpa master/migration grup baru; regresi Batch 1 & FAQ tetap hijau). Rincian opsi: `app/Controllers/Api/MasterData/README.md`.
- Mode urutan `orderMode: 'manual'` (level pangkat: nilai tidak digeser/dinomori ulang), `orderScope` (urutan per field lain, mis. diklat per jenis), `orderColumnType` (batas nilai urutan manual & MAX+1).
- `uniqueFields` (UNIQUE selain nama → 422 pada field itu, termasuk balapan 1062), batas angka field int per tipe kolom (`columnType`, `min`/`max`; meta `min`/`max`), field `boolean` 1/0, field `ref` + `dependsOn` (dropdown berjenjang ke master lain, cek kanonik/ada/aktif/rantai).
- `filters` (allowlist filter `?field=` di options & daftar admin, cache per filter), `statusChain` (options mengikuti rantai status induk, pola U3 FAQ; `whereActiveChain()` untuk service khusus).
- `MasterRegistry` memvalidasi konfigurasi (rujukan antar-master, field opsi, rantai melingkar) → `LogicException`.
- FE: `MasterFormDialog` (ref berjenjang + nilai non-aktif tetap tampil, checkbox boolean, batas angka, urutan manual), `MasterDataView` (filter field, panah urutan hanya mode shift & satu lingkup utuh, keterangan hapus untuk turunan ber-`status_chain`), `FormField` tipe `checkbox` + `allowEmpty`.
- Scaffolding anti-konflik: blok `// --- DBV-00X ---` di `Config\MasterData`, `masterFixtures()`, `MasterDataSeeder`; daftar master admin-only di `RbacMasterEndpointsTest` dibaca dari config.
- Belum: kunci baris ID "sakti" (E7), hook saat ubah status/pulihkan, kolom tambahan di options (E3) — menunggu keputusan grup masing-masing.

## Perubahan di luar folder Modul G (bug yang ditemukan — scope diperluas sesuai 00-INDEX)

- `app/Models/BaseAuditableModel.php` — `rowKey()` meng-cast semua PK numerik ke int → kode `'01'` tercatat `1` di `audit_logs`. Diperbaiki: hanya integer kanonik yang di-cast.
- `app/Controllers/Api/ApiController.php` — `_remap()` meng-cast parameter route digit ke int → kode `'01'` jadi `1`. Ditambah flag `$castNumericParams` (default tetap `true`; dimatikan di controller master).
- DBV-002/CR-003 (G-10): dependency baru `ezyang/htmlpurifier` (composer, `app/Libraries/Html/*`) dan `dompurify` (npm, `frontend/src/shared/utils/sanitizeHtml.ts`, `SafeHtml.vue`); rule validasi `app/Validation/ByteRules.php` (`max_byte_length`) didaftarkan di `Config\Validation` + pesan cadangan di `Language/en/Validation.php`; service `faqService` & `htmlSanitizer` di `Config\Services`; FE `router/index.ts` (route `/faq/:id?`) dan menu "FAQ" di `AppShell.vue`.
- Temuan di luar cakupan (belum diubah): pencarian daftar admin generik (`MasterService::list`) memakai `like()` Query Builder CI4 yang tidak meng-escape `%`/`_` di nilai — kata kunci berisi wildcard ikut bertindak sebagai wildcard (pencarian FAQ pegawai sudah meng-escape sendiri).
