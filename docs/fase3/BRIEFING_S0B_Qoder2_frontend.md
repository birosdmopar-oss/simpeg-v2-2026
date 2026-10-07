# Briefing S0-B — Fondasi Frontend Fase 3 (Qoder-2, WS-2)

| | |
|---|---|
| Penerima | Qoder-2 (pelaksana WS-2 "Pegawai Inti, Organisasi & Alur Khusus") |
| Key review | **MAKE-003** (CR saja) |
| Branch | `ws2/make-003-s0b-fondasi-frontend-fase3`, dibuat dari `origin/main` terbaru |
| Estimasi | **±1,5 hari-agen** (hari 0–1,5 Sprint 0) |
| Berjalan paralel dengan | S0-A Qoder-1 (kontrak backend, MAKE-002) — lihat `BRIEFING_S0A_Qoder1_backend.md` |
| Sumber port | Commit `3420e1a` di branch `origin/feat/frontend-ui-redesign` ("17 tab riwayat Detail Pegawai … dengan data contoh") |
| Acuan | `docs/fase3/PRD_WS2.md`, `docs/fase3/PRD_WS1.md`, `AGENTS.md` (§1 menu ⋮, §3 gate), `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md` (kolom DDL), migration `backend/app/Database/Migrations/2026-09-30-12*`/`13*`, `docs/fase3/03-Kepegawaian.md` (kontrak task Fase 3), `docs/fase3/MATRIKS_ROLE_MODUL_B.md` (hak akses Modul B), `docs/adr/ADR-033-library-pdf-tcpdf.md` (library PDF) |
| Hasil yang diserahkan | Satu branch berisi commit MAKE-003, lolos gate penuh, diserahkan ke reviewer CR. **Jangan push ke `main`.** |

---

## 1. Konteks singkat

Fase 3 (Modul B — Kepegawaian Core) dibangun oleh dua workstream paralel. WS-1 (Qoder-1) membangun mesin riwayat generik dan jenis riwayat karier; WS-2 (Anda) memegang lingkup akses, lampiran, koreksi NIP, biodata, jabatan/struktur, Konket, LKH, halaman pegawai, **dan fondasi frontend**.

UI redesign untuk Kepegawaian sudah dibuat di branch `origin/feat/frontend-ui-redesign` (data contoh, belum tersambung API). Branch itu **tidak boleh di-merge**: tiga commit di dalamnya (`9dd8b1e`, `265aba1`, `ad2fc91`) membawa trailer yang dilarang aturan commit proyek, dan branch itu bercabang dari `main` lama sehingga membawa penghapusan/regresi banyak berkas yang sudah berubah di `main` (Web Config, Lokasi Presensi, master data, FormField, dateTime, dll.). Karena itu Sprint 0 memindahkan **per berkas** hanya bagian yang dibutuhkan, di atas `main` terbaru.

Sprint 0 ada supaya kedua WS bisa bekerja paralel tanpa bentrok berkas: Anda menyiapkan fondasi frontend (shell, router, nav, registry tab, tipe, komponen approval), Qoder-1 membekukan kontrak backend.

## 2. Tujuan S0-B

1. Shell redesign (`RedesignShell` + sidebar + topbar + `nav.config`) menggantikan `AppShell` di seluruh halaman yang ada.
2. Fitur `features/kepegawaian` dari redesign tersedia di atas `main`, **tanpa data contoh di runtime**.
3. Tab riwayat dipecah menjadi satu berkas per jenis dengan registry otomatis, sehingga WS-1 dan WS-2 menambah tab tanpa menyentuh berkas bersama.
4. Route Fase 3 dipecah per WS; semua menu Fase 3 sudah terdaftar (tersembunyi sampai tersambung API).
5. Tipe TypeScript sama dengan kolom DDL; komponen `StatusBadge` dan `ApprovalDialog` siap dipakai kedua WS.

## 3. Cakupan tepat

### 3.1 Port per berkas dari `3420e1a`
Ambil isi berkas dengan `git show 3420e1a:<path> > <path>` (atau `git checkout 3420e1a -- <path>` lalu periksa). **Jangan** `merge`, `cherry-pick`, atau `rebase` dari branch redesign.

| Diambil | Catatan |
|---|---|
| `frontend/src/shared/layouts/AppSidebar.vue`, `AppTopbar.vue`, `RedesignShell.vue`, `nav.config.ts`, `__tests__/RedesignShell.spec.ts`, `__tests__/nav.config.spec.ts`, `__tests__/shellTestUtils.ts` | Komponen `@/shared/ui` (UiAvatar, UiBreadcrumb, …) sudah ada di `main`; pakai versi `main` |
| `frontend/src/features/kepegawaian/**` | Kecuali yang dikecualikan di bawah; sesuaikan dengan §3.2–§3.6 |
| `frontend/src/router/__tests__/router.kepegawaian.spec.ts` | Sesuaikan dengan route file baru (§3.3) |

| **Tidak** diambil | Alasan |
|---|---|
| Semua berkas di luar dua folder di atas (`features/master-data/**`, `features/auth/**`, `shared/components/FormField.vue`, `shared/ui/*`, `shared/utils/*`, `router/index.ts` versi redesign, dll.) | Versi `main` lebih baru; mengambil versi redesign = regresi. Bila berkas yang di-port butuh perubahan kecil di `shared/ui`, sesuaikan berkas yang di-port, atau buat perubahan **aditif** di `shared/ui` dan catat di deskripsi serah |
| Tab **Konket** (`riwayat-konket`) dan **Karpeg/Karis/Karsu** (`karpeg-karis-karsu`) | Keputusan final: keduanya halaman usulan mandiri, bukan tab Detail Pegawai |
| `services/pegawai.mock.ts`, `riwayat/riwayat.mock.ts` sebagai data runtime | Data contoh tidak boleh masuk `main` sebelum tersambung API (§3.7) |

Setelah port, periksa bahwa berkas yang di-port tidak memuat penyebutan AI/assistant/tool, trailer, IP/host internal, atau tautan papan kerja internal.

### 3.2 Pensiunkan `AppShell`
- Ganti pemakaian `AppShell` dengan `RedesignShell` di 6 pembungkus halaman: `features/master-data/views/FaqPage.vue`, `HariLiburPage.vue`, `MasterDataPage.vue`, `WebConfigPage.vue`, `features/auth/views/ChangePasswordPage.vue`, `UserManagementPage.vue` — plus `features/dashboard/views/HomeView.vue` dan rujukan lain yang ditemukan `grep -rn AppShell frontend/src`.
- Hapus `shared/components/AppShell.vue` dan `__tests__/AppShell.spec.ts`; pindahkan asersi yang masih relevan (menu, logout, ganti password) ke test shell/nav.
- `nav.config.ts` wajib memuat **semua menu yang ada di `AppShell` di `main`**: Beranda, Manajemen Akun, Master Data, **Web Config**, Hari Libur, FAQ, Ganti Password — dengan role yang sama seperti di `main` (`USER_MANAGEMENT_ROLES`, `MASTER_DATA_ROLES`, `HARI_LIBUR_READ_ROLES`, `WEB_CONFIG_ROLES`). Tidak boleh ada menu yang hilang.

### 3.3 Route per WS
- Buat `frontend/src/features/kepegawaian/routes.pegawai.ts` (milik WS-2) dan `routes.riwayat.ts` (milik WS-1), masing-masing mengekspor `RouteRecordRaw[]`; impor **sekali** dari `router/index.ts` (spread ke daftar route). Setelah S0, WS-1 hanya mengedit `routes.riwayat.ts`.
- `routes.pegawai.ts`: Daftar Pegawai (`/pegawai`, `pegawai-list`, role `PEGAWAI_LIST_ROLES`), Detail Pegawai (`/pegawai/:nip`), Struktur Organisasi (`/struktur-organisasi`), Usulan Konket, Verifikasi LKH.
- `routes.riwayat.ts`: Usulan Karpeg/Karis.
- Halaman yang belum ada (Konket, Verifikasi LKH, Usulan Karpeg/Karis) diarahkan ke halaman **placeholder kosong** milik WS pemiliknya (judul + keterangan "belum tersedia", tanpa data contoh). Pemiliknya menggantinya di milestone berikutnya.

### 3.4 `nav.config` — semua menu Fase 3 didaftarkan sekarang
- Tambahkan entri: **Daftar Pegawai**, **Struktur Organisasi**, **Usulan Konket**, **Usulan Karpeg/Karis**, **Verifikasi LKH** dengan `phase: 3` dan role mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; untuk Karpeg/Karis: role 1, 2, 4, 5, 7). Tampilan menu hanya UX; backend tetap menegakkan role.
- Set `ACTIVE_PHASE = 2` di `main` supaya menu Fase 3 **tersembunyi** sampai halamannya tersambung API. WS-2 menaikkan ke 3 di B-20 penutup. Test nav memastikan menu Fase 3 tersembunyi saat `ACTIVE_PHASE = 2` dan tampil saat 3.
- Setelah S0, WS-1 tidak menyentuh `router/index.ts` dan `nav.config.ts`.

### 3.5 Registry tab riwayat per jenis
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

### 3.6 Tipe, komponen bersama, re-export
- **`types.ts`** disamakan dengan kolom DDL PR #20 (snake_case persis nama kolom; mis. `glr_awal`, `glr_akhir`, `tgl_lahir`, `bpjs_kes`, `id_provinsi_lahir`). Sumber: migration `2026-09-30-120000_CreatePegawai.php`, `120100_CreatePegawaiSnapshot.php`, `13*` (riwayat), dan dokumen db-review B-01/B-02 §3. Kolom konfigurasi tab per jenis memakai nama kolom DDL tabel `riwayat_*`-nya (bukan nama karangan redesign). Tambahkan tipe `RiwayatTabDescriptor` dan `StatusRiwayat` (0 Menunggu, 1 Disetujui, 2 Ditolak, 10 Dihapus; 3 Diproses hanya bila flag aktif — default nonaktif).
- **`shared/components/StatusBadge.vue`** (baru, generik): status riwayat → badge (Disetujui hijau, Menunggu kuning, Ditolak merah, Dihapus abu-abu; Diproses hanya bila flag). `features/master-data/components/StatusBadge.vue` yang sudah ada **tidak** dipindah/diubah.
- **`shared/components/ApprovalDialog.vue`** (baru): mode Setujui/Tolak; **alasan wajib saat Tolak** (validasi + pesan); slot `diff` untuk perbandingan biodata B-04; emit `{ aksi: 'setujui' | 'tolak', reason_note }`.
- **Menu ⋮**: item "Setujui" dan "Tolak" masuk grup "aksi lain" (sesudah Edit/ubah status/urutan, **sebelum** "Hapus"), sesuai AGENTS.md §1. Pastikan semua aksi baris di berkas yang di-port memakai `RowActionsMenu` dengan `label` "Aksi untuk <nama baris>" dan `testid` `<fitur>-actions-<id>`; test memilih item lewat `data-action`.
- **Re-export, tidak dipindah**: `useCascadeOptions` (dari `features/master-data/composables`) dan `masterService.options` di-re-export lewat `src/shared` (mis. `src/shared/composables/useCascadeOptions.ts`, `src/shared/services/masterOptions.ts`). Berkas asal di `features/master-data` tidak diubah.

### 3.7 Tanpa data contoh di runtime
- `pegawai.service.ts` dan `riwayat.service.ts` memanggil API sesuai kontrak (`GET /pegawai`, `GET /pegawai/{nip}`, `GET/POST/PUT/DELETE /pegawai/{nip}/riwayat/{jenis}[/{id}]`, `POST …/{id}/process`, lampiran `…/pegawai/{nip}/lampiran`) lewat klien `api` yang sudah ada (`@/lib/axios`).
- Halaman menampilkan keadaan kosong/galat yang rapi saat backend menjawab 501/404 (backend belum ada sampai milestone berikutnya).
- Data contoh boleh dipakai **hanya di test** (fixture di `__tests__/`), tidak diimpor kode runtime.

## 4. Di luar cakupan S0-B
Implementasi halaman Konket/LKH/Karpeg-Karis (placeholder saja), tersambungnya API (milestone berikutnya), semua backend, perubahan `features/master-data/**` selain pembungkus halaman (§3.2), perubahan skema.

## 5. Berkas

| BOLEH disentuh (S0-B) | DILARANG |
|---|---|
| `frontend/src/shared/layouts/**` (baru dari port) | Seluruh `backend/**` (milik S0-A), termasuk `Routes.php`, `Services.php`, migration |
| `frontend/src/features/kepegawaian/**` | **Semua migration yang sudah ada di `main`**, `_support` milik PR #20 (`backend/tests/_support/LepasMigrationKepegawaianTrait.php`, `SkemaKepegawaianTestTrait.php`, `Kepegawaian/SkemaD1*.php`) |
| `frontend/src/router/index.ts` (impor route file + hapus route Fase 3 lama bila ada), `router/__tests__/**` | Isi `features/master-data/**` dan `features/auth/**` selain penggantian shell di pembungkus halaman (§3.2) |
| `frontend/src/shared/components/StatusBadge.vue`, `ApprovalDialog.vue` (+ test), penghapusan `AppShell.vue` + spec | `features/master-data/components/StatusBadge.vue`, `features/master-data/composables/useCascadeOptions.ts`, `features/master-data/services/master.service.ts` (hanya di-re-export) |
| `frontend/src/shared/composables/**`, `frontend/src/shared/services/**` (re-export) | `shared/ui/*` kecuali perubahan aditif kecil yang benar-benar dibutuhkan (dicatat) |
| Pembungkus halaman yang memakai `AppShell` (§3.2), `frontend/src/shared/README.md` (catatan shell/registry) | `.env`, berkas kredensial apa pun; `composer.json` |

## 6. Kontrak yang dibekukan di akhir S0
Setelah MAKE-003 di-merge, berikut hanya berubah **aditif** dan **oleh pemiliknya**:
1. Bentuk `RiwayatTabDescriptor` (sama dengan backend S0-A) dan props `RiwayatTabHost` (`nip`, `descriptor`, `config`) — pemilik WS-1.
2. Mekanisme registry `riwayat/jenis/<slug>.ts` + `import.meta.glob` dan daftar slug — pemilik WS-1.
3. Props/emit `StatusBadge` dan `ApprovalDialog` — pemilik WS-2 (perubahan dari WS-1 diajukan ke WS-2).
4. `routes.pegawai.ts` (WS-2) / `routes.riwayat.ts` (WS-1); `router/index.ts` dan `nav.config.ts` milik WS-2.

## 7. Definition of Done
- [ ] Port per berkas §3.1 selesai; tidak ada merge/cherry-pick dari branch redesign; `git log` branch hanya berisi commit MAKE-003 Anda.
- [ ] `AppShell` pensiun; semua halaman lama tampil di `RedesignShell`; semua menu `main` (termasuk Web Config) ada di `nav.config`.
- [ ] `routes.pegawai.ts`/`routes.riwayat.ts` diimpor sekali dari `router/index.ts`; menu Fase 3 terdaftar dengan `phase: 3`, tersembunyi pada `ACTIVE_PHASE = 2`.
- [ ] Registry per jenis (15 berkas sesuai tabel §3.5) + loader `import.meta.glob`; `DetailPegawaiPage` merender tab dari descriptor; tab Konket & Karpeg/Karis tidak ada.
- [ ] `types.ts` = kolom DDL; `StatusBadge` + `ApprovalDialog` (alasan wajib saat tolak, slot diff) + test; item ⋮ Setujui/Tolak sebelum Hapus.
- [ ] `useCascadeOptions`/`masterService.options` di-re-export lewat `src/shared` tanpa memindah berkas asal.
- [ ] Tidak ada data contoh di runtime: tidak ada kode non-test yang mengimpor `*.mock.ts` (`grep -rn "\.mock'" frontend/src --include=*.ts --include=*.vue` hanya menemukan berkas di `__tests__/`).
- [ ] Gate cepat hijau selama kerja (`cd frontend && npm run check`); **gate penuh `./check.sh` hijau sekali** pada commit terakhir yang diserahkan (log ringkas + `EXIT=0` di deskripsi serah).
- [ ] Commit sesuai aturan §8; tidak ada berkas terlarang (§5) yang berubah (`git diff --stat origin/main...HEAD` diperiksa).
- [ ] Deskripsi serah: ringkasan, daftar berkas yang di-port vs ditulis baru, penyesuaian `shared/ui` (bila ada), kontrak yang dibekukan, hal yang perlu diketahui Qoder-1.

## 8. Aturan kerja wajib
1. **Worktree sendiri** dari `origin/main` (jangan bekerja di checkout milik orang lain). Setelah membuat worktree: `cd frontend && npm ci`, `cd backend && composer install` (backend dibutuhkan `check.sh`).
2. **DB scratch sendiri** untuk bagian PHPUnit gate penuh: mis. `simpeg_v2_ws2_dev` dan `simpeg_v2_ws2_testing` (collation `utf8mb4_unicode_ci`). Tulis `.env` worktree sendiri dari `backend/.env.example`; **jangan menyalin `.env` dev** dari tempat lain.
3. **Gate cepat** selama kerja: `cd frontend && npm run check` (ESLint, vue-tsc, Vitest, build). Backend tidak berubah di S0-B, jadi PHPUnit terarah tidak diperlukan selama kerja.
4. **Gate penuh sekali** sebelum serah, pada commit yang diserahkan, **sebagai proses lepas** dengan log ke berkas, mis. `nohup ./check.sh > gate.log 2>&1 &` (atau `Start-Process` di PowerShell, lihat AGENTS.md §3). Satu gate penuh pada satu waktu per mesin; bila setelah gate hanya dokumen yang berubah, gate tidak perlu diulang.
5. **Commit**: Bahasa Indonesia, gaya `feat(kepegawaian): MAKE-003 …` / `refactor(frontend-ui): MAKE-003 …` / `test(kepegawaian): MAKE-003 …`. **Tanpa** trailer `Co-Authored-By` dan tanpa menyebut AI/assistant/tool apa pun di pesan commit, komentar, atau dokumen.
6. **Rahasia & repo publik**: jangan commit `.env`, kredensial, token, password, API key; jangan menulis IP/host internal, nama server, atau detail celah keamanan.
7. **UI**: semua aksi baris tabel lewat satu tombol ⋮ (`RowActionsMenu`), urutan Edit → ubah status → urutan → aksi lain (Setujui/Tolak/Pulihkan) → Hapus (`danger: true`), label kata kerja Bahasa Indonesia, aksi berisiko memakai `ConfirmDialog` (AGENTS.md §1).
8. **Tidak push ke `main`.** Serahkan dengan push branch fitur `ws2/…` (mis. `ws2/make-003-s0b-fondasi-frontend-fase3`) ke `origin`. Review CR dilakukan oleh sesi utama, yang kemudian memasukkan ke `main` (alur CR-only).
9. Bila ada aturan di briefing ini yang bertentangan dengan AGENTS.md atau kode di `main`, **tanyakan reviewer CR** sebelum menyimpang.

## 9. Estimasi
| Bagian | ±Jam-agen |
|---|---|
| Port per berkas + pensiun AppShell + nav semua menu `main` | 3 |
| Route per WS + placeholder + `ACTIVE_PHASE` | 1,5 |
| Pecah registry per jenis + `RiwayatTabHost` + render dari descriptor | 3 |
| `types.ts` = DDL | 1,5 |
| `StatusBadge` + `ApprovalDialog` + ⋮ Setujui/Tolak + re-export | 1,5 |
| Lepas data contoh dari runtime + sesuaikan test | 1 |
| Gate cepat + gate penuh (lepas) | 0,5 + waktu gate |
| **Total** | **±1,5 hari-agen** (gate penuh berjalan sendiri ±80 menit) |

## 10. Titik koordinasi dengan Qoder-1 (S0-A)
| Kapan | Apa | Arah |
|---|---|---|
| ±jam ke-2 | Terima draf bentuk descriptor, daftar slug `{jenis}`, dan bentuk endpoint riwayat/lampiran dari README kontrak Qoder-1; sesuaikan tipe, nama berkas registry, dan klien API | Qoder-1 → Qoder-2 |
| ±jam ke-4 | Konfirmasi payload snake_case = kolom DDL (Anda menyamakan `types.ts` dari DDL yang sama) | dua arah |
| Akhir S0 | Kedua branch diserahkan; reviewer CR memasukkan MAKE-002 (kontrak backend) lebih dulu, lalu MAKE-003 | — |
| Setelah S0 | Permintaan perubahan kontrak diajukan ke pemiliknya (§6), tidak mengedit berkas WS lain | dua arah |

Anda **tidak** menyentuh backend di S0; Qoder-1 **tidak** menyentuh frontend.

## 11. Keputusan yang sudah final (jangan ditanyakan ulang)
1. Build berangkat dari `main` (skema B-01/B-02, G-02, G-03, G-09 sudah di `main`; Fase 2 sign-off 07-10-2026).
2. Tidak ada branch integrasi: tiap WS branch sendiri dari `main`, masuk `main` per milestone sebagai CR-only; gate penuh sekali sebelum push.
3. Pembagian: B-18 di WS-2, B-15 di WS-1, B-12 dipecah (SKP WS-1, LKH WS-2), B-20 dipecah (dasar di B-03, penutup di akhir WS-2).
4. Hak akses mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; Hukdis role 1/3; Jabatan & AK sesuai Matriks; Karpeg/Karis role 1, 2, 4, 5, 7 sesuai DoD), **kecuali** approver LKH = atasan langsung (ikut legacy). Izin disimpan sebagai data (Definisi per jenis).
5. Konket dan Karpeg/Karis = **halaman usulan mandiri**, bukan tab Detail Pegawai; **layout ikut legacy** (menu sendiri, daftar/antrian, form usulan, alur proses), **style ikut redesign** (token + komponen shared, aksi baris ⋮). Halaman Fase 3 lain mengikuti layout redesign.
6. Batas lampiran per jenis 1/2/5 MB ikut legacy.
7. PDF (LKH, DRH) memakai TCPDF lewat ADR-033 (`docs/adr/ADR-033-library-pdf-tcpdf.md`; lisensi LGPL-3.0, sama dengan legacy); cadangan dompdf bila TCPDF bermasalah; QA Lapis 1/review/sesi QA tidak.
10. Default ikut legacy: acuan jarak KGB = KP/KGB terakhir; cascade NIP ikut `update_nip` legacy (+ `jabatan_koordinasi.nip`); masa hukdis = `masa_sanksi_bulan`; aturan lingkup unit destinasi 21 / unit lain 7.
11. Key: satu key CR per paket milestone per WS; S0-B = **MAKE-003**. Peta lengkap di PRD §7.1.
12. Data contoh tidak masuk `main` sebelum tersambung API.
13. Aturan proyek: aksi baris lewat ⋮ (AGENTS.md §1); skema ikut DDL legacy, snake_case = nama kolom; migration di `main` tidak diedit; repo publik (tanpa IP/host internal).
14. Serah-terima (keputusan 07-10-2026): cara serah = **push branch fitur `ws2/…` ke `origin`** (tidak ke `main`); review CR dilakukan oleh sesi utama, yang kemudian memasukkan paket ke `main` (alur CR-only).
15. Menu Fase 3 tersembunyi dengan `ACTIVE_PHASE = 2` sampai B-20 penutup (keputusan 07-10-2026); WS-2 yang menaikkan ke 3.
16. Slug `{jenis}` dan signature interface backend boleh diperhalus Qoder-1 selama S0, lalu dibekukan di akhir S0; Anda menyelaraskan tipe dan nama berkas registry dengan versi beku.
17. Acuan di repo: kontrak task Fase 3 `docs/fase3/03-Kepegawaian.md`, matriks role Modul B `docs/fase3/MATRIKS_ROLE_MODUL_B.md`, ADR library PDF `docs/adr/ADR-033-library-pdf-tcpdf.md`.
