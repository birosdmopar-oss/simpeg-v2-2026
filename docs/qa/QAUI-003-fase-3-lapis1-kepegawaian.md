# QA3 Bagian D — QA UI Lapis 1 Fase 3 (QAUI-003 "Fase 3: Visual Check Kepegawaian")

> Catatan: rujukan `scratchpad/…` di laporan ini menunjuk berkas kerja QA lokal (harness, log mentah, tangkapan layar) yang **tidak dikomit**. Seluruh data uji sintetis (NIP & nama fiktif); kredensial ditulis "password seed lokal".

| | |
|---|---|
| **Jenis** | QA UI/UX Lapis 1 (visual + aksesibilitas dasar), atas izin reviewer CR |
| **Tanggal** | 15 Oktober 2026 |
| **Commit** | `main` = `576ee8f` (worktree detached `scratchpad\wt\qa3d`, kode aplikasi tidak diubah) |
| **Lingkungan** | PHP 8.4.2 `php spark serve` port 8114; Vite dev port 5214 (`.env.local` `VITE_API_BASE_URL`); MySQL 8.0.30 instance test port 3307, DB scratch `simpeg_v2_qa3d` (`utf8mb4_unicode_ci`) hasil `migrate --all` + `MasterDataSeeder` (memanggil `AuthSeeder`); `.env` dari `.env.example` (CI_ENVIRONMENT development, captcha `mock`, CORS origin Vite, JWT/encryption key acak). Browser pane bawaan (Chromium, tab sendiri) dengan emulasi 1440×900, 600×844, 390×844 |
| **Akun** | Akun seed role 1–8 (`AuthSeeder`), password seed lokal |
| **Data uji** | Sintetis. (a) Baris `pegawai` + `pegawai_mutasi_jabatan` untuk 14 NIP akun seed dibuat langsung lewat SQL (nama fiktif, mis. "Bunga Fiktif Lestari"; seed hanya membuat `pengguna`), `pengguna.id_unit/id_satker` diselaraskan ke ID numerik master seed; 1 jabatan + 3 baris `struktur_jabatan` sintetis untuk bagan. (b) Selebihnya lewat API/UI: biodata lengkap + foto (PUT/POST biodata), 13 jenis riwayat untuk 199002152015022002 (status 1 oleh Super Admin; status 0 oleh pegawai; 2 baris ditolak), pengajuan Data Umum (flag 1), Konket (W), 3 LKH (atasan = Super Admin; 1 disetujui, 1 ditolak lewat UI), usulan Karpeg, anak (UI), Plt (UI), pegawai baru PPPK (UI Tambah Pegawai), Koreksi NIP (UI), arsip KTP (UI) |
| **Acuan** | Figma belum ada → kartu QAUI-003 (profile card, tab tanpa reload, grid sort/search + ⋮, uploader drag-and-drop, badge status riwayat hijau/kuning/merah, badge jenis pegawai PNS biru/PPPK tosca/PTT kuning, modal approve/reject beralasan, form 2 kolom + asterisk merah), `AGENTS.md` §1, konsistensi token/komponen Fase 1–2 (preseden QAUI-004), butir UI B-03/B-04/B-05/B-06/B-18/B-20 dan "QA Lapis 1" B-TC |
| **Hasil singkat** | Pola §1 (⋮, urutan, hidden/disabled, aria-label, testid, badge, ConfirmDialog) **lolos di semua 13 tabel**; tab tanpa reload, badge status, modal tolak beralasan, form 2 kolom/1 kolom, asterisk merah, error merah di bawah field lolos. **Gagal**: status pengajuan Data Umum tidak pernah tampil (D-F1), tidak ada jalan menu ke profil sendiri untuk pegawai (D-F2), kolom Aksi tidak sticky di layar sempit pada tab riwayat/usulan/arsip/anak (D-F3), galat dini form berjenjang (D-F4), plus temuan Minor/Trivial lain |

## Cara uji

- Helper audit DOM (disuntik dari `frontend/public/qa-audit.js` worktree QA — berkas sementara, dihapus bersama worktree) dijalankan di setiap halaman/tab/dialog per viewport: scroll horizontal halaman (`scrollWidth - clientWidth`), per tabel: jumlah tombol ⋮ per baris, kontrol lain di baris, letak ⋮ di kolom terakhir, `aria-label` "Aksi untuk …", `data-testid`, `position` header Aksi (sticky), tombol ⋮ di luar viewport, teks terpotong. Isi menu dibaca dari `[role=menu] [data-action]` (urutan, `data-disabled`, kelas merah).
- Navigasi antar halaman lewat router SPA (penanda `window.__marker` bertahan → tidak ada reload penuh saat pindah tab).
- Menu per role: login via API (`credentials: include`) lalu muat ulang, baca tautan sidebar.
- Unduhan berkas (ekspor CSV/XLSX, DRH, PDF struktur, LKH) tidak diklik di browser (aturan unduh), diverifikasi lewat API dengan token yang sama: semua 200 dengan `Content-Type`/`Content-Disposition` benar.
- Tangkapan layar: 56 berkas di `scratchpad\qa3-shots\` pola `dNN-<halaman>-<keadaan>-<d1440|m600|m390>.jpg` (tidak dikomit). Catatan: pane browser sempat diubah lebarnya oleh pengguna, sehingga sebagian tangkapan 1440 beresolusi kecil; bukti utama berupa ukuran DOM di atas.

## Checklist per halaman

### Daftar Pegawai (`/pegawai`)
| Kriteria | Hasil | Bukti |
|---|---|---|
| ⋮ satu per baris, kolom Aksi kanan, sticky | PASS | 10/10 baris, `pegawai-actions-<nip>`, "Aksi untuk Andi Sintetis Utama"; header Aksi `sticky right-0` di 1440/600/390 |
| Urutan menu | PASS | "Lihat detail" → separator → "Hapus" merah (hidden bila tak berhak) |
| ConfirmDialog hapus | PASS | "Hapus pegawai? … dihapus PERMANEN …", Esc menutup (`d01-pegawai-konfirmasi-hapus-d1440`) |
| Tautan nama → detail | INFO | Nama berupa link navigasi (bukan aksi), sama pola tabel lain |
| Filter (status, unit, satker berjenjang, jenis, urutkan, arah) + label | PASS | 6 select berlabel (`<label for>`), satker nonaktif "Pilih unit dulu" |
| Filter Kolom | PASS / INFO | Popover pencarian kolom + centang 2 lajur; label Inggris "Toggle All Column", "Apply Column" ikut mockup (`d01-pegawai-filter-kolom-d1440`) |
| Pencarian lanjutan | PASS | Jenis Riwayat → Jenis Data → Atribut → Operator → Nilai, semua berlabel; "Umum/Nama Pegawai LIKE Fiktif" → 1 hasil + notice "Menampilkan hasil pencarian lanjutan…" (`d01-pencarian-lanjutan-hasil-d1440`); select "Seluruh Riwayat" terpotong (Trivial, lebar select) |
| Ekspor | PASS | Menu "Export Excel (.xlsx)" / "Export CSV"; API: CSV BOM 200, XLSX 200, ekspor pencarian 200 (1 baris) |
| Sort | PASS (dropdown) | Urutkan/Arah; header kolom tidak bisa diklik (lihat D-F7) |
| Responsif | 1440 PASS · 600 PASS · 390 **FAIL** | 390: tombol "Export" melewati tepi (right 397 px > 390), label "Data Pegawai"/"Pencarian Lanjutan" terbungkus 2 baris (D-F9) `d21-pegawai-list-tombol-terpotong-m390` |
| Badge jenis pegawai | **FAIL** | Jenis pegawai teks polos (D-F8) |

### Detail Pegawai — kartu profil, Data Umum, Koreksi NIP, Arsip, DRH
| Kriteria | Hasil | Bukti |
|---|---|---|
| Kartu profil: foto, nama + gelar, NIP, badge status | PASS | Foto dari `GET …/foto`, "Aktif" hijau (`d02-detail-header-dataumum-d1440`) |
| QR code, badge jenis pegawai berwarna | **FAIL** | Tidak ada QR; jenis pegawai teks polos (D-F8) |
| Status pengajuan Data Umum ("menunggu verifikasi"/"ditolak: alasan") | **FAIL** | Pengajuan flag 1 ada, notice `pengajuan-status` tidak dirender (D-F1) |
| Aksi header: Arsip (biru), Cetak (menu "Cetak DRH"), ⋯ (Koreksi NIP → Hapus Pegawai merah) | PASS | `d02-detail-menu-lainnya-d1440` |
| Setujui/Tolak pengajuan (admin) | PASS | Tombol soft hijau/merah; dialog tolak berisi foto + tabel Kolom/Sekarang/Diajukan + Alasan wajib (`d09-antrean-tolak-diff-d1440`) |
| Koreksi NIP: peringatan tegas | PASS | Langkah 2 "NIP … akan diganti … password direset … sesi diakhiri", tombol "Ya, koreksi NIP" merah, "Kembali" soft; sukses → URL pindah ke NIP baru + notice (`d16-koreksi-nip-d1440`) |
| Arsip Kepegawaian | PASS (1440) / **FAIL** (390) | Unggah KTP → baris ⋮ `lampiran-actions-19` (Unduh → Hapus); `.exe` → "Jenis file .exe tidak diperbolehkan."; 6 MB → "Ukuran file melebihi 5 MB."; 390: Aksi tidak sticky, ⋮ di x=509 (D-F3) |
| DRH | PASS | API `GET pegawai/{nip}/drh?inline=1` 200 `application/pdf` 14 KB |
| Responsif kartu | 1440 PASS · 600 **FAIL** · 390 PASS | 600: nama terpecah per kata, meta 3 baris di kolom sempit (D-F9) `d20-detail-header-sempit-m600`; 390: tombol "Ubah Data Umum" teks meluap (D-F9) |

### Tab riwayat (17 tab + Data Umum + LKH)
Tab: Data Umum, Jabatan, Kenaikan Pangkat, KGB, Pendidikan, Keluarga, Alamat, Diklat, Kursus/Seminar, Tanda Jasa, Organisasi, SKP, SKP Periodik, Angka Kredit, Angka Kredit SIASN, Hukuman Disiplin, Laporan Kerja Harian (+ "Semua Menu"). Karpeg/Karis = halaman usulan terpisah (keputusan desain, bukan tab).

| Kriteria | Hasil | Bukti |
|---|---|---|
| Pindah tab tanpa reload penuh | PASS | 18 tab diklik berurutan, `?tab=` berubah, penanda window bertahan |
| Tab aktif terlihat | **FAIL** | Buka `?tab=hukdis` → tab aktif di x=2523 di luar strip 324–1222 (D-F5) `d07-riwayat-jabatan-tab-aktif-tersembunyi-d1440` |
| ⋮ per baris, testid `riwayat-actions-<id>`, aria "Aksi untuk …" | PASS / Trivial | Semua tab berbaris; label kadang tidak deskriptif ("Aksi untuk 1", "…Seminar") (D-F12) |
| Urutan menu admin | PASS | Menunggu: Edit → Setujui → Tolak → Lampiran → Hapus(merah); Disetujui/Ditolak: Edit → Lampiran → Hapus; Keluarga: Edit → Lampiran → Anak → Hapus (`d03-riwayat-seminar-menu-menunggu-d1440`) |
| hidden vs disabled (pegawai role 2) | PASS | Menunggu: Edit, Lampiran, Hapus; Disetujui: Edit(disabled), Lampiran, Hapus(disabled); Setujui/Tolak hidden |
| Badge status | PASS | Disetujui hijau, Menunggu kuning/oranye, Ditolak merah + alasan di bawah badge (`d03-riwayat-seminar-badge-d1440`) |
| Modal tolak beralasan | PASS | Alasan * wajib → "Alasan penolakan wajib diisi." merah, `aria-invalid=true` + `aria-describedby`; Esc menutup (`d03-tolak-dialog-error-d1440`) |
| Form 2 kolom + asterisk merah, error di bawah field | PASS | Seminar: 2 kolom 1440, 1 kolom 390 (input 310 px); "* wajib diisi" merah `rgb(234,84,85)`, 6 field `aria-invalid`; lampiran wajib "Sertifikat wajib diunggah." (`d04-*`) |
| Multiselect ARIA | PASS | Sub Rumpun Jabatan = `fieldset`+`legend`, checkbox berlabel, galat "wajib dipilih minimal satu" lewat `aria-describedby` (`d07-form-jabatan-multiselect-error-d1440`) |
| Dropdown wilayah berjenjang | PASS fungsi / **FAIL** visual | Provinsi → kab/kota terisi, tetapi galat merah muncul sebelum Simpan (D-F4) `d08-form-alamat-galat-dini-d1440` |
| Lampiran dialog | PASS / **FAIL** uploader | Tabel Nama berkas/Ukuran/Diunggah + ⋮ (Unduh → Hapus disabled bila lampiran wajib terakhir); tanpa drag-and-drop/pratinjau (D-F6) `d05-lampiran-dialog-d1440` |
| Anak (keluarga) | PASS / Trivial | Tambah anak + akte via UI → tersimpan, ⋮ `anak-actions-1` Edit → Akte → Hapus; judul dialog "Anak — 1" (D-F12) `d06-*` |
| Sort kolom grid | **FAIL** | Hanya kotak "Search"; header tanpa sort (D-F7) |
| Responsif | 1440 PASS · 600/390 **FAIL** | Header Aksi `position: static`; ⋮ di x=719 (seminar) / x=1003 (jabatan) pada viewport 600/390; Status juga tersembunyi (D-F3) `d20-riwayat-seminar-aksi-tersembunyi-m600` |

### Antrean Pengajuan Data Umum (`/biodata/pengajuan`)
| Kriteria | Hasil | Bukti |
|---|---|---|
| ⋮: Setujui → Tolak → Lihat pegawai, testid `biodata-actions-1`, aria "Aksi untuk pengajuan Bunga Fiktif Lestari, S.E." | PASS | `d09-antrean-biodata-d1440` |
| Modal approve/reject + diff | PASS | lihat Detail |
| Sticky Aksi 600/390, tanpa scroll halaman | PASS | |

### Plt/Plh (`/penugasan`)
| Kriteria | Hasil | Bukti |
|---|---|---|
| Tab Plt/Plh, form tambah (label + asterisk, 2 kolom, select berjenjang) | PASS | Tambah Plt via UI berhasil (`d10-plt-form-d1440`, `d10-plt-daftar-d1440`) |
| ⋮ Edit → (Unduh lampiran) → Hapus merah; ConfirmDialog "Hapus penugasan Plt? … PERMANEN" | PASS | `d10-plt-konfirmasi-hapus-d1440`, fokus terlihat pada "Batal" |
| Status badge | PASS | "Aktif" hijau |
| Format periode | Trivial | "2026-10-01 s.d. 2026-12-31" ISO mentah (D-F13) |
| Responsif | PASS | Aksi sticky 600/390 |

### Struktur Organisasi (`/struktur-organisasi`)
| Kriteria | Hasil | Bukti |
|---|---|---|
| Bagan per format (keputusan Q6, templat so_012025) | PASS sesuai keputusan, cakupan terbatas | Kementerian (Menteri "Lowong"), Sekretariat (Es.I Lowong → Biro SDM "Plt. Dr. Candra…" badge Plt → Bagian Mutasi → kotak JF) `d11-struktur-sesmen-plt-d1440`; format deputi/inspektorat/pusat/BPO/UPT bergantung ID legacy hard-code (satker 37/38/41/49/50/104, jabatan 2060) yang tidak ada di seed — tidak divisualkan |
| Bulan | PASS | "Agustus 2026" → "Kondisi 2026-08-31" |
| PDF | PASS (API) | `struktur-organisasi/pdf` 200 `application/pdf` |
| Aksesibilitas | PASS | Bagan `<ul aria-label="Bagan struktur organisasi Sekretariat Kementerian">`; select berlabel |
| Konsistensi visual | Trivial | `<select>` native beda dari UiSelect; "Unduh PDF" abu solid tampak nonaktif (D-F14) |
| Responsif | PASS | 600/390 tanpa scroll halaman (`d20-struktur-m600`) |

### Usulan Konket (`/konket`)
| Kriteria | Hasil | Bukti |
|---|---|---|
| ⋮ Edit → Setujui → Tolak → Unduh bukti → Hapus(merah), testid `konket-actions-1` | PASS | |
| Badge W/V/X, alasan tolak di bawah badge | PASS | "Menunggu" oranye |
| Modal setujui (alasan opsional) / tolak (wajib) | PASS | `d12-konket-setujui-d1440` |
| Form ajukan | PASS / Trivial | Label + asterisk; "Batal" abu gelap solid beda dari dialog lain (D-F10) `d12-konket-form-batal-gelap-d1440` |
| Responsif | PASS | Aksi sticky; 390 tanpa scroll halaman (`d21-konket-m390`) |

### Verifikasi LKH (`/lkh/verifikasi`: LKH Saya / Verifikasi Bawahan / Rekap Bawahan)
| Kriteria | Hasil | Bukti |
|---|---|---|
| Tab, `?tab=` | PASS | |
| ⋮ Verifikasi (+ Unduh PDF bila Disetujui); LKH Saya: Edit → Unduh PDF → Hapus; Rekap: "Lihat LKH" `lkh-rekap-actions-<nip>` | PASS | `d13-lkh-verifikasi-menu-d1440`, `d13-lkh-rekap-d1440` |
| Dialog verifikasi (Setujui/Revisi/Tolak + catatan) | PASS / Trivial | Setujui & Tolak via UI berhasil; galat catatan wajib tampil sebagai banner, textarea tanpa `aria-invalid` (D-F11), "Batal" abu gelap (D-F10) `d13-lkh-proses-galat-banner-d1440` |
| Badge status | PASS | Menunggu/Disetujui/Ditolak/Revisi |
| Responsif | PASS | Aksi sticky 600/390 |

### Usulan Karpeg/Karis (`/karpeg-karis`)
| Kriteria | Hasil | Bukti |
|---|---|---|
| Admin: Setujui → Tolak → Berkas (→ Unduh tanda terima bila Disetujui) | PASS | `d14-karpeg-antrean-menu-d1440` |
| Dialog berkas per kolom | PASS | 5 berkas "Unduh", berkas opsional "—" (`d14-karpeg-berkas-d1440`) |
| Responsif | **FAIL** | Aksi tidak sticky, ⋮ di x=902 pada 600/390 (D-F3) `d20-karpeg-aksi-tersembunyi-m600` |

### Tambah Pegawai (dialog "Data Pegawai")
| Kriteria | Hasil | Bukti |
|---|---|---|
| Form 2 kolom, label + asterisk merah, petunjuk "Angka saja, maksimal 18 digit." | PASS | `d15-tambah-pegawai-form-d1440` |
| Simpan kosong → 18 field `aria-invalid` merah | PASS | `d15-tambah-pegawai-error-d1440` |
| Sukses → dialog akun (username, password awal = NIP + peringatan ganti password) + notice "lanjutkan mengisi Riwayat Jabatan" | PASS | `d15-tambah-pegawai-sukses-d1440` |

### Menu navigasi per role (ACTIVE_PHASE = 3)
| Role | Menu Fase 3 tampil | Hasil |
|---|---|---|
| 1 Super Admin | Daftar Pegawai, Pengajuan Data Umum, Struktur Organisasi, Plt/Plh, Usulan Konket, Usulan Karpeg/Karis, Verifikasi LKH (+ Pengaturan Fase 1–2) | PASS |
| 2 Pegawai | Struktur Organisasi, Usulan Konket, Usulan Karpeg/Karis, Verifikasi LKH | PASS sesuai `roles.ts`, tetapi **tanpa jalan ke profil/riwayat sendiri** (D-F2) |
| 3 Admin Satker | Daftar Pegawai, Pengajuan Data Umum, Struktur, Plt/Plh, Konket, Verifikasi LKH, Manajemen Akun | PASS |
| 4 Admin View Eselon I | Daftar Pegawai, Struktur, Plt/Plh, Konket, Karpeg/Karis, Hari Libur | PASS |
| 5 Menteri | sama dengan role 4 | PASS |
| 6 PTT | Struktur, Konket, Verifikasi LKH | PASS (D-F2 berlaku) |
| 7 PPPK | Struktur, Konket, Karpeg/Karis, Verifikasi LKH | PASS (D-F2 berlaku) |
| 8 Pimpinan | Daftar Pegawai, Struktur, Hari Libur | PASS |
Menu fase 4+ (Layanan, Presensi, Laporan, Arsip, E-Kinerja, Portal Berita, Halo Simpeg) tidak tampil untuk role mana pun.

### Lintas halaman
| Kriteria | Hasil | Bukti |
|---|---|---|
| Scroll horizontal halaman | PASS | `pageOverflowX = 0` di 13 halaman/tab × 3 viewport (tabel bergulir di wadahnya) |
| Konsol browser | PASS dengan catatan | Tanpa error JS aplikasi; Vue warn berulang `Invalid prop "idHist": Expected Number, got String "1"` (`PengajuanBiodataDialog`) — akar sama dengan D-F1; satu 404 berasal dari skrip QA (`/favicon.ico`), bukan aplikasi |
| Fokus terlihat | PASS | Ring fokus pada tombol tutup dialog, Batal ConfirmDialog, trigger ⋮ (`focus-visible:ring-2`) |
| Dialog ditutup Esc | PASS | Tolak, form riwayat, lampiran, anak, Arsip, ConfirmDialog, menu ⋮; kondisi busy tidak diuji |
| Token & komponen redesign | PASS dengan catatan | UiButton/UiCard/UiBadge/UiNotice/UiFileField/UiPagination dipakai; pengecualian D-F10, D-F14; teks Inggris "Search", "Showing x to y of z entries", "Toggle All Column/Apply Column" mengikuti mockup (Fase 2 memakai "Cari …") — INFO |

## Status per QASMTASK (butir UI / "QA Lapis 1")

| QASMTASK | Task | Butir UI yang dinilai | Hasil |
|---|---|---|---|
| 043 | B-01 | — (skema) | N/A |
| 044 | B-02 | — (skema) | N/A |
| 045 | B-03 | Profile card & badge "Menunggu Persetujuan" | **FAIL** (D-F1 Major; D-F2 Major; D-F8 Minor) |
| 046 | B-04 | Modal reject punya kolom alasan wajib | **PASS** |
| 047 | B-05 | Modal konfirmasi hapus | **PASS** |
| 048 | B-06 | Form peringatan/konfirmasi tegas | **PASS** |
| 049 | B-07 | QA Lapis 1 (tab Jabatan + Plt/Plh) | **FAIL** (D-F3; Plt/Plh sendiri PASS, D-F13 trivial) |
| 050 | B-08 | QA Lapis 1 (tab KP) | **FAIL** (D-F3) |
| 051 | B-09 | QA Lapis 1 (tab KGB) | **FAIL** (D-F3) |
| 052 | B-10 | QA Lapis 1 (tab Pendidikan) | **FAIL** (D-F3; D-F4 kemungkinan pada jenjang→bidang→jurusan) |
| 053 | B-11 | QA Lapis 1 (Diklat, Seminar) | **FAIL** (D-F3) |
| 054 | B-12 | QA Lapis 1 (SKP, SKP Periodik, LKH) | **FAIL** (D-F3 pada tab SKP; halaman Verifikasi LKH PASS dengan catatan D-F10/D-F11) |
| 055 | B-13 | QA Lapis 1 (Konket) | **PASS dengan catatan** (D-F10, D-F13 Trivial) |
| 056 | B-14 | QA Lapis 1 (Hukdis) | **FAIL** (D-F3) |
| 057 | B-15 | QA Lapis 1 (AK, AK SIASN) | **FAIL** (D-F3) |
| 058 | B-16 | QA Lapis 1 (Keluarga+Anak, Alamat; dropdown wilayah) | **FAIL** (D-F3, D-F4; D-F12 trivial) |
| 059 | B-17 | QA Lapis 1 (Karpeg/Karis, Tanda Jasa, Organisasi) | **FAIL** (D-F3) |
| 060 | B-18 | Uploader drag-and-drop & preview; tolak tipe/ukuran dengan pesan jelas | **FAIL** (D-F6; pesan tolak tipe/ukuran PASS; D-F3 pada tabel lampiran/arsip) |
| 061 | B-19 | Bagan (keputusan Q6 so_012025) | **PASS sesuai keputusan user 13-10-2026** (cakupan format terbatas data seed; INFO) |
| 062 | B-20 | Profile card, 20+ tab tanpa reload, grid sort/search, badge timeline, advanced search role 1/3/4/5, DRH | **FAIL** (D-F5, D-F7, D-F8, D-F9; tab tanpa reload, badge, advanced search, DRH PASS) |
| 063 | B-21 | — (test) | N/A |

## Temuan

| ID | Tingkat | Temuan | Reproduksi | Penyebab / opsi perbaikan | Keputusan tercatat? |
|---|---|---|---|---|---|
| D-F1 | Major | Status pengajuan perubahan Data Umum tidak pernah tampil: notice "Pengajuan perubahan data umum sedang menunggu verifikasi admin." / "…ditolak: <alasan>" tidak dirender bagi pengaju maupun admin. B-03 "badge Menunggu Persetujuan" tidak terpenuhi; pegawai juga tidak melihat alasan penolakan | Login 199002152015022002 (role 2) → `/pegawai/199002152015022002` setelah `PUT biodata` (jalur pengajuan, `flag_update` 1): `[data-testid=pengajuan-status]` = null; API `GET pegawai/{nip}` → `"pengajuan":{"id_pegawai_hist":"1","flag_update":"1",…}` | `DetailPegawaiPage.vue:245` dan `:248` membandingkan `pengajuan?.flag_update === 1/2` (number), API mengirim string (MySQLi); `types.ts:143` mengetik number tanpa normalisasi. Akar sama: Vue warn `PengajuanBiodataDialog.vue:23` `idHist: number` menerima "1". Opsi: normalisasi `Number()` di service/composable atau cast di backend; tambah test dengan payload string | Tidak |
| D-F2 | Major | Tidak ada jalan navigasi ke profil/riwayat sendiri untuk role 2/6/7 (juga 4/5/8): sidebar tanpa "Data Saya"/"Profil", menu pengguna hanya "Ganti Password"/"Keluar", Beranda tanpa tautan. Self-service B-03 (ubah Data Umum) dan pengajuan riwayat (submit → status 0) hanya bisa lewat URL manual `/pegawai/<nip>` | Login role 2 → sidebar: Beranda, Struktur Organisasi, Usulan Konket, Usulan Karpeg/Karis, Verifikasi LKH, FAQ | `nav.config.ts` (grup Kepegawaian) dan `AppSidebar.vue:136-170`. Opsi: item menu "Data Pegawai Saya" → `pegawai-detail` NIP akun (UL_ALL) atau entri di menu pengguna | Tidak ditemukan (Matriks: role 2 "lihat data pribadi", `hr/employee/detail/{nip}` UL_ALL) |
| D-F3 | Minor | Kolom Aksi tidak sticky di 600/390 px: ⋮ (dan Status) di luar area terlihat, pengguna harus menggeser tabel — pola sticky Fase 2 (F-UI-4/CR-038) belum diterapkan | 600×844: tab Seminar ⋮ x=719, tab Jabatan x=1003, Karpeg/Karis x=902; 390: Arsip ⋮ x=509 (`d20-*`, `d21-arsip-aksi-tersembunyi-m390`) | Header/sel Aksi tanpa `sticky right-0`: `RiwayatListSection.vue:343`, `UsulanMandiri.vue:261`, `ArsipTable.vue:251` (juga dialog Lampiran), `KeluargaAnakDialog.vue:215`. Tabel lain Fase 3 (Pegawai, Antrean, Plt, Konket, LKH) sudah sticky | Tidak |
| D-F4 | Minor | Galat validasi muncul sebelum Simpan pada isian berjenjang: memilih Provinsi langsung menampilkan "Kabupaten/Kota wajib diisi", "Kecamatan wajib diisi", "Kelurahan/Desa wajib diisi" | Riwayat Alamat → Tambah alamat → pilih Provinsi DKI Jakarta (`d08-form-alamat-galat-dini-d1440`) | `RiwayatFieldForm.vue:176/181/192` mengosongkan turunan lewat model `defineField` vee-validate (validasi saat update). Opsi: `setFieldValue(name, '', false)` / `resetField` | Tidak |
| D-F5 | Minor | Tab aktif tidak dibawa ke area terlihat saat halaman dibuka/di-navigasi dengan `?tab=` (mis. dari tautan) | Buka `/pegawai/199002152015022002?tab=hukdis` di 1440: tab aktif x=2523, strip 324–1222 | `RiwayatMenuTabs.vue:32-39` memanggil `scrollIntoView` hanya di `select()`. Opsi: `watch(modelValue, …, { immediate: true })` / `onMounted` | Tidak |
| D-F6 | Minor | Uploader tanpa drag-and-drop dan pratinjau berkas (B-18 DoD, kartu QAUI-003); hanya tombol "Upload File"/"Unggah berkas" | Form riwayat, Lampiran, Arsip, Anak | `UiFileField.vue`, `ArsipTable.vue:216` (input file saja) | Tidak ditemukan |
| D-F7 | Minor | Grid riwayat tanpa pengurutan kolom (B-20 "DataTables grid (sort/search)"); hanya kotak pencarian. Daftar Pegawai mengurutkan lewat dropdown, bukan header | Tab riwayat mana pun: `th` tanpa tombol/`aria-sort` | `RiwayatListSection.vue` (tidak ada logika sort) | Tidak |
| D-F8 | Minor | Kartu profil tanpa QR code dan jenis pegawai tanpa badge warna (PNS biru / PPPK tosca / PTT kuning) di kartu maupun Daftar Pegawai | `/pegawai/199002152015022002`, `/pegawai` | `PegawaiHeaderCard.vue:121` (teks), `PegawaiTable.vue` | Tidak ditemukan |
| D-F9 | Minor | Responsif: (a) 390 Daftar Pegawai tombol "Export" terpotong di tepi kanan (397 px) dan label tombol terbungkus; (b) 390 Data Umum "Ubah Data Umum" teks meluap tombol; (c) 600 kartu profil: nama terpecah per kata, meta NIP/jenis/tanggal lahir 3 baris di kolom sempit | `d21-pegawai-list-tombol-terpotong-m390`, `d21-detail-dataumum-tombol-m390`, `d20-detail-header-sempit-m600` | `DaftarPegawaiPage.vue` (baris tombol tanpa wrap/stack), `DetailPegawaiPage.vue:236-242`, `PegawaiHeaderCard.vue:99-126` (flex-wrap tanpa breakpoint) | Tidak |
| D-F10 | Trivial | Tombol "Batal" abu gelap solid (`variant="secondary"`) di dialog Konket dan LKH, sedangkan dialog lain memakai soft abu muda | Usulan Konket → Tambah; Verifikasi LKH → Verifikasi | `KonketFormDialog.vue:219`, `LkhFormDialog.vue:193`, `LkhProsesDialog.vue:83` | Tidak |
| D-F11 | Trivial | Dialog Verifikasi LKH: galat "Catatan wajib diisi…" tampil sebagai banner, textarea tidak merah dan tanpa `aria-invalid` (pola lain: galat di bawah field) | Pilih Tolak → Proses kosong | `LkhProsesDialog.vue` | Tidak |
| D-F12 | Trivial | Label aksi/judul memakai kolom pertama yang kurang deskriptif: "Aksi untuk 1" (keluarga), "Aksi untuk Seminar", "Aksi untuk Struktural", "Aksi untuk KTP"; dialog "Anak — 1" | Tab Keluarga, Seminar, Diklat, Alamat | `RiwayatListSection.vue:167-171`, `KeluargaAnakDialog.vue:190`. Opsi: kolom label per jenis (mis. `nama_pasangan`, `nama_seminar`) | Tidak |
| D-F13 | Trivial | Tanggal ISO mentah di beberapa tempat, beda dengan tab riwayat ("3 Maret 2021"): periode Plt "2026-10-01 s.d. 2026-12-31", tanggal Konket/LKH, judul dialog Konket "(2026-10-14)", "Kondisi 2026-10-15" | Plt/Plh, Konket, LKH, Struktur | `PenugasanPage.vue:158`, halaman Konket/LKH/Struktur | Tidak |
| D-F14 | Trivial | Struktur Organisasi memakai `<select>` native (panah & gaya beda dari UiSelect); tombol "Unduh PDF" dan "Unggah berkas" berwarna secondary solid `rgb(130,134,139)` sehingga tampak nonaktif | `/struktur-organisasi`, dialog Lampiran/Arsip | `StrukturOrganisasiPage.vue`, `ArsipTable.vue` | Tidak |
| D-F15 | Trivial | Breadcrumb Detail Pegawai untuk role 2/6/7 menautkan "Daftar Pegawai" yang 403 bagi role itu | Role 2 → `/pegawai/<nip sendiri>` | `DetailPegawaiPage.vue` (breadcrumbs statis) | Tidak |
| INFO-D1 | — | Teks Inggris mengikuti mockup ("Search", "Showing 1 to 3 of 3 entries", "Toggle All Column", "Apply Column"), Fase 2 memakai "Cari …" | — | `UiPagination.vue:42`, `PegawaiColumnPicker.vue:88/102` | Dicatat |
| INFO-D2 | — | Bagan: format deputi/inspektorat/pusat/BPO/UPT tidak dapat divisualkan dengan seed (ID legacy hard-code di `StrukturService.php:52-64`); tidak ada garis penghubung Es.I → Es.II pada format sesmen | `d11-struktur-sesmen-plt-d1440` | Perlu data impor/legacy untuk QA visual lengkap | Keputusan Q6 |
| INFO-D3 | — | Kotak cari Konket tidak sejajar (tanpa label, lebih rendah dari filter tanggal/status) | `d12-konket-daftar-d1440` | — | — |
| INFO-D4 | — | Badge LKH (tanpa titik) berbeda gaya dari badge riwayat (dengan titik) | `d13-*` | — | — |
| INFO-D5 | — | ConfirmDialog masih gaya lama (INFO-1 QAUI-004) | `d01-pegawai-konfirmasi-hapus-d1440` | — | Masih ada |

## Bersih-bersih

Lihat ringkasan serah-terima (server API 8114 dan Vite 5214 dihentikan, DB `simpeg_v2_qa3d` di-DROP, `.env`/`.env.local` dihapus, tab browser QA ditutup, viewport direset ke desktop).
