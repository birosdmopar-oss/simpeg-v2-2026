# Modul E — Halo Simpeg (Fase 8)

Controller REST API modul ini (`App\Controllers\Api\HaloSimpeg\*`). Semua controller extends `App\Controllers\Api\ApiController`,
route di-group `api/v1/...` dengan filter `jwt` + `role:*`. Chatbot "Vanya" punya README sendiri (`Chatbot/README.md`).

Dokumen ini = **kontrak BEKU** helpdesk untuk FE (B1 Worker Muse, MAKE-073). Acuan: PRD Fase 8 v1.3 §6.1, §6.1b, §7.2, §7.3a.
Bagian **master** beku di MAKE-070; bagian **tiket** (di bawah garis pemisah) beku di MAKE-071. Perubahan sesudah beku = CR yang disepakati kedua
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
  - `per_page` diambil sebagai bilangan bulat (cast PHP, mis. `12abc` → 12); < 1 → 1; > 100 → 100;
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
- `category[]` minimal satu, semuanya harus ada. Elemen yang bukan id bilangan bulat positif (termasuk array/objek) → "Kategori tidak
  tersedia." (bukan "Isian harus berupa teks.").
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
- Cek hapus dan peringatan dihitung di dalam transaksi sesudah baris petugas, relasi kategorinya, dan kategori terkait dikunci
  (semua pembacaan sebelum kunci terakhir = locking read, sehingga cek membaca data terbaru, bukan snapshot sebelum menunggu
  kunci). Dua admin yang bersamaan menghapus/menonaktifkan dua petugas terakhir tidak bisa sama-sama lolos.

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
| S3 | Paginasi ikut `ListQuery` (sama dengan chatbot): `per_page` diambil sebagai bilangan bulat (cast PHP), < 1 → 1, > 100 → 100; `page` bukan bulat 1..1.000.000 → 422 `errors.page`; filter `status` ≠ 0/1 → 422 `errors.status`; parameter berbentuk array → 422 (PRD §7.3a) | [V2] |
| S4 | Teks 422 tambahan: "Kategori tidak tersedia." (`category`), "Kata kunci maksimal 50 karakter." (`search`), "ID tidak valid." (`id`) | [V2] |
| S5 | Kandidat: akun aktif (sesuai PRD §7.3a "akun aktif"); akun role 1 dulu lalu pegawai, maks 20; petugas terdaftar tidak disaring [K] (simpan → 422 `petugas_duplikat`); syarat diperiksa hanya saat tambah/ganti `id_user`, sehingga petugas lama yang pegawainya pindah satker tetap tercatat sampai diedit | [V2] / [K] |
| S6 | Label `"NIP - nama bergelar - jabatan"`: pemisah `" - "` dan nama bergelar [K `LS:485-490`] (PRD menulis "–"); tanpa jabatan → `"NIP - nama bergelar"` | [K] |
| S7 | Ganti set kategori = lepas + sisip selisih dalam satu transaksi. `created_at` relasi lama tetap (legacy delete + insert), sehingga urutan petugas yang diurutkan `created_at` naik + `id` naik (N3) bisa sedikit berbeda dari legacy | [V2] |
| S8 | PUT petugas → `Petugas & {peringatan}` bila perubahan membuat kategori ber-tiket Open kehilangan petugas aktif terakhir (setara Nonaktifkan, K-A4 (1')); tidak 422 | [V2], keputusan Horii 17-11-2026 |
| S9 | Tidak ada `GET {jalur}/{id}` (FE memakai baris daftar); DELETE → 200 `{id}` | [V2] |
| S10 | Fixture tambahan: `kandidat-peringatan`, `petugas-put-peringatan`, `galat-422-master`, `galat-422-kategori-duplikat`, `galat-422-petugas-terakhir`, `galat-404-master`; `petugas-saya` dipecah tiga berkas | [V2] |
| S11 | `status` opsional pada POST master, bawaan 1 (legacy: wajib di form) | [V2] |
| S12 | Petugas/penjawab dibatasi akun ber-role 1–7: kandidat pegawai, syarat `id_user`, definisi petugas aktif (peringatan, `petugas/saya`). Role 8 tidak punya route helpdesk (F8-04); legacy menerima role apa pun [K `LS:476-484`] | [V2], keputusan reviewer M1 (dilaporkan ke Horii) |

---

# Bagian tiket (MAKE-071)

Acuan: PRD Fase 8 v1.3 §6.1b, §6.2, §7.3–§7.5, §7.8, F8-08..F8-11, F8-17, H8-16 (a1), D7 (a). Bentuk respons tiket diuji sama dengan
fixture `tiket-*.json` / `galat-*.json` oleh `KontrakFixtureTiketTest`.

## Role dan kewenangan objek

| Endpoint | Role route |
|---|---|
| `GET tiket`, `GET tiket/ringkasan`, `GET tiket/{id}`, `POST tiket/{id}/pesan`, `POST tiket/{id}/baca`, `POST tiket/{id}/tutup`, `DELETE tiket/{id}`, `GET tiket/{id}/pesan/{idPesan}/lampiran` | 1–7 |
| `POST tiket` | 2, 6, 7 (pegawai, F8-04) |

Kewenangan objek dihitung satu kelas, `AksesTiket` (BP8-2). Flag `bisa_*` = hasil aksi (diuji kesetaraan, AC-E04.14):

- **Pemilik** = pembuat tiket (`sender`).
- **Petugas tiket** = petugas **aktif** (status 1, akun aktif, role 1–7; F8-06, S12) yang memegang kategori tiket itu. Tiket
  berkategori NULL tidak punya petugas. Role 1 tidak mendapat akses tambahan (H8-16 (a1)).
- **Melihat** (daftar, detail, baca, lampiran) = pemilik atau petugas tiket. Selain itu **404** "Data percakapan tidak ditemukan."
  (tiket orang lain = tidak ditemukan).
- **Balas** (`bisa_balas`) = dapat melihat, kategori tidak NULL, Open, `allow_reply = 1`. Pemilik selalu boleh membalas tiketnya,
  termasuk bila ia petugas kategori lain (B-19).
- **Tutup** (`bisa_tutup`) = pemilik atau petugas tiket, tiket Open (F8-08).
- **Hapus** (`bisa_hapus`) = petugas tiket yang **bukan pemilik** (F8-09 harfiah, B-11; keputusan Horii 17-11-2026). Pemilik → 403 `bukan_pihak_berwenang`,
  termasuk bila ia petugas aktif kategori tiket itu; petugas lain di kategori yang sama tetap boleh menghapus.
- Cek tulis diulang di dalam transaksi sesudah baris tiket dikunci (FOR UPDATE), sehingga balas ↔ tutup ↔ hapus bersamaan aman.
- **Id akun tidak pernah dikirim** (`sender`, `msg_sender`, `closed_by` mentah tidak ada di respons).

## Tipe

| Tipe | Bentuk |
|---|---|
| `KategoriTiket` | `{id, nama}` |
| `LampiranRingkas` | `{nama, tipe}`; `nama` = `lampiran-{id_tiket}-{id_pesan}.{ext}` (= `Content-Disposition`), `tipe` = tipe MIME dari ekstensi; path tidak pernah dikirim. Lampiran lama berekstensi di luar 9 ekstensi → `tipe` = `application/octet-stream`; path tanpa ekstensi → `nama` = `lampiran-{id_tiket}-{id_pesan}` [V2] |
| `Lampiran` | `LampiranRingkas & {ukuran: null}` (ukuran tidak dihitung, selalu `null`) |
| `PesanTerakhir` | `{id_inbox_msg, waktu, potongan, pengirim_label, lampiran: LampiranRingkas\|null}`; `potongan` = 100 karakter pertama isi |
| `TiketBaris` | `{id, subjek, kategori: KategoriTiket\|null, status: 'open'\|'closed', dibuat_pada, pemilik_nama, sebagai_petugas, belum_dibaca, perlu_tindak, pesan_terakhir: PesanTerakhir\|null, bisa_balas, bisa_tutup, bisa_hapus}` |
| `TiketDetail` | `TiketBaris & {allow_reply: bool, snapshot: {nip: string, jabatan: string\|null, satker: string\|null}\|null, ditutup_oleh_label: string\|null, pengingat: {aktif: bool, jatuh_tempo: string\|null}\|null, pesan: Pesan[]}` |
| `Pesan` | `{id_inbox_msg, waktu, isi, pengirim_label, milik_saya, dari_pemilik, urutan_pengirim, lampiran: Lampiran\|null}` |
| `Ringkasan` | `{belum_dibaca_saya, belum_dibaca_petugas, perlu_tindak, badge, total}` |

Isi field:
- `sebagai_petugas` = baris dilihat dari sisi petugas: `true` di `peran=petugas`; di detail/tutup/balas `true` bila peninjau petugas
  tiket dan bukan pemilik.
- `belum_dibaca` menurut sisi itu: sisi pemilik `is_read = 2`, sisi petugas `is_read_off = 2`.
- `perlu_tindak` = sisi petugas saja, ekspresi SQL yang sama dengan `Ringkasan.perlu_tindak`: Open AND (`is_read_off = 2` OR pengingat
  24 jam jatuh tempo). Selalu `false` di sisi pemilik.
- `snapshot` dan `pengingat` hanya untuk petugas tiket yang bukan pemilik [K `VD:21-26`]; selain itu `null`. `snapshot.jabatan` /
  `snapshot.satker` boleh `null` (pelapor tanpa snapshot jabatan) [K `LS:1543-1545`].
- `pesan` urut `waktu` naik lalu `id_inbox_msg` naik. `urutan_pengirim` 0 = pemilik, petugas 1..n menurut kemunculan pertama (B-23).
- `milik_saya` = pesan ditulis peninjau; `dari_pemilik` = pesan ditulis pemilik tiket.

**Penyamaran nama pengirim (D7 (a), ikut legacy per layar):**

| Layar | `pengirim_label` |
|---|---|
| Daftar (`pesan_terakhir`) | nama pengirim bila pengirim = pemilik; selain itu **"Admin" bagi semua peninjau**, termasuk role 1 [K `VI:110-117`] |
| Detail (`pesan[]`) | nama pengirim bila pengirim = pemilik **atau** peninjau role 1; selain itu "Admin" [K `VD:48-55`] |
| `ditutup_oleh_label` | [V2]: `null` bila Open **atau `closed_by` kosong** (tiket Closed hasil impor legacy); nama pemilik bila penutup = pemilik; nama penutup bila peninjau role 1; selain itu "Admin". FE: `null` pada tiket Closed = tampilkan status Closed tanpa nama penutup |

Nama pengirim pesan baru = nama bergelar bila akun punya data pegawai (B-28), selain itu nama akun/username.

## Endpoint

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET tiket` | `peran?` (`saya` bawaan \| `petugas`), `status?` (`open`\|`closed`), `kategori?` (id), `q?`, `baca?` (`semua` bawaan \| `belum` \| `sudah`), `perlu_tindak?` (`1`, hanya dengan `peran=petugas`), `page`, `per_page` | `Halaman<TiketBaris>` |
| `GET tiket/ringkasan` | — | `Ringkasan` |
| `GET tiket/{id}` | — | `TiketDetail` (tanpa efek samping) |
| `POST tiket` | multipart/JSON `{id_question_category, subject, msg, arsip?}` | 201 `TiketDetail` + `message` "Berhasil menambahkan data halo simpeg." [K] (CR-143, aditif) |
| `POST tiket/{id}/pesan` | multipart/JSON `{msg, arsip?, ingatkan_24_jam?}` | 201 `{pesan: Pesan, tiket: TiketBaris}` |
| `POST tiket/{id}/baca` | — | 200 `Ringkasan` |
| `POST tiket/{id}/tutup` | — | 200 `TiketBaris` |
| `DELETE tiket/{id}` | — | 200 `{id}` |
| `GET tiket/{id}/pesan/{idPesan}/lampiran` | — | berkas (bukan JSON) |

**Daftar:**
- `peran=saya` = tiket milik sendiri, termasuk berkategori NULL. `peran=petugas` = tiket kategori yang dipegang sebagai petugas aktif,
  **tanpa** tiket milik sendiri (sama dengan sisi petugas `Ringkasan`). Bukan petugas / nonaktif → 200 `Halaman` kosong (AC-E04.5).
- `q` = subjek atau isi pesan mana pun, dicari sebagai teks biasa (`%`/`_` bukan wildcard).
- `baca` menurut sisi daftar (`saya`: `is_read`, `petugas`: `is_read_off`).
- `perlu_tindak=1`: `total` = `Ringkasan.perlu_tindak` (AC-E04.13).
- **Urutan:** waktu pesan terakhir turun, NULL (tiket tanpa pesan) di akhir, lalu `id` turun. Pesan terakhir = `created_at` turun,
  `id_inbox_msg` turun, satu subkueri (tanpa N+1).
- Paginasi dan galat query mengikuti konvensi master (`ListQuery`).

**Buat tiket (E-03):**
- Pelapor wajib akun ber-NIP yang terdaftar di `pegawai` [K `SC:612-617`]; selain itu 422 "Data pegawai tidak ditemukan didalam sistem.".
- Throttle **5 per 10 menit** per akun, dicek sebelum validasi dan unggah: lewat batas → 429 tanpa baris dan tanpa berkas.
- `id_question_category` kategori **aktif**; `subject` wajib ≤ 250; `msg` wajib ≤ 5.000 (karakter sesudah trim **Unicode**, CR-148); `arsip` opsional.
- Kategori aktif dicek ulang di dalam transaksi di bawah kunci bersama (`LOCK IN SHARE MODE`) sebelum INSERT; kategori yang
  dinonaktifkan/dihapus di sela itu (termasuk FK 1452) → 422 `errors.id_question_category` "Kategori tidak tersedia.".
- Tersimpan: `status = 0`, `allow_reply = 1`, `is_read = 1`, `is_read_off = 2`, `notify_pic = 2`, `receiver = 0`, `sender_name` =
  nama bergelar, `nip`, snapshot `jabatan`/`satker` jabatan aktif (AC-E03.8) + pesan pertama, dalam satu transaksi. Tidak menulis
  `inbox`/`inbox_msg` (BP8-1, AC-E03.2).

**Balas (E-04):**
- Urutan cek: akses (404) → kategori NULL (422 `tiket_tanpa_kategori`) → Closed (422 `tiket_ditutup`) → `allow_reply = 0`
  (422 `tiket_tanpa_balasan`) → throttle **20 per menit** per akun (satu kunci untuk semua tiket) → isian. Balasan yang pasti ditolak
  tidak memakan kuota.
- `ingatkan_24_jam`: kosong/tidak dikirim = 0; `0`/`1`; nilai lain → 422 "Nilai pengingat tidak valid."; nilai 1 hanya untuk
  petugas tiket yang bukan pemilik, selain itu 422 "Pengingat 24 jam hanya dapat diatur petugas." (F8-11).

**Penanda baca (§6.2):**

| Kejadian | Hasil | Label |
|---|---|---|
| `POST baca` oleh pemilik | `is_read = 1` saja (juga bila ia petugas kategori itu) | [K `LS:907-912`] |
| `POST baca` oleh petugas tiket bukan pemilik | `is_read_off = 1` | [K `LS:914-918`] |
| Baca berulang | idempoten: tanpa tulis/audit bila sudah 1 | [V2] |
| Balasan petugas | `is_read_off = 1` [K `LS:1344-1347`], `is_read = 2` **[I]**; pengingat: 1 → `notify_pic = 1`, `notify_at` = +24 jam UTC; 0 → `notify_pic = 2`, `notify_at = NULL` [K] | H8-10 |
| Balasan pemilik | `is_read = 1` **[I]**, `is_read_off = 2` **[I]** | H8-10 |

Label **[I]** = sisi lawan dan sisi pembalas pemilik tidak bisa dicocokkan ke trigger legacy (keputusan Horii 17-11-2026, H8-10 tetap
[I]); diuji per skenario AC-E04.4. GET tidak pernah menandai baca (AC-E04.8).

**Tutup (E-05):** `status = 1`, `allow_reply = 0`, `is_read = 1`, `is_read_off = 1` [K `LS:1444-1449`] + `closed_by` [V2]. Sudah Closed →
422 `tiket_sudah_ditutup`. Tanpa buka kembali.

**Hapus (E-05):**
- Pesan lalu tiket dihapus fisik dalam satu transaksi, dengan audit `before` (FK RESTRICT; legacy CASCADE).
- Setiap path lampiran dicatat `PenjagaBerkas` di dalam transaksi lalu direkonsiliasi sesudah commit (Q-A0-6 (a')): berkas dihapus
  hanya bila tidak dirujuk baris lain. Path legacy yang ditolak aturan tulis adapter (mis. nama berspasi) tidak dihapus fisik dan
  dicatat log `info` tanpa path.

**Tiket berkategori NULL (H8-16 (a1)):** tampil di daftar pemilik; detail dan baca oleh pemilik; `bisa_balas = false`,
`bisa_tutup = true` (Open), `bisa_hapus = false`; balas → 422 `tiket_tanpa_kategori`; hapus → 403 `bukan_pihak_berwenang`; tidak
terlihat petugas mana pun (404) dan tidak dihitung di sisi petugas.

**Lampiran:**
- Unggah `arsip`: 9 ekstensi (jpg, jpeg, png, gif, pdf, doc, docx, xls, xlsx), ≤ 5 MB, isi cocok ekstensi (`ValidasiBerkas`). Galat
  memakai teks `ValidasiBerkas` di `errors.arsip` dan digabung dengan galat kolom lain dalam satu 422.
- Kunci baru `halo-simpeg/{YYYY}/{MM}/{32 hex}.{ext}` (K-A6); disimpan sebelum transaksi dan dihapus `PenjagaBerkas` bila transaksi
  gagal (AC-E03.6).
- Unduh lampiran lama (path legacy impor) dan baru lewat endpoint yang sama sesudah cek akses (AC-E04.7):
  - `Content-Type` dari ekstensi; `Content-Disposition` `inline` untuk pdf/jpg/jpeg/png/gif, selain itu `attachment`, dengan
    `filename="lampiran-{id}-{idPesan}.{ext}"`;
  - `X-Content-Type-Options: nosniff`, CSP `Config\Arsip::CSP_BERKAS`, `Cache-Control` `private, no-store`;
  - pesan tanpa lampiran, berkas tidak ada, atau path ditolak adapter → 404 "Lampiran tidak ditemukan.";
  - lampiran lama berekstensi di luar daftar → `Content-Type: application/octet-stream` + `attachment`; path tanpa ekstensi →
    `filename="lampiran-{id}-{idPesan}"` [V2].

## Ringkasan (§6.2)

| Bagian | Aturan |
|---|---|
| `belum_dibaca_saya` | tiket milik sendiri `is_read = 2` (termasuk kategori NULL) |
| `belum_dibaca_petugas` | hanya petugas aktif: kategori dipegang, `is_read_off = 2`, bukan milik sendiri |
| `perlu_tindak` | sisi petugas aktif, bukan milik sendiri: Open AND (`is_read_off = 2` OR (`notify_pic = 1` AND `notify_at` < sekarang UTC)) |
| `badge` | tiket distinct (belum dibaca di sisi mana pun ∪ `perlu_tindak`); bisa > `total` |
| `total` | `belum_dibaca_saya + belum_dibaca_petugas` = angka lonceng `tiket_belum_dibaca` (AC-E06.9); role 8 = 0 |

**Syarat DB Validator (DBV-028):**
- **Q6 (beku):** kueri **ringkasan** sisi petugas (`belum_dibaca_petugas`, `perlu_tindak`, `badge`) **wajib** menulis
  `status IN (0, 1)` eksplisit agar indeks `idx_dticket_kategori_status_baca` (kategori, status, is_read_off) terpakai sampai kolom
  ketiga (key_len 10). Ditegakkan `TiketEksplainTest` (EXPLAIN pada data sintetis). Daftar `peran=petugas` tanpa filter status tidak
  wajib menulisnya.
- **Q8 (c):** daftar petugas memakai subkueri pesan terakhir lewat `idx_dticketmsg_inbox_waktu` (diuji EXPLAIN). Ambang ukur
  `last_msg_at` = 100 ms daftar petugas / Q-H19 > 5.000 tiket per petugas, dinilai pada data gladi sebelum cutover (runbook MAKE-074),
  bukan pada CI.

## Galat tiket

| Status | `code` | Teks |
|---|---|---|
| 404 | — | "Data percakapan tidak ditemukan." (tiket tidak ada / tidak berhak melihat) |
| 404 | — | "Lampiran tidak ditemukan." |
| 403 | `bukan_pihak_berwenang` | "Anda tidak berwenang melakukan tindakan ini pada percakapan ini." |
| 422 | `tiket_ditutup` | "Percakapan ini telah di tutup oleh admin. Silahkan tambah pertanyaan baru untuk memulai percakapan baru." [K `LS:1117`] |
| 422 | `tiket_tanpa_balasan` | "Percakapan ini tidak memerlukan balasan. Silahkan tambah pertanyaan baru untuk memulai percakapan baru." [K `LS:1121`] |
| 422 | `tiket_sudah_ditutup` | "Percakapan ini telah ditutup sebelumnya." |
| 422 | `tiket_tanpa_kategori` | "Pertanyaan ini tidak memiliki kategori. Silakan tambah pertanyaan baru." |
| 422 | — | "Data pegawai tidak ditemukan didalam sistem." [K `SC:614`, ejaan legacy dipertahankan] |
| 429 | `throttle` | buat: "Terlalu banyak pertanyaan baru. Coba lagi dalam 10 menit."; balas: "Terlalu banyak pesan. Coba lagi dalam satu menit." |

| Kolom | Teks |
|---|---|
| `id_question_category` | "Kategori wajib dipilih." / "Kategori tidak tersedia." |
| `subject` | "Subjek wajib diisi." / "Subjek maksimal 250 karakter." |
| `msg` | "Pesan wajib diisi." / "Pesan maksimal 5.000 karakter." — buat tiket dan balasan. Wajib dan batas panjang dihitung sesudah trim Unicode (CR-148): spasi `\p{Z}` (NBSP, U+2000–U+200A, U+202F, U+205F, U+3000, …), spasi ASCII, dan lebar-nol U+180E, U+200B–U+200D, U+2060, U+FEFF di tepi tidak dihitung; isi spasi Unicode saja → "Pesan wajib diisi.". Isi yang disimpan tidak diubah |
| `arsip` | teks `ValidasiBerkas` (mis. "Jenis berkas tidak diizinkan (hanya jpg, jpeg, png, gif, pdf, doc, docx, xls, xlsx).", "Ukuran berkas melebihi 5 MB.", "Jenis isi berkas tidak sesuai dengan ekstensinya.") |
| `ingatkan_24_jam` | "Nilai pengingat tidak valid." / "Pengingat 24 jam hanya dapat diatur petugas." |
| `peran` / `status` / `kategori` / `baca` (query) | "Peran tidak valid." / "Status tidak valid." / "Kategori tidak valid." / "Filter baca tidak valid." |
| `perlu_tindak` (query) | "Filter perlu ditindak tidak valid." / "Filter perlu ditindak hanya untuk peran petugas." |
| kolom teks mana pun (array/objek/boolean) | "Isian harus berupa teks." |

## Fixture tiket (`backend/tests/_support/HaloSimpeg/`)

Nilai di fixture disamarkan (nama, NIP `000000000000000034`, waktu); yang dijamin sama dengan respons nyata hanya **bentuknya**
(kunci + tipe JSON), diuji `KontrakFixtureTiketTest`.

| Fixture | Respons |
|---|---|
| `tiket-buat` | `POST tiket` (dengan lampiran) |
| `tiket-balas` | `POST tiket/{id}/pesan` |
| `tiket-daftar-pemilik`, `tiket-daftar-petugas`, `tiket-daftar-role1` | `GET tiket` (sisi pemilik; sisi petugas; dilihat role 1 → "Admin" di `pesan_terakhir`) |
| `tiket-detail-pemilik`, `tiket-detail-petugas`, `tiket-detail-role1` | `GET tiket/{id}` (snapshot `null` / terisi; label D7 (a)) |
| `tiket-detail-tanpa-kategori` | tiket NULL dilihat pemilik |
| `tiket-detail-tanpa-jabatan` | dilihat petugas; `snapshot.jabatan`/`satker` = `null` |
| `tiket-detail-ditutup` | tiket Closed dengan `ditutup_oleh_label` |
| `tiket-ringkasan` | `Ringkasan` dengan `badge > total` (pengingat 24 jam jatuh tempo pada tiket yang sudah dibaca petugas) |
| `baca` | `Ringkasan` sesudah `POST baca` |
| `tiket-tutup`, `tiket-hapus` | tutup, hapus |
| `galat-422-tiket` (termasuk `errors.arsip`), `galat-422-ditutup`, `galat-422-tanpa-kategori`, `galat-403-bukan-pihak-berwenang`, `galat-404`, `galat-429` | galat tiket |

Fixture ekspor (`galat-403-bukan-petugas`, `galat-422-ekspor`, `ekspor-kosong`) milik MAKE-074.

## Selisih tiket terhadap PRD v1.3 (diterima reviewer 17-11-2026)

| No | Selisih | Label |
|---|---|---|
| T1 | `peran=petugas` tanpa tiket milik sendiri (selaras §6.2, AC-E04.13): petugas yang juga pemilik melihat tiketnya hanya di `peran=saya` | [V2] |
| T2 | Hapus = petugas tiket yang bukan pemilik; pemilik selalu 403, termasuk bila ia petugas kategori itu | [V2] F8-09 harfiah, keputusan Horii 17-11-2026 |
| T3 | `snapshot`/`pengingat` hanya petugas tiket bukan pemilik; role 1 tanpa akses tambahan | [K]/[V2] |
| T4 | `lampiran.tipe` = MIME dari ekstensi; `lampiran.ukuran` selalu `null` (FE tidak boleh bergantung pada ukuran) | [V2] |
| T5 | Galat berkas digabung ke `errors.arsip` dengan teks `ValidasiBerkas` | [V2] |
| T6 | Teks tambahan: pelapor tanpa pegawai [K `SC:614`, ejaan "didalam" apa adanya], lampiran 404, galat query (tabel galat) | [K]/[V2] |
| T7 | Cek balas (akses, NULL, Closed, `allow_reply`) sebelum throttle; diulang di dalam transaksi berkunci | [V2] |
| T8 | `ingatkan_24_jam` kosong = 0 | [V2] |
| T9 | Respons tulis: POST pesan 201 `{pesan, tiket}`, baca 200 `Ringkasan`, tutup 200 `TiketBaris`, DELETE 200 `{id}` | [V2] |
| T10 | Fixture tiket di tabel di atas; fixture ekspor di MAKE-074 | [V2] |
| T11 | `q` = subjek atau isi pesan | [V2] |
| T12 | Nama pengirim dan penutup = nama bergelar bila ada data pegawai | [V2] |
| T13 | Q8 (c) dicatat sebagai ambang gladi, tidak diukur waktunya di CI | [V2] |

---

# Bagian notifikasi push (MAKE-072)

Acuan: PRD Fase 8 v1.4 §6.4, §7.6, F8-13..F8-16, H8-14 (a). Tidak ada endpoint baru; notifikasi diantre `TicketNotifService`
**sesudah commit** tulis tiket. Gagal menentukan penerima atau mengantre hanya dicatat log, dan pesan/tiket tetap tersimpan
(AC-E06.3).

| Kejadian | Penerima |
|---|---|
| Tiket baru | petugas aktif kategori tiket |
| Balasan pemilik | petugas aktif kategori tiket |
| Balasan petugas | pemilik tiket |
| Tutup oleh pemilik | petugas aktif kategori tiket (F8-14) |
| Tutup oleh petugas | pemilik tiket (F8-14) |

- **Petugas aktif** = `support_officer.status = 1`, akun aktif, role 1–7 (F8-06, S12). Petugas nonaktif, akun nonaktif/terhapus, dan
  akun role 8 tidak menerima.
- **Pelaku dikecualikan**, termasuk petugas yang membuat atau membalas tiketnya sendiri.
- Tiket berkategori NULL tidak punya petugas penerima (H8-16 (a1)).
- Antrean: `AntrePushPesan::antreAkun(id_pengguna[])` → job `push-pesan` (CR-137), kanal `semua` (F8-15). Token web per akun
  (termasuk petugas role 1 tanpa NIP, AC-E06.7) dan token Android lewat NIP akun bila ada; token yang sama dikirim sekali; token
  tidak sah (`UNREGISTERED`) dihapus; job yang semua kirimannya gagal dicoba ulang terbatas (`PushPesanJob::TRIES` = 3). Catatan
  percobaan ke `integrasi_log` dilakukan adapter FCM (`KlienHttp`, layanan `fcm`).
- **Payload terbatas F8-16** (H8-8 belum ada; uji push real ke perangkat dicatat ke DEPLOY-001.1):
  - judul `"Halo Simpeg"`;
  - isi = teks pesan, spasi dirapatkan, maksimal 100 karakter + `…`; untuk tutup: `"Percakapan Halo Simpeg telah ditutup."`;
    bila potongan kosong: `"Ada pesan baru di Halo Simpeg."` (pengaman; sejak CR-148 pesan yang hanya berisi spasi Unicode ditolak
    422 di validasi `msg`, sehingga jalur ini tidak tercapai lewat endpoint);
  - `data = {modul: "halo-simpeg", id_inbox: "<id>", url: "/halo-simpeg/tiket/<id>", link: "/halo-simpeg/tiket/<id>"}`
    (`link` dibuka service worker web, `url` untuk klien lain);
  - tanpa NIP, jabatan, satker, token, atau isi pesan lengkap (AC-E06.5). Kunci kompatibilitas Android menunggu PN8-1.
- BP8-1: alur ini tidak menulis `inbox`/`inbox_msg` (AC-E06.6, §7.8 (2)); arah sebaliknya, alur approval Fase 4 tidak menulis
  `disiplin_ticket(_msg)` (§7.8 (3)), dibuktikan `NotifikasiTidakKeTicketingTest`.

## Selisih push terhadap PRD v1.4 (diterima reviewer 17-11-2026)

| No | Selisih | Label |
|---|---|---|
| U1 | `data.link` (= `url`) ditambahkan karena service worker web v2 membuka `data.link`; tetap tanpa data pribadi | [V2] |
| U2 | `url` = `/halo-simpeg/tiket/{id}`, cocok dengan rute FE `/halo-simpeg/tiket/:id(\d+)` (MAKE-073) | [V2] |
| U3 | Isi push tutup = teks tetap; isi tiket baru/balasan = potongan 100 karakter (F8-16, AC-E06.5) | [V2] |
| U4 | Tutup memberi notifikasi ke pihak lawan (pemilik menutup → petugas aktif; petugas menutup → pemilik) | [V2] F8-14 |
| U5 | Petugas penerima = petugas aktif versi S12 (status 1, akun aktif, role 1–7); legacy hanya `so.status` + `peng.status` [K B4] | [V2] |
| U6 | Idempotensi tanpa penanda baru: job dicoba ulang hanya bila semua kiriman gagal, jadi token yang sudah sukses tidak dikirimi ulang | [V2] |
| U7 | Catatan `integrasi_log` dibuat adapter FCM (`KlienHttp`, layanan `fcm`), bukan `TicketNotifService` | [V2] |
| U8 | Metode `PenerimaTokenFcm::webAkun` / `androidAkun` (PRD §6.4) + helper `nipAkun` | [V2] |
| U9 | Kanal bawaan `antreAkun` = `android` (sama dengan `antre`); Halo Simpeg selalu mengirim `semua` | [V2] |

---

# Bagian ekspor rekap petugas (MAKE-074)

Acuan: PRD Fase 8 v1.4 §7.9 (E-09), F8-20, §6.1b, §7.3a, AC-E09.1..AC-E09.8 [K `LS:1600-1830`, `SC:751-786`]. Sumber kontrak tombol
ekspor FE (B4, Worker Muse). Service: `App\Libraries\HaloSimpeg\EksporTiketService`. Bagian ini **beku** di MAKE-074 (V1–V13 diputus
18-11-2026); perubahan sesudah beku = CR yang disepakati BE dan FE.

`GET api/v1/halo-simpeg/tiket/ekspor?ds=YYYY-MM-DD&de=YYYY-MM-DD&format=xlsx` — role 1–7 (filter route; role 8 → 403 filter).

| Respons | Isi |
|---|---|
| 200 xlsx | `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment; filename="Rekap_Halo_Simpeg_{ds}_{de}.xlsx"`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`; diaudit |
| 200 JSON kosong | `{status: "success", data: {kosong: true, jumlah: 0}, message: "Tidak ada data Halo Simpeg pada periode ini."}`, `Content-Type: application/json`, **tanpa** `Content-Disposition`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`; **tidak** diaudit |
| 403 `bukan_petugas` | "Fitur ini hanya untuk petugas Halo Simpeg yang aktif." — bukan petugas atau petugas nonaktif (`bisa_ekspor = false`) |
| 422 per kolom | `errors.ds` / `errors.de` / `errors.format` (tabel di bawah); parameter berbentuk array → 422 `ListQuery` |
| 422 `ekspor_terlalu_besar` | "Data pada periode ini terlalu besar untuk diekspor. Persempit periode." — satu teks untuk batas baris dan karakter; tanpa berkas |

Urutan cek: 403 `bukan_petugas` → validasi periode/format (422) → hitung (kosong 200 / terlalu besar 422) → berkas + audit.

| Kolom | Aturan | Teks |
|---|---|---|
| `ds`, `de` | wajib, tepat `YYYY-MM-DD` dan ada di kalender (tahun 1900–2100); `ds` ≤ `de`; tanpa batas jumlah hari | kosong: "Tanggal awal dan akhir wajib diisi." (di kolom yang kosong); format: "Tanggal harus berformat YYYY-MM-DD dan ada di kalender."; `ds` > `de`: "Tanggal awal tidak boleh melebihi tanggal akhir." (di `errors.ds`) |
| `format` | kosong = `xlsx`; selain `xlsx` ditolak | "Format ekspor tidak didukung." |

**Cakupan dan isi berkas**
- Izin = `bisa_ekspor` dari `PetugasService::saya` (petugas aktif, berapa pun kategorinya; [V2] terhadap `VI:13`).
- Tiket = tiket di kategori yang dipegang petugas (`AksesTiket::kategoriAktif`), tanggal tiket (`disiplin_ticket.created_at`, UTC) di
  `[ds 00:00 WIB, de+1 00:00 WIB)` dibandingkan langsung dengan kolom (tanpa `DATE()`). Tiket berkategori NULL dan kategori lain tidak
  ikut (H8-16 (a1), AC-E09.3). Status tiket apa pun. Kategori nonaktif yang masih dipegang petugas tetap ikut.
- Baris = pesan tiket itu dalam cakupan V1 di bawah, urut `id_inbox` lalu `id_inbox_msg` [K urutan]. Tiket yang cocok periode tetap
  ikut walau pengekspor belum menulis (baris pesan pemilik saja; perbaikan F8-20). Tiket tanpa pesan sama sekali (anomali impor,
  hitungan Q-H16 di runbook `README-deploy.md` §1n) tidak menghasilkan baris (V6).
- **Cakupan pesan (V1, keputusan Horii 18-11-2026, opsi (a) ikut legacy penuh [K `LS:1626`]):** hanya pesan **pemilik tiket**
  (`msg_sender = sender`) dan pesan **petugas yang mengekspor** (`msg_sender` = akun pengekspor). Balasan petugas lain di kategori
  yang sama **tidak** ikut. Pengekspor role 1 mengikuti aturan yang sama. Batas baris dan karakter (COUNT/SUM) dihitung atas cakupan
  yang sama. Kode: `EksporTiketService::cakupan()`.
- **Label pengirim (V2, keputusan Horii 18-11-2026, opsi (a) [K `LS:1797-1799`]):** `NAMA PENANYA` / `NIP PENANYA` = nama/NIP
  pengirim pesan baris itu. Tanpa penyamaran D7 (a), karena hanya baris pemilik dan pengekspor sendiri yang tampil. Kode:
  `EksporTiketService::barisTerpilih()`.
- Berkas (`EksporTabel::xlsx`, semua sel teks `inlineStr`, anti formula injection), sheet "Rekap Halo SIMPEG":
  - baris 1 "REKAP PIC HALO SIMPEG {nama akun}", baris 2 "Periode: {ds} - {de}" (format "1 November 2026"), baris 3 kosong;
  - baris 4 header: `NO`, `ID PERTANYAAN`, `KATEGORI`, `NAMA PENANYA`, `NIP PENANYA`, `PERTANYAAN / JAWABAN`, `TIMESTAMP` [K];
  - `NAMA PENANYA` / `NIP PENANYA` = nama/NIP pengirim baris itu (V2); pengirim tanpa NIP (mis. akun role 1) → NIP kosong; NIP tetap teks
    (AC-E09.4);
  - `TIMESTAMP` = waktu pesan dalam WIB, `d/m/Y H:i` [K];
  - isi sel > 32.767 unit UTF-16 (batas sel Excel; emoji = 2 unit) dipotong dengan penanda "… [dipotong]" [V2] (B-27, pengecualian
    AC-E09.8). Penanda **termasuk** dalam 32.767, pasangan surrogate tidak terbelah (V10). Baris tidak pernah dibuang.

**Pengaman (§7.3a, R8-22)** — dihitung satu kueri agregat sebelum berkas dibangun. Sesudah data diambil, jumlah baris **dan**
karakter dicek ulang (pesan yang masuk di antara dua kueri, V12); lewat → 422 yang sama, tanpa berkas:

| Batas | Nilai (`Config\HaloSimpeg`, dapat ditimpa `.env`) |
|---|---|
| `eksporMaksBaris` | 10.000 baris pesan |
| `eksporMaksKarakter` | 5.000.000 karakter `SUM(CHAR_LENGTH(msg))` |

Ambang karakter ditetapkan dari pengukuran memori nyata: `EksporTiketVolumeTest` (opt-in `RUN_VOLUME_TEST=1`, tidak jalan di CI,
V13), request HTTP penuh (kueri, susun baris, `EksporTabel::xlsx`, audit), `memory_reset_peak_usage()` tepat sebelum request, 10.000
baris dengan total tepat 5.000.000 karakter; PHP 8.4.2, MySQL 8, mesin dev, `memory_limit` 512M saat ukur:

| Isi pesan | Byte data | Puncak memori tambahan per request | Waktu |
|---|---|---|---|
| Latin | 5,2 MB | 50,7 MB | 0,4 s |
| CJK 3 byte | 15 MB | 82,4 MB | 0,7 s |
| Emoji 4 byte (terburuk utf8mb4) | 20 MB | 105,3 MB | 0,7 s |

Batas uji 128 MB = ½ `memory_limit` 256M (`README-deploy.md` §1). Ukuran awal 10.000.000 karakter: Latin 69 MB, CJK 140 MB (lewat
batas), jadi ambang diturunkan. **Bila `memory_limit` produksi < 256M, ambang wajib diturunkan** (lalu diukur ulang). CI menguji batas
10.000/10.001 baris nyata dan ambang karakter lewat override config.

**Audit unduhan (AC-E09.7)** — hanya bila berkas terkirim: `audit_logs.entity = dokumen_unduhan` (pola F7-27), `entity_id =
rekap_halo_simpeg`, `event = create`, `after_json = {jenis, format, parameter: {ds, de}, jumlah_baris}`, aktor = id dan NIP akun
pengekspor. Tanpa isi pesan, nama/NIP penanya, atau isi berkas. Ekspor kosong, 403, dan 422 tidak diaudit. Ekspor hanya membaca
(tidak menulis tabel helpdesk maupun `inbox`, BP8-1).

## Fixture ekspor (`backend/tests/_support/HaloSimpeg/`)

| Fixture | Respons |
|---|---|
| `ekspor-kosong` | 200 JSON periode kosong |
| `galat-422-ekspor` | 422 `ds`/`de` kosong |
| `galat-422-ekspor-terlalu-besar` | 422 `ekspor_terlalu_besar` |
| `galat-403-bukan-petugas` | 403 `bukan_petugas` |

Bentuk fixture = respons nyata, diuji `EksporTiketTest` (`cocokFixtureHs`).

## Selisih ekspor terhadap PRD v1.4 (diputus 18-11-2026: V1/V2 Horii, V3–V13 reviewer)

| No | Selisih | Label |
|---|---|---|
| V1 | Cakupan pesan = pesan pemilik + pesan pengekspor saja (legacy penuh); batas COUNT/SUM atas cakupan yang sama; tiket tanpa balasan pengekspor tetap ikut (perbaikan F8-20) | [K], keputusan Horii 18-11-2026 |
| V2 | Nama/NIP = pengirim baris, tanpa penyamaran D7 (a); NIP kosong bila pengirim tanpa NIP | [K], keputusan Horii 18-11-2026 |
| V3 | `format` kosong = xlsx; nilai lain → 422 `errors.format` "Format ekspor tidak didukung." | [V2] |
| V4 | Tanggal tak valid → teks `DaftarLogIntegrasi`; kosong dan `ds` > `de` → teks §7.3a | [V2] |
| V5 | 403 `bukan_petugas` dicek sebelum validasi periode | [V2] |
| V6 | Tiket tanpa pesan sama sekali tidak menghasilkan baris (dihitung Q-H16 di runbook) | [V2] |
| V7 | Fixture tambahan `galat-422-ekspor-terlalu-besar` | [V2] |
| V8 | Judul, periode, sheet, `TIMESTAMP` WIB `d/m/Y H:i` [K]; tanpa gaya/lebar kolom legacy (`EksporTabel` tidak diubah) | [K]/[V2] |
| V9 | Audit `dokumen_unduhan` / `rekap_halo_simpeg`, `after_json` tanpa isi/penanya | [V2] |
| V10 | Potong sel dalam unit UTF-16, penanda termasuk dalam 32.767, surrogate tidak terbelah | [V2] |
| V11 | Kategori nonaktif yang masih dipegang ikut; petugas aktif tanpa `officer_category` → 200 kosong | [K]/[V2] |
| V12 | Baris dan karakter dicek ulang sesudah data diambil | [V2] |
| V13 | Uji volume opt-in (`RUN_VOLUME_TEST=1`) | [V2] |
