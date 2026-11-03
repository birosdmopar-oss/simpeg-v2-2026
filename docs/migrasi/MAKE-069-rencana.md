# MAKE-069 — Rencana impor cutover tahap 2

**Status:** rencana (belum ada kode). Kode menunggu DBV-027 di `main` dan lampu hijau reviewer CR.
**Keputusan:** Horii 03-11-2026 (K-M1, K-M2, K-M3, K-M12, impor cutover opsi (b)); DBV 03-11-2026 (Z-1); reviewer CR
03-11-2026 (K-M5, K-M11).
**Mesin:** sama dengan MAKE-056 (`backend/app/Libraries/Impor/`, command `impor:legacy`): irisan kolom [K], R1a WIB→UTC,
`stripslashes` bawaan, berkas keputusan, pra-cek UNIQUE, counter AUTO_INCREMENT, satu transaksi per tier, laporan hanya
jumlah (tanpa NIP/nilai). Dokumen ini hanya menambah tier dan aturan; aturan umum ada di `backend/app/Libraries/Impor/README.md`.

## 1. Cakupan

MAKE-056 memegang tabel jalur Tukin (Tier 1–6). MAKE-069 memegang semua tabel v2 lain yang berisi data legacy, akun
`pengguna`, bootstrap admin awal, dan pemindahan berkas lampiran. Tabel kerja SIASN (paket B3, DBV-027) menjadi tier
**terakhir** karena `siasn_upload_arsip` merujuk riwayat dan `document_attachment` (syarat K-6 DBV-027).

Tier dinomori lanjutan MAKE-056; nomor final ditetapkan saat kode.

| Tier | Isi | Tabel |
|---|---|---|
| T7 | Master sisa | `kecamatan`, `kelurahan`, `kantor`, `bidang_kursem`, `instansi_kursem`, `bidang_pendidikan`, `jurusan_pendidikan`, `diklat`, `jenis_hukdis`, `tingkat_hukdis`, `jenis_konket`, `tanda_jasa`, `subrumpun_jabatan`, `jabatan_akademik`, `peta_jabatan`, `rumpun_sertifikasi`, `lembaga_sertifikasi`, `dm_ak_jf`, `faq_topic` → `faq_sub_topic` → `faq_article` → `faq_related_article`, `jenis_rwy` (seed) |
| T8 | Akun | `pengguna` |
| T9 | Pegawai lanjutan | `pegawai_hist` (pending saja), `pegawai_foto` |
| T10 | Riwayat non-Tukin | `riwayat_kgb`, `riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar`, `riwayat_skp`, `riwayat_lckh`, `riwayat_hukdis`, `riwayat_ak`, `riwayat_ak_siasn`, `konv_ak`, `ignore_konv_ak`, `riwayat_alamat`, `riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_tanda_jasa`, `riwayat_organisasi`, lalu `document_attachment` paling akhir |
| T11 | Snapshot | `pegawai_cpns`, `pegawai_pns`, `pegawai_kgb`, `pegawai_pendidikan`, `pegawai_diklat`, `pegawai_hukdis`, `pegawai_ak`, `pegawai_ak_siasn`, `pegawai_keluarga`, `pegawai_alamat`, `pegawai_alamat_kantor`, `pegawai_tanda_jasa`, `pegawai_mutasi_jabatan` |
| T12 | Layanan sisa (DBV-022 §5) | `jenis_layanan`, `jenis_pa`, `question_category` → `petugas_layanan`, `pa_layanan` → `riwayat_cuti_notif_kt`, `riwayat_ib`, `riwayat_pmk_req` → `riwayat_pmk` → `esign_hist` |
| T12b | Halo Simpeg master (**skema menyusul DBV-028**) | `help_desk_contact`, `support_officer`, `officer_category` |
| T13 | Tiket lama (hasil sortir Fase 7 F-02) | `disiplin_ticket` ← legacy `inbox`, `disiplin_ticket_msg` ← legacy `inbox_msg` |
| Terakhir | SIASN (B3) | `siasn_ref_unor`, `siasn_referensi`, `siasn_upload_arsip`, `double_riwayat_kp` |

**Dibuat kosong (tidak ada tier):** `token`, `login_attempts`, `forgot_attempts` (A-01); `audit_logs`, `queue_jobs`,
`queue_jobs_failed` (tabel v2); `inbox`/`inbox_msg` v2 (DBV-022 §3.4); `firebase_token` (PRD Fase 6 AC-01.2);
`integrasi_log`, `siasn_status_pegawai` (dibangun job, DBV-027); `penyesuaian_tukin` (CRUD, DBV-024); `gaji_pegawai`,
`gaji_pegawai_qr_history` (histori produksi tidak ada, ISSUE-049; dicek ulang DEPLOY-002.1, bila ada data ikut DBV-023 §7.1).

**Menunggu DBV:** `dm_user_lokasi_presensi` (GAP-4, K-M6); `layanan`, `layanan_pegawai`, `riwayat_layanan` (K-M7).

**Tidak dimigrasi:** daftar di `docs/migrasi/Mapping_Migrasi_Tidak_Dimigrasi.md` (arsip `d_*`, cadangan bertanggal,
`simapi_*`, antrean push, `fb_token`, `pengguna_2021..2024`, dst.). `support_officer`, `officer_category`,
`help_desk_contact` **tidak lagi** di daftar itu: diimpor (PRD Fase 8 F8-21, Q8-13); daftar diperbarui bersama kode.

## 2. Aturan per tier

### T7 master sisa
- ID legacy disalin apa adanya (G-04/05 P12, G-06 B8, G-07/08 F10, DBV-020). ID yang di-hard-code aturan v2 wajib ada:
  `bidang_pendidikan` 98, `jurusan_pendidikan` 1185, `jenis_konket` 2/4/5/6, `tanda_jasa` 26/27/28 (B-01/B-02 §6 #10).
- Kolom `order` [V2] diisi urut nama per lingkup: wilayah (G-01 #4), `bidang_kursem`/`instansi_kursem`, `bidang_pendidikan`/
  `jurusan_pendidikan` (G-04/05 P8); `faq_article.order` = urut `title` per sub topik (G-10 D4).
- `faq_article.content` dilewatkan `HtmlSanitizer` lalu `content_stripped` dihitung ulang (G-10 :210).
- `tingkat_hukdis` yatim (legacy menghapus keras) diaudit; `old_id` G-06 NULL/ganda → keputusan DBV (K-M9).
- `jenis_rwy`: seed migration, `bolehSeed` (id + nama sama). Kode lampiran legacy di luar seed → gagal.

### T8 `pengguna`
- `id_pengguna` = `pengguna.id` legacy, disalin apa adanya (DBV-010 §9.4, A-01 #10). Kolom: `id_pegawai` → `nip` (NULL
  untuk akun role 1/3/4/5/8, K2), `username`, `name`, `email`, `UserLevel` → `user_level`, `id_unit`, `id_satker`,
  `status`, `created_at` (R1a), `expired_at` (R1a).
- `password` legacy (`md5`) → `password_legacy`; `password` v2 NULL (lazy rehash Argon2id saat login pertama,
  `PasswordVerifier`).
- **`password_decode` (plaintext) tidak pernah dibaca**: dikecualikan dari SELECT sumber lewat daftar kolom terlarang
  (`kolomDilarang`), bukan sekadar tidak disalin. Tidak masuk log, laporan, atau berkas keputusan.
- `must_change_password = 1` (K-M1, K-M2):
  - role 2/6 dan `password_legacy = md5(nip)` — padanan popup paksa ganti legacy (`MY_Controller.php:209-238`); hanya hash
    yang dibandingkan;
  - semua akun role 1.
- Role 2/6/7 wajib ber-NIP yang ada di `pegawai` (yatim → gagal, jumlah); akun tanpa NIP wajib `name`.
- Tujuan sudah berisi akun `sistem.integrasi` (command akun sistem terlanjur dijalankan) → impor **gagal** dengan jumlah;
  akun ini bukan `bolehSeed` (Z-1, DBV 03-11-2026).

### T9 pegawai lanjutan
- `pegawai_hist`: hanya baris pending `flag_update = 1` (B-01/B-02 §6 #1, saringan di sumber). Lainnya tidak dimigrasi.
- `pegawai_foto`: impor + berkas (Bagian 4).

### T10 riwayat
- PK tetap, counter disalin. Status di luar domain → berkas keputusan (B-01/B-02 §6 #3); `riwayat_pendidikan.status` NULL
  dipertahankan; `''` di kolom FK → NULL; id G-02 = 0 → NULL; tanggal nol dilaporkan (§6 #4).
- `riwayat_lckh` hanya tabel utama (cadangan `riwayat_lckh_2019..2022` tidak).
- `document_attachment` sesudah semua riwayat (FK `jenis_rwy`); kolom `NIP` huruf besar [K].
- Tabel besar: satu transaksi per tabel, bukan per tier — menunggu keputusan DBV (K-M8).

### T11 snapshot
- Disalin apa adanya, tidak diturunkan ulang (B-01/B-02 §6 #1).
- `id_riwayat_*` yatim: kolom NULL-able → NULL + dicatat; kolom NOT NULL (`pegawai_cpns`, `pegawai_alamat`,
  `pegawai_alamat_kantor`) → gagal dengan jumlah (§6 #6, migration `131000` fail-closed).
- Rekonsiliasi snapshot vs aturan 5.1 dilaporkan, tidak diperbaiki.

### T12 layanan sisa
- Urutan DBV-022 §5. `petugas_layanan`: audit NULL `id_jenis_layanan` dan duplikat (UNIQUE) = 0 lebih dulu (DQ-12/DQ-27).
- `esign_hist` utuh (append-only, bukti legal); `nik` tidak dilog.

### T12b Halo Simpeg master (skema DBV-028)
- Sesudah T8 dan `question_category` (T12). Urutan: `help_desk_contact` → `support_officer` → `officer_category`.
- `support_officer.id_user` disalin langsung ke kolom akun v2 (K-M11: tanpa tabel pemetaan); id yang tidak ada di
  `pengguna` → baris **dibuang** + jumlah dicatat (K-M12). `officer_category` milik baris yang dibuang ikut dibuang.
- `status` legacy varchar `'1'`/`'0'` → tinyint; nilai lain → berkas keputusan.

### T13 tiket lama
- Sumber = hasil sortir Fase 7 F-02 (PRD Fase 8 PN8-2), bukan `inbox` mentah. Tanpa hasil F-02 → tier dilaporkan
  **dilewati**.
- `sender`/`receiver`/`closed_by`/`msg_sender`/`msg_receiver` tanpa FK (sentinel 0 = semua petugas, DQ-4/6): disalin;
  id akun yang tidak ada → INFO.
- Isi percakapan data pribadi: tidak masuk laporan, log, atau fixture (PRD Fase 8 #7).

### Terakhir: SIASN (B3)
- Spesifikasi di DBV-027 §13.3 (keputusan K-3..K-9). Dijalankan sesudah T10 (riwayat + `document_attachment`); bila lebih
  awal, INFO "rujukan tidak ada" menyebut penyebabnya (tabel induk belum diimpor).

## 3. Akun sistem dan bootstrap admin

### Akun sistem integrasi (Z-1)
`sistem.integrasi` tidak di-seed migration DBV-027; dibuat command idempoten `integrasi:siapkan-akun-sistem`.

### Bootstrap admin (K-M2)
`php spark akun:bootstrap-admin --username=<u> --nama=<n> [--email=<e>] --konfirmasi <db>`:
- mencetak tujuan (host/port/DB) sebelum konfirmasi;
- menolak bila sudah ada akun role 1 aktif, kecuali `--tambahan`;
- password acak dari `random_bytes`, ditampilkan **sekali** ke STDOUT; tidak pernah ke log, berkas, `audit_logs`, atau
  laporan;
- disimpan Argon2id, `must_change_password = 1`;
- `audit_logs` mencatat kejadian bootstrap tanpa hash;
- menolak berjalan tanpa TTY kecuali opsi eksplisit (`--paksa-non-interaktif`), agar password tidak tertangkap log otomatis.

Admin role 1 legacy yang diimpor tetap bisa login dengan password lamanya, lalu wajib ganti (T8). Bootstrap dipakai untuk
lingkungan kosong (UP/QA) dan keadaan darurat.

## 4. Berkas lampiran (K-M3)

Legacy menyimpan berkas di dua tempat (`pathToSrc()`/`fixArsipPath()`, `function_helper.php:314`): path `arsip/...` di
server arsip, path `assets/upload/...` di server aplikasi. `LocalStorageAdapter` v2 hanya menerima segmen
`[A-Za-z0-9._-]`, sehingga path legacy (diawali `/`, bisa berspasi) tidak bisa dipakai apa adanya.

Alur (langkah runbook, server arsip legacy tidak dipakai sebagai adapter):
1. Alat impor menulis path v2 aman deterministik ke kolom berkas, mis. `legacy/<sha256(path lama)>.<ext>`, dan membuat
   **manifest** (tabel, kolom, id, path lama, path v2) di folder kerja — tidak di repo.
2. DBA/infra menyalin berkas dari kedua sumber sesuai manifest, dengan checksum.
3. `php spark lampiran:periksa` melaporkan jumlah path yang tidak ada di storage per tabel/kolom, tanpa nama berkas.

Kolom berkas (daftar final dari audit skema salinan): `document_attachment.path`, `pegawai.foto`/`pegawai_foto`,
`riwayat_karpeg.file_*`, `absen_ijin.file_bukti..5`, `pegawai_plt`/`pegawai_plh.lampiran`, `riwayat_lckh.file_lckh`,
`riwayat_cuti` (`file_bukti`, `file_sc_*`, `file_surat_cuti`), `riwayat_tb` (`file_sp`, `file_1..3`, `file_surat_tb`),
`riwayat_tb_perpan.file_sperpan`, `riwayat_mutasi_jabatan.f_spmt/f_bapel`, `riwayat_skp_periodik.f_arsip_*`, lampiran
tiket Halo Simpeg. Kolom milik tabel MAKE-056 ikut ditulis ulang lewat manifest yang sama. Berkas yang dibangkitkan ulang
v2 (PDF LKH, tanda terima) tidak disalin. Akses, volume, dan jendela salin: DBA (K-M4).

## 5. Perubahan mesin yang dibutuhkan

| Properti / fitur | Untuk |
|---|---|
| `sumber` (nama tabel/skema sumber ≠ tujuan) | `disiplin_ticket(_msg)`, tabel SIASN |
| `saringSumber` (WHERE di sumber) | `pegawai_hist` |
| `kolomDilarang` (tidak pernah di-SELECT) | `pengguna.password_decode` |
| `isiUrutan` (kolom `order` = urut nama per lingkup) | T7 |
| `khusus` dengan sanitizer | `faq_article` |
| `praPindai` (lintasan baca pertama) | B3 `riwayat_skp_periodik` |
| `petaKolom`, `konstanta` | B3 `siasn_referensi` |
| Penulis manifest berkas + `lampiran:periksa` | Bagian 4 |
| Command `akun:bootstrap-admin`, `integrasi:siapkan-akun-sistem` | Bagian 3 |
| Transaksi per tabel (opsional) | T10/T11 (K-M8) |

## 6. Urutan runbook cutover

1. `migrate` di DB kosong.
2. Impor MAKE-056 (T1–T6), lalu MAKE-069 T7–T13.
3. Salin berkas sesuai manifest, lalu `lampiran:periksa`.
4. Tier SIASN (B3) dengan `--sumber-siasn`.
5. `integrasi:siapkan-akun-sistem`.
6. `akun:bootstrap-admin` bila diperlukan.
7. Cron integrasi diaktifkan.

## 7. Keputusan yang masih ditunggu

| Kode | Pemutus | Butir |
|---|---|---|
| K-M4 | DBA | Akses salin dari server arsip & server aplikasi legacy, volume, jendela waktu |
| K-M6 | DBV | `dm_user_lokasi_presensi` (GAP-4): dibangun ulang dari aturan atau kosong |
| K-M7 | DBV | `layanan`, `layanan_pegawai`, `riwayat_layanan` |
| K-M8 | DBV | Transaksi per tabel untuk tabel besar T10/T11 |
| K-M9 | DBV | `old_id` G-06; konfirmasi saringan `pegawai_hist`; sanitasi FAQ saat impor |
| K-M10 | DBV | Daftar `waktuBisnis` dan `tanpaStripslashes` T7–T13 (dari audit skema salinan) |
| — | DBV-028 | Skema `support_officer`, `officer_category`, `help_desk_contact` |
| — | Fase 7 F-02 | Hasil sortir `inbox` legacy untuk T13 |
