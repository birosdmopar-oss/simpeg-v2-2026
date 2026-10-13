# Modul B — Kepegawaian Core (Fase 3)

Controller REST API modul ini (`App\Controllers\Api\Kepegawaian\*`). Semua controller extends `App\Controllers\Api\ApiController`. Route Fase 3 ada di `Config/RoutesRiwayat.php` (WS-1) dan `Config/RoutesPegawai.php` (WS-2), grup `api/v1` dengan filter `jwt` di level grup; filter `role:*` per route bila role endpoint seragam, izin per jenis riwayat dicek service (lihat [Route](#route)).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## Kontrak API Modul B (S0-A, MAKE-002)

Bagian ini **mengikat** kedua workstream Fase 3 setelah MAKE-002 masuk `main`: WS-1 (mesin riwayat) dan WS-2 (pegawai,
lingkup, lampiran, frontend). Setelah beku, kontrak di sini hanya boleh berubah **secara aditif** dan **oleh pemiliknya**
(lihat [Kepemilikan & perubahan](#kepemilikan--perubahan)). Runbook: `docs/fase3/SPRINT0_1_backend.md`. Revisi hasil
review CR 07-10-2026 (lampiran satu request, lampiran terikat NIP, filter `jwt` grup) sudah termasuk.

### Aturan umum

| Aturan | Isi |
|---|---|
| Prefix | Semua path di bawah relatif `api/v1/` |
| Autentikasi | Semua endpoint butuh JWT (`Authorization: Bearer …`); tanpa token → **401** |
| Envelope | Sukses `{ "status": "success", "data": … }`; gagal `{ "status": "error", "message": "…", "errors"?: { "<field>": ["…"] } }` (ADR-001) |
| Nama field | Payload & respons **= nama kolom DDL** tabelnya (snake_case, mis. `tgl_lulus`, `id_jenjang_pendidikan`, `reason_note`). Tidak ada alias camelCase. Pengecualian eksplisit: baris lampiran memakai kunci **`NIP`** huruf besar, karena nama kolom DDL `document_attachment.NIP` memang begitu |
| Waktu | Disimpan UTC (`created_at`, `updated_at`); tanggal bisnis (`tmtsk`, `tgl_lulus`, …) `YYYY-MM-DD` apa adanya |
| Audit | Tulis riwayat dicatat `audit_logs`; approve/reject dicatat sebagai aksi **`update`** (before/after) |
| `{nip}` | NIP pegawai (PK `pegawai.nip`), segmen tunggal |
| `{jenis}` | Slug jenis riwayat dari [daftar slug beku](#daftar-slug-jenis-beku) |
| `{id}` | PK baris riwayat (`id_riwayat_*`) atau `id_attachment` — **selalu milik `{nip}`**: dicari `WHERE <pk> = {id} AND <kolom nip> = {nip}`; milik NIP lain = 404 (bukan IDOR) |

### Kode status & urutan pemeriksaan

| Kode | Arti |
|---|---|
| 200 / 201 | Sukses (201 untuk `POST` yang membuat baris) |
| 401 | Tanpa token / token tidak valid (filter `jwt` grup) |
| **403** | Role tidak berhak atas aksi itu (izin Definisi riwayat), **atau** NIP di luar lingkup pemanggil (`PegawaiScope`) |
| **404** | `{jenis}`, NIP, baris, atau lampiran tidak ada / bukan milik `{nip}`; juga path yang belum didaftarkan (envelope error, bukan 500) |
| **422** | Validasi gagal — `errors` per field (nama field = kolom DDL; berkas: `berkas` / `berkas.<id_riwayat>`) |
| 501 | Fitur belum tersedia (service masih stub S0-A, `App\Exceptions\BelumTersediaException`) |

Urutan pemeriksaan (wajib, endpoint riwayat dan lampiran):

1. `{jenis}` terdaftar (lampiran: `id_riwayat` dimiliki Definisi terdaftar) → 404.
2. Izin Definisi × role pemanggil untuk aksinya → 403.
3. `PegawaiScope` atas `{nip}` (`bolehLihat` untuk baca, `bolehUbah` untuk tulis, `bolehProses` untuk Setujui/Tolak) → 403. Bagi pemanggil ber-lingkup, NIP
   yang tidak ada dan NIP di luar lingkup dijawab **sama (403)** — keberadaan NIP tidak bocor.
4. Keberadaan data: NIP (404 bila lingkup pemanggil mencakupnya), baris `{id}` milik `{nip}` → 404.
   Izin per pegawai sasaran `RiwayatDefinisi::bolehAksiUntuk()` → 403 diperiksa di tengah langkah ini: riwayat (semua
   aksi, termasuk `lihat` untuk `GET` daftar/detail/unduh berkas kolom — mis. AK/AK SIASN role 2 dan 7 hanya pejabat JF
   tertentu, MAKE-008; descriptor tab memakainya juga) sesudah keberadaan NIP, sebelum baris `{id}`; lampiran
   `POST`/`DELETE` (CR-046, tambah/ubah/hapus) sesudah `bolehUbah` (`DELETE`: sesudah baris lampiran `{id}` dibaca),
   sebelum baris riwayat `id_entri`. `GET`/unduh lampiran tidak memanggilnya.

### Endpoint

| Method & path | Pemilik | Keterangan |
|---|---|---|
| `GET pegawai` | WS-2 | Daftar pegawai, disaring lingkup (`PegawaiScope::terapkanKeQuery`) |
| `GET pegawai/{nip}` | WS-2 | Detail pegawai + `tabs` (array [descriptor tab](#descriptor-tab-beku)) |
| `GET pegawai/{nip}/riwayat` | WS-1 | **Aditif M3 (MAKE-006).** `{ "tabs": [descriptor…] }` = descriptor tab riwayat engine untuk pemanggil atas `{nip}` — fungsi yang sama dengan `data.tabs` `GET pegawai/{nip}` (`RiwayatRegistryInterface::descriptorUntuk`). Urutan: 401 → 403 lingkup (`bolehLihat`; NIP tidak ada bagi role ber-lingkup = 403) → 404 NIP. Role tanpa izin lihat jenis apa pun → `tabs: []` |
| `GET pegawai/{nip}/riwayat/{jenis}/{id}/berkas/{kolom}` | WS-1 | **Aditif M4 (MAKE-007).** Unduh berkas kolom (`RiwayatDefinisi::kolomBerkas()`, mis. Karpeg `file_1..5`); izin `lihat` + `bolehLihat`; baris status 10, kolom tak terdaftar/kosong → 404 |
| `GET/POST pegawai/{nip}/riwayat/keluarga/{id}/anak`, `PUT/DELETE …/anak/{idAnak}` | WS-1 | **Aditif M4 (MAKE-007).** Anak (`detail_anak`) milik baris keluarga (service `AnakKeluarga`): urutan pemeriksaan sama dengan engine (izin `lihat`/`ubah` keluarga → lingkup → NIP → `bolehAksiUntuk` → keluarga milik NIP, bukan 10 → anak milik keluarga, 404). Akte lahir = multipart `berkas` (kode 3; wajib saat tambah dan saat ubah bila belum ada, 422 `errors.berkas`); ubah dengan berkas = `POST` + `_method=PUT`. Satu transaksi: `jumlah_anak` dihitung ulang, UL_PEGAWAI mengembalikan keluarga ke status 0, snapshot `pegawai_keluarga` bila keluarga Disetujui; kunci baris Disetujui (UL_PEGAWAI; hapus anak juga role 3). Hapus anak = hapus keras + akte (legacy) |
| `GET usulan/{karpeg,kariskarsu}` | WS-1 | **Aditif M4 (MAKE-007).** Antrean usulan (legacy `list_ad`): role ber-izin `lihat` bukan UL_PEGAWAI (1, 4, 5), tanpa lingkup (keputusan user 12-10-2026), baris status ≠ 10 + `nama`/`unit`/`satker`; `?status=` (0/1/2) opsional; `{ can_process, items }` |
| `GET usulan/{karpeg,kariskarsu}/saya` | WS-1 | **Aditif M4 (MAKE-007).** Usulan milik pemanggil (role ber-izin `tambah`: 2, 7): `{ pegawai{nip,nama,glr_*,jenis_kelamin}, can_create, can_edit, can_delete, syarat_terpenuhi, items }`. Ajukan/ubah/hapus/proses lewat endpoint riwayat engine `{jenis}` |
| `GET pegawai/{nip}/riwayat/{jenis}` | WS-1 | Daftar baris riwayat (semua status kecuali 10 Dihapus; Definisi boleh menyaring lagi lewat `saringDaftar()`, aditif MAKE-008) |
| `GET pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Satu baris |
| `POST pegawai/{nip}/riwayat/{jenis}` | WS-1 | Tambah, **satu request**: `multipart/form-data` berisi field data (kolom DDL) + berkas `berkas[<id_riwayat>]` per kode lampiran; JSON boleh bila jenis tanpa lampiran wajib. Status awal **0 Menunggu** untuk UL_PEGAWAI (role 2/6/7) pada alur self-service, **1 Disetujui** untuk admin. Lampiran ber-`wajib` yang tidak dikirim → 422 `errors.berkas.<id_riwayat>` |
| `PUT pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Ubah. JSON; dengan berkas: `POST` multipart + field `_method=PUT` (PHP tidak mem-parse multipart pada PUT; CI4 method spoofing). Berkas menambah lampiran baris itu. Baris berstatus 1 Disetujui terkunci untuk UL_PEGAWAI (`RiwayatDefinisi::kunciBarisDisetujui()`, default ya) |
| `DELETE pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Hapus lunak → status **10**; snapshot dihitung ulang bila baris itu aktif |
| `POST pegawai/{nip}/riwayat/{jenis}/{id}/process` | WS-1 | Body `{ "aksi": "setujui" \| "tolak", "reason_note": "…" }`; `reason_note` wajib saat `tolak` (422). Snapshot `pegawai_*` disinkron di sini (approval final, ADR-006) |
| `GET pegawai/{nip}/lampiran?id_riwayat=&id_entri=` | WS-2 | Daftar lampiran satu baris riwayat (izin `lihat`) |
| `POST pegawai/{nip}/lampiran` | WS-2 | Tambah/ganti lampiran pada baris yang **sudah ada**: `multipart/form-data` `berkas`, `id_riwayat`, `id_entri` (izin `tambah` atau `ubah`) |
| `GET pegawai/{nip}/lampiran/{id}/unduh` | WS-2 | Unduh berkas lampiran milik `{nip}` (izin `lihat`) |
| `DELETE pegawai/{nip}/lampiran/{id}` | WS-2 | Hapus keras lampiran milik `{nip}` + audit (izin `hapus` atau `ubah`) |

Konket dan LKH **bukan** jenis engine riwayat — endpoint sendiri milik WS-2 (didokumentasikan WS-2 di README ini saat
dibuat). `karpeg` dan `kariskarsu` dipakai halaman **usulan mandiri**, bukan tab Detail Pegawai; endpoint antrean
usulan Karpeg/Karis (WS-1, M4) ditambahkan secara aditif dengan prefix `usulan/karpeg`, `usulan/kariskarsu`
(dicadangkan).

Hak akses per role per jenis disimpan sebagai data di Definisi riwayat (`RiwayatDefinisi::izin()`), mengikuti Matriks v2
(`docs/fase3/MATRIKS_ROLE_MODUL_B.md`); lingkup unit/satker/milik sendiri ditegakkan `PegawaiScope`.

Contoh daftar riwayat:

```json
{ "status": "success",
  "data": [ { "id_riwayat_pendidikan": 12, "nip": "199001012015011001", "tgl_lulus": "2012-08-30",
              "institusi_pendidikan": "…", "status": 0, "reason_note": null } ] }
```

Contoh 422 (tambah riwayat tanpa lampiran wajib):

```json
{ "status": "error", "message": "Validasi gagal.",
  "errors": { "tgl_lulus": ["Tanggal lulus wajib diisi."], "berkas.14": ["Lampiran wajib diunggah."] } }
```

### Lampiran

- **Satu request** (keputusan reviewer CR 07-10-2026): data riwayat + berkas dikirim bersama ke `POST/PUT riwayat`
  dan disimpan dalam **satu transaksi engine** (riwayat + snapshot + lampiran, WS1.md). Endpoint `pegawai/{nip}/lampiran*`
  tetap ada untuk menambah/mengganti/menghapus lampiran pada baris yang sudah ada.
- **Per kode `jenis_rwy`**: satu Definisi memuat **daftar** `AturanLampiran` (`RiwayatDefinisi::lampiran(): list`), satu per
  kode — mis. `riwayat_pendidikan` = 14 pendidikan, 39 pencantuman gelar, 40 transkrip nilai (masing-masing 5 MB, legacy
  `L_pendidikan`); `riwayat_mutasi_jabatan` = 9 jabatan, 36 jabatan_pjft, 41 perjanjian_kerja. Satu kode hanya milik satu
  Definisi (registry menolak kode ganda). `RiwayatRegistryInterface::definisiUntukLampiran(int)` /
  `aturanLampiran(int)` memetakan kode → Definisi/aturan.
- **`wajib`** ditegakkan `RiwayatService::tambah()/ubah()`: setelah request, baris wajib punya minimal satu lampiran
  untuk setiap kode ber-`wajib`.
- **Otorisasi endpoint lampiran** (ditegakkan pemanggil sebelum `AttachmentService`): `id_riwayat` → Definisi pemilik
  (tidak terdaftar → 404); izin Definisi × role: `GET`/unduh = `lihat`, `POST` = `tambah` atau `ubah`, `DELETE` = `hapus`
  atau `ubah` (403); `PegawaiScope` `bolehLihat`/`bolehUbah` (403); `id_entri` wajib baris milik `{nip}` di
  `tabel()` Definisi itu (404). Contoh: role 2 tidak bisa membaca/mengunggah lampiran `hukdis` miliknya sendiri karena
  izin `hukdis` hanya role 1, 3. `POST`/`DELETE` juga memeriksa izin per pegawai `bolehAksiUntuk()` (CR-046): salah satu
  aksi yang diizinkan role (`POST` `tambah`/`ubah`; `DELETE` `hapus`/`ubah`) harus lolos hook untuk `{nip}` (403) —
  mis. role 2 tidak bisa mengunggah/menghapus lampiran Pendidikan miliknya setelah bukan CPNS lagi.
- **Berkas**: ekstensi/MIME divalidasi dari **isi** berkas (bukan nama kiriman), batas MB dari `AturanLampiran`; nama &
  path simpan dibangkitkan server, nama asli hanya `display_name`. Error 422 berkunci `berkas` (endpoint lampiran) atau
  `berkas.<id_riwayat>` (endpoint riwayat).
- **Respons lampiran** = baris `document_attachment` apa adanya (kunci `NIP` huruf besar, `id_riwayat`, `id_entri`,
  `display_name`, `file_size`, `file_ext`, …); `path` internal tidak dikirim ke klien.
- **Rincian endpoint (MAKE-009, aditif):** `GET lampiran` mewajibkan query `id_riwayat` dan `id_entri` (422
  `errors.id_riwayat` / `errors.id_entri`); `POST` selalu **menambah** lampiran — "ganti" = unggah baru **dulu**, lalu
  hapus yang lama; lampiran terakhir kode ber-`wajib` pada satu baris tidak bisa dihapus (`DELETE` → 422
  `errors.berkas`); baris riwayat status 1 Disetujui terkunci untuk UL_PEGAWAI bila `kunciBarisDisetujui()` (`POST`/
  `DELETE` → 403), baris status 10 → 404. Urutan unduh/hapus per `{id}`: `PegawaiScope::bolehLihat({nip})` (403,
  sebelum baris dibaca — pemanggil di luar lingkup mendapat 403 untuk id yang ada maupun tidak) → role tanpa izin aksi
  itu (unduh `lihat`; hapus `hapus`/`ubah`) di Definisi berlampiran mana pun (403, juga sebelum baris dibaca) → baris
  lampiran milik `{nip}` (404) → izin Definisi pemilik `id_riwayat` baris itu (+ `bolehUbah` dan `bolehAksiUntuk()`
  untuk hapus, CR-046) (403) →
  baris riwayat `id_entri` milik `{nip}`, bukan status 10 (404; unduh hanya membaca, tanpa kunci baris Disetujui) →
  hapus: kunci baris Disetujui (403) → lampiran wajib terakhir (422). `POST`/`DELETE` mengulang pemeriksaan tulis di
  dalam transaksi (baris `pegawai` `FOR UPDATE`, lampiran & baris riwayat dibaca ulang terkunci, hitungan lampiran
  wajib diulang): hasil tetap 404/403/422 bila keadaan berubah di antaranya. [V2] endpoint lampiran tidak mengubah
  status baris riwayat (legacy mengunggah hanya lewat form edit yang mengembalikan status ke 0/1), dan kunci baris
  Disetujui untuk `DELETE` lebih ketat dari `delete_da` legacy. `DELETE` menjawab
  `{ deleted: true, soft_delete: false, item: <baris> }`; unduh = isi berkas dengan
  `Content-Type` = `file_type`, `Content-Disposition: attachment` (nama = `display_name`), `X-Content-Type-Options:
  nosniff`, `Cache-Control: private, no-store`.

### Descriptor tab (beku)

`GET pegawai/{nip}` mengembalikan `data.tabs`: array descriptor. Frontend merender tab dan tombol aksi **hanya** dari
descriptor ini.

```json
{ "jenis": "pendidikan", "label": "Riwayat Pendidikan",
  "can_view": true, "can_create": true, "can_edit": true, "can_delete": false, "can_process": false }
```

`data.tabs` = descriptor **engine** (dari `RiwayatRegistryInterface::descriptorUntuk(AuthContext, nip)`, urut
`urutanTab()` Definisi lalu slug) **diikuti** descriptor **non-engine** yang ditambahkan WS-2 dengan bentuk sama
(mis. `{ "jenis": "lkh", "label": "Laporan Kerja Harian", … }`). Nilai `jenis` di `tabs` ⊇ slug engine; `jenis`
non-engine tidak boleh sama dengan slug engine.

Descriptor engine:

- `can_view` = role pemanggil ada di izin `lihat` Definisi **dan** `PegawaiScope::bolehLihat`.
- `can_create` / `can_edit` / `can_delete` = izin `tambah` / `ubah` / `hapus` **dan** `PegawaiScope::bolehUbah`;
  `can_process` = izin `proses` **dan** `PegawaiScope::bolehProses` (jalur proses CR-045, sama dengan
  `RiwayatEngine::proses()`; aditif MAKE-006).
- Jenis yang `can_view`-nya false **tidak** dimasukkan; jenis ber-alur `usulan` (`karpeg`, `kariskarsu`) tidak pernah
  menjadi tab.
- `can_edit`/`can_delete` adalah hak umum atas jenis itu; penguncian per baris (baris Disetujui untuk UL_PEGAWAI)
  ditegakkan server per baris.

### Daftar slug `{jenis}` (beku)

Konstanta `App\Libraries\Kepegawaian\Riwayat\JenisRiwayat::SLUG`; dipakai juga sebagai nama berkas registry frontend
`riwayat/jenis/<slug>.ts`.

| Slug | Jenis | Tabel riwayat | Task |
|---|---|---|---|
| `jabatan` | Jabatan / mutasi | `riwayat_mutasi_jabatan` | B-07 (WS-2) |
| `kp` | Kenaikan pangkat | `riwayat_kp` | B-08 |
| `kgb` | Kenaikan gaji berkala | `riwayat_kgb` | B-09 |
| `pendidikan` | Pendidikan | `riwayat_pendidikan` | B-10 |
| `diklat` | Diklat | `riwayat_diklat` | B-11 |
| `seminar` | Kursus / seminar | `riwayat_seminar` | B-11 |
| `skp` | SKP tahunan | `riwayat_skp` | B-12a |
| `skp-periodik` | SKP periodik | `riwayat_skp_periodik` | B-12a |
| `hukdis` | Hukuman disiplin | `riwayat_hukdis` | B-14 |
| `ak` | Angka kredit | `riwayat_ak` | B-15 |
| `ak-siasn` | Angka kredit SIASN | `riwayat_ak_siasn` | B-15 |
| `keluarga` | Keluarga | `riwayat_keluarga` | B-16 |
| `alamat` | Alamat | `riwayat_alamat` | B-16 |
| `tanda-jasa` | Tanda jasa | `riwayat_tanda_jasa` | B-17 |
| `organisasi` | Organisasi | `riwayat_organisasi` | B-17 |
| `karpeg` | Kartu pegawai (usulan mandiri) | `riwayat_karpeg` | B-17 |
| `kariskarsu` | Karis/Karsu (usulan mandiri) | `riwayat_kariskarsu` | B-17 |

Nama tabel final per jenis ditetapkan Definisi jenisnya (`RiwayatDefinisi::tabel()`), mengikuti DDL B-02 di `main`.

### Status riwayat

`App\Libraries\Kepegawaian\Riwayat\StatusRiwayat` (kolom `status` tabel riwayat, ikut legacy):

| Nilai | Status | Catatan |
|---|---|---|
| 0 | Menunggu | Diajukan UL_PEGAWAI, menunggu proses |
| 1 | Disetujui | Satu-satunya status yang mengisi snapshot `pegawai_*` |
| 2 | Ditolak | `reason_note` terisi |
| 3 | Diproses | **Hanya** bila `Config\Kepegawaian::$statusDiprosesAktif` (default `false`) |
| 10 | Dihapus | Hapus lunak; tidak tampil di daftar |

Tabel yang CHECK status-nya `IN (0, 1, 2, 10)` di DDL main (`riwayat_ak`, `riwayat_ak_siasn`, `riwayat_alamat`,
`riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_skp_periodik`): Definisi-nya **wajib** override `pemetaanStatus()`
tanpa 3.

### Rincian engine riwayat (M1, MAKE-004, aditif)

Ketetapan perilaku `RiwayatEngine` yang melengkapi kontrak di atas (catatan review Sprint 0). Perilaku per jenis tetap
data di Definisi; default di bawah ikut legacy.

| Hal | Ketetapan |
|---|---|
| NULL di payload | String kosong (setelah trim) pada field **opsional** = `NULL` — sama untuk JSON, form, dan multipart, jadi klien boleh mengirim `""`. Field wajib kosong → 422. Field yang tidak dikirim saat `PUT` = tidak diubah (parsial) |
| Kolom sistem | `status`, `approved_by`, `reason_note`, `updated_by`, `show_*`, PK, `nip` dari klien **diabaikan** (dikelola engine). Hanya field Definisi yang ditulis. Pengecualian aditif (MAKE-005): role di `RiwayatDefinisi::rolePilihStatusSaatUbah()` boleh mengirim `status` saat `PUT` (0/1/2, 3 bila flag aktif; lainnya 422 `errors.status`; 2 wajib `reason_note` maks 255 byte → 422 `errors.reason_note`) — `kp` dan `pendidikan`: role 1, ikut legacy form edit |
| Izin per pegawai | `RiwayatDefinisi::bolehAksiUntuk()` (aditif MAKE-005) diperiksa sesudah lingkup & keberadaan NIP → 403; descriptor `can_*` ikut. `pendidikan`: role 2 hanya tambah/ubah/hapus selama pegawai CPNS (`pangkat.cpns = 1` snapshot `pegawai_kp`, legacy) |
| Lampiran di respons riwayat | **Tidak** ikut. Respons riwayat = kolom DDL tabel riwayat saja; lampiran dibaca lewat `GET pegawai/{nip}/lampiran?id_riwayat=&id_entri=` (WS-2) |
| `reason_note` saat setujui | Opsional; `""`/spasi = `NULL`, isi lain disimpan (legacy `L_kp.php:652`). Saat tolak wajib (422 `errors.reason_note`); maksimal 255 byte (TINYTEXT) |
| Proses | Hanya baris berstatus 0 Menunggu (atau 3 bila flag Diproses aktif) → selain itu 422 `errors.status` ([V2]; legacy tidak menjaganya). Urutan: baris milik NIP (404) → `aksi` (422) → status (422) → `reason_note` (422). `aksi` selain `setujui`/`tolak` → 422 `errors.aksi`. Mengisi `approved_by` + `updated_by` = id_pengguna pemroses, `show_ua_biro` (role 1) / `show_ua_upt` (role 3 bersatker) / `show_ua_deputi` (role 3 tanpa satker), `show_notif = 1`, `notif_date` (UTC) |
| Status saat tambah | `RiwayatDefinisi::statusAwal()`: UL_PEGAWAI (2/6/7) pada alur self-service/usulan → 0; selain itu → 1 (snapshot langsung disinkron) |
| Status saat ubah | `RiwayatDefinisi::statusSetelahUbah()`: UL_PEGAWAI → 0 (diajukan ulang); role 3 → 1; role lain (1) → tidak berubah (legacy `controllers/hr/rwy/Pendidikan.php:255-260`). Baris lama ber-status NULL diperlakukan seperti 0 (role 1 membiarkannya NULL) |
| Kunci baris Disetujui | Ubah/hapus oleh UL_PEGAWAI → 403 (bila `kunciBarisDisetujui()`); hapus oleh `roleKunciHapusDisetujui()` (default role 3, legacy `Kp.php:384`) → 403 |
| Hapus | Lunak → status 10 (audit `delete`); lampiran baris itu tidak dihapus. Respons `{ "deleted": true, "soft_delete": true }`. Baris status 10 = 404 di detail/ubah/proses |
| Daftar | Semua baris NIP itu kecuali status 10 (baris lama ber-status NULL ikut tampil), disaring lagi oleh `RiwayatDefinisi::saringDaftar(BaseBuilder)` (aditif MAKE-008; mis. SKP periodik hanya status 1, AK status 0/1 ikut legacy; detail/ubah per baris tidak ikut disaring), urut `RiwayatDefinisi::urutanDaftar()` (default PK terbaru = [V2]; jenis nyata meng-override sesuai legacy) |
| Snapshot | Disinkron bila status lama **atau** baru = 1 (termasuk ubah data baris Disetujui); pengajuan dan penolakan baris yang belum disetujui tidak menyentuhnya |
| Notifikasi | Event aplikasi `riwayatNotifikasi` sesudah commit, payload `{tipe, jenis, label, nip, id, status, aksi, reason_note}`; `tipe` 1 = diajukan, 2 = diproses, 3 = diubah (legacy `fcmQueue_rwy`). Pengirim push (FCM, H-01) berlangganan event ini |
| Berkas | `berkas[<id_riwayat>]` satu berkas per kode; kode bukan angka (kecuali nama kolom di `kolomBerkas()`, baris berikut), unggahan gagal, atau kode di luar `lampiran()` → 422 `errors.berkas.<kode>`; slot kosong (`UPLOAD_ERR_NO_FILE`) dilewati |
| Berkas kolom (aditif MAKE-007) | `berkas[<kolom>]` untuk kolom di `RiwayatDefinisi::kolomBerkas()` (path disimpan di kolom tabel riwayat lewat KolomBerkas MAKE-009; berkas lama yang diganti dihapus setelah commit); wajib per `kolomBerkasWajib()` (ada di request atau kolom sudah terisi); kolom tak dikenal → 422 `errors.berkas.<kolom>` |
| Proses (aditif MAKE-008) | Sesudah status setujui/tolak ditulis (dan sesudah snapshot + `afterApprove` saat setujui), engine memanggil `RiwayatDefinisi::afterProses(aksi, baris, auth)` di transaksi yang sama; default no-op (AK: `appr_at`/`appr_by`) |
| beforeSave saat tambah (aditif MAKE-008) | `$data` berisi juga kolom NIP pemilik, agar isian turunan per pegawai bisa diisi server (mis. AK: jabatan, pangkat, TMT, masa kerja, AK kumulatif) |
| Lampiran bersyarat & entri anak (aditif MAKE-007) | `lampiranWajib(aturan, nilai akhir)` menentukan wajib per baris (mis. KTP alamat bukan untuk kantor); kode dengan `entriLampiran()` (mis. akte 3 = `detail_anak`) tidak diminta/diterima di `POST/PUT riwayat` — diunggah lewat endpoint anak atau `POST lampiran` (`id_entri` = PK baris anak, pemilik lewat baris induk) |

### Kontrak backend (interface & service)

Lingkup `pegawaiScope` per jalur (CR-045, keputusan user 12-10-2026, ikut legacy): untuk Admin Satker (role 3) **tanpa
satker** jalur daftar, detail/ubah, dan proses memakai predikat berbeda; untuk role lain dan role 3 bersatker ketiganya
sama (`bolehProses` = `bolehUbah`). Rujukan legacy berkas:baris di README `Libraries/Kepegawaian`, bagian Scope.

| Admin role 3 tanpa satker | Daftar (`terapkanKeQuery`) | Detail/ubah/arsip/lampiran (`bolehLihat`, `bolehUbah`) | Setujui/Tolak + antrean (`bolehProses`) |
|---|---|---|---|
| unit 20 | `pmj.id_unit IN (20, 6)` | `pmj.id_unit = 20` | `pmj.id_unit = 20` |
| unit 21 | `pmj.id_unit = 21 OR pmj.id_satker IN (37, 38, 41)` | `pmj.id_unit = 21 OR (pmj.id_unit = 7 AND pmj.id_satker IN (37, 38, 41))` | `pmj.id_unit = 21 OR pmj.id_satker IN (37, 38, 41)` |
| unit lain | `pmj.id_unit` = unit admin | sama | sama |

Akibatnya pegawai yang tampil di daftar bisa 403 di detail (UPT untuk admin unit 20), dan riwayat yang bisa diproses
bisa 403 di ubah/detail (satker BPO unit bukan 7 untuk admin unit 21). Pegawai tanpa snapshot pmj ditolak di semua jalur.

Didaftarkan **sekali** di `Config\Services` (S0-A); tipe kembalian = interface. Stub produksi di
`App\Libraries\Kepegawaian\Stub` **fail-closed** (`StubPegawaiScope` menolak semua; setiap method stub lain melempar
`App\Exceptions\BelumTersediaException` → 501). Fake test hanya di `tests/_support/Kepegawaian` (`FakePegawaiScope`,
`FakeAttachmentService`, `FakeStorageAdapter`), disuntik `Services::injectMock('<nama>', $fake)`. `riwayatRegistry`
me-resolve `pegawaiScope` setiap dipakai, jadi scope yang disuntik berlaku juga untuk registry shared yang sudah
ter-resolve (tanpa `resetSingle`).

| Service | Interface (`App\Interfaces\Kepegawaian\…`) | Pemilik implementasi |
|---|---|---|
| `pegawaiScope` | `PegawaiScopeInterface`: `bolehLihat(AuthContext, nip)`, `bolehUbah(AuthContext, nip)`, `bolehProses(AuthContext, nip)` (aditif CR-045: Setujui/Tolak + antrean approval), `terapkanKeQuery(BaseBuilder, AuthContext, kolomNip = 'nip')` | WS-2 (MAKE-009) |
| `attachmentService` | `AttachmentServiceInterface`: `simpan(nip, idRiwayat, idEntri, UploadedFile, AturanLampiran): array`, `ambil(nip, idAttachment): array{lampiran, isi}`, `hapus(nip, idAttachment)`, `daftar(nip, idRiwayat, idEntri): list` — lampiran bukan milik `nip` → 404; di dalam transaksi pemanggil, kompensasi berkas bila rollback | WS-2 (MAKE-009) |
| `storageAdapter` | `StorageAdapterInterface`: `simpan`, `hapus`, `ada`, `baca` (path relatif) | WS-2 (MAKE-009) |
| `riwayatRegistry` | `RiwayatRegistryInterface`: `semua()`, `definisi(jenis)`, `definisiUntukLampiran(idRiwayat)`, `aturanLampiran(idRiwayat)`, `descriptorUntuk(AuthContext, nip)` — sudah nyata (`RiwayatRegistry`, auto-discovery) | WS-1 |
| `riwayatService` | `RiwayatServiceInterface`: `daftar`, `detail`, `tambah(…, array $data, array $berkas = [])`, `ubah(…, array $data, array $berkas = [])`, `hapus`, `proses` — `$berkas` = kode `jenis_rwy` ⇒ `UploadedFile` | WS-1 (MAKE-004) — `RiwayatEngine` |
| `snapshotSync` | `SnapshotSyncInterface`: `sinkronkan(RiwayatDefinisi, nip, ?AuthContext pelaku = null)` (parameter pelaku audit aditif MAKE-004) | WS-1 (MAKE-004) — `SnapshotSync` |
| `pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService` | Interface penanda tanpa method; method ditetapkan pemiliknya di milestone-nya (aditif) | WS-2 (MAKE-010..014) |

**Definisi riwayat** (`App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi`, pemilik WS-1): satu berkas per jenis di
`app/Libraries/Kepegawaian/Riwayat/Definisi/`, ditemukan otomatis. Isinya data: `jenis()` (slug), `label()`, `tabel()`,
`primaryKey()`, `kolomNip()`, `kolomStatus()`, `fields()` (`MasterField`), `izin()` (aksi `lihat`/`tambah`/`ubah`/
`hapus`/`proses` → role), `alur()` (`self-service`/`admin`/`usulan`), `pemetaanStatus()`, `kunciBarisDisetujui()`,
`snapshot()` (`AturanSnapshot`: tabel target, kolom, filter, urutan, join — multi-target), `lampiran()` (**daftar**
`AturanLampiran`, satu per kode `jenis_rwy`: wajib, batas 1/2/5 MB, ekstensi), `urutanTab()`, dan hook opsional
`validate`, `beforeSave`, `afterApprove`. Aditif engine-v1: `lampiranWajib`, `kolomBerkas`/`kolomBerkasWajib`,
`entriLampiran` (MAKE-007), `saringDaftar`, `afterProses` (MAKE-008).

**Fixture test** `Tests\Support\Kepegawaian\PegawaiFixtureTrait` (bersama, dipakai di test turunan
`Tests\Support\DatabaseTestCase` MAKE-001): `buatPegawai(array $override = [], array $pmj = []): string`,
`buatPegawaiDiSatker(int $idSatker, …): string`, `buatAkunUntuk(string $nip, int $role, …): int` (pegawai wajib ada),
`buatRiwayatMutasiJabatan(string $nip, array $override = []): int`, `buatUnit()`, `buatSatker()`, `buatJabatan()`,
`buatJabatanKoordinasi()`, `buatRumpunJabatan()`, `authUntukAkun(int)`. Hanya INSERT (bingkai transaksi uji MAKE-001
tanpa `db-isolasi-penuh`), NIP sintetis 18 digit, kolom FK diisi master G-02 yang dibuat fixture (patuh FK DBV-019),
kolom teks snapshot diisi nama master. Pemilik setelah S0: berkas bersama — WS mana pun boleh **menambah** helper
(satu helper per commit); mengubah perilaku helper yang ada butuh persetujuan reviewer CR.

### Route

Route Fase 3 di berkas terpisah lewat `Config\Routing::$routeFiles`: `Config/RoutesRiwayat.php` (WS-1) dan
`Config/RoutesPegawai.php` (WS-2). `Config/Routes.php` tidak disentuh lagi. NIP memakai `(:segment)`, bukan `(:any)`.

- Filter **`jwt` dipasang di level grup** pada kedua berkas, jadi route yang ditambahkan WS tidak pernah terbuka tanpa
  token; filter per route (mis. `Role::filter(...)`) digabung dengan `jwt`, bukan menggantinya. Dijaga
  `tests/Kepegawaian/RouteRiwayatKontrakTest.php` dan `RoutePegawaiKontrakTest.php`.
- Route riwayat memakai `jwt` saja (tanpa `role:*`); izin per role per jenis dicek service dari Definisi (403), karena
  role berbeda per jenis. Ini **penyimpangan dari §2.3.1 SPRINT0_1_backend.md yang disetujui reviewer CR 07-10-2026**.
  Route WS-2 yang role-nya seragam (mis. `GET pegawai` = 1, 3, 4, 5, 8) memakai `Role::filter(...)` per route.

### Kepemilikan & perubahan

Setelah MAKE-002 di `main`, yang berikut hanya boleh berubah aditif dan oleh pemiliknya; perubahan non-aditif butuh
persetujuan reviewer CR dan pemberitahuan ke WS lain:

1. `PegawaiScopeInterface` — WS-2
2. `AttachmentServiceInterface`, `StorageAdapterInterface` — WS-2
3. `RiwayatDefinisi` (+ `AturanLampiran`, `AturanSnapshot`, `AksiRiwayat`, `AlurRiwayat`, `StatusRiwayat`,
   `JenisRiwayat`) dan API engine — WS-1. `AturanLampiran` tetap di `Libraries/Kepegawaian/Riwayat/` dengan pemilik
   WS-1 (keputusan reviewer CR 07-10-2026); WS-2 memakainya, permintaan perubahan diajukan ke WS-1.
4. Descriptor tab + daftar slug `{jenis}` — WS-1 (descriptor non-engine: WS-2, bentuk sama)
5. Bentuk endpoint & kode status di README ini

Satu-satunya perubahan `Config/Services.php` setelah S0-A: pemilik service mengganti `new Stub…` dengan kelas nyata
(dicatat di commit milestone pemilik). Pemilik interface penanda yang menambah method juga memperbarui stub-nya
(tetap fail-closed, dijaga `tests/unit/Kepegawaian/Stub/StubFailClosedTest.php`) atau menggantinya di `Services.php`
pada commit yang sama.
