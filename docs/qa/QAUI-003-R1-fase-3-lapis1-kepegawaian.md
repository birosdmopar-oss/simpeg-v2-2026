# QA3 R1 Bagian D — QA ulang UI Lapis 1 Fase 3 (perbaikan CR-049, ISSUE-033..038)

> Catatan: rujukan `scratchpad/…` di laporan ini menunjuk berkas kerja QA lokal (harness, log mentah, tangkapan layar) yang **tidak dikomit**. Seluruh data uji sintetis (NIP & nama fiktif); kredensial ditulis "password seed lokal".

| | |
|---|---|
| **Jenis** | QA UI/UX Lapis 1 ulang (R1), atas izin reviewer CR |
| **Tanggal** | 15 Oktober 2026 |
| **Commit** | `main` = `91264e3` (Merge CR-049 + CR-050), worktree detached `scratchpad\wt\qa3r1d`, kode aplikasi tidak diubah |
| **Lingkungan** | PHP 8.4.2 `php spark serve` port 8124; Vite dev port 5224 (`.env.local` `VITE_API_BASE_URL`); MySQL 8.0 instance test port 3307, DB scratch `simpeg_v2_qa3r1d` (`utf8mb4_unicode_ci`) hasil `migrate --all` + `MasterDataSeeder` (memanggil `AuthSeeder`); `.env` dari `.env.example` (CI_ENVIRONMENT development, captcha `mock`, CORS origin Vite, JWT/encryption key acak). Browser pane bawaan, tab sendiri, emulasi 1440×900, 600×844, 390×844 |
| **Akun** | Akun seed role 1–8, password seed lokal; plus 1 akun uji role 6 tanpa NIP (dibuat lewat SQL untuk uji ISSUE-034) |
| **Data uji** | Sintetis. SQL: 14 baris `pegawai` + `pegawai_mutasi_jabatan` untuk NIP akun seed (nama fiktif; jenis PNS/PTT/PPPK sesuai id master 1/2/6), `pengguna.id_unit/id_satker` diselaraskan ke ID master, 3 baris `struktur_jabatan`. API: biodata lengkap + foto, 13 jenis riwayat (Super Admin, status 1) + seminar/organisasi/alamat oleh pegawai (status 0), 3 seminar dan 1 KP tambahan bertanggal beda (uji urut), 1 seminar ditolak, 2 pengajuan Data Umum (keduanya ditolak: 1 lewat UI, 1 lewat API), Konket, 3 LKH (atasan = Super Admin), Plt. UI: usulan Karpeg (seret-lepas 5 berkas), anak + akte (seret-lepas), 2 lampiran keluarga, 1 arsip KTP |
| **Hasil singkat** | ISSUE-033, 034, 035, 036, 038 **lolos**. ISSUE-037 **lolos sebagian**: seret-lepas + pratinjau dan QR NIP lolos, tetapi badge jenis pegawai tetap abu-abu untuk nama master yang dipakai seed (R1D-F1, Minor). Regresi Lapis 1 (pola §1 di 13 tabel, scroll horizontal halaman, konsol, menu per role) lolos |

## Cara uji

- Alat sama dengan QA pertama (`scratchpad\qa3r1d-tools\`: `audit.js` disuntik dari `frontend/public/qa-audit.js` worktree QA, berkas sementara, ikut terhapus bersama worktree; `api.mjs`/`create.mjs`/`dl.mjs` untuk data dan unduhan).
- Seret-lepas: `DragEvent` `dragenter`/`dragover`/`drop` dengan `DataTransfer` berisi `File` sintetis (PNG kanvas 40×40, PDF kecil, `.exe`, `.docx`, PDF 6 MB) lewat `javascript_tool`.
- Menu per role: login API (`credentials: include`) lalu muat aplikasi di iframe tersembunyi dan baca tautan sidebar.
- Unduhan tidak diklik di browser. Verifikasi lewat API: DRH 200 `application/pdf` 14 KB, struktur PDF 200, ekspor XLSX 200, CSV 200 (BOM).
- Tangkapan layar: 16 berkas di `scratchpad\qa3r1-shots\` pola `r1-NN-<halaman>-<keadaan>-<d1440|m600|m390>` (`.jpg` tangkapan penuh, `.png` hasil `zoom`). Lebar pane browser sempat berubah dua kali saat pengujian, sehingga emulasi direset sendiri. Tangkapan 1440 beresolusi kecil dan tangkapan 600/390 dipotong pane. Bukti utama tetap ukuran DOM.

## Hasil per temuan QA pertama

| Temuan (kartu) | Hasil R1 | Bukti |
|---|---|---|
| **D-F1** (ISSUE-033): status pengajuan Data Umum | **PASS** | Pengaju (role 2), flag 1: `[data-testid=pengajuan-status]` = "Pengajuan perubahan data umum sedang menunggu verifikasi admin." (info tosca) `r1-01`. Admin (role 1): notice yang sama + tombol "Setujui Pengajuan"/"Tolak Pengajuan" `r1-08`. Tolak lewat UI (dialog "Tolak pengajuan Data Umum — Bunga Fiktif Lestari", diff Nomor HP) → notice merah "Pengajuan perubahan data umum ditolak: Nomor HP tidak sesuai berkas". Pengaju setelah pengajuan ke-2 ditolak → "…ditolak: Nomor HP belum terverifikasi" + "Lihat/Sembunyikan perubahan yang diajukan" (diff inline). Konsol tanpa Vue warn `idHist` (hanya debug Vite) |
| **D-F2** (ISSUE-034): menu "Kelola Data" | **PASS** | Sidebar grup KEPEGAWAIAN: role 2 → "Kelola Data" `/pegawai/199002152015022002`, role 6 → `/pegawai/199505052018052006`, role 7 → `/pegawai/199308082019082007`; aktif (`aria-current`) saat dibuka, navigasi SPA (penanda window bertahan). Role 1/3/4/5/8 tanpa "Kelola Data". Akun role 6 tanpa NIP → tanpa "Kelola Data" |
| **D-F15** (ISSUE-034): breadcrumb | **PASS** | Role 2/6/7: `A:Home` → `SPAN:Data Pegawai` (tanpa tautan "Daftar Pegawai"); role 1 dan 4: `Home` → `Daftar Pegawai` (tautan) → `Data Pegawai` |
| **D-F3** (ISSUE-035): Aksi sticky di layar sempit | **PASS** | 600 dan 390: header Aksi `position: sticky` dan tidak ada ⋮ di luar viewport pada 17 tab riwayat (role 1) dan 14 tab (role 2). Tombol ⋮ di x=535–563 (600) / x=325–353 (390). Usulan Karpeg pegawai `usulan-actions-1` dan antrean admin (390: ⋮ x=325–353) `r1-03`, `r1-04`, `r1-14`. Dialog Lampiran `lampiran-actions-6` (390: 305–333) `r1-10`. Dialog Anak `anak-actions-1` (600: 515–543, 390: 305–333) `r1-11`. Arsip `lampiran-actions-24` (600/390) `r1-12`. `pageOverflowX = 0` di semua halaman/tab di 3 viewport |
| **D-F9** (ISSUE-035): tata letak sempit | **PASS** | (a) Daftar Pegawai 390: "Export" x=236–339 (< 390), tombol tidak terbungkus (`scrollWidth = clientWidth`) `r1-13`; (b) "Ubah Data Umum" 390: lebar 154 = scrollWidth, tidak meluap `r1-05`; (c) kartu profil 600: nama satu baris, meta NIP/jenis/tanggal lahir sebaris, QR di kanan `r1-06` |
| **D-F7** (ISSUE-036): urut kolom grid riwayat | **PASS** | Tab Seminar: header kolom data berupa `<button>` + `aria-sort` (`none` → `ascending` → `descending`). Tanggal Sertifikat naik: 15 Juli 2019, 9 Desember 2020, 3 Maret 2021, 3 Maret 2021, 2 November 2023 (urut kronologis, bukan abjad); turun kebalikannya. Klik Nama Kegiatan → `ascending`, kolom Tanggal kembali `none`. Status/Aksi tanpa sort |
| **D-F5** (ISSUE-036): `?tab=hukdis` | **PASS** | Muat penuh `/pegawai/199002152015022002?tab=hukdis` di 1440: tab aktif "Riwayat Hukuman Disiplin" x=868–1058, di dalam strip 324–1222 |
| **D-F4** (ISSUE-036): galat dini isian berjenjang | **PASS** | Alamat → Tambah → Provinsi DKI Jakarta: 0 galat, 0 `aria-invalid`, kab/kota terisi 4 opsi. Simpan kosong → 5 galat ("Jenis Alamat/Alamat/Kabupaten/Kota/Kecamatan/Kelurahan/Desa wajib diisi"), 5 `aria-invalid`. Pendidikan: pilih Jenjang Strata 1 → bidang terisi, 0 galat |
| **D-F6** (ISSUE-037): seret-lepas + pratinjau | **PASS** (catatan INFO R1D-I1) | `UiFileField` (form riwayat Pendidikan, Usulan Karpeg, Anak, Konket): `dragover` → kotak garis putus biru "Lepaskan berkas di sini"; PNG → `file-preview` berisi `<img alt="Pratinjau berkas terpilih">` + nama + "0,2 KB"; PDF → ikon `FileText` merah + nama + "1,2 KB" `r1-02`, `r1-09`. Usulan Karpeg 5 berkas hasil seret-lepas tersimpan ("usulan tersimpan dan menunggu proses"). Anak + akte PNG hasil seret-lepas tersimpan. Lampiran dan Arsip (`arsip-dropzone`): sorot saat `dragover`, drop langsung mengunggah. Pratinjau "Mengunggah akte-uji.png (190 B)… [img]" / "Mengunggah sk-uji.pdf (1,2 KB)…", lalu baris tabel bertambah |
| Pesan tolak tipe/ukuran (B-18) | **PASS** | Semua uploader: `.exe` → "Jenis file .exe tidak diperbolehkan.", 6 MB → "Ukuran file melebihi 5 MB.", `.docx` di lampiran pendidikan → "Jenis file .docx tidak diperbolehkan." (`role=alert`; Lampiran/Arsip lewat `arsip-notice`) |
| **D-F8** (ISSUE-037): QR NIP | **PASS** | `<img data-testid="pegawai-qr" aria-label="Kode QR NIP 199002152015022002">` 72 px di kartu profil (1440/600/390) |
| **D-F8** (ISSUE-037): badge jenis pegawai | **FAIL** (R1D-F1) | Kartu (`pegawai-jenis`) dan Daftar Pegawai (`badge-jenis-pegawai`) untuk "Pegawai Negeri Sipil", "Pegawai Tidak Tetap", "Pegawai Pemerintah dengan Perjanjian Kerja" (nama master seed) semuanya netral `rgb(241,245,249)`/`rgb(71,85,105)`. Setelah kolom `pegawai.jenis_pegawai` 3 baris diganti lewat SQL menjadi "PPPK"/"PTT"/"PNS": tosca `rgb(224,247,250)`, kuning `rgba(255,192,67,.2)`, biru `rgba(33,122,255,.1)` `r1-16` |
| **D-F10** (ISSUE-038): Batal soft | **PASS** | Konket form "Ajukan Konfirmasi Ketidakhadiran", dialog Setujui Konket dan dialog Verifikasi LKH: "Batal" `rgb(241,245,249)` teks `rgb(71,85,105)` (soft), sama dengan dialog lain |
| **D-F11** (ISSUE-038): galat catatan LKH | **PASS** | Tolak + Proses kosong: textarea `aria-invalid=true`, border `rgb(234,84,85)`, `aria-describedby="lkh-catatan-galat"`. Teks "Catatan wajib diisi saat menolak atau meminta revisi." merah tepat di bawah textarea (top 537 > bottom 527), tanpa banner `r1-15` |
| **D-F12** (ISSUE-038): label ⋮ / judul deskriptif | **PASS** | "Aksi untuk Pasangan Fiktif", "Aksi untuk Seminar — Workshop Anggaran Fiktif", "Aksi untuk Struktural — Diklatpim Tingkat IV", "Aksi untuk KTP — Jl. Contoh No. 1", "Aksi untuk akte-uji.png", "Aksi untuk LKH Bunga Fiktif Lestari, S.E. 13 Oktober 2026", "Aksi untuk konket … 15 Oktober 2026". Judul "Data Anak — Perkawinan dengan Pasangan Fiktif", "Lampiran Pasangan Fiktif" |
| **D-F13** (ISSUE-038): tanggal Indonesia | **PASS** | Plt "1 Oktober 2026 s.d. 31 Desember 2026"; Konket daftar "15 Oktober 2026", judul "Setujui konfirmasi ketidakhadiran … (15 Oktober 2026)"; LKH "13 Oktober 2026"; Struktur "Kondisi 15 Oktober 2026 · Periode struktur: 2024". Filter tanggal Konket memakai `input[type=date]` (format mengikuti peramban, INFO) |
| **D-F14** (ISSUE-038): Struktur UiSelect, tombol | **PASS** | Struktur: 2 select berlabel ("Unit / Satuan Kerja", "Bulan") dengan kelas UiSelect (`appearance-none … pr-10`). "Unduh PDF" dan "Unggah berkas" (Lampiran/Arsip) soft biru `rgba(33,122,255,.1)`/`rgb(33,122,255)`, aktif |

## Regresi Lapis 1

| Kriteria | Hasil | Bukti |
|---|---|---|
| Pola AGENTS.md §1 di 13 tabel (Pegawai, riwayat, Anak, Lampiran, Arsip, Usulan saya, Antrean usulan, Antrean pengajuan Data Umum, Plt/Plh, Konket, LKH Saya, Verifikasi LKH, Rekap LKH) | PASS | 1 ⋮ per baris di kolom terakhir, `aria-label` "Aksi untuk …", testid `<fitur>-actions-<id>` (`pegawai-`, `riwayat-`, `anak-`, `lampiran-`, `usulan-`, `biodata-actions-2`, `penugasan-actions-1`, `konket-actions-1`, `lkh-rekap-actions-<nip>`). Urutan: riwayat Menunggu Edit → Setujui → Tolak → Lampiran → Hapus(merah); Disetujui Edit → Lampiran → Hapus; Keluarga Edit → Lampiran → Anak → Hapus; Konket Edit → Setujui → Tolak → Unduh bukti → Hapus; Plt Edit → Hapus; antrean Data Umum Setujui → Tolak → Lihat pegawai; Karpeg admin Setujui → Tolak → Berkas. Kontrol lain di baris hanya tautan nama di Daftar Pegawai (INFO lama) |
| Tanpa scroll horizontal halaman | PASS | `pageOverflowX = 0`: 9 halaman di 1440, Detail Pegawai semua tab + dialog di 600/390, Karpeg, Daftar Pegawai 390 |
| Konsol | PASS | Tanpa error/warn aplikasi. Satu warn "File chooser dialog can only be shown with a user activation" berasal dari klik skrip QA pada "Unggah berkas" |
| Menu per role (ACTIVE_PHASE 3) vs `roles.ts` | PASS | 1: Daftar Pegawai, Pengajuan Data Umum, Struktur, Plt/Plh, Konket, Karpeg/Karis, Verifikasi LKH (+ Master Data, Web Config, Hari Libur, Manajemen Akun, FAQ). 2: Kelola Data, Struktur, Konket, Karpeg/Karis, Verifikasi LKH. 3: Daftar Pegawai, Pengajuan Data Umum, Struktur, Plt/Plh, Konket, Verifikasi LKH, Manajemen Akun. 4 = 5: Daftar Pegawai, Struktur, Plt/Plh, Konket, Karpeg/Karis, Hari Libur. 6: Kelola Data, Struktur, Konket, Verifikasi LKH. 7: Kelola Data, Struktur, Konket, Karpeg/Karis, Verifikasi LKH. 8: Daftar Pegawai, Struktur, Hari Libur. Sama dengan laporan pertama + "Kelola Data" untuk 2/6/7 |
| Dialog ditutup Esc | PASS | Form Alamat, Anak, Lampiran, Arsip, menu ⋮ |
| Unduhan (API) | PASS | DRH, PDF struktur, ekspor XLSX/CSV 200 dengan `Content-Type`/`Content-Disposition` benar |

## Status QASMTASK (butir UI yang FAIL di laporan pertama)

| QASMTASK | Task | Hasil pertama | Hasil R1 | Catatan |
|---|---|---|---|---|
| 045 | B-03 | FAIL (D-F1, D-F2, D-F8) | **FAIL** | D-F1 dan D-F2 lolos, QR lolos. Badge jenis pegawai di kartu profil tetap netral pada data seed (R1D-F1 Minor) |
| 049 | B-07 | FAIL (D-F3) | **PASS** | Tab Jabatan sticky 600/390; Plt/Plh tanggal Indonesia |
| 050 | B-08 | FAIL (D-F3) | **PASS** | Tab KP sticky, urut tanggal TMT |
| 051 | B-09 | FAIL (D-F3) | **PASS** | |
| 052 | B-10 | FAIL (D-F3, D-F4) | **PASS** | Jenjang → bidang tanpa galat dini; seret-lepas di form |
| 053 | B-11 | FAIL (D-F3) | **PASS** | Diklat, Seminar (urut kolom, label deskriptif) |
| 054 | B-12 | FAIL (D-F3) | **PASS** | SKP, SKP Periodik, tab LKH sticky; Verifikasi LKH: D-F10/D-F11 lolos |
| 055 | B-13 | PASS dengan catatan | **PASS** | D-F10 dan D-F13 lolos |
| 056 | B-14 | FAIL (D-F3) | **PASS** | Tab Hukdis sticky; `?tab=hukdis` terlihat |
| 057 | B-15 | FAIL (D-F3) | **PASS** | AK dan AK SIASN sticky (AK SIASN 0 baris, header sticky) |
| 058 | B-16 | FAIL (D-F3, D-F4) | **PASS** | Keluarga + dialog Anak sticky, judul deskriptif; Alamat tanpa galat dini |
| 059 | B-17 | FAIL (D-F3) | **PASS** | Karpeg/Karis pegawai dan admin sticky; Tanda Jasa, Organisasi sticky |
| 060 | B-18 | FAIL (D-F6) | **PASS dengan catatan** | Seret-lepas + pratinjau + pesan tolak lolos di 5 uploader; R1D-I1 (INFO) |
| 062 | B-20 | FAIL (D-F5, D-F7, D-F8, D-F9) | **FAIL** | D-F5, D-F7, D-F9, QR lolos; badge jenis pegawai Daftar Pegawai/kartu tetap netral (R1D-F1) |

## Temuan baru

| ID | Tingkat | Temuan | Reproduksi | Penyebab / opsi perbaikan | Keputusan tercatat? |
|---|---|---|---|---|---|
| R1D-F1 | Minor | Badge jenis pegawai (D-F8) hanya berwarna bila teks jenis memuat singkatan "PNS"/"PPPK"/"PTT". Dengan nama master yang dipakai seed v2 ("Pegawai Negeri Sipil", "Pegawai Tidak Tetap", "Pegawai Pemerintah dengan Perjanjian Kerja"), semua badge netral abu-abu, di kartu profil maupun Daftar Pegawai. Test unit memakai fixture `'PNS'` sehingga tidak menangkapnya | Login role 1 → `/pegawai`: kolom Jenis Pegawai semua `rgb(241,245,249)`. Kartu role 4/6/7 sama. Bila `pegawai.jenis_pegawai` diubah ke "PPPK"/"PTT"/"PNS" → tosca/kuning/biru (`r1-16`) | `frontend/src/features/kepegawaian/columns.ts:45-51` (`nadaJenisPegawai` mencocokkan teks `includes('PPPK'/'PTT'/'NON'/'PNS')`), dipakai `PegawaiHeaderCard.vue:140` dan `PegawaiTable.vue:90`. Opsi: tentukan nada dari `id_jenis_pegawai` (1 = PNS, 2 = PTT, 6 = PPPK, sama dengan [K] `AkunPegawai::roleUntukJenisPegawai`, komentar `MasterDataSeeder.php:29-31`) dengan teks sebagai cadangan, atau tambah kecocokan nama panjang ("Negeri Sipil", "Tidak Tetap", "Perjanjian Kerja"); tambah test dengan nama master. Nama master produksi belum diverifikasi (legacy `Tester.php:2074` memakai "PPPK"), jadi bisa saja tampil benar setelah impor. Perlu dicek reviewer | Tidak |
| R1D-I1 | INFO | Uploader Lampiran/Arsip (`ArsipTable.vue:262-265`) langsung mengunggah saat drop. Pratinjau hanya tampil selama unggah: gambar berupa thumbnail, PDF/lainnya hanya teks "Mengunggah <nama> (<ukuran>)…" tanpa ikon. Setelah selesai, berkas muncul sebagai baris tabel (nama + ukuran). Tidak menyalahi DoD karena alurnya unggah langsung | Lampiran keluarga / Arsip KTP → seret PDF | Opsional: ikon `FileText` untuk non-gambar seperti `UiFileField` | — |
| R1D-I2 | INFO | Galat catatan LKH (`LkhProsesDialog.vue:98`) tanpa `role="alert"`. Galat tetap terbaca lewat `aria-describedby` saat fokus di textarea, tetapi tidak diumumkan otomatis (pola `UiFileField` memakai `role="alert"`) | Verifikasi LKH → Tolak → Proses kosong | Opsional: tambah `role="alert"` | — |

Catatan teknis: pada tangkapan `zoom` 600/390 kadang tampak sisa glyph ⋮ 1–3 px di luar tepi kanan pembungkus tabel. Pemeriksaan `elementFromPoint` menunjukkan sel Aksi sticky menutup sampai tepi pembungkus (349,6 vs 350,4 px) dan tidak ada elemen di luar batas itu. Sisa glyph ini artefak render emulasi, bukan temuan.

## Bersih-bersih

Server API 8124 dan Vite 5224 dihentikan, `backend/.env` dan `frontend/.env.local` (serta `frontend/public/qa-audit.js`) dihapus, DB `simpeg_v2_qa3r1d` di-DROP, worktree `scratchpad\wt\qa3r1d` dihapus (`git worktree remove --force`), tab browser QA ditutup, viewport direset ke desktop.
