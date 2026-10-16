# Progress Fase 3 — Modul B: Kepegawaian Core

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `03-Kepegawaian.md`. Aturan kalkulasi dan tanggal bisnis: `backend/app/Libraries/Kepegawaian/README.md`.

**Terakhir diperbarui:** 15 Oktober 2026 — QA Fase 3 selesai: QA fungsional + QA UI Lapis 1 (laporan `docs/qa/QAFUNC-004-fase-3-kepegawaian.md`, `QAUI-003-fase-3-lapis1-kepegawaian.md`, QA ulang R1/R2), temuan diperbaiki CR-049/CR-050/CR-051 (ISSUE-033..040, di `main` `ebe1b59`); status B-03..B-21 = DONE. Sebelumnya 13 Oktober 2026 — baris WS-2 (MAKE-010..014, bagian "WS-2 M2–M6" di bawah). Sebelumnya 7 Oktober 2026 — S0-A (MAKE-002, Sprint 0-1): kontrak backend Fase 3 dibekukan, tabel status dipecah per task dengan kolom WS & Key. Riwayat sebelumnya: CR-036 (2 Oktober 2026, registry kolom NIP, format NIP, BUP pensiun) dan CR-025 (di main lewat merge `a540703` 30-09-2026, kalkulasi murni KP/KGB/hukdis/masa kerja) — rincian di bagian "Persiapan tanpa skema".
**Entry criteria:** **terpenuhi** — Fase 1 + Fase 2 DONE (Fase 2 sign-off 07-10-2026); skema B-01/B-02 (44 tabel, DBV-012/DBV-013, PR #20) dan master G-02/G-03/G-09 sudah di `main`. FK G-02 ↔ pegawai/riwayat = DBV-019 (PR #24, disetujui DB Validator 07-10-2026, di `main` 13-10-2026 `191efec`).

Pembagian kerja Fase 3: WS-1 (mesin riwayat + riwayat karier/administrasi, `docs/fase3/WS1.md`) dan WS-2 (lingkup, lampiran, NIP, biodata, jabatan/struktur, Konket, LKH, halaman pegawai, `docs/fase3/WS2.md`). Key per paket milestone = peta `docs/fase3/WS1.md` §3.8.1. **Setiap WS hanya mengubah baris miliknya** di tabel ini.

| Task | WS | Key | Status | Ringkas |
|---|---|---|---|---|
| B-01 Migration tabel pegawai & snapshot | selesai | DBV-012 (+ DBV-019 FK G-02) | DONE (skema) | Tabel `pegawai` + snapshot `pegawai_*` di `main` (PR #20). FK snapshot → master G-02 = DBV-019 (PR #24) |
| B-02 Migration tabel riwayat | selesai | DBV-013 | DONE (skema) | Tabel `riwayat_*`, `jenis_rwy`, `document_attachment` di `main` (PR #20) |
| B-03 CRUD biodata (dua jalur) | WS-2 | MAKE-010 | DONE | Role 1 langsung ke `pegawai`; role 2/3/6/7 lewat `flag_update` + satu `pegawai_hist` pending per NIP; foto `pegawai_foto`; backend dasar B-20 (daftar/detail + descriptor tab). Pemilik melihat perubahan yang diajukannya (MAKE-014) |
| B-04 Approval draft biodata | WS-2 | MAKE-011 | DONE | `biodata/pengajuan*` (role 1/3, lingkup jalur proses), diff Sekarang ↔ Diajukan + foto; halaman antrean "Pengajuan Data Umum" (MAKE-014) |
| B-05 Tambah & hapus pegawai | WS-2 | MAKE-011 | DONE | Tambah role 1 + akun (password awal = NIP). **Hapus = FISIK ikut legacy** (keputusan user 13-10-2026; kontrak `docs/fase3/03-Kepegawaian.md` menulis soft delete status 10 — deviasi tercatat [K]), 422 bila masih ada data terkait/akun ber-NIP |
| B-06 Koreksi NIP (cascade) | WS-2 | MAKE-010 | DONE | `NipCascadeService` atas registry kolom NIP (CR-036), satu transaksi + rollback; username & password akun = NIP baru, sesi dicabut (keputusan user). Penanda wajib ganti password = kebutuhan skema DBV-020 |
| B-07 Riwayat jabatan / mutasi | WS-2 | MAKE-012 / MAKE-013 | DONE | Slug `jabatan` (engine), atasan Es I–IV dari `struktur_jabatan`, Plt/Plh (`penugasan/{plt\|plh}`), sub rumpun multiselect lewat isian mentah MAKE-019 (MAKE-013) |
| B-08 Riwayat KP | WS-1 | MAKE-005 | DONE (QA Fase 3 lolos 15-10-2026) | Definisi `kp` (multi-target kp/cpns/pns, TMT periode CR-025, CPNS/PNS sekali, TMT tidak kembar, SK wajib); role 1 memilih status saat ubah (ikut legacy, keputusan user 09-10); B-TC + test jenis dengan PegawaiScope + lampiran nyata (MAKE-009); engine-v1 dibekukan (di `main`, MAKE-004/005) |
| B-09 Riwayat KGB | WS-1 | MAKE-006 | DONE (QA Fase 3 lolos 15-10-2026) | Definisi `kgb` (snapshot `pegawai_kgb`, TMT tidak kembar, SK wajib, jarak 2 tahun dari KGB/KP terakhir — keputusan #10, KP pengangkatan CPNS = TMT CPNS; role 1 pilih status saat ubah) + test DoD; FE tab tersambung di Detail Pegawai lewat `data.tabs` (MAKE-010); alamat lama `/pegawai/:nip/riwayat` dialihkan ke Detail Pegawai dengan query tab yang sama dan halamannya dihapus (MAKE-016); endpoint `GET pegawai/{nip}/riwayat` tetap (kontrak aditif). Mode "halaman usulan mandiri" mesin FE (§3.4.5) dipindah ke M4 (MAKE-007) bersama halaman Usulan Karpeg/Karis (keputusan reviewer CR 12-10-2026) |
| B-10 Riwayat pendidikan | WS-1 | MAKE-005 | DONE (QA Fase 3 lolos 15-10-2026) | Definisi `pendidikan` (self-service, snapshot jenjang tertinggi, bidang/jurusan bersyarat, Ijazah wajib); B-TC + test jenis dengan PegawaiScope + lampiran nyata (MAKE-009); engine-v1 dibekukan (di `main`, MAKE-004/005) |
| B-11 Riwayat diklat & seminar | WS-1 | MAKE-007 | DONE (QA Fase 3 lolos 15-10-2026) | Definisi `diklat` (isian per jenis, snapshot jenis 1, duplikat jenis 1, lampiran 1 MB; [V2] status ikut engine — perbaikan bug legacy `set_param`; jenis 5 rumpun/lembaga teks — kebutuhan master `rumpun_sertifikasi`/`lembaga_sertifikasi` = DBV baru) dan `seminar` (bidang/instansi teks + saran master, 1 MB); FE tab tersambung |
| B-12 Riwayat SKP & LKH | WS-1 (B-12a SKP) / WS-2 (B-12b LKH) | MAKE-008 / MAKE-013 | DONE (QA Fase 3 lolos 15-10-2026) | B-12b (WS-2): LKH `lkh/*` (atasan langsung = port getAL, verifikator [V2] siapa pun ber-NIP `nip_atasan`, PDF hanya Disetujui, timpa atasan role 1), tab LKH di Detail Pegawai + rekap LKH bawahan per bulan (MAKE-014). | B-12a: Definisi `skp` (skema nilai ≤2021 / rating ≥2022, kolom skema lain dikosongkan, duplikat periode hanya terhadap status 1, lampiran 32 wajib; aturan form legacy ditegakkan FE + server: tahun maks 2022, periode 2022 = 1 Jan s.d. 31 Des, batas nilai skala 120 mulai 2021 / 100 sebelumnya; FE isian hitung rumus form legacy, tidak menimpa nilai tersimpan saat Edit) dan `skp-periodik` (data tarik e-Kinerja: daftar hanya status 1 lewat hook `saringDaftar`, satu-satunya ubah = unggah arsip `f_arsip_1` PDF 1 MB oleh role 1, tanpa tambah/hapus/proses — [K] ikut legacy, deviasi teks PRD, keputusan user 13-10-2026). TODO: hapus arsip SKP periodik (legacy `L_skp.php:937-975`), rincian periode `bkn_periode_ekinper`. Approver LKH = atasan langsung (ikut legacy) |
| B-13 Riwayat Konket | WS-2 | MAKE-013 | DONE | Halaman usulan mandiri `konket/*` (tabel `absen_ijin`), bukan jenis engine. **Deviasi arti:** Konket = *Konfirmasi Ketidakhadiran* (legacy `hr/rwy/konket` = izin/sakit/dinas luar/cuti, berkas bukti), bukan "kondisi kerja (Dinas Luar/WFH/WFO)" seperti keterangan Matriks v2 §B. Role 4/5 lihat saja [K]. [V2] CR-050 (QA B-F1): pengajuan baru memakai `jenis_konket.affect_tukin` sebagai nilai awal (admin tetap bisa mengubah; legacy selalu 1) [K] CR-050 (QA B-F4): jenis terlarang tetap berkunci `id_jenis_konket` seperti legacy (impor mempertahankan PK; `old_id` produksi bisa NULL/ganda, nama bisa diganti), daftar dipindah ke `Config\Konket` (bawaan legacy 2/4/5/6 dan 4/5, override `.env` tervalidasi); verifikasi arti id 2/4/5/6 di data produksi sebelum go-live (runbook DBV-011 2.4 #3) |
| B-14 Riwayat hukdis | WS-1 | MAKE-006 | DONE (QA Fase 3 lolos 15-10-2026) | Definisi `hukdis` (role 1/3; [K] hapus hanya role 1 ikut legacy `Hukdis.php:171-190`, keputusan user 12-10-2026 — deviasi Matriks v2; tanpa proses, status selalu 1, jenis berjenjang dari tingkat, akhir/masa dikosongkan = dihitung dari `masa_sanksi_bulan`, SK wajib) + test DoD; FE tab seperti B-09 |
| B-15 Riwayat angka kredit | WS-1 | MAKE-008 | DONE (QA Fase 3 lolos 15-10-2026; integrasi PermenPAN-RB 1/2023 ISSUE-041 dikerjakan MAKE-021 — keputusan user 16-10-2026: master `dm_ak_jf` CRUD role 1 menu Data Master "Angka Kredit JF" tanpa hapus [K], target AK KP `GET pegawai/{nip}/ak/target-kp` port `getAKAccu`, konstanta `Config\AngkaKredit`) | `ak` (lihat 1/2/3/4/7 — role 2 hanya JF tertentu `id_group_jabatan` 2; tulis & proses role 1, keputusan 13-10-2026; tambah hanya kelompok jabatan 2/3, satu PAK menunggu per jabatan, periode tidak beririsan dengan status 0/1; [V2] isian turunan diisi server — nama, jabatan/no induk JF/TMT jabatan berantai, pangkat/TMT pangkat, masa kerja sampai tanggal PAK, AK kumulatif `dm_ak_jf`; file PAK kolom berkas 1 MB; snapshot `pegawai_ak`) dan `ak-siasn` (ubah role 1 = pasangkan jabatan JF, status tetap; snapshot `pegawai_ak_siasn` — aturan masih ⏳ dok DBV-012 #6). Skema: tabel `dm_ak_jf` dibuat DBV-020 (diisi impor; master kosong → AK kumulatif 0). `konv_ak` (konversi AK) tidak dibangun di WS-1. Perbaikan review: role 7 juga hanya JF tertentu, proses setujui/tolak mengisi `appr_at`/`appr_by` (hook afterProses, ikut legacy), jumlah AK & masa kerja hanya dihitung saat tambah atau bila AK/tanggal PAK berubah, Current AK (`jenis_ak` 1) tidak dihitung ulang. Pratinjau isian turunan di form tambah (termasuk AK Lama otomatis dari `pegawai_ak`): `GET pegawai/{nip}/riwayat/ak/pratinjau?tgl_pak=` (hook aditif `pratinjauTambah`, MAKE-017, keputusan user 13-10-2026) |
| B-16 Riwayat keluarga & alamat | WS-1 | MAKE-007 | DONE (QA Fase 3 lolos 15-10-2026) | Definisi `keluarga` (+ sub-resource anak `detail_anak`, akte kode 3, hook `entriLampiran`) dan `alamat` (snapshot `pegawai_alamat` + `pegawai_alamat_kantor`, wilayah + LAIN-LAIN, [V2] kd_pos 5 digit, KTP wajib kecuali kantor lewat hook `lampiranWajib`); FE tab + dialog anak |
| B-17 Karpeg, Karis/Karsu, tanda jasa, organisasi | WS-1 | MAKE-007 | DONE (QA Fase 3 lolos 15-10-2026) | `tanda-jasa` (snapshot 26/27/28, tambah role 1) dan `organisasi`; Karpeg/Karis = halaman usulan mandiri (`/karpeg-karis`, mode usulan mesin FE): berkas kolom lewat hook `kolomBerkas` (KolomBerkas MAKE-009), antrean `usulan/{jenis}` role 1/4/5 tanpa lingkup, [V2] proses role 1 — TODO(C-01) alihkan ke `petugas_layanan`; TODO(ADR-033) PDF tanda terima |
| B-18 Upload lampiran | WS-2 | MAKE-009 | DONE | AttachmentService + LocalStorageAdapter + kompensasi berkas, helper kolom berkas, endpoint `pegawai/{nip}/lampiran*`, `ArsipTable`; bagian "WS-2 M1" di bawah |
| B-19 Struktur organisasi | WS-2 | MAKE-012 / MAKE-013 | DONE | Templat legacy so_012025 per pilihan kementerian/unit/satker, per bulan mulai 2025-01 (TODO port templat lama), ekspor PDF tabel hierarki [V2] |
| B-20 Detail pegawai & pencarian (frontend) | WS-2 | MAKE-003 (fondasi) / MAKE-010 (dasar) / MAKE-014 (penutup) | DONE (QA Fase 3 lolos 15-10-2026) | `ACTIVE_PHASE = 3` (menu Fase 3 tampil); filter Daftar Pegawai (status bawaan Aktif, unit → satker, jenis, urutan; disembunyikan role 8); pencarian lanjutan (role 1/3/4/5, whitelist); ekspor CSV/XLSX; Cetak DRH PDF; Arsip Kepegawaian; semua tab riwayat + LKH di Detail Pegawai. Rincian bagian "WS-2 M2–M6" |
| B-21 Unit test Modul Kepegawaian | WS-1 (snapshot) / WS-2 (cascade NIP) | MAKE-008 / MAKE-010 | DONE (QA Fase 3 lolos 15-10-2026) | CR-025 (periode KP, jarak KGB, akhir hukdis) + snapshot sync hanya di approval final (WS-1 MAKE-008, `tests/Kepegawaian/Riwayat/SnapshotApprovalFinalTest.php`). Butir WS-2 cascade NIP + rollback: `tests/Kepegawaian/NipCascadeTest.php` (MAKE-010) |

Paket lintas task: S0-A kontrak backend (MAKE-002, bagian di bawah), S0-B fondasi frontend (MAKE-003, WS-2), WS-1 M1 SnapshotSync + RiwayatEngine (MAKE-004), WS-2 M1 PegawaiScope + B-18 (MAKE-009, bagian di bawah).

## WS-2 M2–M6 — MAKE-010..014 (biodata, NIP, pegawai, jabatan/struktur, Konket, LKH, B-20 penutup)

Satu branch per milestone dari `origin/main`, tanpa migration (kebutuhan skema dicatat untuk DBV: penanda wajib ganti
password + `pegawai_hist.glr_*` = DBV-020). Keputusan bisnis user dicatat [K]/[V2] di README `Controllers/Api/Kepegawaian`
dan `Libraries/Kepegawaian`.

- **M2 MAKE-010:** B-06 koreksi NIP (cascade + akun), B-03 biodata dua jalur, backend dasar B-20.
- **M3 MAKE-011:** B-04 approval biodata, B-05 tambah/hapus pegawai (**hapus fisik ikut legacy** — keputusan user; bukan
  soft delete status 10 seperti kontrak awal), status pegawai 1↔2 (akun role 2 dinonaktifkan).
- **M4 MAKE-012:** B-07 jabatan (engine) + Plt/Plh, B-19 struktur organisasi templat legacy.
- **M5 MAKE-013:** B-13 Konket, B-12b LKH, pembungkus PDF TCPDF (ADR-033), SO per bulan + PDF, tanda terima Karpeg/Karis,
  sub rumpun jabatan (isian mentah MAKE-019).
- **M6 MAKE-014 (B-20 penutup):**
  - `ACTIVE_PHASE` = 3 (`nav.config.ts`), spec menu per role; Konket untuk role 4/5 lihat saja; menu "Pengajuan Data Umum"
    (role 1/3).
  - Daftar Pegawai: panel filter (status bawaan Aktif ikut legacy, unit → satker, jenis pegawai, urutan; disembunyikan
    role 8 sesuai WS2 §3.4.2 #12), pencarian lanjutan `pegawai/pencarian*` (port advsearch: 13 jenis riwayat, data
    terakhir / seluruh riwayat, operator legacy, AND/OR; whitelist atribut & operator, parameter terikat, status pegawai 1,
    [V2] lingkup role 3), ekspor `GET pegawai/export` / `POST pegawai/pencarian/export` CSV (UTF-8 BOM) / XLSX minimal
    (ZipArchive), kolom tampil, NIP teks, escape formula.
  - Detail Pegawai: Cetak DRH PDF `GET pegawai/{nip}/drh` (bagian A–L legacy; hak UL_ALL: 2/6/7 sendiri, lainnya lingkup;
    PDF saja [V2], tanpa DOCX/ZIP generator), Arsip Kepegawaian (arsip Data Umum kode 38 + Arsip Status kode 37 lewat
    endpoint lampiran, `id_entri` = NIP), tab LKH (`LkhService::descriptorTab`), pemilik melihat perubahan pengajuannya.
  - LKH: rekap LKH bawahan per bulan `lkh/rekap*` (legacy list_rekap; bawahan per struktur + LKH ber-`nip_atasan`
    pemanggil).
  - [K] data sensitif detail pegawai (NIK, NPWP, BPJS, TASPEN, HP, kerabat) tampil penuh untuk role 4/5/8 (legacy
    `Employee::detail` tanpa penyamaran; keputusan user Q3).
  - Belum: Cetak Data Umum (legacy `print_du`) — tombol disembunyikan; 90 kolom legacy Daftar Pegawai (12 kolom v2).
- **DoD M6:** B-20 penutup tersambung, `ACTIVE_PHASE` = 3; integrasi tab: semua jenis engine WS-1 (MAKE-004..008 di
  `main`) + LKH. Test: `PegawaiPencarianEksporTest`, `DrhPegawaiTest`, `ArsipDataUmumTest`, `LkhTest` (rekap),
  `PegawaiEndpointTest` (tab LKH); Vitest nav/DaftarPegawai/Detail/AntreanBiodata/VerifikasiLkh.

## WS-2 M1 — MAKE-009 (PegawaiScope nyata + B-18 Lampiran)

Branch `ws2/make-009-m1-scope-lampiran`; tanpa perubahan skema/migration. `Config\Services`: baris stub `pegawaiScope`, `attachmentService`, `storageAdapter` diganti kelas nyata (kelas stub tetap ada untuk test fail-closed).

- **PegawaiScope** (`App\Libraries\Kepegawaian\Scope\PegawaiScope`): role 1 lihat+ubah semua; 4/5/8 lihat semua, ubah tidak; 2/6/7 hanya NIP sendiri; role 3 = port `validateAccess_AP` [K] (`helpers/function_helper.php:72-98`, literal `['37', '38', '41']` di :85; konstanta `UNIT_DESTINASI` 21 / `UNIT_LAIN` 7 / `LIST_SATKER_BPO` 37,38,41 di `config/constants.php:312/314/316`) atas `pegawai_mutasi_jabatan`: admin bersatker → `id_satker` = satker admin; admin tanpa satker unit 21 → `id_unit = 21 OR (id_unit = 7 AND id_satker IN (37,38,41))`; admin tanpa satker unit lain → `id_unit` = unit admin; tanpa pengecualian NIP sendiri; tanpa sesi/role dikenal → tidak ada. `bolehLihat`/`bolehUbah` = `validateAccess_AP`; untuk admin unit 20/21 tanpa satker, daftar dan proses memakai predikat per jalur sejak CR-045 (di bawah); role/kasus lain daftar = detail (diuji per role termasuk 21/7). Pegawai tanpa snapshot pmj tidak masuk lingkup admin role 3 yang berlingkup (sama dengan legacy). Satker akun "ada" bila bukan NULL dan bukan `''` tanpa trim (legacy `'  ' != ''` → cabang satker, menolak semua); nilai bukan angka kanonik ditolak.
- **Deviasi [V2]:** legacy meloloskan admin role 3 tanpa satker dan tanpa unit (`null == ''`) ke pegawai tanpa pmj atau `pmj.id_unit` NULL; v2 fail-closed. Isi daftar admin unit 20/21 tanpa satker: diputus CR-045 (di bawah). Endpoint lampiran tidak mengubah status baris riwayat (legacy unggah hanya lewat form edit yang mengembalikan status ke 0/1); kunci baris Disetujui untuk `DELETE` lampiran lebih ketat dari `delete_da` legacy. Cast claim `id_satker`/`id_unit` VARCHAR → INT hanya untuk angka kanonik (`'012'` ditolak) = [V2] sementara sampai DBV-009.
- **Sumber legacy 21/7:** `validateAccess_AP` (`function_helper.php:72-95`) dan konstanta (`constants.php:312-314`) dikutip reviewer CR; salinan legacy yang tersedia di mesin pengembang lebih lama (`constants.php` tanpa `UNIT_DESTINASI`/`UNIT_LAIN`/`SATKER_BPO`), jadi diimplementasikan dari spesifikasi review CR.
- **B-18:** `LocalStorageAdapter` (akar `writable/uploads/lampiran`, path relatif tervalidasi, tulis atomik), `AttachmentService` (validasi dari isi berkas lewat finfo, batas MB `AturanLampiran`, nama/path simpan dari server, `path` tidak pernah dikembalikan, terikat NIP → 404, hapus keras + audit lewat `DocumentAttachmentModel`), `PenjagaBerkas` (kompensasi: berkas direkonsiliasi dengan DB setelah transaksi pemanggil selesai — rollback menghapus unggahan dan mempertahankan berkas yang "dihapus"), `KolomBerkas` (helper kolom berkas `file_1..5` dsb.), `OtorisasiLampiran` + `LampiranController` (daftar/unggah: kode → izin Definisi → lingkup → keterikatan `id_entri` → kunci baris Disetujui untuk UL_PEGAWAI; unduh/hapus: lingkup lihat → izin aksi di Definisi mana pun → baris lampiran → izin Definisi (+ lingkup ubah) → baris riwayat; baris status 10 → 404 (termasuk unduh); lampiran terakhir kode `wajib` tidak bisa dihapus → 422 `errors.berkas`, dihitung ulang di dalam transaksi dengan baris `pegawai` `FOR UPDATE`).
- **Frontend:** `components/ArsipTable.vue` (daftar/unggah/unduh/hapus, menu ⋮ Unduh → Hapus, ConfirmDialog); TODO kontrak lampiran diselesaikan (query wajib, "ganti" = unggah baru dulu, lalu hapus yang lama; Hapus disabled untuk lampiran terakhir kode `wajib` dan di semua baris selama satu aksi berjalan; 422 hapus menampilkan pesan `errors.berkas`).
- **Test:** `tests/Kepegawaian/PegawaiScopeTest.php` (role 1/2/3/4/5/6/7/8 + unit 21/7 + fail-closed + filter daftar), `AttachmentServiceTest.php` (validasi, NIP, audit, rollback simpan/hapus, rekonsiliasi), `KolomBerkasTest.php`, `LampiranEndpointTest.php` (feature, Definisi contoh pendidikan; 403 di luar lingkup untuk id ada/tidak ada, kunci baris Disetujui, baris dihapus 404, lampiran wajib terakhir 422), `tests/unit/Kepegawaian/Lampiran/LocalStorageAdapterTest.php`; Vitest `ArsipTable.spec.ts`.

- **CR-046 (12-10-2026):** endpoint lampiran `POST`/`DELETE pegawai/{nip}/lampiran` memanggil `RiwayatDefinisi::bolehAksiUntuk()` (MAKE-005) sesudah izin role + `bolehUbah` → 403; sebelumnya role 2 PNS masih bisa unggah/hapus lampiran Pendidikan barisnya sendiri. Daftar/unduh tidak dibatasi. Test: `LampiranEndpointTest` (Definisi Pendidikan nyata: role 2 PNS/tanpa snapshot 403, CPNS boleh, role 1/3 tidak terpengaruh, Definisi tanpa hook tetap).
- **CR-045 (12-10-2026, keputusan user):** lingkup admin role 3 tanpa satker unit 20/21 ikut legacy **per jalur** — daftar `terapkanKeQuery` (unit 20: `id_unit IN (20, 6)`; unit 21: `id_unit = 21 OR id_satker IN (37,38,41)`), detail/ubah tetap `validateAccess_AP`, proses lewat method aditif `bolehProses` (unit 20: `id_unit = 20`; unit 21: `id_unit = 21 OR id_satker IN (37,38,41)`); `RiwayatEngine::proses()` memakai `bolehProses`. Pegawai tanpa pmj tetap ditolak di semua jalur ([V2], legacy `process` meloloskan). Rincian + rujukan legacy: README `Libraries/Kepegawaian` bagian Scope. Test: `PegawaiScopeTest` (empat jalur per pegawai fixture), `RiwayatEngineTest::testProsesMemakaiLingkupJalurProsesScopeNyata`.

## S0-A — MAKE-002 (kontrak backend Fase 3)

Branch `ws1/make-002-s0a-kontrak-backend`; tanpa perubahan skema. Kontrak lengkap: `app/Controllers/Api/Kepegawaian/README.md` bagian "Kontrak API Modul B". Yang dibekukan saat MAKE-002 masuk `main` (hanya berubah aditif, oleh pemiliknya):

- **Route terpisah:** `Config/RoutesRiwayat.php` (WS-1) dan `Config/RoutesPegawai.php` (WS-2) lewat `Routing::$routeFiles`; `Routes.php` tidak disentuh lagi. Kerangka grup `api/v1`, path kontrak yang belum didaftarkan menjawab 404 terkendali.
- **Service:** 12 service didaftarkan sekali di `Config\Services` dengan tipe kembalian interface — `pegawaiScope`, `attachmentService`, `storageAdapter`, `riwayatRegistry`, `riwayatService`, `snapshotSync`, `pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService`. Stub fail-closed di `App\Libraries\Kepegawaian\Stub` (scope menolak semua; lainnya `App\Exceptions\BelumTersediaException` 501); fake hanya di `tests/_support/Kepegawaian`.
- **Interface lintas-WS:** `PegawaiScopeInterface`, `AttachmentServiceInterface`, `StorageAdapterInterface`, value object `AturanLampiran` (milik WS-1, dipakai WS-2; keputusan reviewer CR 07-10-2026); `RiwayatRegistryInterface`, `RiwayatServiceInterface`, `SnapshotSyncInterface` (milik WS-1); interface penanda service WS-2 lain.
- **Mesin riwayat sebagai data:** `RiwayatDefinisi` (satu berkas per jenis, auto-discovery `RiwayatRegistry`), `StatusRiwayat` (0/1/2/10; 3 di belakang flag `Config\Kepegawaian::$statusDiprosesAktif`, nonaktif default), `AksiRiwayat`, `AlurRiwayat`, `AturanSnapshot`, daftar slug `{jenis}` (`JenisRiwayat::SLUG`), descriptor tab.
- **Fixture bersama:** `Tests\Support\Kepegawaian\PegawaiFixtureTrait` — master G-02 → `pegawai` → `pegawai_mutasi_jabatan` → `pengguna`; hanya INSERT, dipakai di test `DatabaseTestCase` (base case MAKE-001, sudah di `main`), patuh FK DBV-019, NIP sintetis 18 digit.
- **Revisi review CR (07-10-2026):** lampiran = daftar `AturanLampiran` per kode `jenis_rwy` + lookup registry `definisiUntukLampiran()`/`aturanLampiran()`; lampiran satu request dengan tambah/ubah riwayat (`$berkas`, `wajib` ditegakkan engine); `AttachmentService` terikat NIP (`ambil`/`hapus(nip, id)`) + aturan izin per jenis; filter `jwt` di level grup kedua berkas route (izin riwayat di service — penyimpangan §2.3.1 disetujui reviewer CR); scope registry di-resolve tiap dipakai; fixture menolak akun tanpa pegawai, menurunkan rantai dari `id_jabatan`, helper `buatJabatanKoordinasi`/`buatRumpunJabatan`/`buatRiwayatMutasiJabatan`.
- **Test:** `tests/unit/Kepegawaian/` (StatusRiwayat, AturanLampiran, AturanSnapshot, registry + descriptor + lookup lampiran, stub fail-closed reflektif, fake lampiran) dan `tests/Kepegawaian/` (service bertipe interface + wiring scope, route riwayat/pegawai ber-`jwt` per pemilik, fixture lolos FK + rollback).

## WS-1 M1 — MAKE-004 (SnapshotSync + RiwayatEngine backend)

Branch `ws1/make-004-m1-engine-be`; tanpa perubahan skema/migration. `Config\Services`: baris stub `riwayatService` dan
`snapshotSync` diganti kelas nyata (`RiwayatEngine`, `SnapshotSync`; kelas stub tetap ada untuk test fail-closed). Belum
ada Definisi jenis nyata (mulai M2: B-10 + B-08), jadi endpoint riwayat di produksi menjawab 404 "Jenis riwayat tidak
ditemukan" sampai Definisi pertama masuk. Rincian: `app/Libraries/Kepegawaian/README.md` bagian "Mesin riwayat".

- **SnapshotSync** (`Riwayat/SnapshotSync.php`) + **PemilihSnapshot** (fungsi murni): pilih ulang baris status 1 per
  `AturanSnapshot` (filter logika tiga nilai SQL, urutan berprioritas termasuk kolom tabel join, seri → PK terbesar [V2]),
  multi-target, DELETE bila kosong, audit manual create/update/delete (entity = tabel snapshot), di transaksi pemanggil.
- **RiwayatEngine** (`Riwayat/RiwayatEngine.php`, service `riwayatService`): daftar/detail/tambah/ubah/hapus (status
  10)/proses; urutan pemeriksaan kontrak (jenis → izin Definisi → PegawaiScope → NIP → baris milik NIP); status awal/ubah
  ikut legacy (data di Definisi); kunci baris Disetujui; validasi field `MasterField` + ref master; hook
  validate/beforeSave/afterApprove; lampiran satu transaksi (`berkas.<id_riwayat>`); kunci baris `pegawai` `FOR UPDATE`;
  `approved_by`, `reason_note`, `show_ua_*`, `show_notif`/`notif_date` UTC; event notifikasi `riwayatNotifikasi` sesudah
  commit. Model generik `App\Models\Kepegawaian\RiwayatModel` (audit otomatis, hapus lunak = event `delete`).
- **HTTP:** `RiwayatController` (`BaseRiwayatController`) + loop route per slug `JenisRiwayat::SLUG` di
  `Config/RoutesRiwayat.php`.
- **Kontrak (aditif):** `SnapshotSyncInterface::sinkronkan(..., ?AuthContext $pelaku = null)`; `RiwayatDefinisi::statusAwal()`,
  `statusSetelahUbah()`, `roleKunciHapusDisetujui()`, `urutanDaftar()`; README kontrak API bagian "Rincian engine (M1)".
- **Test:** `tests/unit/Kepegawaian/Riwayat/PemilihSnapshotTest.php` (pemilih murni per aturan dok DBV-012 §5.1),
  `tests/Kepegawaian/Riwayat/SnapshotSyncTest.php`, `RiwayatEngineTest.php` (draft → setujui/tolak → snapshot → audit →
  lingkup, kunci, validasi, lampiran, rollback), `RiwayatEndpointTest.php`, dan trait B-TC generik
  `Tests\Support\Kepegawaian\RiwayatBtcTrait` (dipakai `BtcUjiPendidikanTest`, `BtcUjiKpTest`; jenis nyata memakainya
  mulai M2) dengan Definisi uji `tests/_support/Kepegawaian/Riwayat/` dan fake scope/lampiran S0.

## Skema Fase 3 (review DB Validator)

- **DBV-012/DBV-013 (B-01/B-02)** — di `main` lewat PR #20 (`957729f`, perbaikan `96fcca2`); dokumen
  `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md`.
- **DBV-019 (lanjutan DBV-012/013)** — PR #24 (draf PR #23 ditutup), branch `dbv-019/fk-g02-pegawai`, ✅ disetujui DB
  Validator 07-10-2026, di `main` 13-10-2026 (`191efec`): 55 FK
  snapshot/riwayat → master G-02, `jabatan_koordinasi`, `rumpun_jabatan` (migration `2026-10-07-100000`/`100100`, RESTRICT,
  pra-cek nilai yatim fail-closed); 2 FK masuk `pengguna.nip`/`faq_rate.nip` → `pegawai.nip` ikut diajukan tetapi tetap
  ditahan (`app/Database/MigrationsDitahan/`) sampai `pegawai` diimpor dan validasi NIP 422 di Manajemen Akun ada
  (keputusan #4); dokumen `backend/docs/db-review/DBV-019-fk-g02-pegawai-schema.md`.

## Persiapan tanpa skema

### CR-025 — kalkulasi murni Kepegawaian (bagian B-21) — pra-review internal sisi CR; di main lewat merge `a540703` 30-09-2026

Key review **CR-025** (CR saja). Tidak mengubah skema, jadi tidak ada dokumen `docs/db-review/` dan tidak perlu review DB Validator. Tanpa migration, tanpa FE, tanpa endpoint.

**Sudah jalan**
- `app/Libraries/Kepegawaian/Kalkulasi/` (namespace `App\Libraries\Kepegawaian\Kalkulasi`):
  - `TanggalBisnis`: validasi input (delegasi `HariLiburRules::isValidDate`), `hariIni()` Asia/Jakarta, tambah bulan/tahun dijepit akhir bulan, selisih bulan penuh, format "1 April 2027".
  - `HasilKalkulasi`: hasil gagal input pengguna → `lemparBilaGagal($field)` → 422.
  - `KenaikanPangkat` + `JenisKp`: periode 1 April/1 Oktober (SRS-FR-025), proyeksi reguler/percepatan setara legacy `next_kp`.
  - `KenaikanGajiBerkala` + `JenisReferensiTmt` + `ReferensiTmt`: jarak minimal 2 tahun dari acuan pendahulu (SRS-FR-026), proyeksi setara legacy `next_kgb`.
  - `HukumanDisiplin`: akhir = TMT + masa (SRS-FR-027, MTC-021), masa SK ?? masa bawaan `jenis_hukdis.masa_sanksi_bulan` (K5b).
  - `MasaKerja`: MKG kalender murni, TMT CPNS dari NIP, format `formatMker`.
- Test `tests/unit/Kepegawaian/Kalkulasi/` (tanpa DB): `TanggalBisnisTest`, `KenaikanPangkatTest` (+ `JenisKp`), `KenaikanGajiBerkalaTest`, `HukumanDisiplinTest`, `MasaKerjaTest`, `HasilKalkulasiTest`, dan penjaga arsitektur `KalkulasiMurniTest` (tanpa DB/HTTP/session/jam kecuali `hariIni()`). 135 test, 480 assertion.
- Uji mutasi: 32 mutasi pada kode kalkulasi (penjepitan, zona WIB, batas inklusif periode/jarak/36 bulan, prioritas acuan, masa SK vs bawaan, off-by-one, penjaga arsitektur, dll.) semuanya membuat test gagal; tanpa folder `Kalkulasi/` test gagal (125 error, 9 failure).
- Quality gate backend (PHPStan level 5, PHP-CS-Fixer, PHPUnit penuh ber-DB) lolos pada 30-09-2026: PHPStan tanpa error, CS-Fixer 0 dari 255 berkas, PHPUnit 670 test / 17.696 assertion.
- README modul (`app/Libraries/Kepegawaian/README.md`): aturan tanggal bisnis/zona, tabel aturan [K]/[V2] + deviasi, keputusan K-CR025-1..12, pertanyaan terbuka, dan cara integrasi B-08/B-09/B-14/B-19.

**Belum / di luar CR-025**
- Pemanggilan dari service ber-DB B-08/B-09/B-14 dan proyeksi dashboard B-19/H-08.
- B-21 butir cascade NIP + rollback (B-06) dan snapshot sync di approval final (B-03): test ber-DB di `tests/Kepegawaian/` setelah B-01/B-02.
- Hari kerja (Senin–Jumat dikurangi `hari_libur`) ditunda ke Fase 5 (K-CR025-12).
- Kelayakan KP (maks. golongan per pendidikan/eselon), aturan cron kelipatan 4 tahun `get_all_kp`, dan proyeksi pensiun → H-08.
- Penundaan KP/KGB karena hukdis tidak ada di legacy; perlu keputusan produk bila diinginkan.

**Pertanyaan terbuka** (detail dan usulan di README modul): #1 validasi [V2] hanya untuk input pengguna; #2 jenis KP yang bebas periode (usulan: 1 dan 2); #3 TMT KP sebagai acuan jarak KGB (SRS vs legacy); #4 akhir hukdis TMT + masa atau − 1 hari (usulan: literal SRS/MTC-021); #5 impor legacy/sinkron SIASN tidak divalidasi.

**Catatan lokasi test:** kontrak B-21 menyebut `tests/Kepegawaian/`. Test kalkulasi murni ditaruh di `tests/unit/Kepegawaian/` mengikuti konvensi repo (`tests/unit` = kelas tanpa DB, mis. `tests/unit/Libraries/HariLiburRulesTest.php`); test ber-DB B-21 tetap di `tests/Kepegawaian/`.

### CR-036 — registry kolom NIP, format NIP, dan BUP pensiun (bahan B-06/B-21) — pra-review internal sisi CR

Key review **CR-036** (CR saja). User menyetujuinya 01-10-2026 sebagai pengecualian urutan fase, seperti CR-025. Tidak mengubah skema, jadi tidak ada dokumen `docs/db-review/`. Tanpa migration, FE, dan endpoint. Rincian aturan, sumber, keputusan K-CR036-1..6, dan pertanyaan terbuka ada di README modul, bagian "Registry kolom NIP dan format NIP".

**Sudah jalan**
- `app/Libraries/Kepegawaian/Nip/` (namespace `App\Libraries\Kepegawaian\Nip`):
  - `TabelAnakNip` (+ `RujukanNip`, `JenisRujukanNip`, `KeberadaanV2`): registry statis 79 kolom.
    - 75 FK legacy `REFERENCES pegawai (nip)` di 70 tabel dump struktur produksi 01-10-2026, dengan nama FK legacy.
    - `pengguna.username`, yang diganti kode Ubah NIP legacy (`L_employee.php:589-599`).
    - `gaji_pegawai.nip` (Slip Gaji).
    - `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui`, yang tanpa FK di legacy tetapi wajib FK di v2 (DoD C-01).
    - Keberadaan v2: `Ada` 61, `TidakDiimpor` 11, `BelumDiputuskan` 7.
    - Per fase: 0: 1, 1: 2, 2: 2, 3: 40 (B-01 16 + B-02 24, sama dengan draf DBV-012/013 revisi D1 termasuk `pegawai_ak_siasn`), 4: 8, 5: 2, 6: 1, 7: 5.
    - Method: `semua`, `perFase`, `sampaiFase`, `perJenis`, `perKeberadaan`.
  - `FormatNip`:
    - Format NIP 1–18 digit ASCII, sama dengan aturan akun DBV-010. Auth tidak punya versi publik yang bisa dipanggil kelas murni.
    - `periksaKoreksi` untuk B-06: NIP lama wajib, NIP baru valid dan berbeda dari NIP lama.
    - Hasil gagal memakai `HasilKalkulasi` → 422.
- `app/Libraries/Kepegawaian/Kalkulasi/Pensiun`: BUP setia legacy `getTanggalPensiun` (`jabatan.umur_pensiun` > 0, Struktural Eselon I/II 60, selain itu 58), sesuai SRS-FR-033 (58/60/65). TMT pensiun = tanggal 1 bulan sesudah ulang tahun ke-BUP. Untuk tanggal lahir 29–31, legacy meluap; deviasi ini terdokumentasi.
- Test tanpa DB:
  - `tests/unit/Kepegawaian/Nip/TabelAnakNipTest`: tidak ada duplikat, entri lengkap, jumlah per jenis/fase/keberadaan, fase 3 = draf B-01/B-02.
  - `FormatNipTest`: kasus batas, koreksi NIP, keselarasan dengan `UserService::validateNip`/`PenggunaModel::NIP_MAX_DIGITS`.
  - `tests/unit/Kepegawaian/Kalkulasi/PensiunTest`: urutan BUP, deviasi tanggal 29–31, identik dengan legacy untuk tanggal 1–28 pada 1950–1975 × 58/60/65.
  - `KalkulasiMurniTest` kini juga menjaga folder `Nip/` dan menutup celah yang ditemukan di CR-027: pemanggilan berawalan `\` (`\date()`, `\random_int()`, `\CodeIgniter\I18n\Time::now()`) sebelumnya lolos karena PHP 8 menokenisasinya sebagai T_NAME_FULLY_QUALIFIED. Daftar fungsi terlarang diperluas (varian `date_create*`, angka acak, `getenv`/`env`) dan superglobal dilarang, mengikuti `SlipGajiMurniTest`; self-test memuat contoh pelanggaran baru.
- Uji mutasi sederhana (2 Oktober 2026): 40 mutan pada `Pensiun`, `FormatNip`, `TabelAnakNip`, dan pemindai `KalkulasiMurniTest` (BUP 58/60/65, urutan `umur_pensiun` → Eselon I/II, TMT tanggal 1 bulan berikutnya, awal bulan lahir, batas 1/18/19 digit, `\z`, digit non-ASCII, trim, koreksi NIP sama/beda, batas fase, awalan `\`). 38 mati; 2 yang lolos setara secara perilaku: `in_array` ketat → longgar (parameter sudah `?int`) dan `perFase` tanpa cek `Ada` (entri selain `Ada` selalu ber-fase `null`, dijaga `testSetiapEntriLengkap`).
- Rebase ke main 2 Oktober 2026: registry diselaraskan dengan revisi D1 draf DBV-012 (PR #20), yang memasukkan `pegawai_ak_siasn` ke B-01 (keputusan 8 #6, belum disetujui DB Validator).

**Belum / di luar CR-036**
- `NipCascadeService` (B-06): transaksi salin → arahkan ulang → hapus, cek keunikan NIP baru (`pegawai` dan `username` akun lain), audit log, dan role 1 only. Semua menunggu B-01/B-02.
- Test ber-DB B-21 yang mencocokkan registry dengan `information_schema` dan menguji cascade + rollback, setelah tabel B-01/B-02 ada. Draf DBV-012 sudah punya `NipReferenceRegistryTest` untuk tabel B-01/B-02; penyatuannya adalah pertanyaan terbuka CR-036 #5.
- Pemakaian `Pensiun` di dashboard (B-19/B-20), cron `rekomendasi_pensiun` (H-08), dan daftar pensiun (F-09).
- Validasi gelar dan nama tidak dikerjakan. Legacy hanya punya format tampilan `formatNamaGelar` (`helpers/function_helper.php:102-108`) tanpa validasi, dan spesifikasi v2 tidak menyebut aturan gelar.

**Pertanyaan terbuka CR-036** (detail dan usulan di README modul):
1. Username akun saat koreksi NIP (usulan: ganti hanya bila username = NIP lama; password tidak disentuh).
2. `gaji_pegawai.nip` dan kolom atasan `riwayat_cuti` ikut dikoreksi. Kolom `riwayat_cuti` wajib ikut karena FK RESTRICT.
3. Kolom NIP non-FK di luar registry.
4. Tujuh tabel `BelumDiputuskan`.
5. Penyatuan dengan registry draf DBV-012.
6. Pensiun PPPK memakai `mhpk_akhir`.
