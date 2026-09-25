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
| `orderMode` | `shift` (bawaan): `order` = posisi tampil 1..n, tambah/pindah menggeser entri lain, hapus merapatkan. `manual`: `order` = nilai bisnis (mis. level pangkat, DBV-004) — disimpan apa adanya, **tidak pernah** menggeser/menomori ulang entri lain (tambah, ubah, PATCH order, hapus, pulihkan), boleh kembar; kosong saat tambah = MAX+1 |
| `orderScope` | field **wajib** pembentuk lingkup urutan selain induk (mis. diklat per `jenis_diklat`, DBV-005), dan wajib ikut `filters` (daftar admin disaring per lingkup; tanpa itu daftar mencampur lingkup sehingga panah urutan FE salah hitung): nomor urut, penggeseran, MAX+1, dan pulihkan berlaku per nilai field itu; pindah nilai = ditaruh di akhir lingkup baru, lingkup lama dirapatkan. Daftar & dropdown diurutkan induk → field lingkup → `order` → nama |
| `orderColumnType` | tipe kolom `order` (`tinyint`, `smallint`, `int` [bawaan], … `+ unsigned`): batas nilai urutan mode manual (422 "Urutan maksimal 127.") dan batas MAX+1 otomatis di kedua mode, termasuk tambah dengan `order` di lingkup mode shift yang sudah penuh (dicek sebelum insert; 422 "Urutan … sudah mencapai batas maksimal …", bukan 500/terpotong) |
| `uniqueFields` | field selain nama yang ber-UNIQUE di DB: `['old_id']` (unik global) atau `['kode' => ['id_induk']]` (unik per lingkup). Dicek seperti nama (case-insensitive, termasuk entri tidak aktif/dihapus + saran pulihkan), nilai kosong/NULL tidak dibatasi; pelanggaran index (1062) saat balapan juga → 422 pada field itu |
| `filters` | allowlist field untuk filter `?field=nilai` di **options** dan **daftar admin** (mis. pangkat `?cpns=1`). Field lain di query diabaikan; nilai yang tidak sah untuk tipe field-nya (bukan pilihan select, bukan 0/1, bukan angka/kode, array) → 422 "Filter X tidak valid." Cache dropdown dipisah per filter |
| `statusChain` | options hanya memuat entri yang **seluruh** rantai induknya status 1 (pola U3 FAQ, mis. jurusan hilang saat bidangnya tidak aktif — DBV-004 E6). Status turunan tidak diubah; menulis leluhur meng-invalidate cache dropdown turunan ber-`statusChain`. `MasterService::whereActiveChain()` bisa dipakai service khusus untuk tampilan yang sama |
| `fields.*.type = int` + `columnType` / `min` / `max` | batas nilai = rentang tipe kolom (`tinyint` 0–127, `int unsigned` 0–4.294.967.295, bawaan `int` 0–2.147.483.647; min bawaan 0) dijepit batas eksplisit → 422 "X maksimal 127." (bukan 1264 → 500 di koneksi strict atau terpotong diam-diam). `decimal` memakai `min`/`max` eksplisit. Meta field mengirim `min`/`max` |
| `fields.*.type = boolean` | flag 1/0 (TINYINT NOT NULL DEFAULT 0, mis. D_I..S_3): menerima `'1'`/`'0'`/`1`/`0`/JSON `true`/`false`; lainnya 422 "X hanya boleh 1 (ya) atau 0 (tidak)."; tidak dikirim saat tambah = default DB. Form: checkbox |
| `fields.*.type = ref` + `entity` / `dependsOn` / `checkDependsOn` | rujukan ke master lain (dropdown `{entity}/options`). Kode wajib bentuk kanonik master rujukan (422 "X tidak ditemukan."), ada, dan aktif (hanya bila nilainya berubah, seperti induk E6). `dependsOn` = field ref lain di form yang menjadi induk entri rujukan (dropdown berjenjang, mis. kabupaten/kota ← provinsi): entri rujukan wajib berada di bawah nilai itu (422 "… tidak berada di bawah X yang dipilih." / "Pilih X terlebih dahulu."), diperiksa juga saat hanya `dependsOn`-nya yang berubah. `checkDependsOn: false` bila rantai diperiksa hook (mis. sentinel LAIN-LAIN kantor DBV-003): engine tidak memeriksa rantai maupun keberadaan nilai `dependsOn`, tetapi kanonik/ada/aktif tetap diperiksa. Meta field mengirim `entity`/`depends_on` |

Konfigurasi diperiksa saat registry dibangun (`MasterRegistry`): induk/entity ref terdaftar, `dependsOn` menunjuk field ref yang entity-nya induk master rujukan, field `orderScope`/`uniqueFields`/`filters` ada (filter bukan `search`/`status`/`parent`/`page`/`per_page`; `orderScope` = field wajib dan ikut `filters`), `statusChain` hanya untuk master berinduk, tanpa rantai induk melingkar → selain itu `LogicException`. Meta master mengirim `order_mode`, `order_scope`, `order_max` (mode manual), `filters`, `status_chain`.

**Blok per grup (CR-009).** `Config\MasterData` (entri & konstanta), `tests/_support/MasterDataTestTrait::masterFixtures()`, dan `tests/_support/Database/Seeds/MasterDataSeeder` (panggilan & method seed) punya blok bertanda `// --- DBV-003 ---` … `// --- /DBV-003 ---` (juga DBV-004, DBV-005). Setiap grup hanya menambah di dalam bloknya agar cabang paralel tidak konflik; urutan entri config = urutan fixture (dicek `RbacMasterEndpointsTest`). Daftar master ber-`publicOptions: false` di test RBAC dibaca dari config.

Skema mengikuti SIMPEG legacy (DBV-001, G-01 Bagian 8; FAQ: DBV-002, G-10): nama tabel & kolom legacy, status **`1` Aktif / `2` Tidak Aktif / `10` Dihapus**, kolom audit legacy (`created_at`, `updated_at`, `updated_by` = `id_pengguna` aktor; `agama` + `deleted_at`). Tabel yang punya `created_by` (FAQ) mengikuti legacy: tambah mengisi `created_by` dan membiarkan `updated_by` NULL, ubah mengisi `updated_by`; tabel tanpa `created_by` mengisi `updated_by` saat tambah maupun ubah.

Kode master (PK) **tidak bisa diubah** setelah dibuat. Dua bentuk:
- **kode diinput admin** (wilayah): string tepat 2/4/7/10 digit angka (`CHAR(N)`), tidak pernah di-cast ke int (`'09'` ≠ `'9'`);
- **AUTO_INCREMENT** (agama, jenis pegawai, jenis status, topik/sub topik/artikel FAQ): diberikan DB, kode dari input diabaikan.

`{kode}` di URL harus bentuk kanonik; selain itu 404 (bukan alias entri lain): AUTO_INCREMENT = bilangan bulat positif tanpa nol di depan (`6`, bukan `06`/`6abc`), kode wilayah = tepat N digit.

## Endpoint

`{entity}` = key master (tabel di bawah). `{kode}` = PK entri.

| Method | Path | Role | Keterangan |
|---|---|---|---|
| GET | `/master/meta` | 1 | Daftar master + metadata form/tabel (dipakai halaman Master Data FE) |
| GET | `/master/{entity}` | 1 | Daftar, urut induk → `order` → nama. Default **tanpa** status `10` (seperti legacy). Query: `search`, `status` (`1`/`2`/`10`), `parent` (id induk), `page`, `per_page` (≤100). Kolom `listExclude` tidak dikirim (mis. `content`/`content_stripped` artikel FAQ) |
| GET | `/master/{entity}/options` | **UL_ALL**; FAQ: **1** | Dropdown untuk modul lain: **hanya entri aktif** (master ber-`statusChain`: seluruh rantai induknya juga aktif), urut `order`. Query: `parent` + field allowlist opsi `filters` (mis. `?cpns=1`; field lain diabaikan, nilai tidak sah → 422). `parent` wajib bentuk kanonik kode induk (`1`, bukan `01`/`1abc`) → selain itu 422 `parent` "Filter <Induk> tidak valid." (MySQL meng-cast `1abc` ke 1); master tanpa induk mengabaikan `parent`. Di-cache per induk+filter (kunci tidak bisa bentrok antar kombinasi), invalidasi di setiap penulisan. Master ber-`publicOptions: false` (`faq-topic`, `faq-sub-topic`, `faq-article`) hanya role 1 — role lain 403 (lihat bagian FAQ) |
| POST | `/master/{entity}` | 1 | Tambah → 201 |
| GET | `/master/{entity}/{kode}` | 1 | Detail (+ `parent_nama` untuk master berinduk) |
| PUT | `/master/{entity}/{kode}` | 1 | Ubah parsial (nama, induk, order, status). Kode tidak ikut diubah |
| PATCH | `/master/{entity}/{kode}/status` | 1 | Toggle `{ "status": "1"\|"2" }`; juga **memulihkan** entri berstatus `10` (kosongkan `deleted_at`) |
| PATCH | `/master/{entity}/{kode}/order` | 1 | Pindah posisi `{ "order": n }` (1-based, per lingkup urutan = induk + `orderScope`, hanya entri yang tampil); entri lain bergeser. Mode urutan manual: `order` entri itu diganti menjadi `n` (maks sesuai `orderColumnType`), entri lain tidak berubah. Entri berstatus `10` → 422 (pulihkan dulu) |
| DELETE | `/master/{entity}/{kode}` | 1 | **Soft delete** → `status=10` (+ `deleted_at` bila ada). Tidak pernah hard delete |

**Kolom audit saat urutan bergeser:** hanya entri yang diedit (dipindah, diubah, dihapus, dipulihkan) yang di-stamp `updated_at`/`updated_by`. Entri lain yang sekadar bergeser karena itu (reorder, sisip, hapus, pindah induk) **tidak** berubah `updated_at`/`updated_by`-nya (legacy tidak me-renumber saudara), tetapi perubahan `order`-nya tetap tercatat di `audit_logs` (event `update`).

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
| G-10 ⏳ | `FaqController` | `faq-topic` | faq_topic | id_faq_topic (AUTO_INCREMENT) | faq_topic (≤255) | — | `remark` (opsional, ≤255 **byte**) |
| G-10 ⏳ | `FaqController` | `faq-sub-topic` | faq_sub_topic | id_faq_sub_topic (AUTO_INCREMENT) | faq_sub_topic (≤255) | `id_faq_topic` → faq-topic | `remark` (opsional, ≤255 byte) |
| G-10 ⏳ | `FaqController` | `faq-article` | faq_article | id_faq_article (AUTO_INCREMENT) | title (≤255, label "Judul Artikel") | `id_faq_sub_topic` → faq-sub-topic | `content` (tipe `html`, wajib, ≤1.000.000 byte, disanitasi server); `content_stripped` diisi server, bukan input. Daftar tidak mengirim `content`/`content_stripped`; pencarian daftar ikut mencari `content_stripped` |

G-10 ⏳ = pilot DBV-002/CR-003, menunggu approval DB Validator & review kode (`backend/docs/db-review/G-10-faq-schema.md`). Kolom `faq_topic.icon` ada di tabel tetapi belum dikelola dan **tidak diekspos** (D6): tidak ada di form/meta dan tidak dikirim di respons admin mana pun (daftar, detail, hasil tambah/ubah/status/urutan/hapus) lewat `hiddenColumns`; nilainya di DB tidak disentuh.

Master lain (jabatan, lokasi presensi, KP, pendidikan, diklat/hukdis/konket/tanda jasa, kantor, hari libur, web config) menyusul setelah skemanya disetujui DB Validator — lihat `backend/docs/progress/02-MasterData.md`.

**Dropdown berjenjang wilayah 4 level:** `provinsi/options` → `kabupaten-kota/options?parent={id_provinsi}` → `kecamatan/options?parent={id_kabupaten_kota}` → `kelurahan/options?parent={id_kecamatan}`.

## Payload & response

### POST /master/{entity}
Contoh kelurahan: `{ "id_kelurahan": "3171010003", "id_kecamatan": "3171010", "kelurahan": "Petojo Utara", "kd_pos": "10130", "order": 2 }`
Contoh agama (kode otomatis): `{ "agama": "Kepercayaan" }`

| Field | Aturan |
|---|---|
| PK (`id_*`) | wilayah: wajib, **tepat N digit angka** (2/4/7/10), unik; master AUTO_INCREMENT: tidak dikirim (diabaikan). `options`/`meta` dicadangkan |
| induk (kalau ada) | wajib, harus ada **dan aktif — termasuk seluruh leluhurnya** (mis. kecamatan baru ditolak bila provinsi dari kabupatennya Tidak Aktif/Dihapus; artikel FAQ ditolak bila topik dari sub topiknya non-aktif). Berlaku saat tambah & pindah induk; ubah tanpa pindah induk tetap boleh. Id induk harus bentuk kanonik seperti `{kode}` di URL (induk AUTO_INCREMENT: `1`, bukan `01`/`1abc`/`1.0`) — selain itu 422 "`<Induk>` tidak ditemukan." (mis. "Topik FAQ tidak ditemukan.") |
| nama | wajib, ≤ panjang kolom; spasi dirapikan; **unik per induk (+ `status_pegawai` untuk jenis status), case-insensitive, termasuk entri tidak aktif & dihapus** — ditegakkan juga oleh UNIQUE index DB |
| kolom tambahan | sesuai tabel master di atas (`kd_area`, `kd_pos`, `status_pegawai`, `remark`, `content`). Batas byte (`max_bytes` di meta) dihitung dalam byte UTF-8, bukan karakter: 128 × `é` = 256 byte → 422 "Keterangan maksimal 255 byte." |
| `content` (faq-article) | HTML; disanitasi server dengan whitelist (p, br, strong, b, em, i, u, s, sub, sup, ul, ol, li, a[href\|title\|target], img[src\|alt\|width\|height], h2–h4, blockquote, pre, code, hr, table/thead/tbody/tr/th/td[colspan\|rowspan], span). Tanpa style/class/on*; URI http/https/mailto (+ tautan relatif); `img src` hanya URL absolut http/https; `target` hanya `_blank` + `rel="noopener noreferrer"` otomatis. Kosong setelah sanitasi → 422 "Isi artikel wajib diisi." |
| `order` | opsional, bilangan ≥1 = posisi sisip, dijepit ke 1..(jumlah entri tampil di lingkup urutannya + 1); entri baru langsung ditulis di posisi itu (tidak di-update lagi, jadi `updated_by` tabel ber-`created_by` tetap NULL) dan hanya saudaranya yang bergeser. Kosong = paling akhir. Mode manual: disimpan apa adanya (≤ batas `orderColumnType`), kosong = MAX+1 |
| `status` | opsional `'1'`/`'2'` (default `'1'`); `10` hanya lewat DELETE |

| Status | Kapan | Body |
|---|---|---|
| 201 | sukses | `data: { <kolom tabel>, order, status, parent_nama? }` |
| 422 | kode dipakai / nama duplikat / induk tidak ada atau tidak aktif / format salah | `errors: { <field>: ["..."] }` — duplikat nama menyebut kode entri yang sudah ada (dan saran aktifkan kembali / pulihkan kalau entri itu tidak aktif / dihapus) |

### PUT /master/{entity}/{kode}
Parsial; field yang dikirim wajib terisi. Pindah induk → entri ditaruh di akhir induk baru, urutan induk lama dirapikan. `order` (kalau dikirim) memindah posisi; untuk entri berstatus `10` ditolak 422 kecuali sekaligus dipulihkan (`status` 1/2). `status` yang dikirim wajib `1`/`2` (`null`/`''` → 422, bukan diam-diam diaktifkan).

### DELETE /master/{entity}/{kode}
`data: { deleted: true, soft_delete: true, item: {…, status:'10'} }`. Audit dicatat sebagai event `delete` (before/after). Relasi dari data lain tetap utuh; FK `ON DELETE RESTRICT` di DB menolak hard delete master yang direlasikan.

### GET /master/{entity}/options
`data: [ { "id": "3171010001", "nama": "Gambir", "parent": "3171010" }, … ]` — hanya `status=1`. Query hanya membaca kolom kode, nama, dan induk (kolom besar seperti isi artikel tidak ikut terbaca).

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

## G-TC → bukti otomatis

| G-TC | Test |
|---|---|
| #1 Keunikan nama/kode | `tests/MasterData/MasterGenericTcTest::testCreateSucceedsAndDuplicateCodeOrNameIsRejected`, `testSameNameIsAllowedUnderDifferentParent`, `testJenisStatusNameIsUniquePerStatusPegawai`, `testKodeWilayahMustBeExactDigits` |
| #2 Soft-delete only | `testDeleteIsAlwaysSoftAndReferencedMasterCannotBeHardDeleted` (termasuk FK RESTRICT), `testDeletedEntriesAreHiddenByDefaultAndCanBeRestored` |
| #3 Toggle status → dropdown | `testStatusToggleIsReflectedInOptionsImmediately`, `testFourLevelCascadeOptions` |
| #4 Re-ordering | `testReorderShiftsOtherEntriesAndKeepsDropdownLogical`, `testMovingToAnotherParentAppendsAndRenumbersOldParent`, `testShiftedSiblingsKeepTheirAuditColumns` (saudara yang bergeser tidak di-stamp); `FaqTest::testCreateWithOrderInsertsAtFinalPositionWithoutUpdatingNewRow`, `testShiftingSiblingsDoesNotTouchTheirAuditColumns` |
| #5 RBAC | `tests/MasterData/RbacMasterEndpointsTest` (8 role × seluruh endpoint × seluruh master; options UL_ALL kecuali master FAQ = role 1) |
| #6 Audit log | `testAuditLogIsRecordedForCreateUpdateDeleteWithActor`, `testLegacyAuditColumnsAreFilledWithActor`, `testLeadingZeroCodeIsPreservedInRouteAndAudit` |
| Opsi engine CR-009 | `tests/MasterData/MasterEngineFeaturesTest` (master UJI `Tests\Support\Config\MasterDataUji`: urutan manual & batas `orderColumnType`, `orderScope`, filter allowlist + cache per filter, `uniqueFields` + balapan 1062 lewat koneksi DB kedua, batas int per tipe kolom, boolean, `statusChain` 2 & 5 level, ref + `dependsOn`/`checkDependsOn`, `parent` options kanonik + racun cache, kapasitas lingkup mode shift); `tests/unit/Libraries/MasterFieldTest`, `MasterRegistryTest` (validasi konfigurasi), `MasterOptionsCacheKeyTest` (kunci cache dropdown per induk+filter tidak bentrok) |
| Skema DBV-001 | `tests/MasterData/Batch1LegacySchemaTest` (kolom legacy, collation, UNIQUE, FK legacy, rollback, tolak jalan saat tabel berisi data) |
| Rantai induk & batas byte (DBV-002) | `MasterGenericTcTest::testWholeParentChainMustBeActive`, `testMaxBytesFieldCountsBytesNotCharacters`; `testLegacyAuditColumnsAreFilledWithActor` (juga `created_by`) |
| Skema DBV-002 (FAQ) | `tests/MasterData/FaqSchemaTest` (kolom & tipe, collation, UNIQUE, FULLTEXT, FK legacy RESTRICT + kolom induk FK, tanpa FK `faq_rate.nip`, rollback, `up()` gagal di tengah membersihkan tabel run itu) |
| FAQ baca & rating (G-10) | `tests/MasterData/FaqTest` (8 role, rantai status, pencarian FULLTEXT + fallback, urutan relevansi lalu id, batas 50 di kedua jalur, sanitasi, list tanpa `content`, `icon` tidak diekspos, options FAQ role 1, induk kanonik, UTF-8 tidak valid → 422, `created_by`/`updated_by`, `updated_at` detail, rating role 2/6/7 + audit, 403/404/422, balapan PK); `tests/unit/Libraries/HtmlSanitizerTest` |
| #7 QA Lapis 1 (Figma) | **Belum bisa** — desain Figma belum ada (item terbuka 00-INDEX) |
| Penjaga UTF-8 & error data DB (CR-007) | `tests/feature/InvalidUtf8InputTest` (body form login, lupa sandi, create/update akun & master — update lewat `_method=PUT`; query string; field bersarang & key non-UTF-8; JSON tetap 400; segmen route non-UTF-8 → Router 400 envelope lewat handler global tanpa `Accept` JSON + lapis cadangan `_remap()`), `tests/feature/DatabaseDataErrorTest` (6 kode error data nyata di koneksi strict → 422 generik + log, tulis gagal di engine master di-rollback, error DB lain — 1062, 1146, lock wait, deadlock, SIGNAL, tanpa kode — tetap dilempar ke handler global/500 tanpa log "diterjemahkan ke 422"), `tests/unit/Config/ExceptionsTest` (deteksi request API walau `indexPage` terisi), `tests/unit/Libraries/ApiExceptionHandlerTest` (4xx framework → "Permintaan tidak valid.") |
