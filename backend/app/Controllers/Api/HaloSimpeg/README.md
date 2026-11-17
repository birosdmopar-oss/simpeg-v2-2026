# Modul E — Halo Simpeg (Fase 8)

Controller REST API modul ini (`App\Controllers\Api\HaloSimpeg\*`). Semua controller extends `App\Controllers\Api\ApiController`,
route di-group `api/v1/...` dengan filter `jwt` + `role:*`. Chatbot "Vanya" punya README sendiri (`Chatbot/README.md`).

Dokumen ini = **kontrak BEKU** helpdesk untuk FE (B1 Worker Muse, MAKE-073). Acuan: PRD Fase 8 v1.3 §6.1, §6.1b, §7.2, §7.3a.
Bagian **master** beku di MAKE-070; bagian **tiket** ditambahkan MAKE-071. Perubahan sesudah beku = CR yang disepakati kedua
jalur lewat reviewer. Contoh respons sintetis: `backend/tests/_support/HaloSimpeg/*.json` (bentuk ke-17 fixture master diuji sama
dengan respons nyata oleh `MasterHaloSimpegTest` lewat `cocokFixtureHs`; FE menyalinnya ke
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
- `Halaman<T> = {items: T[], total, page, per_page}` (`Config\HaloSimpeg`, bawaan `per_page` 20):
  - `page` wajib bilangan bulat 1..1.000.000 bila dikirim, selain itu → 422 `errors.page`;
  - `per_page` > 100 → 100; `per_page` < 1 atau bukan angka → 1;
  - filter `status` selain `0`/`1` → 422 `errors.status`;
  - parameter berbentuk array → 422 "Parameter daftar tidak valid.";
  - `q`/`search` dicari sebagai teks biasa (`%` dan `_` bukan wildcard).
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
- akun lain yang punya data pegawai → `"NIP - nama bergelar - jabatan"` (pemisah dan nama bergelar legacy `LS:485-490`; tanpa
  jabatan → `"NIP - nama bergelar"`);
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
  sama dengan `bisa_hapus`, dicek di dalam transaksi berkunci; pemakaian yang muncul bersamaan (FK RESTRICT) → 422 yang sama.

## Petugas

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET petugas` | `status?` (0/1), `q?` (nama akun, username, nama pegawai, NIP, email), `page`, `per_page` | `Halaman<Petugas>` |
| `GET petugas/kandidat` | `search` (2..50 karakter) | `{items: PetugasKandidat[], peringatan: string\|null}` |
| `GET petugas/saya` | — | `PetugasSaya` (selalu 200) |
| `POST petugas` | `{id_user, category[], email, no_hp?, status?}` (status bawaan 1) | 201 `Petugas` |
| `PUT petugas/{id}` | `{id_user, category[], email, no_hp?, status?}` | 200 `Petugas & {peringatan: string\|null}` |
| `PATCH petugas/{id}/status` | `{status: 0\|1}` | 200 `Petugas & {peringatan: string\|null}` |
| `DELETE petugas/{id}` | — | 200 `{id}` |

**Kandidat** (F8-07):
- Yang termasuk kandidat:
  - akun role 1 aktif;
  - akun **aktif** ber-role **1–7** milik pegawai `status = 1` di satker kepegawaian (`web_config.id_satker_kepegawaian`)
    [K `LS:468-491`; akun aktif = PRD §7.3a; role 1–7 = S12].
- Urutan: akun role 1 dulu (urut nama), lalu pegawai (urut nama, NIP). Maksimal 20 hasil, tanpa duplikat akun.
- Petugas yang sudah terdaftar ikut tampil (ikut legacy). Menyimpannya ditolak 422 `petugas_duplikat`.
- Bila satker kepegawaian belum diatur, hanya akun role 1 yang muncul, ditambah `peringatan` = "Satker kepegawaian belum diatur di
  Web Config; hanya akun Super Admin yang dapat dipilih.".

**`petugas/saya`:**
- Bila akun bukan petugas → `{petugas: false, aktif: false, kategori: [], bisa_ekspor: false}`.
- `aktif` = `support_officer.status = 1`, akun aktif, dan akun ber-role 1–7 (F8-06, S12). `bisa_ekspor` = `aktif`.
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
- **PUT** yang membuat sebuah kategori ber-tiket Open kehilangan petugas aktif terakhirnya (kategori itu dilepas dari set, atau
  `status` diubah ke 0) tetap disimpan → 200 + `peringatan` dengan teks yang sama seperti Nonaktifkan (S8 (b), keputusan Horii
  17-11-2026). Selain itu `peringatan: null`. Hanya hapus permanen yang diblok.
- "Petugas aktif" pada aturan peringatan = `status = 1`, akun aktif, dan akun ber-role 1–7 (F8-06, S12). Petugas yang tidak aktif
  bukan penjawab, jadi perubahan atasnya tidak memberi peringatan, dan ia tidak dihitung sebagai "petugas aktif lain".
- Cek hapus dan peringatan dihitung di dalam transaksi sesudah baris petugas dan kategori terkait dikunci, sehingga dua admin yang
  bersamaan menghapus/menonaktifkan dua petugas terakhir tidak bisa sama-sama lolos.

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
| 422 | — | per kolom (di bawah), termasuk "Parameter daftar tidak valid." untuk parameter query berbentuk array |

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
| `page` (query) | "Halaman harus berupa angka bulat 1 sampai 1000000." |
| `status` (query) | "Status tidak valid." |
| kolom teks/id mana pun (masukan berbentuk array/objek/boolean) | "Isian harus berupa teks." |

## Fixture master (`backend/tests/_support/HaloSimpeg/`)

| Fixture | Respons |
|---|---|
| `kategori-daftar` | `GET kategori` |
| `kategori-opsi` | `GET kategori/opsi` |
| `petugas-daftar` | `GET petugas` (termasuk `bisa_hapus=false` + alasan) |
| `kandidat`, `kandidat-peringatan` | `GET petugas/kandidat` (tanpa / dengan `peringatan` satker kosong) |
| `petugas-saya-aktif`, `petugas-saya-nonaktif`, `petugas-saya-bukan` | `GET petugas/saya` |
| `kontak-aktif` | `GET kontak/aktif` |
| `kontak-daftar` | `GET kontak` |
| `status-patch` | `PATCH kategori/{id}/status` (`peringatan: null`) |
| `status-patch-peringatan` | `PATCH petugas/{id}/status` (Nonaktifkan petugas aktif terakhir) |
| `petugas-put-peringatan` | `PUT petugas/{id}` (melepas kategori aktif terakhir) |
| `galat-422-master`, `galat-422-kategori-duplikat`, `galat-422-petugas-terakhir`, `galat-404-master` | galat master |

## Selisih terhadap PRD v1.3 (diterima reviewer 17-11-2026; PRD diralat lewat CR-138)

| No | Selisih | Label |
|---|---|---|
| S1 | `GET petugas/kandidat` → `{items: PetugasKandidat[], peringatan}`, penyesuaian bentuk `PetugasKandidat` dari PRD §6.1b (array polos). Alasan: F8-07 meminta peringatan bila satker kepegawaian kosong (pola `PetugasLayananService`) | [V2] |
| S2 | Filter `status` + `q` juga untuk daftar `kategori` dan `petugas` (aditif). Pencarian NIP/email hanya di endpoint role 1, LIKE ter-escape | [V2] |
| S3 | Paginasi ikut `ListQuery` (sama dengan chatbot): `per_page` > 100 → 100, `per_page` < 1 atau bukan angka → 1; `page` bukan bulat 1..1.000.000 → 422 `errors.page`; filter `status` ≠ 0/1 → 422 `errors.status`; parameter berbentuk array → 422 (PRD §7.3a) | [V2] |
| S4 | Teks 422 tambahan: "Kategori tidak tersedia." (`category`), "Kata kunci maksimal 50 karakter." (`search`), "ID tidak valid." (`id`) | [V2] |
| S5 | Kandidat: akun aktif (sesuai PRD §7.3a "akun aktif"); akun role 1 dulu lalu pegawai, maks 20; petugas terdaftar tidak disaring [K] (simpan → 422 `petugas_duplikat`); syarat diperiksa hanya saat tambah/ganti `id_user`, sehingga petugas lama yang pegawainya pindah satker tetap tercatat sampai diedit | [V2] / [K] |
| S6 | Label `"NIP - nama bergelar - jabatan"`: pemisah `" - "` dan nama bergelar [K `LS:485-490`] (PRD menulis "–"); tanpa jabatan → `"NIP - nama bergelar"` | [K] |
| S7 | Ganti set kategori = lepas + sisip selisih dalam satu transaksi. `created_at` relasi lama tetap (legacy delete + insert), sehingga urutan petugas yang diurutkan `created_at` naik + `id` naik (N3) bisa sedikit berbeda dari legacy | [V2] |
| S8 | PUT petugas → `Petugas & {peringatan}` bila perubahan membuat kategori ber-tiket Open kehilangan petugas aktif terakhir (setara Nonaktifkan, K-A4 (1')); tidak 422 | [V2], keputusan Horii 17-11-2026 |
| S9 | Tidak ada `GET {jalur}/{id}` (FE memakai baris daftar); DELETE → 200 `{id}` | [V2] |
| S10 | Fixture tambahan: `kandidat-peringatan`, `petugas-put-peringatan`, `galat-422-master`, `galat-422-kategori-duplikat`, `galat-422-petugas-terakhir`, `galat-404-master`; `petugas-saya` dipecah tiga berkas | [V2] |
| S11 | `status` opsional pada POST master, bawaan 1 (legacy: wajib di form) | [V2] |
| S12 | Petugas/penjawab dibatasi akun ber-role 1–7: kandidat pegawai, syarat `id_user`, definisi petugas aktif (peringatan, `petugas/saya`). Role 8 tidak punya route helpdesk (F8-04); legacy menerima role apa pun [K `LS:476-484`] | [V2], keputusan reviewer M1 (dilaporkan ke Horii) |
