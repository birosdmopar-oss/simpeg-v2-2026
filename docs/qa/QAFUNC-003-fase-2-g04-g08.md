# QAFUNC-003 (G-04..G-08): QA Master Data Gelombang 2 — QASMTASK-036, 037, 038, 039, 040

| | |
|---|---|
| **Jenis** | QA Functional (black-box API + DB + UI), dijalankan reviewer CR atas persetujuan user |
| **Tanggal eksekusi** | 29 September 2026 |
| **Commit yang diuji** | `e602205` (`main`; DBV-003/CR-010 PR #12 `d1cf1b3`, DBV-004/CR-011 PR #13 `acd8693`, DBV-005/CR-012 PR #14 `e602205`) |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 Laragon, `php spark serve` per area (8141 G-04/G-05, 8142 G-06, 8143 G-07/G-08; API 8089 + Vite 5173 untuk UI), DB scratch per area hasil `migrate --all` + `MasterDataSeeder`, captcha `mock`; akun dari seeder test |
| **Rujukan** | Kartu QASMTASK-036..040 (DoD), G-TC #1–#7, `backend/docs/db-review/G-04-G-05-…`, `G-06-…`, `G-07-G-08-…`, README MasterData |
| **Hasil** | **Pass untuk DoD fungsional** (dikoreksi 29-09-2026, lihat "Koreksi 29-09-2026" di bawah). Semua butir DoD fungsional kelima kartu PASS; butir "Seluruh G-TC" 036–039: G-TC #1–#6 PASS, #7 QA Lapis 1 SKIP (visual vs Figma — desain belum ada; kriteria layout → **ISSUE-007**), sehingga G-04..G-07 (QASMTASK-036..039) **belum Done** karena QA Lapis 1. 040 (G-08) PASS penuh (DoD tanpa butir QA Lapis 1). Satu FAIL minor lintas master (isu lama engine generik, **ISSUE-019**, bukan regresi Gelombang 2). |

## Ringkasan per DoD

| Kartu | DoD | Hasil | Bukti utama |
|---|---|---|---|
| 036 (G-04) | CRUD pangkat/golongan PNS | PASS | `gol_ruang`, urutan = level mode manual (nilai tetap, tidak menggeser, level kembar diterima — lihat catatan), filter `?cpns=1\|2` |
| 036 | CRUD jenis_kp | PASS | tambah akhir/sisip, saudara bergeser tanpa stamp, audit per saudara |
| 036 | CRUD gol_pppk | PASS | `uang_makan` 0..10.000.000 (desimal) bisa diubah role 1, hapus → status 10 |
| 036 | Seluruh G-TC | G-TC #1–#6 PASS; #7 QA Lapis 1 SKIP (Figma belum ada; kriteria layout → ISSUE-007) | unik (huruf besar/kecil, spasi, termasuk entri terhapus + saran pulihkan), soft delete, toggle → options/cache, reorder, RBAC, audit ber-actor |
| 037 (G-05) | CRUD jenjang_pendidikan | PASS | UNIQUE nama & singkatan, `row_jurusan` 7 kode tetap (CHECK biner, `s_1`/`S_1 ` → 422), `bobot_ipasn` tidak diekspos & default NULL |
| 037 | CRUD bidang & jurusan | PASS | UNIQUE(id_bidang, nama), minimal satu flag jenjang |
| 037 | Dropdown berjenjang bidang → jurusan | PASS | statusChain: bidang nonaktif → jurusannya hilang dari options, cache ter-invalidasi |
| 037 | Seluruh G-TC | G-TC #1–#6 PASS; #7 QA Lapis 1 SKIP (Figma belum ada; kriteria layout → ISSUE-007) | idem 036 |
| 038 (G-06) | CRUD diklat | PASS | `jenis_diklat` wajib 1–5, UNIQUE(jenis, nama), urutan per jenis + filter `?jenis_diklat=` |
| 038 | CRUD tingkat & jenis hukdis | PASS | induk wajib aktif, statusChain, `masa_sanksi_bulan` opsional 1–255, `bobot_ipasn` tidak diekspos |
| 038 | CRUD jenis_konket + `affect_tukin` | PASS | 1/2 wajib di API (default 1 hanya di DDL), `old_id` wajib ≥ 1 & unik |
| 038 | CRUD tanda_jasa | PASS | |
| 038 | 4 controller terpisah | PASS | 40 route: Diklat, Hukdis (tingkat+jenis), Konket, TandaJasa |
| 038 | Seluruh G-TC | G-TC #1–#6 PASS; #7 QA Lapis 1 SKIP (Figma belum ada; kriteria layout → ISSUE-007) | #1–#6 untuk 5 master |
| 039 (G-07) | CRUD agama, jenis_pegawai, jenis_status, kantor | PASS | kantor: rantai wilayah konsisten, LAIN-LAIN berjenjang + `*_lain` wajib, `kode_pos` 5 digit & salah satu `kelurahan.kd_pos` |
| 039 | CRUD wilayah 4 level | PASS | sentinel 99/9999/9999999/9999999999 tidak tampil di options/daftar admin dan tidak bisa diubah/dinonaktifkan/dihapus/jadi induk riil |
| 039 | Dropdown berjenjang wilayah 4 level | PASS | |
| 039 | Seluruh G-TC | G-TC #1–#6 PASS; #7 QA Lapis 1 SKIP (Figma belum ada; kriteria layout → ISSUE-007) | 438/438 cek untuk 11 master |
| 040 (G-08) | CRUD hari_libur | PASS | baca role 1/4/5/8 (4/5/8 hanya status 1, tanpa kolom audit), tulis role 1, soft delete + pulihkan |
| 040 | Validasi tgl_mulai ≤ tgl_akhir | PASS | termasuk tanggal tidak valid (30 Feb, 29 Feb non-kabisat) → 422 |
| 040 | Range tidak boleh overlap | PASS | inklusif terhadap status 1/2/10; balapan 3 ronde × 12 worker CLI + 12 worker `tgl_mulai` sama → tepat 1×201; lock dipegang sesi lain → 409 tanpa tulis |
| 040 | Unit test kedua validasi pass | PASS | `HariLiburTest` + `HariLiburRulesTest` (tests/unit) OK |

Tambahan yang ikut PASS: PK AUTO_INCREMENT habis → 422 (TINYINT dan INT, jalur MySQL 1062); CHECK tidak pernah 500 dari API; FK RESTRICT jenis → tingkat hukdis; kapasitas urutan TINYINT 127 → 422 tanpa tulis; CR-007 encoding; input array/objek di body → 422; `tanggalLibur()` hanya status 1. Regresi: FAQ E2E 84/84, Batch 1 agama/wilayah PASS (8 selisih harness lama = perubahan terencana sentinel Gelombang 2).

## QA UI (browser, desktop pane)

| # | Langkah | Hasil |
|---|---|---|
| U-1 | Super Admin → Master Data | 25 master tercantum; 15 master baru menampilkan data dengan satu tombol ⋮ per baris (tanpa tombol ikon/switch lain, aturan CR-015) |
| U-2 | Form tambah master baru | Field sesuai konfigurasi tiap master |
| U-3 | Kantor → provinsi LAIN-LAIN (99) | Pilihan turunan hanya 9999/9999999; empat isian "… Lainnya *" muncul |
| U-4 | Hari Libur (Super Admin) | Kolom Tanggal \| Hari \| Nama Libur \| Jenis \| Keterangan \| Status \| Aksi; `tgl_akhir` otomatis mengikuti `tgl_mulai`; rentang bentrok → "Rentang tanggal bentrok dengan hari libur "Tahun Baru 2026 Masehi" (2026-01-01 s.d. 2026-01-01)."; tambah valid → "Hari libur "QA Uji Bentrok" ditambahkan."; menu ⋮: Edit, Nonaktifkan, Hapus (merah) |
| U-5 | Role 4 (Admin View) → Hari Libur | Menu Beranda, Hari Libur, FAQ, Ganti Password; tabel read-only tanpa kolom Aksi dan tanpa tombol Tambah |

## Temuan

| ID | Tingkat | Temuan | Tindak lanjut |
|---|---|---|---|
| F-LIST | Minor | Daftar admin semua master engine generik: `?search[]=`, `?status[]=`, `?parent[]=` → 500 "Array to string conversion"; `?page` sangat besar → 500 (offset float). Isu lama QAFUNC-002-R2 BX-1/BX-2/F1, kini juga di master baru | ISSUE-019 (PR #11) |
| F-OPT | Minor | Options berjenjang `?parent[]=1` (juga `parent[a]=1`) → 200 berisi seluruh data lintas induk (filter diabaikan diam-diam), padahal `parent` non-kanonik → 422 | Ditambahkan ke cakupan ISSUE-019 |
| INFO | — | `pangkat` mode manual menerima level kembar (UNIQUE(cpns, order) ditunda, catatan DBV-004 #5 "hanya dicegah aplikasi" belum ada di kode); pencarian admin tidak meng-escape `%`/`_` (ISSUE-019); `affect_tukin` di form FE mulai "Pilih…", tidak pra-pilih 1; pesan bentrok hari libur tampil di kedua field tanggal; urutan berikutnya = MAX+1 per lingkup (data impor berlubang bisa cepat mencapai 127); nama "Lain-Lain" boleh untuk entri riil level 2–4 (keunikan per induk) | Dicatat; tidak memblokir |
| SKIP | — | QA Lapis 1 (Figma) — desain belum ada; jalur MariaDB errno 167 — server QA MySQL 8 (sudah diverifikasi DB Validator di MariaDB 10.4); balapan HTTP sejati — `php -S` Windows single-thread (balapan dibuktikan lewat worker CLI) | QA Lapis 1 = G-TC #7 di butir "Seluruh G-TC" 036–039 → belum lolos; layout form ISSUE-007 (ikut redesign, MIG-001b — keputusan user 29-09-2026). Lainnya — |

## Bersih-bersih

Server QA dihentikan; DB scratch QA di-drop dengan nama persis; worktree QA tidak mengubah kode yang diuji. Data uji hanya di DB scratch.

## Koreksi 29-09-2026

Dicatat CR-022 setelah audit kartu Fase 2. Data uji, bukti, dan hasil tiap cek di atas tidak diubah; yang dikoreksi hanya penilaian butir "Seluruh G-TC" 036–039, ringkasan Hasil di kepala laporan, dan kolom tindak lanjut baris SKIP.

1. Versi awal laporan ini menulis butir "Seluruh G-TC" 036–039 sebagai **PASS** dan ringkasan "Semua butir DoD kelima kartu PASS", padahal G-TC #7 (QA Lapis 1: form CRUD, status, badge vs Figma) berstatus **SKIP** di bagian Temuan. Kolom Hasil keempat baris itu dikoreksi menjadi "G-TC #1–#6 PASS; #7 QA Lapis 1 SKIP", dan ringkasan Hasil di kepala laporan disesuaikan: DoD fungsional lolos, tetapi G-04..G-07 (QASMTASK-036..039) belum Done karena QA Lapis 1. QASMTASK-040 (G-08) tidak terpengaruh.
2. Figma tidak punya frame Master Data (QAUI-002), dan kriteria tertulis QAUI-002 #1 (layout form) gagal → **ISSUE-007**. Keputusan user 29-09-2026: layout form admin ikut redesign (label di atas, dua field per baris; kriteria QAUI-002 #1 dibaca sesuai redesign), diselesaikan lewat MIG-001b di branch redesign; kriteria "toggle switch" (G-TC #7, QAUI-002 #3, MTC-009) dibaca sebagai aksi status Aktifkan/Nonaktifkan lewat menu ⋮ (CR-015). QA Lapis 1 G-04..G-07 dijalankan ulang setelah MIG-001b masuk main.
3. G-TC #5 (RBAC) dinilai dengan endpoint `{master}/options` terbuka untuk semua role login; ini sesuai keputusan user ISSUE-012 29-09-2026 (opsi A: "Super Admin only" di Matriks berlaku untuk CRUD, master FAQ tetap role 1), sehingga hasil PASS tetap berlaku.
4. Label kartu mengikuti koreksi ini: QASMTASK-036/037/038 kembali Marked, 039 tetap Marked (ISSUE-007); butir DoD "Seluruh G-TC … QA Lapis 1" di keempat kartu di-uncheck. Progres: `backend/docs/progress/02-MasterData.md` (G-04..G-07 IN_PROGRESS).
