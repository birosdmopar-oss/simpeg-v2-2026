# Modul E — Halo Simpeg (Fase 8)

Controller REST API modul ini (`App\Controllers\Api\HaloSimpeg\*`). Semua controller extends `App\Controllers\Api\ApiController`,
route di-group `api/v1/...` dengan filter `jwt` + `role:*`. Chatbot "Vanya" punya README sendiri (`Chatbot/README.md`).

Dokumen ini = **kontrak BEKU** helpdesk untuk FE (B1 Worker Muse, MAKE-073). Acuan: PRD Fase 8 v1.3 §6.1, §6.1b, §7.2, §7.3a.
Bagian **master** beku di MAKE-070; bagian **tiket** ditambahkan MAKE-071. Perubahan sesudah beku = CR yang disepakati kedua
jalur lewat reviewer. Contoh respons sintetis: `backend/tests/_support/HaloSimpeg/*.json` (bentuknya diuji sama dengan respons
nyata oleh `MasterHaloSimpegTest::testBentukResponsSamaDenganFixture`; FE menyalinnya ke
`frontend/src/features/halo-simpeg/__tests__/fixtures/`, tanpa `*.php`).

## Konvensi

- Prefix `api/v1/halo-simpeg`, route di `Config/RoutesHaloSimpeg.php`. Role dicek filter route (tersapu `KontrakMatriksRoleTest`):
  role lain → **403** dari filter. Role 8 tidak punya akses (F8-04).
- Amplop v2:
  - sukses `{status: "success", data}`;
  - galat `{status: "error", message, code?, errors?}`. 422 validasi membawa `errors` per kolom `{field: [pesan]}`, dan `message` =
    galat pertama. `code` (snake_case) hanya untuk galat bisnis bernama.
- **Masukan** JSON atau multipart memakai nama legacy (`question_category`, `id_user`, `category[]`, `email`, `no_hp`, `name`,
  `contact`, `status`). **Respons** memakai nama Indonesia ringkas.
- Teks di-trim; panjang = jumlah karakter (code point) sesudah trim. Teks dikirim polos.
- Waktu ISO-8601 UTC berakhiran `Z` (`dibuat_pada`, `diubah_pada`), atau `null` bila kosong.
- **Status master: `0` Tidak Aktif, `1` Aktif** (berbeda dari chatbot 1/2). FE memetakan Aktifkan = 1, Nonaktifkan = 0.
- `Halaman<T> = {items: T[], total, page, per_page}`. `page` ≥ 1; `per_page` 1..100 (lebih dari 100 dipotong ke 100), bawaan 20
  (`Config\HaloSimpeg`). Parameter query berbentuk array → 422 "Parameter daftar tidak valid.".
- Urutan daftar master: `created_at` naik [K], pemutus seri `id` naik (DBV-028 N3).
- Semua tulis tercatat di `audit_logs` (AC-E02.6). Ubah status lewat `PATCH …/{id}/status` (K-A1); PUT tetap payload penuh dan
  boleh membawa `status` (tanpa `status` = nilai lama).

## Role per endpoint

| Endpoint | Role |
|---|---|
| `GET kategori/opsi`, `GET petugas/saya`, `GET kontak/aktif` | 1–7 |
| `GET/POST kategori`, `PUT/DELETE kategori/{id}`, `PATCH kategori/{id}/status` | 1 |
| `GET/POST petugas`, `PUT/DELETE petugas/{id}`, `PATCH petugas/{id}/status`, `GET petugas/kandidat` | 1 |
| `GET/POST kontak`, `PUT/DELETE kontak/{id}`, `PATCH kontak/{id}/status` | 1 |

Tidak ada `GET {jalur}/{id}`: form Edit memakai baris dari daftar.

## Tipe

| Tipe | Bentuk |
|---|---|
| `Kategori` | `{id, nama, status: 0\|1, jumlah_petugas, bisa_hapus, dibuat_pada, diubah_pada}`. `jumlah_petugas` = semua petugas (aktif dan nonaktif) yang ditugaskan; `bisa_hapus` = false bila dipakai tiket atau petugas |
| `KategoriOpsi` | `{id, nama}` |
| `KategoriPetugas` | `{id, nama, status: 0\|1}` |
| `Petugas` | `{id, id_user, label, email, no_hp: string\|null, status: 0\|1, kategori: KategoriPetugas[] (urut id), bisa_hapus, alasan_tidak_bisa_hapus: string\|null}` |
| `PetugasKandidat` | `{id_user, label}` |
| `PetugasSaya` | `{petugas: bool, aktif: bool, kategori: KategoriPetugas[] (urut id), bisa_ekspor: bool}` |
| `Kontak` | `{id, nama, kontak, email: string\|null, status: 0\|1, dibuat_pada, diubah_pada}` |
| `KontakAktif` | `{id, nama, kontak, email: string\|null}` |

**`label` akun** (PRD §7.2):
- akun role 1 → nama akun;
- akun lain yang punya data pegawai → `"NIP - nama bergelar - jabatan"` (pemisah legacy `LS:485-490`; tanpa jabatan → `"NIP - nama"`);
- selain itu → nama akun, atau username bila nama kosong.

## Kategori

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET kategori/opsi` | — | `KategoriOpsi[]`, status 1 saja (form tiket) |
| `GET kategori` | `status?` (0/1), `q?` (nama), `page`, `per_page` | `Halaman<Kategori>` |
| `POST kategori` | `{question_category, status?}` (bawaan 1) | 201 `Kategori` |
| `PUT kategori/{id}` | `{question_category, status?}` | 200 `Kategori` |
| `PATCH kategori/{id}/status` | `{status: 0\|1}` | 200 `Kategori & {peringatan: null}` |
| `DELETE kategori/{id}` | — | 200 `{id}` |

Aturan:
- `question_category` wajib, maks 250 karakter, unik (trim, huruf besar/kecil diabaikan) [K `LS:392-424`]. Keunikan dijaga
  aplikasi, tanpa UNIQUE DB (DBV-028 Q3).
- Hapus fisik [K]. Bila kategori dipakai tiket atau petugas → 422 `kategori_dipakai` (sarankan Nonaktifkan, B-12). Aturan ini
  sama dengan `bisa_hapus`.

## Petugas

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET petugas` | `status?` (0/1), `q?` (nama akun, username, nama pegawai, NIP, email), `page`, `per_page` | `Halaman<Petugas>` |
| `GET petugas/kandidat` | `search` (2..50 karakter) | `{items: PetugasKandidat[], peringatan: string\|null}` |
| `GET petugas/saya` | — | `PetugasSaya` (selalu 200) |
| `POST petugas` | `{id_user, category[], email, no_hp?, status?}` (status bawaan 1) | 201 `Petugas` |
| `PUT petugas/{id}` | `{id_user, category[], email, no_hp?, status?}` | 200 `Petugas` |
| `PATCH petugas/{id}/status` | `{status: 0\|1}` | 200 `Petugas & {peringatan: string\|null}` |
| `DELETE petugas/{id}` | — | 200 `{id}` |

**Kandidat** (F8-07):
- Yang termasuk kandidat:
  - akun role 1 aktif;
  - akun **aktif** milik pegawai `status = 1` di satker kepegawaian (`web_config.id_satker_kepegawaian`) [K `LS:468-491`; syarat
    akun aktif = V2].
- Urutan: akun role 1 dulu (urut nama), lalu pegawai (urut nama, NIP). Maksimal 20 hasil, tanpa duplikat akun.
- Petugas yang sudah terdaftar ikut tampil (ikut legacy). Menyimpannya ditolak 422 `petugas_duplikat`.
- Bila satker kepegawaian belum diatur, hanya akun role 1 yang muncul, ditambah `peringatan` = "Satker kepegawaian belum diatur di
  Web Config; hanya akun Super Admin yang dapat dipilih.".

**`petugas/saya`:**
- Bila akun bukan petugas → `{petugas: false, aktif: false, kategori: [], bisa_ekspor: false}`.
- `aktif` = `support_officer.status = 1` dan akun aktif (F8-06). `bisa_ekspor` = `aktif`.
- `id_support_officer` tidak dikirim.
- FE memakai endpoint ini untuk tab antrean dan panel "Perlu ditindak".

**Tulis:**
- `id_user` wajib dan harus kandidat; syarat kandidat diperiksa saat tambah atau saat `id_user` diganti. Satu akun hanya boleh
  satu petugas (422 `petugas_duplikat`).
- `category[]` minimal satu, semuanya harus ada.
- `email` wajib, berformat email, maks 150.
- `no_hp` opsional, maks 20; kosong → `null`.
- Set kategori diganti dalam satu transaksi: kategori yang dilepas dihapus, yang baru disisip.

**Hapus dan nonaktifkan (K-A4 (1'), Horii 16-11-2026):**
- Hapus ditolak 422 `petugas_terakhir` bila petugas itu petugas **terakhir** (aktif **atau** nonaktif) pada sebuah kategori yang
  masih punya tiket Open. Selain itu hapus fisik: relasi kategori dulu, lalu petugas.
- `bisa_hapus` / `alasan_tidak_bisa_hapus` dihitung dari aturan yang sama. Teks alasan = teks 422.
- Menonaktifkan petugas **aktif terakhir** (petugas lain di kategori itu tidak ada yang aktif) sebuah kategori ber-tiket Open tetap
  boleh → 200 + `peringatan` = "Petugas ini adalah petugas aktif terakhir pada kategori {nama, dipisah koma}. Tiket terbuka pada
  kategori tersebut tidak memiliki penjawab sampai petugas lain diaktifkan.". Selain itu `peringatan: null`.
- PUT yang mengeluarkan petugas terakhir dari sebuah kategori ber-tiket Open **tidak** dijaga aturan ini (lihat Selisih S8).

## Kontak

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET kontak/aktif` | — | `KontakAktif[]`, status 1 (role 1–7) |
| `GET kontak` | `status?` (0/1), `q?` (nama, nomor, email), `page`, `per_page` | `Halaman<Kontak>` (role 1; role 2–7 → 403) |
| `POST kontak` | `{name, contact, email?, status?}` (status bawaan 1) | 201 `Kontak` |
| `PUT kontak/{id}` | `{name, contact, email?, status?}` | 200 `Kontak` |
| `PATCH kontak/{id}/status` | `{status: 0\|1}` | 200 `Kontak & {peringatan: null}` |
| `DELETE kontak/{id}` | — | 200 `{id}` |

Aturan:
- `name` wajib, maks 150.
- `contact` wajib, maks 20, unik → 422 `kontak_duplikat`.
- `email` opsional, berformat email, maks 150; kosong → `null`.
- Hapus fisik.

## Status (`PATCH …/{id}/status`)

- `status` wajib `0` atau `1` (angka atau teks). Selain itu → 422 `errors.status` "Status tidak valid.".
- Status sama dengan nilai sekarang → 200 tanpa tulis dan tanpa audit (idempoten).
- Respons = baris master + `peringatan`. `peringatan` selalu `null` untuk kategori dan kontak.

## Galat

| Status | `code` | Teks |
|---|---|---|
| 404 | — | "Data tidak ditemukan." (id master tidak ada) |
| 422 | `kategori_duplikat` | "Kategori sudah terdaftar." (`errors.question_category`) |
| 422 | `kategori_dipakai` | "Kategori masih dipakai tiket atau petugas. Nonaktifkan kategori ini." |
| 422 | `petugas_duplikat` | "Akun ini sudah terdaftar sebagai petugas." (`errors.id_user`) |
| 422 | `petugas_terakhir` | "Petugas ini petugas terakhir pada kategori {nama, dipisah koma} yang masih memiliki tiket terbuka. Tambahkan petugas lain lebih dulu." |
| 422 | `kontak_duplikat` | "Nomor kontak sudah terdaftar." (`errors.contact`) |
| 422 | — | per kolom (di bawah) |

| Kolom | Teks |
|---|---|
| `question_category` | "Nama kategori wajib diisi." / "Nama kategori maksimal 250 karakter." |
| `status` | "Status tidak valid." |
| `id_user` | "Akun petugas wajib dipilih." / "Akun tidak memenuhi syarat petugas." |
| `category` | "Minimal satu kategori." / "Kategori tidak tersedia." |
| `email` (petugas) | "Email wajib diisi." / "Format email tidak valid." / "Email maksimal 150 karakter." |
| `email` (kontak) | "Format email tidak valid." / "Email maksimal 150 karakter." |
| `no_hp` | "Nomor HP maksimal 20 karakter." |
| `name` | "Nama wajib diisi." / "Nama maksimal 150 karakter." |
| `contact` | "Nomor kontak wajib diisi." / "Nomor kontak maksimal 20 karakter." |
| `search` | "Kata kunci minimal 2 karakter." / "Kata kunci maksimal 50 karakter." |
| `id` (path) | "ID tidak valid." |

## Fixture master (`backend/tests/_support/HaloSimpeg/`)

| Fixture | Respons |
|---|---|
| `kategori-daftar` | `GET kategori` |
| `kategori-opsi` | `GET kategori/opsi` |
| `petugas-daftar` | `GET petugas` (termasuk `bisa_hapus=false` + alasan) |
| `kandidat` | `GET petugas/kandidat` |
| `petugas-saya-aktif`, `petugas-saya-nonaktif`, `petugas-saya-bukan` | `GET petugas/saya` |
| `kontak-aktif` | `GET kontak/aktif` |
| `kontak-daftar` | `GET kontak` |
| `status-patch` | `PATCH kategori/{id}/status` (`peringatan: null`) |
| `status-patch-peringatan` | `PATCH petugas/{id}/status` (Nonaktifkan petugas aktif terakhir) |
| `galat-422-master`, `galat-422-kategori-duplikat`, `galat-422-petugas-terakhir`, `galat-404-master` | galat master |

## Selisih terhadap PRD v1.3 (dilaporkan ke reviewer sebelum beku)

- **S1:** `petugas/kandidat` → `{items, peringatan}`, bukan array polos (F8-07 meminta peringatan bila satker kosong).
- **S2:** filter `status`/`q` juga untuk daftar `kategori` dan `petugas` (aditif).
- **S3:** `per_page` > 100 dipotong ke 100 (perilaku `ListQuery`), bukan 422.
- **S4:** teks tambahan: "Kategori tidak tersedia." (`category[]`) dan "Kata kunci maksimal 50 karakter." (`search`).
- **S5:** kandidat mensyaratkan akun aktif [V2]; petugas terdaftar tidak disaring.
- **S6:** pemisah label " - " (legacy).
- **S7:** ganti set kategori = lepas + sisip selisih (setara hapus + sisip [K]).
- **S8:** PUT yang mengeluarkan petugas terakhir dari kategori ber-tiket Open tidak dijaga K-A4; menunggu keputusan reviewer.
- **S9:** tidak ada `GET {jalur}/{id}`.
- **S10:** fixture galat master tambahan; `petugas-saya` dipecah menjadi tiga berkas.
