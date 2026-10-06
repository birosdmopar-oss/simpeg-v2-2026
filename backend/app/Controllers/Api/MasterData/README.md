# Modul G — Master Data & Pengaturan (Fase 2)

Controller REST API modul ini (`App\Controllers\Api\MasterData\*`), semua extends `App\Controllers\Api\ApiController`.
Envelope: sukses `{status:'success', data}`, gagal `{status:'error', message, errors?}` (ADR-001).
Role akses mengacu Matriks Role x Endpoint Bagian 2 Modul G. Prefix seluruh path: `/api/v1`.

Error umum di seluruh endpoint (`ApiController` dan handler global `ApiExceptionHandler`, CR-007):
- query string atau body form (form-urlencoded/multipart) yang bukan UTF-8 valid → **422** `message: "Input tidak valid (encoding)."`, `errors: { <field>: ["Isian mengandung karakter yang tidak valid (bukan UTF-8)."] }` (field bersarang bernotasi titik; key ikut dicek). Ditolak sebelum menyentuh DB. Body JSON seperti itu tetap 400 "Body JSON tidak valid.".
- segmen path URL yang bukan UTF-8 valid (mis. `/master/agama/%C3`), atau memuat karakter di luar `Config\App::$permittedURIChars` (huruf/angka ASCII, spasi, `~ % . : _ -`), ditolak Router CI4 sebelum controller → **400** `message: "Permintaan tidak valid."` tanpa `errors`; segmen tidak dipantulkan (detail di log). Cek parameter route di `ApiController::_remap()` (422 "Input tidak valid (encoding)." tanpa `errors`) hanya lapis cadangan bila `permittedURIChars` dilonggarkan.
- exception yang lolos ke handler global pada path `/api/...` (dan `/`) selalu dijawab envelope ini, dengan atau tanpa header `Accept: application/json` (`Config\Exceptions::isApiRequest()` memakai route path, bukan path yang memuat `index.php`); error 4xx dari framework → "Permintaan tidak valid.".
- nilai yang ditolak MySQL strict (1406 terlalu panjang, 1264 di luar rentang, 1366, 1292, 1265, 1364) → **422** `message: "Data tidak dapat diproses karena ada isian yang tidak valid."` tanpa `errors`; pesan MySQL (kolom, nilai) hanya di log. Error DB lain (lock wait, deadlock, 1062 yang tidak diterjemahkan service, koneksi) tetap 500.

## Engine CRUD master generik

Seluruh master memakai satu engine. Menambah master = migration + 1 entri di `Config\MasterData` + key di controller grupnya.

| Komponen | Peran |
|---|---|
| `Config\MasterData` | Registry master (tabel, PK, kolom nama, induk, controller grup). Satu sumber kebenaran untuk routing, service, dan metadata form FE |
| `Libraries\MasterData\MasterService` | Seluruh aturan G-TC (keunikan, soft delete, reorder, cache dropdown), transaksi |
| `Models\MasterData\MasterModel` | Model generik turunan `BaseAuditableModel` → audit otomatis |
| `Controllers\Api\MasterData\BaseMasterController` | Endpoint generik + validasi bentuk. Controller grup (`UmumController`, `FaqController`, dst.) hanya mendaftarkan key master |
| `Libraries\MasterData\MasterHooks` | Hook tulis per master (opsi `hooks`): dipanggil saat tambah & ubah, setelah validasi dan sebelum tulis, dalam transaksi yang sama — untuk sanitasi & kolom turunan (mis. `FaqArticleHooks`) |

Opsi definisi tambahan (DBV-002): `fields.*.type = html` (konten HTML, maks 1.000.000 byte), `fields.*.maxBytes` (batas BYTE, mis. 255 untuk TINYTEXT; rule `max_byte_length[N]`), `extraSearch` (kolom tambahan yang ikut dicari), `hooks`, `listExclude` (kolom yang tidak dikirim di daftar admin; detail tetap mengirimnya), `publicOptions` (default `true` = dropdown UL_ALL; `false` = dropdown hanya role 1), `hiddenColumns` (kolom tabel yang tidak dikelola engine dan tidak pernah dikirim di respons admin mana pun).

Opsi definisi tambahan (CR-009, fondasi DBV-003/004/005; semua opsional, bawaan = perilaku lama):

| Opsi | Arti |
|---|---|
| `orderMode` | `shift` (bawaan): `order` = posisi tampil 1..n, tambah/pindah menggeser entri lain, hapus merapatkan. `manual`: `order` = nilai bisnis (mis. level pangkat, DBV-004) — disimpan apa adanya, **tidak pernah** menggeser/menomori ulang entri lain (tambah, ubah, PATCH order, hapus, pulihkan), boleh kembar; kosong saat tambah = MAX+1 dari seluruh entri lingkupnya **termasuk yang dihapus** (levelnya tetap dipegang dan kembali saat dipulihkan) |
| `orderScope` | field **wajib** pembentuk lingkup urutan selain induk (mis. diklat per `jenis_diklat`, DBV-005), dan wajib ikut `filters` (daftar admin disaring per lingkup; tanpa itu daftar mencampur lingkup sehingga panah urutan FE salah hitung): nomor urut, penggeseran, MAX+1, dan pulihkan berlaku per nilai field itu; pindah nilai = ditaruh di akhir lingkup baru, lingkup lama dirapatkan. Daftar & dropdown diurutkan induk → field lingkup → `order` → nama |
| `orderColumnType` | tipe kolom `order` (`tinyint`, `smallint`, `int` [bawaan], … `+ unsigned`): batas nilai urutan mode manual (422 "Urutan maksimal 127.") dan batas MAX+1 otomatis di kedua mode, termasuk tambah dengan `order` di lingkup mode shift yang sudah penuh (dicek sebelum insert; 422 "Urutan … sudah mencapai batas maksimal …", bukan 500/terpotong) |
| `uniqueFields` | field selain nama yang ber-UNIQUE di DB: `['old_id']` (unik global) atau `['kode' => ['id_induk']]` (unik per lingkup). Dicek seperti nama (case-insensitive, termasuk entri tidak aktif/dihapus + saran pulihkan), nilai kosong/NULL tidak dibatasi; pelanggaran index (1062) saat balapan juga → 422 pada field itu |
| `filters` | allowlist field untuk filter `?field=nilai` di **options** dan **daftar admin** (mis. pangkat `?cpns=1`). Field lain di query diabaikan; nilai yang tidak sah untuk tipe field-nya (bukan pilihan select, bukan 0/1, bukan angka/kode, array) → 422 "Filter X tidak valid." Cache dropdown dipisah per filter |
| `statusChain` | options hanya memuat entri yang **seluruh** rantai induknya status 1 (pola U3 FAQ, mis. jurusan hilang saat bidangnya tidak aktif — DBV-004 E6). Status turunan tidak diubah; menulis leluhur meng-invalidate cache dropdown turunan ber-`statusChain`. `MasterService::whereActiveChain()` bisa dipakai service khusus untuk tampilan yang sama |
| `fields.*.type = int` + `columnType` / `min` / `max` | batas nilai = rentang tipe kolom (`tinyint` 0–127, `int unsigned` 0–4.294.967.295, bawaan `int` 0–2.147.483.647; min bawaan 0) dijepit batas eksplisit → 422 "X maksimal 127." (bukan 422 generik tanpa `errors` dari 1264 di koneksi strict — CR-007 — atau terpotong diam-diam). `decimal` memakai `min`/`max` eksplisit. Meta field mengirim `min`/`max` |
| `fields.*.type = boolean` | flag 1/0 (TINYINT NOT NULL DEFAULT 0, mis. D_I..S_3): menerima `'1'`/`'0'`/`1`/`0`/JSON `true`/`false`; lainnya 422 "X hanya boleh 1 (ya) atau 0 (tidak)."; tidak dikirim saat tambah = default DB. Form: checkbox |
| `fields.*.type = ref` + `entity` / `dependsOn` / `checkDependsOn` | rujukan ke master lain (dropdown `{entity}/options`). Kode wajib bentuk kanonik master rujukan (422 "X tidak ditemukan."), ada, dan aktif (hanya bila nilainya berubah, seperti induk E6). `dependsOn` = field ref lain di form yang menjadi induk entri rujukan (dropdown berjenjang, mis. kabupaten/kota ← provinsi): entri rujukan wajib berada di bawah nilai itu (422 "… tidak berada di bawah X yang dipilih." / "Pilih X terlebih dahulu."), diperiksa juga saat hanya `dependsOn`-nya yang berubah. `checkDependsOn: false` bila rantai diperiksa hook (mis. sentinel LAIN-LAIN kantor DBV-003): engine tidak memeriksa rantai maupun keberadaan nilai `dependsOn`, tetapi kanonik/ada/aktif tetap diperiksa. Meta field mengirim `entity`/`depends_on` |

Konfigurasi diperiksa saat registry dibangun (`MasterRegistry`): induk/entity ref terdaftar, `dependsOn` menunjuk field ref yang entity-nya induk master rujukan, field `orderScope`/`uniqueFields`/`filters` ada (filter bukan `search`/`status`/`parent`/`page`/`per_page`; `orderScope` = field wajib dan ikut `filters`), `statusChain` hanya untuk master berinduk, tanpa rantai induk melingkar → selain itu `LogicException`. Meta master mengirim `order_mode`, `order_scope`, `order_max` (mode manual), `filters`, `status_chain`.

Opsi definisi tambahan (CR-010, DBV-003; opsional, bawaan = perilaku lama):

| Opsi | Arti |
|---|---|
| `systemIds` | kode **baris sistem** master itu (sentinel LAIN-LAIN wilayah: `provinsi` 99, `kabupaten-kota` 9999, `kecamatan` 9999999, `kelurahan` 9999999999, di-seed migration `2026-09-25-100200`). Tidak pernah tampil di options maupun daftar admin (filter apa pun), tidak ikut penomoran urutan (`order` 0 tidak disentuh reorder/MAX+1), `PUT`/`PATCH status`/`PATCH order`/`DELETE` → 422 "… adalah baris sistem dan tidak bisa diubah, dinonaktifkan, atau dihapus." tanpa tulis & audit, tidak bisa menjadi induk (422 "<Induk> LAIN-LAIN tidak bisa dipilih sebagai induk."), namanya tidak bisa dipakai entri riil (422 "… sudah dipakai baris sistem …"). `GET {kode}` tetap 200. Meta master mengirim `system_ids` |
| `fields.*.allowSystem` | field `ref` boleh merujuk baris sistem master rujukannya (kolom wilayah `kantor`); field ref lain menolaknya dengan pesan kode tak dikenal ("X tidak ditemukan."). Kanonik/ada/aktif tetap diperiksa. Meta field ref mengirim `allow_system` |
| `fields.*.otherFor` | field `text`/`textarea` = isian "lainnya" untuk field ref ber-`allowSystem` di master yang sama (mis. `provinsi_lain` → `id_provinsi`). Hanya metadata FE (tampil & wajib saat field ref-nya LAIN-LAIN); wajib/NULL ditegakkan hook master. Meta field text/textarea mengirim `other_for` |

Registry menolak (`LogicException`) `systemIds` yang bukan kode kanonik master itu atau ditulis dobel, `allowSystem` ke master tanpa `systemIds` atau di luar tipe `ref`, dan `otherFor` yang tidak menunjuk field ref ber-`allowSystem`.

Opsi definisi tambahan (CR-026, DBV-008 G-02; opsional, bawaan = perilaku lama):

| Opsi | Arti |
|---|---|
| `hasOrder` | `false` untuk tabel tanpa kolom `order` (mis. `jabatan`, `kelas-jabatan`): daftar & dropdown diurutkan nama, `PATCH {kode}/order` → 422 "… tidak memakai urutan tampil.", meta `has_order: false` (FE tidak menampilkan kolom Urutan maupun item urutan di menu ⋮). Opsi ini sudah ada di `MasterDefinition` sejak awal, baru dipakai G-02 |
| `codeAsName` | kode (PK) sekaligus nama tampilan — `nameField` = `primaryKey`, kode manual (PK alami tanpa kolom nama, mis. `kelas_jabatan`). Tambah: rule kode yang berlaku (rule nama tidak dipasang karena key-nya sama), keunikan nama = keunikan kode ("Kode 7 sudah dipakai."). Ubah: nama yang dikirim wajib sama dengan kode, selain itu 422 pada kode "… adalah kode entri dan tidak dapat diubah."; kolom lain boleh diubah. Options: `id` = `nama`. Meta `code_as_name: true` → form FE tidak merender input nama terpisah dan input kode berlabel nama master |
| `idRange` | `[min, max]` (1 ≤ min ≤ max): kode manual berupa bilangan bulat **tanpa nol di depan** dalam rentang itu (mis. kelas jabatan `[1, 20]`, mask form legacy). Tambah: `required|regex_match[/^[1-9][0-9]*\z/]|greater_than_equal_to[min]|less_than_equal_to[max]` → 422 pada kode; `{kode}` di URL kanonik = bilangan bulat tanpa nol di depan (`07` → 404, bukan alias 7 yang di-cast MySQL). Meta `id_range` |

Registry menolak `codeAsName` pada master AUTO_INCREMENT atau dengan `nameField` ≠ `primaryKey`, `nameField` = `primaryKey` tanpa `codeAsName`, `idRange` pada master AUTO_INCREMENT/ber-`idDigits`, dan `idRange` dengan min < 1 atau min > max.

**Blok per grup (CR-009).** `Config\MasterData` (entri & konstanta), `tests/_support/MasterDataTestTrait::masterFixtures()`, dan `tests/_support/Database/Seeds/MasterDataSeeder` (panggilan & method seed) punya blok bertanda `// --- DBV-003 ---` … `// --- /DBV-003 ---` (juga DBV-004, DBV-005). Setiap grup hanya menambah di dalam bloknya agar cabang paralel tidak konflik; urutan entri config = urutan fixture (dicek `RbacMasterEndpointsTest`). Daftar master ber-`publicOptions: false` di test RBAC dibaca dari config.

Skema mengikuti SIMPEG legacy (DBV-001, G-01 Bagian 8; FAQ: DBV-002, G-10): nama tabel & kolom legacy, status **`1` Aktif / `2` Tidak Aktif / `10` Dihapus**, kolom audit legacy (`created_at`, `updated_at`, `updated_by` = `id_pengguna` aktor; `agama` + `deleted_at`). Tabel yang punya `created_by` (FAQ) mengikuti legacy: tambah mengisi `created_by` dan membiarkan `updated_by` NULL, ubah mengisi `updated_by`; tabel tanpa `created_by` mengisi `updated_by` saat tambah maupun ubah.

Kode master (PK) **tidak bisa diubah** setelah dibuat. Tiga bentuk:
- **kode diinput admin** (wilayah): string tepat 2/4/7/10 digit angka (`CHAR(N)`), tidak pernah di-cast ke int (`'09'` ≠ `'9'`);
- **kode angka dalam rentang** (`idRange`, kelas jabatan 1–20, CR-026): bilangan bulat tanpa nol di depan, diinput admin;
- **AUTO_INCREMENT** (agama, jenis pegawai, jenis status, topik/sub topik/artikel FAQ, unit/satker/jabatan): diberikan DB, kode dari input diabaikan.

`{kode}` di URL harus bentuk kanonik; selain itu 404 (bukan alias entri lain): AUTO_INCREMENT dan kode `idRange` = bilangan bulat positif tanpa nol di depan (`6`, bukan `06`/`6abc`), kode wilayah = tepat N digit.

## Endpoint

`{entity}` = key master (tabel di bawah). `{kode}` = PK entri.

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/master/meta` | 1 | Daftar master + metadata form/tabel (dipakai halaman Master Data FE) |
| GET | `/master/{entity}` | 1 | Daftar, urut induk → `order` → nama. Default **tanpa** status `10` (seperti legacy). Query: `search`, `status` (`1`/`2`/`10`), `parent` (id induk), `page`, `per_page` (≤100), + field allowlist `filters` — aturan lengkap di [GET /master/{entity}](#get-masterentity-daftar). Kolom `listExclude` tidak dikirim (mis. `content`/`content_stripped` artikel FAQ) |
| GET | `/master/{entity}/options` | **UL_ALL**; FAQ: **1** | Dropdown untuk modul lain: **hanya entri aktif** (master ber-`statusChain`: seluruh rantai induknya juga aktif), urut `order`. Query: `parent` + field allowlist opsi `filters` (mis. `?cpns=1`; field lain diabaikan, nilai tidak sah → 422). `parent` wajib bentuk kanonik kode induk (`1`, bukan `01`/`1abc`) → selain itu 422 `parent` "Filter <Induk> tidak valid." (MySQL meng-cast `1abc` ke 1); bentuk array/objek (`?parent[]=1`, `?parent[a]=1`) juga 422 yang sama (CR-024); `?parent=` kosong = tanpa filter; master tanpa induk mengabaikan `parent`. Aturan lengkap di [GET /master/{entity}/options](#get-masterentityoptions). Di-cache per induk+filter (kunci tidak bisa bentrok antar kombinasi), invalidasi di setiap penulisan. Master ber-`publicOptions: false` (`faq-topic`, `faq-sub-topic`, `faq-article`) hanya role 1 — role lain 403 (lihat bagian FAQ) |
| POST | `/master/{entity}` | 1 | Tambah → 201 |
| GET | `/master/{entity}/{kode}` | 1 | Detail (+ `parent_nama` untuk master berinduk) |
| PUT | `/master/{entity}/{kode}` | 1 | Ubah parsial (nama, induk, order, status). Kode tidak ikut diubah |
| PATCH | `/master/{entity}/{kode}/status` | 1 | Toggle `{ "status": "1"\|"2" }`; juga **memulihkan** entri berstatus `10` (kosongkan `deleted_at`) |
| PATCH | `/master/{entity}/{kode}/order` | 1 | Pindah posisi `{ "order": n }` (1-based, per lingkup urutan = induk + `orderScope`, hanya entri yang tampil); entri lain bergeser. Mode urutan manual: `order` entri itu diganti menjadi `n` (maks sesuai `orderColumnType`), entri lain tidak berubah. Entri berstatus `10` → 422 (pulihkan dulu) |
| DELETE | `/master/{entity}/{kode}` | 1 | **Soft delete** → `status=10` (+ `deleted_at` bila ada). Tidak pernah hard delete |

**Kolom audit saat urutan bergeser:** hanya entri yang diedit (dipindah, diubah, dihapus, dipulihkan) yang di-stamp `updated_at`/`updated_by`. Entri lain yang sekadar bergeser karena itu (reorder, sisip, hapus, pindah induk) **tidak** berubah `updated_at`/`updated_by`-nya (legacy tidak me-renumber saudara), tetapi perubahan `order`-nya tetap tercatat di `audit_logs` (event `update`).

**Serialisasi tulis (CR-020, ISSUE-020):** setiap `POST`/`PUT`/`PATCH status`/`PATCH order`/`DELETE` master berjalan di dalam named lock `GET_LOCK` per database + prefix + **tabel** master (sesi koneksi, diambil sebelum transaksi dan dilepas sesudah commit/rollback, batas tunggu 10 detik), sehingga tambah/sisip/pindah induk atau lingkup/pulihkan yang paralel di lingkup urutan yang sama membaca urutan terbaru dan hasilnya tetap rapat 1..n tanpa kembar. Lock tidak didapat → **409** `message: "Data <Master> sedang diubah pengguna lain. Coba lagi."` (mis. "Data Agama …") tanpa tulis & audit. Tabel master lain tidak ikut terkunci; baca (daftar, detail, options) tidak memakai lock. Satu lock per tabel (bukan per lingkup) dipilih agar pindah lingkup cukup satu lock; `SELECT … FOR UPDATE` tidak dipakai (gap lock rawan deadlock 1213, alasan DBV-003 #15). `MasterService` tidak boleh dipanggil di dalam transaksi pemanggil (lock akan lepas sebelum commit luar) → `LogicException`.

Role selain 1 → `403 {status:'error', message:'Forbidden'}`; tanpa token → 401.

### Master yang tersedia

| Task | Controller | `{entity}` | Tabel | PK | Nama | Induk | Kolom tambahan |
|---|---|---|---|---|---|---|---|
| G-02 ⏳ | `JabatanController` | `unit` | unit | id_unit (AUTO_INCREMENT) | unit (≤150, label "Unit Kerja", unik) | — | `is_upt` (boolean 1/0), `alamat_pdf_header` (≤255 byte), `tembusan_kppn` (≤256), `lokasi_kppn` (≤50) |
| G-02 ⏳ | `JabatanController` | `satker` | satker | id_satker (AUTO_INCREMENT) | satker (≤150, label "Satuan Kerja", unik per unit) | `id_unit` → unit | `zonasi` **wajib** 0–120 (menit dari WIB: 0 WIB, 60 WITA, 120 WIT), `is_upt`, `alamat_pdf_header` (≤65.535 byte), `tembusan_kppn`, `lokasi_kppn`; `logo_uns` disimpan tetapi tidak dikelola/diekspos; urutan per unit; options `statusChain` |
| G-02 ⏳ | `JabatanController` | `group-jabatan` | group_jabatan | id_group_jabatan (AUTO_INCREMENT) | group_jabatan (≤45, unik) | — | — |
| G-02 ⏳ | `JabatanController` | `sub-group-jabatan` | sub_group_jabatan | id_sub_group_jabatan (AUTO_INCREMENT) | sub_group_jabatan (≤100, unik per group) | `id_group_jabatan` → group-jabatan | `need_satker` wajib `1` Ya / `2` Tidak; pindah group ditolak bila sudah dirujuk jabatan (`SubGroupJabatanHooks`); urutan per group; options `statusChain` |
| G-02 ⏳ | `JabatanController` | `kelas-jabatan` | kelas_jabatan | kelas_jabatan (nomor kelas 1–20, **kode = nama**, `codeAsName` + `idRange`) | = kode | — | `tukin` wajib (0–2.147.483.647); tanpa `order` |
| G-02 ⏳ | `JabatanController` | `jabatan` | jabatan | id_jabatan (AUTO_INCREMENT) | jabatan (≤250, unik per sub group + satker, termasuk satker kosong) | — | `id_group_jabatan` (ref wajib), `id_sub_group_jabatan` (ref wajib, di bawah group), `id_satker` (ref opsional), `kelas_jabatan` (ref opsional), `umur_pensiun` (opsional 50–80); filter `?id_group_jabatan=&id_sub_group_jabatan=&id_satker=&kelas_jabatan=`; `id_jenjang_jf` tidak dikelola; tanpa `order` |
| G-02 ⏳ | `JabatanController` | `rumpun-jabatan` | rumpun_jabatan | id_rumpun_jabatan (AUTO_INCREMENT, TINYINT maks 127) | rumpun_jabatan (≤50, unik) | — | — (created_by diisi saat tambah, DBV-018/CR-032) |
| G-02 ⏳ | `JabatanController` | `subrumpun-jabatan` | subrumpun_jabatan | id_subrumpun_jabatan (AUTO_INCREMENT, TINYINT maks 127) | subrumpun_jabatan (≤255, unik per rumpun) | `id_rumpun_jabatan` → rumpun-jabatan | urutan per rumpun; options `statusChain` |
| G-02 ⏳ | `JabatanController` | `jabatan-akademik` | jabatan_akademik | id_jabatan_akademik (AUTO_INCREMENT) | jabatan_akademik (≤255, unik) | — | `is_atasan` wajib `1` Ya / `2` Tidak (label "Jabatan Atasan"); tanpa `order` |
| G-02 ⏳ | `JabatanController` | `periode-struktur-jabatan` | periode_struktur_jabatan | id_periode_struktur_jabatan (AUTO_INCREMENT) | periode_struktur_jabatan (≤45, label "Periode Struktur", unik; tahun 4 digit 2000–3000 — `PeriodeStrukturJabatanHooks`) | — | tanpa `order` |
| G-04 ✅ | `KpController` | `pangkat` | pangkat | id_pangkat (AUTO_INCREMENT, TINYINT) | gol_ruang (≤10, label "Gol./Ruang", **unik**) | — | `cpns` wajib `1` CPNS / `2` PNS, `pangkat` wajib (≤50), `gol` wajib `I`–`IV`, `ruang` wajib `a`–`e`; `order` = level pangkat (mode urutan manual, 1–127); filter `?cpns=` |
| G-04 ✅ | `KpController` | `jenis-kp` | jenis_kp | id_jenis_kp (AUTO_INCREMENT, TINYINT) | jenis_kp (≤100) | — | — |
| G-04 ✅ | `KpController` | `gol-pppk` | gol_pppk | id_gol_pppk (AUTO_INCREMENT, TINYINT) | gol_pppk (≤10) | — | `uang_makan` wajib (desimal 0–10.000.000), `keterangan` (opsional, ≤255 byte) |
| G-05 ✅ | `PendidikanController` | `jenjang-pendidikan` | jenjang_pendidikan | id_jenjang_pendidikan (AUTO_INCREMENT) | jenjang_pendidikan (≤100) | — | `jenjang_pendidikan_singkat` wajib (≤50, **unik**), `row_jurusan` opsional `D_I`/`D_II`/`D_III`/`D_IV`/`S_1`/`S_2`/`S_3` (kosong = NULL); kolom `bobot_ipasn` tidak diekspos |
| G-05 ✅ | `PendidikanController` | `bidang-pendidikan` | bidang_pendidikan | id_bidang_pendidikan (AUTO_INCREMENT, TINYINT) | bidang_pendidikan (≤100) | — | `bidang_pendidikan_english` (opsional, ≤100) |
| G-05 ✅ | `PendidikanController` | `jurusan-pendidikan` | jurusan_pendidikan | id_jurusan_pendidikan (AUTO_INCREMENT) | jurusan_pendidikan (≤255, unik per bidang) | `id_bidang_pendidikan` → bidang-pendidikan | `jurusan_pendidikan_english` (≤255), `gelar` (≤50), flag `D_I`…`S_3` (boolean 1/0, minimal satu bernilai 1) |
| G-06 ✅ | `DiklatController` | `diklat` | diklat | id_diklat (AUTO_INCREMENT, TINYINT legacy: maks. 127) | nama_diklat (≤255, label "Nama Pelatihan") | — | `jenis_diklat` wajib `1`–`5` (Struktural, Teknis, Fungsional, Prajabatan, Sertifikasi); nama unik & urutan per jenis; filter `?jenis_diklat=` di daftar & options |
| G-06 ✅ | `HukdisController` | `tingkat-hukdis` | tingkat_hukdis | id_tingkat_hukdis (AUTO_INCREMENT) | tingkat_hukdis (≤100) | — | `bobot_ipasn` (skor IPASN legacy) disimpan tetapi tidak dikelola & tidak diekspos (`hiddenColumns`) |
| G-06 ✅ | `HukdisController` | `jenis-hukdis` | jenis_hukdis | id_jenis_hukdis (AUTO_INCREMENT) | jenis_hukdis (≤255) | `id_tingkat_hukdis` → tingkat-hukdis | `masa_sanksi_bulan` opsional `1`–`255` (kosong = NULL); options hanya jenis yang tingkatnya aktif (`statusChain`) |
| G-06 ✅ | `KonketController` | `jenis-konket` | jenis_konket | id_jenis_konket (AUTO_INCREMENT) | jenis_konket (≤255, label "Jenis Konfirmasi Ketidakhadiran") | — | `old_id` ("Kode Kategori" = `absen_ijin.kategori`) wajib ≥1 & unik (`uniqueFields`); `affect_tukin` wajib `1` Ya / `2` Tidak |
| G-06 ✅ | `TandaJasaController` | `tanda-jasa` | tanda_jasa | id_tanda_jasa (AUTO_INCREMENT) | tanda_jasa (≤255) | — | — |
| G-07 | `UmumController` | `agama` | agama | id_agama (AUTO_INCREMENT) | agama (≤30) | — | — |
| G-07 | `UmumController` | `jenis-pegawai` | jenis_pegawai | id_jenis_pegawai (AUTO_INCREMENT) | jenis_pegawai (≤50) | — | — |
| G-07 | `UmumController` | `jenis-status` | jenis_status | id_jenis_status (AUTO_INCREMENT) | jenis_status (≤50) | — | `status_pegawai` wajib `1`/`2`; nama unik per status_pegawai |
| G-07 | `UmumController` | `provinsi` | provinsi | id_provinsi (2 digit) | provinsi (≤255) | — | — |
| G-07 | `UmumController` | `kabupaten-kota` | kabupaten_kota | id_kabupaten_kota (4 digit) | kabupaten_kota | `id_provinsi` → provinsi | `kd_area` (≤4) |
| G-07 | `UmumController` | `kecamatan` | kecamatan | id_kecamatan (7 digit) | kecamatan | `id_kabupaten_kota` → kabupaten-kota | — |
| G-07 | `UmumController` | `kelurahan` | kelurahan | id_kelurahan (10 digit) | kelurahan | `id_kecamatan` → kecamatan | `kd_pos` (kode pos 5 digit, boleh beberapa dipisah koma) |
| G-07 ✅ | `UmumController` | `kantor` | kantor | id_kantor (AUTO_INCREMENT) | nama_kantor (≤255, unik **global**) | — | `alamat` (wajib, ≤65.535 byte); `id_provinsi`/`id_kabupaten`/`id_kecamatan`/`id_kelurahan` (ref wajib, berjenjang, boleh LAIN-LAIN) + `provinsi_lain`/`kabupaten_lain`/`kecamatan_lain`/`kelurahan_lain` (≤255); `kode_pos` (5 digit); `telp`, `faks` (≤50); `remark` — aturan lintas kolom di `KantorHooks` (bawah) |
| G-07 ✅ | `UmumController` | `bidang-kursem` | bidang_kursem | id_bidang_kursem (AUTO_INCREMENT, TINYINT maks 127) | bidang_kursem (≤255) | — | — (tanpa `*_by`; aktor di `audit_logs`) |
| G-07 ✅ | `UmumController` | `instansi-kursem` | instansi_kursem | id_instansi_kursem (AUTO_INCREMENT, TINYINT maks 127) | instansi_kursem (≤255) | — | — (tanpa `*_by`) |
| G-08 ✅ | `HariLiburController` | `jenis-libur` | jenis_libur | id_jenis_libur (AUTO_INCREMENT, TINYINT maks 127) | jenis_libur (≤255) | — | — (dropdown form hari libur) |
| G-10 ⏳ | `FaqController` | `faq-topic` | faq_topic | id_faq_topic (AUTO_INCREMENT) | faq_topic (≤255) | — | `remark` (opsional, ≤255 **byte**) |
| G-10 ⏳ | `FaqController` | `faq-sub-topic` | faq_sub_topic | id_faq_sub_topic (AUTO_INCREMENT) | faq_sub_topic (≤255) | `id_faq_topic` → faq-topic | `remark` (opsional, ≤255 byte) |
| G-10 ⏳ | `FaqController` | `faq-article` | faq_article | id_faq_article (AUTO_INCREMENT) | title (≤255, label "Judul Artikel") | `id_faq_sub_topic` → faq-sub-topic | `content` (tipe `html`, wajib, ≤1.000.000 byte, disanitasi server); `content_stripped` diisi server, bukan input. Daftar tidak mengirim `content`/`content_stripped`; pencarian daftar ikut mencari `content_stripped` |

G-10 ⏳ = pilot DBV-002/CR-003, menunggu approval DB Validator & review kode (`backend/docs/db-review/G-10-faq-schema.md`). Kolom `faq_topic.icon` ada di tabel tetapi belum dikelola dan **tidak diekspos** (D6): tidak ada di form/meta dan tidak dikirim di respons admin mana pun (daftar, detail, hasil tambah/ubah/status/urutan/hapus) lewat `hiddenColumns`; nilainya di DB tidak disentuh.

G-04/G-05 ✅ = DBV-004/CR-011, disetujui DB Validator & review kode, di main lewat PR #13 (merge `acd8693`; `backend/docs/db-review/G-04-G-05-pangkat-pendidikan-schema.md`). Perilaku khusus:
- `pangkat`: `order` = **level pangkat** (mode urutan manual) — disimpan apa adanya 1–127, tidak pernah menggeser atau menomori ulang pangkat lain saat tambah/ubah/hapus/pulihkan (G-TC #4 tidak berlaku); tambah tanpa `order` = nilai terbesar seluruh pangkat (termasuk yang dihapus) + 1. Dropdown per jenis: `pangkat/options?cpns=1` (CPNS) / `?cpns=2` (PNS), label = `gol_ruang`; nilai `cpns` lain → 422 "Filter Jenis Pangkat tidak valid.". Daftar admin menerima filter yang sama.
- `gol-pppk`: status 1/2/10 (legacy ENUM('1','2') tidak bisa menyimpan 10); `uang_makan` = tarif uang makan PPPK per hari, dapat diubah role 1.
- `jenjang-pendidikan`: singkatan duplikat (case-insensitive, termasuk entri tidak aktif/dihapus) → 422 pada `jenjang_pendidikan_singkat`; `row_jurusan` hanya salah satu dari 7 kode (peka huruf; CHECK DB sebagai lapis kedua). Kolom `bobot_ipasn` tidak ada di form/meta/respons mana pun dan tidak bisa diubah lewat API.
- `jurusan-pendidikan`: minimal satu flag jenjang bernilai 1, diperiksa saat tambah dan saat ubah yang menyentuh flag (flag terkini dibaca dengan kunci baris, jadi dua ubah bersamaan tidak bisa mematikan semua flag) → 422 `D_I` "Pilih minimal satu jenjang pendidikan.". **Dropdown berjenjang pendidikan:** `bidang-pendidikan/options` → `jurusan-pendidikan/options?parent={id_bidang_pendidikan}`; dropdown jurusan hanya memuat jurusan aktif yang bidangnya aktif (`statusChain`). Dropdown jurusan per jenjang (flag menurut `row_jurusan`) belum ada — dikerjakan bersama riwayat pendidikan (Fase 3).

Master yang sudah ada di engine (tabel di atas): jabatan/unit/satker (G-02 ⏳, sebagian; rumpun, sub rumpun, jabatan akademik, periode struktur = DBV-018/CR-032 ⏳), kenaikan pangkat (G-04), pendidikan (G-05), diklat/hukdis/konket/tanda jasa (G-06), data umum & wilayah termasuk kantor dan kursem (G-07), jenis libur (G-08; hari libur sendiri lewat endpoint khusus `api/v1/hari-libur`, bawah), dan FAQ (G-10). Web config (G-09) bukan master engine: endpoint khusus `api/v1/web-config` (bagian "Web Config" di bawah). Master lain (peta jabatan, struktur jabatan, dan jabatan koordinasi G-02 — tabelnya dibuat DBV-018 tetapi butuh halaman khusus; lokasi presensi G-03) menyusul setelah DDL legacy tersedia dan skemanya disetujui DB Validator — lihat `backend/docs/progress/02-MasterData.md`.

G-02 ⏳ = DBV-008/CR-026, **menunggu review DB Validator** dan review kode (`backend/docs/db-review/G-02-jabatan-unit-satker-schema.md` Bagian 2.9). **Dropdown berjenjang G-02 (UL_ALL):** `unit/options` → `satker/options?parent={id_unit}` (satker aktif yang unitnya aktif); `group-jabatan/options` → `sub-group-jabatan/options?parent={id_group_jabatan}` (sub group aktif yang group-nya aktif); `jabatan/options?id_sub_group_jabatan=&id_satker=` (legacy `list_jabatan_sub_satker`; tanpa urutan, diurutkan nama); `kelas-jabatan/options` (`id` = `nama` = nomor kelas, urut numerik). Tanpa parameter `restrict` untuk Admin Satker (usulan G-02 Bagian 8, belum diputuskan).

**Dropdown berjenjang wilayah 4 level:** `provinsi/options` → `kabupaten-kota/options?parent={id_provinsi}` → `kecamatan/options?parent={id_kabupaten_kota}` → `kelurahan/options?parent={id_kecamatan}`.

**Dropdown berjenjang hukuman disiplin:** `tingkat-hukdis/options` → `jenis-hukdis/options?parent={id_tingkat_hukdis}` (hanya jenis yang tingkatnya aktif, `statusChain`). Pelatihan per jenis: `diklat/options?jenis_diklat={1..5}`.

G-06 ✅ = DBV-005/CR-012, disetujui DB Validator & review kode, di main lewat PR #14 (merge `e602205`; `backend/docs/db-review/G-06-diklat-hukdis-konket-tanda-jasa-schema.md` Bagian 2.7): kolom `order` TINYINT (maks. 127 entri tampil per lingkup urutan → 422 `order`), kolom audit hanya `updated_at`/`updated_by`; baris ber-ID hard-coded legacy (konket 2/4/5/6, `old_id` 8/10/13, tanda jasa 26/27/28/44, diklat 8) tidak dikunci.

## Payload & response

### GET /master/{entity} (daftar)

`data: { items: [...], total, page, per_page }`. Parameter daftar diperiksa `App\Libraries\ListQuery` — sama persis dengan `/auth/users` (ISSUE-019/CR-016).

| Aturan | Perilaku |
|---|---|
| **Nilai tunggal** | Setiap parameter daftar (`search`, `status`, `parent`, `page`, `per_page`, dan field allowlist `filters`) hanya boleh berisi satu nilai teks/angka. Bentuk array atau objek — `?search[]=a`, `?status[]=1`, `?parent[]=31`, yang sah menurut PHP — → **422** `message: "Parameter daftar tidak valid."`, `errors: { <key>: ["Parameter ini hanya boleh berisi satu nilai teks atau angka."] }`. Beberapa key salah sekaligus dikumpulkan jadi **satu** respons yang memuat semuanya. Sebelumnya 500 (`Array to string conversion`) |
| **`page`** | Bilangan bulat **1..1.000.000**. Selain itu (0, negatif, bukan angka, atau nilai yang melewati jangkauan int seperti `?page=99999999999999999999`) → **422** `errors: { page: ["Halaman harus berupa angka bulat 1 sampai 1000000."] }`; sebelumnya nilai sebesar itu membuat offset `(page - 1) * per_page` meluap → 500. Tidak dikirim atau `?page=` kosong = halaman 1 |
| **Halaman di luar data** | Halaman **valid** yang melewati jumlah data **bukan** error: tetap **200** dengan `items: []`, sedangkan `total` tetap berisi jumlah seluruh baris yang cocok |
| **`per_page`** | **Dijepit** ke 1..100 (bawaan 20), tidak ditolak: `?per_page=9999` → 100, `?per_page=0` → 1 |
| **`search`** | Dipangkas spasi di awal/akhir. `%`, `_`, dan `!` dicari sebagai **teks biasa**, bukan wildcard LIKE (`!` = ESCAPE char Query Builder CI4) — `?search=%` mencari karakter `%` dan tidak lagi mencocokkan seluruh baris. Nilai kosong/hanya spasi = tidak menyaring |
| **Filter lain** | `status`, `parent`, dan field `filters` juga dipangkas spasi. Nilai yang tidak sah untuk field-nya tetap 422 "Filter X tidak valid." (lihat `filters` di tabel opsi master) |

### POST /master/{entity}
Contoh kelurahan: `{ "id_kelurahan": "3171010003", "id_kecamatan": "3171010", "kelurahan": "Petojo Utara", "kd_pos": "10130", "order": 2 }`
Contoh agama (kode otomatis): `{ "agama": "Kepercayaan" }`

| Field | Aturan |
|---|---|
| PK (`id_*`) | wilayah: wajib, **tepat N digit angka** (2/4/7/10), unik; master AUTO_INCREMENT: tidak dikirim (diabaikan). `options`/`meta` dicadangkan |
| induk (kalau ada) | wajib, harus ada **dan aktif — termasuk seluruh leluhurnya** (mis. kecamatan baru ditolak bila provinsi dari kabupatennya Tidak Aktif/Dihapus; artikel FAQ ditolak bila topik dari sub topiknya non-aktif). Berlaku saat tambah & pindah induk; ubah tanpa pindah induk tetap boleh. Id induk harus bentuk kanonik seperti `{kode}` di URL (induk AUTO_INCREMENT: `1`, bukan `01`/`1abc`/`1.0`) — selain itu 422 "`<Induk>` tidak ditemukan." (mis. "Topik FAQ tidak ditemukan.") |
| nama | wajib, ≤ panjang kolom; spasi dirapikan; **unik per induk (+ `status_pegawai` untuk jenis status), case-insensitive, termasuk entri tidak aktif & dihapus** — ditegakkan juga oleh UNIQUE index DB |
| kolom tambahan | sesuai tabel master di atas (`kd_area`, `kd_pos`, `status_pegawai`, `remark`, `content`). Batas byte (`max_bytes` di meta) dihitung dalam byte UTF-8, bukan karakter: 128 × `é` = 256 byte → 422 "Keterangan maksimal 255 byte.". Kolom opsional yang kosong setelah trim (spasi saja) atau `false` disimpan NULL. Nilai array/objek JSON di kolom mana pun (kode, induk, nama, kolom tambahan, `order`, `status`) → 422 "`<Label>` tidak valid." |
| `content` (faq-article) | HTML; disanitasi server dengan whitelist (p, br, strong, b, em, i, u, s, sub, sup, ul, ol, li, a[href\|title\|target], img[src\|alt\|width\|height], h2–h4, blockquote, pre, code, hr, table/thead/tbody/tr/th/td[colspan\|rowspan], span). Tanpa style/class/on*; URI http/https/mailto (+ tautan relatif); `img src` hanya URL absolut http/https; `target` hanya `_blank` + `rel="noopener noreferrer"` otomatis. Kosong setelah sanitasi → 422 "Isi artikel wajib diisi." |
| `order` | opsional, bilangan ≥1 = posisi sisip, dijepit ke 1..(jumlah entri tampil di lingkup urutannya + 1); entri baru langsung ditulis di posisi itu (tidak di-update lagi, jadi `updated_by` tabel ber-`created_by` tetap NULL) dan hanya saudaranya yang bergeser. Kosong (tidak dikirim, `""`, spasi saja, `false`) = paling akhir; pada ubah = urutan tidak berubah. Mode manual: disimpan apa adanya (1 .. batas `orderColumnType`), kosong = MAX+1 termasuk entri terhapus |
| `status` | opsional `'1'`/`'2'` (default `'1'`); `10` hanya lewat DELETE |

| Status | Kapan | Body |
|---|---|---|
| 201 | sukses | `data: { <kolom tabel>, order, status, parent_nama? }` |
| 422 | kode dipakai / nama duplikat / induk tidak ada atau tidak aktif / format salah | `errors: { <field>: ["..."] }` — duplikat nama menyebut kode entri yang sudah ada (dan saran aktifkan kembali / pulihkan kalau entri itu tidak aktif / dihapus) |
| 422 | master AUTO_INCREMENT yang PK-nya sudah di batas tipe kolom (mis. TINYINT 127): MySQL 8 InnoDB memberi 1062 pada PRIMARY (id 127 sudah terpakai) atau 1467 `Failed to read auto-increment value from storage engine` (counter di atas batas, mis. setelah `ALTER TABLE … AUTO_INCREMENT = 128`; CR-041), MariaDB 10.4 memberi 167 `Out of range value for column '<pk>'` (kedua keadaan) — semuanya diterjemahkan engine, pesan DB hanya di log; 167 pada kolom lain, serta 167/1467 di luar engine, tetap 500 | `message: "Kode <Master> sudah mencapai batas maksimal tipe kolom, sehingga entri baru tidak bisa ditambahkan. Hubungi admin database."` tanpa `errors` (CR-011, CR-041) |
| 409 | tulis lain di tabel master yang sama belum selesai dalam 10 detik (named lock, CR-020) — berlaku juga untuk `PUT`/`PATCH`/`DELETE` | `message: "Data <Master> sedang diubah pengguna lain. Coba lagi."` tanpa `errors`, tanpa tulis |

### PUT /master/{entity}/{kode}
Parsial; field yang dikirim wajib terisi. Pindah induk → entri ditaruh di akhir induk baru, urutan induk lama dirapikan. `order` (kalau dikirim) memindah posisi; untuk entri berstatus `10` ditolak 422 kecuali sekaligus dipulihkan (`status` 1/2). `status` yang dikirim wajib `1`/`2` (`null`/`''` → 422, bukan diam-diam diaktifkan).

### DELETE /master/{entity}/{kode}
`data: { deleted: true, soft_delete: true, item: {…, status:'10'} }`. Audit dicatat sebagai event `delete` (before/after). Relasi dari data lain tetap utuh; FK `ON DELETE RESTRICT` di DB menolak hard delete master yang direlasikan.

### GET /master/{entity}/options
`data: [ { "id": "3171010001", "nama": "Gambir", "parent": "3171010" }, … ]` — hanya `status=1`. Query hanya membaca kolom kode, nama, dan induk (kolom besar seperti isi artikel tidak ikut terbaca).

Parameter options **tidak** memakai `ListQuery` (daftar admin): tiap parameter diperiksa sendiri, `parent` lebih dulu lalu field `filters`, dan respons 422 memuat **satu** key pertama yang salah dengan `message` = pesan key itu.

| Query | Perilaku |
|---|---|
| **`parent`** | Kode induk **bentuk kanonik** persis seperti `{kode}` di URL (`1`, bukan `01`/`1abc`/`1.0`; **tidak** dipangkas spasi, jadi `1 ` ditolak — beda dengan daftar admin) → hanya anak induk itu; induk kanonik yang tidak ada → `data: []`. Tidak dikirim atau `?parent=` kosong = **tanpa filter** (seluruh entri aktif). Non-kanonik **atau berbentuk array/objek** — `?parent[]=3171`, `?parent[a]=3171`, `?parent[]=` — → **422** `message: "Filter <Induk> tidak valid."`, `errors: { parent: ["Filter <Induk> tidak valid."] }` (mis. `kecamatan/options` → "Filter Kabupaten/Kota tidak valid."), untuk semua role. Sebelum CR-024 bentuk array dibuang controller menjadi "tanpa induk", sehingga dropdown berjenjang diam-diam mengembalikan **200 berisi seluruh entri lintas induk** (F-OPT QAFUNC-003, ISSUE-019). Master tanpa induk mengabaikan `parent` dalam bentuk apa pun |
| **field `filters`** | Hanya field allowlist master (mis. `pangkat` `?cpns=`, `diklat` `?jenis_diklat=`); tidak dikirim atau kosong = tanpa filter. Nilai yang tidak sah untuk tipe field-nya, hanya spasi, atau berbentuk array/objek (`?cpns[]=1`, `?jenis_diklat[a]=1`) → **422** `errors: { <field>: ["Filter <Label> tidak valid."] }` |
| lainnya | Diabaikan (tidak ikut kunci cache) |

## FAQ untuk pegawai (G-10, ⏳ DBV-002/CR-003)

Kelola konten FAQ (role 1) = endpoint master generik di atas (`faq-topic`, `faq-sub-topic`, `faq-article`). Endpoint baca & rating berikut ada di `FaqController` (logika di `Libraries\MasterData\FaqService`), prefix `/api/v1/faq`:

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/faq` | **UL_ALL** (wajib login) | Pohon topik → sub topik → judul artikel |
| GET | `/faq?search=q` | UL_ALL | Pencarian judul & isi, maks 50 hasil |
| GET | `/faq/{id}` | UL_ALL | Detail artikel + artikel terkait + status rating aktor |
| POST | `/faq/{id}/rate` | **2, 6, 7** (Pegawai, PTT, PPPK = UL_PEGAWAI legacy) | Nilai artikel sekali, tidak bisa diubah/dihapus |

Tanpa token → 401; role lain di endpoint rating → `403 {status:'error', message:'Forbidden'}`. Tidak ada endpoint untuk `faq_related_article`.

**Dropdown master FAQ = role 1 saja** (`publicOptions: false`, CR-003): `master/faq-topic|faq-sub-topic|faq-article/options` hanya dipakai form admin, dan options generik hanya menyaring status baris itu sendiri (bukan seluruh rantai). Bila terbuka untuk semua role, judul sub topik/artikel yang disembunyikan aturan tampil di bawah (induk Tidak Aktif/Dihapus) tetap terbaca. Role 2–8 → 403.

**Aturan tampil:** hanya entri yang **seluruh** rantainya status `1` (topik, sub topik, artikel). Menonaktifkan/menghapus induk tidak mengubah status anak; turunannya hanya tersembunyi dan muncul lagi saat induk dipulihkan. Artikel/sub topik/topik yang tidak status 1, id yang tidak ada, atau id non-kanonik (`01`, `abc`) → 404 "Artikel FAQ tidak ditemukan.". Tidak di-cache: perubahan admin langsung terlihat (MTC-014). Semua `id` berupa bilangan bulat.

### GET /faq
`data: { topics: [ { id, nama, sub_topics: [ { id, nama, articles: [ { id, title } ] } ] } ] }` — urut topik `order`, nama; sub topik `order`, nama; artikel `order`, title. Topik/sub topik tanpa artikel aktif tetap tampil (daftar kosong).

### GET /faq?search=q
`data: { results: [ { id, title, topic: { id, nama }, sub_topic: { id, nama }, snippet } ], search }`
- `q` di-trim; kosong → sama dengan tanpa `search` (pohon). Lebih dari 100 karakter → 422 `search`. Bukan UTF-8 valid (mis. `?search=%C3`) → 422 `search` dari penjaga UTF-8 `ApiController` (lihat atas).
- `q` ≥ 3 karakter: `MATCH(title, content_stripped) AGAINST(? IN NATURAL LANGUAGE MODE)` (FULLTEXT legacy, parameter terikat), urut relevansi lalu id terbaru. Relevansi InnoDB memakai statistik jumlah baris tabel: tepat setelah tabel dibuat/diimpor statistiknya bisa masih 0 sehingga seluruh relevansi 0 dan urutan jatuh ke id terbaru, sampai statistik dihitung ulang (otomatis di latar, atau `ANALYZE TABLE faq_article` setelah impor). `q` < 3 karakter atau FULLTEXT tanpa hasil: fallback `title LIKE %q%` (`%`, `_`, `!` dicari sebagai karakter biasa), urut id terbaru.
- `snippet` = kalimat pertama `content_stripped` (sampai `.`/`!`/`?`/`:` yang diikuti spasi atau akhir teks), maks 200 karakter (dipotong 199 + "…").

### GET /faq/{id}
`data: { id, title, content, topic: { id, nama }, sub_topic: { id, nama }, updated_at, related: [ { id, title } ], rating: { can_rate, rated, rate } }`
- `content` = HTML yang sudah disanitasi saat tulis (FE tetap menyanitasi ulang dengan DOMPurify saat render).
- `updated_at` = `created_at` bila artikel belum pernah diubah.
- `related` = maks 5 artikel aktif lain di sub topik yang sama, id terbaru dulu (seperti legacy).
- `rating.can_rate` = role 2/6/7 **dan** belum menilai; `rated`/`rate` (`1`/`2`/`null`) = penilaian aktor.

### POST /faq/{id}/rate
Body `{ "rate": 1 | 2, "reason"?: string }` → **201** `data: { rated: true, rate }`.

| Field | Aturan |
|---|---|
| `rate` | wajib, `1` (Membantu) atau `2` (Kurang Membantu) — selain itu 422. Divalidasi sebelum cek artikel |
| `reason` | `rate` 2: wajib teks UTF-8 valid (byte non-UTF-8 hanya bisa lewat form-urlencoded dan ditolak penjaga UTF-8 `ApiController`), di-trim, tidak kosong, maks **255 byte** → 422 `reason`. `rate` 1: diabaikan, disimpan NULL |

| Status | Kapan |
|---|---|
| 201 | tersimpan: `nip` = NIP aktor (JWT `sub`), `created_by` = `id_pengguna` aktor, `created_at` = waktu UTC aplikasi; audit `audit_logs` event `create`, entity `faq_rate`, entity_id `"{id}:{nip}"` (fail-open) |
| 404 | artikel tidak tampil (rantai tidak aktif / tidak ada / id non-kanonik) |
| 422 | `rate`/`reason` tidak valid; sudah pernah menilai → `errors.rate: ["Artikel ini sudah Anda nilai."]` (termasuk balapan dua permintaan: pelanggaran PK diterjemahkan ke 422 yang sama) |
| 403 | role selain 2/6/7 |

FE: 4 alasan baku legacy (`views/hr/faq/detail.php:142-156`) + "Lainnya" (teks bebas); yang dikirim sebagai `reason` adalah teks alasannya.

## Kantor, kursem, hari libur (G-07/G-08, ✅ DBV-003/CR-010)

✅ = disetujui DB Validator (DBV-003) & review kode (CR-010), di main lewat PR #12 (merge `d1cf1b3`), dokumen `backend/docs/db-review/G-07-G-08-kantor-hari-libur-kursem-schema.md`. `order` agama/jenis-pegawai/jenis-status = `tinyint` dan wilayah = `int unsigned` (temuan QA CR-009, dicocokkan `MasterConfigSchemaTest`).

**Kantor (`KantorHooks`).** Engine memeriksa tiap kode wilayah (kanonik, ada, aktif bila berubah; LAIN-LAIN hanya lewat field ber-`allowSystem`) dan nama unik global. Hook menambah, urut provinsi → kelurahan, error pertama → 422 pada field-nya:
1. level di bawah LAIN-LAIN wajib LAIN-LAIN ("Kabupaten/Kota harus LAIN-LAIN bila Provinsi LAIN-LAIN.");
2. pasangan level yang keduanya riil harus satu rantai ("Kecamatan X tidak berada di bawah Kabupaten/Kota yang dipilih."); induk riil + anak LAIN-LAIN boleh;
3. `*_lain` wajib bila levelnya LAIN-LAIN ("Provinsi Lainnya wajib diisi bila Provinsi LAIN-LAIN."), dan dipaksa NULL bila bukan;
4. `kode_pos` opsional, tepat 5 digit; bila kelurahan riil punya `kd_pos`, wajib salah satunya ("Kode Pos harus salah satu kode pos kelurahan terpilih: 10110, 10120.").

Saat ubah, aturan hanya diperiksa ulang bila kolom wilayah/`*_lain`/`kode_pos` ikut berubah: data lama yang rantainya tidak konsisten tetap bisa diubah nama/alamatnya. Kantor: `created_by` saat tambah, `updated_by` saat ubah.

**Hari libur** — bukan master engine (unik = `tgl_mulai`, urut `tgl_mulai DESC`, tanpa `order`). `HariLiburController` + `Libraries\MasterData\HariLiburService`, prefix `/api/v1/hari-libur`:

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/hari-libur` | **1, 4, 5, 8** | Daftar, urut `tgl_mulai DESC`. Query: `tahun` (4 digit 1900-2100, rentang yang beririsan dengan tahun itu; selain itu 422), `search` (nama, ≤100 karakter, wildcard di-escape), `status` (`1`/`2`/`10`, **role 1 saja**; default tanpa `10`), `page` (dibatasi agar offset tidak meluap: halaman raksasa = kosong), `per_page` (≤100). Role 4/5/8 **hanya status 1** (`status` diabaikan) |
| GET | `/hari-libur/{id}` | 1, 4, 5, 8 | Detail; id non-kanonik → 404; role 4/5/8 + status ≠ 1 → 404 |
| POST | `/hari-libur` | 1 | Tambah → 201 |
| PUT | `/hari-libur/{id}` | 1 | Ubah parsial; `status` `1`/`2` juga memulihkan status 10 |
| PATCH | `/hari-libur/{id}/status` | 1 | `{ "status": "1"\|"2" }`; juga memulihkan status 10 |
| DELETE | `/hari-libur/{id}` | 1 | Soft delete → status 10, `{ deleted: true, soft_delete: true, item }` |

Baris: `{ id_libur, id_jenis_libur, jenis_libur (nama, LEFT JOIN — null bila tanpa jenis), tgl_mulai, tgl_akhir, nama_libur, keterangan, status, created_at, updated_at, updated_by }`; kolom audit `created_at`/`updated_at`/`updated_by` hanya untuk role 1 (role 4/5/8 tidak menerimanya); daftar `{ items, total, page, per_page }`. Role lain → 403; tanpa token → 401.

| Field | Aturan |
|---|---|
| `tgl_mulai`, `tgl_akhir` | wajib (ubah: bila dikirim), tepat `YYYY-MM-DD`, tanggal kalender yang ada, tahun 1900-2100; `tgl_akhir ≥ tgl_mulai` → selain itu 422 `tgl_akhir` "Tanggal selesai tidak boleh sebelum tanggal mulai." |
| `id_jenis_libur` | wajib (legacy NULL di DB, F5), kanonik, ada, dan aktif (hanya bila berubah) → 422 "Jenis Libur tidak ditemukan." / "Jenis Libur X sedang non-aktif." |
| `nama_libur` | wajib, ≤100 karakter, spasi dirapikan; **boleh sama** dengan tahun lain (tanpa UNIQUE nama) |
| `keterangan` | opsional, ≤65.535 byte; kosong = NULL |
| `status` | tambah: opsional `1`/`2` (default 1) |

- **Overlap (Paket A):** rentang inklusif tidak boleh beririsan dengan hari libur lain berstatus **apa pun** (1/2/10); bersebelahan boleh → 422 `tgl_mulai` "Rentang tanggal bentrok dengan hari libur "X" (mulai s.d. akhir)" + saran aktifkan/pulihkan bila entri itu tidak aktif/dihapus. Dicek hanya bila tanggal berubah.
- **Serialisasi tulis:** semua tulis berjalan di dalam named lock `GET_LOCK` (per database + prefix, batas tunggu 10 detik) + transaksi, sehingga dua tambah paralel tidak sama-sama lolos cek overlap. Lock tidak didapat → **409** "Data hari libur sedang diubah pengguna lain. Coba lagi." tanpa tulis.
- **Lapis DB:** UNIQUE `tgl_mulai` (1062) → 422 `tgl_mulai`; CHECK `chk_hari_libur_rentang` (3819 MySQL / 4025 MariaDB) → 422 `tgl_akhir` — diterjemahkan di service, bukan daftar global CR-007.
- `updated_by` diisi saat tambah **dan** ubah (legacy `sp_holiday`; tabel tanpa `created_by`); audit create/update/delete.
- **`HariLiburService::tanggalLibur(from, to)`** (tanpa endpoint): daftar tanggal `Y-m-d` unik & terurut dari hari libur **status 1** yang beririsan dengan [from, to], dipotong ke rentang itu. Satu-satunya sumber tanggal libur untuk presensi, tukin, uang makan, lama cuti, konket, dan LKH (Fase 5) — legacy membaca `hari_libur` tanpa filter.

## Web Config (G-09, ✅ DBV-006/CR-030 disetujui DB Validator 06-10-2026, di main lewat PR #19)

Key-value bertipe, **bukan** master engine (tanpa `order`/`status`). `WebConfigController` + `Libraries\MasterData\WebConfigService`, prefix `/api/v1/web-config`, **semua role 1** (Matriks Modul G `hr/master/web_config/*`). Tidak ada endpoint baca publik: modul lain membaca lewat `service('webConfigService')->value($key)` / `values()` (nilai bertipe, cache diinvalidasi setiap tulis). Katalog key + tipe: `Config\WebConfig`; skema & keputusan: `backend/docs/db-review/G-09-web-config-schema.md`.

| Method | Path | Keterangan |
|---|---|---|
| GET | `/web-config` | `{ items }`: seluruh key katalog (urut katalog) lalu key tak dikenal hasil impor (urut nama) |
| GET | `/web-config/{config_name}` | Satu key; tidak ada di katalog maupun tabel → 404 |
| PUT | `/web-config/{config_name}` | `{ config_value, remark? }` → upsert (201 bila baris baru, 200 bila ubah). Nilai dinormalisasi per tipe; kosong/tipe salah → 422 `errors.config_value`; key tak dikenal yang ada di tabel → 422 (read-only) |
| DELETE | `/web-config/{config_name}` | Hard delete baris → nilai kembali ke bawaan katalog; `{ deleted: true, item }`; tanpa baris → 404 |

`config_name` legacy boleh mengandung `/` (`TL1/PSW1`), jadi route memakai `(:any)`; FE meng-encode tiap segmen terpisah. Item: `{ config_name, id_web_config, config_value, remark, updated_at, updated_by, is_default, known, label, group, type, description, default, effective, valid, personal, constraints }` — `effective` = nilai tersimpan bila sah, selain itu bawaan.

## G-TC → bukti otomatis

| G-TC | Test |
|---|---|
| #1 Keunikan nama/kode | `tests/MasterData/MasterGenericTcTest::testCreateSucceedsAndDuplicateCodeOrNameIsRejected`, `testSameNameIsAllowedUnderDifferentParent`, `testJenisStatusNameIsUniquePerStatusPegawai`, `testKodeWilayahMustBeExactDigits` |
| #2 Soft-delete only | `testDeleteIsAlwaysSoftAndReferencedMasterCannotBeHardDeleted` (termasuk FK RESTRICT), `testDeletedEntriesAreHiddenByDefaultAndCanBeRestored` |
| #3 Toggle status → dropdown | `testStatusToggleIsReflectedInOptionsImmediately`, `testFourLevelCascadeOptions` |
| #4 Re-ordering | `testReorderShiftsOtherEntriesAndKeepsDropdownLogical`, `testMovingToAnotherParentAppendsAndRenumbersOldParent`, `testShiftedSiblingsKeepTheirAuditColumns` (saudara yang bergeser tidak di-stamp); `FaqTest::testCreateWithOrderInsertsAtFinalPositionWithoutUpdatingNewRow`, `testShiftingSiblingsDoesNotTouchTheirAuditColumns`; tulis paralel (CR-020/ISSUE-020): `tests/MasterData/MasterWriteLockTest` (lock sibuk → 409 tanpa tulis untuk semua tulis termasuk pulihkan, lock per tabel, lock selalu dilepas setelah 422/404/error DB, tolak di dalam transaksi pemanggil; tambah tanpa `order`, sisip `order`=1, pulihkan, dan pindah urutan lewat proses worker paralel → urutan rapat 1..n) |
| #5 RBAC | `tests/MasterData/RbacMasterEndpointsTest` (8 role × seluruh endpoint × seluruh master; options UL_ALL kecuali master FAQ = role 1) |
| #6 Audit log | `testAuditLogIsRecordedForCreateUpdateDeleteWithActor`, `testLegacyAuditColumnsAreFilledWithActor`, `testLeadingZeroCodeIsPreservedInRouteAndAudit` |
| Opsi engine CR-009 | `tests/MasterData/MasterEngineFeaturesTest` (master UJI `Tests\Support\Config\MasterDataUji`: urutan manual & batas `orderColumnType`, `orderScope`, filter allowlist + cache per filter, `uniqueFields` + balapan 1062 lewat koneksi DB kedua, batas int per tipe kolom, boolean, `statusChain` 2 & 5 level, ref + `dependsOn`/`checkDependsOn`, `parent` options kanonik + racun cache, kapasitas lingkup mode shift); `tests/unit/Libraries/MasterFieldTest`, `MasterRegistryTest` (validasi konfigurasi), `MasterOptionsCacheKeyTest` (kunci cache dropdown per induk+filter tidak bentrok) |
| Skema DBV-001 | `tests/MasterData/Batch1LegacySchemaTest` (kolom legacy, collation, UNIQUE, FK legacy, rollback, tolak jalan saat tabel berisi data) |
| DBV-003/CR-010 ✅ (G-07/G-08) | `tests/MasterData/LiburKantorKursemSchemaTest` (skema 5 tabel, CHECK portabel, constraint DB, rollback, `testWilayahSentinelRows`), `WilayahSentinelTest` (baris sistem di engine), `KantorTest` (`KantorHooks`), `HariLiburTest` (RBAC 1/4/5/8, validasi, overlap semua status, soft delete, balapan UNIQUE, CHECK, lock 409, `tanggalLibur()`), `MasterConfigSchemaTest` (`orderColumnType`/`columnType` vs DDL); `tests/unit/Libraries/HariLiburRulesTest`; G-TC generik di atas mencakup `kantor`, `bidang-kursem`, `instansi-kursem`, `jenis-libur` |
| Rantai induk & batas byte (DBV-002) | `MasterGenericTcTest::testWholeParentChainMustBeActive`, `testMaxBytesFieldCountsBytesNotCharacters`; `testLegacyAuditColumnsAreFilledWithActor` (juga `created_by`) |
| Skema DBV-002 (FAQ) | `tests/MasterData/FaqSchemaTest` (kolom & tipe, collation, UNIQUE, FULLTEXT, FK legacy RESTRICT + kolom induk FK, tanpa FK `faq_rate.nip`, rollback, `up()` gagal di tengah membersihkan tabel run itu) |
| Skema DBV-004 (G-04/G-05) | `tests/MasterData/PangkatPendidikanSchemaTest` (kolom & tipe, collation, 7 UNIQUE termasuk singkatan jenjang, CHECK `row_jurusan` biner (peka huruf & spasi di akhir), FK RESTRICT jurusan → bidang, `gol_pppk.status` menyimpan 10, tanpa UNIQUE(cpns, order), rollback & `up()` gagal per migration) |
| G-04/G-05 (DBV-004/CR-011) | `tests/MasterData/KenaikanPangkatTest` (urutan pangkat mode manual & batas 127, `order` kosong/array tidak menjadi level 0, MAX+1 termasuk pangkat terhapus, PK TINYINT habis → 422, `gol_ruang` unik, pilihan `cpns`/`gol`/`ruang`, filter `?cpns=` + cache, jenis KP tetap mode geser, status 10 & `uang_makan`/`keterangan` golongan PPPK (spasi = NULL, array → 422), kolom audit), `PendidikanTest` (singkatan unik + balapan 1062, `row_jurusan` pilihan tetap (spasi/`false` = NULL, array → 422), wajib/panjang maksimal & `extraSearch` per master, `bobot_ipasn` tidak diekspos/diubah, minimal satu flag jurusan (termasuk flag terkini terkunci), PK bidang TINYINT habis → 422, nama jurusan unik per bidang, rantai status bidang → jurusan, induk aktif, kolom audit tanpa `created_*`); keenam master juga masuk G-TC generik (`MasterGenericTcTest`, `RbacMasterEndpointsTest`) |
| FAQ baca & rating (G-10) | `tests/MasterData/FaqTest` (8 role, rantai status, pencarian FULLTEXT + fallback, urutan relevansi lalu id, batas 50 di kedua jalur, sanitasi, list tanpa `content`, `icon` tidak diekspos, options FAQ role 1, induk kanonik, UTF-8 tidak valid → 422, `created_by`/`updated_by`, `updated_at` detail, rating role 2/6/7 + audit, 403/404/422, balapan PK); `tests/unit/Libraries/HtmlSanitizerTest` |
| Skema DBV-005 (G-06) | `tests/MasterData/DiklatHukdisKonketSchemaTest` (kolom & tipe legacy `diklat` + [I], collation, 6 UNIQUE, FK legacy RESTRICT, 3 CHECK `chk_`, tanpa seed, batas PK TINYINT, rollback, `up()` gagal di tengah) |
| Perilaku G-06 (CR-012) | `tests/MasterData/DiklatHukdisKonketTest` (meta 5 master termasuk `id_max_length`, dropdown UL_ALL role 1–8, urutan & keunikan per jenis diklat + filter, `bobot_ipasn` tidak diekspos, statusChain & masa sanksi jenis hukdis (spasi/`false` = NULL), `old_id` wajib/unik/bisa diubah, `affect_tukin` 1/2, baris hard-coded tidak dikunci, kapasitas urutan 127, PK TINYINT `diklat` habis → 422, stamp audit hanya baris yang diedit; nilai yang ditolak NOT NULL/CHECK DB, termasuk JSON array/objek, → 422, bukan 500); FE `frontend/src/features/master-data/__tests__/MasterDataView.g06.spec.ts` (menu aksi baris ⋮ kelima master: urutan & label item baku, item urutan per jenis pelatihan/per tingkat/global, Status hanya badge) |
| Skema DBV-018 (G-02 sisa) ⏳ | `tests/MasterData/JabatanSisaSchemaTest` (8 tabel [K] D1, collation, 6 UNIQUE, 15 FK legacy RESTRICT termasuk `jabatan → jenjang_jf` dan FK ke diri sendiri, 4 CHECK `chk_`, tanpa seed, rollback, `up()` gagal di tengah) |
| Skema DBV-008 (G-02) ⏳ | `tests/MasterData/JabatanUnitSatkerSchemaTest` (kolom & tipe [K] D1, collation, 5 UNIQUE termasuk lingkup NULL, 6 FK legacy RESTRICT, tanpa FK `id_jenjang_jf`, 5 CHECK `chk_`, tanpa seed, rollback, `up()` gagal di tengah) |
| Perilaku G-02 (CR-026) ⏳ | `tests/MasterData/JabatanUnitSatkerTest` (meta 6 master, dropdown UL_ALL, satker unik per unit + `zonasi` 0–120 wajib + `statusChain` + `logo_uns` tidak diekspos, sub group unik per group + `need_satker` + pindah group ditolak bila dirujuk jabatan, kelas jabatan kode = nama 1–20 tidak bisa diubah, jabatan group → sub group + rujukan aktif + unik per (sub group, satker) termasuk satker kosong + filter options, soft delete master yang dirujuk + FK RESTRICT, `id_jenjang_jf` tidak dikelola); `tests/unit/Libraries/MasterRegistryTest` (`codeAsName`/`idRange`); FE `MasterDataView.g02.spec.ts` (menu ⋮, tanpa urutan untuk jabatan/kelas, form satker/kelas/jabatan) |
| #7 QA Lapis 1 (Figma) | **Belum bisa** — desain Figma belum ada (item terbuka 00-INDEX) |
| Penjaga UTF-8 & error data DB (CR-007) | `tests/feature/InvalidUtf8InputTest` (body form login, lupa sandi, create/update akun & master — update lewat `_method=PUT`; query string; field bersarang & key non-UTF-8; JSON tetap 400; segmen route non-UTF-8 → Router 400 envelope lewat handler global tanpa `Accept` JSON + lapis cadangan `_remap()`), `tests/feature/DatabaseDataErrorTest` (6 kode error data nyata di koneksi strict → 422 generik + log, tulis gagal di engine master di-rollback, error DB lain — 1062, 1146, lock wait, deadlock, SIGNAL, tanpa kode — tetap dilempar ke handler global/500 tanpa log "diterjemahkan ke 422"), `tests/unit/Config/ExceptionsTest` (deteksi request API walau `indexPage` terisi), `tests/unit/Libraries/ApiExceptionHandlerTest` (4xx framework → "Permintaan tidak valid.") |
| Kapasitas PK AUTO_INCREMENT (CR-011, CR-041) | `MasterGenericTcTest::testAutoIncrementCounterBeyondTinyintPkGives422ForEveryTinyintMaster` (counter 128 nyata di semua master ber-PK TINYINT yang dibaca dari DDL: MySQL 1467 / MariaDB 167 → 422 + tepat satu catatan log baru dengan kode error engine itu, isi tabel, urutan, audit, transaksi, dan named lock tidak berubah), `testMariaDbAutoIncrementOutOfRangeGives422ForEveryAutoIncrementMaster` (167 disimulasikan trigger); `tests/unit/Libraries/MasterAutoIncrementExhaustedTest` (tanpa DB: 1467/167/1062 PRIMARY → 422 + log, nama ganda menang, deadlock/lock wait/kode acak/167 kolom lain/1467 di luar tambah → 500); `KenaikanPangkatTest::testPangkatFullTinyintKeyGives422` (MySQL 1062 PRIMARY) |
