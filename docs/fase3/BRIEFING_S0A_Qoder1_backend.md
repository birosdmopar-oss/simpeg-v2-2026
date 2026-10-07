# Briefing S0-A — Kontrak Backend Fase 3 (Qoder-1, WS-1)

| | |
|---|---|
| Penerima | Qoder-1 (pelaksana WS-1 "Mesin Riwayat & Riwayat Karier/Administrasi") |
| Key review | **CR-046** (CR saja, tanpa perubahan skema) |
| Branch | `ws1/cr-046-s0a-kontrak-backend-fase3`, dibuat dari `origin/main` terbaru |
| Estimasi | **±1 hari-agen** (hari 0–1 Sprint 0) |
| Berjalan paralel dengan | S0-B Qoder-2 (fondasi frontend, CR-047) — lihat `BRIEFING_S0B_Qoder2_frontend.md` |
| Acuan | `docs/fase3/PRD_WS1.md`, `docs/fase3/PRD_WS2.md`, `AGENTS.md`, `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md` (skema B-01/B-02, aturan snapshot §5.1), `backend/docs/progress/03-Kepegawaian.md`, `backend/app/Libraries/Kepegawaian/README.md`, `docs/fase3/03-Kepegawaian.md` (kontrak task Fase 3), `docs/fase3/MATRIKS_ROLE_MODUL_B.md` (hak akses Modul B), `docs/adr/ADR-033-library-pdf-tcpdf.md` (library PDF) |
| Hasil yang diserahkan | Satu branch berisi commit CR-046, lolos gate penuh, diserahkan ke reviewer CR. **Jangan push ke `main`.** |

---

## 1. Konteks singkat

Fase 3 (Modul B — Kepegawaian Core) dibangun oleh dua workstream paralel:

- **WS-1 (Anda):** mesin riwayat generik (RiwayatEngine + SnapshotSync) dan jenis riwayat karier/administrasi (Pendidikan, KP, KGB, Hukdis, Keluarga/Alamat, Diklat/Seminar, Tanda Jasa/Organisasi/Karpeg/Karis, SKP, AK).
- **WS-2 (Qoder-2):** lingkup akses (`PegawaiScope`), lampiran (B-18), koreksi NIP, biodata, jabatan/struktur, Konket, LKH, halaman pegawai, dan fondasi frontend.

Skema seluruh tabel Fase 3 (44 tabel B-01/B-02) **sudah ada di `main`** lewat migration `2026-09-30-12*`/`13*`. Tabel master G-02 (unit, satker, jabatan, dst.) juga sudah di `main`.

Sprint 0 ada supaya kedua WS bisa bekerja paralel tanpa saling menunggu dan tanpa bentrok berkas: Anda **membekukan kontrak backend** (interface, stub, fake, fixture, kontrak API), sedangkan Qoder-2 menyiapkan fondasi frontend. Setelah S0, berkas bersama (`Routes.php`, `Services.php`) tidak disentuh lagi, dan setiap WS hanya mengedit berkas miliknya.

## 2. Tujuan S0-A

1. Route Fase 3 dipisah ke dua berkas milik masing-masing WS.
2. Semua service Fase 3 terdaftar **sekali** di `Config/Services.php` (stub/fake), sehingga implementasi nyata cukup mengganti kelas di balik service tanpa menyentuh `Services.php` lagi.
3. Interface lintas-WS dibekukan: `PegawaiScope`, `AttachmentService`, `StorageAdapter`, `RiwayatDefinisi`, `StatusRiwayat`, descriptor tab.
4. Fixture test pegawai bersama tersedia untuk kedua WS.
5. Kontrak REST API Modul B tertulis, sehingga frontend (Qoder-2) bisa menyelaraskan tipe dan klien API sejak hari pertama.

## 3. Cakupan tepat

### 3.1 Berkas route terpisah
- `backend/app/Config/Routing.php`: tambahkan ke `$routeFiles` (properti sudah ada, saat ini hanya berisi `Routes.php`):
  - `APPPATH . 'Config/RoutesRiwayat.php'` (milik WS-1)
  - `APPPATH . 'Config/RoutesPegawai.php'` (milik WS-2)
- Buat kedua berkas dengan grup `api/v1` + namespace `App\Controllers\Api\Kepegawaian` mengikuti gaya `Routes.php` (filter `jwt` + `Role::filter(...)` dari `App\Constants\Role`). Isi S0: kerangka grup + komentar kontrak; endpoint nyata ditambahkan pemiliknya di milestone berikutnya. Endpoint yang sudah didaftarkan di S0 wajib menjawab **501/404 yang terkendali**, bukan 500.
- Setelah S0, `Routes.php` tidak disentuh lagi oleh WS mana pun.

### 3.2 `Config/Services.php` — disentuh sekali
Daftarkan method service berikut (pola sama dengan `masterService()`/`hariLiburService()` yang sudah ada, `bool $getShared = true`):

`pegawaiScope`, `attachmentService`, `storageAdapter`, `riwayatRegistry`, `riwayatService`, `snapshotSync`, `pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService`.

Aturan stub:
- Stub produksi ditaruh di `app/` (mis. `app/Libraries/Kepegawaian/Stub/`), **fail-closed**: `PegawaiScope` stub menolak semua akses; service yang belum diimplementasi melempar exception yang dipetakan ke 501 "belum tersedia".
- Fake untuk test ditaruh di `tests/_support/Kepegawaian/` dan disuntik di test lewat `Services::injectMock(...)`. Fake **tidak** pernah dipakai di kode produksi.
- Tipe kembalian method service = interface (bukan kelas konkret), supaya WS-2/WS-1 cukup mengganti kelas di balik `new ...` pada milestone berikutnya. Penggantian baris `new Stub...` → `new ...Nyata` di `Services.php` oleh pemilik service adalah **satu-satunya** perubahan `Services.php` yang diizinkan setelah S0, dan dicatat di commit milestone pemilik.

### 3.3 Interface + fake

Lokasi interface mengikuti konvensi repo (`app/Interfaces/`, akhiran `Interface`), di sub-folder `app/Interfaces/Kepegawaian/`. Nama dan signature di bawah adalah usulan; Anda boleh memperhalus selama tercatat di README kontrak (§3.7) dan disetujui reviewer CR sebelum dibekukan.

| Interface | Isi minimum | Fake (test) |
|---|---|---|
| `PegawaiScopeInterface` | `bolehLihat(AuthContext, string $nip): bool`; `bolehUbah(AuthContext, string $nip): bool`; `terapkanKeQuery(BaseBuilder, AuthContext, string $kolomNip = 'nip'): void` (filter daftar sesuai lingkup); semantik: di luar lingkup → pemanggil mengembalikan 403 | `FakePegawaiScope` — izinkan semua, atau dikonfigurasi per test (daftar NIP yang diizinkan) |
| `AttachmentServiceInterface` | `simpan(nip, idRiwayat /*kode jenis_rwy*/, idEntri, UploadedFile, AturanLampiran): array` (baris `document_attachment`); `hapus(int $idAttachment): void` (hapus keras + audit); `daftar(nip, idRiwayat, idEntri): list<array>`; dipanggil **di dalam transaksi pemanggil**; kompensasi berkas bila transaksi rollback | `FakeAttachmentService` (memori) |
| `StorageAdapterInterface` | `simpan(string $path, string $isi): void`, `hapus(string $path): void`, `ada(string $path): bool`, `baca(string $path): string` | `FakeStorageAdapter` (memori) |
| `AturanLampiran` (value object, bukan interface) | wajib/tidak, kode `jenis_rwy`, batas MB **per jenis (1/2/5 MB ikut legacy)**, daftar ekstensi | — |

Implementasi nyata `PegawaiScope` dan `AttachmentService`/`StorageAdapter` **milik WS-2** (bukan pekerjaan Anda).

### 3.4 Bentuk `RiwayatDefinisi`, `StatusRiwayat`, flag status 3
- `RiwayatDefinisi`: **satu berkas per jenis** di `app/Libraries/Kepegawaian/Riwayat/Definisi/*.php`, dibaca lewat **auto-discovery** (memindai folder; tidak ada daftar terpusat yang harus diedit WS lain). Isi kontrak (sebagai data, bukan kode khusus per jenis):
  - kunci jenis (slug URL, lihat §3.7), label, tabel + PK, kolom `nip`;
  - field (pola `App\Libraries\MasterData\MasterField`);
  - **izin per aksi per role** (lihat/tambah/ubah/hapus/proses) — hak akses mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`) dan disimpan di sini, sehingga perubahan cukup satu baris;
  - alur: `self-service` (pegawai mengajukan, status 0), `admin` (input langsung status 1), `usulan` (halaman usulan mandiri);
  - pemetaan domain status;
  - aturan snapshot sebagai data: tabel target, filter, urutan (mengacu dok DBV-012 §5.1), multi-target;
  - aturan lampiran (`AturanLampiran`);
  - hook opsional: `validate`, `beforeSave`, `afterApprove`.
  
  S0 cukup menghasilkan kelas abstrak/interface + **satu Definisi contoh untuk test** (di `tests/_support`), bukan Definisi jenis nyata.
- `StatusRiwayat` (enum PHP): 0 = Menunggu, 1 = Disetujui, 2 = Ditolak, 10 = Dihapus; nilai 3 "Diproses" ada tetapi hanya berlaku bila flag aktif.
- Flag status 3: `app/Config/Kepegawaian.php` (baru) dengan properti boolean `statusDiprosesAktif = false` (nonaktif default).
- `RiwayatRegistry` stub: `descriptorUntuk(AuthContext $auth, string $nip): list<array>` — mengembalikan daftar descriptor tab dari Definisi yang terdaftar (S0: daftar kosong atau dari Definisi contoh di test).

### 3.5 Bentuk descriptor tab (dibekukan)
```json
{ "jenis": "pendidikan", "label": "Riwayat Pendidikan",
  "can_view": true, "can_create": true, "can_edit": true, "can_delete": false, "can_process": false }
```
Dihitung dari izin Definisi × role pemanggil × `PegawaiScope`. Frontend merender tab dan tombol hanya dari descriptor ini.

### 3.6 Fixture `tests/_support/Kepegawaian/PegawaiFixtureTrait.php` (berkas baru)
- Membuat: baris master G-02 yang dirujuk (unit kerja, satker, jabatan, dan master lain yang dirujuk kolom terisi) → `pegawai` → snapshot `pegawai_mutasi_jabatan` ber-unit/satker → akun `pengguna` tertaut NIP. Helper minimal: `buatPegawai(array $override = []): string` (mengembalikan NIP), `buatAkunUntuk(string $nip, int $role): int`, `buatPegawaiDiSatker(...)` untuk test lingkup role 3.
- **Wajib mematuhi FK DBV-019** (FK G-02 ↔ pegawai/riwayat yang sedang dipasang sesi utama): jangan mengisi kolom FK dengan ID master yang tidak ada; buat baris masternya di fixture.
- **Wajib cocok dengan base case CR-045** (base case test DB baru: migrate sekali + transaksi per test, dikerjakan sesi utama paralel): fixture **hanya INSERT** lewat query builder, tanpa DDL, tanpa `migrate`, tanpa commit eksplisit, tanpa `TRUNCATE`. Bila CR-045 sudah masuk `main` saat Anda mulai/selesai, rebase dan pakai base case-nya; bila belum, tulis fixture agar bisa dipakai base case itu tanpa perubahan, dan catat di deskripsi serah.
- Gunakan NIP sintetis 18 digit (bukan data asli).

### 3.7 Kontrak API — `backend/app/Controllers/Api/Kepegawaian/README.md`
Tulis (bagian baru; isi lama dipertahankan) kontrak berikut. Envelope mengikuti modul lain: sukses `{status:'success', data}`, gagal `{status:'error', message, errors?}`.

| Method & path (`api/v1/...`) | Pemilik | Keterangan |
|---|---|---|
| `GET pegawai` | WS-2 | Daftar pegawai (filter lingkup) |
| `GET pegawai/{nip}` | WS-2 | Detail + `tabs` (array descriptor §3.5) |
| `GET pegawai/{nip}/riwayat/{jenis}` | WS-1 | Daftar baris riwayat |
| `GET pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Satu baris |
| `POST pegawai/{nip}/riwayat/{jenis}` | WS-1 | Tambah; status 0 untuk UL_PEGAWAI (role 2/6/7), 1 untuk admin |
| `PUT pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Ubah (baris yang sudah disetujui dijaga sesuai Definisi) |
| `DELETE pegawai/{nip}/riwayat/{jenis}/{id}` | WS-1 | Hapus lunak → status 10 |
| `POST pegawai/{nip}/riwayat/{jenis}/{id}/process` | WS-1 | Body `{aksi: "setujui"\|"tolak", reason_note}`; `reason_note` wajib saat tolak |
| `GET/POST pegawai/{nip}/lampiran`, `GET .../lampiran/{id}/unduh`, `DELETE .../lampiran/{id}` | WS-2 | Lampiran (`id_riwayat` = kode `jenis_rwy`, `id_entri` = PK baris riwayat) |

Aturan kontrak:
- Payload & respons **snake_case = nama kolom DDL** (tidak ada alias camelCase).
- Kode: 401 tanpa token, **403** role/lingkup tidak berhak, **404** NIP/baris/jenis tidak ada, **422** validasi (`errors` per field), 501 belum tersedia.
- Approve/reject dicatat di `audit_logs` sebagai aksi `update` (before/after).
- Waktu disimpan UTC.
- **Daftar slug `{jenis}` (dibekukan, dipakai juga sebagai nama berkas registry frontend):** `jabatan`, `kp`, `kgb`, `pendidikan`, `diklat`, `seminar`, `skp`, `skp-periodik`, `hukdis`, `ak`, `ak-siasn`, `keluarga`, `alamat`, `tanda-jasa`, `organisasi`, `karpeg`, `kariskarsu`. Konket dan LKH **bukan** jenis engine (endpoint sendiri milik WS-2). `karpeg`/`kariskarsu` dipakai halaman usulan mandiri, bukan tab Detail Pegawai.

### 3.8 Progres — `backend/docs/progress/03-Kepegawaian.md`
- Pecah tabel status menjadi **satu baris per task** (B-01..B-21) dengan kolom **WS** (WS-1/WS-2/selesai) dan **Key** (sesuai peta key di PRD §7.1). Tiap WS hanya mengubah barisnya sendiri setelah ini.
- Perbarui baris "Entry criteria": Fase 1 + Fase 2 DONE (Fase 2 sign-off 07-10-2026), skema B-01/B-02 di `main`.
- Tambahkan bagian singkat "S0-A — CR-046" (apa yang dibekukan).

### 3.9 Test minimum S0-A
- Unit: `StatusRiwayat` (nilai, flag status 3 mati default), auto-discovery Definisi (Definisi contoh terbaca; folder kosong aman), descriptor dari Definisi contoh × role.
- Feature/integrasi ringan: semua service di §3.2 dapat di-resolve dan bertipe interface; stub `PegawaiScope` menolak; fixture membuat pegawai + pmj + akun yang lolos FK.
- Route: kedua berkas route termuat (`php spark routes` tidak error); endpoint yang didaftarkan di S0 tidak menghasilkan 500.

## 4. Di luar cakupan S0-A
SnapshotSync, RiwayatEngine, BaseRiwayatController (milestone WS-1 M1, CR-048); Definisi jenis nyata; implementasi nyata `PegawaiScope`/lampiran (WS-2); semua frontend; migration/perubahan skema apa pun.

## 5. Berkas

| BOLEH disentuh (S0-A) | DILARANG |
|---|---|
| `backend/app/Config/Routing.php` (hanya `$routeFiles`) | **Semua migration yang sudah ada di `main`** (`app/Database/Migrations/*`) — tidak diedit, tidak dihapus |
| `backend/app/Config/RoutesRiwayat.php`, `RoutesPegawai.php` (baru; isi `RoutesPegawai.php` hanya kerangka) | `backend/app/Config/Routes.php` |
| `backend/app/Config/Services.php` (sekali, §3.2) | `_support` milik PR #20: `tests/_support/LepasMigrationKepegawaianTrait.php`, `tests/_support/SkemaKepegawaianTestTrait.php`, `tests/_support/Kepegawaian/SkemaD1.php`, `tests/_support/Kepegawaian/SkemaD1TestTrait.php` |
| `backend/app/Config/Kepegawaian.php` (baru) | Berkas base case CR-045 dan migration DBV-019 (milik sesi utama) |
| `backend/app/Interfaces/Kepegawaian/**` (baru) | `app/Libraries/Kepegawaian/Kalkulasi/**`, `app/Libraries/Kepegawaian/Nip/**` (sudah di `main`, CR-025/CR-036; hanya dipakai) |
| `backend/app/Libraries/Kepegawaian/Riwayat/**` (kerangka: Definisi, StatusRiwayat, Registry stub) | `composer.json`/`composer.lock` (milik WS-2) |
| `backend/app/Libraries/Kepegawaian/Stub/**` (baru) | Seluruh `frontend/**` (milik S0-B) |
| `backend/tests/_support/Kepegawaian/PegawaiFixtureTrait.php`, `Fake*.php`, Definisi contoh (baru) | `.env`, berkas kredensial apa pun |
| `backend/tests/Kepegawaian/**`, `backend/tests/unit/Kepegawaian/**` (test baru S0) | |
| `backend/app/Controllers/Api/Kepegawaian/README.md`, `backend/docs/progress/03-Kepegawaian.md` | |

Butuh perubahan skema? **Jangan** membuat migration. Catat kebutuhan di deskripsi serah; sesi utama mengalokasikan key DBV baru.

## 6. Kontrak yang dibekukan di akhir S0
Setelah CR-046 di-merge, berikut hanya boleh berubah **secara aditif** dan **oleh pemiliknya**:
1. `PegawaiScopeInterface` (pemilik implementasi: WS-2)
2. `AttachmentServiceInterface` / `StorageAdapterInterface` / `AturanLampiran` (pemilik: WS-2)
3. `RiwayatDefinisi` + API engine (pemilik: WS-1)
4. Descriptor tab (§3.5) + daftar slug `{jenis}` (pemilik: WS-1) — frontend membekukan pasangan props `RiwayatTabHost` di S0-B
5. Bentuk endpoint & kode status di README (§3.7)

Perubahan non-aditif setelah beku harus disetujui reviewer CR dan diberitahukan ke WS lain.

## 7. Definition of Done
- [ ] §3.1–§3.8 selesai; `php spark routes` memuat kedua berkas route.
- [ ] Semua service §3.2 terdaftar, bertipe interface, stub fail-closed; fake hanya di `tests/_support`.
- [ ] `PegawaiFixtureTrait` membuat data yang lolos FK (termasuk FK DBV-019 bila sudah di `main`) dan cocok dengan base case CR-045.
- [ ] README kontrak API lengkap (endpoint, descriptor, slug, kode status, snake_case, audit `update`).
- [ ] Progres 03-Kepegawaian dipecah per task dengan kolom WS & Key.
- [ ] Test §3.9 hijau.
- [ ] Gate cepat hijau selama kerja; **gate penuh `./check.sh` hijau sekali** pada commit terakhir yang diserahkan (log disertakan di deskripsi serah: ringkasan jumlah test/assertion dan `EXIT=0`).
- [ ] Commit sesuai aturan §8; tidak ada berkas terlarang (§5) yang berubah (`git diff --stat origin/main...HEAD` diperiksa).
- [ ] Deskripsi serah berisi: ringkasan, daftar kontrak yang dibekukan, keputusan desain yang Anda ambil, hal yang perlu diketahui Qoder-2.

## 8. Aturan kerja wajib
1. **Worktree sendiri** dari `origin/main` (jangan bekerja di checkout milik orang lain). Setelah membuat worktree: `cd backend && composer install`, `cd frontend && npm ci` (frontend dibutuhkan `check.sh`).
2. **DB scratch sendiri**: buat database khusus, mis. `simpeg_v2_ws1_dev` dan `simpeg_v2_ws1_testing` (collation `utf8mb4_unicode_ci`). Tulis `.env` worktree sendiri dari `backend/.env.example`; **jangan menyalin `.env` dev** dari tempat lain. Pastikan `database.default` dan `database.tests` menunjuk DB scratch Anda sebelum menjalankan `spark migrate` atau PHPUnit.
3. **Gate cepat** selama kerja (±5–10 menit): `cd backend && composer analyse && composer cs-check`; PHPUnit hanya berkas/folder yang Anda sentuh, mis. `vendor/bin/phpunit --no-coverage tests/Kepegawaian tests/unit/Kepegawaian`.
4. **Gate penuh sekali** sebelum serah, pada commit yang diserahkan, **sebagai proses lepas** dengan log ke berkas, mis. `nohup ./check.sh > gate.log 2>&1 &` (atau `Start-Process` di PowerShell, lihat AGENTS.md §3). Jangan jalankan gate penuh berulang; satu gate penuh pada satu waktu per mesin. Bila setelah gate hanya dokumen yang berubah, gate tidak perlu diulang.
5. **Commit**: Bahasa Indonesia, gaya `feat(kepegawaian): CR-046 …` / `test(kepegawaian): CR-046 …` / `docs(kepegawaian): CR-046 …`. **Tanpa** trailer `Co-Authored-By` dan tanpa menyebut AI/assistant/tool apa pun di pesan commit, komentar, atau dokumen.
6. **Rahasia & repo publik**: jangan commit `.env`, kredensial, token, password, API key; jangan menulis IP/host internal, nama server, atau detail celah keamanan di kode/dokumen.
7. **Tidak push ke `main`.** Serahkan dengan push branch fitur `ws1/…` (mis. `ws1/cr-046-s0a-kontrak-backend-fase3`) ke `origin`. Review CR dilakukan oleh sesi utama, yang kemudian memasukkan ke `main` (alur CR-only).
8. **UI** (tidak relevan untuk S0-A, berlaku di milestone berikutnya): aksi baris tabel lewat menu ⋮ (`RowActionsMenu`, AGENTS.md §1).
9. Bila ada aturan di briefing ini yang bertentangan dengan AGENTS.md atau kode di `main`, **tanyakan reviewer CR** sebelum menyimpang.

## 9. Estimasi
| Bagian | ±Jam-agen |
|---|---|
| Routing + Services + stub | 1,5 |
| Interface + fake + StatusRiwayat + Config flag | 2 |
| RiwayatDefinisi + discovery + Registry stub + descriptor | 1,5 |
| PegawaiFixtureTrait (FK G-02/DBV-019, base case CR-045) | 1,5 |
| README kontrak + progres | 1 |
| Test + gate cepat + gate penuh (lepas) | 0,5 + waktu gate |
| **Total** | **±1 hari-agen** (gate penuh berjalan sendiri ±80 menit, bisa lebih cepat setelah CR-045) |

## 10. Titik koordinasi dengan Qoder-2 (S0-B)
| Kapan | Apa | Arah |
|---|---|---|
| ±jam ke-2 | Kirim draf **bentuk descriptor tab** + **daftar slug `{jenis}`** + bentuk endpoint riwayat/lampiran (cukup isi §3.5/§3.7 README di branch Anda) | Qoder-1 → Qoder-2 (Qoder-2 menyelaraskan tipe TS, nama berkas `riwayat/jenis/<slug>.ts`, dan klien API) |
| ±jam ke-4 | Konfirmasi bentuk payload snake_case = kolom DDL (Qoder-2 menyamakan `types.ts` dengan DDL yang sama) | dua arah |
| Akhir S0 | Kedua branch diserahkan; reviewer CR memasukkan CR-046 lebih dulu (kontrak), lalu CR-047 | — |
| Setelah S0 | Permintaan perubahan kontrak/hook diajukan ke pemiliknya (§6), tidak mengedit berkas WS lain | dua arah |

Qoder-2 **tidak** menyentuh backend di S0; Anda **tidak** menyentuh frontend.

## 11. Keputusan yang sudah final (jangan ditanyakan ulang)
1. Build berangkat dari `main` (skema B-01/B-02 PR #20, G-02, G-03, G-09 sudah di `main`; Fase 2 sign-off 07-10-2026).
2. Tidak ada branch integrasi: tiap WS branch sendiri dari `main`, masuk `main` per milestone sebagai CR-only; gate penuh sekali sebelum push.
3. Pembagian: B-18 di WS-2, B-15 di WS-1, B-12 dipecah (SKP WS-1, LKH WS-2), B-20 dipecah (dasar di B-03, penutup di akhir WS-2).
4. Hak akses mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; Hukdis role 1/3; Jabatan & AK sesuai Matriks; Karpeg/Karis role 1, 2, 4, 5, 7 sesuai DoD), **kecuali** approver LKH = atasan langsung (ikut legacy). Izin disimpan sebagai data di Definisi per jenis.
5. Konket dan Karpeg/Karis = halaman usulan mandiri (bukan tab), layout legacy, style redesign.
6. Batas lampiran per jenis 1/2/5 MB ikut legacy.
7. PDF memakai TCPDF (ikut legacy) lewat ADR-033 (`docs/adr/ADR-033-library-pdf-tcpdf.md`; lisensi LGPL-3.0, sama dengan legacy); cadangan dompdf bila TCPDF bermasalah) + gate = bagian build; QA Lapis 1/review/sesi QA tidak.
10. Default ikut legacy: acuan jarak KGB = KP/KGB terakhir; cascade NIP ikut `update_nip` legacy (+ `jabatan_koordinasi.nip`); masa hukdis = `masa_sanksi_bulan`; aturan lingkup unit destinasi 21 / unit lain 7 ikut legacy.
11. Key: satu key CR per paket milestone per WS; S0-A = **CR-046**. Peta lengkap di PRD §7.1.
12. Aturan proyek yang berlaku: PK pegawai = `nip`; status data 1/2/10 (riwayat 0/1/2/10); collation `utf8mb4_unicode_ci`; skema ikut DDL legacy; migration di `main` tidak diedit; perubahan skema lewat key DBV dan review DB Validator; snapshot hanya disinkronkan di approval final (ADR-006), termasuk saat baris aktif ditolak/dihapus; trigger legacy tidak dibawa (menjadi aturan aplikasi); hanya `LocalStorageAdapter` (arsip remote legacy tidak disalin).
13. Serah-terima (keputusan 07-10-2026): cara serah = **push branch fitur `ws1/…` ke `origin`** (tidak ke `main`); review CR dilakukan oleh sesi utama, yang kemudian memasukkan paket ke `main` (alur CR-only).
14. Menu Fase 3 tersembunyi dengan `ACTIVE_PHASE = 2` sampai B-20 penutup (keputusan 07-10-2026); WS-2 yang menaikkan ke 3.
15. Slug `{jenis}` dan signature interface boleh diperhalus Qoder-1 selama S0 (dicatat di README kontrak §3.7, disetujui reviewer CR), lalu dibekukan di akhir S0 (§6).
16. Acuan di repo: kontrak task Fase 3 `docs/fase3/03-Kepegawaian.md`, matriks role Modul B `docs/fase3/MATRIKS_ROLE_MODUL_B.md`, ADR library PDF `docs/adr/ADR-033-library-pdf-tcpdf.md`.
