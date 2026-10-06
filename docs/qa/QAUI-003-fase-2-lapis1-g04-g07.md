# QAUI-003 (G-04..G-07): QA Lapis 1 Master Data setelah redesign form admin — QASMTASK-036, 037, 038, 039

| | |
|---|---|
| **Jenis** | QA UI/UX (Lapis 1, visual), dijalankan reviewer CR atas persetujuan user |
| **Tanggal eksekusi** | 2 Oktober 2026 |
| **Commit yang diuji** | `eb86d09` (`main`; CR-028 form admin memakai komponen redesign MIG-001b `0e04a01` + `eb86d09`, CR-016/CR-018..CR-024, CR-029) |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 Laragon, `php spark serve` (API) + Vite dev server di worktree QA (port terpisah dari checkout utama), DB scratch hasil `migrate --all` + `MasterDataSeeder`, captcha `mock`; akun Super Admin dari seeder test. Chrome 154 headless lewat CDP: viewport 1440×900, 600×900, dan 390×844 |
| **Acuan** | Kriteria QAUI-002 dengan tafsir keputusan user 29-09-2026 (ISSUE-007: layout form ikut redesign; "toggle switch" = aksi status lewat menu ⋮, CR-015), `AGENTS.md` §1, G-TC #7 di kartu QASMTASK-036..039 |
| **Hasil** | **Belum lolos (FAIL minor).** Komponen redesign, label di atas, grid 2/1 kolom, error merah di bawah field, tombol Batal/Simpan, menu ⋮, badge status, dan ConfirmDialog **PASS** untuk 19 master. Yang gagal hanya butir "tidak ada elemen terpotong" (F-UI-1 di 1440px, F-UI-2 di 390–600px) dan konsistensi teks pesan induk (F-UI-3, G-05/G-06/G-07). Ketiganya ada di komponen bersama `MasterDataView`/`MasterFormDialog`, jadi satu perbaikan menutup keempat kartu. |

## Cara uji

- Untuk tiap master: buka `/master/<slug>`, ukur DOM (header tabel, isi kolom Aksi, aria-label/testid tombol ⋮, warna badge, isi menu ⋮, tata letak form, posisi dan warna pesan error), lalu ambil tangkapan layar daftar, menu ⋮ (1440px), form tambah, dan form setelah **Simpan** dikirim kosong (error validasi klien).
- Pada satu master per kartu (`jenis-kp`, `jenjang-pendidikan`, `tanda-jasa`, `agama`) satu entri dinonaktifkan dan satu dihapus lewat API, lalu diperiksa: badge Tidak Aktif/Dihapus, menu baris tidak aktif (Aktifkan), menu baris terhapus (Pulihkan, lewat filter status Dihapus), ConfirmDialog Hapus, form Edit, dan error 422 backend (nama duplikat).
- Terpotong/tumpang tindih: scroll horizontal halaman, elemen keluar layar, dan teks yang melewati kotak induk yang memotong (`overflow` ≠ `visible`), ditambah pemeriksaan visual tangkapan layar.

Nama tangkapan layar: `<kartu>-<master>-<viewport>-<n>-<keadaan>.png`, viewport `d1440` / `m600` / `m390`, keadaan `1-daftar`, `2-menu`, `3-form-tambah`, `4-form-error`, `5-menu-tidak-aktif`, `6-konfirmasi-hapus`, `7-menu-dihapus`, `8-form-edit`, `9-form-error-duplikat`. Total 211 file, disimpan di folder screenshot lokal QA (tidak dikomit, sesuai konvensi repo yang tidak menyimpan gambar QA).

## Checklist kriteria

| # | Kriteria | 036 G-04 | 037 G-05 | 038 G-06 | 039 G-07 | Bukti |
|---|---|---|---|---|---|---|
| 1 | Form dialog memakai komponen redesign (`UiTextField`/`UiSelect`/`UiCheckbox`/`UiButton`) | PASS | PASS | PASS | PASS | `*-d1440-3-form-tambah.png`; dialog `rounded-2xl`, field ber-`label for` + input redesign |
| 2 | Label di atas field | PASS | PASS | PASS | PASS | Semua label teks/select berada di atas input (19/19 master). Checkbox jenjang di jurusan berlabel di samping kotak (pola checkbox), `037-G05-jurusan-pendidikan-d1440-3-form-tambah.png` |
| 3 | 2 field per baris di layar lebar, 1 kolom di layar sempit | PASS | PASS | PASS | PASS | `grid-template-columns`: 2 kolom di 1440px (19/19), 1 kolom di 600px dan 390px (19/19); textarea kantor dua kolom penuh. `*-d1440-3-*`, `*-m600-3-*`, `*-m390-3-*` |
| 4 | Error merah di bawah field | PASS | PASS | PASS | PASS | Submit kosong: setiap field wajib merah (`rgb(234,84,85)`), pesan tepat di bawah input, `aria-invalid=true`, `role=alert` (19/19 di tiga viewport). `*-4-form-error.png`; error 422 backend tampil di field + banner, `*-9-form-error-duplikat.png` |
| 5 | Tombol Batal/Simpan gaya redesign | PASS | PASS | PASS | PASS | Batal `UiButton` secondary soft (bg `#F1F5F9`), Simpan primary (bg `#217AFF`, teks putih), radius 8px, kanan bawah |
| 6 | Aksi baris lewat menu ⋮, urutan Edit → Aktifkan/Nonaktifkan → urutan → Pulihkan → Hapus merah | PASS | PASS | PASS | PASS | Satu tombol per baris di kolom Aksi (19/19); `aria-label` "Aksi untuk <nama>", testid `master-actions-<id>`. Baris aktif: Edit, Nonaktifkan, Naikkan urutan (nonaktif di baris pertama), Turunkan urutan, garis pemisah, Hapus merah; baris tidak aktif: Edit, **Aktifkan**, …; baris terhapus: Edit, **Pulihkan** (tanpa Hapus). Master berinduk/berlingkup tanpa filter induk tidak menampilkan aksi urutan (petunjuk "Pilih … untuk mengubah urutan"). `*-d1440-2-menu.png`, `*-5-menu-tidak-aktif.png`, `*-7-menu-dihapus.png` |
| 7 | Kolom Status hanya badge | PASS | PASS | PASS | PASS | Aktif hijau (`#DCFCE7`/`#166534`), Tidak Aktif abu (`#E2E8F0`/`#475569`), Dihapus merah (`#FEE2E2`/`#B91C1C`); tanpa switch. `*-5-menu-tidak-aktif.png`, `*-7-menu-dihapus.png` |
| 8 | ConfirmDialog untuk Hapus | PASS | PASS | PASS | PASS | Judul `Hapus <Master> "<nama>"?`, penjelasan soft delete, tombol Batal + Hapus merah. `*-6-konfirmasi-hapus.png` |
| 9 | Teks Bahasa Indonesia konsisten | PASS | **FAIL** (F-UI-3) | **FAIL** (F-UI-3) | **FAIL** (F-UI-3) | Semua label, tombol, menu, dan pesan berbahasa Indonesia, tetapi field induk memakai pesan generik "Induk wajib dipilih." sementara field lain "<Label> wajib dipilih.": jurusan, jenis hukdis, kabupaten/kota, kecamatan, kelurahan. `037-G05-jurusan-pendidikan-d1440-4-form-error.png`, `038-G06-jenis-hukdis-d1440-4-form-error.png`, `039-G07-kelurahan-d1440-4-form-error.png` |
| 10a | Tidak ada elemen terpotong/tumpang tindih di 1440px | **FAIL** (F-UI-1) | **FAIL** (F-UI-1) | **FAIL** (F-UI-1) | **FAIL** (F-UI-1) | Tabel, form, dan dialog tidak terpotong (19/19), tetapi daftar master di sisi kiri (ada di setiap halaman master) memotong "Jenis Konfirmasi Ketidakhadira…" (master G-06) dan "Instansi Penyelenggara Kursus…". `038-G06-jenis-konket-d1440-0-halaman-penuh-nav.png`, `036-G04-pangkat-d1440-1-daftar.png` |
| 10b | Tidak ada elemen terpotong/tumpang tindih di ±390–600px | **FAIL** (F-UI-2) | **FAIL** (F-UI-2) | **FAIL** (F-UI-2) | **FAIL** (F-UI-2) | Form dan dialog rapi satu kolom tanpa scroll horizontal (19/19). Di 390px kolom Aksi (⋮) berada di luar area tabel yang terlihat untuk 18/19 master (juga Status untuk master 6 kolom), dan header terpotong di tepi; di 600px kolom Aksi kelurahan terpotong ("AKS"). `037-G05-jurusan-pendidikan-m390-1-daftar.png`, `039-G07-kelurahan-m600-1-daftar.png` |

## Status per QASMTASK

| QASMTASK | Grup | Master diuji | Hasil QA Lapis 1 | Catatan |
|---|---|---|---|---|
| 036 | G-04 Kenaikan Pangkat | pangkat, jenis_kp, gol_pppk | **FAIL minor** | Gagal hanya di #10a/#10b (komponen bersama). Pangkat mode urutan manual: menu tanpa aksi urutan, label "Urutan (nilai tetap)", sesuai DBV-004 |
| 037 | G-05 Pendidikan | jenjang, bidang, jurusan | **FAIL minor** | #9 (jurusan), #10a/#10b |
| 038 | G-06 Diklat, Hukdis, Konket, Tanda Jasa | diklat, tingkat & jenis hukdis, jenis_konket, tanda_jasa | **FAIL minor** | #9 (jenis hukdis), #10a (nama menu jenis konket sendiri terpotong), #10b |
| 039 | G-07 Data Umum & Wilayah | agama, jenis_pegawai, jenis_status, kantor, provinsi, kabupaten/kota, kecamatan, kelurahan | **FAIL minor** | #9 (kabupaten/kota, kecamatan, kelurahan), #10a/#10b |

## Temuan

| ID | Tingkat | Temuan | Langkah reproduksi | Usulan |
|---|---|---|---|---|
| F-UI-1 | Minor | Daftar master (kolom kiri `MasterDataView`, lebar tetap 220px) memotong nama master yang panjang di 1440px: "Jenis Konfirmasi Ketidakhadiran" (sekitar 8px, master G-06) dan "Instansi Penyelenggara Kursus/Seminar" (sekitar 60px). Teks tidak turun baris dan tidak ada elipsis atau tooltip | Login Super Admin → Master Data → viewport 1440×900 → lihat dua item tersebut di daftar master kiri | Biarkan teks turun baris (hapus `whitespace-nowrap`) atau lebarkan kolom; berlaku untuk semua master |
| F-UI-2 | Minor | Di 390px tabel daftar lebih lebar daripada kartunya (`overflow-x-auto`), sehingga kolom Aksi (⋮) di 18/19 master, dan juga kolom Status/induk di master 6 kolom, hanya bisa dicapai dengan geser horizontal tanpa penanda. Header terpotong di tepi. Di 600px kolom Aksi kelurahan terpotong ("AKS") | Viewport 390×844 → `/master/jurusan-pendidikan` (atau master lain); viewport 600×900 → `/master/kelurahan` | Perlu keputusan reviewer: ADR-028 menyatakan modul admin cukup tablet ke atas, sedangkan kriteria QA ini meminta 390–600px. Bila tetap berlaku: kolom Aksi `sticky right-0`, atau sembunyikan kolom Kode/Urutan di layar sempit |
| F-UI-3 | Minor | Pesan validasi klien untuk dropdown induk adalah "Induk wajib dipilih.", tidak memakai label field-nya ("Bidang Pendidikan", "Tingkat Hukuman Disiplin", "Provinsi", "Kabupaten/Kota", "Kecamatan") seperti field lain ("Jenis Pangkat wajib dipilih.") | Tambah Jurusan Pendidikan / Jenis Hukuman Disiplin / Kabupaten/Kota / Kecamatan / Kelurahan/Desa → Simpan kosong | Pakai pola "<Label induk> wajib dipilih." |
| INFO-1 | — | ConfirmDialog masih bergaya lama (tombol radius 6px, Batal outline transparan, Hapus `red-600`), belum `UiButton` redesign seperti form | `*-6-konfirmasi-hapus.png` | Restyle ConfirmDialog bersama halaman tabel (di luar cakupan CR-028 yang hanya form) |
| INFO-2 | — | Error 422 backend tampil dua kali: banner atas form dan di bawah field, dengan teks yang sama | Tambah Agama "Islam" → Simpan (`039-G07-agama-d1440-9-form-error-duplikat.png`) | Tampilkan banner hanya untuk error tanpa field |
| INFO-3 | — | Tombol halaman "Tambah …" masih navy lama (`#1C3964`), sedangkan Simpan di dialog memakai biru primary redesign (`#217AFF`) | `*-d1440-3-form-tambah.png` | Ikut restyle halaman (branch redesign) |
| INFO-4 | — | Menu baris terhapus berisi Edit + Pulihkan. Edit pada entri Dihapus tetap tersedia; bila tidak dimaksudkan, `hidden: true` | `*-7-menu-dihapus.png` | Konfirmasi ke reviewer |
| INFO-5 | — | Di layar sempit daftar master kiri menjadi strip yang digeser horizontal; item di luar layar memang tersembunyi oleh desain (bisa digeser), bukan terpotong | `*-m390-1-daftar.png` | — |

Tidak ada error konsol browser selama seluruh pengujian.

## Bersih-bersih

Server QA (API, Vite, Chrome headless) dihentikan; DB scratch QA di-drop dengan nama persis; worktree QA tidak mengubah kode aplikasi. Data uji (entri nonaktif/terhapus) hanya ada di DB scratch.
