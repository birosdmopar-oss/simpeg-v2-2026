# MAKE-069 — Rencana impor cutover tahap 2

**Status:** rencana (belum ada kode). Kode menunggu DBV-027 di `main` dan lampu hijau reviewer CR.
**Keputusan:** Horii 03-11-2026 (K-M1, K-M2, K-M3 opsi A, K-M4 opsi 1, K-M12, impor cutover opsi (b)); DBV 03-11-2026 (Z-1); reviewer CR
03-11-2026 (K-M5, K-M11); DBV-029 (K-M6..K-M10, `backend/docs/db-review/DBV-029-impor-cutover-tahap2.md`, branch
`docs/dbv-029-impor-tahap2`, ikut kode).
**Mesin:** sama dengan MAKE-056 (`backend/app/Libraries/Impor/`, command `impor:legacy`): irisan kolom [K], R1a WIB→UTC,
`stripslashes` bawaan, berkas keputusan, pra-cek UNIQUE, counter AUTO_INCREMENT, satu transaksi per tier, laporan hanya
jumlah (tanpa NIP/nilai). T10/T11 memakai transaksi per tabel (K-M8, Bagian 2). Dokumen ini hanya menambah tier dan aturan; aturan umum ada di `backend/app/Libraries/Impor/README.md`.

## 1. Cakupan

MAKE-056 memegang tabel jalur Tukin (Tier 1–6). MAKE-069 memegang semua tabel v2 lain yang berisi data legacy, akun
`pengguna`, bootstrap admin awal, dan pemindahan berkas lampiran. Tabel kerja SIASN (paket B3, DBV-027) menjadi tier
**terakhir** karena `siasn_upload_arsip` merujuk riwayat dan `document_attachment` (syarat K-6 DBV-027).

Tier dinomori lanjutan MAKE-056; nomor final ditetapkan saat kode.

| Tier | Isi | Tabel |
|---|---|---|
| T7 | Master sisa | `kecamatan`, `kelurahan`, `kantor`, `bidang_kursem`, `instansi_kursem`, `bidang_pendidikan`, `jurusan_pendidikan`, `diklat`, `jenis_hukdis`, `tingkat_hukdis`, `jenis_konket`, `tanda_jasa`, `subrumpun_jabatan`, `jabatan_akademik`, `peta_jabatan`, `rumpun_sertifikasi`, `lembaga_sertifikasi`, `dm_ak_jf`, `faq_topic` → `faq_sub_topic` → `faq_article` → `faq_related_article`, `jenis_rwy` (seed) |
| T8 | Akun | `pengguna` |
| T9 | Pegawai lanjutan | `pegawai_hist` (pending saja), `pegawai_foto`, `dm_user_lokasi_presensi` (sesudah `lokasi_presensi` T5) |
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

**Tidak dimigrasi:** `layanan`, `layanan_pegawai`, `riwayat_layanan` (K-M7, DBV-029): registry `layanan_pegawai` menjadi
`TidakDiimpor`; DBA melaporkan jumlah baris status 0/3 sebelum cutover. Selain itu daftar di `docs/migrasi/Mapping_Migrasi_Tidak_Dimigrasi.md` (arsip `d_*`, cadangan bertanggal,
`simapi_*`, antrean push, `fb_token`, `pengguna_2021..2024`, dst.). `support_officer`, `officer_category`,
`help_desk_contact` **tidak lagi** di daftar itu: diimpor (PRD Fase 8 F8-21, Q8-13); daftar diperbarui bersama kode,
termasuk ketiga tabel `layanan*` (K-M7).

**Aturan umum T7–T13 (K-M10, DBV-029):** daftar `waktuBisnis` dan `tanpaStripslashes` **kosong** — semua kolom DATETIME
dikonversi R1a dan semua kolom teks ikut `stripslashes` bawaan. Kolom `siasn_lu` wajib R1a. `pengguna.last_login_at` dan
`password_changed_at` → NULL.

## 2. Aturan per tier

### T7 master sisa
- ID legacy disalin apa adanya (G-04/05 P12, G-06 B8, G-07/08 F10, DBV-020). ID yang di-hard-code aturan v2 wajib ada:
  `bidang_pendidikan` 98, `jurusan_pendidikan` 1185, `jenis_konket` 2/4/5/6, `tanda_jasa` 26/27/28 (B-01/B-02 §6 #10).
- Kolom `order` [V2] diisi urut nama per lingkup: wilayah (G-01 #4), `bidang_kursem`/`instansi_kursem`, `bidang_pendidikan`/
  `jurusan_pendidikan` (G-04/05 P8); `faq_article.order` = urut `title` per sub topik (G-10 D4).
- FAQ (K-M9c): `faq_article.content` → `stripslashes` → `HtmlSanitizer` → `content_stripped` dihitung ulang (G-10 :210);
  jumlah artikel yang diubah sanitizer dilaporkan.
- `old_id` (K-M9a): hanya ada di `jenis_konket`. Angka sah dan unik → INT; selain itu impor gagal sampai diputus lewat
  berkas keputusan. Audit WAJIB_0 sesudah T7 untuk `absen_ijin.kategori`.
- `tingkat_hukdis` yatim (legacy menghapus keras) diaudit.
- `jenis_rwy`: seed migration, `bolehSeed` (id + nama sama). Kode lampiran legacy di luar seed → gagal.

### T8 `pengguna`
- `id_pengguna` = `pengguna.id` legacy, disalin apa adanya (DBV-010 §9.4, A-01 #10). Kolom: `id_pegawai` → `nip` (NULL
  untuk akun role 1/3/4/5/8, K2), `username`, `name`, `email`, `UserLevel` → `user_level`, `id_unit`, `id_satker`,
  `status`, `created_at` (R1a), `expired_at` (R1a); `last_login_at` dan `password_changed_at` NULL (K-M10).
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
- `pegawai_hist` (K-M9b): hanya baris pending `flag_update = 1` (B-01/B-02 §6 #1, saringan di sumber), **maksimal satu
  baris per NIP** — id terbesar disimpan, sisanya dibuang + jumlah dicatat. Lainnya tidak dimigrasi.
- `pegawai_foto`: impor + berkas (Bagian 4).
- `dm_user_lokasi_presensi` (K-M6): **diimpor** apa adanya, PK tetap, `status = 1`, kolom `target_*` ikut `stripslashes`,
  stempel R1a. Id lokasi/NIP yatim → INFO; JSON yang tidak terurai → gagal. Tidak boleh kosong: tanpa aturan ini geofence
  presensi tidak berlaku.

### T10 riwayat
- PK tetap, counter disalin. Status di luar domain → berkas keputusan (B-01/B-02 §6 #3); `riwayat_pendidikan.status` NULL
  dipertahankan; `''` di kolom FK → NULL; id G-02 = 0 → NULL; tanggal nol dilaporkan (§6 #4).
- `riwayat_lckh` hanya tabel utama (cadangan `riwayat_lckh_2019..2022` tidak).
- `document_attachment` sesudah semua riwayat (FK `jenis_rwy`); kolom `NIP` huruf besar [K].
- **Transaksi per tabel untuk T10/T11 (K-M8, DBV-029):** pra-cek seluruh tier dijalankan di depan sebelum tabel pertama
  ditulis; setiap tabel satu transaksi; tabel yang selesai dicatat di berkas jejak di folder kerja; jalankan ulang dengan
  `--tier N --lanjut` untuk meneruskan dari tabel berikutnya; tidak ada TRUNCATE. Rincian aturan di DBV-029.

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

## 4. Berkas lampiran (K-M3, keputusan Horii 03-11-2026 — menggantikan keputusan K-M3 sebelumnya)

Legacy menyimpan berkas di dua tempat (`pathToSrc()`/`fixArsipPath()`, `function_helper.php:314`): path `arsip/...` di
server arsip, path `assets/upload/...` di server aplikasi. `LocalStorageAdapter` v2 hanya menerima segmen
`[A-Za-z0-9._-]`, sehingga path legacy (diawali `/`, bisa berspasi) tidak terbaca apa adanya (temuan Z-2).

| Opsi | Isi | Status |
|---|---|---|
| **A** | Path di DB **tetap legacy** (`arsip/...`, `assets/upload/...`, termasuk awalan `/` dan spasi), disalin apa adanya. Berkas fisik disalin ke storage v2 dengan **struktur folder relatif yang sama**. Adapter v2 diberi normalisasi baca untuk path legacy | **DIPILIH** |
| B | Path v2 baru aman deterministik (`legacy/<sha256(path lama)>.<ext>`) lewat manifest; kolom DB ditulis path baru; adapter tidak diubah | Dibatalkan (keputusan K-M3 sebelumnya) |
| C | Berkas tetap di server arsip legacy; v2 membaca lewat adapter remote baca-saja mengikuti `fixArsipPath`; server lama dipertahankan | Tidak dipilih |

**Alasan A:** data dan perilaku sama dengan legacy ("intinya ikut legacy"); path tidak berubah sehingga rujukan lain tetap
cocok — mis. `siasn_upload_arsip.arsip_path`, yang dipakai legacy sebagai kunci anti-antre-ulang (`nip***id_rw***arsip_path`,
`SS/Rw_jabatan::set_queue_arsip`); server legacy bisa dimatikan setelah cutover.

**Risiko A:** adapter menerima bentuk path yang lebih longgar, jadi validasi traversal wajib ketat dan diuji.

**Perubahan adapter (commit terpisah, berkas bersama Kepegawaian `Lampiran/LocalStorageAdapter.php`):**
- normalisasi baca: buang `/` di awal, izinkan spasi di segmen, dan ubah `\` serta `./` menjadi `/` seperti
  `fixArsipPath()` legacy (DBV-029);
- tetap ditolak: segmen `.`/`..`, path absolut sesudah normalisasi (termasuk huruf drive / `\`), byte kontrol dan karakter
  tak terlihat, NUL, dan hasil resolusi yang keluar dari akar storage (dicek dengan `realpath` atas berkas yang ada; symlink
  yang menunjuk ke luar akar ditolak);
- aturan tulis untuk berkas baru v2 tidak berubah (segmen `[A-Za-z0-9._-]`);
- test keamanan path (PHPUnit): traversal (`../`, `..\`, campuran, ter-encode), path absolut, byte kontrol,
  symlink keluar akar (bila didukung OS uji), serta path legacy sah (awalan `/`, spasi) terbaca;
- variasi lain dari `fixArsipPath()` legacy dicek saat kode, agar normalisasi v2 mencakup bentuk yang sama.

**Kolom path ikut `stripslashes` (DBV-029).** Legacy membuka berkas lewat `pathToSrc(stripslashes(path))`, jadi nilai
yang disimpan di v2 dan yang dipakai manifest **wajib nilai sesudah `stripslashes`**.

**Alur runbook:**
1. Alat impor menyalin kolom path (sesudah `stripslashes`, tanpa perubahan lain) dan membuat **manifest** di folder kerja (tidak di repo): daftar path unik yang
   harus disalin per sumber (`arsip/…` → server arsip, `assets/upload/…` → server aplikasi), tanpa penggantian nama.
2. **Tarik lewat URL legacy, tanpa masuk server (K-M4 opsi 1, keputusan Horii 03-11-2026).** Legacy menyajikan berkas
   lewat HTTP (`pathToSrc()`): `arsip/…` dari alamat dasar server arsip, `assets/upload/…` dari alamat dasar aplikasi.
   `php spark lampiran:tarik --manifest=<berkas> --dasar-arsip=<url> --dasar-aplikasi=<url> [--jeda-ms=<n>]`:
   - alamat dasar hanya lewat opsi/env saat jalan — tidak pernah di config repo, `.env.example`, log, atau laporan;
     nilainya diisi operator (Horii/TL); agen tidak membuka `constants.php` dan tidak menjalankan alat ini ke server
     mana pun;
   - path manifest = nilai sesudah `stripslashes` + normalisasi `fixArsipPath` (`\` dan `./` → `/`, `/` awal dibuang);
     URL = alamat dasar + path tervalidasi (tolak `..`, path absolut, byte kontrol, skema/host di path — cegah SSRF);
     redirect ke host lain tidak diikuti; TLS verify aktif;
   - disimpan ke akar storage v2 dengan struktur relatif sama, tulis atomik (sementara → rename); berkas yang
     checksum-nya sama dilewati, jadi bisa dilanjutkan dan diulang untuk delta saat freeze;
   - laju dibatasi (`--jeda-ms`, satu koneksi), timeout, retry terbatas untuk 5xx/timeout; 404 dicatat tanpa retry;
   - sha256 dicatat ke manifest;
   - respons halaman login (HTML / redirect ke login) dihitung `butuh_login` dan alat berhenti setelah ambang;
   - keluaran hanya jumlah per sumber/tabel/kolom: berhasil, 404, galat lain, dilewati, butuh_login — tanpa nama
     berkas, NIP, host, atau URL.
3. `php spark lampiran:periksa` melaporkan jumlah path yang tidak ada di storage atau checksum-nya berbeda, per tabel/kolom,
   tanpa nama berkas.

Kolom berkas (daftar final dari audit skema salinan): `document_attachment.path`, `pegawai.foto`/`pegawai_foto`,
`riwayat_karpeg.file_*`, `absen_ijin.file_bukti..5`, `pegawai_plt`/`pegawai_plh.lampiran`, `riwayat_lckh.file_lckh`,
`riwayat_cuti` (`file_bukti`, `file_sc_*`, `file_surat_cuti`), `riwayat_tb` (`file_sp`, `file_1..3`, `file_surat_tb`),
`riwayat_tb_perpan.file_sperpan`, `riwayat_mutasi_jabatan.f_spmt/f_bapel`, `riwayat_skp_periodik.f_arsip_*`, lampiran
tiket Halo Simpeg. Kolom milik tabel MAKE-056 ikut masuk manifest yang sama (path tidak diubah). Berkas yang dibangkitkan ulang
v2 (PDF LKH, tanda terima) tidak disalin. Cara salin, volume, dan jendela waktu: K-M4.

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
| Penulis manifest berkas (daftar salin + checksum), `lampiran:tarik` (HTTP, transport bisa diganti untuk test), `lampiran:periksa` | Bagian 4 |
| Normalisasi baca path legacy di `LocalStorageAdapter` + test keamanan path (commit terpisah) | Bagian 4, opsi A |
| Command `akun:bootstrap-admin`, `integrasi:siapkan-akun-sistem` | Bagian 3 |
| Transaksi per tabel + pra-cek tier di depan + berkas jejak + `--tier N --lanjut` | T10/T11 (K-M8) |
| Batas satu baris per kunci (id terbesar) | `pegawai_hist` (K-M9b) |

## 6. Urutan runbook cutover

1. `migrate` di DB kosong.
2. Impor MAKE-056 (T1–T6), lalu MAKE-069 T7–T13.
3. Salin berkas sesuai manifest, lalu `lampiran:periksa`.
4. Tier SIASN (B3) dengan `--sumber-siasn`.
5. `integrasi:siapkan-akun-sistem`.
6. `akun:bootstrap-admin` bila diperlukan.
7. Cron integrasi diaktifkan.

## 7. Keputusan yang masih ditunggu

K-M4 (opsi 1, tarik lewat URL) dan K-M6..K-M10 (DBV-029) sudah diputus dan dimasukkan ke Bagian 1–5.

| Kode | Pemutus | Butir |
|---|---|---|
| — | DBV-028 | Skema `support_officer`, `officer_category`, `help_desk_contact` |
| — | Fase 7 F-02 | Hasil sortir `inbox` legacy untuk T13 |
