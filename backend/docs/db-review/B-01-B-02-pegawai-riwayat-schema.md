# DB Validator Review — B-01/B-02 Pegawai, snapshot, riwayat & lampiran (skema legacy)

> **DRAF (30-09-2026).** Satu dokumen gabungan untuk dua key review: bagian B-01 (DBV-012) dan B-02 (DBV-013) dipisah
> per bagian (2.1 = B-01; 2.2–2.4 = B-02). Bila PR dipecah per key, dokumen ini ikut PR DBV-012 dan diperbarui di PR
> DBV-013, atau dipecah menjadi dua dokumen sesuai keputusan user/DB Validator (1.4 #7).

**Key review:** `DBV-012` (B-01, Trello QASMTASK-043) dan `DBV-013` (B-02, QASMTASK-044). Belum ada PR. Draf dikerjakan di
branch lokal `dbv-012/b01-b02-pegawai-riwayat-draf` (dari `origin/main` `89ea98d`, belum di-push) dalam dua commit:
(1) DBV-012 — migration `120000`/`120100`, test B-01, penyesuaian test master, dokumen ini; (2) DBV-013 — migration
`130000`..`131000` dan test B-02. Migration yang **ditahan** (FK masuk `pengguna`/`faq_rate`, FK ke tabel G-02) tidak ikut
kedua commit itu; drafnya disimpan di branch lokal `dbv-012/fk-ditahan` (Bagian 7). Bentuk PR dan alur merge ditetapkan
saat PR dibuka, mengikuti `AGENTS.md` bagian 2: PR hanya `[DBV]` → DB Validator yang merge; PR `[CR]` + `[DBV]` → DB
Validator review/approve, reviewer CR (user) yang merge. Karena PR ini juga mengubah test yang sudah ada di `main`
(Bagian 6.2), usulan alurnya `[CR]` + `[DBV]` (keputusan 4.1 #13). Urutan merge yang direncanakan: DBV-008 (G-02) ↔
DBV-012 → DBV-013 → migration FK yang ditahan (Bagian 7).

**Status:** ⏳ **MENUNGGU REVIEW DB VALIDATOR.** Pemeriksaan yang dicatat di dokumen ini adalah **pra-review internal sisi
CR** (penulis per kelompok + integrator); belum ada butir yang direview atau disetujui DB Validator. Migration B-01/B-02
**JANGAN** dijalankan di Dev/Production sebelum disetujui DBV-012/DBV-013.

**Rujukan:** `03-Kepegawaian.md` B-01 (:13-22), B-02 (:24-32), B-03/B-04 (DoD `pegawai_hist`), B-18 (:189-196, lampiran);
`04-Layanan.md:16` (C-01, pemilik tabel cuti/TB/IB/PMK); `Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 2 & Tier 3;
`SIMPEG_Technical_Specification_00-08.docx`; ADR-006 / `app/Models/BaseSnapshotModel.php` (F0-05); DDL produksi
`simpeg_prod.sql` (HeidiSQL, MySQL 8.0.21); ERD `simpeg01.erd`; kode legacy CI3 (`application/libraries/hr/**`,
`controllers/hr/**`, `views/hr/**`, `config/constants.php`, `helpers/function_helper.php`); dokumen DBV di `main`:
`A-01-auth-schema.md` (#4, D-7, D-8, D-12), `G-10-faq-schema.md` (D2), `G-06-diklat-hukdis-konket-tanda-jasa-schema.md`
(B8, 1.4 #3), `G-07-G-08-kantor-hari-libur-kursem-schema.md` (sentinel wilayah).

**Label sumber** (dipakai di dokumen ini dan di komentar migration):

| Label | Arti |
|---|---|
| **[K]** | DDL produksi `simpeg_prod.sql`. Dump hanya mencakup `aa_lkh`..`jabatan` (urut alfabetis) ditambah trigger `absen_ijin_beDel` (:1476-1482) |
| **[K-m]** | DDL produksi tabel arsip hapus `d_*`/`da_deleted`. Trigger `INSERT INTO d_konket SELECT * FROM absen_ijin` (:1479-1481, satu-satunya trigger di dump) mensyaratkan **jumlah & urutan** kolom sama dengan tabel sumber; untuk tabel `d_*` lain trigger serupa diasumsikan (berada di luar rentang dump). Contoh: `d_lkh` :673-701 = `riwayat_lckh`, `d_konket` :639-668 = `absen_ijin`, `da_deleted` :350-368 = `document_attachment`, `d_riwayat_cuti` :706-763 = `riwayat_cuti`. Tipe **tidak** dijamin identik (mis. `da_deleted.tag` VARCHAR(200) vs `document_attachment.tag` VARCHAR(255)), jadi [K-m] kuat untuk daftar kolom, lemah untuk panjang tipe |
| **[K-erd]** | Nama FK dari `simpeg01.erd`. ERD tidak mencatat kolom maupun aksi FK |
| **[L]** | `SHOW CREATE TABLE` di DB lokal `simpeg_prod_duplikat` (provenance belum pasti). Lengkap untuk `pegawai`, `pegawai_kp`, `pegawai_mutasi_jabatan`; `riwayat_*` di sana hanya stub 5-7 kolom |
| **[I]** | Kode CI3 `1_legacy_simpeg.kemenparekraf.go.id/application` (`berkas:baris`). `simpegdev_local` sintetis (DEFAULT berisi data demo), jadi **jangan** dipakai untuk tipe/default |
| **[E]** | Aplikasi `html/etalenta` (Laravel, membaca `simpeg01`). Hanya bukti lemah keberadaan kolom |
| **[V2]** | Keputusan v2 (deviasi wajib dicatat di Bagian 3) |

**Berkas:**

| File | Isi | Key | Status |
|---|---|---|---|
| `app/Database/Migrations/2026-09-30-120000_CreatePegawai.php` | `pegawai`, `pegawai_hist`, `pegawai_foto` | DBV-012 | ✍️ draf, lolos uji (6.2) |
| `…/2026-09-30-120100_CreatePegawaiSnapshot.php` | 13 snapshot `pegawai_*` | DBV-012 | ✍️ draf, lolos uji |
| `…/2026-09-30-130000_CreateJenisRwy.php` | lookup `jenis_rwy` + seed 21 baris | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130100_CreateRiwayatJabatan.php` | `riwayat_mutasi_jabatan`, `pegawai_plt`, `pegawai_plh` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130200_CreateRiwayatKpKgb.php` | `riwayat_kp`, `riwayat_kgb` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130300_CreateRiwayatPendidikanDiklatSeminar.php` | `riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130400_CreateRiwayatSkpLkh.php` | `bkn_periode_ekinper`, `riwayat_skp`, `riwayat_skp_periodik`, `riwayat_lckh` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130500_CreateAbsenIjin.php` | `absen_ijin` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130600_CreateRiwayatHukdisAk.php` | `riwayat_hukdis`, `riwayat_ak`, `riwayat_ak_siasn`, `konv_ak`, `ignore_konv_ak` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130700_CreateRiwayatKeluargaAlamat.php` | `riwayat_keluarga`, `detail_anak`, `riwayat_alamat` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130800_CreateRiwayatKartuTjOrganisasi.php` | `riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_tanda_jasa`, `riwayat_organisasi` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-130900_CreateDocumentAttachment.php` | `document_attachment` | DBV-013 | ✍️ draf, lolos uji |
| `…/2026-09-30-131000_AddFkSnapshotKeRiwayat.php` | 13 FK snapshot → riwayat + pra-cek orphan | DBV-013 | ✍️ draf, lolos uji |
| `tests/database/Kepegawaian/PegawaiSnapshotSchemaTest.php`, `NipReferenceRegistryTest.php` | schema test B-01 (16 tabel) + registry kolom NIP | DBV-012 | ✅ lolos lokal |
| `tests/database/Kepegawaian/SnapshotRiwayatFkTest.php`, `RiwayatJabatanKpPendidikanSchemaTest.php`, `RiwayatSkpLkhKonketHukdisAkSchemaTest.php`, `RiwayatKeluargaKartuLampiranSchemaTest.php` | test `131000` + schema test B-02 per kelompok (27 tabel) | DBV-013 | ✅ lolos lokal |
| `tests/_support/SkemaKepegawaianTestTrait.php` | helper schema test B-02 (portabel MySQL 8 / MariaDB 10.4) | DBV-013 | ✅ |
| `tests/_support/LepasMigrationKepegawaianTrait.php` + `tests/MasterData/{Batch1Legacy,DiklatHukdisKonket,PangkatPendidikan}SchemaTest.php` | test master yang memanggil `down()` melepas migration B-01/B-02 dulu (6.2) | DBV-012 | ✅ lolos lokal |
| `AddFkPegawaiDiPenggunaFaqRate` (`120200`), `AddFkG02Pegawai` (`129900`), `AddFkG02Riwayat` (`139900`) | **ditahan** — tidak di folder `Migrations`, tidak ikut PR B-01/B-02 (Bagian 7) | DBV-012/013 | ⏸️ ditahan |

## 1. Latar belakang & keputusan

### 1.1 Keputusan final yang berlaku (tidak dibuka ulang)

| # | Isi | Dampak ke B-01/B-02 |
|---|---|---|
| K1 | PK `pegawai` = `nip` VARCHAR(30) `utf8mb4_unicode_ci`. Ganti NIP (B-06) = satu transaksi: salin baris → arahkan ulang tabel anak → hapus baris lama | Semua kolom NIP VARCHAR(30) unicode_ci; semua FK ke `pegawai` RESTRICT (2.0.1, 2.0.5); registry kolom NIP (2.0.8) |
| K2 | Akun non-pegawai `pengguna.nip` NULL (DBV-010, di `main`) | FK `pengguna.nip` → `pegawai.nip` nullable, **ditahan** (2.1.6, Bagian 7) |
| A-01 #4 | FK `pengguna.nip` → `pegawai.nip` di-defer ke B-01 | Migration `120200`, **ditahan** (4.1 #12) |
| G-10 D2 | FK `fk_nip_faqrate_to_peg` ditambahkan di B-01; KEY dengan nama itu sudah ada di `main` | Migration `120200` (cukup `ADD CONSTRAINT`), **ditahan** (4.1 #12) |
| G2 | (DBV-001/002) collation `utf8mb4_unicode_ci` per tabel, FK `ON DELETE RESTRICT ON UPDATE RESTRICT` dengan nama legacy, AUTO_INCREMENT awal tidak ditulis, kolom audit diisi aplikasi (UTC, `id_pengguna`) | Seluruh tabel |
| B8 (G-06) | Tipe PK ikut legacy; impor memakai ID legacy apa adanya; tiap PR DBV meminta verifikasi MariaDB 10.4 eksplisit | Tipe kolom FK anak = tipe PK master di `main` persis (2.0.2); 6.3 |
| G-06 1.4 #3 | Tipe kolom FK `riwayat_*` di B-11/B-14/B-17 mengikuti PK master G-06 (`diklat` TINYINT, empat lainnya INT) | 2.0.2 |
| ADR-006 | `BaseSnapshotModel::syncToActiveSnapshot()` (di `main`): upsert satu baris per `nip` di tabel snapshot, dipanggil hanya di approval final | Snapshot ber-PK `nip`, tanpa kolom workflow (2.0.4) |
| DBV-010 / A-01 D-12 | `pengguna.id_pengguna` INT UNSIGNED, sedangkan `*_by` legacy INT signed; penyelarasan di DBV-009 | Semua `*_by` tanpa FK (2.0.4) |

### 1.2 Temuan riset yang mengoreksi dokumen v2

1. **Snapshot `pegawai_*` tidak pernah ditulis PHP legacy.** Tidak ada titik insert/update ke `pegawai_kp/kgb/pendidikan/keluarga/alamat/diklat/tanda_jasa/hukdis/cpns/pns/mutasi_jabatan/ak/foto`. Kemungkinan besar diisi trigger DB pada tabel `riwayat_*` yang berada di luar rentang dump. `notif_date` juga tidak ditulis PHP untuk riwayat (hanya `L_pmk.php:1782`).
   - Aturan "baris riwayat mana yang menjadi snapshot" tidak terdokumentasi di legacy.
   - v2 memakai `BaseSnapshotModel::syncToActiveSnapshot()` di approval final. Aturan pemilihan baris per riwayat ditetapkan di B-07..B-17, bukan di skema.
   - `SHOW TRIGGERS` diminta ke pemegang akses (6.4).
2. **`document_attachment` [K] (`simpeg_prod.sql:497-519`) tidak punya kolom `id_parent`/`jenis_rwy`.** Kolom polimorfiknya `id_riwayat` INT (kode jenis) + `id_entri` VARCHAR(100) (id baris). Istilah "id_parent + jenis_rwy" di Mapping, Tech Spec, dan B-18 adalah nama konsep, bukan nama kolom.
   - Kode jenis: `ARSIP_RWY` (`config/constants.php:193-214`); label: `JENIS_RWY_ARSIP` (:215-238); resolusi ke tabel: `previewRwy()` (`helpers/function_helper.php:2260-2383`).
   - `id_riwayat = 0` berarti "arsip belum terhubung" (`views/hr/employee/arsip/list.php:77-125`, `L_arsip.php:17`).
3. **Ada kode "jenis_rwy" kedua yang berbeda:** `skl.jenis_rwy` 1 Cuti / 2 Kariskarsu / 3 Karpeg / 4 TB / 5 IB (`function_helper.php:241` `jenisLayananSKL`, `controllers/hr/Announcement.php:677`). Kode ini **bukan** bagian lookup B-02 dan tidak boleh dicampur.
4. **Status per tabel:**
   - Riwayat: 0 Menunggu / 1 Disetujui / 2 Ditolak / 10 Dihapus. Daftar memfilter `NOT IN ('2','10')` atau `IN ('0','1')`; penghapusan legacy tetap hard delete.
   - Bukti [K-m] (`d_lkh.status`, `d_riwayat_cuti.status`) bertipe `int NOT NULL DEFAULT '0'`. Stub [L] `VARCHAR(5) DEFAULT '1'` diragukan.
   - LKH: 0/1/2/3 (3 = Revisi) [K-m] `d_lkh` :691.
   - `absen_ijin`: `CHAR(2) NOT NULL DEFAULT 'W'` W/V/X/10 [K] :51.
   - `pegawai_hist.flag_update`: 1 Diajukan / 2 Ditolak-dibatalkan / 3 Disetujui (`L_employee.php:432-482, 1098-1110, 1175`), **bukan** "status 0" seperti DoD B-03/B-04 (1.4 #1).
   - `pegawai.flag_update` 0..3; `pegawai.status` 1/2/10 [L].
5. **Kolom workflow approval yang dibaca notifikasi** (`L_notification.php:3350-3425` `get_ah_sa`): `status`, `reason_note`, `approved_by`, `show_notif`, `notif_date`, `show_ua_upt`, `show_ua_deputi`, `show_ua_biro`, `updated_at`. Berlaku untuk `absen_ijin`, `riwayat_kp`, `riwayat_mutasi_jabatan`, `riwayat_kgb`, `riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar`, `riwayat_organisasi`, `riwayat_tanda_jasa`, `riwayat_skp`, `riwayat_keluarga`, `riwayat_alamat`, `riwayat_ak`, `pegawai_hist`.
6. **Pemilik tabel di fase lain** (tidak dibuat di jalur ini): C-01 Fase 4 (`04-Layanan.md:16`) untuk `riwayat_cuti`, `riwayat_tb`, `riwayat_tb_perpan`, `riwayat_ib`, `riwayat_pmk`, `riwayat_pmk_req`; D-01 Fase 5 untuk `dh_online`, `gaji_pegawai`. `absen_ijin` **tidak** ada di D-01, sehingga masuk B-02 (B-13 Konket).

### 1.3 Cakupan

**DBV-012 (B-01):** `pegawai`, `pegawai_hist`, `pegawai_foto`, dan 13 snapshot (`pegawai_kp`, `pegawai_cpns`, `pegawai_pns`, `pegawai_kgb`, `pegawai_pendidikan`, `pegawai_diklat`, `pegawai_hukdis`, `pegawai_ak`, `pegawai_keluarga`, `pegawai_alamat`, `pegawai_alamat_kantor`, `pegawai_tanda_jasa`, `pegawai_mutasi_jabatan`), ditambah FK masuk `pengguna.nip` dan `faq_rate.nip` → `pegawai`.

**DBV-013 (B-02):** `riwayat_mutasi_jabatan`, `pegawai_plt`, `pegawai_plh` (penugasan Plt/Plh B-07, bukan snapshot), `riwayat_kp`, `riwayat_kgb`, `riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar`, `riwayat_skp`, `riwayat_skp_periodik`, `bkn_periode_ekinper`, `riwayat_lckh`, `absen_ijin`, `riwayat_hukdis`, `riwayat_ak`, `riwayat_ak_siasn`, `konv_ak`, `ignore_konv_ak`, `riwayat_keluarga`, `detail_anak`, `riwayat_alamat`, `riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_tanda_jasa`, `riwayat_organisasi`, `document_attachment`, `jenis_rwy` (lookup [V2]), ditambah FK snapshot → riwayat.

**Di luar cakupan:**

| Tabel | Alasan / pemilik |
|---|---|
| `riwayat_cuti`, `riwayat_cuti_notif_kt`, `riwayat_tb`, `riwayat_tb_perpan`, `riwayat_ib`, `riwayat_pmk`, `riwayat_pmk_req`, `pegawai_sisa_cuti`, `petugas_layanan` | Fase 4 (C-01) |
| `skl` | Tier 7 |
| `riwayat_ak_unsur`, `riwayat_ak_spmk`, `riwayat_ak_bk` | Hanya dibaca `L_ak.php:1064-1072` dan hanya ditulis `L_ak_20230729.php` → data historis pra-PermenPAN-RB 1/2023 |
| `riwayat_ak_skkp`, `pegawai_ak_siasn`, `riwayat_layanan`, `riwayat_mutasi_req`, `riwayat_konket_beta`, `riwayat_skp_periodik_temp`, `pegawai_test` | 0 pemakaian di kode |
| `riwayat_jabatan`, `pegawai_jabatan` | Hanya `L_support.php:511`, jalur mati |
| `jabatan_plt` | Tidak dipakai kode (FK ERD ke G-02 saja; temuan kelompok 2 A #8) |
| Tabel backup `*_YYYYMMDD`, `riwayat_lckh_2019..2022` | Salinan historis |
| `d_*`, `da_deleted` | Arsip hapus berbasis trigger; di v2 digantikan `audit_logs` |
| `aa_lkh` [K :23-28], `queue_aa_lkh` | Auto-approval LKH lewat cron (`controllers/hr/services/Cron.php:18-220`); pemilik belum ditetapkan (4.1 #10) |

### 1.4 Konflik yang dicatat

| # | Konflik | Sikap |
|---|---|---|
| 1 | DoD B-03/B-04: `pegawai_hist` "status 0 (pending)", approve → 1, reject → 2, vs legacy `flag_update` 1 Diajukan / 2 Ditolak-dibatalkan / 3 Disetujui | Skema ikut legacy (`flag_update` DEFAULT 1, CHECK 1/2/3); B-03/B-04 memetakan istilah DoD ke nilai legacy |
| 2 | Mapping / Tech Spec / B-18 menyebut kolom `id_parent` + `jenis_rwy`; DDL [K] memakai `id_riwayat` + `id_entri` | Nama kolom [K] dipakai; "jenis_rwy" menjadi nama tabel lookup [V2] |
| 3 | DoD B-01 "FK aktif ke seluruh master Tier 1" vs tabel G-02 (jabatan/unit/satker, dst.) belum ada di `main` | FK ke G-02 dipisah ke migration yang ditahan (2.0.7 keranjang d); DoD B-01 baru penuh setelah DBV-008 merge |
| 4 | `skl.jenis_rwy` (1-5, layanan) vs `document_attachment.id_riwayat` (kode arsip) | Hanya kode arsip yang masuk lookup `jenis_rwy`; `skl` Tier 7 |
| 5 | Seed rekonstruksi `simpeg_v2_local_seed_legacy.sql` (:1096-1285, ±1405-2040) | Titik awal DDL saja, label tetap [I]; tidak disentuh di PR ini |
| 6 | Stub [L] `pegawai_hist` (4 kolom) bertentangan dengan kode | Kode [I] dipakai (2.1.2) |
| 7 | Rencana menyebut dua dokumen (`B-01-pegawai-snapshot-schema.md`, `B-02-riwayat-document-attachment-schema.md`); draf ini satu dokumen gabungan | Dipecah saat PR dipecah, atau tetap satu dokumen (4.1 #14) |
| 8 | Rencana: CHECK status riwayat 0/1/2/10 vs form admin legacy yang menulis 3 "Diproses" di 11 tabel | 3 masuk domain tabel-tabel itu (1.5 #2, 4.1 #2) |
| 9 | `jenis_jabatan` snapshot [L] NOT NULL DEFAULT 1 vs riwayat stub [L] NULL | Masing-masing ikut buktinya; B01-6 / K2-5 |
| 10 | A-01 #4 / G-10 D2 menjadwalkan FK `pengguna`/`faq_rate` di B-01 vs data Dev (akun ber-NIP) & alur akun di `main` | Migration `120200` ditahan (1.5 #1, 4.1 #12) |
| 11 | Daftar legacy sebagian memfilter `IN ('0','1')` (SKP/hukdis/AK, kelompok 3) dan sebagian `NOT IN ('2','10')` (kelompok 2) | Tidak mengubah skema; tampil/tidaknya status 3 diputuskan B-07..B-17 |

### 1.5 Penyelarasan lintas kelompok (integrasi 30-09-2026, pra-review internal CR)

Migration ditulis empat kelompok penulis secara paralel lalu disatukan. Pemeriksaan integrasi dan keputusan yang diambil
integrator untuk draf ini (semuanya tetap diajukan ke DB Validator di Bagian 4):

1. **FK masuk `pengguna.nip`/`faq_rate.nip` → `pegawai` (migration `120200`) DITAHAN** (4.1 #12, 2.1.6, Bagian 7). Tabel
   `pengguna` dan `faq_rate` sudah disetujui dan berisi data di Dev: `pengguna` di Dev sudah memuat akun ber-NIP, sedangkan
   `pegawai` baru dibuat kosong oleh `120000`. Dengan FK di batch yang sama, `php spark migrate` di Dev berhenti di
   `120200` (pra-cek orphan fail-closed — terverifikasi pada DB pengembangan lokal berisi 14 akun ber-NIP dan pada DB
   scratch, 6.2), migration sesudahnya tidak jalan, dan pembuatan/ubah akun ber-NIP lewat A-09 AccountProvisioner/Manajemen Akun berubah dari 422 menjadi
   error server (1452) selama `pegawai` belum terisi. Migration tetap fail-closed dan tidak mengubah data; ia diaktifkan
   setelah prasyarat di Bagian 7 terpenuhi. Registry NIP mencatat kedua kolom sebagai "FK ditahan" (2.0.8).
2. **Status 3 "Diproses" masuk domain CHECK riwayat yang form admin legacy-nya menulis nilai itu** (4.1 #2): opsi
   `value="3"` "Diproses" ada di `views/hr/employee/rwy/{jabatan,kp,kgb,pendidikan,diklat,seminar,skp,hukdis,keluarga,organisasi,tj}/form.php`
   dan disimpan apa adanya (`$param = $postData`); COMMENT [K-m] `d_riwayat_cuti.status` juga memuat "3: Diproses". Tabel
   `riwayat_mutasi_jabatan`, `riwayat_kp`, `riwayat_kgb`, `riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar`,
   `riwayat_skp`, `riwayat_hukdis`, `riwayat_keluarga`, `riwayat_tanda_jasa`, `riwayat_organisasi` → CHECK
   `IN (0, 1, 2, 3, 10)` + COMMENT `'… 3: Diproses …'`. Tabel yang form legacy-nya tidak menawarkan 3 (`riwayat_alamat`,
   `riwayat_ak`, `riwayat_ak_siasn`, `konv_ak`, `riwayat_skp_periodik`, `riwayat_karpeg`, `riwayat_kariskarsu`) tetap
   `IN (0, 1, 2, 10)`. Pengecualian yang sudah ada tidak berubah: LKH 0/1/2/3/10 (3 = Revisi) [K-m], `absen_ijin`
   W/V/X/10 [K], Plt/Plh 1/2/10. Alasan: CHECK adalah tambahan [V2]; menolak nilai yang memang ditulis legacy memaksa
   pemetaan lossy saat impor. Usulan kelompok 3 (tetap 0/1/2/10, petakan 3 → 0) dicatat sebagai alternatif; tabel masih
   kosong sehingga ALTER CHECK murah bila DBV memilih sebaliknya.
3. **`siasn_flag` diseragamkan `TINYINT NOT NULL DEFAULT 0`** (tanpa lebar tampilan, pola [L] `pegawai.siasn_flag`) di
   `riwayat_mutasi_jabatan`, `riwayat_diklat`, `riwayat_seminar` (sebelumnya `TINYINT(1)`), sama dengan `riwayat_skp` dan
   `riwayat_tanda_jasa`. COMMENT domain tetap per tabel (domain legacy berbeda per modul).
4. **Snapshot = salinan riwayat** (sudah diterapkan kelompok 1 sebelum integrasi, dicek ulang): `pegawai_hukdis.keterangan`
   TEXT (= `riwayat_hukdis`), `pegawai_tanda_jasa` + `negara`/`keterangan`, `pegawai_diklat` + `sub_group_jabatan`/
   `deskripsi`/`keterangan`. `SnapshotRiwayatFkTest` membuktikan tipe `id_riwayat_*` snapshot = PK riwayat (13 FK).
5. **Yang sengaja dibiarkan berbeda dan diajukan sebagai keputusan:** `jenis_jabatan` snapshot NOT NULL DEFAULT 1 [L] vs
   riwayat NULL [L stub] (4.2 B01-6 / 4.3 K2-5); tipe blok workflow TINYINT vs INT (4.1 #3); `keterangan` TINYTEXT
   (KP/KGB/pendidikan/keluarga) vs TEXT (diklat/seminar/hukdis/tanda jasa) mengikuti bukti per modul.
6. **Nama constraint:** 108 FK dan 32 CHECK dari 43 tabel B-01/B-02 unik per schema (diperiksa di `information_schema`
   setelah migrate penuh, tidak ada nama ganda dengan `main`), panjang maksimum 49 karakter (≤ 64). Nama FK = ERD persis;
   FK [V2] satu-satunya `fk_id_riwayat_da_to_jenis_rwy`.
7. **Urutan migration & dependensi FK:** `120000` (pegawai → master `main`) → `120100` (snapshot → `pegawai` + master) →
   `130000` (`jenis_rwy`) → `130100`..`130800` (riwayat → `pegawai` + master) → `130900` (`document_attachment` →
   `pegawai`, `jenis_rwy`) → `131000` (snapshot → riwayat, pra-cek tabel/kolom induk). Rollback batch berjalan terbalik;
   tidak ada migration yang merujuk tabel dari migration sesudahnya kecuali `131000` yang dirancang untuk itu.
8. **Kontrak `jenis_rwy`:** seed 21 baris dicocokkan literal dengan `ARSIP_RWY`/`JENIS_RWY_ARSIP` legacy di test (kode,
   kunci, label, urutan tampil) dan `tabel_entri` menunjuk tabel B-02 yang benar (kode 42 → `riwayat_pmk` Fase 4, nama
   saja). Kode `skl.jenis_rwy` (layanan) tidak dicampur.
9. **Test:** schema test B-02 kelompok 2-4 (belum ditulis penulis) dibuat integrator dan mengunci kolom (tipe/NULL/urutan),
   default & ON UPDATE, collation, PRIMARY/UNIQUE/index, FK (nama, kolom, induk, RESTRICT/RESTRICT), CHECK (nama & domain),
   perilaku constraint, `down()`/`up()`, dan pembersihan `up()` yang gagal di tengah. Draf penulis yang disimpan di luar
   repo (`AddFkG02*`) dicocokkan ulang: PK `jabatan_koordinasi` = `id_jabatan_koordinasi` [I `L_jabatan.php:2194`] di kedua
   draf.

### 1.6 Temuan riset per kelompok

Temuan penulis per kelompok (dirujuk sebagai "A #n" di 2.x kelompok yang sama). Butir yang berubah karena integrasi diberi catatan.

#### 1.6.1 Kelompok 1 — pegawai, pegawai_hist, pegawai_foto, snapshot, FK masuk, FK snapshot → riwayat

1. **`pegawai_hist` = salinan baris `pegawai` + `email` + blok workflow.** Pengajuan non-Super Admin membentuk
   `$paramHist = $param + $peg` lalu `unset($paramHist['deleted_at'])` (`L_employee.php:473-475`), menambah `email`,
   `approved_by`, `reason_note`, `show_notif`, `show_ua_*` (:476-482), lalu insert (tanpa `created_at`) atau update
   (tanpa `updated_at`) (:483-490). Approval menyalin baris hist kembali ke `pegawai` setelah membuang
   `id_pegawai_hist`, `created_at`, `updated_at`, `approved_by`, `reason_note`, `show_notif`, `notif_date`, `show_ua_*`
   (`sp_process` :1172-1178; `email` dipisah ke `pengguna.email` :1085-1096). Konsekuensi skema: **setiap kolom
   `pegawai` kecuali `deleted_at` wajib ada di `pegawai_hist` dengan tipe identik** (termasuk `status`, `flag_update`,
   `id_pns_siasn`, `siasn_*`, `created_at`, `updated_by`), dan hist tidak boleh punya kolom lain selain yang dibuang
   `sp_process` + `email`. Migration memakai satu fungsi kolom biodata untuk kedua tabel.
2. **Domain `flag_update`.** `pegawai.flag_update`: 0 (disimpan Super Admin, `set_param` :1018-1023), 1 (diajukan,
   :465-468), 2/3 (hasil approval disalin dari hist, :1175) → CHECK 0..3. `pegawai_hist.flag_update`: 1 Diajukan,
   2 Ditolak/dibatalkan (juga saat Super Admin menimpa pengajuan yang masih menunggu, :441-447), 3 Disetujui → CHECK
   1..3, DEFAULT 1. DoD B-03/B-04 "status 0 pending" dipetakan ke `flag_update = 1` (Bagian 1.4 #1).
3. **`pegawai_foto` tidak punya titik tulis PHP** — hanya dibaca sebagai galeri (`L_employee.php:44` urut
   `id_pegawai_foto DESC`; `views/hr/employee/form.php:891-895` memakai `id_pegawai_foto` dan `foto`). Kemungkinan diisi
   trigger saat `pegawai.foto` berubah. Kolom dibuat minimal (`id_pegawai_foto`, `nip`, `foto`, `created_at` [V2]);
   banyak baris per `nip` (bukan snapshot). Kolom lain menunggu SHOW CREATE TABLE (B01-9 (4.2)).
4. **`pegawai_cpns` / `pegawai_pns` berbentuk `pegawai_kp`.** simapi `pengangkatan_cpns`/`pengangkatan_pns` membaca
   `id_riwayat_kp`, `nip`, `tmtsk`, `tgl_sk`, `no_sk`, `id_jenis_kp`, `id_pangkat`, `mker_th`, `mker_bl`, `gaji_pokok`
   (`controllers/simapi/v1/A_employee.php:1439-1468, 1524-1530`), dan ERD memberi keduanya FK ke `riwayat_kp`,
   `jenis_kp`, `pangkat` dengan pola nama `pegawai_kp`. Kolom [I] = DDL [L] `pegawai_kp` persis.
5. **Semua pembaca snapshot men-JOIN per `nip`** (`Tester.php:3713-3747`, `L_employee.php:5893-5925, 6969-6980`,
   `L_ak.php:46-56`) → konsisten dengan PK `nip` (satu baris per pegawai, ADR-006 `syncToActiveSnapshot()`). Pengecualian
   hanya `pegawai_ak` yang juga dicari per (`nip`, `id_jabatan`, `id_pangkat`) (`L_ak.php:359`) — tetap satu baris per
   nip; nilai lama hanya dipakai bila jabatan & pangkat sama.
6. **Jumlah FK G-02 `pegawai_mutasi_jabatan` = 13, bukan 15** (koreksi Bagian 2.5). ERD mencatat 16 FK untuk
   tabel ini: 1 PEG (`fk_nip_pmj_to_pegawai`), 1 MAIN (`pegawai_mutasi_jabatan_ibfk_01` → `gol_pppk`), 1 RWY, 13 G02.
   Dari 13 FK G02, **4 menunjuk tabel yang belum ada di draf DBV-008** (`jabatan_koordinasi` ×3, `rumpun_jabatan` ×1;
   sama dengan temuan kelompok 2 A #3). Total FK G02 kelompok 1 = 14 (13 + `pegawai_ak.fk_id_jabatan_cak_to_jab`).
7. **`AddFkG02Pegawai` disimpan di luar folder `Migrations`.** Bila berada di `app/Database/Migrations/`, setiap
   `migrate` dan test ber-`$migrate = true` gagal (tabel G-02 belum ada di `main`). Draf ada di
   branch lokal `dbv-012/fk-ditahan` (Bagian 7): 10 FK ke tabel yang sudah ada
   di draf DBV-008 (`group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `jabatan`; PK INT cocok) + konstanta
   `MENUNGGU_TABEL` berisi 4 FK yang belum bisa dipasang. Timestamp ditetapkan ulang agar > migration G-02.
8. **Keselarasan lintas kelompok (snapshot = salinan riwayat).**
   - `pegawai_hukdis.keterangan` **diubah ke TEXT** mengikuti `riwayat_hukdis.keterangan` TEXT (kelompok 3 K3-9;
     TINYTEXT akan gagal 1406 saat sinkron teks > 255 byte). Kolom bisnis lain `pegawai_kp`/`pegawai_kgb`/
     `pegawai_pendidikan`/`pegawai_diklat`/`pegawai_ak`/`pegawai_mutasi_jabatan` dicek sama tipe dengan draf riwayat
     kelompok 2/3 (30-09-2026, sebelum integrasi).
   - **`jenis_jabatan`**: snapshot [L] `NOT NULL DEFAULT 1`, riwayat (kelompok 2 A #4) `NULL DEFAULT NULL` (stub [L]).
     Sinkron baris riwayat dengan `jenis_jabatan` NULL ke snapshot akan gagal (1048). Keputusan B01-6 (4.2).
   - Kolom snapshot [I] dibuat **nullable** kecuali yang [L] / rencana tetapkan NOT NULL (mis. `pegawai_kgb.tmtsk`),
     walaupun riwayatnya NOT NULL (`riwayat_diklat.jenis_diklat`/`tgl_sertifikat`, `riwayat_hukdis.tmtsk`,
     `riwayat_ak.jenis_ak`): snapshot legacy diisi trigger di luar kendali kode, jadi skema tidak lebih ketat dari
     bukti. Kolom riwayat yang tidak disalin ke snapshot (mis. `riwayat_pendidikan.glr_*`, `riwayat_diklat.deskripsi`)
     cukup diabaikan `snapshotFields()`.
   - Kelompok 4 mencocokkan `pegawai_keluarga`/`pegawai_alamat`/`pegawai_alamat_kantor` identik dengan
     riwayatnya. Temuan kelompok 4 A #3 diverifikasi dan **diterapkan**: simapi "tanda jasa terakhir" membaca `ptj.*`
     termasuk `negara` dan `keterangan` (`A_employee.php:2112-2131`) → `pegawai_tanda_jasa` + `negara` V100 + `keterangan`
     TEXT (= `riwayat_tanda_jasa`). Pemindaian yang sama menemukan simapi "pelatihan struktural terakhir" membaca
     `pdik.deskripsi`, `pdik.sub_group_jabatan`, `pdik.keterangan` (`A_employee.php:939-970`) → `pegawai_diklat` + tiga
     kolom itu (tipe = `riwayat_diklat` kelompok 2). Pembaca `SELECT p*.*` snapshot lain di simapi (`pegawai_alamat*`,
     `pegawai_pendidikan`, `pegawai_kp/cpns/pns`, `pegawai_keluarga`) hanya memakai kolom yang sudah ada.
9. **FK masuk `120200` mematahkan test & seed lama** (diverifikasi, 6.2). Setelah FK aktif, setiap
   `pengguna`/`faq_rate` ber-NIP wajib punya baris `pegawai`. `tests/_support/Database/Seeds/AuthSeeder.php` (14 akun
   ber-NIP tanpa `pegawai`) dan test yang membuat akun/rating dengan NIP bebas gagal 1452; `FaqSchemaTest` masih
   mengharapkan "tanpa FK faq_rate.nip" (D2). Perbaikan ada di file bersama (seed/test support), **di luar file milik
   kelompok 1** → B01-1 (4.2).
   > Catatan integrasi (1.5): karena temuan ini dan data Dev, migration `120200` **ditahan** (1.5 #1, 4.1 #12);
   seed/test akun & rating tidak diubah di PR ini.
10. **Test yang memanggil `down()` migration lama sementara migration sesudahnya terpasang** (pola
    `Batch1LegacySchemaTest`, `AuthCollationMigrationTest`, `AkunNonPegawaiSchemaTest`) kini juga terhalang FK B-01/B-02
    ke wilayah/agama/jenis_pegawai/jenis_status/`pengguna.nip` (MySQL 1833/3780 saat MODIFY/CONVERT kolom yang dirujuk).
    Hasil nyata di 6.2; perbaikan (lepas dependen B-01/B-02 di `setUp()`, seperti `WILAYAH_DEPENDENTS`) di file
    test lama → B01-1 (4.2).
    > Catatan integrasi (1.5): diterapkan lewat `Tests\Support\LepasMigrationKepegawaianTrait` di
    `Batch1LegacySchemaTest`, `DiklatHukdisKonketSchemaTest`, `PangkatPendidikanSchemaTest` (6.2); test Auth tidak perlu
    diubah selama `120200` ditahan.
11. **Tidak ada CHECK format NIP.** PNS 18 digit, tetapi `pegawai` juga memuat Non-PNS (PTT/PPPK/tenaga ahli) yang memakai
    NIK 16 digit; aturan yang sudah dipakai v2 = "angka saja, maksimal 18 digit" (docblock
    `app/Libraries/Auth/AccountProvisioner.php`, DBV-010). Validasi format di aplikasi (B-05/B-06), bukan CHECK.
12. **`pegawai.created_at` adalah data bisnis**: laporan presensi/tukin/uang makan legacy hanya menghitung pegawai dengan
    `peg.created_at` < batas periode (mis. `L_presensi.php:182, 599, 975, 1053, 1610`; juga :8255) → nilai legacy wajib diimpor apa adanya
    (bukan waktu impor). Dicatat untuk Mapping (6.5.1).
13. **`131000` harus jalan setelah seluruh riwayat.** Bila salah satu tabel/kolom induk belum ada, `up()` berhenti dengan
    daftar kolom yang kurang (bukan error 1824 mentah). Selama migration riwayat kelompok 4 belum ada di worktree,
    `migrate` penuh di branch draf gagal di `131000` — perilaku yang disengaja (fail-closed), bukan bug. Setelah integrasi
    semua migration riwayat ada dan `131000` terpasang bersih (6.2).

#### 1.6.2 Kelompok 2 — riwayat jabatan, Plt/Plh, KP, KGB, pendidikan, diklat, seminar

1. **Status legacy 3 "Diproses" ada di keenam riwayat kelompok 2.** Form edit admin (UserLevel 1) menawarkan
   `1 Disetujui / 2 Ditolak / 3 Diproses / 0 Waiting` di `views/hr/employee/rwy/{kp,kgb,jabatan,pendidikan,diklat,seminar}/form.php:59-72`
   (opsi 3 di baris 68), dan nilainya tersimpan karena `set_param`/`sp` menyalin `$postData['status']`
   (`L_kp.php:538-566`, `L_kgb.php:508-526`, dst.). Label generik legacy: `formatLblStatus()` 3/H = "Proses"
   (`helpers/function_helper.php:180-200`); [K-m] `d_riwayat_cuti.status` COMMENT "3: Diproses / Disetujui AL"
   (`simpeg_prod.sql`, blok `d_riwayat_cuti`). Daftar legacy memfilter `NOT IN ('2','10')` sehingga baris status 3 tetap
   tampil. Konvensi 2.0.3 (CHECK 0/1/2/10) **menolak** nilai ini → keputusan K2-1. Migration kelompok 2 semula memakai
   konvensi (satu konstanta `STATUS_RIWAYAT_DOMAIN` per file). Integrasi: domain diubah ke 0/1/2/3/10 (1.5 #2).
2. **`riwayat_pendidikan.glr_awal` / `glr_akhir`** ditulis sinkron SIASN (`siasn_sync/Rw_pendidikan.php:164-165, 425-426`)
   tetapi tidak ada di rencana (rencana menyamakan kolom dengan snapshot `pegawai_pendidikan`). Kolom dibuat
   `VARCHAR(50) NULL` [I] (panjang = `pegawai.glr_awal` [L]); seed rekonstruksi juga memuatnya.
3. **Jumlah FK G-02 `riwayat_mutasi_jabatan` = 13, bukan 15.** ERD mencatat 15 FK untuk tabel ini, dua di antaranya PEG
   (`fk_nip_rwymutasijabatan_to_pegawai`) dan MAIN (`riwayat_mutasi_jabatan_ibfk_01` → `gol_pppk`). Dari 13 FK G-02,
   4 menunjuk tabel yang **belum ada di draf DBV-008** (`jabatan_koordinasi` ×3, `rumpun_jabatan` ×1). Hal yang sama
   kemungkinan berlaku untuk hitungan `pegawai_mutasi_jabatan` (kelompok 1) di Bagian 2.5.
4. **`jenis_jabatan` riwayat nullable.** Stub [L] riwayat: `jenis_jabatan tinyint(1) DEFAULT NULL`; snapshot
   `pegawai_mutasi_jabatan` [L]: `NOT NULL DEFAULT '1'`. Riwayat mengikuti bukti tabelnya sendiri (stub [L]).
5. **`jenis_jabatan_koord` bukti bertentangan:** `pegawai_mutasi_jabatan` [L `simpeg_prod_duplikat`] `NOT NULL DEFAULT '3'`
   vs `pegawai_mutasi_jabatan` di DB lokal `simpeg01` `tinyint(1) DEFAULT NULL`. Riwayat memakai `NOT NULL DEFAULT 3`
   (seed + [L]); kode tidak pernah menulis NULL eksplisit ke riwayat (`$param = $postData`, radio disabled → kolom tidak
   dikirim → default). Keputusan K2-5.
6. **`kredit_jft` tidak punya titik tulis PHP di riwayat**; hanya dibaca dari snapshot (`L_employee.php:2472, 2533, 2752`,
   `simapi/v1/A_employee.php:1810, 1900`). Tetap dibuat di riwayat agar kolom bisnis riwayat = snapshot [L] (snapshot
   kemungkinan diisi trigger, Bagian 1.2 #1). Keputusan K2-7.
7. **Domain `siasn_flag` 0..4**: 0 belum, 1 sukses, 2 gagal, 3 proses (`Rw_jabatan.php:142-143, 165, 272, 317`), 4 dilewati
   (`Siasn.php:2010-2015`, diklat tanpa `id_rw_siasn`). Hanya COMMENT, tanpa CHECK.
8. **`jabatan_plt`** (ERD: FK `fk_id_*_jabplt_to_*` ke G-02) tidak dipakai kode (hanya substring nama kolom
   `id_group_jabatan_plt`) → di luar cakupan, tambahkan ke tabel "Di luar cakupan" Bagian 1.3.
9. **Path berkas memuat NIP**: `ser_dosen`/`f_spmt`/`f_bapel` = `arsip/{nip}/jabatan/<file>` (`L_jabatan.php:1006, 1362`);
   lampiran Plt/Plh `arsip/off_plt/`, `arsip/off_plh/` (`:4612, 5111`, tanpa NIP). Relevan untuk B-06 (ganti NIP) dan B-18.
10. **`AddFkG02Riwayat` disimpan di luar folder `Migrations` (Bagian 7).** Bila file berada di `app/Database/Migrations/`, setiap
    `migrate` dan setiap test ber-`$migrate = true` gagal (tabel G-02 belum ada di `main`), sehingga gate semua penulis
    patah. Drafnya disimpan di branch lokal `dbv-012/fk-ditahan`.
11. **ID master yang di-hard-code legacy** (wajib ada di impor master, kalau tidak FK menolak baris riwayat):
    `bidang_pendidikan` 98 dan `jurusan_pendidikan` 1185 = "LAIN-LAIN" (`L_pendidikan.php:821-829`,
    `Rw_pendidikan.php:150-151`); jenjang 1-3 = SD/SLTP/SLTA penentu `nem` vs `ipk` (`L_pendidikan.php:833-834`);
    `id_satker` 39 = dipekerjakan keluar instansi → `id_jabatan` NULL + `jabatan` teks bebas (`L_jabatan.php:2251`);
    `id_group_jabatan` 2 = JFT (`no_induk_jft`/`status_jft`), `id_sub_group_jabatan` 12 = dosen (`ser_dosen`,
    `views/hr/employee/rwy/jabatan/form.php:606`).
12. **Plt/Plh dihapus keras di legacy** (`L_jabatan.php:4778, 5277`); status legacy hanya 1/2 (`views/hr/officer/plt/form.php:20-32`).
    Status 10 disiapkan untuk soft delete B-07 (rencana 4.3).

#### 1.6.3 Kelompok 3 — SKP, LKH, konket, hukdis, angka kredit

1. **Status 3 "Diproses" masih ditawarkan form admin legacy.** Form edit role 1 untuk `riwayat_skp` (`views/hr/employee/rwy/skp/form.php:79-85`), `riwayat_hukdis` (`hukdis/form.php:45`), dan `absen_ijin` (`konket/form.php:40-53`) memuat opsi `1 Disetujui / 2 Ditolak / 3 Diproses / 0 Waiting`. Label legacy `formatLblStatus()` (`helpers/function_helper.php:180-201`) menampilkan `3`/`H` sebagai "Proses". Opsi yang sama ada di form `diklat`, `jabatan`, `keluarga`, `kgb`, `kp`, `organisasi`, `pendidikan`, `seminar`, dan `tj`, sehingga temuan ini **lintas kelompok**. CHECK konvensi 2.0.3 `IN (0,1,2,10)` menolak nilai 3. Daftar riwayat legacy memfilter `IN ('0','1')` (mis. `L_skp.php:27`, `L_hukdis.php:13`, `L_ak.php:29`), jadi baris berstatus 3 sudah tidak tampil di legacy. Keputusan: K3-1. Integrasi: `riwayat_skp` dan `riwayat_hukdis` memakai 0/1/2/3/10 (1.5 #2).
2. **`absen_ijin.status` bisa berisi angka.** Role 1 yang mengedit lewat `konket/form.php` mengirim `0/1/2/3`, dan `L_konket.php:1746-1748` menyimpannya apa adanya ke kolom `CHAR(2)`. Kode legacy juga memeriksa nilai angka (`controllers/hr/rwy/Konket.php:471` `== '0'`, `:747` `in ['1','2']`). Pemetaan legacy ada di `formatSimpegStatus()` (`function_helper.php:203-218`): W↔0, V↔1, X↔2, H↔3. CHECK `IN ('W','V','X','10')` menolak nilai angka tersebut. Keputusan: K3-2 dan audit impor (F-audit #2).
3. **`riwayat_ak` punya 4 kolom yang tidak ada di rencana.** Pop-up "Current AK" di dasbor role 2 (`controllers/User.php:569-590` → `libraries/hr/L_user.php:4878-4975` `insert_pak`, `jenis_ak = 1`) menulis `tgl_ak_terakhir`, `tgl_pengajuan_ak`, `file_pengantar`, dan `file_dupak`. `file_dupak` dibaca `views/hr/employee/rwy/ak/form_process_ak.php:54` (approval `Ak.php:801-830` `process_cak`). Keempat kolom ditambahkan [I].
4. **PK `riwayat_ak_siasn` = `id_riwayat_ak_siasn`.** Sumbernya kode (`Rw_ak.php:113, 186`, `L_ak.php:961, 992-994`) dan FK ERD `fk_id_riwayat_ak_siasn_peg_ak_siasn_02` (dari tabel `pegawai_ak_siasn` yang di luar cakupan). `simpegdev_local` memakai `id`, tetapi DB itu sintetis. Kolom `keterangan` dibaca `ak/list_siasn.php` dan `list_siasn_ad.php`, jadi ikut dibuat [I].
5. **`riwayat_skp_periodik.keterangan` dibaca** oleh `skp/detail_periodic.php:96` dan `form_periodic.php:97` lewat `skpper.*`, jadi ikut dibuat [I]. Kolom lain adalah salinan respons API e-Kinerja BKN (`Ekin_bkn.php:263-297`). Satu-satunya penulis (cron) selalu menulis `status = '1'`.
6. **Isi `riwayat_lckh` berformat array JSON dan `nip_atasan` bisa string kosong.** Kolom `kegiatan`, `output`, `jumlah_diselesaikan`, `jam_mulai`, dan `jam_selesai` berisi hasil `json_encode` array (`L_lkh.php:924-944`). `nip_atasan = addslashes($postData['nip_atasan'])` (`L_lkh.php:904`) bisa bernilai `''`, dan string kosong itu ditolak FK (terverifikasi, 1452). Impor harus menormalkan `''` → NULL (F-audit #4).
7. **Tidak cocok lintas kelompok: `pegawai_hukdis.keterangan` TINYTEXT (kelompok 1) vs `riwayat_hukdis.keterangan` TEXT (kelompok 3).** Keterangan hukdis ditulis dari textarea dan sinkron SIASN menambah akhiran `" - Sync SIASN"` (`Siasn.php:3044`). Sinkron snapshot akan gagal (strict, 1406) bila teks lebih dari 255 byte. Keputusan: K3-9. Integrasi: snapshot sudah TEXT (1.5 #4).
8. **`siasn_flag` SKP memakai domain sendiri:** 0 belum, 1 sukses (`Siasn.php:7781`), 2 gagal API (`:7812`), 3 data tidak valid (`:7823`). Kelompok 2 memakai COMMENT `'0 belum, 1 sukses, 2 gagal, 3 proses, 4 dilewati'` dan tipe `TINYINT(1)`. Kelompok 3 memakai `TINYINT` (pola [L] `pegawai.siasn_flag tinyint`) dengan COMMENT khusus SKP. Integrasi: diseragamkan ke `TINYINT` (1.5 #3, K3-10).
9. **[E] etalenta** membaca `riwayat_skp.kategori_nilai`, `nilai`, `nilai_skp_konversi`, dan `id` (`html/etalenta/app/**`). Kolom tersebut tidak ada di kode CI3, sehingga **tidak dibuat**. Kolomnya dicek ulang saat `SHOW CREATE TABLE` produksi tersedia (F-dump #1).
10. **Hapus legacy = hapus keras** di semua tabel kelompok 3: `L_skp.php:448`, `L_lkh.php:668`, `L_konket.php:939, 949` (dengan trigger `absen_ijin_beDel` → `d_konket`), `L_hukdis.php:292`, `L_ak.php:733`, `L_user.php:5175-5176` (`konv_ak` + `ignore_konv_ak` per nip). Status 10 tetap ada di domain karena daftar legacy memfilter `!= '10'` / `NOT IN ('2','10')`.

#### 1.6.4 Kelompok 4 — keluarga, alamat, kartu, tanda jasa, organisasi, lampiran, lookup

1. **Status legacy 3 "Diproses" juga ada di riwayat kelompok 4.** Form edit admin `keluarga`, `organisasi`, dan `tj`
   (`views/hr/employee/rwy/{keluarga,organisasi,tj}/form.php:64-69`) menawarkan `1 Disetujui / 2 Ditolak / 3 Diproses /
   0 Waiting`, dan `sp`/`set_param` menyimpannya apa adanya (`L_keluarga.php:1046`; `L_organisasi.php:514` dan
   `L_tj.php:493` lewat `$param = $postData`). Form `alamat` hanya menawarkan 1/2 (`alamat/form.php:64-68`); karpeg/
   kariskarsu diproses lewat radio 1/2 (`karpeg/form_process.php:146-149`). Ini temuan lintas kelompok yang sama dengan
   kelompok 2 (K2-1) dan kelompok 3 (K3-1). Keputusan: K4-1. Integrasi: `riwayat_keluarga`, `riwayat_organisasi`,
   `riwayat_tanda_jasa` memakai 0/1/2/3/10; alamat dan karpeg/kariskarsu tetap 0/1/2/10 (1.5 #2).
2. **Kolom `riwayat_tanda_jasa.negara` tidak ada di rencana.** Field form `negara` (`tj/form.php:116-118`) ikut tersimpan
   karena `$param = $postData` (`L_tj.php:491-505`); sinkron SIASN menulis `'negara' => 'Indonesia'`
   (`controllers/hr/services/Siasn.php:6067`). Kolom ini dibaca daftar/detail/proses (`tj/list.php:60`, `tj/detail.php:71`,
   `tj/form_process.php:57`), API simapi (`controllers/simapi/v1/A_employee.php:2210`), dan pencarian lanjutan
   (`L_employee.php:11368`). Dibuat `VARCHAR(100) NULL` [I] (panjang [I], nama negara). Keputusan: K4-7.
3. **Snapshot `pegawai_tanda_jasa` juga harus punya `negara` dan `keterangan`.** simapi "tanda jasa terakhir"
   (`A_employee.php:2112-2131`) membaca `ptj.negara` dan `ptj.keterangan` dari snapshot. Draf kelompok 1 (`120100`) belum
   memuat kedua kolom itu (keputusan B01-7). Integrasi: sudah ditambahkan kelompok 1 (1.5 #4).
4. **Hapus keluarga legacy mengandalkan CASCADE untuk `detail_anak`.** `L_keluarga::delete()` (`:720-777`) hanya menghapus
   baris `riwayat_keluarga` (`:772`) dan lampiran (keluarga id_riwayat 20 + anak id_riwayat 3); baris `detail_anak` ikut
   terhapus karena FK [K] `ON DELETE CASCADE` (`simpeg_prod.sql:385`). Dengan RESTRICT (K1), service B-15 wajib menghapus
   `detail_anak` dan lampirannya lebih dulu dalam satu transaksi; FK menolak (1451) bila urutannya salah (terverifikasi).
   Hapus satu anak (`delete_anak` `:812-846`) sudah eksplisit: hapus `detail_anak`, lampiran, dan hitung ulang
   `riwayat_keluarga.jumlah_anak`.
5. **`rated` karpeg/kariskarsu harus DEFAULT 2.** Pop-up survei kepuasan layanan muncul untuk baris
   `status='1' AND rated='2'` (`core/MY_Controller.php:488-523`); kode hanya pernah menulis `rated = '1'`
   (`controllers/hr/Announcement.php:683, 710`) dan tidak pernah menulis 2 saat insert (`sp()` = `$param = $postData`,
   controller hanya menambah `nip` dan `status = 0`, `controllers/hr/rwy/Karpeg.php:77-78, 137-138`). Jadi nilai 2 berasal
   dari default DB. Pola [K-m] `d_riwayat_cuti.rated tinyint NOT NULL DEFAULT '2' COMMENT '0: Ignore, 1: Sudah, 2: Belum'`
   (`simpeg_prod.sql`, blok `d_riwayat_cuti`) cocok dengan perilaku ini; stub [L] `rated varchar(5) DEFAULT NULL`
   bertentangan (survei tidak akan pernah muncul). Dipakai pola [K-m]. Keputusan: K4-6.
6. **Karpeg/kariskarsu adalah permohonan layanan, bukan riwayat ber-approval biasa.**
   - Diproses petugas layanan (`petugas_layanan` jenis layanan 1/2, tabel Fase 4 C-01; `Karpeg.php:206, 318`), bukan
     admin UPT/deputi: `sp_process` selalu `show_ua_biro = 1` (`L_karpeg.php:1077-1085`). Karena itu blok WF-nya
     pengecualian (hanya `show_ua_biro` + `rated`).
   - `notif_date` dibaca riwayat petugas layanan (`L_notification.php:4120-4127`); `show_notif` diset 3 saat dibaca
     (`L_karpeg.php:75-81`) dan daftar notifikasi membaca `show_notif IN (1, 3, 4)` (`L_notification.php:3104`).
   - `created_at` + `id` membentuk nomor tiket dan kode QR tanda terima (`L_karpeg.php:100, 960-961`) → nilai legacy
     wajib ikut diimpor, ID dipertahankan.
   - Edit/hapus hanya bila status bukan 1/10 (`Karpeg.php:121, 275`); daftar `status != '10'` (`L_karpeg.php:15, 30`);
     hapus = hard delete (`L_karpeg.php:736`). Berkas di kolom path `arsip/{nip}/karpeg/…` (`:180-351`), bukan
     `document_attachment`; batas unggah legacy 5 MB.
   - `skl.id_rwy` untuk `skl.jenis_rwy` 2/3 menunjuk `id_riwayat_kariskarsu`/`id_riwayat_karpeg`
     (`Announcement.php:677-678`). Kode `skl.jenis_rwy` ini **bukan** lookup `jenis_rwy` (Bagian 1.2 #3).
7. **`document_attachment` [K] — perilaku yang memengaruhi B-18 (bukan skema).**
   - Tidak ada kolom `id_parent`/`jenis_rwy`: pasangan polimorfiknya `id_riwayat` (kode) + `id_entri` (id baris). Query
     legacy selalu `id_entri='…' AND id_riwayat='…'` (mis. `L_employee.php:10808`, `L_keluarga.php:18`); KEY legacy
     `id_riwayat_id_entri` dipakai optimizer (EXPLAIN, 6.2.4).
   - `id_entri` VARCHAR di-join ke PK INT (`controllers/hr/rwy/Keluarga.php:590`, `L_employee.php:8500`) → konversi
     implisit; service v2 menulis `id_entri` sebagai string desimal id tanpa spasi/nol di depan.
   - `id_riwayat = 0` = arsip belum ditautkan (`views/hr/employee/arsip/list.php:77-125`); penautan ulang mengubah
     `id_riwayat`, `nama_riwayat` (= label `JENIS_RWY_ARSIP`) dan `id_entri` (NULL untuk 38) (`controllers/hr/services/Local.php:3690-3700`).
   - `JENIS_RWY_ARSIP` memuat baris yang dikomentari `33 => 'Data Umum'` (label lama kode 38, lihat `Local.php:3696`
     `// 'id_entri' => $idJenisRwy == '33' ? …`). Baris lama ber-`id_riwayat = 33` ditolak FK → pemetaan sebelum impor
     (K4-3).
   - `file_size` berisi kilobyte (upload CI3 `file_size`, SIASN `intval(Content-Length / 1000)` `Siasn.php:1425`) dan
     disimpan ke INT; `filename` bisa `''` (`Siasn.php:1434`). Satuan dan validasi ditetapkan B-18, skema tetap [K].
   - Path berkas memuat NIP (`arsip/{nip}/…`, mis. `L_keluarga.php` lewat `arsip_path`, karpeg `arsip/{nip}/karpeg/`) →
     relevan untuk B-06 (ganti NIP).
   - Batas unggah legacy 5 MB (`L_keluarga.php:215-216`, `L_karpeg.php:165`) vs DoD B-18 1–2 MB → keputusan B-18,
     bukan skema.
8. **Pemetaan kode `jenis_rwy` → tabel (`tabel_entri`) dicek ulang** terhadap `previewRwy()`
   (`helpers/function_helper.php:2260-2383`, menangani 1/3/5/7/9/36/10/11/13/14/20/22/23/32) dan titik tulis modul:
   39/40 → `riwayat_pendidikan` (`controllers/hr/rwy/Pendidikan.php:566, 618`), 36/41 → `riwayat_mutasi_jabatan`
   (`L_jabatan.php:938-941`), 42 → `riwayat_pmk` (`L_pmk.php:106-122`, `id_entri` = `id_riwayat_pmk`, tabel Fase 4),
   37/38 → `id_entri` NULL (`L_employee.php:289-304, 497-512`). Seed cocok dengan tabel Bagian 2.4.
9. **`riwayat_alamat`:** `KdPos` adalah salinan `kd_pos` yang ditulis legacy (`L_alamat.php:612, 615`); jenis 3 (Kantor)
   memaksa `alamat_utama = 2` dan mengisi kolom kantor (`:669-675`), serta lampiran tidak wajib (`:504, 565, 575`);
   wilayah LAIN-LAIN 99/9999/9999999/9999999999 + kolom `*_lain` (`:508-555`, `:630-667`), sentinel sudah ada di `main`
   (DBV-003). `jenis_alamat` wajib di form (`:499-502`) → NOT NULL [I] (K4-8). Keputusan `KdPos`: K4-10.
10. **Aturan duplikat di aplikasi, bukan UNIQUE:** tanda jasa dengan `tgl_sertifikat` sama per `nip` (status bukan 2/10)
    ditolak (`L_tj.php:453-460, 476-480`); organisasi dengan `nama_organisasi` + `tgl_mulai` (+ `tgl_akhir` atau NULL)
    sama per `nip` ditolak (`L_organisasi.php:453-470, 487-503`). Baris ditolak/dihapus boleh berulang → tidak ada UNIQUE.
11. **ID master yang di-hard-code legacy** (wajib ada di impor master G-06, kalau tidak FK menolak riwayat):
    `tanda_jasa` 44 = LAIN-LAIN (`tj/form.php:100`, `L_tj.php:501`), 26/27/28 = Satyalancana Karya Satya (DRH
    `L_employee.php:10807-10882`, sinkron SIASN `siasn_sync/Rw_tj.php:325, 393`).
12. **`siasn_flag` tanda jasa memakai domain sendiri:** 0 belum (filter `siasn_flag='0'`, `Rw_tj.php:393`), 2 sedang
    diproses (`:420-424`) atau gagal + `siasn_error` (`:520-525`), 1 sukses (`:455-460, 577-581`), 3 fallback manual saat
    transaksi gagal (`:560-564`). Hanya COMMENT, tanpa CHECK. Tipe `TINYINT` (pola [L] `pegawai.siasn_flag tinyint`, sama
    dengan kelompok 3); kelompok 2 semula memakai `TINYINT(1)`; integrasi menyeragamkan ke `TINYINT` (1.5 #3, K4-12).
13. **Hapus legacy = hapus keras** di semua tabel kelompok 4 (`L_keluarga.php:640, 772, 833`, `L_karpeg.php:736`,
    `L_kariskarsu` `delete()` `:799+`, `L_tj`/`L_organisasi` `delete()`), dengan lampiran dihapus eksplisit. Status 10 tetap
    ada di domain karena daftar legacy memfilter `!= '10'` / `NOT IN ('2','10')`.

## 2. Skema per tabel

### 2.0 Konvensi bersama (berlaku untuk semua tabel B-01/B-02)

#### 2.0.1 NIP

- Semua kolom NIP: `VARCHAR(30)`, collation diwarisi tabel (`utf8mb4_unicode_ci`), **NOT NULL** bila ber-FK ke `pegawai.nip` (kecuali `pengguna.nip`, K2).
- Nama kolom ikut legacy: `nip`, `NIP` (`document_attachment` [K], huruf besar), `nip_plt`, `nip_plh`, `nip_atasan`.
- Deviasi yang dicatat: stub [L] riwayat `nip DEFAULT NULL` → NOT NULL; `ignore_konv_ak.nip` VARCHAR(50) [K] → 30.
- Kolom NIP tanpa FK di legacy tetap tanpa FK dan wajib masuk daftar "NIP non-FK" (2.0.8).

#### 2.0.2 Opsi tabel & tipe kolom rujukan

- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`. Kolom string **tanpa** COLLATE per kolom (mewarisi tabel; `DBCollat` `main` sudah unicode_ci dan `strictOn=true`). Tanpa `ROW_FORMAT` (legacy `ROW_FORMAT=DYNAMIC` = default MySQL 8/MariaDB 10.4) dan tanpa nilai `AUTO_INCREMENT` awal (G2).
- Tipe kolom FK ke master **sama persis** dengan PK di `main` (diperiksa dari migration `main`):

| Master (di `main`) | Tipe PK |
|---|---|
| `agama`, `jenis_pegawai`, `jenis_status`, `pangkat`, `jenis_kp`, `gol_pppk`, `diklat`, `bidang_pendidikan` | TINYINT (signed) |
| `jenjang_pendidikan`, `jurusan_pendidikan`, `tingkat_hukdis`, `jenis_hukdis`, `tanda_jasa`, `jenis_konket` | INT (signed) |
| `provinsi` / `kabupaten_kota` / `kecamatan` / `kelurahan` | CHAR(2) / CHAR(4) / CHAR(7) / CHAR(10) |

- Ekspektasi tipe PK G-02 (cocokkan dengan penulis DBV-008 sebelum migration FK G-02 ditulis): `jabatan`, `group_jabatan` INT [K]; `sub_group_jabatan`, `unit`, `satker`, `jabatan_koordinasi` INT [L pmj]; `rumpun_jabatan` TINYINT [L pmj]. `jabatan_koordinasi` dan `rumpun_jabatan` belum ada di draf DBV-008 (4.1 #9).

#### 2.0.3 Status & blok workflow riwayat

- Riwayat [I]: `status TINYINT NOT NULL DEFAULT 0 COMMENT '0: Menunggu, 1: Disetujui, 2: Ditolak, 10: Dihapus'` + `CONSTRAINT chk_<tabel>_status CHECK (status IN (0,1,2,10))` [I, pola K-m; deviasi dari stub [L] VARCHAR(5) DEFAULT '1'].
- Riwayat yang form admin legacy-nya menulis **3 "Diproses"** (`riwayat_mutasi_jabatan`, `riwayat_kp`, `riwayat_kgb`, `riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar`, `riwayat_skp`, `riwayat_hukdis`, `riwayat_keluarga`, `riwayat_tanda_jasa`, `riwayat_organisasi`): COMMENT `'0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus'` + CHECK `IN (0,1,2,3,10)` (1.5 #2, 4.1 #2).
- Pengecualian status:
  - `riwayat_lckh.status INT NOT NULL DEFAULT 0` [K-m] + CHECK `IN (0,1,2,3,10)`.
  - `absen_ijin.status CHAR(2) NOT NULL DEFAULT 'W'` [K] + CHECK `IN ('W','V','X','10')`.
  - `pegawai_plt` / `pegawai_plh`: 1 Aktif / 2 Tidak Aktif (`views/hr/officer/plt/form.php:24-26`) + CHECK `IN (1,2,10)`.
- Blok workflow standar (WF) untuk tabel [I] di daftar 1.2 #5:
  - `reason_note TEXT NULL`
  - `approved_by INT NULL`
  - `show_notif TINYINT NOT NULL DEFAULT 0`
  - `notif_date DATETIME NULL`
  - `show_ua_upt TINYINT NULL`, `show_ua_deputi TINYINT NULL`, `show_ua_biro TINYINT NULL`
- Tabel berlabel [K]/[K-m] (`absen_ijin`, `riwayat_lckh`) **tidak** memakai blok WF standar; kolom workflow-nya persis DDL (mis. `absen_ijin.show_notif int DEFAULT '0'`, `reason_note mediumtext`).
- Pengecualian blok WF: `riwayat_ak` memakai `appr_at DATETIME` + `appr_by INT` + `created_by` (kode); `riwayat_karpeg`/`riwayat_kariskarsu` hanya `show_ua_biro` + `rated`; `riwayat_hukdis` tanpa proses approval di legacy.
- ⚠️ **Catatan pra-review (keputusan 4.1 #3):** semua bukti DDL untuk kolom workflow memakai **INT**, bukan TINYINT: `absen_ijin` [K] `show_notif int DEFAULT '0'`, `show_ua_* int DEFAULT '0'`, `reason_note mediumtext`; `d_riwayat_cuti` [K-m] `show_notif int DEFAULT NULL`, `reason_note text`; `d_lkh` [K-m] `show_notif int NOT NULL DEFAULT '0'`. Preseden G-06 D2 (tabel [I] meniru tabel [K] terdekat) mengarah ke `show_notif INT NULL DEFAULT 0` dan `show_ua_* INT NULL DEFAULT 0`. Draf memakai nilai di atas (TINYINT) di semua tabel riwayat [I] dan `pegawai_hist`.
- `siasn_flag` riwayat: `TINYINT NOT NULL DEFAULT 0` + COMMENT domain per modul, tanpa CHECK (1.5 #3).

#### 2.0.4 Kolom audit

- Tabel [K]/[K-m]/[L]: persis seperti DDL.
- Tabel riwayat [I]: `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL COMMENT 'id_pengguna yang terakhir mengubah'`. Sumber [I, pola `absen_ijin`/`d_lkh`/`d_riwayat_cuti`]; `created_at` tidak ada di stub [L] sehingga dicatat [I].
- Snapshot: hanya `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` [L `pegawai_kp`]; tanpa `*_by`, `status`, atau kolom workflow.
- Semua `*_by` = `id_pengguna` **tanpa FK** (preseden DBV-001/010; A-01 D-12). Nilai ditulis aplikasi dalam UTC; default DB hanya cadangan (catatan zona waktu ISSUE-022, 6.5).

#### 2.0.5 Aksi FK

- Semua FK `ON DELETE RESTRICT ON UPDATE RESTRICT` (K1: B-06 = salin → arahkan ulang → hapus).
- Deviasi dari legacy yang dicatat (Bagian 3): FK `nip` [K]/[L] `CASCADE/CASCADE` → RESTRICT; FK master [L] `SET NULL/CASCADE` → RESTRICT; `detail_anak` [K] `CASCADE` → RESTRICT (service menghapus anak dan lampiran secara eksplisit).
- Nama FK = **nama legacy ERD persis**. FK baru [V2] mengikuti gaya legacy `fk_<kolom>_<singkatan tabel>_to_<singkatan induk>`.
- Nama FK dan CHECK unik **per schema** (InnoDB), ≤ 64 karakter, dan **tanpa** prefix DB (`t_` hanya untuk nama tabel).

#### 2.0.6 Penamaan index & constraint

- KEY untuk kolom FK dinamai sama dengan FK-nya dan dibuat di CREATE TABLE, **walaupun FK baru ditambahkan belakangan** (snapshot → riwayat, G-02), sehingga migration FK cukup `ADD CONSTRAINT`.
- Pengecualian: kolom FK yang sudah tercakup index lain di `main` tidak diberi KEY baru (`pengguna.nip` memakai UNIQUE `nip` yang ada; `faq_rate.nip` sudah punya KEY `fk_nip_faqrate_to_peg`).
- KEY legacy [K]/[L] tetap memakai nama legacy (`nip`, `status`, `date_start`, `show_notif`, `affect_tukin`, `id_riwayat_id_entri`, `fk_NIP_da_to_pegawai`).
- KEY baru [V2]: `idx_<tabel>_<kolom>`. UNIQUE: `uq_<tabel>_<nama>`. CHECK: `chk_<tabel>_<kolom|aturan>`.
- CHECK hanya untuk domain yang pasti dari kode: status (2.0.3), `flag_update`, `jenis_kelamin` 1/2, `affect_tukin` 1/2, `jenis_dinas` NULL/0/1, `jenis_diklat` 1-5. Kolom seperti `status_pernikahan` tidak diberi CHECK sebelum audit data.

#### 2.0.7 Keranjang FK & urutan migration

| Keranjang | Isi | Dipasang di |
|---|---|---|
| (a) MAIN | Target sudah ada di `main` (2.0.2) | CREATE TABLE |
| (b) PEG | Ke `pegawai.nip` | CREATE TABLE (DBV-012 langsung; DBV-013 juga langsung karena di-branch di atas DBV-012) |
| (c) RWY | Snapshot → riwayat | Migration terpisah `131000_AddFkSnapshotKeRiwayat` (DBV-013) setelah semua riwayat dibuat, dengan pra-cek orphan |
| (d) G02 | Ke tabel G-02 | `129900_AddFkG02Pegawai` (DBV-012) dan `139900_AddFkG02Riwayat` (DBV-013), **tidak ikut PR B-01/B-02**; disimpan di branch lokal `dbv-012/fk-ditahan` sampai DBV-008 merge; timestamp ditetapkan ulang agar lebih besar dari migration G-02 (Bagian 7) |
| (e) FK masuk | `pengguna.nip`, `faq_rate.nip` → `pegawai` (tabel yang sudah di `main`) | `120200_AddFkPegawaiDiPenggunaFaqRate`, **ditahan** (1.5 #1, Bagian 7) |

Konsekuensi: DoD B-01 "FK aktif ke seluruh master Tier 1" baru penuh setelah DBV-008 merge (1.4 #3).

#### 2.0.8 Registry kolom NIP (landasan B-06)

`NipReferenceRegistryTest` (kelompok 1) mencocokkan daftar FK yang merujuk `t_pegawai(nip)` di `information_schema.KEY_COLUMN_USAGE` dengan konstanta ekspektasi. Nama kolom dibandingkan tanpa peka huruf (`NIP` di `document_attachment`).

| Jenis | Kolom | Sumber |
|---|---|---|
| FK → `pegawai.nip` (DBV-012, 15) | `pegawai_hist.nip`, `pegawai_foto.nip`, `nip` 13 snapshot | `120000`, `120100` |
| FK → `pegawai.nip` (DBV-013, 24) | `riwayat_mutasi_jabatan.nip`, `pegawai_plt.nip_plt`, `pegawai_plh.nip_plh`, `riwayat_kp/kgb/pendidikan/diklat/seminar/skp.nip`, `riwayat_lckh.nip`, `riwayat_lckh.nip_atasan` (nullable), `absen_ijin.nip`, `riwayat_hukdis/ak/ak_siasn.nip`, `konv_ak.nip`, `ignore_konv_ak.nip`, `riwayat_keluarga/alamat/karpeg/kariskarsu/tanda_jasa/organisasi.nip`, `document_attachment.NIP` | `130100`..`130900` |
| FK ditahan (tercatat NON_FK sampai aktif) | `pengguna.nip` (nullable, K2), `faq_rate.nip` | `120200` (Bagian 7) |
| NIP non-FK (tetap tanpa FK) | `riwayat_skp.nip_penilai`, `riwayat_skp.nip_atasan_penilai`, `riwayat_skp_periodik.nip`, `riwayat_skp_periodik.pegawai_atasan_nip` | legacy tanpa FK / data API BKN |
| NIP jejak di `main` (tanpa FK, keputusan A-01) | `token.nip` (D-7), `audit_logs.nip_actor` (D-8) | `main` |
| Bukan rujukan | `pegawai.nip_lama` (NIP lama, data historis) | [L] |

B-06 memutuskan perlakuan kolom non-FK dan jejak saat NIP diganti; skema hanya mendokumentasikannya.

#### 2.0.9 Singkatan

Singkatan: WF = blok workflow 2.0.3; AUD = audit 2.0.4; MAIN/PEG/RWY/G02 = keranjang 2.0.7.

### 2.1 Kelompok 1 — DBV-012 (B-01)

Opsi semua tabel: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, kolom string tanpa COLLATE per
kolom, tanpa `ROW_FORMAT` dan tanpa nilai AUTO_INCREMENT awal (2.0.2). Semua FK `ON DELETE RESTRICT ON UPDATE RESTRICT`.

#### 2.1.1 pegawai — [L] `simpeg_prod_duplikat` (DDL lengkap) + nama FK [K-erd]; seed rekonstruksi :1104-1157

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `nip` | VARCHAR(30) NOT NULL | [L]; PK K1 |
| `id_provinsi_lahir` | CHAR(2) NULL | [L]; = PK `provinsi` di `main` |
| `id_kabupaten_kota_lahir` | CHAR(4) NULL | [L]; = PK `kabupaten_kota` |
| `id_agama`, `id_jenis_pegawai`, `id_jenis_status` | TINYINT NULL | [L]; = PK master DBV-001 (TINYINT signed) |
| `nip_lama` | VARCHAR(18) NULL | [L] |
| `nama` | VARCHAR(100) NOT NULL | [L] |
| `glr_awal`, `glr_akhir` | VARCHAR(50) NULL | [L] |
| `tgl_lahir` | DATE NOT NULL | [L] |
| `provinsi_lahir`, `provinsi_lahir_lain`, `kabupaten_kota_lahir`, `kabupaten_kota_lahir_lain` | VARCHAR(255) NULL | [L]; teks turunan master / isian "lain" untuk kode sentinel 99/9999 (`set_param` :972, :978) |
| `agama` | VARCHAR(30) NULL | [L] |
| `jenis_kelamin` | TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Laki-laki, 2: Perempuan' | [L] |
| `npwp`, `nik` | VARCHAR(20) NULL | [L] |
| `bpjs_kes`, `bpjs_ket`, `no_taspen` | VARCHAR(50) NULL | [L] |
| `no_hp` | VARCHAR(20) NULL | [L] |
| `jenis_kerabat` | VARCHAR(100) NULL | [L] |
| `no_telp_kerabat` | VARCHAR(20) NULL | [L] |
| `status_pernikahan` | TINYINT(1) NULL COMMENT '1: Menikah, 2: Tidak Menikah' | [L]; tanpa CHECK sebelum audit data (2.0.6) |
| `jenis_pegawai` | VARCHAR(255) NULL | [L] |
| `jenis_status` | VARCHAR(100) NULL | [L] |
| `tmt_status` | DATE NULL | [L] |
| `foto` | VARCHAR(255) NULL | [L]; path `arsip/<nip>/umum/foto_<nip>_<waktu>.<ext>` (:378-379) |
| `keterangan` | TINYTEXT NULL | [L] |
| `status` | TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus' | [L]; COMMENT v2 (legacy "10: Deleted") |
| `flag_update` | TINYINT NOT NULL DEFAULT 0 COMMENT '0: Tidak ada pengajuan, 1: Diajukan, 2: Ditolak/Dibatalkan, 3: Disetujui' | [L]; COMMENT [V2] dari kode (A #2) |
| `id_pns_siasn` | VARCHAR(100) NULL | [L] |
| `siasn_flag` | TINYINT NULL DEFAULT 0 | [L] |
| `siasn_lu` | DATETIME NULL | [L] |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [L]; data bisnis (A #12) |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [L] |
| `updated_by` | INT NULL COMMENT 'id_pengguna yang terakhir mengubah' | [L]; COMMENT [V2]; tanpa FK (2.0.4) |
| `deleted_at` | DATETIME NULL | [L]; hapus = status 10 + `deleted_at` (B-05) |

Kunci: PRIMARY (`nip`); KEY `status` [L]; KEY bernama FK untuk 5 kolom master. FK MAIN [K-erd] (a):
`fk_id_provinsi_lahir_peg_to_prov` → `provinsi`, `fk_id_kabupaten_kota_lahir_peg_to_kab_kota` → `kabupaten_kota`,
`fk_id_agama_peg_to_agama` → `agama`, `fk_id_jenis_pegawai_peg_to_jenis_pegawai` → `jenis_pegawai`,
`fk_id_jenis_status_peg_to_jenis_status` → `jenis_status`. CHECK [V2]: `chk_pegawai_status` (1, 2, 10),
`chk_pegawai_flag_update` (0..3), `chk_pegawai_jenis_kelamin` (1, 2).
jenis_rwy: — (arsip data umum memakai kode 37 `status_du`/38 `arsip_du` tanpa `id_entri`).
Tanpa kolom `email` (ada di `pengguna`). Kode wilayah sentinel 99/9999 valid terhadap FK (seed DBV-003).

#### 2.1.2 pegawai_hist — [I] `L_employee.php:431-490, 1018-1023, 1085-1110, 1172-1178` + [K-erd]; seed :1201-1265

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_pegawai_hist` | INT NOT NULL AUTO_INCREMENT | [I] (dibaca `L_notification.php:1321`, `L_employee.php:87`) |
| `nip` | VARCHAR(30) NOT NULL | [I]; [V2] NOT NULL (stub [L] NULL) |
| `id_provinsi_lahir` .. `siasn_lu` (35 kolom) | identik 2.1.1, urutan sama | [I] salinan `$peg` (A #1) |
| └ `flag_update` | TINYINT NOT NULL DEFAULT 1 COMMENT '1: Diajukan, 2: Ditolak/Dibatalkan, 3: Disetujui' | [I] :445, :1175; [V2] DEFAULT 1 |
| `email` | VARCHAR(150) NULL COMMENT | [I] :476; lebar = `pengguna.email` (DBV-010) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [I] (insert tanpa `created_at`, :487-489) |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [I] |
| `updated_by` | INT NULL COMMENT | [I] :1103 |
| `approved_by` | INT NULL COMMENT 'id_pengguna yang memproses (setuju/tolak)' | [I] :477, :1104 |
| `reason_note` | MEDIUMTEXT NULL | [I] :478, :1105; tipe pola [K] `absen_ijin.reason_note` |
| `show_notif` | TINYINT NOT NULL DEFAULT 0 | [I] :479; blok WF 2.0.3 (INT vs TINYINT = 4.1 #3) |
| `notif_date` | DATETIME NULL | [I] `L_notification.php:3401` |
| `show_ua_upt`, `show_ua_deputi`, `show_ua_biro` | TINYINT NULL | [I] :480-482, :1106-1108 (nilai '0'/'1'/NULL) |

Kunci: PRIMARY (`id_pegawai_hist`); KEY `nip`, `flag_update`, `show_notif` (`show_notif`, `notif_date`) [I seed]; KEY
bernama FK `fk_peg_hist_ibfk_02`..`_06`. FK [K-erd]: PEG (b) `fk_peg_hist_ibfk_01` (`nip` → `pegawai`, memakai KEY `nip`);
MAIN (a) `_02` provinsi, `_03` kabupaten_kota, `_04` agama, `_05` jenis_pegawai, `_06` jenis_status. CHECK [V2]:
`chk_pegawai_hist_flag_update` (1..3), `chk_pegawai_hist_status` (1, 2, 10), `chk_pegawai_hist_jenis_kelamin` (1, 2)
— dua terakhir karena baris hist disalin ke `pegawai` saat disetujui (gagal lebih awal).
jenis_rwy: — . Impor: hanya `flag_update = 1` (Mapping Tier 3).

#### 2.1.3 pegawai_foto — [K-erd] `fk_nip_pegfoto_to_peg`; [I] `L_employee.php:44`, `views/hr/employee/form.php:891-895`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_pegawai_foto` | INT NOT NULL AUTO_INCREMENT | [I] |
| `nip` | VARCHAR(30) NOT NULL | [I] + [K-erd] |
| `foto` | VARCHAR(255) NOT NULL COMMENT 'path berkas foto (format = pegawai.foto)' | [I]; tipe = `pegawai.foto` [L] |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [V2] |

Kunci: PRIMARY (`id_pegawai_foto`); KEY `fk_nip_pegfoto_to_peg`; FK PEG (b) `fk_nip_pegfoto_to_peg`. Banyak baris per nip.

#### 2.1.4 Snapshot `pegawai_*` (13 tabel) — aturan umum

PK `nip` = FK PEG (b); `id_riwayat_*` INT NULL + KEY bernama FK RWY (FK-nya di `131000`); kolom bisnis salinan riwayat;
hanya `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP [L `pegawai_kp`]; tanpa
`status`, `*_by`, blok WF, maupun CHECK (domain ditegakkan di riwayat). Satu baris per nip (ADR-006).

| Tabel | Kolom (urut, setelah `nip`; tanpa `updated_at`) | Sumber | PEG | MAIN (a) | RWY (131000) | G02 (ditahan) |
|---|---|---|---|---|---|---|
| `pegawai_kp` | `id_riwayat_kp` INT, `id_jenis_kp` TINYINT, `id_pangkat` TINYINT, `tmtsk` DATE NN, `tgl_sk` DATE NN, `no_sk` V100, `tgl_pertek_bkn` DATE, `no_pertek_bkn` V100, `jumlah_kredit_utama`/`_tambahan` DOUBLE, `jenis_kp` V100 NN, `gol`/`ruang`/`gol_ruang` V10 NN, `pangkat` V50 NN, `mker_th`/`mker_bl` TINYINT, `gaji_pokok` INT, `keterangan` TINYTEXT, `id_rw_siasn` V100 | [L] persis | `fk_nip_pegkp_to_pegawai` | `fk_id_jenis_kp_peg_kp_to_jenis_kp`, `fk_id_pangkat_peg_kp_to_pangkat` | `fk_id_riwayat_kp_peg_kp_to_rwy_kp` | — |
| `pegawai_cpns` | = `pegawai_kp` | [K-erd] + [I] (A #4) | `fk_nip_pcpns_to_pegawai` | `fk_id_jenis_kp_pcpns_to_jenis_kp`, `fk_id_pangkat_pcpns_to_pangkat` | `fk_id_riwayat_kp_pcpns_to_rwy_kp` | — |
| `pegawai_pns` | = `pegawai_kp` | [K-erd] + [I] (A #4) | `fk_nip_ppns_to_pegawai` | `fk_id_jenis_kp_ppns_to_jenis_kp`, `fk_id_pangkat_ppns_to_pangkat` | `fk_id_riwayat_kp_ppns_to_rwy_kp` | — |
| `pegawai_kgb` | `id_riwayat_kgb` INT, `id_gol_pppk` TINYINT, `tmtsk` DATE NN, `no_sk` V100, `tgl_sk` DATE, `mker_gol_th` TINYINT, `gaji_pokok` INT, `jym` V255 COMMENT, `keterangan` TINYTEXT | [I] `L_kgb.php:508-527`; dibaca `L_employee.php:4102, 8439` | `fk_nip_pegkgb_to_pegawai` | `pegawai_kgb_ibfk_01` (gol_pppk) | `fk_id_riwayat_kgb_pkgb_to_rkgb` | — |
| `pegawai_pendidikan` | `id_riwayat_pendidikan` INT, `id_jenjang_pendidikan` INT, `id_bidang_pendidikan` TINYINT, `id_jurusan_pendidikan` INT, `tgl_lulus` DATE, `no_ijazah`/`no_sk_penc_glr` V100, `institusi_pendidikan` V255, `jenjang_pendidikan_singkat` V50, `bidang_pendidikan` V100, `bidang_pendidikan_lain`/`jurusan_pendidikan`/`jurusan_pendidikan_lain` V255, `nem`/`ipk` DOUBLE, `keterangan` TINYTEXT, `id_rw_siasn` V100 | [I] seed; dibaca `Tester.php:3724`, `L_employee.php:2473`, simapi `A_employee.php:280-281, 775` | `fk_nip_pegpend_to_pegawai` | `fk_id_jenjang_pendidikan_pegpend_to_jpend`, `fk_id_bidang_pendidikan_pegpend_to_bpend`, `fk_id_jurusan_pendidikan_pegpend_to_jupend` | `fk_id_riwayat_pendidikan_pegpend_to_rpend` | — |
| `pegawai_diklat` | `id_riwayat_diklat` INT, `id_diklat` TINYINT, `jenis_diklat` TINYINT(1) COMMENT, `nama_diklat` V255, `sub_group_jabatan` V100, `tgl_sertifikat` DATE, `no_sertifikat` V100, `instansi_penyelenggara` V255, `deskripsi` TEXT, `jumlah_jp` SMALLINT, `keterangan` TEXT | [I] `L_diklat.php:626-680`; dibaca `Tester.php:3723`, simapi `A_employee.php:939-970` (`pdik.*`) | `fk_nip_pegdiklat_to_pegawai` | `fk_id_diklat_pegdiklat_to_diklat` | `fk_id_riwayat_diklat_pegdiklat_to_rwydiklat` | — |
| `pegawai_hukdis` | `id_riwayat_hukdis` INT, `id_tingkat_hukdis` INT, `tingkat_hukdis` V100, `id_jenis_hukdis` INT, `jenis_hukdis` V255, `id_pangkat` TINYINT, `gol`/`ruang`/`gol_ruang` V10, `pangkat` V50, `no_sk` V100, `tgl_sk` DATE, `tmtsk` DATE, `masa_hukuman` TEXT, `akhir_hukdis` DATE, `aturan_dilanggar`/`alasan_hukuman`/`keterangan` TEXT | [I] `L_hukdis.php:463-500`; dibaca `Tester.php:3729` | `fk_nip_peghukdis_to_pegawai` | `fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis`, `fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis`, `fk_id_pangkat_peg_hukdis_to_pangkat` | `fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis` | — |
| `pegawai_ak` | `id_riwayat_ak` INT, `id_jabatan` INT, `id_pangkat` TINYINT, `jenis_ak` TINYINT, `no_hpak` V100, `tgl_pak` DATE, `periode_awal`/`periode_akhir` DATE, `nilai_ak` DECIMAL(8,3), `file_pak` V255 | [I] `L_ak.php:48, 359`, `S_stat.php:624` | `fk_nip_cak_to_peg` | `fk_id_pangkat_cak_to_pangkat` | `fk_id_riwayat_ak_cak_to_rak` | `fk_id_jabatan_cak_to_jab` |
| `pegawai_keluarga` | `id_riwayat_keluarga` INT, `urutan_perkawinan` TINYINT, `tgl_perkawinan` DATE, `kota_perkawinan`/`nama_pasangan` V255, `jumlah_anak` TINYINT NN DEFAULT 0, `keterangan` TINYTEXT | [I] `L_keluarga.php:1035-1050` | `fk_nip_pegkeluarga_to_pegawai` | — | `fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga` | — |
| `pegawai_alamat` | `id_riwayat_alamat` INT, `jenis_alamat` TINYINT(1) COMMENT '1: KTP, 2: Domisili, 3: Kantor', `id_provinsi` CHAR(2), `id_kabupaten_kota` CHAR(4), `id_kecamatan` CHAR(7), `id_kelurahan` CHAR(10), `provinsi`, `provinsi_lain`, `kabupaten_kota`, `kabupaten_kota_lain`, `kecamatan`, `kecamatan_lain`, `kelurahan`, `kelurahan_lain` V255, `kd_pos` V10, `alamat` TEXT | [I] `Tester.php:3730`, `L_employee.php:5899-5903, 6974` | `fk_nip_pegalamat_to_pegawai` | `fk_id_provinsi_pegalamat_provinsi`, `fk_id_kabupaten_kota_pegalamat_kabkota`, `fk_id_kecamatan_pegalamat_kecamatan`, `fk_id_kelurahan_pegalamat_kelurahan` | `fk_id_riwayat_alamat_pegalamat_riwalamat` | — |
| `pegawai_alamat_kantor` | = `pegawai_alamat` + `nama_kantor` V255, `lantai`/`telp`/`faks` V50 | [I] `L_employee.php:5903-5916`, simapi `A_employee.php:510` | `pegawai_alamat_kantor_ibfk_6` | `_ibfk_1` kab/kota, `_ibfk_2` kecamatan, `_ibfk_3` kelurahan, `_ibfk_4` provinsi | `pegawai_alamat_kantor_ibfk_5` | — |
| `pegawai_tanda_jasa` | `id_riwayat_tanda_jasa` INT, `id_tanda_jasa` INT, `tanda_jasa`/`tanda_jasa_lain` V255, `tgl_sertifikat` DATE, `no_sertifikat` V100, `negara` V100, `keterangan` TEXT | [I] `L_tj.php:490-505`; dibaca `Tester.php:3727`, simapi `A_employee.php:2112-2131` (`ptj.*`) | `fk_nip_pegtj_to_pegawai` | `fk_id_tanda_jasa_pegtj_to_tj` | `fk_id_riwayat_tanda_jasa_ptj_to_rtj` | — |
| `pegawai_mutasi_jabatan` | 52 kolom [L] persis (lihat 2.1.5) | [L] persis; seed :1536-1613 | `fk_nip_pmj_to_pegawai` | `pegawai_mutasi_jabatan_ibfk_01` (gol_pppk) | `fk_id_riwayat_mutasi_jabatan_pmj_to_rmj` | 13 FK (A #6) |

Semua kolom tabel di atas NULL kecuali yang ditandai NN. KEY: satu KEY per kolom FK (MAIN, RWY, G02) bernama FK-nya;
kolom PEG memakai PRIMARY.

#### 2.1.5 pegawai_mutasi_jabatan — [L] `simpeg_prod_duplikat` persis

Kolom (urut [L]): `nip` V30 NN, `id_riwayat_mutasi_jabatan`, `id_group_jabatan`, `id_sub_group_jabatan`, `id_unit`,
`id_satker`, `id_atasan_es_1`, `id_atasan_es_2`, `id_atasan_es_3`, `id_atasan_es_3_koord`, `id_atasan_es_4`,
`id_atasan_es_4_koord`, `id_jabatan`, `id_jabatan_koord` INT NULL; `id_gol_pppk`, `id_rumpun_jabatan` TINYINT NULL;
`jenis_jabatan` TINYINT(1) NN DEFAULT 1; `jenis_jabatan_koord` TINYINT(1) NN DEFAULT 3; `jenis_mutasi` TINYINT(1) NN
DEFAULT 1; `tmtsk` DATE NN; `no_sk` V100; `tgl_sk` DATE; `pmk` YEAR; `mhpk_mulai`, `mhpk_akhir` DATE; `gol_pppk` V10;
`nama_instansi` V255; `group_jabatan` V45; `sub_group_jabatan` V100; `unit`, `satker`, `atasan_es_1`, `atasan_es_2`,
`atasan_es_3`, `atasan_es_3_koord`, `atasan_es_4`, `atasan_es_4_koord`, `jabatan`, `jabatan_koord`, `jabatan_lain` V255;
`rumpun_jabatan` V50; `subrumpun_jabatan` TEXT; `kelas_jabatan` TINYINT; `kredit_jft` INT; `no_induk_jft` V50;
`status_jft` TINYINT(1); `ser_dosen`, `f_spmt`, `f_bapel` V255; `keterangan` MEDIUMTEXT; `id_rw_siasn` V100;
`updated_at` DATETIME NN DEFAULT CURRENT_TIMESTAMP ON UPDATE.

Kunci: PRIMARY (`nip`); KEY [L] `fk_id_unit_pmj_to_unit`, `fk_id_satker_pmj_to_satker`; KEY [V2 nama K-erd]
`fk_id_riwayat_mutasi_jabatan_pmj_to_rmj`, `fk_id_group_jabatan_pmj_to_gj`, `fk_id_sub_group_jabatan_pmj_to_sgj`,
`fk_id_jabatan_pmj_to_jabatan`, `fk_id_atasan_es_1_pmj_to_jabatan` .. `_es_4_`, `fk_id_jabatan_koord_pmj_to_jabkoor`,
`fk_id_atasan_es_3_koord_pmj_to_jabkoor`, `fk_id_atasan_es_4_koord_pmj_to_jabkoor`, `pegawai_mutasi_jabatan_ibfk_01`,
`pegawai_mutasi_jabatan_ibfk_02`. FK dipasang di sini: `fk_nip_pmj_to_pegawai` (b), `pegawai_mutasi_jabatan_ibfk_01`
→ `gol_pppk` (a). `kelas_jabatan` tanpa FK (ERD tidak mencatat). `id_unit`/`id_satker` = dasar lingkup Admin Satker.
[E] etalenta memuat `id_subrumpun_jabatan` — tidak dibuat sampai dikonfirmasi SHOW CREATE TABLE.

#### 2.1.6 FK masuk — `120200_AddFkPegawaiDiPenggunaFaqRate` (DITAHAN, Bagian 7)

> Catatan integrasi (1.5): migration ini **tidak** ada di folder `Migrations` branch draf (1.5 #1, 4.1 #12). Rancangannya tetap
> seperti di bawah dan diaktifkan setelah prasyarat Bagian 7 terpenuhi.

| FK | Anak → induk | Index yang dipakai | Asal |
|---|---|---|---|
| `fk_id_pegawai_pengguna_to_pegawai` [K-erd] | `pengguna.nip` (NULL untuk akun non-pegawai, K2) → `pegawai.nip` | UNIQUE `nip` yang sudah ada | A-01 #4 |
| `fk_nip_faqrate_to_peg` [K-erd] | `faq_rate.nip` → `pegawai.nip` | KEY `fk_nip_faqrate_to_peg` (DBV-002) | G-10 D2; legacy CASCADE → RESTRICT |

`up()`: (1) pra-cek orphan per tabel — baris ber-NIP yang tidak ada di `pegawai` (termasuk akun soft-deleted) → berhenti
dengan jumlah per tabel sebelum ALTER apa pun; (2) ADD CONSTRAINT hanya bila belum ada; (3) bila ALTER kedua gagal,
FK pertama yang dipasang pada run itu dilepas lagi. `down()`: DROP FOREIGN KEY hanya bila ada (index lama tetap).
Idempoten dua arah — perlu karena test FAQ me-recreate `faq_rate` tanpa FK lalu regress memanggil `down()` ini.

#### 2.1.7 FK snapshot → riwayat — `131000_AddFkSnapshotKeRiwayat` (file DBV-013)

13 FK RWY dari tabel 2.1.4 (kolom `id_riwayat_*` INT NULL → PK INT riwayat), nama [K-erd], RESTRICT/RESTRICT (legacy seed/[L]
SET NULL/CASCADE). `up()`: pra-cek semua tabel & kolom induk ada (pesan daftar yang kurang), pra-cek orphan per FK, lalu
ADD CONSTRAINT (lewati yang sudah ada; rollback FK yang dipasang pada run itu bila gagal). `down()`: DROP FOREIGN KEY
bila ada; KEY tetap (milik 120100). Konsekuensi RESTRICT: riwayat yang masih menjadi snapshot tidak bisa dihapus keras
(v2 menghapus riwayat lunak, status 10); penghapusan keras (B-06/pembersihan) wajib mengosongkan/menyinkronkan snapshot
dulu.

#### 2.1.8 Registry kolom NIP (isi 2.0.8)

`NipReferenceRegistryTest` memeriksa: (1) FK yang merujuk `t_pegawai(nip)` = registry per versi migration yang sudah
jalan; (2) setiap FK itu RESTRICT/RESTRICT, kolom VARCHAR(30) unicode_ci, NOT NULL kecuali daftar NULLABLE; (3) setiap
kolom bernama NIP (`nip`, `NIP`, `nip_*`, `*_nip`, `*_nip_*`) di tabel aplikasi terklasifikasi sebagai FK atau NON_FK —
kolom NIP baru tanpa klasifikasi membuat test gagal (pagar untuk B-06 dan tabel Fase 4+ seperti `nip_atasan_langsung`,
`nip_kt`, `nip_yang_menyetujui` di C-01).

| Jenis | Kolom | Versi |
|---|---|---|
| FK PEG (DBV-012, 15) | `pegawai_hist.nip`, `pegawai_foto.nip`, 13 snapshot `.nip` | 120000, 120100 |
| FK ditahan (tercatat NON_FK sampai `120200` aktif) | `pengguna.nip` (nullable, K2), `faq_rate.nip` | 120200 (Bagian 7) |
| FK PEG (DBV-013, 24) | `riwayat_mutasi_jabatan.nip`, `pegawai_plt.nip_plt`, `pegawai_plh.nip_plh` (130100); `riwayat_kp.nip`, `riwayat_kgb.nip` (130200); `riwayat_pendidikan/diklat/seminar.nip` (130300); `riwayat_skp.nip`, `riwayat_lckh.nip`, `riwayat_lckh.nip_atasan` (130400); `absen_ijin.nip` (130500); `riwayat_hukdis/ak/ak_siasn.nip`, `konv_ak.nip`, `ignore_konv_ak.nip` (130600); `riwayat_keluarga/alamat.nip` (130700); `riwayat_karpeg/kariskarsu/tanda_jasa/organisasi.nip` (130800); `document_attachment.NIP` (130900) | per versi |
| FK boleh NULL | `riwayat_lckh.nip_atasan` ([K-m] `d_lkh` DEFAULT NULL); `pengguna.nip` (K2) saat `120200` aktif | — |
| NIP non-FK | `riwayat_skp.nip_penilai`, `riwayat_skp.nip_atasan_penilai` (penilai bisa di luar instansi); `riwayat_skp_periodik.nip`, `riwayat_skp_periodik.pegawai_atasan_nip` (data API BKN) | 130400 |
| Jejak di `main` (tanpa FK, A-01) | `token.nip` (D-7), `audit_logs.nip_actor` (D-8) | main |
| Bukan rujukan | `pegawai.nip` (PK), `pegawai.nip_lama`, `pegawai_hist.nip_lama` | 120000 |

Nama FK DBV-013 di registry = nama di migration kelompok 2-4 (dicocokkan saat integrasi, test lolos). Saat PR dipecah, versi 130xxx tetap di konstanta (hanya dicek bila versinya sudah jalan).

### 2.2 Kelompok 2 — DBV-013 (jabatan, Plt/Plh, KP, KGB, pendidikan, diklat, seminar)

Blok **WF+AUD** (sama untuk keenam riwayat, urutan kolom di akhir tabel):
`status` TINYINT NOT NULL DEFAULT 0 COMMENT '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus' (CHECK
`IN (0, 1, 2, 3, 10)`, 1.5 #2) ·
`reason_note` TEXT NULL · `approved_by` INT NULL · `show_notif` TINYINT NOT NULL DEFAULT 0 · `notif_date` DATETIME NULL ·
`show_ua_upt` / `show_ua_deputi` / `show_ua_biro` TINYINT NULL · `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ·
`updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP · `updated_by` INT NULL.
Sumber: `status`/`show_notif` stub [L] (tipe status → [V2] 2.0.3), `reason_note`/`approved_by`/`show_ua_*` [I]
(`set_param_process`/`sp_process` tiap library), `notif_date` [I] (`L_notification.php:3350-3425`), AUD [I] 2.0.4.
`show_ua_*` diisi 1 sesuai level penyetuju: biro (UserLevel 1), UPT (UserLevel 3 + `id_satker`), deputi (UserLevel 3
tanpa satker) — mis. `L_jabatan.php:2428-2448`.

#### 2.2.1 riwayat_mutasi_jabatan — stub [L] + kolom snapshot `pegawai_mutasi_jabatan` [L] + kode [I] `L_jabatan.php:2085-2319`, `siasn_sync/Rw_jabatan.php`; seed :1422-1510

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_mutasi_jabatan` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub `DEFAULT NULL` → NOT NULL [V2] |
| `id_group_jabatan` | INT NULL | [L] stub |
| `jenis_jabatan` | TINYINT(1) NULL COMMENT '1: Nomenklatur, 2: Instansi Lain, 3: Pelaksana Tugas (PLT)' | [L] stub (nullable, A #4); domain [I] `jabatan/form.php:80-84` |
| `id_sub_group_jabatan`, `id_unit`, `id_satker` | INT NULL | [L] pmj; [I] `set_param` |
| `id_atasan_es_1`, `id_atasan_es_2`, `id_atasan_es_3`, `id_atasan_es_3_koord`, `id_atasan_es_4`, `id_atasan_es_4_koord` | INT NULL | [L] pmj; [I] `L_jabatan.php:2169-2225` (nilai berawalan `koord_` → kolom `_koord`, `struk_` → kolom biasa) |
| `id_jabatan`, `id_jabatan_koord` | INT NULL | [L] pmj; [I] `:2249-2282` (satker 39 → `id_jabatan` NULL) |
| `id_gol_pppk` | TINYINT NULL | [L] pmj; [I] hanya PPPK |
| `id_rumpun_jabatan` | TINYINT NULL | [L] pmj; [I] `:2109, 2229` |
| `jenis_jabatan_koord` | TINYINT(1) NOT NULL DEFAULT 3 COMMENT '1: Koordinator, 2: Subkoordinator, 3: Tidak Ada' | [L] pmj (A #5); domain [I] `jabatan/form.php:232-243` |
| `jenis_mutasi` | TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Mutasi Jabatan, 2: Mutasi UNOR' | [L] pmj; domain [I] `jabatan/form.php:88-95`; diisi hanya bila `jenis_jabatan` 1 (`L_jabatan.php:2135`) |
| `tmtsk` | DATE NOT NULL | [L] pmj |
| `no_sk` | VARCHAR(100) NULL | [L] pmj |
| `tgl_sk` | DATE NULL | [L] pmj |
| `pmk` | YEAR NULL | [L] pmj |
| `mhpk_mulai`, `mhpk_akhir` | DATE NULL | [L] pmj |
| `gol_pppk` | VARCHAR(10) NULL | [L] pmj (= `gol_pppk.gol_pppk` VARCHAR(10) di `main`) |
| `nama_instansi` | VARCHAR(255) NULL | [L] pmj; [I] `web_config.nama_kementerian` atau isian instansi lain |
| `group_jabatan` | VARCHAR(45) NULL | [L] pmj (= master G-02 draf 45) |
| `sub_group_jabatan` | VARCHAR(100) NULL | [L] pmj (= master 100) |
| `unit`, `satker` | VARCHAR(255) NULL | [L] pmj (master G-02 draf 150) |
| `atasan_es_1`, `atasan_es_2`, `atasan_es_3`, `atasan_es_3_koord`, `atasan_es_4`, `atasan_es_4_koord` | VARCHAR(255) NULL | [L] pmj |
| `jabatan`, `jabatan_koord`, `jabatan_lain` | VARCHAR(255) NULL | [L] pmj (PLT: `"PLT " + nama jabatan`, master 250 + 4 ≤ 255) |
| `rumpun_jabatan` | VARCHAR(50) NULL | [L] pmj |
| `subrumpun_jabatan` | TEXT NULL COMMENT 'nama subrumpun jabatan, dipisah ***' | [L] pmj; format [I] `:2234-2247` |
| `kelas_jabatan` | TINYINT NULL | [L] pmj; tanpa FK (ERD) |
| `kredit_jft` | INT NULL | [L] pmj; tanpa titik tulis PHP di riwayat (A #6) |
| `no_induk_jft` | VARCHAR(50) NULL | [L] pmj |
| `status_jft` | TINYINT(1) NULL COMMENT '1: Aktif, 2: Pembebasan Sementara' | [L] pmj; domain [I] `jabatan/form.php:486-490` |
| `ser_dosen`, `f_spmt`, `f_bapel` | VARCHAR(255) NULL (path berkas) | [L] pmj; [I] `L_jabatan.php:1113, 1186, 1217` |
| `keterangan` | MEDIUMTEXT NULL | [L] pmj |
| `id_rw_siasn` | VARCHAR(100) NULL | [L] pmj; [I] `Rw_jabatan.php` |
| `siasn_flag` | TINYINT NOT NULL DEFAULT 0 COMMENT 'sinkron SIASN legacy: 0 belum, 1 sukses, 2 gagal, 3 proses, 4 dilewati' | [I] seed; `Rw_jabatan.php:142-143, 165, 272, 317` (A #7) |
| `siasn_error` | TEXT NULL | [I] `Rw_jabatan.php:273, 318` |
| `siasn_lu` | DATETIME NULL | [I] `Rw_jabatan.php:274, 319` |
| WF+AUD (11 kolom) | lihat awal 2.2 | [L]/[I]/[V2] |

Total 65 kolom. Kunci: PRIMARY (`id_riwayat_mutasi_jabatan`); 16 KEY bernama FK; FK PEG `fk_nip_rwymutasijabatan_to_pegawai` →
`pegawai(nip)` RESTRICT/RESTRICT [K-erd] (b); FK MAIN `riwayat_mutasi_jabatan_ibfk_01` → `gol_pppk(id_gol_pppk)` RESTRICT/RESTRICT
[K-erd] (a); **G02 ditahan (d), 13 FK**: `fk_id_group_jabatan_rwymj_to_gj`, `fk_id_sub_group_jabatan_rwymj_to_sgj`,
`fk_id_unit_rwymj_to_unit`, `fk_id_satker_rwymj_to_satker`, `fk_id_jabatan_rwymj_to_jabatan`,
`fk_id_atasan_es_1_rwymj_to_jabatan` .. `fk_id_atasan_es_4_rwymj_to_jabatan`, `fk_id_jabatan_koord_rmj_to_jabkoor`,
`fk_id_atasan_es_3_koord_rmj_to_jabkoor`, `fk_id_atasan_es_4_koord_rmj_to_jabkoor` (→ `jabatan_koordinasi`),
`riwayat_mutasi_jabatan_ibfk_02` (→ `rumpun_jabatan`); CHECK `chk_riwayat_mutasi_jabatan_status` [V2].
Dirujuk: `pegawai_mutasi_jabatan.id_riwayat_mutasi_jabatan` (RWY `fk_id_riwayat_mutasi_jabatan_pmj_to_rmj`, 131000).

jenis_rwy: 9 `jabatan` (SK), 36 `jabatan_pjft` (SK pemberhentian sementara JFT, bila `status_jft` 2), 41 `perjanjian_kerja`
(PPPK, `id_jenis_pegawai` 6) — `L_jabatan.php:957-963`; `id_entri` = `id_riwayat_mutasi_jabatan`.

Perilaku legacy yang memengaruhi skema: `$param = $postData` (kolom form tersimpan apa adanya); jenis 2 (Instansi Lain)
mengisi teks bebas `nama_instansi`/`unit`/`satker`/`jabatan` tanpa id; jenis 3 (PLT) memberi awalan "PLT " pada `jabatan`
/`jabatan_koord`; atasan koordinator/subkoordinator disimpan di kolom `_koord`; data SIASN disinkron cron
(`siasn_flag`/`siasn_lu`, `updated_at` ditulis eksplisit = `siasn_lu`); hapus = hard delete. Query terberat: "jabatan pada
tanggal" `status='1' AND tmtsk<=X AND jenis_jabatan='1' ORDER BY nip, tmtsk DESC` (`L_employee.php:5148-5161`, juga
`L_presensi.php`, `L_cuti.php`) → usulan index K2-11.

#### 2.2.2 pegawai_plt — [I] `L_jabatan.php:4890-4912` (`set_param_off_plt`), lampiran `:4594-4622`; UI `views/hr/officer/plt/form.php:20-60`; nama FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_pegawai_plt` | INT NOT NULL AUTO_INCREMENT | [I] `L_jabatan.php:4435, 4518` |
| `status` | TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus' | [I] form :20-32 (1/2); 10 [V2] (A #12) |
| `jenis_jabatan` | TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Jabatan Normal, 2: Jabatan Koordinasi' | [I] form :35-47 (default 1) |
| `jenis_jabatan_koord` | TINYINT(1) NULL COMMENT '1: Koordinator, 2: Subkoordinator; NULL bila jenis_jabatan 1' | [I] `:4895`, form :48-60 |
| `tgl_mulai`, `tgl_akhir` | DATE NOT NULL | [I] wajib di form; dipakai filter periode aktif (`function_helper.php:1950-1966`) |
| `id_group_jabatan`, `id_sub_group_jabatan` | INT NULL | [I] NULL bila jenis 2 |
| `id_unit`, `id_satker` | INT NULL | [I] |
| `id_jabatan` | INT NULL | [I] NULL bila jenis 2 |
| `id_jabatan_koord` | INT NULL | [I] NULL bila jenis 1 |
| `id_group_jabatan_plt`, `id_sub_group_jabatan_plt`, `id_unit_plt`, `id_satker_plt` | INT NULL | [I] posisi pegawai Plt (filter pemilihan pegawai `L_jabatan.php:4549`) |
| `nip_plt` | VARCHAR(30) NOT NULL | [I] wajib (`:4882-4885`); NOT NULL [V2] |
| `lampiran` | VARCHAR(255) NULL | [I] path `arsip/off_plt/…` (`:4612-4622`) |
| `created_at`, `updated_at`, `updated_by` | AUD 2.0.4 | [I] (`updated_by` `:4909`) |

Kunci: PRIMARY (`id_pegawai_plt`); 11 KEY bernama FK; FK PEG `fk_nip_plt_plt_to_pegawai` (`nip_plt`) → `pegawai(nip)`
RESTRICT/RESTRICT [K-erd] (b); **G02 ditahan (d), 10 FK**: `fk_id_group_jabatan_plt_to_gj` (`id_group_jabatan`),
`fk_id_group_jabatan_plt_plt_to_gj` (`id_group_jabatan_plt`), `fk_id_sub_group_jabatan_plt_to_sgj`,
`fk_id_sub_group_jabatan_plt_plt_to_sgj`, `fk_id_unit_plt_to_unit`, `fk_id_unit_plt_plt_to_unit`,
`fk_id_satker_plt_to_satker`, `fk_id_satker_plt_plt_to_satker`, `fk_id_jabatan_plt_to_jabatan`,
`fk_id_jabatan_koord_plt_to_jabkoord` (→ `jabatan_koordinasi`); CHECK `chk_pegawai_plt_status` `IN (1, 2, 10)` [V2].
Pemetaan nama → kolom mengikuti pola legacy `fk_<kolom>_<tabel>_to_<induk>` (`…_plt_plt_…` = kolom berakhiran `_plt`).
jenis_rwy: — (lampiran di kolom `lampiran`, bukan `document_attachment`).
Perilaku legacy: penentu atasan Plt dalam rantai approval (`function_helper.php:1519-1530, 1950-1966`,
`L_cuti.php:4049, 4122`, `L_skp.php:107, 143`) memfilter `id_jabatan`/`id_jabatan_koord` + `status='1'` + periode
`tgl_mulai`–`tgl_akhir` (KEY FK sudah menutup kolom id); hapus = hard delete (`:4778`).

#### 2.2.3 pegawai_plh — [I] `L_jabatan.php:5389-5411` (`sp_off_plh`), lampiran `:5093-5121`; UI `views/hr/officer/plh`; nama FK [K-erd] `fk_pegawai_plh_ibfk_01..11`

Kolom **sama persis** dengan `pegawai_plt` (2.2.2) dengan akhiran `_plh`: `id_pegawai_plh`, …, `id_group_jabatan_plh`,
`id_sub_group_jabatan_plh`, `id_unit_plh`, `id_satker_plh`, `nip_plh` VARCHAR(30) NOT NULL, `lampiran` (path
`arsip/off_plh/…`), AUD.

| FK (ERD) | Kolom | Induk | Keranjang |
|---|---|---|---|
| `fk_pegawai_plh_ibfk_01` | `nip_plh` | `pegawai` | PEG (b), di CREATE |
| `fk_pegawai_plh_ibfk_02` | `id_jabatan` | `jabatan` | G02 (d) |
| `fk_pegawai_plh_ibfk_03` | `id_jabatan_koord` | `jabatan_koordinasi` | G02 (d) |
| `fk_pegawai_plh_ibfk_04` .. `_07` | `id_group_jabatan`, `id_sub_group_jabatan`, `id_unit`, `id_satker` | `group_jabatan`, `sub_group_jabatan`, `unit`, `satker` | G02 (d) |
| `fk_pegawai_plh_ibfk_08` .. `_11` | `id_group_jabatan_plh`, `id_sub_group_jabatan_plh`, `id_unit_plh`, `id_satker_plh` | idem | G02 (d) |

ERD hanya mencatat nama & induk; **pemetaan nomor 04-11 → kolom adalah [I]** (urutan kolom di `sp_off_plh`: kolom
posisi yang di-Plh-kan dulu, lalu kolom `_plh`). Keputusan K2-6. CHECK `chk_pegawai_plh_status` `IN (1, 2, 10)` [V2].
Perilaku legacy: daftar Plh aktif `tgl_mulai<=hari ini AND tgl_akhir>=hari ini AND status='1'`, dikunci per `id_jabatan`
(`function_helper.php:1728-1740`, `L_cuti.php:26-35`); hapus = hard delete (`:5277`).

#### 2.2.4 riwayat_kp — stub [L] + kolom snapshot `pegawai_kp` [L] + kode [I] `L_kp.php:538-566`, `siasn_sync/Rw_gol.php`; seed :1639-1678

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_kp` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub `DEFAULT NULL` → NOT NULL [V2] |
| `id_jenis_kp`, `id_pangkat` | TINYINT NULL | [L] pegawai_kp (= PK master `main`) |
| `tmtsk` | DATE NOT NULL | [L] pegawai_kp |
| `tgl_sk` | DATE NOT NULL | [L] pegawai_kp; SIASN mengisi `tmtsk` bila kosong (`Rw_gol.php:255, 293, 322`) |
| `no_sk` | VARCHAR(100) NULL | [L] pegawai_kp |
| `tgl_pertek_bkn`, `no_pertek_bkn` | DATE NULL, VARCHAR(100) NULL | [L] pegawai_kp; [I] `Rw_gol.php` |
| `jumlah_kredit_utama`, `jumlah_kredit_tambahan` | DOUBLE NULL | [L] pegawai_kp; [I] `Rw_gol.php:259` |
| `jenis_kp` | VARCHAR(100) NOT NULL | [L] pegawai_kp (salinan `jenis_kp.jenis_kp`, `L_kp.php:550-553`) |
| `gol`, `ruang`, `gol_ruang` | VARCHAR(10) NOT NULL | [L] pegawai_kp (salinan `pangkat`, `:555-561`) |
| `pangkat` | VARCHAR(50) NOT NULL | [L] pegawai_kp |
| `mker_th`, `mker_bl` | TINYINT NULL | [L] pegawai_kp |
| `gaji_pokok` | INT NULL | [L] pegawai_kp |
| `keterangan` | TINYTEXT NULL | [L] pegawai_kp |
| `id_rw_siasn` | VARCHAR(100) NULL | [L] pegawai_kp; [I] `Rw_gol.php` |
| WF+AUD (11 kolom) | awal 2.2 | [L]/[I]/[V2] |

Kunci: PRIMARY; KEY `fk_nip_rwykp_to_pegawai`, `fk_id_jenis_kp_rwy_kp_to_jenis_kp`, `fk_id_pangkat_rwy_kp_to_pangkat`;
FK PEG `fk_nip_rwykp_to_pegawai` (b); FK MAIN `fk_id_jenis_kp_rwy_kp_to_jenis_kp` → `jenis_kp`, `fk_id_pangkat_rwy_kp_to_pangkat`
→ `pangkat` (a); semua RESTRICT/RESTRICT [K-erd]; CHECK `chk_riwayat_kp_status` [V2].
Dirujuk (RWY, 131000): `pegawai_kp`, `pegawai_cpns`, `pegawai_pns` (`fk_id_riwayat_kp_{peg_kp,pcpns,ppns}_to_rwy_kp`).
jenis_rwy: 11 `kp`.
Perilaku legacy: duplikat `tmtsk` per `nip` di antara status selain 2/10 ditolak aplikasi (`L_kp.php:523-531`) — **bukan
UNIQUE** (baris ditolak/dihapus boleh berulang); aturan periode KP 1 April/1 Oktober dan kalkulasi B-21 di aplikasi.

#### 2.2.5 riwayat_kgb — [I] `L_kgb.php:508-526` (`sp`), `:622-648` (`sp_process`); FK [K-erd]; stub [L] 5 kolom

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_kgb` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2] |
| `id_gol_pppk` | TINYINT NULL | [K-erd] `riwayat_kgb_ibfk_01` → `gol_pppk` (tipe = PK `main`); tanpa titik tulis PHP |
| `tmtsk` | DATE NOT NULL | [I] `sp` (wajib) |
| `no_sk` | VARCHAR(100) NULL | [I] (panjang = `riwayat_kp.no_sk` [L]) |
| `tgl_sk` | DATE NULL | [I] |
| `mker_gol_th` | TINYINT NULL | [I] (`intval > 0` atau NULL, `:516`) |
| `gaji_pokok` | INT NULL | [I] (tipe = `pegawai_kp.gaji_pokok` [L]) |
| `jym` | VARCHAR(255) NULL COMMENT 'Jabatan Yang Menandatangani SK' | [I] `:518`, label form kgb |
| `keterangan` | TINYTEXT NULL | [I] (tipe = keluarga KP [L]; K2-8) |
| WF+AUD (11 kolom) | awal 2.2 | [L]/[I]/[V2] |

Kunci: PRIMARY; KEY `fk_nip_rwykgb_to_pegawai`, `riwayat_kgb_ibfk_01`; FK PEG `fk_nip_rwykgb_to_pegawai` (b); FK MAIN
`riwayat_kgb_ibfk_01` → `gol_pppk` (a); RESTRICT/RESTRICT [K-erd]; CHECK `chk_riwayat_kgb_status` [V2].
Dirujuk (RWY, 131000): `pegawai_kgb.id_riwayat_kgb` (`fk_id_riwayat_kgb_pkgb_to_rkgb`).
jenis_rwy: 10 `kgb`.
Perilaku legacy: jarak KGB minimal 2 tahun dari TMT KGB/KP terakhir = aturan aplikasi (B-21), bukan constraint.

#### 2.2.6 riwayat_pendidikan — stub [L] + kode [I] `L_pendidikan.php:803-841`, `Rw_pendidikan.php`; seed :1757-1797

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_pendidikan` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2] |
| `id_jenjang_pendidikan` | INT NULL | [I] (= PK `main`) |
| `id_bidang_pendidikan` | TINYINT NULL COMMENT '98 = LAIN-LAIN (pakai bidang_pendidikan_lain)' | [I] (= PK `main`); 98 hard-code (A #11) |
| `id_jurusan_pendidikan` | INT NULL COMMENT '1185 = LAIN-LAIN (pakai jurusan_pendidikan_lain)' | [I] (= PK `main`); 1185 hard-code |
| `tgl_lulus` | DATE NULL | [I] seed |
| `no_ijazah`, `no_sk_penc_glr` | VARCHAR(100) NULL | [I] |
| `institusi_pendidikan` | VARCHAR(255) NULL | [I] (SIASN mengisi '-' bila kosong) |
| `jenjang_pendidikan_singkat` | VARCHAR(50) NULL | [I] (= master `main` 50) |
| `bidang_pendidikan` | VARCHAR(100) NULL | [I] (= master `main` 100) |
| `bidang_pendidikan_lain` | VARCHAR(255) NULL | [I] |
| `jurusan_pendidikan`, `jurusan_pendidikan_lain` | VARCHAR(255) NULL | [I] (= master `main` 255) |
| `nem` | DOUBLE NULL COMMENT 'hanya jenjang 1-3 (SD/SLTP/SLTA)' | [I] `:833` |
| `ipk` | DOUBLE NULL COMMENT 'hanya jenjang selain 1-3' | [I] `:834` |
| `glr_awal`, `glr_akhir` | VARCHAR(50) NULL | [I] `Rw_pendidikan.php:164-165, 425-426` (A #2) |
| `keterangan` | TINYTEXT NULL | [I] seed (= seed snapshot) |
| `id_rw_siasn` | VARCHAR(100) NULL | [I] `Rw_pendidikan.php`, `Siasn.php:5585` |
| WF+AUD (11 kolom) | awal 2.2 | [L]/[I]/[V2] |

Kunci: PRIMARY; KEY `fk_nip_rwypendidikan_to_pegawai`, `fk_id_jenjang_pendidikan_rpend_to_jpend`,
`fk_id_bidang_pendidikan_rpend_to_bpend`, `fk_id_jurusan_pendidikan_rpend_jupend`; FK PEG (b) + 3 FK MAIN (a) dengan nama
tersebut, RESTRICT/RESTRICT [K-erd]; CHECK `chk_riwayat_pendidikan_status` [V2]. Konsistensi jurusan ↔ bidang (jurusan
milik bidang lain) tidak ditegakkan DB (legacy juga tidak).
Dirujuk (RWY, 131000): `pegawai_pendidikan.id_riwayat_pendidikan` (`fk_id_riwayat_pendidikan_pegpend_to_rpend`).
jenis_rwy: 14 `pendidikan` (ijazah), 39 `pencantuman_gelar`, 40 `transkrip_nilai`; `id_entri` = `id_riwayat_pendidikan`.

#### 2.2.7 riwayat_diklat — stub [L] + kode [I] `L_diklat.php:626-684`, `Siasn.php` (sinkron); FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_diklat` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2] |
| `jenis_diklat` | TINYINT(1) NOT NULL COMMENT '1: Struktural, 2: Teknis, 3: Fungsional, 4: Prajabatan, 5: Sertifikasi' | [I] `getJenisDiklat()` `function_helper.php:220-238`, form `diklat/form.php:80-88`; tipe = `diklat.jenis_diklat` [K] |
| `id_diklat` | TINYINT NULL | [K-erd] `fk_id_diklat_rdiklat_to_diklat` (tipe = PK `diklat` `main`, G-06 1.4 #3); jenis 1/2/4 |
| `nama_diklat` | VARCHAR(255) NULL | [I] (salinan `diklat.nama_diklat` 255 atau nama sertifikasi) |
| `nama_diklat_lain` | VARCHAR(255) NULL | [I] `Siasn.php:4753`, field form `nama_diklat_lain` |
| `id_sub_group_jabatan` | INT NULL COMMENT 'jenis 3 Fungsional; tanpa FK (legacy)' | [I] `:668-674`; tanpa FK (ERD) |
| `sub_group_jabatan` | VARCHAR(100) NULL | [I] (= master G-02 100) |
| `id_rumpun_sertifikasi` | INT NULL COMMENT 'jenis 5 Sertifikasi; tanpa FK (legacy)' | [I] `:652-655`; master belum ada di `main` |
| `rumpun_sertifikasi` | VARCHAR(255) NULL | [I] |
| `id_lembaga_sertifikasi` | INT NULL COMMENT 'jenis 5 Sertifikasi; tanpa FK (legacy)' | [I] `:659-661` |
| `instansi_penyelenggara` | VARCHAR(255) NULL | [I] (jenis 5 = nama lembaga sertifikasi) |
| `deskripsi` | TEXT NULL | [I] (nama kursus SIASN) |
| `tgl_sertifikat` | DATE NOT NULL | [I] wajib; SIASN selalu mengisi |
| `no_sertifikat` | VARCHAR(100) NULL | [I] |
| `jumlah_jp` | SMALLINT NULL | [I] (NULL untuk jenis 5) |
| `mb_sertifikat_awal`, `mb_sertifikat_akhir` | DATE NULL | [I] masa berlaku sertifikasi |
| `keterangan` | TEXT NULL | [I] (SIASN menambah akhiran "Sync SIASN"/"**Upload To SIASN**", K2-8) |
| `id_rw_siasn` | VARCHAR(100) NULL | [I] `Siasn.php:1725` |
| `siasn_flag` | TINYINT NOT NULL DEFAULT 0 COMMENT (0..4) | [I] `Siasn.php:1812, 1861, 1892, 2013` |
| `siasn_error` | TEXT NULL | [I] `Siasn.php:1862` |
| WF+AUD (11 kolom) | awal 2.2 | [L]/[I]/[V2] |

Kunci: PRIMARY; KEY `fk_nip_rwydiklat_to_pegawai`, `fk_id_diklat_rdiklat_to_diklat`; FK PEG (b) + MAIN → `diklat` (a),
RESTRICT/RESTRICT [K-erd]; CHECK `chk_riwayat_diklat_status` [V2] dan `chk_riwayat_diklat_jenis_diklat` `BETWEEN 1 AND 5`
[V2] (domain pasti, sama dengan `chk_diklat_jenis_diklat` di `main`). `riwayat_diklat.jenis_diklat` = `diklat.jenis_diklat`
untuk baris ber-`id_diklat` tidak ditegakkan DB.
Dirujuk (RWY, 131000): `pegawai_diklat.id_riwayat_diklat` (`fk_id_riwayat_diklat_pegdiklat_to_rwydiklat`).
jenis_rwy: 5 `diklat` ("Pelatihan").
Perilaku legacy: diklat struktural ganda (`nip`, jenis 1, `tgl_sertifikat`, `id_diklat`, status selain 2/10) ditolak
aplikasi (`L_diklat.php:604-617`), bukan UNIQUE.

#### 2.2.8 riwayat_seminar — stub [L] + kode [I] `L_seminar.php:512-531`, `Siasn.php:4635-4662, 4905-4912`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_seminar` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2] |
| `jenis_seminar` | TINYINT NOT NULL DEFAULT 1 COMMENT '1: Seminar, 2: Kursus' | [I] `seminar/form.php:81-88` (default 1), SIASN '1' |
| `bidang_seminar` | VARCHAR(255) NULL COMMENT 'teks nama bidang_kursem atau isian Lain-Lain' | [I] `:516`, form :95-109 |
| `nama_seminar` | VARCHAR(255) NULL | [I] |
| `tgl_sertifikat` | DATE NOT NULL | [I] wajib; SIASN selalu mengisi |
| `no_sertifikat` | VARCHAR(100) NULL | [I] |
| `jumlah_jp` | SMALLINT NULL | [I] (field form `jumlah_jp` lewat `$param = $postData`) |
| `instansi_penyelenggara` | VARCHAR(255) NULL COMMENT 'teks nama instansi_kursem atau isian Lain-Lain' | [I] `:520`, form :160-174 |
| `keterangan` | TEXT NULL | [I] (akhiran SIASN, K2-8) |
| `id_rw_siasn` | VARCHAR(100) NULL | [I] `Siasn.php:4908` |
| `siasn_flag` | TINYINT NOT NULL DEFAULT 0 COMMENT (0..4) | [I] `Siasn.php:4909` |
| `siasn_error` | TEXT NULL | [I] `Siasn.php:4910` |
| WF+AUD (11 kolom) | awal 2.2 | [L]/[I]/[V2] |

Kunci: PRIMARY; KEY + FK PEG `fk_nip_rwyseminar_to_pegawai` (b) RESTRICT/RESTRICT [K-erd]; CHECK `chk_riwayat_seminar_status` [V2].
Relasi ke master kursem G-08 (`bidang_kursem`, `instansi_kursem`) hanya lewat teks nama → tanpa FK (legacy).
jenis_rwy: 22 `seminar` ("Kursus / Seminar"). Tanpa snapshot.

#### 2.2.9 AddFkG02Riwayat — ditahan (keranjang d), 40 FK, RESTRICT/RESTRICT, pra-cek orphan fail-closed

| Tabel | Jumlah | Induk di draf DBV-008 (konstanta `FK_DRAF_DBV008`) | Induk belum ada di draf DBV-008 (konstanta `FK_KOORD_RUMPUN`) |
|---|---|---|---|
| `riwayat_mutasi_jabatan` (kel. 2) | 13 | 9 (`group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `jabatan` ×5) | 4 (`jabatan_koordinasi` ×3, `rumpun_jabatan` ×1) |
| `pegawai_plt` (kel. 2) | 10 | 9 | 1 (`fk_id_jabatan_koord_plt_to_jabkoord`) |
| `pegawai_plh` (kel. 2) | 10 | 9 | 1 (`fk_pegawai_plh_ibfk_03`) |
| `riwayat_lckh` (kel. 3) | 2 | `fk_riwayat_lckh_ibfk_03` (`id_unit`), `_04` (`id_satker`) | — |
| `riwayat_ak` (kel. 3) | 1 | `fk_id_jabatan_rak_to_jab` | — |
| `riwayat_ak_siasn` (kel. 3) | 1 | `fk_id_jabatan_rw_ak_siasn_02` | — |
| `konv_ak` (kel. 3) | 3 | `fk_id_jabatan_konvak_to_jabatan`, `fk_id_unit_konvak_to_unit`, `fk_id_satker_konvak_to_satker` | — |
| **Total** | **40** | 34 | 6 |

Tipe kolom anak: semua INT kecuali `id_rumpun_jabatan` TINYINT → wajib sama dengan PK G-02 (ekspektasi 2.0.2: INT;
`rumpun_jabatan` TINYINT; draf DBV-008 `unit`/`satker`/`group_jabatan`/`sub_group_jabatan`/`jabatan` = INT ✓). PK induk yang
dipakai: `jabatan_koordinasi.id_jabatan_koordinasi` [I `L_jabatan.php:2194, 2273`], `rumpun_jabatan.id_rumpun_jabatan`
[I `:2109`]. Nama/kolom FK kelompok 3 diambil dari ERD dan **wajib dicocokkan** dengan migration `130400`/`130600`.
Mekanisme: pra-cek orphan SEMUA FK (termasuk nilai 0 hasil `addslashes('')` legacy) sebelum ALTER pertama; satu ALTER per
tabel; ALTER gagal → FK tabel yang sudah di-ALTER pada run itu di-drop, error dilempar ulang; `down()` men-drop 40 FK
(KEY tetap, milik migration Create).

### 2.3 Kelompok 3 — DBV-013 (SKP, LKH, konket, hukdis, AK)

Singkatan: WF = blok workflow 2.0.3 (TINYINT; keputusan tipe 4.1 #3); AUD = `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL` (2.0.4); STATUS = `status TINYINT NOT NULL` + COMMENT `'0: Menunggu, 1: Disetujui, 2: Ditolak, 10: Dihapus'` + CHECK `IN (0,1,2,10)`;
untuk `riwayat_skp` dan `riwayat_hukdis` COMMENT + `3: Diproses` dan CHECK `IN (0,1,2,3,10)` (1.5 #2). Semua FK `ON DELETE RESTRICT ON UPDATE RESTRICT`. Semua kolom string mewarisi `utf8mb4_unicode_ci` dari tabel.

#### 2.3.1 bkn_periode_ekinper — [K] `simpeg_prod.sql:270-284` persis

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id` | VARCHAR(150) NOT NULL | [K] :271. id periode dari API BKN (mis. `SKPManualFeb2025`, `Ekin_bkn.php:232`) |
| `nama` | VARCHAR(150) NOT NULL | [K] :272 |
| `tahun` | YEAR NOT NULL | [K] :273 |
| `bulan` | VARCHAR(5) NULL | [K] :274. Bagian pertama `periode_akhir` (`Ekin_bkn.php:31-32`) |
| `periode_awal`, `periode_akhir`, `batas_pengisian` | VARCHAR(10) NULL | [K] :275-277 (string API, bukan DATE) |
| `jenis_periode` | VARCHAR(150) NULL | [K] :278. `IS NULL` = periode bulanan reguler (`Ekin_bkn.php:228`) |
| `status` | TINYINT NOT NULL DEFAULT 1 | [K] :279. Hanya ditulis `'1'` (`Ekin_bkn.php:42`) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K] :280 |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [K] :281 |

Kunci: PRIMARY (`id`) [K] :282; KEY `tahun_bulan_jenis_periode_status` (`tahun`, `bulan`, `jenis_periode`, `status`) [K] :283. Tanpa FK dan tanpa CHECK (K3-6).
jenis_rwy: —.
Perilaku legacy: diisi cron `Ekin_bkn::cron_periode_skp` (:9-54, insert periode baru saja). Dirujuk logis oleh `riwayat_skp_periodik.periode_id` (join `L_skp.php:778-779`).

#### 2.3.2 riwayat_skp — [I] `L_skp.php:197-300, 625-662, 745-765`; `Siasn.php:7016-7027, 7075-7101, 7777-7825`; stub [L]; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_skp` | INT NOT NULL AUTO_INCREMENT | stub [L] |
| `nip` | VARCHAR(30) NOT NULL | stub [L] `DEFAULT NULL` → NOT NULL [V2] (2.0.1) |
| `tahun` | SMALLINT NOT NULL, COMMENT | [I] `L_skp.php:629` (digit saja; `>= 2022` menentukan skema nilai :638). Legacy tidak diketahui; SMALLINT dipilih agar impor toleran (K3-7) |
| `tgl_mulai`, `tgl_akhir` | DATE NOT NULL | [I] `L_skp.php:630-631`; wajib di form (`skp/form.php:104,110`); SIASN mengisi `{tahun}-01-02`/`-12-30` (`Siasn.php:7079-7080`) |
| `nip_penilai` | VARCHAR(30) NULL, COMMENT | [I] :632. **NIP non-FK** (2.0.8) |
| `nama_penilai`, `jabatan_penilai` | VARCHAR(255) NULL | [I] :633-634 |
| `nip_atasan_penilai` | VARCHAR(30) NULL, COMMENT | [I] :635. **NIP non-FK** |
| `nama_atasan_penilai`, `jabatan_atasan_penilai` | VARCHAR(255) NULL | [I] :636-637 |
| `rating_skp`, `rating_perilaku` | TINYINT NULL, COMMENT 1/2/3 | [I] :639-640 (tahun ≥ 2022); label `skp/detail.php:109,115` |
| `nilai_skp`, `nilai_skp_60_persen`, `nilai_perilaku`, `nilai_perilaku_40_persen`, `nilai_prestasi_kerja` | DECIMAL(6,2) NULL | [I] :649-653 (tahun < 2022); pembulatan 2 desimal `Siasn.php:7037-7039` |
| `kategori_nilai_prestasi` | VARCHAR(50) NULL | [I] :658 ("Sangat Baik" … "Sangat Kurang", `Siasn.php:7043-7072`) |
| `keterangan` | TEXT NULL | [I] :660; SIASN menambah akhiran (`Siasn.php:7025, 7783`) |
| `id_rw_siasn` | VARCHAR(100) NULL | [I] `Siasn.php:7780`; tipe pola [L] `pegawai_kp.id_rw_siasn` |
| `siasn_flag` | TINYINT NOT NULL DEFAULT 0, COMMENT | [I] `Siasn.php:7714` (`siasn_flag='0'` = antrean), :7781/:7812/:7823 (1/2/3) |
| `siasn_error` | TEXT NULL | [I] `Siasn.php:7813` (`json_encode` respons) |
| `status` | STATUS, DEFAULT 0 | [I] `controllers/hr/rwy/Skp.php:126` (pegawai 0, admin 1); domain K3-1 |
| WF: `reason_note` TEXT, `approved_by` INT, `show_notif` TINYINT NOT NULL DEFAULT 0, `notif_date` DATETIME, `show_ua_upt`/`show_ua_deputi`/`show_ua_biro` TINYINT NULL | | [I] `L_skp.php:190, 749-761`; `L_notification.php:3350-3425` |
| AUD | | [I] 2.0.4 |

Kunci: PRIMARY (`id_riwayat_skp`); KEY `fk_nip_rwyskp_to_pegawai` (`nip`); FK `fk_nip_rwyskp_to_pegawai` → `pegawai` (`nip`) [K-erd] keranjang (b); CHECK `chk_riwayat_skp_status` [V2].
jenis_rwy: 32 (`skp`, `document_attachment.id_entri` = `id_riwayat_skp`, `L_skp.php:256-275`).
Perilaku legacy yang memengaruhi skema: duplikat (`nip`, `tahun`, `tgl_mulai`, `tgl_akhir`) dengan status 1 ditolak di aplikasi (`L_skp.php:583, 604`), bukan dengan UNIQUE. Lampiran wajib minimal 1 (`L_skp.php:612-619`, maks 5 MB). Hapus keras beserta lampiran (`L_skp.php:444-451`).

#### 2.3.3 riwayat_skp_periodik — [I] `Ekin_bkn.php:263-340` (`cron_skp_periodik`; salinan identik di `test_cron_skp_periodik` :108-150); `L_skp.php:772-960`; `L_chart.php:4852`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_skp_periodik` | INT NOT NULL AUTO_INCREMENT | [I] `L_skp.php:811` |
| `id_pns` | VARCHAR(100) NULL, COMMENT | [I] `Ekin_bkn.php:268` (field `id` API); cron non-PNS (`Ekin_bkn.php:360-509`) tidak selalu mengisinya |
| `periode_id` | VARCHAR(150) NULL, COMMENT | [I] :269; tipe = `bkn_periode_ekinper.id` [K]; relasi logis tanpa FK |
| `skp_id`, `skp_penilaian_id` | VARCHAR(150) NULL | [I] :270-271 |
| `jenis` | VARCHAR(100) NULL | [I] :272 |
| `tahun_skp` | VARCHAR(10) NULL | [I] :273 (string API) |
| `nip` | VARCHAR(30) NOT NULL, COMMENT | [I] :274. **NIP non-FK** (data API BKN) |
| `nama` | VARCHAR(255) NULL | [I] :275 |
| `periode_awal_skp`, `periode_akhir_skp` | VARCHAR(30) NULL | [I] :276-277 (string API, pola `bkn_periode_ekinper.periode_*` [K]) |
| `skp_unor_id` | VARCHAR(150) NULL | [I] :278 |
| `skp_unor`, `skp_unor_induk`, `skp_jabatan` | VARCHAR(255) NULL | [I] :279-281 |
| `skp_jenis_jabatan` | VARCHAR(100) NULL | [I] :282 |
| `is_skp_plt_plh_pjb` | VARCHAR(10) NULL | [I] :283 (nilai API apa adanya) |
| `hasil_kerja`, `perilaku_kerja`, `hasil_akhir` | VARCHAR(100) NULL | [I] :284-286; `hasil_akhir` dikelompokkan `strtoupper(trim())` (`L_chart.php:4862`) |
| `pegawai_atasan_id` | VARCHAR(150) NULL | [I] :287 |
| `pegawai_atasan_nip` | VARCHAR(30) NULL, COMMENT | [I] :288. **NIP non-FK** |
| `pegawai_atasan_nama`, `pegawai_atasan_unor`, `pegawai_atasan_jabatan` | VARCHAR(255) NULL | [I] :289, :291-292 |
| `pegawai_atasan_unor_id` | VARCHAR(150) NULL | [I] :290 |
| `pegawai_atasan_golru`, `golru` | VARCHAR(20) NULL | [I] :293, :296 |
| `waktu_dinilai` | VARCHAR(30) NULL | [I] :294 (string API) |
| `pegawai_penilai_id` | VARCHAR(150) NULL | [I] :295 |
| `f_arsip_1` | VARCHAR(255) NULL, COMMENT | [I] `L_skp.php:893` (unggah admin), dikosongkan `:947` |
| `keterangan` | TEXT NULL | [I] dibaca `skp/detail_periodic.php:96` (temuan A #5) |
| `status` | STATUS, **DEFAULT 1** | [I] `Ekin_bkn.php:297` (selalu `'1'`); daftar `status='1'` (`L_skp.php:780`). K3-5 |
| AUD | | [I] 2.0.4 |

Kunci: PRIMARY (`id_riwayat_skp_periodik`); KEY [V2] `idx_riwayat_skp_periodik_periode_nip` (`periode_id`, `nip`) untuk cron per periode (`Ekin_bkn.php:242`) dan grafik (`L_chart.php:4852-4856`); KEY [V2] `idx_riwayat_skp_periodik_nip` (`nip`) untuk daftar per pegawai (`L_skp.php:780`); CHECK `chk_riwayat_skp_periodik_status` [V2]. Tanpa FK (tidak ada di ERD).
jenis_rwy: — (arsip di kolom `f_arsip_1`).
Perilaku legacy: kunci de-duplikasi cron = (`id_pns`, `periode_id`, `skp_id`) (`Ekin_bkn.php:245, 263`) tanpa UNIQUE (K3-8). Cron memperbarui baris bila `hasil_akhir` berubah (:302-340).

#### 2.3.4 riwayat_lckh — [K-m] `d_lkh` `simpeg_prod.sql:673-701` + PK; FK [K-erd] `fk_riwayat_lckh_ibfk_01..04`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_lckh` | INT NOT NULL AUTO_INCREMENT | [K-m] :674 `int NOT NULL`; PK + AUTO_INCREMENT [V2] (tabel arsip tak ber-PK; dirujuk FK `aa_lkh` [K] :23-28) |
| `id_unit`, `id_satker` | INT NULL | [K-m] :675-676. KEY `_03`/`_04`, FK G02 ditahan |
| `nip` | VARCHAR(30) NOT NULL | [K-m] :677 |
| `tgl_laporan` | DATE NOT NULL | [K-m] :678 |
| `nama` | VARCHAR(150) NOT NULL | [K-m] :679 (nama bergelar, `L_lkh.php:914`) |
| `unit`, `satker` | VARCHAR(150) NULL | [K-m] :680-681 |
| `nip_atasan` | VARCHAR(30) NULL | [K-m] :682. FK nullable (pengecualian 2.0.1, seperti `pengguna.nip`) |
| `nama_atasan` | VARCHAR(150) NOT NULL | [K-m] :683 |
| `kegiatan`, `output` | MEDIUMTEXT NOT NULL | [K-m] :684-685; isi array JSON (A #6) |
| `jumlah_diselesaikan`, `jam_mulai`, `jam_selesai` | MEDIUMTEXT NULL | [K-m] :686-688 |
| `catatan` | TEXT NULL | [K-m] :689 (catatan atasan, `L_lkh.php:1179`) |
| `file_lckh` | VARCHAR(250) NULL | [K-m] :690 (PDF hasil generate, `Cron.php:373`) |
| `status` | INT NOT NULL DEFAULT 0, COMMENT v2 | [K-m] :691 (`'0: Wating, 1: Approved, 2: Rejected, 3: Revisi'`); COMMENT v2 + nilai 10 [V2] |
| `auto_approval` | TINYINT(1) NULL DEFAULT 2, COMMENT legacy | [K-m] :692 (`Cron.php:375` menulis 1) |
| `status_regen` | TINYINT(1) NULL DEFAULT 0, COMMENT legacy | [K-m] :693 (`Cron.php:2675`) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K-m] :694 |
| `updated_at` | DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE | [K-m] :695 |
| `updated_by`, `approved_by` | INT NULL | [K-m] :696-697 |
| `show_notif`, `show_atasan` | INT NOT NULL DEFAULT 0 | [K-m] :698-699 |
| `show_history_atasan` | INT NOT NULL DEFAULT 1 | [K-m] :700 (`L_notification.php:3990`) |

Kunci: PRIMARY (`id_riwayat_lckh`) [V2]; KEY `fk_riwayat_lckh_ibfk_01` (`nip`), `_02` (`nip_atasan`), `_03` (`id_unit`), `_04` (`id_satker`); KEY [V2] `idx_riwayat_lckh_nip_tgl` (`nip`, `tgl_laporan`) untuk daftar per pegawai (`L_lkh.php:117`) dan cek duplikat harian (`:851, :874`); KEY [V2] `idx_riwayat_lckh_atasan_status` (`nip_atasan`, `status`) untuk antrean atasan (`L_notification.php:170` `nip_atasan=? AND status='0'`); FK `fk_riwayat_lckh_ibfk_01` (`nip`) dan `_02` (`nip_atasan`) → `pegawai` [K-erd] keranjang (b); FK `_03` → `unit`, `_04` → `satker` keranjang (d); CHECK `chk_riwayat_lckh_status` `IN (0,1,2,3,10)` [V2].
jenis_rwy: —.
Perilaku legacy: pemetaan nomor `ibfk_01/_02` → `nip`/`nip_atasan` adalah [I]. ERD hanya menunjukkan keduanya merujuk `pegawai`, dan `_03`/`_04` merujuk `unit`/`satker`. Satu LKH per (`nip`, `tgl_laporan`) di antara status ≠ 2/10 ditegakkan aplikasi (`L_lkh.php:851`), tanpa UNIQUE. Auto-approval lewat cron `aa_lkh`/`queue_aa_lkh` (`Cron.php:18-220`) di luar cakupan (4.1 #10). KEY FK `_01`/`_02` tumpang tindih dengan indeks komposit (K3-4).

#### 2.3.5 absen_ijin (konket) — [K] `simpeg_prod.sql:33-69` persis

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id` | INT NOT NULL AUTO_INCREMENT | [K] :34 |
| `nip` | VARCHAR(30) NOT NULL | [K] :35 |
| `date_start`, `date_end` | DATETIME NOT NULL | [K] :36-37 |
| `kategori` | INT NOT NULL | [K] :38. = `jenis_konket.old_id` tanpa FK (G-06) |
| `jenis_konket` | VARCHAR(255) NULL | [K] :39 (salinan nama master, `L_konket.php:1753`) |
| `jenis_dinas` | TINYINT(1) NULL, COMMENT legacy | [K] :40. Diisi hanya untuk kategori 8 (`L_konket.php:1758-1763`) |
| `affect_tukin` | TINYINT(1) NOT NULL DEFAULT 1, COMMENT legacy | [K] :41 |
| `id_parent` | INT NULL | [K] :42. Induk dinas gabungan, tanpa FK |
| `alasan` | MEDIUMTEXT NOT NULL | [K] :43 |
| `file_bukti` .. `file_bukti_5` | VARCHAR(255) NULL | [K] :44-48 |
| `date_created` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K] :49 |
| `last_updated` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [K] :50 |
| `status` | CHAR(2) NOT NULL DEFAULT 'W', COMMENT legacy | [K] :51 |
| `created_at` | DATETIME NULL DEFAULT CURRENT_TIMESTAMP | [K] :52 |
| `updated_at` | DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE | [K] :53 |
| `updated_by`, `approved_by` | INT NULL | [K] :54-55 |
| `reason_note` | MEDIUMTEXT NULL | [K] :56 |
| `show_notif` | INT NULL DEFAULT 0 | [K] :57 |
| `notif_date` | DATETIME NULL | [K] :58 |
| `show_ua_upt`, `show_ua_deputi`, `show_ua_biro` | INT NULL DEFAULT 0 | [K] :59-61 |

Kunci: PRIMARY (`id`); KEY `nip`, `date_start` (`date_start`, `date_end`), `show_notif` (`show_notif`, `notif_date`), `status`, `affect_tukin` [K] :62-67 (FK memakai KEY legacy `nip`, tanpa KEY baru); FK `fk_nip_abijin_to_pegawai` → `pegawai` (`nip`) [K] :68, CASCADE → RESTRICT, keranjang (b); CHECK [V2] `chk_absen_ijin_status` (`IN ('W','V','X','10')`), `chk_absen_ijin_affect_tukin` (`IN (1,2)`), `chk_absen_ijin_jenis_dinas` (`IS NULL OR IN (0,1)`).
jenis_rwy: — (bukti di `file_bukti*`).
Perilaku legacy: trigger `absen_ijin_beDel` (:1476-1482) menyalin baris ke `d_konket` (:639-668, identik) sebelum DELETE. Trigger ini **tidak** ditiru; jejak lewat `audit_logs`. Hapus keras, termasuk anak dinas gabungan `id IN (…)` (`L_konket.php:939`). Overlap tanggal dengan status W/V ditolak aplikasi (`L_konket.php:1608, 1720`, `validate_param`). Pegawai selalu mengirim `W` (`Konket.php:140, 315`). Dipakai Presensi Fase 5 (`L_presensi.php`) dan notifikasi (`L_notification.php:434-441, 3370-3380`). Status CHAR W/V/X/10 berbeda dari B-TC generik 0/1/2 (K3-2).

#### 2.3.6 riwayat_hukdis — [I] `L_hukdis.php:463-498`; `Siasn.php:3030-3048, 3055-3080`; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_hukdis` | INT NOT NULL AUTO_INCREMENT | [I] |
| `nip` | VARCHAR(30) NOT NULL | [I] `Hukdis.php:72`; NOT NULL [V2] |
| `id_tingkat_hukdis` | INT NULL | [I] :466; tipe = PK `tingkat_hukdis` di `main`. NULL dari SIASN tanpa pemetaan (`Siasn.php:3057`) |
| `tingkat_hukdis` | VARCHAR(100) NULL | [I] :479 (salinan `tingkat_hukdis.tingkat_hukdis` V100) |
| `id_jenis_hukdis` | INT NULL | [I] :467 |
| `jenis_hukdis` | VARCHAR(255) NULL | [I] :484 (salinan master V255) |
| `id_pangkat` | TINYINT NULL | [I] :468; tipe = PK `pangkat` |
| `gol`, `ruang`, `gol_ruang` | VARCHAR(10) NULL | [I] :489-491; tipe pola [L] `pegawai_kp` |
| `pangkat` | VARCHAR(50) NULL | [I] :492 |
| `no_sk` | VARCHAR(100) NULL | [I] :471 |
| `tgl_sk` | DATE NULL | [I] :470 |
| `tmtsk` | DATE NOT NULL | [I] :469; wajib (`L_hukdis.php:388-391`, form `:104`); SIASN mengisi `skTanggal` |
| `masa_hukuman` | TEXT NULL, COMMENT | [I] :472; textarea bebas. SIASN: `"X Tahun Y Bulan"` (`Siasn.php:2940-2946`) |
| `akhir_hukdis` | DATE NULL | [I] :473 |
| `aturan_dilanggar`, `alasan_hukuman` | TEXT NULL | [I] :474-475 |
| `keterangan` | TEXT NULL | [I] :495 (lihat A #7) |
| `status` | STATUS, DEFAULT 0 | [I] `Hukdis.php:73, 130` (selalu `'1'`); daftar `IN ('0','1')` (`L_hukdis.php:13`); K3-3 |
| `approved_by` | INT NULL, COMMENT | [I] `Siasn.php:3046` (hanya sinkron) |
| AUD | | [I] 2.0.4 |

Kunci: PRIMARY (`id_riwayat_hukdis`); KEY bernama FK untuk keempat FK; FK `fk_nip_rwyhukdis_to_pegawai` → `pegawai` keranjang (b); `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis` → `tingkat_hukdis`, `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis` → `jenis_hukdis`, `fk_id_pangkat_rwyhukdis_to_pangkat` → `pangkat` [K-erd] keranjang (a); CHECK `chk_riwayat_hukdis_status` [V2].
jenis_rwy: 7 (`hukdis`).
Perilaku legacy: tanpa approval dan tanpa blok WF (role 1 saja, `Hukdis.php:12, 53, 114`; DoD B-14: role 1 dan 3). Duplikat `tmtsk` per nip di antara status ≠ 2/10 ditolak aplikasi (`L_hukdis.php:429, 451`). Lampiran wajib saat tambah. B-14 menghitung `akhir_hukdis` dari `jenis_hukdis.masa_sanksi_bulan` (K5); kolom numerik masa sanksi per riwayat tidak dibuat (K3-11).

#### 2.3.7 riwayat_ak — [I] `L_ak.php:875-925, 1178-1210, 1231-1290, 558`; `L_user.php:4878-4975`; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_ak` | INT NOT NULL AUTO_INCREMENT | stub [L] |
| `id_jabatan` | INT NULL | [I] :878. KEY `fk_id_jabatan_rak_to_jab`, FK G02 ditahan |
| `id_pangkat` | TINYINT NULL | [I] :879 |
| `nip` | VARCHAR(30) NOT NULL | [I] :880; stub [L] NULL → NOT NULL |
| `jenis_ak` | TINYINT NOT NULL DEFAULT 2, COMMENT | [I] :881 (`'2'`), `L_user.php:4901` (`'1'` Current AK); label `L_ak.php:211`. Tanpa CHECK (K3-12) |
| `nama` | VARCHAR(255) NULL | [I] :882 (nama bergelar) |
| `no_induk_jf` | VARCHAR(50) NULL | [I] :883; pola [L] `pegawai_mutasi_jabatan.no_induk_jft` V50 |
| `no_hpak` | VARCHAR(100) NULL | [I] :884; NULL untuk Current AK |
| `tgl_pak`, `periode_awal`, `periode_akhir` | DATE NULL | [I] :885-887 |
| `ak_kum_kp`, `ak_kum_next_jen`, `ak_terakhir`, `pengajuan_ak`, `nilai_ak` | DECIMAL(8,3) NULL | [I] :888-892; tipe DECIMAL(8,3) pola `simpegdev_local.riwayat_ak.pengajuan_ak` dan snapshot `pegawai_ak.nilai_ak` (kelompok 1) |
| `flag_instansi_ym` | TINYINT NULL, COMMENT | [I] :893 (`0` Kemenpar / `1` Instansi Lain, `ak/form.php:155-158`) |
| `instansi_ym`, `nama_pym`, `jab_pym`, `jabatan` | VARCHAR(255) NULL | [I] :894-897 |
| `tmt_jab` | DATE NULL | [I] :898 |
| `mker_th_jab`, `mker_bl_jab` | TINYINT NULL | [I] :899-900; pola [L] `pegawai_kp.mker_*` |
| `pangkat` | VARCHAR(50) NULL | [I] :901 |
| `gol_ruang` | VARCHAR(10) NULL | [I] :902 |
| `tmt_pang` | DATE NULL | [I] :903 |
| `mker_th_pang`, `mker_bl_pang` | TINYINT NULL | [I] :904-905 |
| `tgl_ak_terakhir`, `tgl_pengajuan_ak` | DATE NULL | [I] `L_user.php:4908-4909` (A #3) |
| `keterangan` | TEXT NULL | [I] :906 |
| `file_pak` | VARCHAR(255) NULL | [I] :558 |
| `file_dupak`, `file_pengantar` | VARCHAR(255) NULL | [I] `L_user.php:4913, 4950` (A #3) |
| `status` | STATUS, DEFAULT 0 | [I] :909-911 (dari form); Current AK `'0'` (`L_user.php:4914`) |
| `reason_note` | TEXT NULL | [I] :1190, :1250 |
| `appr_at` | DATETIME NULL | [I] :1184 (`date('Y-m-d H:i:s')` waktu server; v2 menulis UTC) |
| `appr_by` | INT NULL, COMMENT | [I] :1185 |
| `show_notif` | TINYINT NOT NULL DEFAULT 0 | [I] :487 (WF, dibaca notifikasi) |
| `notif_date` | DATETIME NULL | [I] dibaca `L_notification.php:3405-3414` |
| `show_ua_upt`, `show_ua_deputi`, `show_ua_biro` | TINYINT NULL | [I] :1196-1203 |
| `created_by` | INT NULL, COMMENT | [I] :917 (saat tambah); `L_user.php:4916` |
| AUD | | [I] 2.0.4; `updated_by` :920, :1183 |

Kunci: PRIMARY (`id_riwayat_ak`); KEY `fk_nip_rak_to_peg`, `fk_id_pangkat_rak_to_pangkat`, `fk_id_jabatan_rak_to_jab`; FK `fk_nip_rak_to_peg` → `pegawai` (b), `fk_id_pangkat_rak_to_pangkat` → `pangkat` (a) [K-erd]; `fk_id_jabatan_rak_to_jab` → `jabatan` keranjang (d); CHECK `chk_riwayat_ak_status` [V2].
jenis_rwy: — (berkas di kolom `file_pak`/`file_dupak`).
Perilaku legacy: pengecualian blok WF (2.0.3) memakai `appr_at`/`appr_by`, bukan `approved_by`. Tumpang tindih `periode_awal..periode_akhir` per nip di antara status 0/1 ditolak aplikasi (`L_ak.php:837, 861`). Current AK diproses lewat `Ak.php:801-830` (`jenis_ak='1' AND status!='10'`).

#### 2.3.8 riwayat_ak_siasn — [I] `siasn_sync/Rw_ak.php:60-200`; `L_ak.php:931-1045`; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_ak_siasn` | INT NOT NULL AUTO_INCREMENT | [I] `Rw_ak.php:113, 186` (A #4) |
| `id_rw_siasn` | VARCHAR(100) NULL | [I] :138 (kunci sinkron, :62-67) |
| `id_pns` | VARCHAR(100) NULL | [I] :139; pola [L] `pegawai.id_pns_siasn` V100 |
| `id_jabatan` | INT NULL, COMMENT | [I] :140 (dari riwayat jabatan) dan pairing admin `L_ak.php:1040`. KEY `_02`, FK G02 ditahan |
| `id_pangkat` | TINYINT NULL | [I] :141 (selalu NULL saat sinkron) |
| `nip` | VARCHAR(30) NOT NULL | [I] :142 |
| `no_sk` | VARCHAR(100) NULL | [I] :143 |
| `tgl_sk` | DATE NULL | [I] :144 (dibalik dari `dd-mm-yyyy`, :84-87) |
| `bulan_mulai_penilaian`, `bulan_selesai_penilaian` | TINYINT NULL | [I] :145, :147 |
| `tahun_mulai_penilaian`, `tahun_selesai_penilaian` | SMALLINT NULL | [I] :146, :148 |
| `kredit_utama_baru`, `kredit_penunjang_baru`, `kredit_baru_total` | DECIMAL(8,3) NULL | [I] :149-151; dibandingkan untuk update (:111) |
| `id_rw_jabatan_siasn` | VARCHAR(100) NULL | [I] :152 |
| `nama_jabatan` | VARCHAR(255) NULL | [I] :153 |
| `is_angka_kredit_pertama`, `is_integrasi`, `is_konversi` | TINYINT(1) NULL | [I] :154-156; hanya dibandingkan `== '1'` (`ak/list_siasn.php:60-62`) |
| `f_pak_siasn` | VARCHAR(255) NULL | [I] :157 (`dok_uri` SIASN) |
| `sumber` | VARCHAR(100) NULL | [I] :158 |
| `is_pemenuhan_kp` | TINYINT(1) NULL | [I] :159 |
| `keterangan` | TEXT NULL | [I] dibaca `ak/list_siasn.php` (A #4) |
| `status` | STATUS, **DEFAULT 1** | [I] :160 (selalu `'1'`); daftar `IN ('0','1')` (`L_ak.php:934`). K3-5 |
| AUD | | [I] 2.0.4; `updated_by` `L_ak.php:1041` |

Kunci: PRIMARY (`id_riwayat_ak_siasn`); KEY `fk_nip_rw_ak_siasn_01`, `fk_id_jabatan_rw_ak_siasn_02`, `fk_id_pangkat_rw_ak_siasn_03`; FK `_01` → `pegawai` (b), `_03` → `pangkat` (a) [K-erd]; `_02` → `jabatan` keranjang (d); CHECK `chk_riwayat_ak_siasn_status` [V2].
jenis_rwy: —.
Perilaku legacy: baris yang hilang dari SIASN tidak dihapus (blok delete dikomentari, `Rw_ak.php:190-199`). Tanpa UNIQUE `id_rw_siasn` (K3-8).

#### 2.3.9 konv_ak — [I] `L_user.php:4987-5250` (baris param utama :5054-5072); `controllers/User.php:425-460, 592-610, 1229-1331`; stub [L]; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id` | INT NOT NULL AUTO_INCREMENT | stub [L] |
| `nip` | VARCHAR(30) NOT NULL | [I] :5055; stub [L] NULL → NOT NULL |
| `id_pangkat` | TINYINT NULL | [I] :5056 |
| `id_jabatan`, `id_unit`, `id_satker` | INT NULL | [I] :5057-5059. KEY bernama FK, FK G02 ditahan |
| `pangkat` | VARCHAR(50) NULL | [I] :5060 |
| `gol_ruang` | VARCHAR(10) NULL | [I] :5061 |
| `jabatan`, `unit`, `satker` | VARCHAR(255) NULL | [I] :5062-5064; pola [L] `pegawai_mutasi_jabatan` V255 |
| `no_pak` | VARCHAR(100) NULL | [I] :5065 |
| `tgl_pak` | DATE NULL | [I] :5066 |
| `ak_terakhir` | DECIMAL(8,3) NULL | [I] :5067 (koma → titik) |
| `file_dupak` | VARCHAR(255) NULL | [I] :5068, :5103 |
| `status` | STATUS, DEFAULT 0 | [I] :5069 (selalu `'0'`); stub [L] VARCHAR(5) DEFAULT '1' |
| `reason_note` | TEXT NULL | [I] :5070 |
| `created_by` | INT NULL, COMMENT | [I] :5071 |
| AUD | | [I]; `created_at` dibaca `ORDER BY` (`L_user.php:5156`) |

Kunci: PRIMARY (`id`); KEY bernama FK untuk kelima FK; FK `fk_nip_konvak_to_pegawai` → `pegawai` (b), `fk_id_pangkat_konvak_to_pangkat` → `pangkat` (a) [K-erd]; `fk_id_jabatan_konvak_to_jabatan`, `fk_id_unit_konvak_to_unit`, `fk_id_satker_konvak_to_satker` keranjang (d); CHECK `chk_konv_ak_status` [V2].
jenis_rwy: —.
Perilaku legacy: pop-up konversi AK untuk JF (`id_group_jabatan = 2`) dengan batas unggah 31-03-2023 (`User.php:425-460`); muncul selama belum ada baris status 0/1. Get/update/delete memakai `nip` (satu baris per nip), tetapi UNIQUE `nip` ditunda sampai audit data (K3-8). Tidak ada proses approval di kode (status tetap 0). Data konversi pernah disalin ke `riwayat_ak` oleh skrip `Tester.php:3020-3105`.

#### 2.3.10 ignore_konv_ak — [K] `simpeg_prod.sql:1345-1350`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `nip` | VARCHAR(30) NOT NULL | [K] :1346 VARCHAR(50) → 30 [V2] (2.0.1) |
| `ignore_date` | DATE NOT NULL | [K] :1347 |

Kunci: PRIMARY (`nip`, `ignore_date`) [K] :1348 (menjadi index FK, tanpa KEY terpisah seperti legacy); FK `fk_nip_ignorekonvak_to_pegawai` → `pegawai` [K] :1349, CASCADE → RESTRICT, keranjang (b).
jenis_rwy: —.
Perilaku legacy: cabang "abaikan" (`L_user.php:5024-5029, 5114-5117`) tidak menyimpan baris, dan pemeriksaan di `MY_Controller.php:613-632` dikomentari. Isi tabel hanya data historis. Baris dihapus bersama `konv_ak` (`L_user.php:5176`). Tabel dipertahankan karena ada di [K] dan dirujuk kode hapus. Bila data produksi kosong, DBV dapat memutuskan untuk tidak membuatnya (K3-13).

#### 2.3.11 Ringkasan constraint, masukan FK G-02 & registry kelompok 3

| Tabel | PRIMARY | KEY (non-FK) | FK dipasang | KEY FK ditahan (G02) | CHECK |
|---|---|---|---|---|---|
| `bkn_periode_ekinper` | `id` | `tahun_bulan_jenis_periode_status` | — | — | — |
| `riwayat_skp` | `id_riwayat_skp` | — | `fk_nip_rwyskp_to_pegawai` | — | `chk_riwayat_skp_status` |
| `riwayat_skp_periodik` | `id_riwayat_skp_periodik` | `idx_riwayat_skp_periodik_periode_nip`, `idx_riwayat_skp_periodik_nip` | — | — | `chk_riwayat_skp_periodik_status` |
| `riwayat_lckh` | `id_riwayat_lckh` | `idx_riwayat_lckh_nip_tgl`, `idx_riwayat_lckh_atasan_status` | `fk_riwayat_lckh_ibfk_01`, `_02` | `fk_riwayat_lckh_ibfk_03` (unit), `_04` (satker) | `chk_riwayat_lckh_status` |
| `absen_ijin` | `id` | `date_start`, `show_notif`, `status`, `affect_tukin` (+ `nip` dipakai FK) | `fk_nip_abijin_to_pegawai` | — | `chk_absen_ijin_status`, `chk_absen_ijin_affect_tukin`, `chk_absen_ijin_jenis_dinas` |
| `riwayat_hukdis` | `id_riwayat_hukdis` | — | `fk_nip_rwyhukdis_to_pegawai`, `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis`, `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis`, `fk_id_pangkat_rwyhukdis_to_pangkat` | — | `chk_riwayat_hukdis_status` |
| `riwayat_ak` | `id_riwayat_ak` | — | `fk_nip_rak_to_peg`, `fk_id_pangkat_rak_to_pangkat` | `fk_id_jabatan_rak_to_jab` | `chk_riwayat_ak_status` |
| `riwayat_ak_siasn` | `id_riwayat_ak_siasn` | — | `fk_nip_rw_ak_siasn_01`, `fk_id_pangkat_rw_ak_siasn_03` | `fk_id_jabatan_rw_ak_siasn_02` | `chk_riwayat_ak_siasn_status` |
| `konv_ak` | `id` | — | `fk_nip_konvak_to_pegawai`, `fk_id_pangkat_konvak_to_pangkat` | `fk_id_jabatan_konvak_to_jabatan`, `fk_id_unit_konvak_to_unit`, `fk_id_satker_konvak_to_satker` | `chk_konv_ak_status` |
| `ignore_konv_ak` | (`nip`, `ignore_date`) | — | `fk_nip_ignorekonvak_to_pegawai` | — | — |

**Masukan untuk `AddFkG02Riwayat` (kelompok 2, ditahan).** Tujuh FK berikut, semuanya `ON DELETE RESTRICT ON UPDATE RESTRICT` dan cukup `ADD CONSTRAINT` karena KEY-nya sudah ada:

| FK | Kolom | Induk | Tipe kolom |
|---|---|---|---|
| `fk_riwayat_lckh_ibfk_03` | `riwayat_lckh.id_unit` | `unit.id_unit` | INT |
| `fk_riwayat_lckh_ibfk_04` | `riwayat_lckh.id_satker` | `satker.id_satker` | INT |
| `fk_id_jabatan_rak_to_jab` | `riwayat_ak.id_jabatan` | `jabatan.id_jabatan` | INT |
| `fk_id_jabatan_rw_ak_siasn_02` | `riwayat_ak_siasn.id_jabatan` | `jabatan.id_jabatan` | INT |
| `fk_id_jabatan_konvak_to_jabatan` | `konv_ak.id_jabatan` | `jabatan.id_jabatan` | INT |
| `fk_id_unit_konvak_to_unit` | `konv_ak.id_unit` | `unit.id_unit` | INT |
| `fk_id_satker_konvak_to_satker` | `konv_ak.id_satker` | `satker.id_satker` | INT |

**Masukan untuk registry NIP (2.0.8):**
- FK → `pegawai.nip` (10): `riwayat_skp.nip`, `riwayat_lckh.nip`, `riwayat_lckh.nip_atasan` (nullable), `absen_ijin.nip`, `riwayat_hukdis.nip`, `riwayat_ak.nip`, `riwayat_ak_siasn.nip`, `konv_ak.nip`, `ignore_konv_ak.nip` (bagian PK). B-06 wajib mengarahkan ulang juga `riwayat_lckh.nip_atasan`.
- NIP non-FK (4): `riwayat_skp.nip_penilai`, `riwayat_skp.nip_atasan_penilai`, `riwayat_skp_periodik.nip`, `riwayat_skp_periodik.pegawai_atasan_nip`.
- Catatan B-06: `ignore_konv_ak.nip` termasuk PK, sehingga pola salin → arahkan ulang → hapus berjalan dengan `UPDATE` kolom PK anak. Nilai baru tidak bentrok karena NIP baru belum punya baris.

### 2.4 Kelompok 4 — DBV-013 (keluarga, alamat, kartu, tanda jasa, organisasi, lampiran, lookup)

Blok **WF+AUD** (sama persis dengan kelompok 2/3, di akhir tabel `riwayat_keluarga`, `riwayat_alamat`,
`riwayat_tanda_jasa`, `riwayat_organisasi`):
`status` TINYINT NOT NULL DEFAULT 0 COMMENT '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus' untuk
`riwayat_keluarga`/`riwayat_tanda_jasa`/`riwayat_organisasi` (CHECK `IN (0, 1, 2, 3, 10)`; `riwayat_alamat` tanpa 3, 1.5 #2) ·
`reason_note` TEXT NULL · `approved_by` INT NULL · `show_notif` TINYINT NOT NULL DEFAULT 0 · `notif_date` DATETIME NULL ·
`show_ua_upt` / `show_ua_deputi` / `show_ua_biro` TINYINT NULL · `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ·
`updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP · `updated_by` INT NULL.
Sumber: `status`/`show_notif` stub [L] (tipe status → [V2] 2.0.3), `reason_note`/`approved_by`/`show_ua_*` [I]
(`sp_process`/`set_param_process` tiap library: `L_keluarga.php:1300-1320`, `L_alamat.php:777-800`, `L_tj.php:591-610`,
`L_organisasi.php:606-625`), `notif_date` [I] (`L_notification.php:3350-3425`), AUD [I] 2.0.4.

Blok **LAYANAN** (pengecualian 2.0.3, karpeg/kariskarsu): `status` (seperti di atas) · `reason_note` TEXT NULL ·
`approved_by` INT NULL · `show_ua_biro` TINYINT NULL · `show_notif` TINYINT NOT NULL DEFAULT 0 · `notif_date` DATETIME NULL ·
`rated` TINYINT NOT NULL DEFAULT 2 COMMENT '0: Ignore, 1: Sudah, 2: Belum (survei kepuasan layanan)' · AUD.

#### 2.4.1 jenis_rwy — lookup [V2]; kode/label [I] `config/constants.php:193-238`; seed di migration yang sama (preseden seed sentinel DBV-003)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jenis_rwy` | INT NOT NULL (tanpa AUTO_INCREMENT) COMMENT kode legacy | [V2]; nilai = `ARSIP_RWY` [I]; tipe = `document_attachment.id_riwayat` [K] |
| `kode` | VARCHAR(30) NOT NULL COMMENT 'kunci ARSIP_RWY legacy' | [I] kunci array `ARSIP_RWY` (`:193-214`) |
| `jenis_rwy` | VARCHAR(100) NOT NULL COMMENT 'label JENIS_RWY_ARSIP legacy' | [I] `JENIS_RWY_ARSIP` (`:215-238`) |
| `tabel_entri` | VARCHAR(64) NULL COMMENT tabel tujuan `id_entri` | [V2]; nilai [I] (A #8); 64 = panjang maksimum nama tabel MySQL |
| `order` | TINYINT NOT NULL DEFAULT 1 | pola master `main` (`diklat` [K]); nilai = urutan elemen `JENIS_RWY_ARSIP` [I]; baris 0 → 0 [V2] |
| `status` | TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus' | pola master `main` [V2] |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | pola master `main` (`diklat` [K]) |
| `updated_by` | INT NULL COMMENT 'id_pengguna yang terakhir mengubah' | pola master `main` |

Kunci: PRIMARY (`id_jenis_rwy`); UNIQUE `uq_jenis_rwy_kode` (`kode`), `uq_jenis_rwy_nama` (`jenis_rwy`); CHECK
`chk_jenis_rwy_status` `IN (1, 2, 10)` [V2]. Dirujuk: `document_attachment.id_riwayat` (`fk_id_riwayat_da_to_jenis_rwy`, 2.4.9).

**Seed 21 baris** (satu INSERT multi-baris, `status` 1, `updated_at` = `UTC_TIMESTAMP()`, `updated_by` NULL; konstanta
`CreateJenisRwy::ROWS` ditulis literal):

| id | kode | jenis_rwy | tabel_entri | order |
|---|---|---|---|---|
| 0 | `belum_terhubung` | Belum Terhubung | NULL | 0 [V2] |
| 1 | `alamat` | Alamat | `riwayat_alamat` | 1 |
| 3 | `anak` | Anak | `detail_anak` | 2 |
| 38 | `arsip_du` | Data Umum | NULL | 3 |
| 5 | `diklat` | Pelatihan | `riwayat_diklat` | 4 |
| 7 | `hukdis` | Hukuman Disiplin | `riwayat_hukdis` | 5 |
| 9 | `jabatan` | Jabatan | `riwayat_mutasi_jabatan` | 6 |
| 20 | `keluarga` | Keluarga | `riwayat_keluarga` | 7 |
| 11 | `kp` | Kenaikan Pangkat | `riwayat_kp` | 8 |
| 10 | `kgb` | KGB | `riwayat_kgb` | 9 |
| 22 | `seminar` | Kursus / Seminar | `riwayat_seminar` | 10 |
| 13 | `organisasi` | Organisasi | `riwayat_organisasi` | 11 |
| 36 | `jabatan_pjft` | Pemberhentian Sementara JFT | `riwayat_mutasi_jabatan` | 12 |
| 14 | `pendidikan` | Pendidikan | `riwayat_pendidikan` | 13 |
| 32 | `skp` | Sasaran Kerja | `riwayat_skp` | 14 |
| 37 | `status_du` | Status Data Umum | NULL | 15 |
| 23 | `tj` | Tanda Jasa | `riwayat_tanda_jasa` | 16 |
| 39 | `pencantuman_gelar` | Arsip Pencantuman Gelar | `riwayat_pendidikan` | 17 |
| 40 | `transkrip_nilai` | Transkrip Nilai | `riwayat_pendidikan` | 18 |
| 41 | `perjanjian_kerja` | Perjanjian Kerja | `riwayat_mutasi_jabatan` | 19 |
| 42 | `pmk` | Penyesuaian Masa Kerja | `riwayat_pmk` (tabel Fase 4) | 20 |

Perilaku: tidak didaftarkan ke engine Master Data (tanpa UI, tanpa menu ⋮); perubahan hanya lewat migration baru. Kode
aplikasi B-18 / arsip Fase 7 membaca lookup ini (termasuk `tabel_entri`) dan tidak meng-hard-code angka. **Bukan**
`skl.jenis_rwy` (1-5 layanan, Tier 7). `tabel_entri` hanya nama (tanpa FK); 42 menunjuk tabel yang belum ada.

#### 2.4.2 riwayat_keluarga — stub [L] + kode [I] `L_keluarga.php:1035-1050` (`sp`), `:1300-1320` (`sp_process`); seed :1882-1904; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_keluarga` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub `DEFAULT NULL` → NOT NULL [V2] |
| `urutan_perkawinan` | TINYINT NULL | [I] `:1040` (input numerik, default 1 di form) |
| `tgl_perkawinan` | DATE NULL | [I] `:1041` (wajib di form, tetapi seed/snapshot NULL; tidak dijadikan NOT NULL) |
| `kota_perkawinan` | VARCHAR(255) NULL | [I] `:1042` |
| `nama_pasangan` | VARCHAR(255) NULL | [I] `:1043` |
| `jumlah_anak` | TINYINT NOT NULL DEFAULT 0 COMMENT 'jumlah baris detail_anak, dihitung aplikasi' | [I] `:1044` (`count(urutan)`), `:829` (hapus anak) |
| `keterangan` | TINYTEXT NULL | [I] `:1045`; tipe = seed & snapshot `pegawai_keluarga` (kelompok 1) |
| WF+AUD (11 kolom) | awal 2.4 | [L]/[I]/[V2] |

Kunci: PRIMARY; KEY + FK PEG `fk_nip_rwykeluarga_to_pegawai` → `pegawai(nip)` RESTRICT/RESTRICT [K-erd] (b); CHECK
`chk_riwayat_keluarga_status` [V2]. Dirujuk: `detail_anak` (2.4.3); `pegawai_keluarga.id_riwayat_keluarga` (RWY
`fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga`, 131000).
jenis_rwy: 20 `keluarga` (buku nikah; `id_entri` = `id_riwayat_keluarga`).
Perilaku legacy: satu baris = satu perkawinan; data anak di `detail_anak` dan TIDAK ikut alur approval (ditulis langsung
saat pengajuan/ubah, `L_keluarga.php:342-367, 582-640`); hapus mengandalkan CASCADE (A #4).

#### 2.4.3 detail_anak — [K] `simpeg_prod.sql:373-386` persis (deviasi hanya aksi FK)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_detail_anak` | INT NOT NULL AUTO_INCREMENT | [K] |
| `id_riwayat_keluarga` | INT NOT NULL | [K] |
| `urutan` | TINYINT NOT NULL DEFAULT 1 | [K] |
| `tgl_lahir` | DATE NOT NULL | [K] |
| `nama` | VARCHAR(255) NOT NULL | [K] |
| `tempat_lahir` | VARCHAR(255) NULL | [K] (wajib di form `vp_anak`, tetapi DDL NULL → ikut [K]) |
| `keterangan` | TINYTEXT NULL | [K] |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K] |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [K] |

Kunci: PRIMARY; KEY + FK `fk_id_riwayat_keluarga_detanak_to_rwykeluarga` → `riwayat_keluarga(id_riwayat_keluarga)`
**RESTRICT/RESTRICT** ([K] CASCADE/CASCADE → deviasi D K4-b). Tanpa kolom `nip` (pemilik = `riwayat_keluarga.nip`), tanpa
status/audit pengubah ([K]). jenis_rwy: 3 `anak` (akte lahir; `id_entri` = `id_detail_anak`).
Perilaku legacy: ditulis `L_keluarga.php:344, 584, 612` (`sp_anak` `:1111-1175`), dihapus keras `:640, 833`; dibaca
presensi (`L_presensi.php:4493` dst., jumlah anak sebelum tanggal cuti) dan simapi (`A_employee.php:608, 695`).
AUTO_INCREMENT legacy 6094 [K] (6.5).

#### 2.4.4 riwayat_alamat — stub [L] + kode [I] `L_alamat.php:593-680` (`sp`), `:777-800` (`sp_process`); seed :1920-1966; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_alamat` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2] |
| `jenis_alamat` | TINYINT(1) NOT NULL COMMENT '1: KTP, 2: Domisili, 3: Kantor' | [I] `:603`, domain `alamat/form.php:80-82`; NOT NULL [I] (wajib `:499-502`; seed NULL) — K4-8 |
| `alamat_utama` | TINYINT(1) NULL COMMENT '1: Ya, 2: Tidak (jenis_alamat 3 selalu 2)' | [I] `:602, 670`; form radio `alamat/form.php:90-96` |
| `id_provinsi` / `id_kabupaten_kota` / `id_kecamatan` / `id_kelurahan` | CHAR(2) / CHAR(4) / CHAR(7) / CHAR(10) NULL | [I] `:598-601`; tipe = PK wilayah `main` |
| `provinsi`, `provinsi_lain`, `kabupaten_kota`, `kabupaten_kota_lain`, `kecamatan`, `kecamatan_lain`, `kelurahan`, `kelurahan_lain` | VARCHAR(255) NULL | [I] `:604-611, 624-667` (salinan nama master; `*_lain` hanya untuk kode LAIN-LAIN) |
| `kd_pos` | VARCHAR(10) NULL | [I] `:612` (satu kode dari daftar `kelurahan.kd_pos`) |
| `KdPos` | VARCHAR(10) NULL COMMENT salinan `kd_pos` | [I] `:615` (A #9, K4-10) |
| `alamat` | TEXT NULL | [I] `:613` |
| `nama_kantor` | VARCHAR(255) NULL | [I] `:616, 671` (hanya jenis 3) |
| `lantai`, `telp`, `faks` | VARCHAR(50) NULL | [I] `:617-619, 672-674` |
| `keterangan` | TINYTEXT NULL | [I] `:614`; tipe = seed |
| WF+AUD (11 kolom) | awal 2.4 | [L]/[I]/[V2] |

Total 35 kolom. Kunci: PRIMARY; 5 KEY bernama FK; FK PEG `fk_nip_rwyalamat_to_pegawai` (b); FK MAIN (a)
`fk_id_provinsi_riwalamat_provinsi` → `provinsi`, `fk_id_kabupaten_kota_riwalamat_kabkota` → `kabupaten_kota`,
`fk_id_kecamatan_riwalamat_kecamatan` → `kecamatan`, `fk_id_kelurahan_riwalamat_kelurahan` → `kelurahan`; semua
RESTRICT/RESTRICT [K-erd] (seed: wilayah SET NULL/CASCADE); CHECK `chk_riwayat_alamat_status` [V2].
Dirujuk (RWY, 131000): `pegawai_alamat` (`fk_id_riwayat_alamat_pegalamat_riwalamat`) dan `pegawai_alamat_kantor`
(`pegawai_alamat_kantor_ibfk_5`). Kolom bisnis wilayah/alamat/kantor = snapshot kelompok 1 persis (tipe identik).
jenis_rwy: 1 `alamat` (`id_entri` = `id_riwayat_alamat`; tidak wajib untuk jenis 3).
Perilaku legacy: kode wilayah LAIN-LAIN valid terhadap FK (sentinel DBV-003 di `main`, terverifikasi); konsistensi
hierarki wilayah (kelurahan milik kecamatan yang dipilih) tidak ditegakkan DB (legacy juga tidak).

#### 2.4.5 riwayat_karpeg — stub [L] (6 kolom) + kode [I] `L_karpeg.php:160-360, 882-890, 1011, 1077-1085`; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_karpeg` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2]; diisi controller (`Karpeg.php:77`) |
| `jenis_permohonan` | VARCHAR(100) NULL COMMENT 'Permohonan Pertama / Karena Kehilangan' | [I] `:886`, radio `karpeg/form.php:66-73`; panjang [I] (`simpegdev_local`, hanya bukti keberadaan) |
| `file_1` .. `file_5` | VARCHAR(255) NULL (path SK CPNS, SK PNS, SPMT, sertifikat prajabatan, pas foto) | [I] `:193-351` (`arsip/{nip}/karpeg/…`) |
| `file_surat_hilang` | VARCHAR(255) NULL | [I] `:163-191` (jenis Karena Kehilangan) |
| `file_tanda_terima` | VARCHAR(255) NULL (PDF tanda terima saat disetujui) | [I] `:1011` |
| Blok LAYANAN (10 kolom) | awal 2.4 | `status`/`show_notif`/`rated` stub [L] (tipe → [V2]/[K-m]); `reason_note`/`approved_by`/`show_ua_biro` [I] `:1077-1085`; `notif_date` [I] `L_notification.php:4127`; AUD [I] (`created_at` dibaca `:100, 960`) |

Kunci: PRIMARY; KEY + FK PEG `fk_nip_rkarpeg_to_pegawai` (b) RESTRICT/RESTRICT [K-erd]; CHECK `chk_riwayat_karpeg_status`
[V2]. jenis_rwy: — (berkas di kolom path). ERD juga memuat `fk_nip_rwykarpeg_to_pegawai`, tetapi milik tabel backup
`riwayat_kartu_pegawai_20211111` (di luar cakupan).

#### 2.4.6 riwayat_kariskarsu — sama dengan 2.4.5 (`L_kariskarsu.php`), tanpa `jenis_permohonan`, + `file_alasan`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_kariskarsu` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2]; `Kariskarsu.php:97, 168` |
| `file_1` .. `file_5` | VARCHAR(255) NULL (path SK CPNS, SK PNS, buku nikah, laporan perkawinan, pas foto) | [I] `L_kariskarsu.php:163-319` |
| `file_surat_hilang` | VARCHAR(255) NULL | [I] `:323-351` |
| `file_alasan` | VARCHAR(255) NULL (akta kematian / surat cerai) | [I] `:355-383` |
| `file_tanda_terima` | VARCHAR(255) NULL | [I] `:1076` |
| Blok LAYANAN (10 kolom) | awal 2.4 | seperti 2.4.5 (`sp_process` `:1142-1150`) |

Kunci: PRIMARY; KEY + FK PEG `fk_nip_rkariskarsu_to_pegawai` (b) RESTRICT/RESTRICT [K-erd]; CHECK
`chk_riwayat_kariskarsu_status` [V2]. jenis_rwy: —.

#### 2.4.7 riwayat_tanda_jasa — stub [L] + kode [I] `L_tj.php:491-505, 591-610`; `Siasn.php:6045-6070`; `siasn_sync/Rw_tj.php`; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_tanda_jasa` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2] |
| `id_tanda_jasa` | INT NULL COMMENT '44 = LAIN-LAIN (nama di tanda_jasa_lain)' | [I] `:496`; tipe = PK `tanda_jasa` `main` (G-06 1.4 #3) |
| `tanda_jasa` | VARCHAR(255) NULL COMMENT salinan nama master | [I] `:498-500` (= master `main` 255) |
| `tanda_jasa_lain` | VARCHAR(255) NULL | [I] `:501` (hanya id 44) |
| `tgl_sertifikat` | DATE NOT NULL | [I] `:495`; wajib di form; SIASN selalu mengisi (`skDate` atau `tahun-01-01`, `Siasn.php:6063`) — K4-8 |
| `no_sertifikat` | VARCHAR(100) NULL | [I] `:494`; panjang = kelompok 2 `no_sertifikat` |
| `negara` | VARCHAR(100) NULL | [I] form `tj/form.php:118`, `Siasn.php:6067` (A #2, K4-7) |
| `keterangan` | TEXT NULL | [I] `:503`; SIASN menambah akhiran `- Sync SIASN` / `**Upload To SIASN**` (`Siasn.php:6049`, `Rw_tj.php:452-460`) → TEXT (K4-11) |
| `id_rw_siasn` | VARCHAR(100) NULL | [I] `Rw_tj.php:338, 457`; panjang = kelompok 2 |
| `siasn_flag` | TINYINT NOT NULL DEFAULT 0 COMMENT 'sinkron SIASN legacy: 0 belum, 1 sukses, 2 proses/gagal (lihat siasn_error), 3 fallback manual' | [I] A #12 |
| `siasn_error` | TEXT NULL | [I] `Rw_tj.php:459, 524` (JSON respons) |
| WF+AUD (11 kolom) | awal 2.4 | [L]/[I]/[V2] |

Kunci: PRIMARY; KEY `fk_nip_rwytj_to_pegawai`, `fk_id_tanda_jasa_rtj_to_tj`; FK PEG (b) + FK MAIN `fk_id_tanda_jasa_rtj_to_tj`
→ `tanda_jasa(id_tanda_jasa)` (a), RESTRICT/RESTRICT [K-erd]; CHECK `chk_riwayat_tanda_jasa_status` [V2].
Dirujuk (RWY, 131000): `pegawai_tanda_jasa.id_riwayat_tanda_jasa` (`fk_id_riwayat_tanda_jasa_ptj_to_rtj`).
jenis_rwy: 23 `tj` (sertifikat; `id_entri` = `id_riwayat_tanda_jasa`).

#### 2.4.8 riwayat_organisasi — stub [L] + kode [I] `L_organisasi.php:512-522` (`set_param`), `:606-625`; FK [K-erd]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_riwayat_organisasi` | INT NOT NULL AUTO_INCREMENT | [L] stub |
| `nip` | VARCHAR(30) NOT NULL | [L] stub → NOT NULL [V2] |
| `nama_organisasi` | VARCHAR(255) NULL | [I] `:515` (panjang: `simpegdev_local`, hanya bukti keberadaan) |
| `kedudukan` | VARCHAR(255) NULL COMMENT 'jabatan dalam organisasi' | [I] `:516`, label form `organisasi/form.php:89` |
| `tgl_mulai` | DATE NOT NULL | [I] `:517`; wajib di form (`organisasi/form.php:100`), kunci urutan daftar & aturan duplikat — K4-8 |
| `tgl_akhir` | DATE NULL COMMENT 'NULL = masih aktif' | [I] `:518`; form edit menonaktifkan isian kosong (`organisasi/form.php:106`) |
| `keterangan` | TEXT NULL | [I] `:520` (textarea; tanpa snapshot) |
| WF+AUD (11 kolom) | awal 2.4 | [L]/[I]/[V2] |

Kunci: PRIMARY; KEY + FK PEG `fk_nip_rwyorganisasi_to_pegawai` (b) RESTRICT/RESTRICT [K-erd]; CHECK
`chk_riwayat_organisasi_status` [V2]. Tanpa snapshot. jenis_rwy: 13 `organisasi`.

#### 2.4.9 document_attachment — [K] `simpeg_prod.sql:497-519` persis + FK [V2] ke `jenis_rwy`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_attachment` | INT NOT NULL AUTO_INCREMENT | [K] |
| `NIP` | VARCHAR(30) NOT NULL | [K] (nama huruf besar dipertahankan; registry B-06 membandingkan tanpa peka huruf) |
| `document_id` | INT NULL | [K] (SIASN menulis NULL, `Siasn.php:1433`) |
| `filename` | VARCHAR(200) NULL | [K] |
| `id_riwayat` | INT NULL | [K]; kode `jenis_rwy` (0 = belum terhubung) |
| `nama_riwayat` | VARCHAR(255) NULL | [K]; label bebas legacy (mis. "Data Anak", "Riwayat Pelatihan") |
| `id_entri` | VARCHAR(100) NULL | [K]; id baris di `jenis_rwy.tabel_entri`, polimorfik, tanpa FK |
| `tag` | VARCHAR(255) NULL | [K] ([K-m] `da_deleted.tag` 200 — hanya daftar kolom yang dijamin) |
| `path`, `url`, `basename`, `display_name` | VARCHAR(200) NULL | [K] |
| `file_size` | INT NULL | [K] (kilobyte, A #7) |
| `file_ext`, `file_type` | VARCHAR(100) NULL | [K] |
| `created_at` | DATETIME NULL DEFAULT CURRENT_TIMESTAMP | [K] (bukan pola AUD riwayat) |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [K] |

Kunci: PRIMARY (`id_attachment`); KEY `fk_NIP_da_to_pegawai` (`NIP`) [K]; KEY `id_riwayat_id_entri` (`id_riwayat`,
`id_entri`) [K]; FK PEG `fk_NIP_da_to_pegawai` → `pegawai(nip)` **RESTRICT/RESTRICT** ([K] CASCADE/CASCADE, D K4-c);
FK [V2] `fk_id_riwayat_da_to_jenis_rwy` → `jenis_rwy(id_jenis_rwy)` RESTRICT/RESTRICT, memakai KEY legacy
`id_riwayat_id_entri` (kolom terdepan), tanpa KEY baru (pengecualian 2.0.6, K4-4). Tanpa CHECK.
Perilaku legacy: satu entri boleh punya banyak lampiran; hapus riwayat menghapus lampirannya eksplisit; trigger
`da_deleted` [K-m] tidak ditiru. AUTO_INCREMENT legacy 169499 [K] (6.5).

#### 2.4.10 Ringkasan constraint kelompok 4

| Tabel | PRIMARY | UNIQUE | KEY (non-PK) | FK (keranjang) | CHECK |
|---|---|---|---|---|---|
| `jenis_rwy` | `id_jenis_rwy` | `uq_jenis_rwy_kode`, `uq_jenis_rwy_nama` | — | — | `chk_jenis_rwy_status` |
| `riwayat_keluarga` | `id_riwayat_keluarga` | — | `fk_nip_rwykeluarga_to_pegawai` | `fk_nip_rwykeluarga_to_pegawai` (b) | `chk_riwayat_keluarga_status` |
| `detail_anak` | `id_detail_anak` | — | `fk_id_riwayat_keluarga_detanak_to_rwykeluarga` | `fk_id_riwayat_keluarga_detanak_to_rwykeluarga` → `riwayat_keluarga` | — |
| `riwayat_alamat` | `id_riwayat_alamat` | — | 5 KEY bernama FK | `fk_nip_rwyalamat_to_pegawai` (b); `fk_id_provinsi_riwalamat_provinsi`, `fk_id_kabupaten_kota_riwalamat_kabkota`, `fk_id_kecamatan_riwalamat_kecamatan`, `fk_id_kelurahan_riwalamat_kelurahan` (a) | `chk_riwayat_alamat_status` |
| `riwayat_karpeg` | `id_riwayat_karpeg` | — | `fk_nip_rkarpeg_to_pegawai` | `fk_nip_rkarpeg_to_pegawai` (b) | `chk_riwayat_karpeg_status` |
| `riwayat_kariskarsu` | `id_riwayat_kariskarsu` | — | `fk_nip_rkariskarsu_to_pegawai` | `fk_nip_rkariskarsu_to_pegawai` (b) | `chk_riwayat_kariskarsu_status` |
| `riwayat_tanda_jasa` | `id_riwayat_tanda_jasa` | — | `fk_nip_rwytj_to_pegawai`, `fk_id_tanda_jasa_rtj_to_tj` | `fk_nip_rwytj_to_pegawai` (b); `fk_id_tanda_jasa_rtj_to_tj` (a) | `chk_riwayat_tanda_jasa_status` |
| `riwayat_organisasi` | `id_riwayat_organisasi` | — | `fk_nip_rwyorganisasi_to_pegawai` | `fk_nip_rwyorganisasi_to_pegawai` (b) | `chk_riwayat_organisasi_status` |
| `document_attachment` | `id_attachment` | — | `fk_NIP_da_to_pegawai`, `id_riwayat_id_entri` | `fk_NIP_da_to_pegawai` (b); `fk_id_riwayat_da_to_jenis_rwy` [V2] → `jenis_rwy` | — |

Semua FK `ON DELETE RESTRICT ON UPDATE RESTRICT`; nama FK = ERD `simpeg01.erd` persis, kecuali
`fk_id_riwayat_da_to_jenis_rwy` [V2] (gaya legacy `fk_<kolom>_<singkatan tabel>_to_<induk>`). Semua nama ≤ 64 karakter,
unik per schema. FK masuk ke tabel kelompok 4 (131000, kelompok 1): `fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga`,
`fk_id_riwayat_alamat_pegalamat_riwalamat`, `pegawai_alamat_kantor_ibfk_5`, `fk_id_riwayat_tanda_jasa_ptj_to_rtj`.
Registry FK → `pegawai(nip)` kelompok 4 (7 FK) = `NipReferenceRegistryTest::REGISTRY` versi `130700`/`130800`/`130900`
(nama dicocokkan). Kelompok 4 tidak menambah kolom NIP non-FK.

### 2.5 Ringkasan FK: sudah bisa dipasang vs menunggu DBV-008

- **Target sudah ada di `main`:** `provinsi`, `kabupaten_kota`, `kecamatan`, `kelurahan`, `agama`, `jenis_pegawai`, `jenis_status`, `pangkat`, `jenis_kp`, `gol_pppk`, `jenjang_pendidikan`, `bidang_pendidikan`, `jurusan_pendidikan`, `diklat`, `tingkat_hukdis`, `jenis_hukdis`, `tanda_jasa`; ditambah `pegawai` (DBV-012) dan `jenis_rwy` (DBV-013).
- **Menunggu G-02/DBV-008** (`group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `jabatan`, `jabatan_koordinasi`, `rumpun_jabatan`):

| Tabel | Jumlah | Nama FK |
|---|---|---|
| `pegawai_mutasi_jabatan` | 13 | `fk_id_group_jabatan_pmj_to_gj`, `fk_id_sub_group_jabatan_pmj_to_sgj`, `fk_id_unit_pmj_to_unit`, `fk_id_satker_pmj_to_satker`, `fk_id_jabatan_pmj_to_jabatan`, `fk_id_atasan_es_1_pmj_to_jabatan` .. `fk_id_atasan_es_4_pmj_to_jabatan`, `fk_id_jabatan_koord_pmj_to_jabkoor`, `fk_id_atasan_es_3_koord_pmj_to_jabkoor`, `fk_id_atasan_es_4_koord_pmj_to_jabkoor`, `pegawai_mutasi_jabatan_ibfk_02` (rumpun_jabatan) |
| `pegawai_ak` | 1 | `fk_id_jabatan_cak_to_jab` |
| `riwayat_mutasi_jabatan` | 13 | `fk_id_group_jabatan_rwymj_to_gj`, `fk_id_sub_group_jabatan_rwymj_to_sgj`, `fk_id_unit_rwymj_to_unit`, `fk_id_satker_rwymj_to_satker`, `fk_id_jabatan_rwymj_to_jabatan`, `fk_id_atasan_es_1_rwymj_to_jabatan` .. `fk_id_atasan_es_4_rwymj_to_jabatan`, `fk_id_jabatan_koord_rmj_to_jabkoor`, `fk_id_atasan_es_3_koord_rmj_to_jabkoor`, `fk_id_atasan_es_4_koord_rmj_to_jabkoor`, `riwayat_mutasi_jabatan_ibfk_02` (rumpun_jabatan) |
| `pegawai_plt` | 10 | `fk_id_group_jabatan_plt_to_gj`, `fk_id_group_jabatan_plt_plt_to_gj`, `fk_id_sub_group_jabatan_plt_to_sgj`, `fk_id_sub_group_jabatan_plt_plt_to_sgj`, `fk_id_unit_plt_to_unit`, `fk_id_unit_plt_plt_to_unit`, `fk_id_satker_plt_to_satker`, `fk_id_satker_plt_plt_to_satker`, `fk_id_jabatan_plt_to_jabatan`, `fk_id_jabatan_koord_plt_to_jabkoord` |
| `pegawai_plh` | 10 | `fk_pegawai_plh_ibfk_02` (jabatan), `_03` (jabatan_koordinasi), `_04`/`_08` (group_jabatan), `_05`/`_09` (sub_group_jabatan), `_06`/`_10` (unit), `_07`/`_11` (satker) |
| `riwayat_ak` | 1 | `fk_id_jabatan_rak_to_jab` |
| `riwayat_ak_siasn` | 1 | `fk_id_jabatan_rw_ak_siasn_02` |
| `konv_ak` | 3 | `fk_id_jabatan_konvak_to_jabatan`, `fk_id_unit_konvak_to_unit`, `fk_id_satker_konvak_to_satker` |
| `riwayat_lckh` | 2 | `fk_riwayat_lckh_ibfk_03` (unit), `_04` (satker) |

- Dari FK di atas, 10 menunjuk tabel yang **belum ada di draf DBV-008**: `jabatan_koordinasi` (`pegawai_mutasi_jabatan` 3, `riwayat_mutasi_jabatan` 3, `pegawai_plt` 1, `pegawai_plh` 1) dan `rumpun_jabatan` (`pegawai_mutasi_jabatan` 1, `riwayat_mutasi_jabatan` 1) — 4.1 #9. Total FK G-02 ditahan: 14 (B-01) + 40 (B-02) = 54.
- **Tanpa FK walau kolomnya ada** (ikut ERD): `riwayat_diklat.id_sub_group_jabatan` / `id_rumpun_sertifikasi` / `id_lembaga_sertifikasi`, `absen_ijin.kategori` / `id_parent`, `pegawai_mutasi_jabatan.kelas_jabatan`, kolom teks kursem di `riwayat_seminar`, `riwayat_skp_periodik.periode_id` (relasi logis ke `bkn_periode_ekinper`).

### 2.6 Ringkasan nama constraint per tabel

Dibangkitkan dari `information_schema` setelah `php spark migrate` penuh di DB scratch (6.2); FK yang ditahan (Bagian 7)
tidak termasuk. KEY non-unik mencakup KEY bernama FK (termasuk KEY untuk FK G-02 yang ditahan) dan KEY legacy.

| Migration | Tabel | Kolom | PRIMARY | UNIQUE | KEY non-unik | FK terpasang (→ induk) | CHECK |
|---|---|---|---|---|---|---|---|
| `120000` | `pegawai` | 40 | `nip` | — | 6 | `fk_id_agama_peg_to_agama` → `agama`; `fk_id_jenis_pegawai_peg_to_jenis_pegawai` → `jenis_pegawai`; `fk_id_jenis_status_peg_to_jenis_status` → `jenis_status`; `fk_id_kabupaten_kota_lahir_peg_to_kab_kota` → `kabupaten_kota`; `fk_id_provinsi_lahir_peg_to_prov` → `provinsi` | `chk_pegawai_flag_update`, `chk_pegawai_jenis_kelamin`, `chk_pegawai_status` |
| `120000` | `pegawai_hist` | 48 | `id_pegawai_hist` | — | 8 | `fk_peg_hist_ibfk_01` → `pegawai`; `fk_peg_hist_ibfk_02` → `provinsi`; `fk_peg_hist_ibfk_03` → `kabupaten_kota`; `fk_peg_hist_ibfk_04` → `agama`; `fk_peg_hist_ibfk_05` → `jenis_pegawai`; `fk_peg_hist_ibfk_06` → `jenis_status` | `chk_pegawai_hist_flag_update`, `chk_pegawai_hist_jenis_kelamin`, `chk_pegawai_hist_status` |
| `120000` | `pegawai_foto` | 4 | `id_pegawai_foto` | — | 1 | `fk_nip_pegfoto_to_peg` → `pegawai` | — |
| `120100` | `pegawai_kp` | 22 | `nip` | — | 3 | `fk_id_jenis_kp_peg_kp_to_jenis_kp` → `jenis_kp`; `fk_id_pangkat_peg_kp_to_pangkat` → `pangkat`; `fk_id_riwayat_kp_peg_kp_to_rwy_kp` → `riwayat_kp`; `fk_nip_pegkp_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_cpns` | 22 | `nip` | — | 3 | `fk_id_jenis_kp_pcpns_to_jenis_kp` → `jenis_kp`; `fk_id_pangkat_pcpns_to_pangkat` → `pangkat`; `fk_id_riwayat_kp_pcpns_to_rwy_kp` → `riwayat_kp`; `fk_nip_pcpns_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_pns` | 22 | `nip` | — | 3 | `fk_id_jenis_kp_ppns_to_jenis_kp` → `jenis_kp`; `fk_id_pangkat_ppns_to_pangkat` → `pangkat`; `fk_id_riwayat_kp_ppns_to_rwy_kp` → `riwayat_kp`; `fk_nip_ppns_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_kgb` | 11 | `nip` | — | 2 | `fk_id_riwayat_kgb_pkgb_to_rkgb` → `riwayat_kgb`; `fk_nip_pegkgb_to_pegawai` → `pegawai`; `pegawai_kgb_ibfk_01` → `gol_pppk` | — |
| `120100` | `pegawai_pendidikan` | 19 | `nip` | — | 4 | `fk_id_bidang_pendidikan_pegpend_to_bpend` → `bidang_pendidikan`; `fk_id_jenjang_pendidikan_pegpend_to_jpend` → `jenjang_pendidikan`; `fk_id_jurusan_pendidikan_pegpend_to_jupend` → `jurusan_pendidikan`; `fk_id_riwayat_pendidikan_pegpend_to_rpend` → `riwayat_pendidikan`; `fk_nip_pegpend_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_diklat` | 13 | `nip` | — | 2 | `fk_id_diklat_pegdiklat_to_diklat` → `diklat`; `fk_id_riwayat_diklat_pegdiklat_to_rwydiklat` → `riwayat_diklat`; `fk_nip_pegdiklat_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_hukdis` | 20 | `nip` | — | 4 | `fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis` → `jenis_hukdis`; `fk_id_pangkat_peg_hukdis_to_pangkat` → `pangkat`; `fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis` → `riwayat_hukdis`; `fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis` → `tingkat_hukdis`; `fk_nip_peghukdis_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_ak` | 12 | `nip` | — | 3 | `fk_id_pangkat_cak_to_pangkat` → `pangkat`; `fk_id_riwayat_ak_cak_to_rak` → `riwayat_ak`; `fk_nip_cak_to_peg` → `pegawai` | — |
| `120100` | `pegawai_keluarga` | 9 | `nip` | — | 1 | `fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga` → `riwayat_keluarga`; `fk_nip_pegkeluarga_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_alamat` | 18 | `nip` | — | 5 | `fk_id_kabupaten_kota_pegalamat_kabkota` → `kabupaten_kota`; `fk_id_kecamatan_pegalamat_kecamatan` → `kecamatan`; `fk_id_kelurahan_pegalamat_kelurahan` → `kelurahan`; `fk_id_provinsi_pegalamat_provinsi` → `provinsi`; `fk_id_riwayat_alamat_pegalamat_riwalamat` → `riwayat_alamat`; `fk_nip_pegalamat_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_alamat_kantor` | 22 | `nip` | — | 5 | `pegawai_alamat_kantor_ibfk_1` → `kabupaten_kota`; `pegawai_alamat_kantor_ibfk_2` → `kecamatan`; `pegawai_alamat_kantor_ibfk_3` → `kelurahan`; `pegawai_alamat_kantor_ibfk_4` → `provinsi`; `pegawai_alamat_kantor_ibfk_5` → `riwayat_alamat`; `pegawai_alamat_kantor_ibfk_6` → `pegawai` | — |
| `120100` | `pegawai_tanda_jasa` | 10 | `nip` | — | 2 | `fk_id_riwayat_tanda_jasa_ptj_to_rtj` → `riwayat_tanda_jasa`; `fk_id_tanda_jasa_pegtj_to_tj` → `tanda_jasa`; `fk_nip_pegtj_to_pegawai` → `pegawai` | — |
| `120100` | `pegawai_mutasi_jabatan` | 52 | `nip` | — | 15 | `fk_id_riwayat_mutasi_jabatan_pmj_to_rmj` → `riwayat_mutasi_jabatan`; `fk_nip_pmj_to_pegawai` → `pegawai`; `pegawai_mutasi_jabatan_ibfk_01` → `gol_pppk` | — |
| `130000` | `jenis_rwy` | 8 | `id_jenis_rwy` | `uq_jenis_rwy_kode`, `uq_jenis_rwy_nama` | 0 | — | `chk_jenis_rwy_status` |
| `130100` | `riwayat_mutasi_jabatan` | 65 | `id_riwayat_mutasi_jabatan` | — | 15 | `fk_nip_rwymutasijabatan_to_pegawai` → `pegawai`; `riwayat_mutasi_jabatan_ibfk_01` → `gol_pppk` | `chk_riwayat_mutasi_jabatan_status` |
| `130100` | `pegawai_plt` | 21 | `id_pegawai_plt` | — | 11 | `fk_nip_plt_plt_to_pegawai` → `pegawai` | `chk_pegawai_plt_status` |
| `130100` | `pegawai_plh` | 21 | `id_pegawai_plh` | — | 11 | `fk_pegawai_plh_ibfk_01` → `pegawai` | `chk_pegawai_plh_status` |
| `130200` | `riwayat_kp` | 32 | `id_riwayat_kp` | — | 3 | `fk_id_jenis_kp_rwy_kp_to_jenis_kp` → `jenis_kp`; `fk_id_pangkat_rwy_kp_to_pangkat` → `pangkat`; `fk_nip_rwykp_to_pegawai` → `pegawai` | `chk_riwayat_kp_status` |
| `130200` | `riwayat_kgb` | 21 | `id_riwayat_kgb` | — | 2 | `fk_nip_rwykgb_to_pegawai` → `pegawai`; `riwayat_kgb_ibfk_01` → `gol_pppk` | `chk_riwayat_kgb_status` |
| `130300` | `riwayat_pendidikan` | 31 | `id_riwayat_pendidikan` | — | 4 | `fk_id_bidang_pendidikan_rpend_to_bpend` → `bidang_pendidikan`; `fk_id_jenjang_pendidikan_rpend_to_jpend` → `jenjang_pendidikan`; `fk_id_jurusan_pendidikan_rpend_jupend` → `jurusan_pendidikan`; `fk_nip_rwypendidikan_to_pegawai` → `pegawai` | `chk_riwayat_pendidikan_status` |
| `130300` | `riwayat_diklat` | 33 | `id_riwayat_diklat` | — | 2 | `fk_id_diklat_rdiklat_to_diklat` → `diklat`; `fk_nip_rwydiklat_to_pegawai` → `pegawai` | `chk_riwayat_diklat_jenis_diklat`, `chk_riwayat_diklat_status` |
| `130300` | `riwayat_seminar` | 24 | `id_riwayat_seminar` | — | 1 | `fk_nip_rwyseminar_to_pegawai` → `pegawai` | `chk_riwayat_seminar_status` |
| `130400` | `bkn_periode_ekinper` | 11 | `id` | — | 1 | — | — |
| `130400` | `riwayat_skp` | 34 | `id_riwayat_skp` | — | 1 | `fk_nip_rwyskp_to_pegawai` → `pegawai` | `chk_riwayat_skp_status` |
| `130400` | `riwayat_skp_periodik` | 36 | `id_riwayat_skp_periodik` | — | 2 | — | `chk_riwayat_skp_periodik_status` |
| `130400` | `riwayat_lckh` | 27 | `id_riwayat_lckh` | — | 6 | `fk_riwayat_lckh_ibfk_01` → `pegawai`; `fk_riwayat_lckh_ibfk_02` → `pegawai` | `chk_riwayat_lckh_status` |
| `130500` | `absen_ijin` | 28 | `id` | — | 5 | `fk_nip_abijin_to_pegawai` → `pegawai` | `chk_absen_ijin_affect_tukin`, `chk_absen_ijin_jenis_dinas`, `chk_absen_ijin_status` |
| `130600` | `riwayat_hukdis` | 24 | `id_riwayat_hukdis` | — | 4 | `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis` → `jenis_hukdis`; `fk_id_pangkat_rwyhukdis_to_pangkat` → `pangkat`; `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis` → `tingkat_hukdis`; `fk_nip_rwyhukdis_to_pegawai` → `pegawai` | `chk_riwayat_hukdis_status` |
| `130600` | `riwayat_ak` | 48 | `id_riwayat_ak` | — | 3 | `fk_id_pangkat_rak_to_pangkat` → `pangkat`; `fk_nip_rak_to_peg` → `pegawai` | `chk_riwayat_ak_status` |
| `130600` | `riwayat_ak_siasn` | 28 | `id_riwayat_ak_siasn` | — | 3 | `fk_id_pangkat_rw_ak_siasn_03` → `pangkat`; `fk_nip_rw_ak_siasn_01` → `pegawai` | `chk_riwayat_ak_siasn_status` |
| `130600` | `konv_ak` | 21 | `id` | — | 5 | `fk_id_pangkat_konvak_to_pangkat` → `pangkat`; `fk_nip_konvak_to_pegawai` → `pegawai` | `chk_konv_ak_status` |
| `130600` | `ignore_konv_ak` | 2 | `nip, ignore_date` | — | 0 | `fk_nip_ignorekonvak_to_pegawai` → `pegawai` | — |
| `130700` | `riwayat_keluarga` | 19 | `id_riwayat_keluarga` | — | 1 | `fk_nip_rwykeluarga_to_pegawai` → `pegawai` | `chk_riwayat_keluarga_status` |
| `130700` | `detail_anak` | 9 | `id_detail_anak` | — | 1 | `fk_id_riwayat_keluarga_detanak_to_rwykeluarga` → `riwayat_keluarga` | — |
| `130700` | `riwayat_alamat` | 35 | `id_riwayat_alamat` | — | 5 | `fk_id_kabupaten_kota_riwalamat_kabkota` → `kabupaten_kota`; `fk_id_kecamatan_riwalamat_kecamatan` → `kecamatan`; `fk_id_kelurahan_riwalamat_kelurahan` → `kelurahan`; `fk_id_provinsi_riwalamat_provinsi` → `provinsi`; `fk_nip_rwyalamat_to_pegawai` → `pegawai` | `chk_riwayat_alamat_status` |
| `130800` | `riwayat_karpeg` | 20 | `id_riwayat_karpeg` | — | 1 | `fk_nip_rkarpeg_to_pegawai` → `pegawai` | `chk_riwayat_karpeg_status` |
| `130800` | `riwayat_kariskarsu` | 20 | `id_riwayat_kariskarsu` | — | 1 | `fk_nip_rkariskarsu_to_pegawai` → `pegawai` | `chk_riwayat_kariskarsu_status` |
| `130800` | `riwayat_tanda_jasa` | 23 | `id_riwayat_tanda_jasa` | — | 2 | `fk_id_tanda_jasa_rtj_to_tj` → `tanda_jasa`; `fk_nip_rwytj_to_pegawai` → `pegawai` | `chk_riwayat_tanda_jasa_status` |
| `130800` | `riwayat_organisasi` | 18 | `id_riwayat_organisasi` | — | 1 | `fk_nip_rwyorganisasi_to_pegawai` → `pegawai` | `chk_riwayat_organisasi_status` |
| `130900` | `document_attachment` | 17 | `id_attachment` | — | 2 | `fk_id_riwayat_da_to_jenis_rwy` → `jenis_rwy`; `fk_NIP_da_to_pegawai` → `pegawai` | — |

Total: 43 tabel, 108 FK terpasang, 32 CHECK.

## 3. Deviasi dari legacy & nilai [I]

### 3.1 Deviasi bersama

| # | Item | Legacy | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | Aksi FK | `nip` [K]/[L] CASCADE/CASCADE; master [L] SET NULL/CASCADE; `detail_anak` [K] CASCADE | Semua RESTRICT/RESTRICT | K1/G2: aplikasi tidak menghapus keras data yang masih dirujuk; ganti NIP lewat B-06 (salin → arahkan ulang → hapus). Service wajib menghapus anak (`detail_anak`, lampiran) secara eksplisit |
| 2 | `nip` riwayat | Stub [L] `DEFAULT NULL` | NOT NULL | Setiap baris riwayat milik satu pegawai; audit NULL/orphan sebelum impor (6.5) |
| 3 | Status riwayat | Stub [L] VARCHAR(5) DEFAULT '1' | TINYINT NOT NULL DEFAULT 0 + CHECK (2.0.3) | Pola [K-m] `d_lkh`/`d_riwayat_cuti` (`int NOT NULL DEFAULT '0'`); nilai legacy di luar domain dinormalkan saat impor |
| 4 | CHECK baru | Tidak ada | Daftar 2.0.6 | MySQL 3819 / MariaDB 4025 |
| 5 | `ignore_konv_ak.nip` | VARCHAR(50) [K] | VARCHAR(30) | Samakan dengan `pegawai.nip` (FK beda panjang tetap bisa, tetapi K1 menetapkan 30); audit panjang > 30 |
| 6 | Trigger `absen_ijin_beDel` dan arsip `d_*` | Trigger salin ke `d_konket`/`d_*` sebelum DELETE | Tidak ditiru | Jejak perubahan lewat `audit_logs` |
| 7 | `jenis_rwy` + FK `fk_id_riwayat_da_to_jenis_rwy` | Konstanta PHP, tanpa tabel | Lookup [V2] + FK | DoD B-02 (lookup eksplisit, bukan hard-code) |
| 8 | KEY LKH | Tidak diketahui ([K-m] tanpa index) | `idx_riwayat_lckh_nip_tgl`, `idx_riwayat_lckh_atasan_status` [V2] | Pola query daftar & approval atasan |
| 9 | `created_at` di riwayat [I] | Tidak ada di stub [L] | `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` [I] | Pola `absen_ijin`/`d_lkh`/`d_riwayat_cuti` |
| 10 | `pegawai_hist.flag_update` | Default tidak diketahui | DEFAULT 1 + CHECK 1/2/3 | Nilai dari kode (1.2 #4) |
| 11 | Status 3 "Diproses" di 11 tabel riwayat | Ditulis form admin legacy | CHECK `IN (0,1,2,3,10)` | 1.5 #2; tabel lain tetap 0/1/2/10 |
| 12 | FK masuk `pengguna.nip`/`faq_rate.nip` | `faq_rate` CASCADE; A-01/G-10 menjadwalkan di B-01 | Ditahan (Bagian 7), RESTRICT saat aktif | 1.5 #1, 4.1 #12 |

Nilai **[I]** yang tersisa ada di Bagian 6.4.

### 3.2 Kelompok 1

| # | Item | Legacy | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | Aksi FK semua tabel B-01, FK masuk, FK RWY | [L]/seed: `nip` CASCADE/CASCADE, master & RWY SET NULL/CASCADE; `faq_rate` CASCADE | RESTRICT/RESTRICT | K1/G2; ganti NIP lewat B-06 (salin → arahkan ulang → hapus) |
| 2 | `pegawai_hist.nip` | NULL (stub [L]/seed) | NOT NULL | Setiap pengajuan milik satu pegawai |
| 3 | `pegawai_hist.flag_update` | stub [L] DEFAULT 0 | DEFAULT 1 + CHECK 1..3 | Nilai dari kode (A #2) |
| 4 | CHECK baru | tidak ada | `pegawai`: status/flag_update/jenis_kelamin; `pegawai_hist`: flag_update/status/jenis_kelamin | Domain pasti dari kode/COMMENT [L] (2.0.6) |
| 5 | COMMENT | `status` "10: Deleted"; `flag_update`/`updated_by`/`approved_by`/`email`/`foto`/`jym` tanpa COMMENT | COMMENT v2 | Kosmetik, dokumentasi domain |
| 6 | KEY `pegawai_mutasi_jabatan` | [L] hanya 2 KEY FK | + 13 KEY bernama FK [K-erd] (total 15) | FK RWY/G02 dipasang belakangan cukup ADD CONSTRAINT (2.0.6) |
| 7 | `pegawai_foto.created_at` | tidak diketahui | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP [V2] | Urutan galeri; kolom lain menunggu dump |
| 8 | Tipe/NULL 11 snapshot [I] | DDL tidak ada | Tipe = riwayat asal / pola [L] `pegawai_kp`; nullable kecuali NN di rencana | Verifikasi dengan SHOW CREATE TABLE (6.4.1) |
| 9 | `pegawai_hukdis.keterangan` | tidak diketahui | TEXT (bukan TINYTEXT pola `pegawai_kp`) | = `riwayat_hukdis.keterangan` (kelompok 3 K3-9) |
| 10 | Blok WF `pegawai_hist` | seed/[K] `absen_ijin`: `show_notif`/`show_ua_*` INT DEFAULT 0 | `show_notif` TINYINT NN DEFAULT 0, `show_ua_*` TINYINT NULL | Konvensi 2.0.3; menunggu keputusan 4.1 #3 |
| 11 | Trigger pengisi snapshot/foto | diduga ada (A #1 induk, A #3) | tidak ditiru | Sinkron di aplikasi (ADR-006) |

### 3.3 Kelompok 2

| # | Item | Legacy | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| K2-a | `pegawai_plt.nip_plt`, `pegawai_plh.nip_plh` | Tanpa DDL; wajib di form | VARCHAR(30) NOT NULL + FK PEG RESTRICT | Satu-satunya pemilik baris; FK ERD CASCADE diasumsikan seperti FK `nip` lain |
| K2-b | Status Plt/Plh | 1/2, hapus = hard delete | TINYINT NOT NULL DEFAULT 1 + CHECK 1/2/10 | Soft delete B-07; rencana 4.3 |
| K2-c | `pegawai_plt/plh.tgl_mulai/tgl_akhir` | Tanpa DDL; wajib di form | DATE NOT NULL [I] | Dipakai filter periode aktif; audit tanggal nol/NULL (6.5.2 #4) |
| K2-d | `jenis_diklat` riwayat | Tanpa DDL; domain 1-5 dari kode | TINYINT(1) NOT NULL + CHECK 1-5 | Domain pasti (2.0.6), sama dengan master `diklat` |
| K2-e | `tgl_sertifikat` diklat/seminar | Tanpa DDL | DATE NOT NULL [I] | Wajib di form & SIASN |
| K2-f | `riwayat_pendidikan.glr_awal/glr_akhir` | Ditulis SIASN (`Rw_pendidikan.php`) | VARCHAR(50) NULL [I] | Tidak ada di rencana (A #2) |
| K2-g | `riwayat_mutasi_jabatan.kredit_jft` | Tanpa titik tulis PHP | INT NULL [L pmj] | Paritas kolom snapshot (A #6) |
| K2-h | Tipe [I] tanpa DDL | — | `jumlah_jp` SMALLINT; `keterangan` TEXT (diklat/seminar) / TINYTEXT (KP/KGB/pendidikan); `mker_gol_th` TINYINT; `jym` V255; `no_sertifikat` V100; `id_rumpun_sertifikasi`/`id_lembaga_sertifikasi` INT | Menunggu `SHOW CREATE TABLE` (6.4.2) |
| K2-i | FK G-02 riwayat/penugasan | `SET NULL`/`CASCADE` [L]/seed | RESTRICT/RESTRICT, migration ditahan | Keranjang (d); 6 FK menunggu `jabatan_koordinasi`/`rumpun_jabatan` |

### 3.4 Kelompok 3

| # | Item | Legacy | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| K3-a | PK `riwayat_lckh` | [K-m] `d_lkh.id_riwayat_lckh int NOT NULL` tanpa PK | PK + AUTO_INCREMENT | Tabel sumber pasti ber-PK karena dirujuk FK `aa_lkh` [K] :27 |
| K3-b | COMMENT `riwayat_lckh.status` | `'0: Wating, 1: Approved, 2: Rejected, 3: Revisi'` | `'0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Revisi, 10: Dihapus'` + CHECK `IN (0,1,2,3,10)` | Daftar legacy memfilter `NOT IN (2,10)` (`L_lkh.php:117`) |
| K3-c | CHECK `absen_ijin` | Tidak ada | `status IN ('W','V','X','10')`, `affect_tukin IN (1,2)`, `jenis_dinas` NULL/0/1 | Domain dari COMMENT [K]; nilai angka legacy dinormalkan saat impor (A #2) |
| K3-d | Status `riwayat_skp_periodik`, `riwayat_ak_siasn` | Tidak diketahui | TINYINT NOT NULL **DEFAULT 1** + CHECK 0/1/2/10 | Satu-satunya penulis (sinkron BKN/SIASN) selalu menulis 1 (K3-5) |
| K3-e | Kolom tambahan `riwayat_ak` | Tidak ada di rencana | `tgl_ak_terakhir`, `tgl_pengajuan_ak`, `file_pengantar`, `file_dupak` [I] | Jalur Current AK aktif (A #3) |
| K3-f | `riwayat_ak_siasn.keterangan`, `riwayat_skp_periodik.keterangan` | Tidak ditulis kode | TEXT NULL [I] | Dibaca view legacy (A #4, #5) |
| K3-g | KEY [V2] `riwayat_skp_periodik` | Tidak diketahui | `idx_riwayat_skp_periodik_periode_nip`, `idx_riwayat_skp_periodik_nip` | Pola query cron/grafik/daftar |
| K3-h | Tipe teks [I] | Tidak diketahui | nama/jabatan V255, `no_sk`/`no_hpak`/`no_pak` V100, keterangan TEXT, nilai AK DECIMAL(8,3), nilai SKP DECIMAL(6,2), `tahun` SKP SMALLINT | Pola kolom sejenis [L]/[K]; dicek `MAX(CHAR_LENGTH)` sebelum impor (F-dump #6) |
| K3-i | `absen_ijin` AUTO_INCREMENT | 326331 [K] :69 | Tidak ditulis | G2; counter disalin setelah impor (Bagian 6.5 #6) |

Deviasi bersama yang juga berlaku di kelompok 3 (sudah ada di Bagian 3 dokumen gabungan): #1 aksi FK (CASCADE → RESTRICT untuk `absen_ijin`, `ignore_konv_ak`, dan FK `nip` [K-erd] lainnya), #2 `nip` NOT NULL, #3 status TINYINT, #5 `ignore_konv_ak.nip` 50 → 30, #6 trigger `absen_ijin_beDel`/`d_konket`/`d_lkh` tidak ditiru, #8 KEY LKH, #9 `created_at` [I].

### 3.5 Kelompok 4

| # | Item | Legacy | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| K4-a | `nip` riwayat kelompok 4 | Stub [L] `DEFAULT NULL` | NOT NULL + FK PEG RESTRICT | Konvensi 2.0.1; audit NULL/orphan sebelum impor |
| K4-b | FK `detail_anak` → `riwayat_keluarga` | [K] CASCADE/CASCADE | RESTRICT/RESTRICT | K1; legacy mengandalkan CASCADE saat hapus keluarga (A #4) → service B-15 hapus anak + lampiran eksplisit, satu transaksi |
| K4-c | FK `document_attachment.NIP` | [K] CASCADE/CASCADE | RESTRICT/RESTRICT | K1/B-06: ganti NIP mengarahkan ulang `NIP` (dan path berkas yang memuat NIP); hapus pegawai tidak menghapus lampiran diam-diam |
| K4-d | FK `document_attachment.id_riwayat` → `jenis_rwy` | Konstanta PHP, tanpa FK | FK [V2] RESTRICT + baris 0 | DoD B-02; kode di luar seed (mis. 33) ditolak → pemetaan sebelum impor (K4-3) |
| K4-e | Status 4 riwayat + 2 layanan | Stub [L] VARCHAR(5) DEFAULT '1' | TINYINT NOT NULL DEFAULT 0 + CHECK 0/1/2/3/10 (keluarga, tanda jasa, organisasi) / 0/1/2/10 (alamat, karpeg, kariskarsu) | 2.0.3; 1.5 #2 |
| K4-f | `rated` karpeg/kariskarsu | Stub [L] VARCHAR(5) DEFAULT NULL | TINYINT NOT NULL DEFAULT 2 (pola [K-m] `d_riwayat_cuti`) | Survei legacy butuh default 2 (A #5); impor NULL → lihat F-audit #6 |
| K4-g | `riwayat_tanda_jasa.negara` | Ditulis/dibaca kode, tidak di rencana | VARCHAR(100) NULL [I] | A #2 |
| K4-h | NOT NULL [I] | Tanpa DDL | `riwayat_alamat.jenis_alamat`, `riwayat_tanda_jasa.tgl_sertifikat`, `riwayat_organisasi.tgl_mulai` | Wajib di form / diisi SIASN; audit NULL & `0000-00-00` (strict menolak) |
| K4-i | `created_at` riwayat [I] | Tidak ada di stub [L] | `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | 2.0.4; karpeg/kariskarsu memakainya untuk nomor tiket (bukti kuat [I]) |
| K4-j | Tipe [I] tanpa DDL | — | `keterangan` TINYTEXT (keluarga, alamat) / TEXT (TJ, organisasi); `jenis_permohonan` V100; `file_*` V255; `kedudukan`/`nama_organisasi` V255; `lantai`/`telp`/`faks` V50 | Menunggu `SHOW CREATE TABLE` (F-dump #1) |
| K4-k | Lookup `jenis_rwy` | Konstanta `ARSIP_RWY`/`JENIS_RWY_ARSIP` | Tabel [V2] + seed 21 baris (termasuk 0) + CHECK status | Kontrak Bagian 2.4 |
| K4-l | Trigger arsip hapus `da_deleted` [K-m] | Trigger salin sebelum DELETE (asumsi, di luar dump) | Tidak ditiru | Jejak lewat `audit_logs` (3.1 #6) |

## 4. Keputusan yang diminta dari DB Validator (DBV-012 / DBV-013)

### 4.1 Keputusan umum (lintas kelompok)

| # | Pertanyaan | Usulan (pra-review CR) | Keputusan |
|---|---|---|---|
| 1 | Setujui skema Bagian 2 + migration B-01 (`120000`, `120100`) dan B-02 (`130000`..`131000`) untuk dijalankan di Dev | Setujui setelah `migrate` → `migrate:rollback` → `migrate` dan schema test lolos di MariaDB 10.4 (6.3) | ⏳ menunggu |
| 2 | Domain status riwayat: TINYINT NOT NULL DEFAULT 0 + CHECK; `IN (0,1,2,3,10)` untuk 11 tabel yang form admin legacy-nya menulis 3 "Diproses", `IN (0,1,2,10)` untuk sisanya; pengecualian LKH, `absen_ijin`, Plt/Plh (2.0.3, 1.5 #2) | Setujui. Alternatif (usulan kelompok 3, 4.4 K3-1): semua `IN (0,1,2,10)` dan impor memetakan 3 → 0 (informasi "sedang diproses" hilang). Putuskan bersama hitungan `status = 3` produksi (6.4) | ⏳ menunggu |
| 3 | Tipe blok workflow [I]: TINYINT (draf) atau INT mengikuti bukti [K]/[K-m] (catatan 2.0.3) | Draf memakai TINYINT (stub [L] riwayat `show_notif tinyint`); INT NULL DEFAULT 0 bila DBV memilih pola [K] `absen_ijin` (preseden G-06 D2). Ubah lewat edit migration selama belum di `main` | ⏳ menunggu |
| 4 | Semua FK RESTRICT/RESTRICT, termasuk `detail_anak` dan `document_attachment` yang legacy CASCADE | Setujui (K1) | ⏳ menunggu |
| 5 | `nip` riwayat NOT NULL; `ignore_konv_ak.nip` 50 → 30 | Setujui; audit NULL/panjang sebelum impor | ⏳ menunggu |
| 6 | Kolom audit riwayat [I] (`created_at` NOT NULL, `updated_at` ON UPDATE, `updated_by`), snapshot hanya `updated_at` | Setujui; bila dump berbeda, ALTER selagi tabel kosong | ⏳ menunggu |
| 7 | Lookup `jenis_rwy` [V2] (PK = kode legacy tanpa AUTO_INCREMENT, seed 21 baris termasuk 0) + FK dari `document_attachment.id_riwayat` | Setujui; audit kode di luar seed (6.4) | ⏳ menunggu |
| 8 | Trigger `absen_ijin_beDel` dan tabel `d_*` tidak ditiru | Setujui (`audit_logs`) | ⏳ menunggu |
| 9 | FK ke tabel G-02 dipisah ke migration yang ditahan sampai DBV-008 merge (2.0.7 d, Bagian 7); 10 FK ke `jabatan_koordinasi`/`rumpun_jabatan` menunggu tabel yang belum ada di draf DBV-008 | Setujui; DoD B-01 "FK aktif ke seluruh master Tier 1" penuh setelah DBV-008 (+ tabel koordinasi/rumpun). Key review untuk migration FK G-02: DBV-012/013 yang sama atau key baru — putuskan user | ⏳ menunggu |
| 10 | Pemilik `aa_lkh`/`queue_aa_lkh` (auto-approval LKH via cron, FK [K] ke `riwayat_lckh`) | Diputuskan bersama B-12/Fase 5; skema B-02 cukup menyiapkan PK `riwayat_lckh.id_riwayat_lckh` INT | ⏳ menunggu |
| 11 | Kolom `pegawai_foto` di luar `id_pegawai_foto`/`nip`/`foto`/`created_at` | Tunggu `SHOW CREATE TABLE` (6.4) | ⏳ menunggu |
| 12 | **FK masuk ke tabel yang sudah disetujui di `main`:** `fk_id_pegawai_pengguna_to_pegawai` (`pengguna.nip`, A-01 #4) dan `fk_nip_faqrate_to_peg` (`faq_rate.nip`, G-10 D2) mengubah tabel DBV-001/002/010. Draf menahan migration `120200` (1.5 #1, Bagian 7) | Setujui penahanan. Aktifkan (timestamp baru, PR tersendiri) setelah: (1) `pegawai` terisi di lingkungan target; (2) A-09 AccountProvisioner & Manajemen Akun memvalidasi NIP ada di `pegawai` (422) — CR terpisah; (3) seed/test akun & rating menyisipkan `pegawai` dulu. Migration fail-closed: menolak jalan (tanpa ALTER) bila ada NIP yatim, tidak mengubah/menghapus data, `down()` hanya melepas FK | ⏳ menunggu |
| 13 | Alur PR: migration + dokumen + schema test **ditambah** penyesuaian 3 test master di `main` (`Batch1LegacySchemaTest`, `DiklatHukdisKonketSchemaTest`, `PangkatPendidikanSchemaTest`: lepas migration B-01/B-02 sebelum `down()` master) | `[CR]` + `[DBV]` (PR; DB Validator review, user merge), karena test `main` ikut berubah | ⏳ menunggu |
| 14 | Dokumen: satu dokumen gabungan (ini) atau dipecah per key saat PR dipecah | Gabungan selama draf; dipecah bila PR DBV-012 dan DBV-013 dibuka terpisah | ⏳ menunggu |

Koreksi setelah approval dilakukan lewat migration ALTER baru, bukan dengan mengedit migration yang sudah di `main`.

### 4.2 Kelompok 1 (B-01)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| B01-1 | **FK masuk `120200` & dampaknya ke test/seed/aplikasi** (A #9, #10). Dengan FK aktif: `AuthSeeder` (14 akun ber-NIP tanpa `pegawai`), test yang membuat akun/rating ber-NIP bebas, dan `FaqSchemaTest` (D2 "tanpa FK") gagal 1452; A-09 AccountProvisioner/Manajemen Akun menjawab error server alih-alih 422; Dev berhenti di `120200` | Integrasi: `120200` **ditahan** (4.1 #12, Bagian 7). Saat diaktifkan: (1) seed `AuthSeeder` menyisipkan `pegawai` (diff usulan disimpan bersama migration yang ditahan); (2) test akun/rating menyisipkan `pegawai` dulu; (3) `FaqSchemaTest` mengharapkan `fk_nip_faqrate_to_peg`; (4) CR terpisah: validasi NIP ada di `pegawai` (422) di A-09/Manajemen Akun. Test master yang memanggil `down()` (`Batch1LegacySchemaTest` dkk.) sudah disesuaikan di PR ini (6.2) | ⏳ menunggu |
| B01-2 | Skema 2.1.1-2.1.3 (`pegawai` [L], `pegawai_hist` [I], `pegawai_foto` [I]) + CHECK [V2] | Setujui | ⏳ menunggu |
| B01-3 | `pegawai_hist.flag_update` DEFAULT 1 + CHECK 1/2/3; CHECK `status`/`jenis_kelamin` ikut di hist | Setujui (B-03/B-04 memetakan "pending" = 1) | ⏳ menunggu |
| B01-4 | Snapshot tanpa CHECK/status/WF; kolom [I] nullable kecuali NN rencana (A #8) | Setujui; ketatkan lewat ALTER setelah dump bila perlu | ⏳ menunggu |
| B01-5 | `pegawai_cpns`/`pegawai_pns` berbentuk `pegawai_kp` (A #4) | Setujui; konfirmasi SHOW CREATE TABLE | ⏳ menunggu |
| B01-6 | `jenis_jabatan`: snapshot [L] NOT NULL DEFAULT 1 vs riwayat NULL (kelompok 2 A #4) | Riwayat ikut NOT NULL DEFAULT 1 (form legacy selalu mengirim radio jenis jabatan), atau service B-07 wajib mengisi 1 saat NULL sebelum sinkron. Snapshot tetap [L] | ⏳ menunggu (bersama kelompok 2) |
| B01-7 | Kolom snapshot = kolom yang dibaca dari snapshot, tipe = riwayat asal (A #8): `pegawai_tanda_jasa` + `negara`/`keterangan`, `pegawai_diklat` + `sub_group_jabatan`/`deskripsi`/`keterangan`, `pegawai_hukdis.keterangan` TEXT | Setujui (sudah diterapkan di `120100`; kelompok 4 K4-7 terkait) | ⏳ menunggu |
| B01-8 | 13 FK RWY RESTRICT (bukan SET NULL legacy); `131000` di PR DBV-013 | Setujui | ⏳ menunggu |
| B01-9 | Kolom `pegawai_foto` selain 4 kolom di 2.1.3 | Tunggu SHOW CREATE TABLE (= 4.1 #11) | ⏳ menunggu |
| B01-10 | 4 FK G02 ke `jabatan_koordinasi`/`rumpun_jabatan` (A #6) tidak ikut `AddFkG02Pegawai` | Tambahkan tabel di DBV-008 atau DBV lain, lalu migration FK terpisah | ⏳ menunggu |
| B01-11 | Registry NIP (2.1.8) sebagai pagar: kolom NIP baru wajib diklasifikasi di test | Setujui | ⏳ menunggu |

Koreksi setelah approval dilakukan lewat migration ALTER baru, bukan mengedit migration yang sudah di `main`.

### 4.3 Kelompok 2

| # | Pertanyaan | Usulan penulis (pra-review CR) | Keputusan |
|---|---|---|---|
| K2-1 | Status legacy **3 "Diproses"** (A #1) ditolak CHECK 0/1/2/10 | **(a, disarankan)** domain riwayat B-02 = 0/1/2/3/10 dengan COMMENT '3: Diproses' di SEMUA tabel riwayat ber-WF (keputusan lintas kelompok, diterapkan integrator); atau (b) pertahankan 0/1/2/10 dan petakan 3 → 0 saat impor (kehilangan informasi "sedang diproses admin"). Putuskan setelah `SELECT status, COUNT(*)` per tabel (6.4.2 #2). **Integrasi: opsi (a) diterapkan untuk tabel yang formnya menawarkan 3 (1.5 #2, 4.1 #2)** | ⏳ menunggu |
| K2-2 | `pegawai_plt`/`pegawai_plh`: status 1/2/10 + CHECK, `tgl_mulai`/`tgl_akhir` NOT NULL, `nip_plt`/`nip_plh` NOT NULL | Setujui; CHECK `tgl_akhir >= tgl_mulai` tidak dibuat (usul opsional setelah audit) | ⏳ menunggu |
| K2-3 | 6 FK ke `jabatan_koordinasi`/`rumpun_jabatan` (tabel belum ada di draf DBV-008) | Tambahkan kedua tabel ke DBV-008 (tidak ada DDL [K]; ISSUE-003) sebelum `AddFkG02Riwayat` dijalankan; bila tidak, konstanta `FK_KOORD_RUMPUN` dipindah ke migration terpisah | ⏳ menunggu |
| K2-4 | `riwayat_pendidikan.glr_awal/glr_akhir` [I] | Setujui; B-10 memutuskan apakah gelar disalin ke `pegawai.glr_*` saat approval | ⏳ menunggu |
| K2-5 | `riwayat_mutasi_jabatan.jenis_jabatan` NULL (stub [L]) dan `jenis_jabatan_koord` NOT NULL DEFAULT 3 ([L] pmj; `simpeg01` lokal: NULL) | Setujui; koreksi lewat ALTER bila dump produksi berbeda | ⏳ menunggu |
| K2-6 | Pemetaan `fk_pegawai_plh_ibfk_04..11` → kolom [I] | Verifikasi dengan `SHOW CREATE TABLE pegawai_plh` produksi sebelum `AddFkG02Riwayat` | ⏳ menunggu |
| K2-7 | `kredit_jft` di riwayat tanpa titik tulis PHP | Setujui (paritas snapshot); B-07 menentukan sumber nilainya | ⏳ menunggu |
| K2-8 | Tipe [I] K2-h, khususnya `keterangan` TEXT vs TINYTEXT dan `jumlah_jp` SMALLINT | Setujui; snapshot (kelompok 1) wajib bertipe sama/lebih lebar dari riwayat | ⏳ menunggu |
| K2-9 | CHECK domain lain yang pasti dari form tetapi di luar daftar 2.0.6: `jenis_jabatan` 1-3, `jenis_mutasi` 1-2, `jenis_jabatan_koord` 1-3, `status_jft` 1-2, `jenis_seminar` 1-2, Plt/Plh `jenis_jabatan` 1-2 / `jenis_jabatan_koord` 1-2 | Tidak dibuat sekarang; tambah lewat ALTER setelah audit nilai (6.4.2 #3) | ⏳ menunggu |
| K2-10 | Tanpa UNIQUE untuk duplikat `tmtsk` KP dan diklat struktural ganda | Setujui (aturan aplikasi mengecualikan status 2/10) | ⏳ menunggu |
| K2-11 | Index [V2] `idx_riwayat_mutasi_jabatan_nip_tmtsk` (`nip`, `tmtsk`) untuk query "jabatan pada tanggal" (2.2.1) | Usulan, **tidak** dibuat di migration; putuskan bersama beban query Fase 5 (presensi) | ⏳ menunggu |

### 4.4 Kelompok 3

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| K3-1 | **Lintas kelompok.** Status 3 "Diproses" yang ditawarkan form admin legacy (A #1) berada di luar CHECK riwayat `IN (0,1,2,10)` | Pertahankan CHECK 0/1/2/10. Saat impor, petakan 3 → 0 (Menunggu). v2 tidak menawarkan status "Diproses" (B-TC hanya 0/1/2). Minta hitungan `status = 3` per tabel (F-dump #3); bila banyak, ALTER CHECK selagi tabel kosong. **Integrasi: tidak diikuti — lihat 4.1 #2 (alternatif tetap diajukan)** | ⏳ menunggu |
| K3-2 | `absen_ijin.status` tetap CHAR(2) W/V/X/10 [K] (tidak diseragamkan ke 0/1/2/10 B-TC); nilai angka legacy (A #2) | Setujui [K]. B-13 memetakan istilah B-TC ke W/V/X. Impor menormalkan `0`→W, `1`→V, `2`→X, `3`/`H`→W | ⏳ menunggu |
| K3-3 | `riwayat_hukdis.status` DEFAULT 0 (konvensi) padahal legacy selalu menulis 1 dan tanpa approval | Setujui DEFAULT 0 demi keseragaman; service B-14 wajib menulis `status = 1` eksplisit | ⏳ menunggu |
| K3-4 | KEY FK tunggal `fk_riwayat_lckh_ibfk_01` (`nip`) dan `_02` (`nip_atasan`) tumpang tindih dengan `idx_riwayat_lckh_nip_tgl` dan `idx_riwayat_lckh_atasan_status` | Pertahankan (konvensi 2.0.6, preseden G-06 `jenis_hukdis`). Alternatif: hapus kedua KEY tunggal; FK memakai indeks komposit | ⏳ menunggu |
| K3-5 | Status DEFAULT 1 untuk tabel sinkron `riwayat_skp_periodik` dan `riwayat_ak_siasn` (deviasi dari DEFAULT 0 konvensi) | Setujui (mengikuti satu-satunya penulis legacy) | ⏳ menunggu |
| K3-6 | `bkn_periode_ekinper` [K] tanpa CHECK status (hanya 1 yang ditulis) | Setujui tanpa CHECK (persis [K]); tambahkan CHECK `IN (1,2,10)` hanya bila DBV menghendaki keseragaman master | ⏳ menunggu |
| K3-7 | `riwayat_skp.tahun` SMALLINT (bukan YEAR) | Setujui SMALLINT (toleran impor); ganti ke YEAR bila dump produksi menunjukkan YEAR | ⏳ menunggu |
| K3-8 | UNIQUE yang **tidak** dibuat: `konv_ak.nip`, `riwayat_ak_siasn.id_rw_siasn`, `riwayat_skp_periodik (id_pns, periode_id, skp_id)`, `riwayat_lckh (nip, tgl_laporan)` aktif, `riwayat_skp (nip, tahun, tgl_mulai, tgl_akhir)` | Tunda sampai audit duplikat (F-dump #4). Keunikan ditegakkan aplikasi seperti legacy | ⏳ menunggu |
| K3-9 | **Lintas kelompok.** `pegawai_hukdis.keterangan` TINYTEXT (kelompok 1) lebih sempit dari `riwayat_hukdis.keterangan` TEXT | Samakan snapshot menjadi TEXT di migration `120100` (belum di `main`, jadi masih boleh diubah); alternatif: riwayat TINYTEXT dengan validasi 255 byte. **Integrasi: diterapkan (1.5 #4)** | ⏳ menunggu |
| K3-10 | **Lintas kelompok.** Tipe `siasn_flag`: kelompok 2 `TINYINT(1)`, kelompok 3 `TINYINT` ([L] `pegawai.siasn_flag tinyint`); domain SKP 0-3 berbeda dari COMMENT kelompok 2 | Seragamkan ke `TINYINT` (tanpa lebar tampilan, pola [L]); COMMENT per tabel sesuai kode. **Integrasi: diterapkan (1.5 #3)** | ⏳ menunggu |
| K3-11 | Kolom numerik masa sanksi per riwayat hukdis (mis. `masa_sanksi_bulan`) untuk B-14 | Tidak ditambah di B-02. B-14 menghitung dari `jenis_hukdis.masa_sanksi_bulan` (K5) dan menyimpan hasil di `akhir_hukdis`. Bila B-14 butuh override per kasus, tambahkan lewat ALTER | ⏳ menunggu |
| K3-12 | CHECK tambahan yang domainnya pasti dari kode tetapi di luar daftar 2.0.6: `riwayat_ak.jenis_ak` 1/2, `riwayat_skp.rating_*` 1-3, `riwayat_lckh.auto_approval` 1/2, `siasn_flag` | Tidak dipasang (ikut daftar 2.0.6); DBV dapat meminta ditambah | ⏳ menunggu |
| K3-13 | `ignore_konv_ak` hanya berisi data historis (titik tulis tidak aktif) | Tetap dibuat ([K], dirujuk kode hapus); hapus dari cakupan bila hitungan produksi 0 | ⏳ menunggu |
| K3-14 | Di luar cakupan kelompok 3: `aa_lkh` [K] :23-28 dan `queue_aa_lkh` (auto-approval LKH), `riwayat_ak_unsur`/`_spmk`/`_bk`/`_skkp`, `riwayat_lckh_2019..2022`, `d_lkh`/`d_konket` | Sesuai Bagian 1.3; `aa_lkh`/`queue_aa_lkh` = 4.1 #10 (FK legacy CASCADE ke `riwayat_lckh` perlu diputuskan saat dibuat) | ⏳ menunggu |

### 4.5 Kelompok 4

| # | Pertanyaan | Usulan penulis (pra-review CR) | Keputusan |
|---|---|---|---|
| K4-1 | Status legacy **3 "Diproses"** di `riwayat_keluarga`, `riwayat_organisasi`, `riwayat_tanda_jasa` (A #1) ditolak CHECK 0/1/2/10 | Ikut keputusan lintas kelompok (K2-1 / kelompok 3 K3-1); satu keputusan untuk semua riwayat ber-WF, diterapkan integrator lewat konstanta `STATUS_RIWAYAT_DOMAIN`/COMMENT. Putuskan setelah `SELECT status, COUNT(*)` (F-dump #3). **Integrasi: diterapkan untuk ketiga tabel (1.5 #2)** | ⏳ menunggu |
| K4-2 | Lookup `jenis_rwy` [V2]: PK = kode legacy tanpa AUTO_INCREMENT, seed 21 baris termasuk 0, UNIQUE `kode`/`jenis_rwy`, CHECK status, `tabel_entri` teks tanpa FK, tidak masuk engine Master Data | Setujui (4.1 #7) | ⏳ menunggu |
| K4-3 | Kode `id_riwayat` legacy di luar seed (mis. **33**, label lama "Data Umum" yang dikomentari di `JENIS_RWY_ARSIP`) ditolak FK | Audit `GROUP BY id_riwayat` (F-dump #2); usulan petakan 33 → 38 (label sama, `id_entri` NULL) saat impor; kode lain diputuskan per kasus. Tidak menambah baris 33 ke seed | ⏳ menunggu |
| K4-4 | FK `fk_id_riwayat_da_to_jenis_rwy` memakai KEY legacy `id_riwayat_id_entri` (tanpa KEY baru) | Setujui (pengecualian 2.0.6: kolom FK sudah terdepan di index lain); EXPLAIN pola query legacy memakai index ini | ⏳ menunggu |
| K4-5 | RESTRICT untuk `detail_anak` dan `document_attachment` (legacy CASCADE) | Setujui (dokumen 4.1 #4); B-15/B-18 wajib menghapus anak/lampiran eksplisit dalam satu transaksi (A #4) | ⏳ menunggu |
| K4-6 | `rated` TINYINT NOT NULL DEFAULT 2 ([K-m]) alih-alih stub [L] VARCHAR(5) DEFAULT NULL | Setujui; CHECK 0/1/2 ditunda sampai audit nilai (F-dump #4) | ⏳ menunggu |
| K4-7 | Kolom `riwayat_tanda_jasa.negara` VARCHAR(100) [I] + kolom `negara`/`keterangan` di snapshot `pegawai_tanda_jasa` (kelompok 1) | Setujui; panjang dicocokkan dengan dump (F-dump #1, #6). **Integrasi: snapshot sudah memuat kedua kolom** | ⏳ menunggu |
| K4-8 | NOT NULL [I]: `riwayat_alamat.jenis_alamat`, `riwayat_tanda_jasa.tgl_sertifikat`, `riwayat_organisasi.tgl_mulai` | Setujui; audit NULL/nol sebelum impor (F-audit #3); bila data legacy berisi NULL, ALTER ke NULL selagi tabel kosong | ⏳ menunggu |
| K4-9 | CHECK domain lain yang pasti dari form tetapi di luar daftar 2.0.6: `jenis_alamat` 1-3, `alamat_utama` 1-2, `rated` 0-2 | Tidak dibuat sekarang (sejalan K2-9); ALTER setelah audit nilai | ⏳ menunggu |
| K4-10 | `riwayat_alamat.KdPos` (salinan `kd_pos`) | Pertahankan untuk paritas impor; aplikasi v2 menulis nilai yang sama dengan `kd_pos`; penghapusan kolom diputuskan B-16 lewat ALTER bila tidak ada pembaca | ⏳ menunggu |
| K4-11 | Tipe `keterangan`: TINYTEXT (keluarga, alamat; = seed & snapshot) vs TEXT (tanda jasa, organisasi; akhiran SIASN/textarea) | Setujui; audit `MAX(LENGTH)` (TINYTEXT 255 byte, strict 1406) — bila melebihi, naikkan riwayat + snapshot bersama | ⏳ menunggu |
| K4-12 | Tipe `siasn_flag`: TINYINT (kelompok 3, kelompok 4) vs TINYINT(1) (kelompok 2) | Seragamkan ke TINYINT (lebar tampilan tidak berarti di MySQL 8); COMMENT domain per tabel | ⏳ menunggu (integrasi: diterapkan, 1.5 #3) |
| K4-13 | Karpeg/kariskarsu memakai blok LAYANAN (hanya `show_ua_biro` + `rated`, tanpa `show_ua_upt`/`show_ua_deputi`) dan tinggal di B-02 walau diproses petugas layanan (Fase 4) | Setujui; relasi ke `petugas_layanan` (C-01) dan `skl` (Tier 7) lewat aplikasi | ⏳ menunggu |
| K4-14 | `document_attachment` persis [K]: `created_at` NULLable, tanpa `updated_by`/status (bukan pola AUD riwayat) | Setujui; pengubah lampiran dicatat di `audit_logs` (B-18) | ⏳ menunggu |

## 5. Status keputusan terkait

| Rujukan | Isi | Sebelumnya | Di DBV-012/013 |
|---|---|---|---|
| A-01 #4 | FK `pengguna.nip` → `pegawai.nip` | Di-defer ke B-01 | Migration `120200` **ditahan** sampai prasyarat Bagian 7 (4.1 #12) |
| G-10 D2 | FK `fk_nip_faqrate_to_peg` | Di-defer ke B-01; KEY sudah ada | Migration `120200` **ditahan** (4.1 #12) |
| G-06 1.4 #3 | Tipe FK riwayat mengikuti PK G-06 | Menunggu B-11/B-14/B-17 | Diterapkan di skema (2.0.2) |
| A-01 D-7/D-8 | `token.nip`, `audit_logs.nip_actor` jejak tanpa FK | ✅ di `main` | Masuk registry NIP (2.0.8) |
| A-01 D-12 / DBV-009 | `*_by` INT signed vs `pengguna.id_pengguna` UNSIGNED | Penyelarasan di DBV-009 | `*_by` tanpa FK (2.0.4) |
| DBV-008 (G-02) | Master jabatan/unit/satker | Dalam pengerjaan | FK keranjang (d) ditahan (2.0.7, Bagian 7); `jabatan_koordinasi`/`rumpun_jabatan` belum ada di draf (4.1 #9) |
| G-06 K5 | `jenis_hukdis.masa_sanksi_bulan` | ✅ di `main` | Dasar `akhir_hukdis` B-14; tanpa kolom numerik per riwayat (K3-11) |
| ISSUE-022 | Zona waktu default `CURRENT_TIMESTAMP` (WIB) vs aplikasi (UTC) | Tindak lanjut | Berlaku juga untuk tabel B-01/B-02 (6.5) |

## 6. Verifikasi developer (sebelum review DB Validator)

### 6.1 Lingkungan

MySQL 8.0.30 (Laragon lokal), PHP 8.4.2, CodeIgniter 4.7.4, PHPUnit 10.5. Worktree branch lokal
`dbv-012/b01-b02-pegawai-riwayat-draf` dari `origin/main` `89ea98d`. Database test `simpeg_v2_t_<prefix>_s<i>` (prefix
tabel `t_`) dibuat dan di-DROP dengan nama persis oleh skrip gate; uji migrate/rollback memakai DB scratch bernama unik
yang juga di-DROP dengan nama persis setelah selesai. Semua pemeriksaan di bawah adalah **pra-review internal sisi CR**.

### 6.2 Hasil integrasi

| Perintah / uji | Hasil |
|---|---|
| Dev-like: DB scratch baru → `php spark migrate` dengan migration `main` saja (batch 1) → sisipkan akun `pengguna` ber-NIP (data Dev tiruan) → `php spark migrate` (batch 2 = 13 migration B-01/B-02) | ✅ batch 1 = 20 migration `main`; batch 2 = 13 migration (`120000`..`131000`) tanpa error walau `pengguna` berisi akun ber-NIP tanpa baris `pegawai`; 43 tabel, 108 FK RESTRICT/RESTRICT, 32 CHECK, `jenis_rwy` 21 baris; tidak ada FK baru di `pengguna`/`faq_rate`; data akun utuh |
| `php spark migrate:rollback` (batch 2) | ✅ hanya batch 2 yang dibatalkan (urutan `131000` → `120000`); 0 tabel B-01/B-02 tersisa, 34 tabel `main` + data akun utuh, batch 1 tetap tercatat |
| `php spark migrate` ulang | ✅ batch 2 terpasang lagi persis (43 tabel, 108 FK, `jenis_rwy` 21 baris) |
| Migration `120200` yang ditahan, dijalankan sekali di DB yang sama (akun ber-NIP tanpa `pegawai`) | ✅ fail-closed: berhenti dengan pesan "pengguna.nip: 1 akun pengguna … dengan NIP yang tidak ada di pegawai", 0 FK terpasang, tabel `migrations` tidak berubah, data akun utuh (file dikeluarkan lagi setelah uji) |
| Nama constraint | 108 FK + 32 CHECK dari 43 tabel; tidak ada nama FK/CHECK ganda di schema (termasuk `main`); nama terpanjang 49 karakter |
| Schema test terarah (`fastcheck.py … --only phpunit --shards 3 --files`: 6 test Kepegawaian + 3 test master yang disesuaikan) | ✅ 46 tests, 4214 assertions |
| Gate `fastcheck.py <worktree> dbv12c --only backend --shards 2` (PHPStan level 5, PHP-CS-Fixer, PHPUnit penuh) | ✅ SEMUA CHECK LOLOS: PHPStan No errors, PHP-CS-Fixer 0 dari 260 file, PHPUnit 562 tests / 20850 assertions (shard 1: 287 tests, 105,6 mnt; shard 2: 275 tests, 100,0 mnt) |

**Penyesuaian test yang sudah ada (bukan migration `main`):** `Batch1LegacySchemaTest` (DBV-001), `DiklatHukdisKonketSchemaTest`
(G-06) dan `PangkatPendidikanSchemaTest` (G-04/G-05) memanggil `down()` migration master sementara migration sesudahnya
tetap terpasang. Tabel B-01/B-02 merujuk master itu dengan FK RESTRICT, sehingga `down()` master ditolak (1832 saat MODIFY
kolom yang dirujuk, 3730 saat DROP tabel induk). Ketiganya kini melepas migration B-01/B-02 (versi ≥ `2026-09-30-120000`,
urut menurun) di `setUp()` dan memasangnya ulang di `tearDown()` lewat `Tests\Support\LepasMigrationKepegawaianTrait` —
pola yang sama dengan `WILAYAH_DEPENDENTS`; assertion master tidak berubah. Test Auth/FAQ tidak perlu diubah karena FK
masuk `120200` ditahan. Catatan biaya: setiap test ber-`$refresh = true` kini ikut me-regress/migrate 43 tabel tambahan,
sehingga durasi suite PHPUnit naik.

**Schema test B-02 (baru, integrator):** `RiwayatJabatanKpPendidikanSchemaTest` (8 tabel), `RiwayatSkpLkhKonketHukdisAkSchemaTest`
(10 tabel), `RiwayatKeluargaKartuLampiranSchemaTest` (9 tabel) memakai `Tests\Support\SkemaKepegawaianTestTrait` dan
mengunci: kolom (tipe, NULL, urutan), default & ON UPDATE/AUTO_INCREMENT, collation tabel & kolom, engine, PRIMARY,
UNIQUE, index (nama & kolom), FK (nama, kolom, induk, RESTRICT/RESTRICT; FK G-02 yang ditahan boleh muncul kelak), CHECK
(nama & domain ternormal), perilaku (CHECK 3819/4025, FK 1452, RESTRICT 1451, UNIQUE/PK 1062, default baris), `down()`
semua migration sejak versi pertama kelompok lalu `up()` persis, dan `up()` yang gagal di tengah (tabel penghalang)
hanya men-drop tabel run itu. Seed `jenis_rwy` dibandingkan dengan konstanta legacy yang ditulis literal di test.

#### 6.2.1 Kelompok 1 (pra-review penulis, sebelum integrasi)

> Catatan integrasi: hasil di bawah dijalankan penulis di salinan privat saat `120200` masih di folder `Migrations`;
> dampak ke test lama yang dicatat penulis (akun/FAQ 1452, `down()` master 1832/3730) ditangani di 6.2.

##### Lingkungan

MySQL 8.0.30 (Laragon lokal), PHP 8.4.2, CodeIgniter 4.7.4. Gate dijalankan di **salinan backend privat**
(berkas uji lokal (tidak di-commit), tidak di-commit) berisi migration `main` + `120000`..`120200` saja,
supaya file kelompok lain yang masih ditulis di worktree bersama tidak memengaruhi hasil; `.env` salinan dihapus
setelah selesai. DB shard dibuat & di-DROP `fastcheck.py` dengan nama persis (`simpeg_v2_t_<prefix>_s<i>`), prefix `t_`.

##### Hasil

| Perintah | Hasil |
|---|---|
| `php -l` 4 migration + 3 test + 1 migration ditahan | ✅ tanpa error |
| PHPStan (config repo, level 5) pada 4 migration + 3 test | ✅ No errors |
| `php-cs-fixer fix` (config repo) pada file kelompok 1 | ✅ diterapkan (perataan `=>`/`=` saja) |
| `fastcheck.py <salinan privat> k1b01a --only phpunit --files PegawaiSnapshotSchemaTest,NipReferenceRegistryTest` | ✅ OK (9 tests, 1510 assertions): skema 16 tabel, default/COMMENT, tanpa baris, CHECK 3819, FK 1452/1451, PK snapshot 1062, `down()`→`up()` bersih, `up()` gagal di tengah → `dropCreated()`, registry FK & kolom NIP, pra-cek orphan 120200 fail-closed + idempoten |
| Suite PHPUnit penuh / terarah di salinan privat (DBV-012 saja) | ❌ test lama patah — lihat 6.2 |
| Salinan privat ke-2: `main` + `120000`..`120200` + draf kelompok 2/3 (`130100`..`130600`, disalin 30-09-2026 ±11:05) + **stub sandbox** `130950` (hanya PK `riwayat_keluarga`/`riwayat_alamat`/`riwayat_tanda_jasa`, tidak di repo) + `131000`; `fastcheck.py … k1b013x --files` 3 test kelompok 1 | ✅ OK (12 tests, 1628 assertions): `131000` memasang 13 FK RWY, pra-cek orphan fail-closed, idempoten; registry cocok dengan nama FK PEG kelompok 2/3; rantai `down()`/`up()` melewati migration kelompok 2/3 bersih |
| `SnapshotRiwayatFkTest` dengan migration kelompok 4 asli | ⏳ integrator (stub hanya membuktikan mekanisme, bukan tipe kolom kelompok 4) |

#### 6.2.2 Kelompok 2 (pra-review penulis, sebelum integrasi)

##### Lingkungan

PHP 8.4.2 lokal (CS-Fixer memperingatkan target proyek PHP 8.2), MySQL 8.0.30 Laragon, worktree `wt\dbv012` (branch lokal
`dbv-012/b01-b02-pegawai-riwayat-draf`, dasar `origin/main` `89ea98d`). DB scratch `scr_dbv013_k2_0930a` (dibuat khusus,
di-drop dengan nama persis setelah uji). Tabel `pegawai` kelompok 1 belum ada saat uji → dipakai **stub**
`t_pegawai (nip VARCHAR(30) PK, unicode_ci)`; uji wajib diulang setelah `CreatePegawai` tersedia.

##### Hasil

| Perintah / uji | Hasil |
|---|---|
| `php -l` 4 file (3 migration + draf ditahan) | ✅ tanpa error sintaks |
| `php-cs-fixer fix --dry-run` (konfigurasi repo) | ✅ 0 file perlu diperbaiki |
| `phpstan analyse` level 5 (konfigurasi repo) | ✅ No errors |
| `up()` 130100/130200/130300 di DB scratch (prefix `t_`, setelah migration master `main` 110000/110100/120000) | ✅ 8 tabel; 65/21/21/32/21/31/33/24 kolom; semua kolom string `utf8mb4_unicode_ci` |
| FK di `REFERENTIAL_CONSTRAINTS` | ✅ 15 FK (PEG 8 + MAIN 7), semua `RESTRICT/RESTRICT` |
| CHECK di `TABLE_CONSTRAINTS` + penegakan | ✅ 9 CHECK; status 3 / 11 / Plt 0 / `jenis_diklat` 6 ditolak (3819) |
| FK orphan | ✅ `nip`, `gol_pppk`, `diklat`, `pangkat`, `jurusan_pendidikan` ditolak (1452); kolom G-02 menerima id apa pun (FK ditahan) |
| Hapus/ubah `pegawai.nip` yang dirujuk | ✅ ditolak (1451) |
| Default baris baru | ✅ `status` 0, `show_notif` 0, `created_at`/`updated_at` terisi |
| `down()` lalu `up()` ulang | ✅ 0 tabel tersisa, `up()` ulang bersih |
| `up()` gagal di tengah (penghalang `t_riwayat_kgb`, CREATE ke-2 di 130200) | ✅ error 1050 dilempar, `riwayat_kp` yang sempat dibuat di-drop, penghalang tidak disentuh |
| `AddFkG02Riwayat` dengan stub tabel G-02 + kelompok 3 | ✅ orphan (termasuk `id_unit` = 0) → exception, 0 FK terpasang; `up()` → 40 FK RESTRICT/RESTRICT tanpa index otomatis baru; `down()` → 0 FK; ALTER tabel terakhir gagal (3780) → 0 FK tersisa |
| Schema test PHPUnit + `fastcheck.py` | ✅ dijalankan integrator dengan migration asli semua kelompok (6.2) |

Bukti: `SHOW CREATE TABLE` hasil uji di berkas uji lokal (tidak di-commit); skrip uji `ddl_probe.php`,
`fk_g02_probe.php` (scratch, bukan untuk di-commit).

#### 6.2.3 Kelompok 3 (pra-review penulis, sebelum integrasi)

**Lokal (MySQL 8.0.30 Laragon, 30-09-2026), pra-review internal sisi CR.** Harness scratch (tidak di-commit) membuat DB scratch bernama unik (`scr_dbv013_k3_<acak>`, prefix `t_`, sesi strict). Harness menjalankan migration `main` + `120000_CreatePegawai` (kelompok 1), lalu `130400`/`130500`/`130600`, lalu men-drop DB scratch dengan nama persis. Hasil: **semua cek lolos**.

| Cek | Hasil |
|---|---|
| `php -l` ketiga migration | lolos |
| PHP-CS-Fixer (`--dry-run`, config repo) dan PHPStan (config repo) pada ketiga berkas | 0 perbaikan; 0 error |
| `up()` | 10 tabel InnoDB `utf8mb4_unicode_ci`; 0 kolom string dengan collation lain |
| `REFERENTIAL_CONSTRAINTS` | 15 FK, semuanya `RESTRICT`/`RESTRICT`, nama sesuai 2.3.11 |
| `TABLE_CONSTRAINTS` CHECK | 10 CHECK (2.3.11) |
| KEY bernama FK G02 | 7 ada (FK belum terpasang); `id_jabatan` 12345 tanpa induk diterima |
| CHECK menolak (3819) | `riwayat_skp.status` 3; `riwayat_lckh.status` 4; `absen_ijin.status` '1', `affect_tukin` 0, `jenis_dinas` 2; `riwayat_ak.status` 5 |
| CHECK menerima | `riwayat_lckh.status` 3; `absen_ijin.status` V/10, `jenis_dinas` 1/NULL |
| FK menolak orphan (1452) | `riwayat_skp.nip`; `riwayat_hukdis.id_pangkat`/`id_tingkat_hukdis`/`id_jenis_hukdis`; `konv_ak.nip`; `riwayat_lckh.nip_atasan = ''` |
| Induk dirujuk (1451) | `DELETE` dan `UPDATE nip` `pegawai` ditolak |
| Default | `riwayat_skp` status/siasn_flag/show_notif 0 + `created_at` terisi; `riwayat_skp_periodik`/`riwayat_ak_siasn` status 1; `riwayat_lckh` status 0, auto_approval 2, status_regen 0, show_history_atasan 1; `absen_ijin` W/1/0/0; `riwayat_ak` jenis_ak 2, status 0 |
| Lain-lain | `riwayat_hukdis` tanpa `tmtsk` ditolak (1364); `ignore_konv_ak` PK ganda (1062); nip 31 karakter (1406) |
| `down()` lalu `up()` | 10 tabel di-drop, lalu dibuat ulang bersih |
| `up()` gagal di tengah | Tabel `t_riwayat_lckh` / `t_konv_ak` dibuat lebih dulu: `130400` / `130600` gagal, 3 tabel yang sempat dibuat run itu di-drop, tabel yang sudah ada tidak disentuh |

Schema test PHPUnit kelompok 3 (`tests/database/Kepegawaian/RiwayatSkpLkhKonketHukdisAkSchemaTest.php`) dan gate penuh dijalankan integrator (6.2).

**Permintaan verifikasi MariaDB 10.4 (tambahan 6.3):**
1. CHECK `chk_absen_ijin_status` membandingkan CHAR dengan literal string. MariaDB menegakkannya dengan kode 4025. Pastikan `'w'` huruf kecil lolos seperti di MySQL (unicode_ci).
2. `absen_ijin.last_updated DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP` dan `bkn_periode_ekinper.tahun YEAR` terbaca sama di `information_schema.COLUMNS` (MariaDB menampilkan `year(4)`).
3. FK nullable `riwayat_lckh.nip_atasan` menerima NULL dan menolak `''`.

**Pemulihan manual bila pembersihan otomatis gagal (tambahan 6.6).** Jangan `migrate:rollback` batch. Drop manual dengan urutan ini (prefix sesuai lingkungan):
- `130400_CreateRiwayatSkpLkh`: `riwayat_lckh` → `riwayat_skp_periodik` → `riwayat_skp` → `bkn_periode_ekinper`.
- `130500_CreateAbsenIjin`: `absen_ijin`.
- `130600_CreateRiwayatHukdisAk`: `ignore_konv_ak` → `konv_ak` → `riwayat_ak_siasn` → `riwayat_ak` → `riwayat_hukdis`.
- Bila `131000_AddFkSnapshotKeRiwayat` sudah jalan, drop dulu FK `fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis` dan `fk_id_riwayat_ak_cak_to_rak`, atau rollback migration itu, sebelum men-drop `riwayat_hukdis`/`riwayat_ak`.

#### 6.2.4 Kelompok 4 (pra-review penulis, sebelum integrasi)

**Lingkungan:** PHP 8.4.2 lokal (CS-Fixer memperingatkan target proyek PHP 8.2), MySQL 8.0.30 Laragon, worktree
`wt\dbv012` (branch lokal `dbv-012/b01-b02-pegawai-riwayat-draf`, dasar `origin/main` `89ea98d`). Harness scratch (tidak
di-commit) membuat DB scratch bernama unik (`scr_dbv013_k4_…`, prefix `t_`, `strictOn`), menjalankan **seluruh 34
migration** worktree urut versi (main + DBV-012 kelompok 1 + DBV-013 kelompok 1-4, termasuk `131000`), lalu men-drop DB
scratch dengan nama persis. Bukti: berkas uji lokal (tidak di-commit) (`ddl_probe.php`, `ddl_probe_output.txt`,
`seed_fail_probe.php`, `master_down_probe.php`, `show_create_*.sql`).

| Perintah / uji | Hasil |
|---|---|
| `php -l` keempat migration | ✅ tanpa error sintaks |
| `php-cs-fixer fix --dry-run` (config repo) | ✅ 0 file perlu diperbaiki (setelah 1 perbaikan kutip) |
| `phpstan analyse` (config repo) | ✅ No errors |
| `up()` seluruh migration worktree | ✅ 34 file; 9 tabel kelompok 4 InnoDB `utf8mb4_unicode_ci`, 0 kolom string dengan collation lain (kolom: 8/19/9/35/20/20/23/18/17) |
| FK di `REFERENTIAL_CONSTRAINTS` | ✅ 14 FK kelompok 4 + 4 FK RWY masuk (131000), semua `RESTRICT/RESTRICT`, nama = 2.4.10 |
| CHECK di `TABLE_CONSTRAINTS` + penegakan | ✅ 7 CHECK; status 3 keluarga, 11 alamat, 5 kariskarsu, -1 organisasi, `jenis_rwy.status` 3 ditolak (3819) |
| Seed `jenis_rwy` vs konstanta legacy literal | ✅ 21 baris; kode = `ARSIP_RWY`, label = `JENIS_RWY_ARSIP`, `order` = urutan array, baris 0 order 0 & `tabel_entri` NULL; `status` 1, `updated_by` NULL |
| FK orphan (1452) | ✅ `nip` keluarga/karpeg; `detail_anak.id_riwayat_keluarga`; wilayah provinsi/kelurahan; `id_tanda_jasa`; `document_attachment.NIP`; `id_riwayat` 33 |
| Induk dirujuk (1451) | ✅ hapus `riwayat_keluarga` yang punya anak; hapus master `tanda_jasa`; hapus/ubah kode `jenis_rwy` yang dirujuk; hapus/ubah `pegawai.nip` |
| Diterima | ✅ sentinel wilayah 99/9999/9999999/9999999999; `id_riwayat` 3 / 0 / NULL / 38 (`id_entri` NULL) |
| NOT NULL / tanpa default (1364/1048) | ✅ `detail_anak.tgl_lahir`, `riwayat_alamat.jenis_alamat`, `riwayat_tanda_jasa.tgl_sertifikat`, `riwayat_organisasi.tgl_mulai`, `document_attachment.NIP`; `riwayat_keluarga.nip` NULL |
| UNIQUE (1062) | ✅ `uq_jenis_rwy_kode`, `uq_jenis_rwy_nama` |
| Default | ✅ keluarga status/show_notif/jumlah_anak 0 + `created_at`/`updated_at` terisi; `detail_anak.urutan` 1, `updated_at` NULL; karpeg/kariskarsu status 0, **rated 2**, `show_ua_biro` NULL; tanda jasa status/siasn_flag 0; lampiran `created_at` terisi, `updated_at` NULL |
| EXPLAIN `id_riwayat=… AND id_entri='…'` | ✅ `key = id_riwayat_id_entri` |
| `down()` 130000..131000 (terbalik) lalu `up()` | ✅ 0 tabel tersisa; `up()` ulang 9 tabel + seed 21 baris |
| `down()` 130000 selagi `document_attachment` ada | ✅ ditolak 3730 (urutan rollback batch benar) |
| `up()` gagal di tengah | ✅ 130700 dengan penghalang `t_detail_anak` (1050): `riwayat_keluarga` yang sempat dibuat di-drop, `riwayat_alamat` tidak dibuat, penghalang utuh; 130800 dengan penghalang `t_riwayat_organisasi`: 3 tabel run itu di-drop; 130000 saat `jenis_rwy` sudah ada: gagal tanpa menyentuh tabel lama (21 baris utuh) |
| Seed `jenis_rwy` gagal (simulasi koneksi pembungkus yang melempar pada INSERT) | ✅ exception asli dilempar ulang, tabel yang baru dibuat di-drop (`seed_fail_probe.php`) |
| Schema test PHPUnit + `fastcheck.py` | ✅ dijalankan integrator (6.2) |

**Permintaan verifikasi MariaDB 10.4 (tambahan 6.3):**
1. Seed `jenis_rwy`: baris `id_jenis_rwy = 0` tersimpan (kolom tanpa AUTO_INCREMENT, tidak terpengaruh
   `NO_AUTO_VALUE_ON_ZERO`); kolom `order` (kata kunci) ter-escape.
2. FK `fk_id_riwayat_da_to_jenis_rwy` memakai index komposit `id_riwayat_id_entri` (MariaDB tidak membuat index otomatis
   tambahan); `SHOW INDEX` tetap 3 index.
3. `document_attachment.created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP` dan `detail_anak.updated_at DEFAULT NULL ON
   UPDATE CURRENT_TIMESTAMP` terbaca sama di `information_schema.COLUMNS` (MariaDB: `current_timestamp()`).
4. Kolom `kd_pos` dan `KdPos` di `riwayat_alamat` diterima sebagai dua kolom berbeda.
5. CHECK ditegakkan (4025) dan FK RESTRICT terbaca di `REFERENTIAL_CONSTRAINTS`.

**Pemulihan manual bila pembersihan otomatis gagal (tambahan 6.6).** Jangan `migrate:rollback` batch. Drop manual
(prefix sesuai lingkungan):
- `130900_CreateDocumentAttachment`: `document_attachment`.
- `130800_CreateRiwayatKartuTjOrganisasi`: `riwayat_organisasi` → `riwayat_tanda_jasa` → `riwayat_kariskarsu` →
  `riwayat_karpeg`.
- `130700_CreateRiwayatKeluargaAlamat`: `riwayat_alamat` → `detail_anak` → `riwayat_keluarga`.
- `130000_CreateJenisRwy`: `jenis_rwy` (hanya setelah `document_attachment` di-drop).
- Bila `131000_AddFkSnapshotKeRiwayat` sudah jalan, drop dulu FK `fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga`,
  `fk_id_riwayat_alamat_pegalamat_riwalamat`, `pegawai_alamat_kantor_ibfk_5`, `fk_id_riwayat_tanda_jasa_ptj_to_rtj`
  (atau rollback migration itu) sebelum men-drop `riwayat_keluarga`/`riwayat_alamat`/`riwayat_tanda_jasa` (3730).

### 6.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator) — WAJIB sebelum approval

1. `php spark migrate` → `php spark migrate:rollback` → `php spark migrate`: migration B-01/B-02 masuk batch tersendiri; rollback hanya membatalkan batch itu.
2. Seluruh `tests/database/Kepegawaian/*SchemaTest.php` di database test MariaDB (helper test menormalkan lebar tampilan `int(11)`/`tinyint(4)` dan default `'NULL'`/`current_timestamp()` MariaDB).
3. CHECK terdaftar di `information_schema.TABLE_CONSTRAINTS` dan ditegakkan (MariaDB 4025, MySQL 3819).
4. FK RESTRICT dicek lewat `information_schema.REFERENTIAL_CONSTRAINTS` (MariaDB bisa menyembunyikan klausa RESTRICT di `SHOW CREATE TABLE`); orphan ditolak 1452, hapus induk yang dirujuk ditolak 1451.
5. `NipReferenceRegistryTest`: daftar FK ke `pegawai(nip)` sama dengan konstanta ekspektasi.
6. Seed `jenis_rwy` 21 baris.
7. Butir khusus kelompok: 6.2.3 (CHECK CHAR `absen_ijin`, `year(4)`, FK nullable `nip_atasan`) dan 6.2.4 (baris `jenis_rwy` id 0, kolom `order`, index komposit FK, `current_timestamp()`, `kd_pos`/`KdPos`).
8. Laporkan hasil per butir di PR.

Butir tambahan kelompok 1:

1. `migrate` → `migrate:rollback` → `migrate`: `120000`..`120200` (dan `131000` di DBV-013) masuk batch tersendiri.
2. `PegawaiSnapshotSchemaTest`, `NipReferenceRegistryTest`, `SnapshotRiwayatFkTest` di DB test MariaDB (helper menormalkan
   `int(11)`/`tinyint(4)`/`year(4)` dan default `'NULL'`/`current_timestamp()`; CHECK 4025).
3. CHECK `chk_pegawai_*`/`chk_pegawai_hist_*` terdaftar di `information_schema.TABLE_CONSTRAINTS` dan ditegakkan.
4. FK RESTRICT dicek via `REFERENTIAL_CONSTRAINTS` (MariaDB bisa menyembunyikan klausa RESTRICT di SHOW CREATE TABLE).

### 6.4 Nilai [I] yang menunggu dump struktur produksi (gabungkan ke paket permintaan DBV-008)

1. `SHOW CREATE TABLE` seluruh tabel cakupan 1.3.
2. **`SHOW TRIGGERS` dan `SHOW CREATE TRIGGER`** semua trigger di `riwayat_*` dan `pegawai*` — penentu aturan snapshot dan `notif_date` (1.2 #1).
3. `SELECT id_riwayat, COUNT(*) FROM document_attachment GROUP BY 1` — kode di luar seed, termasuk 33 (2.4).
4. Orphan `nip` untuk setiap tabel cakupan.
5. Nilai `status` per tabel riwayat.
6. Duplikat `konv_ak.nip`.
7. `pegawai.status_pernikahan` distinct.
8. `MAX(CHAR_LENGTH)` untuk kolom teks [I].

Bila dump berbeda: koreksi lewat migration ALTER baru selagi tabel masih kosong.

#### 6.4.1 Kelompok 1

1. SHOW CREATE TABLE `pegawai_hist`, `pegawai_foto`, 11 snapshot [I] (`pegawai_cpns`, `pegawai_pns`, `pegawai_kgb`,
   `pegawai_pendidikan`, `pegawai_diklat`, `pegawai_hukdis`, `pegawai_ak`, `pegawai_keluarga`, `pegawai_alamat`,
   `pegawai_alamat_kantor`, `pegawai_tanda_jasa`) — konfirmasi juga `pegawai`/`pegawai_kp`/`pegawai_mutasi_jabatan` [L].
2. SHOW TRIGGERS/SHOW CREATE TRIGGER yang menulis `pegawai_*` dan `pegawai_foto` (aturan baris snapshot).
3. `SELECT flag_update, COUNT(*) FROM pegawai GROUP BY 1` dan `FROM pegawai_hist GROUP BY 1`.
4. `SELECT status, COUNT(*) FROM pegawai GROUP BY 1`; `jenis_kelamin` dan `status_pernikahan` distinct.
5. Orphan `nip` di `pegawai_hist`, `pegawai_foto`, 13 snapshot, `pengguna.id_pegawai`, `faq_rate.nip`; orphan
   `id_riwayat_*` snapshot → riwayat; jumlah baris per nip di tiap snapshot (harus ≤ 1).
6. `MAX(CHAR_LENGTH)` kolom teks [I] snapshot (terutama `keterangan`, `alasan_hukuman`, `jym`).

#### 6.4.2 Kelompok 2

1. `SHOW CREATE TABLE` untuk `riwayat_mutasi_jabatan`, `pegawai_plt`, `pegawai_plh`, `riwayat_kp`, `riwayat_kgb`,
   `riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar`, `jabatan_koordinasi`, `rumpun_jabatan` (PK & tipe untuk FK
   ditahan; pemetaan `fk_pegawai_plh_ibfk_04..11`).
2. `SELECT status, COUNT(*) … GROUP BY status` per tabel di atas (terutama nilai 3, K2-1).
3. Nilai distinct `jenis_jabatan`, `jenis_mutasi`, `jenis_jabatan_koord`, `status_jft`, `siasn_flag` (rmj), `jenis_diklat`,
   `jenis_seminar`, `jenis_jabatan`/`jenis_jabatan_koord` (Plt/Plh) — dasar CHECK tambahan K2-9.
4. Jumlah baris dengan kolom id G-02 = 0 atau tanpa induk (per FK di 2.2.9).
5. `MAX(CHAR_LENGTH(keterangan))` KP/KGB/pendidikan (TINYTEXT 255 byte), `MAX(jumlah_jp)`, `MAX(LENGTH(no_sertifikat))`.
6. `SHOW TRIGGERS` untuk `riwayat_mutasi_jabatan`, `riwayat_kp`, `riwayat_kgb`, `riwayat_pendidikan`, `riwayat_diklat`
   (penentu isi snapshot dan `kredit_jft`).

#### 6.4.3 Kelompok 3 (permintaan dump & audit impor)

**Permintaan dump (F-dump; gabungkan ke paket DBV-008):**
1. `SHOW CREATE TABLE` untuk `riwayat_skp`, `riwayat_skp_periodik`, `riwayat_lckh`, `riwayat_hukdis`, `riwayat_ak`, `riwayat_ak_siasn`, dan `konv_ak`. Cek juga kolom [E] `riwayat_skp.kategori_nilai`, `nilai`, dan `nilai_skp_konversi` (A #9).
2. `SELECT status, COUNT(*) FROM absen_ijin GROUP BY 1`, untuk nilai angka/`H` (A #2).
3. `SELECT status, COUNT(*) … GROUP BY 1` untuk `riwayat_skp`, `riwayat_hukdis`, `riwayat_ak`, `riwayat_ak_siasn`, `konv_ak`, `riwayat_lckh`, dan `riwayat_skp_periodik`, untuk nilai 3 dan nilai lain (K3-1).
4. Duplikat: `konv_ak.nip`; `riwayat_ak_siasn.id_rw_siasn`; `riwayat_skp_periodik (id_pns, periode_id, skp_id)`; `riwayat_lckh (nip, tgl_laporan)` dengan status ∉ {2,10} (K3-8).
5. `SELECT COUNT(*) FROM ignore_konv_ak` (K3-13); `SELECT COUNT(*) FROM riwayat_lckh WHERE nip_atasan = ''`.
6. `MAX(CHAR_LENGTH)` untuk kolom teks [I] kelompok 3 (K3-h), terutama `riwayat_hukdis.keterangan` (K3-9) dan `riwayat_skp.kategori_nilai_prestasi`. Minta juga `MAX(nilai_*)` dan `MAX(ak_*)` terhadap presisi DECIMAL(6,2)/(8,3).

**Audit sebelum impor (F-audit; untuk Mapping):**
1. Orphan `nip` di kesepuluh tabel, termasuk `riwayat_lckh.nip_atasan`.
2. `absen_ijin.status` angka/`H` → W/V/X (K3-2).
3. Status 3 riwayat → 0 (K3-1).
4. `riwayat_lckh.nip_atasan = ''` → NULL (A #6).
5. Tanggal nol (`0000-00-00`) di kolom DATE NOT NULL (`riwayat_skp.tgl_mulai/tgl_akhir`, `riwayat_hukdis.tmtsk`, `riwayat_lckh.tgl_laporan`) dan string kosong di kolom DECIMAL (`L_skp.php:649-653` menulis `addslashes('')` di mode non-strict).
6. `riwayat_ak.appr_at` legacy memakai waktu server; konversi ke UTC mengikuti keputusan ISSUE-022.
7. Counter AUTO_INCREMENT `absen_ijin` (326331 [K]) dan tabel riwayat lain disalin bila lebih besar dari `MAX(id)+1`.

#### 6.4.4 Kelompok 4 (permintaan dump & audit impor)

**F-dump (gabungkan ke paket permintaan DBV-008 / 6.4):**
1. `SHOW CREATE TABLE` `riwayat_keluarga`, `riwayat_alamat`, `riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_tanda_jasa`,
   `riwayat_organisasi` (semua kolom [I] di 2.4), serta `detail_anak`/`document_attachment` untuk mencocokkan [K].
2. `SELECT id_riwayat, COUNT(*) FROM document_attachment GROUP BY 1` (kode di luar seed, K4-3).
3. `SELECT status, COUNT(*)` per tabel kelompok 4 (nilai 3, K4-1).
4. `SELECT rated, status, COUNT(*)` karpeg/kariskarsu (K4-6); distinct `jenis_alamat`, `alamat_utama` (K4-9);
   distinct `siasn_flag` tanda jasa.
5. **`SHOW TRIGGERS`** untuk `riwayat_keluarga`, `riwayat_alamat`, `riwayat_tanda_jasa` (pengisi snapshot),
   `document_attachment` (`da_deleted`), `detail_anak`.
6. `MAX(CHAR_LENGTH)`/`MAX(LENGTH)`: `negara`, `keterangan` (TINYTEXT = 255 byte), `jenis_permohonan`, `file_*`,
   `nama_organisasi`, `kedudukan`, `kota_perkawinan`, `nama_pasangan`, `document_attachment.tag`/`path`.

**F-audit (sebelum impor, tambahan 6.5):**
1. Orphan `nip` di keenam riwayat + `document_attachment.NIP`; `detail_anak` tanpa induk (seharusnya 0 karena CASCADE).
2. `document_attachment.id_entri` yang tidak menunjuk baris di `jenis_rwy.tabel_entri` (bukan FK, dibersihkan/ditandai
   B-18); `NIP` lampiran ≠ `nip` baris riwayat yang dirujuk.
3. NULL / `0000-00-00` di `jenis_alamat`, `tgl_sertifikat`, `tgl_mulai`, `detail_anak.tgl_lahir`; kolom wilayah berisi
   `''` (hasil `addslashes('')`) → NULL (FK menolak `''`).
4. `id_riwayat` di luar seed → petakan dulu (K4-3); `NULL` tetap boleh.
5. Master `tanda_jasa` 26/27/28/44 dan sentinel wilayah wajib ada sebelum riwayat diimpor (A #11).
6. `rated` NULL/kosong: baris `status = 1` yang sudah punya survei di `skl` (`jenis_rwy` 2/3, `id_rwy`) → 1, sisanya → 2
   (agar pop-up survei v2 konsisten); nilai lain → 0.
7. Nilai legacy `created_at` karpeg/kariskarsu dan `id` wajib dipertahankan (nomor tiket/QR, `skl.id_rwy`,
   `document_attachment.id_entri`).
8. AUTO_INCREMENT: `document_attachment` 169499 [K], `detail_anak` 6094 [K]; tabel lain disalin setelah impor bila lebih
   besar dari `MAX(id)+1` (riwayat legacy dihapus keras).
9. Path berkas yang memuat NIP (`arsip/{nip}/…`) dipetakan ulang bila B-06/B-18 memindahkan penyimpanan.

### 6.5 Catatan migrasi data untuk Mapping (audit sebelum impor)

1. Orphan `nip` di semua tabel ber-FK PEG, termasuk `pengguna.nip` dan `faq_rate.nip` (G-10 6 #6: `faq_rate.nip` legacy berisi `id_pegawai`).
2. `pegawai_hist`: hanya `flag_update = 1` yang diimpor (Mapping Tier 3).
3. `pegawai.created_at` dipakai filter laporan presensi (`L_presensi.php:8255`) → nilai legacy wajib ikut diimpor.
4. Normalisasi `status` riwayat ke domain CHECK; kode `id_riwayat` di luar seed `jenis_rwy` dipetakan dulu.
5. `*_by` legacy = `user.id` akun legacy → petakan ke `id_pengguna`; stempel waktu mengikuti keputusan zona waktu (ISSUE-022).
6. Counter AUTO_INCREMENT legacy (mis. `document_attachment` 169499 [K] :519, `absen_ijin` 326331 [K] :69, `detail_anak` 6094 [K] :386) disalin setelah impor bila lebih besar dari `MAX(id)+1`, karena riwayat legacy dihapus keras.
7. Butir per kelompok: 6.5.1-6.5.2 dan F-audit di 6.4.3-6.4.4.

#### 6.5.1 Kelompok 1

1. Urutan impor: master (G-01..G-08, G-02) → `pegawai` → `pegawai_hist` (hanya `flag_update = 1`) → `pegawai_foto` →
   riwayat (DBV-013) → snapshot → `pengguna`/`faq_rate` ber-NIP. Migration FK boleh dijalankan sebelum impor (tabel
   kosong) — pra-cek orphan hanya menjaga bila urutan terbalik.
2. `pegawai.created_at` diimpor apa adanya (A #12).
3. Snapshot diimpor dari dump legacy (bukan diturunkan ulang) sampai aturan baris per riwayat B-07..B-17 selesai;
   `id_riwayat_*` yatim dikosongkan (NULL) atau riwayatnya diimpor dulu, karena `131000` fail-closed.

#### 6.5.2 Kelompok 2

1. Normalisasi `status` riwayat sesuai K2-1 (nilai 3) sebelum impor; CHECK menolak nilai di luar domain.
2. Kolom id G-02 legacy bisa berisi 0 (`addslashes('')` di mode non-strict) → ubah ke NULL sebelum `AddFkG02Riwayat`
   (pra-cek orphan fail-closed menolak).
3. Plt/Plh: `id_group_jabatan`/`id_sub_group_jabatan`/`id_jabatan` NULL bila `jenis_jabatan` 2, `jenis_jabatan_koord` NULL
   bila 1 — pertahankan apa adanya.
4. Kolom DATE NOT NULL (`tmtsk`, `tgl_sk` KP, `tgl_mulai`/`tgl_akhir`, `tgl_sertifikat`): audit NULL dan tanggal nol
   `0000-00-00` (ditolak strict mode).
5. ID master hard-code (A #11) wajib ada di impor G-05/G-02: `bidang_pendidikan` 98, `jurusan_pendidikan` 1185, satker 39.
6. Path berkas `ser_dosen`/`f_spmt`/`f_bapel` memuat NIP → dipetakan ulang bila B-06/B-18 memindahkan penyimpanan.
7. AUTO_INCREMENT legacy riwayat disalin setelah impor bila lebih besar dari `MAX(id)+1` (riwayat legacy dihapus keras;
   `document_attachment.id_entri` merujuk ID lama).

### 6.6 Pemulihan bila `up()` gagal di tengah

DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`. Setiap migration Create men-drop tabel yang sempat dibuat **pada run itu** (`dropCreated()`, urutan terbalik) lalu melempar ulang error; migration FK men-drop FK yang sempat ditambahkan. Bila pembersihan otomatis ikut gagal, pulihkan manual — **jangan `migrate:rollback` batch** (rollback membatalkan batch terakhir yang tercatat, bukan migration yang gagal).

Urutan drop manual per migration (prefix sesuai lingkungan; hanya tabel yang belum tercatat di `migrations`):

- `131000`: `ALTER TABLE <snapshot> DROP FOREIGN KEY <nama>` untuk 13 FK di 2.1.7 yang tertinggal (KEY tetap).
- `130900`: `document_attachment`. `130800`: `riwayat_organisasi` → `riwayat_tanda_jasa` → `riwayat_kariskarsu` → `riwayat_karpeg`.
- `130700`: `riwayat_alamat` → `detail_anak` → `riwayat_keluarga`. `130600`: `ignore_konv_ak` → `konv_ak` → `riwayat_ak_siasn` → `riwayat_ak` → `riwayat_hukdis`.
- `130500`: `absen_ijin`. `130400`: `riwayat_lckh` → `riwayat_skp_periodik` → `riwayat_skp` → `bkn_periode_ekinper`.
- `130300`: `riwayat_seminar` → `riwayat_diklat` → `riwayat_pendidikan`. `130200`: `riwayat_kgb` → `riwayat_kp`. `130100`: `pegawai_plh` → `pegawai_plt` → `riwayat_mutasi_jabatan`.
- `130000`: `jenis_rwy` (setelah `document_attachment` di-drop).
- `120100`: 13 snapshot urutan terbalik (`pegawai_mutasi_jabatan` → … → `pegawai_kp`), setelah FK `131000` dilepas. `120000`: `pegawai_foto` → `pegawai_hist` → `pegawai` (setelah semua tabel B-01/B-02 yang merujuk `pegawai` di-drop).
- Tabel riwayat yang dirujuk snapshot tidak bisa di-drop selama FK `131000` terpasang (3730); lepas FK itu dulu.

Catatan pemulihan kelompok 1:

- `120000`/`120100`: `dropCreated()` men-drop tabel yang dibuat pada run itu (urutan terbalik) lalu melempar ulang error.
  Bila pembersihan ikut gagal, drop manual: `pegawai_mutasi_jabatan` → … → `pegawai_kp` (urutan terbalik 2.1.4), lalu
  `pegawai_foto`, `pegawai_hist`, `pegawai` — hanya tabel yang belum tercatat di `migrations`.
- `120200`/`131000`: FK yang dipasang pada run itu dilepas otomatis; manual: `ALTER TABLE … DROP FOREIGN KEY <nama>`
  untuk FK di 2.1.6/2.1.7 yang tertinggal. Index tidak perlu diubah.
- Jangan `migrate:rollback` batch untuk migration yang gagal (tidak tercatat di `migrations`).

Catatan pemulihan kelompok 2:

- `130300`: `riwayat_seminar` → `riwayat_diklat` → `riwayat_pendidikan`.
- `130200`: `riwayat_kgb` → `riwayat_kp`.
- `130100`: `pegawai_plh` → `pegawai_plt` → `riwayat_mutasi_jabatan`.
- Bila `131000_AddFkSnapshotKeRiwayat` sudah jalan, FK RWY dari `pegawai_kp`/`pegawai_cpns`/`pegawai_pns`/`pegawai_kgb`/
  `pegawai_pendidikan`/`pegawai_diklat`/`pegawai_mutasi_jabatan` ke tabel kelompok 2 harus di-drop dulu (MySQL 3730). Hal
  yang sama berlaku untuk test `down()` → `up()` kelompok 2: jalankan saat `131000` belum/tidak terpasang.
- `AddFkG02Riwayat`: `ALTER TABLE … DROP FOREIGN KEY …` per tabel (KEY tetap).

## 7. Migration yang ditahan (tidak ikut PR B-01/B-02)

Ketiga migration di bawah **tidak** berada di `app/Database/Migrations/` pada branch draf, karena bila ada di sana setiap
`php spark migrate` dan setiap test ber-`$migrate = true` gagal (tabel G-02 belum ada di `main`) atau Dev berhenti di tengah
batch (FK masuk, 1.5 #1). Drafnya disimpan di branch lokal `dbv-012/fk-ditahan` (di atas commit DBV-013, belum di-push)
di folder `backend/app/Database/Migrations/` supaya siap dipakai; branch itu **tidak** untuk di-merge apa adanya. Saat
diaktifkan, timestamp ditetapkan ulang agar lebih besar dari migration yang sudah jalan di lingkungan target (CI4 menjalankan
migration yang belum tercatat tanpa melihat urutan, tetapi urutan versi menentukan urutan rollback).

| Migration (timestamp draf) | Isi | Key | Prasyarat aktif | Pra-cek |
|---|---|---|---|---|
| `AddFkPegawaiDiPenggunaFaqRate` (`120200`) | `fk_id_pegawai_pengguna_to_pegawai` (`pengguna.nip`, nullable, memakai UNIQUE `nip` yang ada), `fk_nip_faqrate_to_peg` (`faq_rate.nip`, memakai KEY G-10 yang ada); RESTRICT/RESTRICT | DBV-012 | 4.1 #12: `pegawai` terisi; validasi NIP di AccountProvisioner/Manajemen Akun (422); seed & test akun/rating menyisipkan `pegawai` | Orphan per tabel (termasuk akun soft-deleted) → exception sebelum ALTER apa pun; FK yang sudah ada dilewati; ALTER kedua gagal → FK pertama dilepas; `down()` hanya DROP FK (idempoten). Test-nya (`NipReferenceRegistryTest::testFkMasukRefusesOrphansBeforeAnyAlter`) ikut disimpan di branch yang sama |
| `AddFkG02Pegawai` (`129900`) | 10 FK snapshot → `group_jabatan`/`sub_group_jabatan`/`unit`/`satker`/`jabatan` (`pegawai_mutasi_jabatan` 9, `pegawai_ak` 1) + konstanta `MENUNGGU_TABEL` 4 FK ke `jabatan_koordinasi`/`rumpun_jabatan` | DBV-012 | DBV-008 merge; 4 FK sisanya setelah tabel koordinasi/rumpun ada (4.1 #9) | Tabel/kolom induk ada; orphan per FK; rollback FK yang dipasang pada run itu |
| `AddFkG02Riwayat` (`139900`) | 40 FK riwayat/penugasan → G-02 (34 ke tabel draf DBV-008, 6 ke `jabatan_koordinasi`/`rumpun_jabatan`, 2.2.9) | DBV-013 | Sama | Orphan per FK termasuk nilai 0 legacy; satu ALTER per tabel; ALTER gagal → FK tabel yang sudah dipasang dilepas |

KEY untuk semua FK di atas sudah dibuat migration Create B-01/B-02 dengan nama FK yang sama, jadi migration yang ditahan
cukup `ADD CONSTRAINT`; schema test B-01/B-02 sudah mengizinkan nama FK itu muncul kelak (`LATER_FOREIGN_KEYS`) dan registry
NIP memindahkan `pengguna.nip`/`faq_rate.nip` dari "FK ditahan" ke registry FK saat `120200` aktif.
