# QAUI-003-R1 (G-04..G-07): QA Lapis 1 ulang Master Data setelah CR-038 — QASMTASK-036, 037, 038, 039

| | |
|---|---|
| **Jenis** | QA UI/UX ulang (Lapis 1, visual) + cek API F-R1-1, dijalankan reviewer CR atas persetujuan user |
| **Tanggal eksekusi** | 2 Oktober 2026 |
| **Commit yang diuji** | `9399cc5` (`main`; CR-038 perbaikan temuan QAUI-003 F-UI-1/2/3 dan QAFUNC-003-R1 F-R1-1; juga memuat CR-037/CR-027 yang tidak menyentuh UI) |
| **Lingkungan** | Sama dengan QAUI-003: PHP 8.4.2, MySQL 8.0.30 Laragon, API `php -S` + Vite dev server di worktree QA, DB scratch baru hasil `migrate --all` + `MasterDataSeeder`, captcha `mock`, akun Super Admin dari seeder test, Chrome 154 headless (CDP) di 1440×900, 600×900, 390×844 |
| **Acuan** | Kriteria QAUI-003 (QAUI-002 dengan tafsir keputusan user 29-09-2026), `AGENTS.md` §1 |
| **Hasil** | **PASS.** F-UI-1, F-UI-2, F-UI-3, dan F-R1-1 tertutup; tidak ada regresi pada 19 master di tiga viewport. Semua butir kriteria #1–#10b PASS untuk keempat kartu. Catatan INFO lama masih ada (tidak memblokir), ditambah satu INFO baru. |

## Cara uji

Skrip dan kriteria sama dengan QAUI-003 (19 master × 3 viewport: daftar, menu ⋮, form tambah, form error; satu master per kartu untuk Tidak Aktif/Dihapus/ConfirmDialog/Edit/422 duplikat). Tambahan untuk CR-038, per master dan per viewport:

- Daftar master kiri: rentang teks tiap item dibandingkan dengan kotak itemnya (Range API).
- Kolom Aksi: `position: sticky`, header "Aksi" dan tombol ⋮ berada di dalam area tabel yang terlihat, bayangan pemisah hanya saat tabel bisa digeser (dan hilang setelah digeser penuh ke kanan).
- Menu ⋮ dibuka dengan klik mouse sungguhan (CDP `Input.dispatchMouseEvent`) di koordinat tombol pada sel sticky: elemen teratas di titik itu adalah tombol, menu tampil penuh di viewport, dan setiap item menu adalah elemen teratas di posisinya (tidak tertutup sel sticky/overflow).
- Hover baris: warna latar baris dan sel sticky.

Tangkapan layar: 221 file di folder screenshot lokal QA `screenshots-r1` (tidak dikomit), pola nama sama dengan QAUI-003 (`<kartu>-<master>-<d1440|m600|m390>-<n>-<keadaan>.png`), ditambah `*-m600|m390-2-menu-sticky.png` (menu ⋮ dari sel sticky) dan `039-G07-kelurahan-m600|m390-1b-daftar-digeser-penuh.png`.

## Verifikasi temuan sebelumnya

| Temuan | Hasil | Bukti |
|---|---|---|
| F-UI-1 nama master terpotong di daftar kiri (1440px) | **Tertutup** | 0 item terpotong di 1440px; "Instansi Penyelenggara Kursus/Seminar" dan "Jenis Konfirmasi Ketidakhadiran" turun baris. `038-G06-jenis-konket-d1440-0-halaman-penuh-nav.png`. Di 600/390px daftar tetap strip geser horizontal (INFO-5 lama, by design), tanpa item yang terpotong di dalam kotaknya |
| F-UI-2 kolom Aksi tidak terlihat di 390–600px | **Tertutup** | 19/19 master × 3 viewport: header "Aksi" dan tombol ⋮ selalu terlihat, sel `sticky`. Bayangan tampil tepat saat tabel bisa digeser (57/57 cocok; di 1440px tidak ada yang bisa digeser, di 600px hanya kelurahan) dan hilang setelah digeser penuh ke kanan. Kolom yang tergulir masuk ke bawah sel sticky berlatar putih, tidak tumpang tindih. `037-G05-jurusan-pendidikan-m390-1-daftar.png`, `039-G07-kelurahan-m600-1-daftar.png`, `039-G07-kelurahan-m390-1b-daftar-digeser-penuh.png` |
| F-UI-3 pesan induk generik | **Tertutup** | Form: "Bidang Pendidikan wajib dipilih." (jurusan), "Tingkat Hukuman Disiplin wajib dipilih." (jenis hukdis), "Provinsi / Kabupaten/Kota / Kecamatan wajib dipilih." (kabupaten/kota, kecamatan, kelurahan). Backend juga: `POST /master/jurusan-pendidikan` tanpa induk → 422 `errors.id_bidang_pendidikan` "Bidang Pendidikan wajib dipilih.". `*-4-form-error.png` |
| F-R1-1 pesan validasi bahasa Inggris | **Tertutup** | `POST /auth/users` `name: 123` → 422 `errors.name` "Isian harus berupa teks."; `POST /auth/change-password` `old_password` array → 422 "Isian harus berupa teks."; `old_password: 123` → 422 pesan sama; kontrol `username` array → 422 pesan sama |

## Regresi CR-038

| Cek | Hasil |
|---|---|
| Menu ⋮ bisa dibuka dari sel sticky | PASS — 57/57 (19 master × 3 viewport): klik mendarat pada tombol, menu terbuka. `*-m390-2-menu-sticky.png`, `*-m600-2-menu-sticky.png` |
| Dropdown menu ⋮ tidak tertutup/terpotong sel sticky atau overflow tabel | PASS — 57/57: menu utuh di viewport dan setiap item adalah elemen teratas di posisinya (menu di-portal ke `body`) |
| Hover baris | PASS — baris dan sel sticky sama-sama `rgb(248,250,252)` saat hover; tanpa hover sel sticky putih |
| Urutan/isi menu, badge, ConfirmDialog, form Edit, error 422 | PASS — sama dengan QAUI-003 (`*-5-*` … `*-9-*`) |
| Dark/light mode | N/A — aplikasi belum punya mode gelap (tidak ada `darkMode`/kelas `dark:` di frontend) |
| Error konsol browser | Tidak ada |

## Checklist kriteria

| # | Kriteria | 036 G-04 | 037 G-05 | 038 G-06 | 039 G-07 | Bukti |
|---|---|---|---|---|---|---|
| 1 | Form dialog memakai komponen redesign | PASS | PASS | PASS | PASS | `*-d1440-3-form-tambah.png` |
| 2 | Label di atas field | PASS | PASS | PASS | PASS | 19/19 (checkbox jenjang jurusan berlabel di samping kotak, pola checkbox) |
| 3 | 2 field per baris di layar lebar, 1 kolom di layar sempit | PASS | PASS | PASS | PASS | grid 2 kolom di 1440px, 1 kolom di 600/390px (19/19) |
| 4 | Error merah di bawah field | PASS | PASS | PASS | PASS | `rgb(234,84,85)`, di bawah input, `aria-invalid` (19/19 × 3 viewport). `*-4-form-error.png`, `*-9-form-error-duplikat.png` |
| 5 | Tombol Batal/Simpan gaya redesign | PASS | PASS | PASS | PASS | Batal soft secondary, Simpan primary `#217AFF`, radius 8px |
| 6 | Aksi baris lewat menu ⋮ dengan urutan baku | PASS | PASS | PASS | PASS | Satu tombol per baris, aria-label "Aksi untuk <nama>", testid `master-actions-<id>`; Edit → Nonaktifkan/Aktifkan → Naikkan/Turunkan urutan → Pulihkan → Hapus merah. `*-2-menu.png`, `*-5-*`, `*-7-*` |
| 7 | Kolom Status hanya badge | PASS | PASS | PASS | PASS | Aktif hijau, Tidak Aktif abu, Dihapus merah |
| 8 | ConfirmDialog untuk Hapus | PASS | PASS | PASS | PASS | `*-6-konfirmasi-hapus.png` |
| 9 | Teks Bahasa Indonesia konsisten | PASS | PASS | PASS | PASS | F-UI-3 tertutup |
| 10a | Tidak ada elemen terpotong/tumpang tindih di 1440px | PASS | PASS | PASS | PASS | F-UI-1 tertutup; tabel/form/dialog tanpa potongan |
| 10b | Tidak ada elemen terpotong/tumpang tindih di ±390–600px | PASS | PASS | PASS | PASS | F-UI-2 tertutup; form satu kolom tanpa scroll horizontal halaman |

## Status per QASMTASK

| QASMTASK | Grup | Hasil QA Lapis 1 |
|---|---|---|
| 036 | G-04 Kenaikan Pangkat | **PASS** |
| 037 | G-05 Pendidikan | **PASS** |
| 038 | G-06 Diklat, Hukdis, Konket, Tanda Jasa | **PASS** |
| 039 | G-07 Data Umum & Wilayah | **PASS** |

## Temuan

| ID | Tingkat | Temuan | Langkah reproduksi | Status |
|---|---|---|---|---|
| INFO-6 (baru) | — | Header kolom Aksi diberi `data-testid="master-actions-header"`, yang berawalan sama dengan tombol baris `master-actions-<id>`. Selektor prefiks `[data-testid^="master-actions-"]` tanpa pembatas `tbody` kini mengenai header lebih dulu (skrip QA perlu disesuaikan). Test Vitest repo memakai id lengkap sehingga tidak terdampak | `document.querySelector('[data-testid^="master-actions-"]')` di halaman master → mengembalikan `<th>` | Usulan opsional: ganti nama menjadi mis. `master-col-actions` |
| INFO-1 | — | ConfirmDialog masih bergaya lama (tombol radius 6px, Batal outline transparan) | `*-6-konfirmasi-hapus.png` | Masih ada |
| INFO-2 | — | Error 422 backend tampil dua kali (banner + field) | `039-G07-agama-d1440-9-form-error-duplikat.png` | Masih ada |
| INFO-3 | — | Tombol halaman "Tambah …" navy lama vs Simpan biru redesign | `*-d1440-3-form-tambah.png` | Masih ada |
| INFO-4 | — | Baris Dihapus masih menampilkan Edit di samping Pulihkan | `*-7-menu-dihapus.png` | Masih ada |
| INFO-5 | — | Di layar sempit daftar master kiri berupa strip geser horizontal (by design) | `*-m390-1-daftar.png` | Masih ada |

## Bersih-bersih

Server QA (API, Vite, Chrome headless) dihentikan; DB scratch QA di-drop dengan nama persis; worktree QA tidak mengubah kode aplikasi. Data uji hanya di DB scratch.
