# QAUI-002 — Fase 2: Visual Check Master Data

| | |
|---|---|
| **Jenis** | QA UI/UX (Lapis 1) |
| **Tanggal eksekusi** | 23 September 2026 |
| **Commit yang diuji** | `7494ce7` — "Fase 2 — Modul G: Master Data & Pengaturan" |
| **Lingkungan** | Lokal — frontend `http://localhost:5173` (Vite), API `http://localhost:8089`, login Super Admin `198501012010011001`, viewport desktop 1366×850 |
| **Acuan desain** | Figma `Simpeg-Kemenpar` (file `eYZLmyDsebI8ZRy871BJHn`) + kriteria QA UI/UX di kartu QAUI-002 |
| **Hasil** | **1 grup master bisa diuji (G-07), 8 grup belum ada UI** |
| **Status penulisan Trello** | Ditunda — pengembangan Fase 2 (prompt DEV-003 2/2) belum selesai karena ISSUE-003. Hanya temuan layout form yang dicatat (ISSUE-007). QA UI penuh diulang setelah G-01 s.d. G-10 selesai |

## Keterbatasan acuan Figma

File Figma dibuka dalam mode lihat (view-only). Halaman **Main** berisi: Dashboard Admin/Pimpinan, Daftar/Detail Pegawai, Laporan, Struktur Organisasi, Status Layanan, Chat Admin, FAQ (Topic List/Chat), Portal Berita, Notif, Analytics. Halaman **Component** berisi: Avatar, Dropdown, Checkbox, Input, dan palet warna (Primary, Semantic, Color).

**Tidak ada frame Master Data** (form CRUD master, toggle status, badge status, map picker, dropdown berjenjang) di file tersebut. Karena itu pemeriksaan memakai kriteria tertulis di kartu QAUI-002 dan palet Laporan Redesign (primary `#1C3964`). Pembandingan piksel terhadap Figma untuk Modul G **tidak dapat dilakukan** sampai desainnya tersedia. Server MCP Figma belum diotorisasi di sesi ini, sehingga inspeksi token/variabel Figma juga tidak tersedia.

## Cakupan UI yang tersedia di commit ini

Menurut `backend/docs/progress/02-MasterData.md`, UI yang sudah dibangun hanya **G-07** (agama, jenis pegawai, jenis status, provinsi, kabupaten/kota, kecamatan, kelurahan) di `/master/:entity?`. G-02 s.d. G-06 dan G-08 s.d. G-10 masih TODO/blocked (DDL legacy, ISSUE-003).

## Checklist kriteria (diuji pada G-07)

| # | Kriteria QAUI-002 | Hasil | Bukti |
|---|---|---|---|
| 1 | Form CRUD: layout grid 2 kolom, label kiri + input kanan | ❌ **Tidak sesuai** | Form "Tambah Kelurahan/Desa" satu kolom, **label di atas input** untuk 6 field (tidak ada `display: grid` 2 kolom di dialog) |
| 2 | Asterisk merah untuk field wajib | ✅ | `Kode *`, `Kecamatan *`, `Nama Kelurahan/Desa *` → warna `rgb(220, 38, 38)`; field opsional tanpa asterisk |
| 3 | Toggle switch status aktif/non-aktif | ✅ | Radix Switch `role="switch"` di tiap baris; aktif `rgb(22,163,74)`, non-aktif `rgb(203,213,225)`. Klik → badge berubah, DB `status` berubah, audit `update` tercatat (actor Super Admin); dikembalikan setelah uji |
| 4 | Badge status: Aktif = hijau, Non-aktif = abu | ✅ | Aktif bg `rgb(220,252,231)` teks `rgb(22,101,52)`; Non-aktif bg `rgb(226,232,240)` teks `rgb(71,85,105)` |
| 5 | Tombol simpan biru navy (primary), batal abu (default) | ✅ | Simpan bg `rgb(28,57,100)` (#1C3964) teks putih; Batal outline border `rgb(203,213,225)` teks `rgb(51,65,85)`. Tombol "Tambah" juga navy |
| 6 | Map picker lokasi presensi (G-03): lat/long + radius | ⛔ **Belum ada** | G-03 belum diimplementasikan |
| 7a | Dropdown berjenjang wilayah 4 level (G-07) | ✅ | Provinsi → Kab/Kota → Kecamatan: level bawah disabled sampai induk dipilih; opsi tersaring per induk (DKI → Jakarta Pusat/Utara; Jakarta Pusat → Gambir/Sawah Besar); ganti induk (Jawa Barat) mereset level bawah. Juga tersedia di filter tabel |
| 7b | Dropdown berjenjang bidang → jurusan (G-05) | ⛔ **Belum ada** | G-05 belum diimplementasikan |

## Status per QASMTASK

| QASMTASK | Task | Status QA UI | Catatan |
|---|---|---|---|
| 033 | G-01 Migration | N/A (bukan UI) | IN_PROGRESS, menunggu DB Validator + DDL (ISSUE-003) |
| 034 | G-02 Jabatan, Unit & Satker | ⛔ Blocked | UI belum ada |
| 035 | G-03 Lokasi Presensi | ⛔ Blocked | UI + map picker belum ada |
| 036 | G-04 Kenaikan Pangkat | ⛔ Blocked | UI belum ada |
| 037 | G-05 Pendidikan | ⛔ Blocked | UI + cascade bidang→jurusan belum ada |
| 038 | G-06 Diklat, Hukdis, Konket, Tanda Jasa | ⛔ Blocked | UI belum ada |
| 039 | G-07 Data Umum & Wilayah | ❌ **Fail** | 5 dari 6 kriteria yang berlaku lolos; layout form bukan grid 2 kolom label-kiri → **ISSUE-007**. `kantor` belum ada |
| 040 | G-08 Hari Libur | ⛔ Blocked | UI belum ada |
| 041 | G-09 Web Config | ⛔ Blocked | UI belum ada |
| 042 | G-10 FAQ | ⛔ Blocked | UI belum ada (frame FAQ di Figma adalah sisi pegawai, bukan admin CRUD) |

## Catatan

1. Layout form (kriteria #1) memakai komponen bersama `FormField` (label di atas). Perbaikan di satu komponen akan berlaku ke semua grup master, sesuai prinsip "polanya sama" di kartu QAUI-002. Perlu dipastikan dulu apakah Modul A (Login, Manajemen Akun) juga harus mengikuti pola label-kiri, karena komponen yang sama dipakai di sana.
2. Data master di DB dev diisi dari nilai `MasterDataSeeder` untuk keperluan QA (agama Konghucu di-set Non-aktif agar kedua badge terlihat).
3. Uji dilakukan di viewport desktop; tampilan mobile tidak termasuk kriteria QAUI-002 (ADR-028: modul admin cukup tablet ke atas).
