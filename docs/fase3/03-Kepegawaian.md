> Salinan kontrak task Fase 3 (sumber: Bahan Baku SIMPEG, disalin 07-10-2026); keputusan pelaksanaan ada di PRD_WS1/PRD_WS2.

# Fase 3 — Modul B: Kepegawaian Core

**Entry Criteria:** Fase 1 + Fase 2 DONE.

**Catatan:** Modul paling kritikal & paling banyak tabel. DB Validator wajib cross-check tiap kolom vs Legacy Audit sebelum migration di-approve — kalau salah di sini, error-nya baru ketahuan setelah modul lain sudah menempel.

**Referensi akses:** Matriks_Role_x_Endpoint_SIMPEG_v2.docx — Bagian 2, Modul B.

**Tidak termasuk di fase ini:** Generator Dokumen (DUK/Nominatif/Pensiun/Baperjakat) dan Arsip Digital — keduanya Modul F, dikerjakan di Fase 7.

---

## B-01 — Migration Tabel Pegawai & Snapshot

- **Status:** TODO
- **Depends on:** Fase 2 DONE
- **ADR Reference:** Mapping_Migrasi_Data_SIMPEG_v2.docx Tier 2 & Tier 3
- **Files touched:** migration `pegawai`, `pegawai_hist`, seluruh `pegawai_*` snapshot
- **Definition of Done:**
  - **Keputusan PK (`id_pegawai` vs `nip`) sudah final** sebelum task ini mulai — lihat Item Kritis #2 dokumen migrasi
  - Skema direview & di-approve DB Validator sebelum dijalankan
  - FK aktif ke seluruh master Tier 1

## B-02 — Migration Tabel Riwayat

- **Status:** TODO
- **Depends on:** B-01
- **Files touched:** migration seluruh `riwayat_*` + `document_attachment`
- **Definition of Done:**
  - Skema direview & di-approve DB Validator
  - `document_attachment`: mapping angka `jenis_rwy` (1=Alamat, 5=Diklat, dst.) didokumentasikan sebagai lookup/enum eksplisit, **bukan hardcode di kode**
  - FK aktif `riwayat_*.nip` → `pegawai.nip`

## B-03 — CRUD Biodata Pegawai (Dua Jalur)

- **Status:** TODO
- **Depends on:** B-01
- **Files touched:** `app/Controllers/Api/Kepegawaian/PegawaiController.php`
- **Definition of Done:**
  - Role 1 edit → langsung update tabel `pegawai`
  - Role 2/3/6/7 edit → masuk `pegawai_hist` status 0 (pending), tabel `pegawai` **belum berubah**
  - Role 3 (Admin Satker) hanya bisa akses pegawai di `id_satker`/`id_unit` sendiri → di luar itu 403
  - Sesuai matriks: `hr/employee/edit/{nip?}` = role 1, 2, 3, 6, 7

## B-04 — Approval Draft Biodata

- **Status:** TODO
- **Depends on:** B-03, F0-05
- **Files touched:** `app/Controllers/Api/Kepegawaian/PegawaiApprovalController.php`
- **Definition of Done:**
  - Approve → data dari `pegawai_hist` masuk ke `pegawai`, status jadi 1
  - Reject → status jadi 2, tabel `pegawai` tidak berubah
  - Sesuai matriks: `hr/employee/process/{id_hist}` = role 1, 3

## B-05 — Tambah & Hapus Pegawai

- **Status:** TODO
- **Depends on:** B-03, A-09
- **Files touched:** `app/Controllers/Api/Kepegawaian/PegawaiController.php`
- **Definition of Done:**
  - Tambah pegawai → akun `pengguna` otomatis ter-generate (regression test A-09)
  - Hapus pegawai → soft delete + akun terkait dinonaktifkan
  - Role 1 only

## B-06 — Koreksi NIP (Cascade)

- **Status:** TODO
- **Depends on:** B-02
- **Files touched:** `app/Libraries/Kepegawaian/NipCascadeService.php`
- **Definition of Done:**
  - Ubah NIP → cascade update ke 20+ tabel riwayat & snapshot dalam **1 transaction**
  - Test di data dummy dengan 20+ record riwayat terkait → semua konsisten
  - **Rollback penuh** kalau 1 tabel gagal (test transaction eksplisit)
  - Unit test wajib
  - Role 1 only

## B-07 — Riwayat Jabatan / Mutasi

- **Status:** TODO
- **Depends on:** B-02, F0-05
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/JabatanController.php`
- **Definition of Done:**
  - CRUD riwayat mutasi jabatan (struktural/JFT/pelaksana/Plt/Plh/koordinator)
  - Snapshot `pegawai_mutasi_jabatan` ter-update **hanya** saat approval final
  - Sesuai matriks: role 1, 3 (Admin), 2, 4, 5 (View)
  - Seluruh B-TC lolos

## B-08 — Riwayat Kenaikan Pangkat (KP)

- **Status:** TODO
- **Depends on:** B-02, F0-05
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/KpController.php`
- **Definition of Done:**
  - CRUD riwayat KP + snapshot sync di approval final
  - **Validasi TMT KP harus kelipatan periode reguler (1 April / 1 Oktober)** — tanggal lain ditolak
  - Unit test wajib untuk validasi TMT
  - Sesuai matriks: role 1, 3 (Admin), 2, 4, 5 (View)

## B-09 — Riwayat KGB

- **Status:** TODO
- **Depends on:** B-08
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/KgbController.php`
- **Definition of Done:**
  - CRUD riwayat KGB + snapshot sync di approval final
  - **Validasi TMT KGB minimal 2 tahun dari TMT KGB/KP terakhir**
  - Unit test wajib untuk validasi jarak 2 tahun
  - Sesuai matriks: role 1, 3 (Admin), 2, 4, 5 (View)

## B-10 — Riwayat Pendidikan

- **Status:** TODO
- **Depends on:** B-02, F0-05
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/PendidikanController.php`
- **Definition of Done:**
  - CRUD + snapshot `pegawai_pendidikan` sync di approval final
  - Sesuai matriks: role 1, 2, 3, 6, 7
  - Seluruh B-TC lolos

## B-11 — Riwayat Diklat & Seminar

- **Status:** TODO
- **Depends on:** B-02
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/DiklatController.php`, `SeminarController.php`
- **Definition of Done:**
  - CRUD keduanya, approval draft berfungsi
  - Sesuai matriks: role 1, 2, 3, 7 (keduanya)

## B-12 — Riwayat SKP & LKH

- **Status:** TODO
- **Depends on:** B-02
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/SkpController.php`, `LkhController.php`
- **Definition of Done:**
  - SKP: input & rekap tahunan/periodik — role 1, 2, 3, 7
  - LKH: aktivitas harian, verifikasi atasan langsung, generate PDF — role 1, 2, 3, 6, 7
  - LKH memperhitungkan `hari_libur` dari Fase 2

## B-13 — Riwayat Konket (Kondisi Kerja)

- **Status:** TODO
- **Depends on:** B-02
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/KonketController.php`
- **Definition of Done:**
  - CRUD pengajuan Dinas Luar/WFH/WFO
  - Flag `affect_tukin` dari master konket terbawa — **dipakai Fase 5**, catat sebagai dependency
  - Sesuai matriks: role 1, 2, 3, 6, 7

## B-14 — Riwayat Hukdis

- **Status:** TODO
- **Depends on:** B-02
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/HukdisController.php`
- **Definition of Done:**
  - CRUD sanksi (Ringan/Sedang/Berat)
  - **Tanggal berakhir sanksi terhitung otomatis** dari masa sanksi (bulan/tahun)
  - Unit test untuk kalkulasi tanggal berakhir
  - Sesuai matriks: role 1, 3 only

## B-15 — Riwayat Angka Kredit (AK)

- **Status:** TODO
- **Depends on:** B-02
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/AkController.php`
- **Definition of Done:**
  - CRUD PAK, integrasi aturan PermenPAN-RB 1/2023
  - Sesuai matriks: role 1, 2, 3, 4, 7 (perhatikan: **role 6/PTT tidak eligible**)

## B-16 — Riwayat Keluarga & Alamat

- **Status:** TODO
- **Depends on:** B-02, F0-05
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/KeluargaController.php`, `AlamatController.php`
- **Definition of Done:**
  - Keluarga (pasangan, orang tua, anak) — role 1, 2, 3, 7 — snapshot `pegawai_keluarga` sync
  - Alamat (domisili & KTP) — role 1, 2, 3, 6, 7 — snapshot `pegawai_alamat` sync
  - Dropdown wilayah berjenjang dari Fase 2 berfungsi

## B-17 — Riwayat Karpeg, Karis/Karsu, Tanda Jasa, Organisasi

- **Status:** TODO
- **Depends on:** B-02
- **Files touched:** `app/Controllers/Api/Kepegawaian/Riwayat/` (4 controller)
- **Definition of Done:**
  - Karpeg & Karis/Karsu — role 1, 2, 4, 5, 7
  - Tanda Jasa — role 1, 3 (Admin), 2, 4, 5 (View)
  - Organisasi — role 1, 2, 3, 7

## B-18 — Upload Lampiran (document_attachment)

- **Status:** TODO
- **Depends on:** B-02
- **Files touched:** `app/Libraries/Kepegawaian/AttachmentService.php`
- **Definition of Done:**
  - Upload PDF/gambar, batas ukuran sesuai legacy (1-2 MB)
  - Tersimpan dengan `id_parent` + `jenis_rwy` yang benar sesuai lookup B-02
  - File di luar tipe/ukuran yang diizinkan ditolak

## B-19 — Struktur Organisasi (Tree)

- **Status:** TODO
- **Depends on:** B-07
- **Files touched:** `app/Controllers/Api/Kepegawaian/StrukturController.php`
- **Definition of Done:**
  - Tree unit → satker → jabatan → pegawai
  - `hr/so/full` = Session Logged In; `hr/rwy/jabatan/so` = UL_ALL

## B-20 — Detail Pegawai & Pencarian (Frontend)

- **Status:** TODO
- **Depends on:** B-03 s.d. B-19
- **Files touched:** `src/features/kepegawaian/views/`
- **Definition of Done:**
  - QA Lapis 1 (visual vs Figma): profile card, 20+ tab riwayat, DataTables grid, timeline approval badge
  - Detail profil (`hr/employee/detail/{nip}`) = UL_ALL
  - Advanced search = role 1, 3, 4, 5
  - Cetak DRH standar BKN = role 1, 2, 3, 4, 5

## B-21 — Unit Test Modul Kepegawaian

- **Status:** TODO
- **Depends on:** B-06, B-08, B-09, B-14
- **Files touched:** `tests/Kepegawaian/`
- **Definition of Done:**
  - Kalkulasi periode KP (kelipatan April/Oktober) — pass
  - Kalkulasi jarak KGB 2 tahun — pass
  - Kalkulasi tanggal berakhir hukdis — pass
  - Cascade koreksi NIP + rollback transaction — pass
  - Snapshot sync logic (hanya di final approval) — pass

---

## B-TC — Test Case Generik Riwayat (berlaku ke SEMUA task riwayat B-07 s.d. B-17)

- [ ] Pegawai input mandiri → status 0 (pending), **tidak aktif** sampai di-approve
- [ ] Approval oleh role 1/3 lewat `process($id)` → status jadi 1
- [ ] Snapshot `pegawai_*` ter-update **hanya** setelah approval final, bukan saat submit
- [ ] Reject → status jadi 2, snapshot tidak tersentuh
- [ ] Role 3 (Admin Satker) dibatasi ke satker sendiri
- [ ] Role yang tidak berhak (sesuai matriks per riwayat) → 403
- [ ] Audit log tercatat (termasuk saat `syncToActiveSnapshot` dipanggil — ingat: pakai raw query builder, **wajib tulis manual ke audit_logs**)
- [ ] QA Lapis 1: DataTables grid, status badge (Disetujui=hijau, Menunggu=kuning, Ditolak=merah), modal approve/reject dengan kolom alasan

---

## Exit Criteria Fase 3

Seluruh task DONE + B-TC lolos di semua riwayat + B-21 unit test pass + **Horii sign-off** (modul kritikal).
