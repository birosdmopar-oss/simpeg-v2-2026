# Sprint 0-1 — S0-A Kontrak Backend Fase 3 (MAKE-002) — Qoder-1

**Cara pakai:** baca berkas ini dari atas, kerjakan langkah §0 berurutan, berhenti di setiap **⛔ TITIK TUNGGU**
(ℹ️ = info koordinasi, tidak menghentikan kerja); rincian tiap langkah ada di §2.

| | |
|---|---|
| Urutan | **Dikerjakan PERTAMA** di Fase 3. Sprint 0-2 (frontend, Qoder-2) menunggu paket ini masuk `main` |
| Pelaksana | Qoder-1 (nanti melanjutkan WS-1, `docs/fase3/WS1.md`) |
| Key | **MAKE-002** (tanpa perubahan skema) |
| Branch | `ws1/make-002-s0a-kontrak-backend` dari `origin/main` terbaru |
| Estimasi | ±1 hari-agen |
| Reviewer | Reviewer CR = sesi utama (review kode, memantau CI, memasukkan paket ke `main`) |
| Hasil | Satu branch berisi commit MAKE-002, CI hijau, diserahkan ke reviewer CR. **Jangan push ke `main`.** |

---

## §0 MULAI DI SINI — Runbook langkah demi langkah

Aturan umum untuk setiap langkah: §2.8 (worktree, DB scratch, gate, commit, rahasia, tanpa push ke `main`).

### 0.A Persiapan

- [ ] 1. Buat worktree + branch dari `main` terbaru (jangan bekerja di checkout milik orang lain):
  `git fetch origin` lalu `git worktree add -b ws1/make-002-s0a-kontrak-backend <folder-worktree> origin/main`.
- [ ] 2. Pasang dependensi di worktree: `cd backend && composer install`, lalu `cd frontend && npm ci`.
- [ ] 3. Buat DB scratch sendiri, collation `utf8mb4_unicode_ci`: `simpeg_v2_ws1_dev` dan `simpeg_v2_ws1_testing`.
- [ ] 4. Tulis `backend/.env` dari `backend/.env.example` (**jangan menyalin `.env` dev** dari tempat lain). Arahkan
  `database.default` → `simpeg_v2_ws1_dev` dan `database.tests` → `simpeg_v2_ws1_testing`; periksa ulang sebelum
  `php spark migrate` atau PHPUnit pertama.
- [ ] 5. Baca §1 (tabel tunggu) dan §2 (briefing).

### 0.B Kerjakan S0-A

- [ ] 6. Route terpisah: `Routing.php` `$routeFiles` + `RoutesRiwayat.php` / `RoutesPegawai.php` (§2.3.1).
- [ ] 7. `Config/Services.php` — daftarkan 12 service sekali; stub fail-closed di `app/`, fake di `tests/_support`
  (§2.3.2).
- [ ] 8. Interface + fake: `PegawaiScopeInterface`, `AttachmentServiceInterface`, `StorageAdapterInterface`,
  `AturanLampiran` (§2.3.3).
- [ ] 9. `RiwayatDefinisi` (auto-discovery), `StatusRiwayat`, `Config/Kepegawaian.php` (flag status 3),
  `RiwayatRegistry` stub + descriptor tab (§2.3.4, §2.3.5).
- [ ] 10. Kontrak API di `backend/app/Controllers/Api/Kepegawaian/README.md` (§2.3.7): endpoint, descriptor, daftar slug
  `{jenis}`, kode status, snake_case = kolom DDL. Commit, lalu `git push -u origin ws1/make-002-s0a-kontrak-backend`.
  ℹ️ Qoder-2 boleh membaca draf ini dari branch Anda untuk persiapan, tetapi hanya versi yang masuk `main` yang
  mengikat (§2.10).
- [ ] 11. Fixture `PegawaiFixtureTrait` (§2.3.6): hanya-INSERT, NIP sintetis, patuh FK DBV-019 (buat baris master G-02
  yang dirujuk; DBV-019 tidak perlu ditunggu merge-nya).
  **⛔ TITIK TUNGGU 1 — base case MAKE-001.** Cek: `git fetch origin && git log origin/main --oneline | grep MAKE-001`.
  - Ada → `git rebase origin/main`, pakai base case MAKE-001 di fixture & test.
  - Belum ada → tulis fixture agar bisa dipakai base case itu tanpa perubahan (tanpa DDL/`migrate`/commit/`TRUNCATE`),
    lanjutkan langkah 12–14, lalu cek ulang sebelum langkah 15. Bila masih belum ada saat serah, catat di laporan serah.
- [ ] 12. Pecah progres `backend/docs/progress/03-Kepegawaian.md` per task dengan kolom WS & Key (§2.3.8).
- [ ] 13. Test minimum S0-A (§2.3.9).
- [ ] 14. Gate cepat: `cd backend && composer analyse && composer cs-check` +
  `vendor/bin/phpunit --no-coverage tests/Kepegawaian tests/unit/Kepegawaian`; `php spark routes` tidak error.
- [ ] 15. Periksa DoD (§2.7) dan berkas terlarang: `git diff --stat origin/main...HEAD` dibandingkan dengan §2.5.
  Commit `feat(kepegawaian): MAKE-002 …` (Bahasa Indonesia, tanpa trailer, tanpa menyebut AI).

### 0.C Serah

- [ ] 16. Push **branch fitur** ke `origin` — **bukan** `main`: `git push origin ws1/make-002-s0a-kontrak-backend`.
- [ ] 17. Tunggu workflow CI `quality-gate` (GitHub Actions) untuk SHA itu sampai hijau. CI = gate penuh resmi
  (AGENTS.md §3); gate penuh lokal tidak wajib.
- [ ] 18. Lapor ke reviewer CR: branch, SHA (`git rev-parse HEAD`), key MAKE-002, ringkasan, tautan run CI untuk SHA
  itu, hasil gate cepat, kontrak yang dibekukan, keputusan desain yang Anda ambil, status MAKE-001 (⛔1), kebutuhan
  skema bila ada (jangan buat migration), hal yang perlu diketahui Qoder-2.
- [ ] 19. **⛔ TITIK TUNGGU 2 — tunggu review CR + CI hijau + merge MAKE-002 ke `main`.** Temuan review diperbaiki di
  branch yang sama (key perbaikan mengikuti arahan reviewer, AGENTS.md §2), push ulang, ulangi langkah 17–18. Setelah
  merge, kontrak backend **beku** (§2.6) dan Sprint 0-1 selesai.
- [ ] 20. ℹ️ Lanjutan untuk Anda: `Kerjakan docs/fase3/WS1.md` — mulai M1 hanya setelah MAKE-002 **dan** MAKE-003 di
  `main` (titik tunggu pertama di berkas itu).

---

## §1 Status prasyarat Sprint 0 (tabel tunggu)

Urutan build: **Sprint 0-1 (backend) → Sprint 0-2 (frontend) → WS-1 dan WS-2 paralel.**

| No | Item | Key | Dikerjakan | Menunggu | Membuka jalan |
|---|---|---|---|---|---|
| 1 | PHPUnit cepat + MySQL test (base case: migrate sekali + transaksi per test) | MAKE-001 | Sesi utama | — | Fixture Sprint 0-1 dirapikan di atas base case ini |
| 2 | FK G-02 ↔ pegawai/riwayat (PR #23) | DBV-019 | Sesi utama → review DB Validator | Review/approve DB Validator | Fixture wajib patuh FK-nya sejak awal; **tidak** perlu menunggu merge |
| 3 | Sprint 0-1 — S0-A Kontrak backend (`SPRINT0_1_backend.md`) | MAKE-002 | Qoder-1, ±1 hari-agen | Fixture menunggu MAKE-001 (No 1) | Sprint 0-2 bagian yang bergantung kontrak |
| 4 | Review CR S0-A + CI hijau + merge → **kontrak backend beku** | MAKE-002 | Sesi utama, ±1–2 jam setelah diserahkan | No 3 diserahkan | No 5 |
| 5 | Sprint 0-2 — S0-B Fondasi frontend (`SPRINT0_2_frontend.md`) | MAKE-003 | Qoder-2, ±1,5 hari-agen | S0-A di `main` (No 4); persiapan tanpa kontrak boleh duluan | No 6 |
| 6 | Review CR S0-B + CI hijau + merge → **fondasi frontend beku** | MAKE-003 | Sesi utama, ±1–2 jam setelah diserahkan | No 5 diserahkan | No 7 |
| 7 | WS-1 (`WS1.md`, Qoder-1) dan WS-2 (`WS2.md`, Qoder-2) mulai paralel | MAKE-004..014 | Qoder-1, Qoder-2 | MAKE-002 **dan** MAKE-003 di `main` | — |

Istilah:
- **hari-agen** — ukuran beban kerja satu sesi coder (relatif), bukan tanggal kalender.
- **fixture** — data contoh untuk test (pegawai, akun, master G-02) yang dibuat oleh helper test, bukan data asli.
- **kontrak beku** — setelah paket Sprint 0 masuk `main`, interface/API/route/registry bersama hanya boleh
  **ditambah** (aditif) oleh pemiliknya; perubahan non-aditif butuh persetujuan reviewer CR dan pemberitahuan ke WS
  lain.

---

## §2 Briefing S0-A

### 2.1 Konteks singkat

Fase 3 (Modul B — Kepegawaian Core) dibangun oleh dua workstream paralel:

- **WS-1 (Anda):** mesin riwayat generik (RiwayatEngine + SnapshotSync) dan jenis riwayat karier/administrasi
  (Pendidikan, KP, KGB, Hukdis, Keluarga/Alamat, Diklat/Seminar, Tanda Jasa/Organisasi/Karpeg/Karis, SKP, AK).
- **WS-2 (Qoder-2):** lingkup akses (`PegawaiScope`), lampiran (B-18), koreksi NIP, biodata, jabatan/struktur, Konket,
  LKH, halaman pegawai, dan fondasi frontend.

Skema seluruh tabel Fase 3 (44 tabel B-01/B-02) **sudah ada di `main`** lewat migration `2026-09-30-12*`/`13*`. Tabel
master G-02 (unit, satker, jabatan, dst.) juga sudah di `main`.

Sprint 0 ada supaya kedua WS bisa bekerja paralel tanpa bentrok berkas. Sprint 0 dikerjakan **berurutan**: Anda
(Sprint 0-1) **membekukan kontrak backend** (interface, stub, fake, fixture, kontrak API) lebih dulu; setelah kontrak
itu masuk `main`, Qoder-2 (Sprint 0-2, `docs/fase3/SPRINT0_2_frontend.md`) menyiapkan fondasi frontend di atasnya. Setelah
S0, berkas bersama (`Routes.php`, `Services.php`) tidak disentuh lagi, dan setiap WS hanya mengedit berkas miliknya.

### 2.2 Tujuan S0-A

1. Route Fase 3 dipisah ke dua berkas milik masing-masing WS.
2. Semua service Fase 3 terdaftar **sekali** di `Config/Services.php` (stub/fake), sehingga implementasi nyata cukup
   mengganti kelas di balik service tanpa menyentuh `Services.php` lagi.
3. Interface lintas-WS dibekukan: `PegawaiScope`, `AttachmentService`, `StorageAdapter`, `RiwayatDefinisi`,
   `StatusRiwayat`, descriptor tab.
4. Fixture test pegawai bersama tersedia untuk kedua WS.
5. Kontrak REST API Modul B tertulis, sehingga frontend (Qoder-2) bisa menyelaraskan tipe dan klien API sejak hari
   pertama.

### 2.3 Cakupan tepat

#### 2.3.1 Berkas route terpisah
- `backend/app/Config/Routing.php`: tambahkan ke `$routeFiles` (properti sudah ada, saat ini hanya berisi `Routes.php`):
  - `APPPATH . 'Config/RoutesRiwayat.php'` (milik WS-1)
  - `APPPATH . 'Config/RoutesPegawai.php'` (milik WS-2)
- Buat kedua berkas dengan grup `api/v1` + namespace `App\Controllers\Api\Kepegawaian` mengikuti gaya `Routes.php`
  (filter `jwt` + `Role::filter(...)` dari `App\Constants\Role`). Isi S0: kerangka grup + komentar kontrak; endpoint
  nyata ditambahkan pemiliknya di milestone berikutnya. Endpoint yang sudah didaftarkan di S0 wajib menjawab
  **501/404 yang terkendali**, bukan 500.
- Setelah S0, `Routes.php` tidak disentuh lagi oleh WS mana pun.

#### 2.3.2 `Config/Services.php` — disentuh sekali
Daftarkan method service berikut (pola sama dengan `masterService()`/`hariLiburService()` yang sudah ada,
`bool $getShared = true`):

`pegawaiScope`, `attachmentService`, `storageAdapter`, `riwayatRegistry`, `riwayatService`, `snapshotSync`,
`pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService`.

Aturan stub:
- Stub produksi ditaruh di `app/` (mis. `app/Libraries/Kepegawaian/Stub/`), **fail-closed**: `PegawaiScope` stub
  menolak semua akses; service yang belum diimplementasi melempar exception yang dipetakan ke 501 "belum tersedia".
- Fake untuk test ditaruh di `tests/_support/Kepegawaian/` dan disuntik di test lewat `Services::injectMock(...)`. Fake
  **tidak** pernah dipakai di kode produksi.
- Tipe kembalian method service = interface (bukan kelas konkret), supaya WS-2/WS-1 cukup mengganti kelas di balik
  `new ...` pada milestone berikutnya. Penggantian baris `new Stub...` → `new ...Nyata` di `Services.php` oleh pemilik
  service adalah **satu-satunya** perubahan `Services.php` yang diizinkan setelah S0, dan dicatat di commit milestone
  pemilik.

#### 2.3.3 Interface + fake

Lokasi interface mengikuti konvensi repo (`app/Interfaces/`, akhiran `Interface`), di sub-folder
`app/Interfaces/Kepegawaian/`. Nama dan signature di bawah adalah usulan; Anda boleh memperhalus selama tercatat di
README kontrak (§2.3.7) dan disetujui reviewer CR sebelum dibekukan.

| Interface | Isi minimum | Fake (test) |
|---|---|---|
| `PegawaiScopeInterface` | `bolehLihat(AuthContext, string $nip): bool`; `bolehUbah(AuthContext, string $nip): bool`; `terapkanKeQuery(BaseBuilder, AuthContext, string $kolomNip = 'nip'): void` (filter daftar sesuai lingkup); semantik: di luar lingkup → pemanggil mengembalikan 403 | `FakePegawaiScope` — izinkan semua, atau dikonfigurasi per test (daftar NIP yang diizinkan) |
| `AttachmentServiceInterface` | `simpan(nip, idRiwayat /*kode jenis_rwy*/, idEntri, UploadedFile, AturanLampiran): array` (baris `document_attachment`); `hapus(int $idAttachment): void` (hapus keras + audit); `daftar(nip, idRiwayat, idEntri): list<array>`; dipanggil **di dalam transaksi pemanggil**; kompensasi berkas bila transaksi rollback | `FakeAttachmentService` (memori) |
| `StorageAdapterInterface` | `simpan(string $path, string $isi): void`, `hapus(string $path): void`, `ada(string $path): bool`, `baca(string $path): string` | `FakeStorageAdapter` (memori) |
| `AturanLampiran` (value object, bukan interface) | wajib/tidak, kode `jenis_rwy`, batas MB **per jenis (1/2/5 MB ikut legacy)**, daftar ekstensi | — |

Implementasi nyata `PegawaiScope` dan `AttachmentService`/`StorageAdapter` **milik WS-2** (bukan pekerjaan Anda).

#### 2.3.4 Bentuk `RiwayatDefinisi`, `StatusRiwayat`, flag status 3
- `RiwayatDefinisi`: **satu berkas per jenis** di `app/Libraries/Kepegawaian/Riwayat/Definisi/*.php`, dibaca lewat
  **auto-discovery** (memindai folder; tidak ada daftar terpusat yang harus diedit WS lain). Isi kontrak (sebagai data,
  bukan kode khusus per jenis):
  - kunci jenis (slug URL, lihat §2.3.7), label, tabel + PK, kolom `nip`;
  - field (pola `App\Libraries\MasterData\MasterField`);
  - **izin per aksi per role** (lihat/tambah/ubah/hapus/proses) — hak akses mengikuti Matriks v2
    (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`) dan disimpan di sini, sehingga perubahan cukup satu baris;
  - alur: `self-service` (pegawai mengajukan, status 0), `admin` (input langsung status 1), `usulan` (halaman usulan
    mandiri);
  - pemetaan domain status;
  - aturan snapshot sebagai data: tabel target, filter, urutan (mengacu dok DBV-012 §5.1), multi-target;
  - aturan lampiran (`AturanLampiran`);
  - hook opsional: `validate`, `beforeSave`, `afterApprove`.

  S0 cukup menghasilkan kelas abstrak/interface + **satu Definisi contoh untuk test** (di `tests/_support`), bukan
  Definisi jenis nyata.
- `StatusRiwayat` (enum PHP): 0 = Menunggu, 1 = Disetujui, 2 = Ditolak, 10 = Dihapus; nilai 3 "Diproses" ada tetapi
  hanya berlaku bila flag aktif.
- Flag status 3: `app/Config/Kepegawaian.php` (baru) dengan properti boolean `statusDiprosesAktif = false` (nonaktif
  default).
- `RiwayatRegistry` stub: `descriptorUntuk(AuthContext $auth, string $nip): list<array>` — mengembalikan daftar
  descriptor tab dari Definisi yang terdaftar (S0: daftar kosong atau dari Definisi contoh di test).

#### 2.3.5 Bentuk descriptor tab (dibekukan)
```json
{ "jenis": "pendidikan", "label": "Riwayat Pendidikan",
  "can_view": true, "can_create": true, "can_edit": true, "can_delete": false, "can_process": false }
```
Dihitung dari izin Definisi × role pemanggil × `PegawaiScope`. Frontend merender tab dan tombol hanya dari descriptor
ini.

#### 2.3.6 Fixture `tests/_support/Kepegawaian/PegawaiFixtureTrait.php` (berkas baru)
- Membuat: baris master G-02 yang dirujuk (unit kerja, satker, jabatan, dan master lain yang dirujuk kolom terisi) →
  `pegawai` → snapshot `pegawai_mutasi_jabatan` ber-unit/satker → akun `pengguna` tertaut NIP. Helper minimal:
  `buatPegawai(array $override = []): string` (mengembalikan NIP), `buatAkunUntuk(string $nip, int $role): int`,
  `buatPegawaiDiSatker(...)` untuk test lingkup role 3.
- **Wajib mematuhi FK DBV-019** (FK G-02 ↔ pegawai/riwayat yang dipasang sesi utama): jangan mengisi kolom FK dengan
  ID master yang tidak ada; buat baris masternya di fixture.
- **Wajib cocok dengan base case MAKE-001** (migrate sekali + transaksi per test): fixture **hanya INSERT** lewat query
  builder, tanpa DDL, tanpa `migrate`, tanpa commit eksplisit, tanpa `TRUNCATE`. Urutan kerja terhadap MAKE-001: lihat
  ⛔ TITIK TUNGGU 1 di §0.
- Gunakan NIP sintetis 18 digit (bukan data asli).

#### 2.3.7 Kontrak API — `backend/app/Controllers/Api/Kepegawaian/README.md`
Tulis (bagian baru; isi lama dipertahankan) kontrak berikut. Envelope mengikuti modul lain: sukses
`{status:'success', data}`, gagal `{status:'error', message, errors?}`.

| Method & path (`api/v1/...`) | Pemilik | Keterangan |
|---|---|---|
| `GET pegawai` | WS-2 | Daftar pegawai (filter lingkup) |
| `GET pegawai/{nip}` | WS-2 | Detail + `tabs` (array descriptor §2.3.5) |
| `GET pegawai/{nip}/riwayat/{jenis}` | WS-1 | Daftar baris riwayat |
| `GET pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Satu baris |
| `POST pegawai/{nip}/riwayat/{jenis}` | WS-1 | Tambah; status 0 untuk UL_PEGAWAI (role 2/6/7), 1 untuk admin |
| `PUT pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Ubah (baris yang sudah disetujui dijaga sesuai Definisi) |
| `DELETE pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Hapus lunak → status 10 |
| `POST pegawai/{nip}/riwayat/{jenis}/{id}/process` | WS-1 | Body `{aksi: "setujui"\|"tolak", reason_note}`; `reason_note` wajib saat tolak |
| `GET/POST pegawai/{nip}/lampiran`, `GET .../lampiran/{id}/unduh`, `DELETE .../lampiran/{id}` | WS-2 | Lampiran (`id_riwayat` = kode `jenis_rwy`, `id_entri` = PK baris riwayat) |

Aturan kontrak:
- Payload & respons **snake_case = nama kolom DDL** (tidak ada alias camelCase).
- Kode: 401 tanpa token, **403** role/lingkup tidak berhak, **404** NIP/baris/jenis tidak ada, **422** validasi
  (`errors` per field), 501 belum tersedia.
- Approve/reject dicatat di `audit_logs` sebagai aksi `update` (before/after).
- Waktu disimpan UTC.
- **Daftar slug `{jenis}` (dibekukan, dipakai juga sebagai nama berkas registry frontend):** `jabatan`, `kp`, `kgb`,
  `pendidikan`, `diklat`, `seminar`, `skp`, `skp-periodik`, `hukdis`, `ak`, `ak-siasn`, `keluarga`, `alamat`,
  `tanda-jasa`, `organisasi`, `karpeg`, `kariskarsu`. Konket dan LKH **bukan** jenis engine (endpoint sendiri milik
  WS-2). `karpeg`/`kariskarsu` dipakai halaman usulan mandiri, bukan tab Detail Pegawai.

#### 2.3.8 Progres — `backend/docs/progress/03-Kepegawaian.md`
- Pecah tabel status menjadi **satu baris per task** (B-01..B-21) dengan kolom **WS** (WS-1/WS-2/selesai) dan **Key**
  (sesuai peta key `docs/fase3/WS1.md` §3.8.1). Tiap WS hanya mengubah barisnya sendiri setelah ini.
- Perbarui baris "Entry criteria": Fase 1 + Fase 2 DONE (Fase 2 sign-off 07-10-2026), skema B-01/B-02 di `main`.
- Tambahkan bagian singkat "S0-A — MAKE-002" (apa yang dibekukan).

#### 2.3.9 Test minimum S0-A
- Unit: `StatusRiwayat` (nilai, flag status 3 mati default), auto-discovery Definisi (Definisi contoh terbaca; folder
  kosong aman), descriptor dari Definisi contoh × role.
- Feature/integrasi ringan: semua service di §2.3.2 dapat di-resolve dan bertipe interface; stub `PegawaiScope`
  menolak; fixture membuat pegawai + pmj + akun yang lolos FK.
- Route: kedua berkas route termuat (`php spark routes` tidak error); endpoint yang didaftarkan di S0 tidak
  menghasilkan 500.

### 2.4 Di luar cakupan S0-A
SnapshotSync, RiwayatEngine, BaseRiwayatController (milestone M1, MAKE-004); Definisi jenis nyata; implementasi nyata
`PegawaiScope`/lampiran (WS-2); semua frontend; migration/perubahan skema apa pun.

### 2.5 Berkas S0-A

| BOLEH disentuh (S0-A) | DILARANG |
|---|---|
| `backend/app/Config/Routing.php` (hanya `$routeFiles`) | **Semua migration yang sudah ada di `main`** (`app/Database/Migrations/*`) — tidak diedit, tidak dihapus |
| `backend/app/Config/RoutesRiwayat.php`, `RoutesPegawai.php` (baru; isi `RoutesPegawai.php` hanya kerangka) | `backend/app/Config/Routes.php` |
| `backend/app/Config/Services.php` (sekali, §2.3.2) | `_support` milik PR #20: `tests/_support/LepasMigrationKepegawaianTrait.php`, `tests/_support/SkemaKepegawaianTestTrait.php`, `tests/_support/Kepegawaian/SkemaD1.php`, `tests/_support/Kepegawaian/SkemaD1TestTrait.php` |
| `backend/app/Config/Kepegawaian.php` (baru) | Berkas base case MAKE-001 dan migration DBV-019 (milik sesi utama) |
| `backend/app/Interfaces/Kepegawaian/**` (baru) | `app/Libraries/Kepegawaian/Kalkulasi/**`, `app/Libraries/Kepegawaian/Nip/**` (sudah di `main`, CR-025/CR-036; hanya dipakai) |
| `backend/app/Libraries/Kepegawaian/Riwayat/**` (kerangka: Definisi, StatusRiwayat, Registry stub) | `composer.json`/`composer.lock` (milik WS-2) |
| `backend/app/Libraries/Kepegawaian/Stub/**` (baru) | Seluruh `frontend/**` (milik S0-B) |
| `backend/tests/_support/Kepegawaian/PegawaiFixtureTrait.php`, `Fake*.php`, Definisi contoh (baru) | `.env`, berkas kredensial apa pun |
| `backend/tests/Kepegawaian/**`, `backend/tests/unit/Kepegawaian/**` (test baru S0) | |
| `backend/app/Controllers/Api/Kepegawaian/README.md`, `backend/docs/progress/03-Kepegawaian.md` | |

Butuh perubahan skema? **Jangan** membuat migration. Catat kebutuhan di laporan serah; sesi utama mengalokasikan key
DBV baru.

### 2.6 Kontrak yang dibekukan di akhir S0
Setelah MAKE-002 di-merge, berikut hanya boleh berubah **secara aditif** dan **oleh pemiliknya**:
1. `PegawaiScopeInterface` (pemilik implementasi: WS-2)
2. `AttachmentServiceInterface` / `StorageAdapterInterface` / `AturanLampiran` (pemilik: WS-2)
3. `RiwayatDefinisi` + API engine (pemilik: WS-1)
4. Descriptor tab (§2.3.5) + daftar slug `{jenis}` (pemilik: WS-1) — frontend membekukan pasangan props
   `RiwayatTabHost` di S0-B
5. Bentuk endpoint & kode status di README (§2.3.7)

Perubahan non-aditif setelah beku harus disetujui reviewer CR dan diberitahukan ke WS lain.

### 2.7 Definition of Done S0-A
- [ ] §2.3.1–§2.3.8 selesai; `php spark routes` memuat kedua berkas route.
- [ ] Semua service §2.3.2 terdaftar, bertipe interface, stub fail-closed; fake hanya di `tests/_support`.
- [ ] `PegawaiFixtureTrait` membuat data yang lolos FK (termasuk FK DBV-019 bila sudah di `main`) dan cocok dengan base
  case MAKE-001.
- [ ] README kontrak API lengkap (endpoint, descriptor, slug, kode status, snake_case, audit `update`).
- [ ] Progres 03-Kepegawaian dipecah per task dengan kolom WS & Key.
- [ ] Test §2.3.9 hijau.
- [ ] Gate cepat hijau selama kerja; **CI `quality-gate` hijau** pada SHA yang diserahkan (tautan run di laporan serah).
- [ ] Commit sesuai §2.8; tidak ada berkas terlarang (§2.5) yang berubah (`git diff --stat origin/main...HEAD`).
- [ ] Laporan serah berisi: ringkasan, daftar kontrak yang dibekukan, keputusan desain yang Anda ambil, hal yang perlu
  diketahui Qoder-2.

### 2.8 Aturan kerja wajib
1. **Worktree sendiri** dari `origin/main` (jangan bekerja di checkout milik orang lain). Setelah membuat worktree:
   `cd backend && composer install`, `cd frontend && npm ci` (frontend dibutuhkan gate).
2. **DB scratch sendiri**: `simpeg_v2_ws1_dev` dan `simpeg_v2_ws1_testing` (collation `utf8mb4_unicode_ci`). Tulis
   `.env` worktree sendiri dari `backend/.env.example`; **jangan menyalin `.env` dev** dari tempat lain. Pastikan
   `database.default` dan `database.tests` menunjuk DB scratch Anda sebelum `spark migrate` atau PHPUnit.
3. **Gate cepat** selama kerja (±5–10 menit): `cd backend && composer analyse && composer cs-check`; PHPUnit hanya
   berkas/folder yang Anda sentuh, mis. `vendor/bin/phpunit --no-coverage tests/Kepegawaian tests/unit/Kepegawaian`;
   bila frontend berubah: `cd frontend && npm run check`.
4. **Gate penuh = CI** (AGENTS.md §3): push branch, tunggu workflow `quality-gate` hijau untuk SHA itu. Gate penuh lokal
   (`./check.sh`) tidak wajib; bila dijalankan, jalankan sebagai proses lepas dengan log ke berkas
   (`nohup ./check.sh > gate.log 2>&1 &`, atau `Start-Process` di PowerShell), satu gate penuh per mesin.
5. **Commit**: Bahasa Indonesia, gaya `feat(kepegawaian): MAKE-002 …` / `test(kepegawaian): MAKE-002 …` /
   `docs(kepegawaian): MAKE-002 …`. **Tanpa** trailer `Co-Authored-By` dan tanpa menyebut
   AI/assistant/tool apa pun di pesan commit, komentar, atau dokumen.
6. **Rahasia & repo publik**: jangan commit `.env`, kredensial, token, password, API key; jangan menulis IP/host
   internal, nama server, atau detail celah keamanan di kode/dokumen.
7. **Tidak push ke `main`.** Serahkan dengan push branch fitur `ws1/…` ke `origin` (§0.C). Reviewer CR (sesi utama)
   memasukkan ke `main` (alur CR/MAKE tanpa skema).
8. **UI** (tidak relevan untuk S0-A; berlaku di WS): aksi baris tabel lewat menu ⋮ (`RowActionsMenu`, AGENTS.md §1).
9. Hanya mengedit berkas yang diizinkan (§2.5). Bila ada aturan di berkas ini yang bertentangan dengan AGENTS.md atau kode
   di `main`, **tanyakan reviewer CR** sebelum menyimpang.

### 2.9 Estimasi S0-A
| Bagian | ±Jam-agen |
|---|---|
| Routing + Services + stub | 1,5 |
| Interface + fake + StatusRiwayat + Config flag | 2 |
| RiwayatDefinisi + discovery + Registry stub + descriptor | 1,5 |
| PegawaiFixtureTrait (FK G-02/DBV-019, base case MAKE-001) | 1,5 |
| README kontrak + progres | 1 |
| Test + gate cepat + menunggu CI | 0,5 + waktu CI |
| **Total** | **±1 hari-agen** |

### 2.10 Koordinasi dengan Qoder-2 (Sprint 0-2)
| Kapan | Apa | Arah |
|---|---|---|
| Selama Sprint 0-1 | Qoder-2 hanya mengerjakan langkah yang tidak bergantung kontrak (port layout/shell, komponen shared). Bila Qoder-2 bertanya, jawab lewat README kontrak di branch Anda yang sudah di-push; jangan menyentuh frontend | Qoder-1 → Qoder-2 |
| Serah S0-A | Pastikan README kontrak (§2.3.5 descriptor, §2.3.7 endpoint + daftar slug `{jenis}`, snake_case = kolom DDL) final — Qoder-2 menyelaraskan tipe TS, nama berkas `riwayat/jenis/<slug>.ts`, dan klien API dari versi yang masuk `main` | Qoder-1 → Qoder-2 |
| Setelah MAKE-002 merge | Kontrak beku; Qoder-2 mulai bagian Sprint 0-2 yang bergantung kontrak | — |
| Setelah S0 | Permintaan perubahan kontrak/hook diajukan ke pemiliknya (§2.6), tidak mengedit berkas WS lain | dua arah |

Qoder-2 **tidak** menyentuh backend di Sprint 0; Anda **tidak** menyentuh frontend.

### 2.11 Keputusan yang sudah final (jangan ditanyakan ulang)
1. Build berangkat dari `main` (skema B-01/B-02 PR #20, G-02, G-03, G-09 sudah di `main`; Fase 2 sign-off 07-10-2026).
2. Tidak ada branch integrasi: tiap paket branch sendiri dari `main`, masuk `main` setelah review CR + CI hijau.
3. Pembagian: B-18 di WS-2, B-15 di WS-1, B-12 dipecah (SKP WS-1, LKH WS-2), B-20 dipecah (dasar di B-03, penutup di
   akhir WS-2).
4. Hak akses mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; Hukdis role 1/3; Jabatan & AK sesuai Matriks;
   Karpeg/Karis role 1, 2, 4, 5, 7 sesuai DoD), **kecuali** approver LKH = atasan langsung (ikut legacy). Izin disimpan
   sebagai data di Definisi per jenis.
5. Konket dan Karpeg/Karis = halaman usulan mandiri (bukan tab), layout legacy, style redesign.
6. Batas lampiran per jenis 1/2/5 MB ikut legacy.
7. PDF memakai TCPDF (ikut legacy) lewat ADR-033 (`docs/adr/ADR-033-library-pdf-tcpdf.md`; lisensi LGPL-3.0-or-later);
   cadangan dompdf bila TCPDF bermasalah.
8. Status 3 "Diproses" di belakang flag, nonaktif default.
9. Test yang diwajibkan DoD + gate = bagian build; QA Lapis 1/review/sesi QA tidak.
10. Default ikut legacy: acuan jarak KGB = KP/KGB terakhir; cascade NIP ikut `update_nip` legacy
    (+ `jabatan_koordinasi.nip`); masa hukdis = `masa_sanksi_bulan`; aturan lingkup unit destinasi 21 / unit lain 7.
11. Key: satu key per paket; S0-A = **MAKE-002**, S0-B = **MAKE-003** (peta lengkap `docs/fase3/WS1.md` §3.8.1).
12. Aturan proyek: PK pegawai = `nip`; status data 1/2/10 (riwayat 0/1/2/10); collation `utf8mb4_unicode_ci`; skema ikut
    DDL legacy; migration di `main` tidak diedit; perubahan skema lewat key DBV dan review DB Validator; snapshot hanya
    disinkronkan di approval final (ADR-006), termasuk saat baris aktif ditolak/dihapus; trigger legacy tidak dibawa
    (menjadi aturan aplikasi); hanya `LocalStorageAdapter` (arsip remote legacy tidak disalin).
13. Serah-terima (07-10-2026): **push branch fitur `ws1/…` ke `origin`** (tidak ke `main`); review CR oleh sesi utama,
    yang kemudian memasukkan paket ke `main`.
14. Urutan Sprint 0 (07-10-2026): Sprint 0-1 (backend, Anda) dulu; Sprint 0-2 (frontend, Qoder-2) menyelesaikan bagian
    yang bergantung kontrak setelah MAKE-002 di `main`.
15. Menu Fase 3 tersembunyi dengan `ACTIVE_PHASE = 2` sampai B-20 penutup; WS-2 yang menaikkan ke 3.
16. Slug `{jenis}` dan signature interface boleh diperhalus selama Sprint 0-1 (dicatat di README kontrak §2.3.7,
    disetujui reviewer CR), lalu dibekukan saat MAKE-002 di-merge (§2.6).

---

## Lampiran — rujukan

| Dokumen | Isi |
|---|---|
| `docs/fase3/README.md` | Urutan perintah Fase 3, tabel tunggu ringkas, reviewer |
| `docs/fase3/SPRINT0_2_frontend.md` | Sprint 0-2 (Qoder-2), dikerjakan setelah paket ini di `main` |
| `docs/fase3/WS1.md` | Runbook + PRD WS-1 (lanjutan Anda setelah Sprint 0); peta key §3.8.1 |
| `docs/fase3/MATRIKS_ROLE_MODUL_B.md` | Matriks role × endpoint Modul B (izin di Definisi) |
| `docs/fase3/03-Kepegawaian.md` | Kontrak task Fase 3 (B-01..B-21, DoD per task) |
| `docs/adr/ADR-033-library-pdf-tcpdf.md` | Library PDF TCPDF (milik WS-2, informasi) |
| `AGENTS.md` | Aturan proyek: menu ⋮ (§1), commit/key/rahasia/merge (§2), gate cepat & CI (§3) |
| `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md` | Skema B-01/B-02, aturan snapshot §5.1 |
| `backend/docs/progress/03-Kepegawaian.md` | Progres Fase 3 (dipecah per task di §2.3.8) |
| `backend/app/Libraries/Kepegawaian/README.md` | Library Kepegawaian yang sudah ada (Kalkulasi, Nip) |
