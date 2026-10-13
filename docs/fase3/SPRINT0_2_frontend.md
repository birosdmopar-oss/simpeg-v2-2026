# Sprint 0-2 — S0-B Fondasi Frontend Fase 3 (MAKE-003) — Qoder-2

**Cara pakai:** baca berkas ini dari atas, kerjakan langkah §0 berurutan, berhenti di setiap **⛔ TITIK TUNGGU**
(ℹ️ = info koordinasi, tidak menghentikan kerja); rincian tiap langkah ada di §2.

| | |
|---|---|
| Urutan | **Dikerjakan SETELAH Sprint 0-1** (`docs/fase3/SPRINT0_1_backend.md`, MAKE-002) di-review dan masuk `main` — kontrak backend (descriptor tab, slug `{jenis}`, kontrak API) beku. Langkah bertanda **[boleh duluan]** boleh dikerjakan lebih awal |
| Pelaksana | Qoder-2 (nanti melanjutkan WS-2, `docs/fase3/WS2.md`) |
| Key | **MAKE-003** (tanpa perubahan skema) |
| Branch | `ws2/make-003-s0b-fondasi-frontend` dari `origin/main` terbaru |
| Estimasi | ±1,5 hari-agen |
| Sumber port | Commit `3420e1a` di branch `origin/feat/frontend-ui-redesign` ("17 tab riwayat Detail Pegawai … dengan data contoh") |
| Reviewer | Reviewer CR = sesi utama (review kode, memantau CI, memasukkan paket ke `main`) |
| Hasil | Satu branch berisi commit MAKE-003, CI hijau, diserahkan ke reviewer CR. **Jangan push ke `main`.** |

---

## §0 MULAI DI SINI — Runbook langkah demi langkah

Aturan umum untuk setiap langkah: §2.8 (worktree, DB scratch, gate, commit, rahasia, tanpa push ke `main`).

**⛔ TITIK TUNGGU 1 — kontrak backend MAKE-002 di `main`.** Cek:
`git fetch origin && git log origin/main --oneline | grep MAKE-002`.
- Belum ada → kerjakan hanya langkah 0.A dan 0.B (**[boleh duluan]**, tidak bergantung kontrak), lalu berhenti di
  langkah 13 sampai MAKE-002 muncul.
- Sudah ada → kerjakan semua langkah berurutan.

### 0.A Persiapan [boleh duluan]

- [ ] 1. Buat worktree + branch dari `main` terbaru (jangan bekerja di checkout milik orang lain):
  `git fetch origin` lalu `git worktree add -b ws2/make-003-s0b-fondasi-frontend <folder-worktree> origin/main`.
- [ ] 2. Pasang dependensi di worktree: `cd frontend && npm ci`, lalu `cd backend && composer install`.
- [ ] 3. Bila akan menjalankan PHPUnit lokal: DB scratch `simpeg_v2_ws2_dev` / `simpeg_v2_ws2_testing` (collation
  `utf8mb4_unicode_ci`) dan `backend/.env` dari `backend/.env.example` (**jangan menyalin `.env` dev**).
- [ ] 4. Baca §1 (tabel tunggu) dan §2 (briefing).

### 0.B Bagian yang tidak bergantung kontrak [boleh duluan]

- [ ] 5. Port per berkas dari `3420e1a` (`git show 3420e1a:<path> > <path>`; **tanpa** merge/cherry-pick/rebase):
  `shared/layouts/*` + test shell, dan `features/kepegawaian/**` kecuali tab Konket & Karpeg/Karis (§2.3.1). Periksa
  berkas hasil port: tanpa penyebutan AI/tool, trailer, IP/host internal, tautan papan kerja internal.
- [ ] 6. Pensiunkan `AppShell` → `RedesignShell` di semua pembungkus halaman; `nav.config.ts` memuat semua menu `main`
  (§2.3.2).
- [ ] 7. Route per WS: `routes.pegawai.ts` / `routes.riwayat.ts` diimpor sekali dari `router/index.ts`; placeholder
  kosong untuk Konket, Verifikasi LKH, Usulan Karpeg/Karis (§2.3.3).
- [ ] 8. `nav.config` — menu Fase 3 dengan `phase: 3`, `ACTIVE_PHASE = 2` + test tersembunyi/tampil (§2.3.4).
- [ ] 9. `shared/components/StatusBadge.vue` + `ApprovalDialog.vue` (alasan wajib saat tolak, slot `diff`) + test;
  item ⋮ Setujui/Tolak sebelum Hapus (§2.3.6).
- [ ] 10. Re-export `useCascadeOptions` dan `masterService.options` lewat `src/shared` (§2.3.6).
- [ ] 11. Gate cepat: `cd frontend && npm run check`. Commit `refactor(frontend-ui): MAKE-003 …` /
  `feat(kepegawaian): MAKE-003 …` (tanpa trailer). Boleh push branch untuk cadangan (`git push -u origin <branch>`),
  tetapi **belum** diserahkan.
- [ ] 12. ℹ️ Draf kontrak Qoder-1 boleh dibaca dari branch `origin/ws1/make-002-s0a-kontrak-backend`
  (`backend/app/Controllers/Api/Kepegawaian/README.md`) untuk persiapan; yang mengikat hanya versi di `main`.

### 0.C Bagian yang bergantung kontrak beku

- [ ] 13. **⛔ TITIK TUNGGU 1 (cek ulang)** — `git fetch origin && git log origin/main --oneline | grep MAKE-002` harus
  muncul. Lalu `git rebase origin/main` dan baca README kontrak versi `main` (descriptor §2.3.5, daftar slug `{jenis}`,
  endpoint, snake_case = kolom DDL).
- [ ] 14. Registry tab per jenis: `riwayat/jenis/<slug>.ts` (15 berkas, nama = slug beku) + loader
  `import.meta.glob`; `RiwayatTabHost` (props `nip`, `descriptor`, `config`); `DetailPegawaiPage` merender tab dari
  descriptor (§2.3.5).
- [ ] 15. `types.ts` = kolom DDL PR #20 + `RiwayatTabDescriptor` + `StatusRiwayat` (§2.3.6).
- [ ] 16. Tanpa data contoh di runtime: `pegawai.service.ts`/`riwayat.service.ts` memanggil API sesuai kontrak;
  keadaan kosong/galat rapi untuk 501/404; `grep -rn "\.mock'" frontend/src --include=*.ts --include=*.vue` hanya
  menemukan berkas di `__tests__/` (§2.3.7).
- [ ] 17. Gate cepat `cd frontend && npm run check`; periksa DoD (§2.7) dan berkas terlarang:
  `git diff --stat origin/main...HEAD` dibandingkan dengan §2.5 (tidak ada perubahan `backend/**`). Commit MAKE-003.

### 0.D Serah

- [ ] 18. Push **branch fitur** ke `origin` — **bukan** `main`: `git push origin ws2/make-003-s0b-fondasi-frontend`.
- [ ] 19. Tunggu workflow CI `quality-gate` (GitHub Actions) untuk SHA itu sampai hijau. CI = gate penuh resmi
  (AGENTS.md §3); gate penuh lokal tidak wajib.
- [ ] 20. Lapor ke reviewer CR: branch, SHA (`git rev-parse HEAD`), key MAKE-003, ringkasan, tautan run CI untuk SHA
  itu, hasil gate cepat, daftar berkas yang di-port vs ditulis baru, penyesuaian `shared/ui` (bila ada), kontrak yang
  dibekukan, hal yang perlu diketahui Qoder-1.
- [ ] 21. **⛔ TITIK TUNGGU 2 — tunggu review CR + CI hijau + merge MAKE-003 ke `main`.** Temuan review diperbaiki di
  branch yang sama (key perbaikan mengikuti arahan reviewer, AGENTS.md §2), push ulang, ulangi langkah 19–20. Setelah
  merge, fondasi frontend **beku** (§2.6) dan Sprint 0-2 selesai.
- [ ] 22. ℹ️ Lanjutan untuk Anda: `Kerjakan docs/fase3/WS2.md` — mulai M1 hanya setelah MAKE-002 **dan** MAKE-003 di
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

## §2 Briefing S0-B

### 2.1 Konteks singkat

Fase 3 (Modul B — Kepegawaian Core) dibangun oleh dua workstream paralel. WS-1 (Qoder-1) membangun mesin riwayat generik dan jenis riwayat karier; WS-2 (Anda) memegang lingkup akses, lampiran, koreksi NIP, biodata, jabatan/struktur, Konket, LKH, halaman pegawai, **dan fondasi frontend**.

UI redesign untuk Kepegawaian sudah dibuat di branch `origin/feat/frontend-ui-redesign` (data contoh, belum tersambung API). Branch itu **tidak boleh di-merge**: tiga commit di dalamnya (`9dd8b1e`, `265aba1`, `ad2fc91`) membawa trailer yang dilarang aturan commit proyek, dan branch itu bercabang dari `main` lama sehingga membawa penghapusan/regresi banyak berkas yang sudah berubah di `main` (Web Config, Lokasi Presensi, master data, FormField, dateTime, dll.). Karena itu Sprint 0 memindahkan **per berkas** hanya bagian yang dibutuhkan, di atas `main` terbaru.

Sprint 0 ada supaya kedua WS bisa bekerja paralel tanpa bentrok berkas. Sprint 0 dikerjakan **berurutan**: Qoder-1 lebih dulu membekukan kontrak backend (Sprint 0-1, MAKE-002: descriptor tab, slug `{jenis}`, kontrak API); setelah itu masuk `main`, Anda menyiapkan fondasi frontend (shell, router, nav, registry tab, tipe, komponen approval) di atas kontrak tersebut. Bagian yang tidak bergantung kontrak boleh dikerjakan lebih awal (lihat §0).

### 2.2 Tujuan S0-B

1. Shell redesign (`RedesignShell` + sidebar + topbar + `nav.config`) menggantikan `AppShell` di seluruh halaman yang ada.
2. Fitur `features/kepegawaian` dari redesign tersedia di atas `main`, **tanpa data contoh di runtime**.
3. Tab riwayat dipecah menjadi satu berkas per jenis dengan registry otomatis, sehingga WS-1 dan WS-2 menambah tab tanpa menyentuh berkas bersama.
4. Route Fase 3 dipecah per WS; semua menu Fase 3 sudah terdaftar (tersembunyi sampai tersambung API).
5. Tipe TypeScript sama dengan kolom DDL; komponen `StatusBadge` dan `ApprovalDialog` siap dipakai kedua WS.

### 2.3 Cakupan tepat

#### 2.3.1 Port per berkas dari `3420e1a`
Ambil isi berkas dengan `git show 3420e1a:<path> > <path>` (atau `git checkout 3420e1a -- <path>` lalu periksa). **Jangan** `merge`, `cherry-pick`, atau `rebase` dari branch redesign.

| Diambil | Catatan |
|---|---|
| `frontend/src/shared/layouts/AppSidebar.vue`, `AppTopbar.vue`, `RedesignShell.vue`, `nav.config.ts`, `__tests__/RedesignShell.spec.ts`, `__tests__/nav.config.spec.ts`, `__tests__/shellTestUtils.ts` | Komponen `@/shared/ui` (UiAvatar, UiBreadcrumb, …) sudah ada di `main`; pakai versi `main` |
| `frontend/src/features/kepegawaian/**` | Kecuali yang dikecualikan di bawah; sesuaikan dengan §2.3.2–§2.3.6 |
| `frontend/src/router/__tests__/router.kepegawaian.spec.ts` | Sesuaikan dengan route file baru (§2.3.3) |

| **Tidak** diambil | Alasan |
|---|---|
| Semua berkas di luar dua folder di atas (`features/master-data/**`, `features/auth/**`, `shared/components/FormField.vue`, `shared/ui/*`, `shared/utils/*`, `router/index.ts` versi redesign, dll.) | Versi `main` lebih baru; mengambil versi redesign = regresi. Bila berkas yang di-port butuh perubahan kecil di `shared/ui`, sesuaikan berkas yang di-port, atau buat perubahan **aditif** di `shared/ui` dan catat di laporan serah |
| Tab **Konket** (`riwayat-konket`) dan **Karpeg/Karis/Karsu** (`karpeg-karis-karsu`) | Keputusan final: keduanya halaman usulan mandiri, bukan tab Detail Pegawai |
| `services/pegawai.mock.ts`, `riwayat/riwayat.mock.ts` sebagai data runtime | Data contoh tidak boleh masuk `main` sebelum tersambung API (§2.3.7) |

Setelah port, periksa bahwa berkas yang di-port tidak memuat penyebutan AI/assistant/tool, trailer, IP/host internal, atau tautan papan kerja internal.

#### 2.3.2 Pensiunkan `AppShell`
- Ganti pemakaian `AppShell` dengan `RedesignShell` di 6 pembungkus halaman: `features/master-data/views/FaqPage.vue`, `HariLiburPage.vue`, `MasterDataPage.vue`, `WebConfigPage.vue`, `features/auth/views/ChangePasswordPage.vue`, `UserManagementPage.vue` — plus `features/dashboard/views/HomeView.vue` dan rujukan lain yang ditemukan `grep -rn AppShell frontend/src`.
- Hapus `shared/components/AppShell.vue` dan `__tests__/AppShell.spec.ts`; pindahkan asersi yang masih relevan (menu, logout, ganti password) ke test shell/nav.
- `nav.config.ts` wajib memuat **semua menu yang ada di `AppShell` di `main`**: Beranda, Manajemen Akun, Master Data, **Web Config**, Hari Libur, FAQ, Ganti Password — dengan role yang sama seperti di `main` (`USER_MANAGEMENT_ROLES`, `MASTER_DATA_ROLES`, `HARI_LIBUR_READ_ROLES`, `WEB_CONFIG_ROLES`). Tidak boleh ada menu yang hilang; menu lain yang sudah ada di `main` saat Anda mulai (mis. Lokasi Presensi bila sudah punya route) ikut didaftarkan.

#### 2.3.3 Route per WS
- Buat `frontend/src/features/kepegawaian/routes.pegawai.ts` (milik WS-2) dan `routes.riwayat.ts` (milik WS-1), masing-masing mengekspor `RouteRecordRaw[]`; impor **sekali** dari `router/index.ts` (spread ke daftar route). Setelah S0, WS-1 hanya mengedit `routes.riwayat.ts`.
- `routes.pegawai.ts`: Daftar Pegawai (`/pegawai`, `pegawai-list`, role `PEGAWAI_LIST_ROLES`), Detail Pegawai (`/pegawai/:nip`), Struktur Organisasi (`/struktur-organisasi`), Usulan Konket, Verifikasi LKH.
- `routes.riwayat.ts`: Usulan Karpeg/Karis.
- Halaman yang belum ada (Konket, Verifikasi LKH, Usulan Karpeg/Karis) diarahkan ke halaman **placeholder kosong** milik WS pemiliknya (judul + keterangan "belum tersedia", tanpa data contoh). Pemiliknya menggantinya di milestone berikutnya.

#### 2.3.4 `nav.config` — semua menu Fase 3 didaftarkan sekarang
- Tambahkan entri: **Daftar Pegawai**, **Struktur Organisasi**, **Usulan Konket**, **Usulan Karpeg/Karis**, **Verifikasi LKH** dengan `phase: 3` dan role mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; untuk Karpeg/Karis: role 1, 2, 4, 5, 7). Tampilan menu hanya UX; backend tetap menegakkan role.
- Set `ACTIVE_PHASE = 2` di `main` supaya menu Fase 3 **tersembunyi** sampai halamannya tersambung API. WS-2 menaikkan ke 3 di B-20 penutup. Test nav memastikan menu Fase 3 tersembunyi saat `ACTIVE_PHASE = 2` dan tampil saat 3.
- Setelah S0, WS-1 tidak menyentuh `router/index.ts` dan `nav.config.ts`.

#### 2.3.5 Registry tab riwayat per jenis
- Pecah `riwayat/riwayat.config.ts` (17 jenis dalam satu berkas), `RIWAYAT_MENUS`, dan `__tests__/riwayat.spec.ts` menjadi **satu berkas per jenis**: `features/kepegawaian/riwayat/jenis/<slug>.ts` (default export konfigurasi jenis) dan test per jenis bila perlu.
- Loader `riwayat/registry.ts` memakai `import.meta.glob('./jenis/*.ts', { eager: true })`; tidak ada daftar terpusat yang perlu diedit saat menambah jenis.
- `DetailPegawaiPage` merender tab dari **descriptor backend** (`GET pegawai/{nip}` → `tabs`) dicocokkan dengan registry: tab hanya tampil bila ada di descriptor dan `can_view = true`; tombol tambah/ubah/hapus/proses mengikuti `can_*`. Bentuk descriptor (dibekukan S0-A):
  ```json
  { "jenis": "pendidikan", "label": "Riwayat Pendidikan",
    "can_view": true, "can_create": true, "can_edit": true, "can_delete": false, "can_process": false }
  ```
- Komponen penampung tab `RiwayatTabHost` dengan props dibekukan: `nip: string`, `descriptor: RiwayatTabDescriptor`, `config: RiwayatJenisConfig`.
- **Slug = `{jenis}` di API** (daftar dibekukan bersama S0-A). Pemetaan dari key redesign:

| Key redesign | Slug / berkas | Pemilik setelah S0 |
|---|---|---|
| `riwayat-jabatan` | `jabatan.ts` | WS-2 |
| `riwayat-pangkat` | `kp.ts` | WS-1 |
| `riwayat-kgb` | `kgb.ts` | WS-1 |
| `riwayat-pendidikan` | `pendidikan.ts` | WS-1 |
| `riwayat-pelatihan` | `diklat.ts` | WS-1 |
| `kursus-seminar` | `seminar.ts` | WS-1 |
| `skp-tahunan` | `skp.ts` | WS-1 |
| `skp-periodik` | `skp-periodik.ts` | WS-1 |
| `riwayat-hukdis` | `hukdis.ts` | WS-1 |
| `angka-kredit` | `ak.ts` | WS-1 (WS-1 menambah `ak-siasn.ts` sendiri) |
| `data-keluarga` | `keluarga.ts` | WS-1 |
| `data-alamat` | `alamat.ts` | WS-1 |
| `tanda-jasa` | `tanda-jasa.ts` | WS-1 |
| `riwayat-organisasi` | `organisasi.ts` | WS-1 |
| `laporan-kerja-harian` | `lkh.ts` (tab khusus, bukan jenis engine) | WS-2 |
| `riwayat-konket`, `karpeg-karis-karsu` | **tidak di-port** | — |

- Setelah S0, loader registry, `RiwayatTabHost`, dan komponen `riwayat/*.vue` (form/list/record section) menjadi milik **WS-1** (mesin riwayat FE); `DetailPegawaiPage` tetap milik WS-2.

#### 2.3.6 Tipe, komponen bersama, re-export
- **`types.ts`** disamakan dengan kolom DDL PR #20 (snake_case persis nama kolom; mis. `glr_awal`, `glr_akhir`, `tgl_lahir`, `bpjs_kes`, `id_provinsi_lahir`). Sumber: migration `2026-09-30-120000_CreatePegawai.php`, `120100_CreatePegawaiSnapshot.php`, `13*` (riwayat), dan dokumen db-review B-01/B-02 §3. Kolom konfigurasi tab per jenis memakai nama kolom DDL tabel `riwayat_*`-nya (bukan nama karangan redesign). Tambahkan tipe `RiwayatTabDescriptor` dan `StatusRiwayat` (0 Menunggu, 1 Disetujui, 2 Ditolak, 10 Dihapus; 3 Diproses hanya bila flag aktif — default nonaktif).
- **`shared/components/StatusBadge.vue`** (baru, generik): status riwayat → badge (Disetujui hijau, Menunggu kuning, Ditolak merah, Dihapus abu-abu; Diproses hanya bila flag). `features/master-data/components/StatusBadge.vue` yang sudah ada **tidak** dipindah/diubah.
- **`shared/components/ApprovalDialog.vue`** (baru): mode Setujui/Tolak; **alasan wajib saat Tolak** (validasi + pesan); slot `diff` untuk perbandingan biodata B-04; emit `{ aksi: 'setujui' | 'tolak', reason_note }`.
- **Menu ⋮**: item "Setujui" dan "Tolak" masuk grup "aksi lain" (sesudah Edit/ubah status/urutan, **sebelum** "Hapus"), sesuai AGENTS.md §1. Pastikan semua aksi baris di berkas yang di-port memakai `RowActionsMenu` dengan `label` "Aksi untuk <nama baris>" dan `testid` `<fitur>-actions-<id>`; test memilih item lewat `data-action`.
- **Re-export, tidak dipindah**: `useCascadeOptions` (dari `features/master-data/composables`) dan `masterService.options` di-re-export lewat `src/shared` (mis. `src/shared/composables/useCascadeOptions.ts`, `src/shared/services/masterOptions.ts`). Berkas asal di `features/master-data` tidak diubah.

#### 2.3.7 Tanpa data contoh di runtime
- `pegawai.service.ts` dan `riwayat.service.ts` memanggil API sesuai kontrak (`GET /pegawai`, `GET /pegawai/{nip}`, `GET/POST/PUT/DELETE /pegawai/{nip}/riwayat/{jenis}[/{id}]`, `POST …/{id}/process`, lampiran `…/pegawai/{nip}/lampiran`) lewat klien `api` yang sudah ada (`@/lib/axios`).
- Halaman menampilkan keadaan kosong/galat yang rapi saat backend menjawab 501/404 (backend belum ada sampai milestone berikutnya).
- Data contoh boleh dipakai **hanya di test** (fixture di `__tests__/`), tidak diimpor kode runtime.

### 2.4 Di luar cakupan S0-B
Implementasi halaman Konket/LKH/Karpeg-Karis (placeholder saja), tersambungnya API (milestone berikutnya), semua backend, perubahan `features/master-data/**` selain pembungkus halaman (§2.3.2), perubahan skema.

### 2.5 Berkas

| BOLEH disentuh (S0-B) | DILARANG |
|---|---|
| `frontend/src/shared/layouts/**` (baru dari port) | Seluruh `backend/**` (milik S0-A), termasuk `Routes.php`, `Services.php`, migration |
| `frontend/src/features/kepegawaian/**` | **Semua migration yang sudah ada di `main`**, `_support` milik PR #20 (`backend/tests/_support/LepasMigrationKepegawaianTrait.php`, `SkemaKepegawaianTestTrait.php`, `Kepegawaian/SkemaD1*.php`) |
| `frontend/src/router/index.ts` (impor route file + hapus route Fase 3 lama bila ada), `router/__tests__/**` | Isi `features/master-data/**` dan `features/auth/**` selain penggantian shell di pembungkus halaman (§2.3.2) |
| `frontend/src/shared/components/StatusBadge.vue`, `ApprovalDialog.vue` (+ test), penghapusan `AppShell.vue` + spec | `features/master-data/components/StatusBadge.vue`, `features/master-data/composables/useCascadeOptions.ts`, `features/master-data/services/master.service.ts` (hanya di-re-export) |
| `frontend/src/shared/composables/**`, `frontend/src/shared/services/**` (re-export) | `shared/ui/*` kecuali perubahan aditif kecil yang benar-benar dibutuhkan (dicatat) |
| Pembungkus halaman yang memakai `AppShell` (§2.3.2), `frontend/src/shared/README.md` (catatan shell/registry) | `.env`, berkas kredensial apa pun; `composer.json` |

### 2.6 Kontrak yang dibekukan di akhir S0
Setelah MAKE-003 di-merge, berikut hanya berubah **aditif** dan **oleh pemiliknya**:
1. Bentuk `RiwayatTabDescriptor` (sama dengan backend S0-A) dan props `RiwayatTabHost` (`nip`, `descriptor`, `config`) — pemilik WS-1.
2. Mekanisme registry `riwayat/jenis/<slug>.ts` + `import.meta.glob` dan daftar slug — pemilik WS-1.
3. Props/emit `StatusBadge` dan `ApprovalDialog` — pemilik WS-2 (perubahan dari WS-1 diajukan ke WS-2).
4. `routes.pegawai.ts` (WS-2) / `routes.riwayat.ts` (WS-1); `router/index.ts` dan `nav.config.ts` milik WS-2.

### 2.7 Definition of Done
- [ ] Port per berkas §2.3.1 selesai; tidak ada merge/cherry-pick dari branch redesign; `git log` branch hanya berisi commit MAKE-003 Anda.
- [ ] `AppShell` pensiun; semua halaman lama tampil di `RedesignShell`; semua menu `main` (termasuk Web Config) ada di `nav.config`.
- [ ] `routes.pegawai.ts`/`routes.riwayat.ts` diimpor sekali dari `router/index.ts`; menu Fase 3 terdaftar dengan `phase: 3`, tersembunyi pada `ACTIVE_PHASE = 2`.
- [ ] Registry per jenis (15 berkas sesuai tabel §2.3.5) + loader `import.meta.glob`; `DetailPegawaiPage` merender tab dari descriptor; tab Konket & Karpeg/Karis tidak ada.
- [ ] `types.ts` = kolom DDL; `StatusBadge` + `ApprovalDialog` (alasan wajib saat tolak, slot diff) + test; item ⋮ Setujui/Tolak sebelum Hapus.
- [ ] `useCascadeOptions`/`masterService.options` di-re-export lewat `src/shared` tanpa memindah berkas asal.
- [ ] Tidak ada data contoh di runtime: tidak ada kode non-test yang mengimpor `*.mock.ts` (`grep -rn "\.mock'" frontend/src --include=*.ts --include=*.vue` hanya menemukan berkas di `__tests__/`).
- [ ] Gate cepat hijau selama kerja (`cd frontend && npm run check`); **CI `quality-gate` hijau** pada SHA yang diserahkan (tautan run di laporan serah).
- [ ] Commit sesuai aturan §2.8; tidak ada berkas terlarang (§2.5) yang berubah (`git diff --stat origin/main...HEAD` diperiksa).
- [ ] Deskripsi serah: ringkasan, daftar berkas yang di-port vs ditulis baru, penyesuaian `shared/ui` (bila ada), kontrak yang dibekukan, hal yang perlu diketahui Qoder-1.

### 2.8 Aturan kerja wajib
1. **Worktree sendiri** dari `origin/main` (jangan bekerja di checkout milik orang lain). Setelah membuat worktree: `cd frontend && npm ci`, `cd backend && composer install` (backend dibutuhkan `check.sh`).
2. **DB scratch sendiri** bila menjalankan PHPUnit/gate penuh lokal: mis. `simpeg_v2_ws2_dev` dan `simpeg_v2_ws2_testing` (collation `utf8mb4_unicode_ci`). Tulis `.env` worktree sendiri dari `backend/.env.example`; **jangan menyalin `.env` dev** dari tempat lain.
3. **Gate cepat** selama kerja: `cd frontend && npm run check` (ESLint, vue-tsc, Vitest, build). Backend tidak berubah di S0-B, jadi PHPUnit terarah tidak diperlukan selama kerja.
4. **Gate penuh = CI** (AGENTS.md §3): push branch, tunggu workflow `quality-gate` hijau untuk SHA itu. Gate penuh lokal (`./check.sh`) tidak wajib; bila dijalankan, jalankan sebagai proses lepas dengan log ke berkas (`nohup ./check.sh > gate.log 2>&1 &`, atau `Start-Process` di PowerShell), satu gate penuh per mesin.
5. **Commit**: Bahasa Indonesia, gaya `feat(kepegawaian): MAKE-003 …` / `refactor(frontend-ui): MAKE-003 …` / `test(kepegawaian): MAKE-003 …`. **Tanpa** trailer `Co-Authored-By` dan tanpa menyebut AI/assistant/tool apa pun di pesan commit, komentar, atau dokumen.
6. **Rahasia & repo publik**: jangan commit `.env`, kredensial, token, password, API key; jangan menulis IP/host internal, nama server, atau detail celah keamanan.
7. **UI**: semua aksi baris tabel lewat satu tombol ⋮ (`RowActionsMenu`), urutan Edit → ubah status → urutan → aksi lain (Setujui/Tolak/Pulihkan) → Hapus (`danger: true`), label kata kerja Bahasa Indonesia, aksi berisiko memakai `ConfirmDialog` (AGENTS.md §1).
8. **Tidak push ke `main`.** Serahkan dengan push branch fitur `ws2/…` (`ws2/make-003-s0b-fondasi-frontend`) ke `origin` (§0.C). Review CR dilakukan oleh sesi utama, yang kemudian memasukkan ke `main`.
9. Bila ada aturan di briefing ini yang bertentangan dengan AGENTS.md atau kode di `main`, **tanyakan reviewer CR** sebelum menyimpang.

### 2.9 Estimasi
| Bagian | ±Jam-agen |
|---|---|
| Port per berkas + pensiun AppShell + nav semua menu `main` | 3 |
| Route per WS + placeholder + `ACTIVE_PHASE` | 1,5 |
| Pecah registry per jenis + `RiwayatTabHost` + render dari descriptor | 3 |
| `types.ts` = DDL | 1,5 |
| `StatusBadge` + `ApprovalDialog` + ⋮ Setujui/Tolak + re-export | 1,5 |
| Lepas data contoh dari runtime + sesuaikan test | 1 |
| Gate cepat + menunggu CI | 0,5 + waktu CI |
| **Total** | **±1,5 hari-agen** |

### 2.10 Koordinasi dengan Qoder-1 (Sprint 0-1)
| Kapan | Apa | Arah |
|---|---|---|
| Selama Sprint 0-1 | Anda hanya mengerjakan langkah **[boleh duluan]** (§0.A–0.B). Pertanyaan tentang kontrak diajukan ke Qoder-1; draf bisa dibaca di branch `origin/ws1/make-002-s0a-kontrak-backend` | Qoder-2 → Qoder-1 |
| Setelah MAKE-002 merge | Kontrak backend beku. Selaraskan tipe TS, nama berkas `riwayat/jenis/<slug>.ts`, dan klien API dengan README kontrak versi `main`; payload snake_case = kolom DDL | Qoder-1 → Qoder-2 |
| Setelah S0 | Permintaan perubahan kontrak diajukan ke pemiliknya (§2.6, dan `SPRINT0_1_backend.md` §2.6), tidak mengedit berkas WS lain | dua arah |

Anda **tidak** menyentuh backend di Sprint 0; Qoder-1 **tidak** menyentuh frontend.

### 2.11 Keputusan yang sudah final (jangan ditanyakan ulang)
1. Build berangkat dari `main` (skema B-01/B-02, G-02, G-03, G-09 sudah di `main`; Fase 2 sign-off 07-10-2026).
2. Tidak ada branch integrasi: tiap paket branch sendiri dari `main`, masuk `main` setelah review CR + CI hijau.
3. Pembagian: B-18 di WS-2, B-15 di WS-1, B-12 dipecah (SKP WS-1, LKH WS-2), B-20 dipecah (dasar di B-03, penutup di
   akhir WS-2).
4. Hak akses mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; Hukdis role 1/3; Jabatan & AK sesuai Matriks;
   Karpeg/Karis role 1, 2, 4, 5, 7 sesuai DoD), **kecuali** approver LKH = atasan langsung (ikut legacy). Izin disimpan
   sebagai data (Definisi per jenis).
5. Konket dan Karpeg/Karis = **halaman usulan mandiri**, bukan tab Detail Pegawai; **layout ikut legacy** (menu sendiri,
   daftar/antrian, form usulan, alur proses), **style ikut redesign** (token + komponen shared, aksi baris ⋮). Halaman
   Fase 3 lain mengikuti layout redesign.
6. Batas lampiran per jenis 1/2/5 MB ikut legacy.
7. PDF (LKH, DRH) memakai TCPDF lewat ADR-033 (`docs/adr/ADR-033-library-pdf-tcpdf.md`; lisensi LGPL-3.0-or-later);
   cadangan dompdf bila TCPDF bermasalah.
8. Status 3 "Diproses" di belakang flag, nonaktif default.
9. Test yang diwajibkan DoD + gate = bagian build; QA Lapis 1/review/sesi QA tidak.
10. Default ikut legacy: acuan jarak KGB = KP/KGB terakhir; cascade NIP ikut `update_nip` legacy
    (+ `jabatan_koordinasi.nip`); masa hukdis = `masa_sanksi_bulan`; aturan lingkup unit destinasi 21 / unit lain 7.
11. Key: satu key per paket; S0-A = **MAKE-002**, S0-B = **MAKE-003** (peta lengkap `docs/fase3/WS2.md` §3.8.1).
12. Data contoh tidak masuk `main` sebelum tersambung API.
13. Aturan proyek: aksi baris lewat ⋮ (AGENTS.md §1); skema ikut DDL legacy, snake_case = nama kolom; migration di
    `main` tidak diedit; repo publik (tanpa IP/host internal).
14. Serah-terima (07-10-2026): **push branch fitur `ws2/…` ke `origin`** (tidak ke `main`); review CR oleh sesi utama,
    yang kemudian memasukkan paket ke `main`.
15. Urutan Sprint 0 (07-10-2026): Sprint 0-1 (backend, Qoder-1) dulu; bagian Sprint 0-2 yang bergantung kontrak
    dikerjakan setelah MAKE-002 di `main`.
16. Menu Fase 3 tersembunyi dengan `ACTIVE_PHASE = 2` sampai B-20 penutup; WS-2 yang menaikkan ke 3.
17. Slug `{jenis}` dan signature interface backend boleh diperhalus Qoder-1 selama Sprint 0-1, lalu dibekukan saat
    MAKE-002 di-merge; Anda menyelaraskan tipe dan nama berkas registry dengan versi beku.

---

## Lampiran — rujukan

| Dokumen | Isi |
|---|---|
| `docs/fase3/README.md` | Urutan perintah Fase 3, tabel tunggu ringkas, reviewer |
| `docs/fase3/SPRINT0_1_backend.md` | Sprint 0-1 (Qoder-1): kontrak backend yang Anda ikuti |
| `docs/fase3/WS2.md` | Runbook + PRD WS-2 (lanjutan Anda setelah Sprint 0); peta key §3.8.1 |
| `docs/fase3/MATRIKS_ROLE_MODUL_B.md` | Matriks role × endpoint Modul B (role menu nav) |
| `docs/fase3/03-Kepegawaian.md` | Kontrak task Fase 3 (B-01..B-21, DoD per task) |
| `docs/adr/ADR-033-library-pdf-tcpdf.md` | Library PDF TCPDF (dipakai WS-2 M5) |
| `AGENTS.md` | Aturan proyek: menu ⋮ (§1), commit/key/rahasia/merge (§2), gate cepat & CI (§3) |
| `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md` | Skema B-01/B-02 (kolom DDL untuk `types.ts`) |
| Migration `backend/app/Database/Migrations/2026-09-30-12*`/`13*` | DDL pegawai, snapshot, riwayat |
