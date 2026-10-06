# QAFUNC-003-R1: QA ulang issue Fase 2 — ISSUE-019, ISSUE-020, ISSUE-021, ISSUE-023, CR-023

| | |
|---|---|
| **Jenis** | QA Functional ulang (black-box API + DB + UI), dijalankan reviewer CR atas persetujuan user |
| **Tanggal eksekusi** | 2 Oktober 2026 |
| **Commit yang diuji** | `eb86d09` (`main`; CR-016 PR #11 `ff74bf9` + `c35cbda`, CR-018 `09ed1e1`, CR-019 `e45456c`, CR-020 `f5504c4`, CR-023 `d70a063` + `6eb7d06`, CR-024 `dea43db`) |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 Laragon, DB scratch hasil `migrate --all` + `MasterDataSeeder`, akun dari seeder test (role 1, 2, 3). API development `php spark serve` (captcha `mock`); 8 instance `spark serve` tambahan ke DB yang sama untuk uji paralel; 3 instance `CI_ENVIRONMENT=production` (override env lewat `auto_prepend_file` di folder scratch, kode aplikasi tidak diubah) + tiruan endpoint siteverify Turnstile lokal; Vite + Chrome headless untuk UI |
| **Rujukan** | Temuan F-LIST/F-OPT QAFUNC-003 (ISSUE-019), ISSUE-020, ISSUE-021, ISSUE-023 (QAFUNC-002-R3), ISSUE-022/CR-023, README `Controllers/Api/MasterData` dan `Controllers/Api/Auth` |
| **Hasil** | **PASS.** Kelima butir lolos. Satu temuan minor baru (F-R1-1, pesan validasi bawaan berbahasa Inggris untuk isian angka/array di beberapa field) dan dua catatan INFO, tidak memblokir. |

## Ringkasan

| Issue | Kriteria selesai | Hasil | Bukti utama |
|---|---|---|---|
| ISSUE-019 (CR-016, CR-024) | Parameter daftar berbentuk array atau `page` tidak sah/sangat besar → 422 (bukan 500) di daftar admin users, 25 master, hari libur, dan master FAQ admin | PASS | 454/454 request sesuai harapan, 0 respons 500, log CI4 bersih |
| ISSUE-019 | Pencarian `%`/`_` (dan karakter escape `!`) diperlakukan literal | PASS | 20/20 + 3/3 cek di master, hari libur, dan users |
| ISSUE-020 (CR-020) | Tambah entri master paralel di lingkup urutan yang sama → `order` tidak kembar, tetap rapat 1..n | PASS | 19 ronde × 8 request paralel (152 POST, 8 proses server berbeda): semua 201, 0 kembar, 0 lubang |
| ISSUE-021 (CR-018) | Captcha `mock` dan `exposeResetTokenInResponse=true` ditolak saat production | PASS | login (mock) → 500 ConfigException; forgot-password (expose) → 500 ConfigException untuk username terdaftar maupun tidak, tanpa token di response dan tanpa baris `forgot_attempts` |
| ISSUE-023 (CR-019) | Isian array/objek di body CRUD akun → 422 | PASS | 53/53 kasus (POST/PUT/PATCH status, role 1 dan 3, login, ganti password) → 422; jumlah akun tidak berubah |
| ISSUE-023 (CR-019) | AccountProvisioner: NIP = username akun lain → 422 | PASS | akun aktif dan akun terhapus → `ValidationException` 422 `errors.nip`; akun lain tidak diubah; kontrol NIP baru dan NIP sudah berakun tetap jalan |
| CR-023 (ISSUE-022) | "Login terakhir" tampil dalam WIB yang benar (stempel API UTC) | PASS | API `11:58:21` (UTC) → UI "2 Okt 2026, 18.58" di zona Asia/Jakarta |

## ISSUE-019 — parameter daftar & LIKE literal

Matriks (`curl -g`, role 1 kecuali disebut):

- **25 master** (`/master/{slug}`) × `search[]=a`, `search[a]=1`, `status[]=1`, `parent[]=1`, `page[]=1`, `per_page[]=10`, `page=99999999999999999999`, `page=1000001`, `page=-1`, `page=1.5`, `page=abc`, `page=1e3` → **422**; kontrol `page=1`, `page=1000000` (batas `PAGE_MAX`), `search=a` → 200.
- **`/auth/users`** (role 1 dan role 3) dan **`/hari-libur`**: set parameter yang sama → 422; tambahan `user_level[]`, `id_satker[]`, `sort[]`, `order[]`, `status[x]` (users, role 1 dan 3), `tahun[]`, `tahun[a]` (hari libur) → 422.
- **Options berjenjang** (`/master/{slug}/options`, 7 master berinduk) × `parent[]=1`, `parent[a]=1`, role 1 dan role 2 → 422 "Filter <Induk> tidak valid." (CR-024). Untuk `faq-sub-topic`/`faq-article` role 2 mendapat 403 karena options FAQ khusus role 1 (`publicOptions: false`), sesuai desain.
- Hasil: **454/454** sesuai harapan; tidak ada respons 500, dan tidak ada CRITICAL/ERROR di log CI4 selama matriks.

Contoh respons:

```
GET /master/pangkat?search[]=a           → 422 {"message":"Parameter daftar tidak valid.","errors":{"search":["Parameter ini hanya boleh berisi satu nilai teks atau angka."]}}
GET /master/agama?page=99999999999999999999 → 422 {"message":"Halaman harus berupa angka bulat 1 sampai 1000000.", ...}
GET /auth/users?status[]=1               → 422 {"errors":{"status":["Parameter ini hanya boleh berisi satu nilai teks atau angka."]}}
GET /hari-libur?page=1.5                 → 422 {"errors":{"page":["Halaman harus berupa angka bulat 1 sampai 1000000."]}}
GET /master/kecamatan/options?parent[]=1 → 422 {"errors":{"parent":["Filter Kabupaten/Kota tidak valid."]}}
```

LIKE literal: dibuat entri `QA_LIT`, `QAXLIT`, `QA%PCT`, `QAZPCT` di agama, tanda jasa, topik FAQ (master FAQ admin), hari libur, dan username akun (`qa_lit` dst.). Untuk kelimanya `search=QA_LIT` → hanya `QA_LIT`; `search=QA%PCT` → hanya `QA%PCT`; `search=_` → hanya `QA_LIT`; `search=%` → hanya `QA%PCT` (**20/20**). Karakter escape: `search=!%` → hanya `QA!%X`, `search=!` → `QA!%X`, `QA!X` (**3/3**).

## ISSUE-020 — urutan saat tambah paralel

8 instance `spark serve` tambahan (proses PHP terpisah, DB yang sama). Tiap ronde 8 thread menunggu barrier lalu mengirim POST bersamaan, masing-masing ke instance yang berbeda:

| Skenario | Ronde × request | Status | Pemeriksaan DB setelahnya |
|---|---|---|---|
| agama, tambah di akhir (tanpa `order`) | 5 × 8 | 40 × 201 | — |
| agama, sisip di posisi 1 (`order: 1`, menggeser) | 5 × 8 | 40 × 201 | agama: 90 entri tampil, `order` 1..90, 0 kembar |
| diklat lingkup `jenis_diklat=2`, tambah di akhir | 3 × 8 | 24 × 201 | — |
| diklat lingkup `jenis_diklat=2`, sisip `order: 2` | 3 × 8 | 24 × 201 | diklat per `jenis_diklat`: jenis 2 = 49 entri, `order` 1..49; lingkup lain utuh; 0 kembar |
| jenis hukdis induk tingkat 1, tambah di akhir | 3 × 8 | 24 × 201 | induk 1 = 27 entri, `order` 1..27; 0 kembar |

Kueri: `GROUP BY <lingkup>, order HAVING COUNT(*) > 1` → kosong; `COUNT(*) = MAX(order)` dan `MIN(order) = 1` per lingkup. Tidak ada 409 (lock didapat dalam batas tunggu 10 detik). Entri balapan agama kemudian dihapus langsung dari DB scratch agar tangkapan layar QAUI-003 tidak tercampur.

## ISSUE-021 — guard production

Instance production memakai `.env` worktree QA. Nilai yang diubah hanya lewat env proses: `CI_ENVIRONMENT=production`, lalu `auth.captchaDriver=turnstile` + `auth.turnstileVerifyUrl` ke tiruan siteverify lokal yang selalu sukses, dan `auth.exposeResetTokenInResponse` (`true`/`false`).

| Instance | Request | Hasil | Log |
|---|---|---|---|
| production, captcha `mock` | `POST /auth/login` (kredensial benar) | **500** "Terjadi kesalahan pada server.", tanpa cookie | `ConfigException: auth.captchaDriver = mock tidak boleh dipakai di production` |
| production, captcha `mock` | `GET /_rbac/any` | 404 (route uji tidak terdaftar di production) | — |
| production, turnstile (tiruan), expose `true` | `POST /auth/forgot-password` username terdaftar / tidak terdaftar | **500** keduanya (respons identik, tanpa token) | `ConfigException: auth.exposeResetTokenInResponse = true tidak boleh dipakai di production` (2×) |
| production, turnstile (tiruan), expose `true` | `POST /auth/login` | 200 (guard hanya mengenai forgot-password) | — |
| production, turnstile (tiruan), expose `false` | `POST /auth/forgot-password` | 500: guard notifier `log` (CR-008), lihat INFO-R1-2 | `ConfigException: auth.resetTokenNotifier = log …` |
| development, `mock` (kontrol) | login / forgot-password | 200 / 200 generik | — |

`forgot_attempts` setelah uji hanya berisi 2 baris dari instance development (kontrol). Request ke instance production tidak menulis baris apa pun, sehingga username terdaftar tidak bisa dibedakan.

## ISSUE-023 — isian non-teks di CRUD akun & AccountProvisioner

Isian array/objek/non-teks (53 kasus, semua **422** `errors.<field>`; pesan "Isian harus berupa teks." kecuali dua kasus F-R1-1):

- `POST /auth/users` role 1 × 9 field (`nip`, `name`, `email`, `username`, `password`, `user_level`, `id_unit`, `id_satker`, `status`) × {array, objek}, dan role 3 × 9 field × array; tambahan `username=true` dan `name=123` (angka). Jumlah baris `pengguna` sebelum = sesudah (18 = 18).
- `PUT /auth/users/2` role 1 dan role 3 × 9 field × array; `PATCH /auth/users/2/status` dengan `status` array/objek.
- `POST /auth/login` dengan `username`/`password`/`captcha_token` array; `POST /auth/change-password` `old_password` array.
- Kontrol: `PUT` nama valid → 200.

AccountProvisioner, dijalankan lewat bootstrap CI4 (`Boot::bootConsole`, skrip di folder scratch) ke DB scratch:

| Kasus | Hasil |
|---|---|
| NIP = username akun non-pegawai aktif lain | **422** `errors.nip` "NIP ini sudah dipakai sebagai username akun lain; ganti username akun tersebut sebelum membuat akun pegawai." Akun pemilik username tidak berubah (`nip` tetap NULL) |
| NIP = username akun yang sudah dihapus | **422**, pesan sama |
| NIP baru (kontrol) | akun dibuat, `created=true`, username = NIP |
| NIP yang sudah punya akun (kontrol) | idempoten, `created=false`, akun lama dikembalikan |

## CR-023 — "Login terakhir" di Manajemen Akun

Super Admin login, lalu `/akun` dibuka di Chrome dengan zona yang dipaksa (CDP `Emulation.setTimezoneOverride`):

| Zona browser | `last_login_at` API (UTC, tanpa zona) | Kolom "Login terakhir" | Tangkapan layar |
|---|---|---|---|
| Asia/Jakarta | `2026-10-02 11:58:21` / `11:38:21` / `11:38:49` | "2 Okt 2026, 18.58" / "18.38" / "18.38" | `CR-023-akun-login-terakhir-Asia-Jakarta.png` |
| America/New_York (kontrol) | `2026-10-02 11:58:28` / … | "2 Okt 2026, 07.58" / "07.38" / "07.38" | `CR-023-akun-login-terakhir-America-New_York.png` |

Stempel API dibaca sebagai UTC lalu ditampilkan di zona browser. Untuk pengguna WIB hasilnya jam login sebenarnya (+7 jam dari UTC); kontrol New York (−4) membuktikan nilainya tidak lagi dibaca sebagai jam lokal.

## Temuan

| ID | Tingkat | Temuan | Langkah reproduksi | Tindak lanjut |
|---|---|---|---|---|
| F-R1-1 | Minor | Beberapa jalur validasi masih memakai pesan bawaan CI4 berbahasa Inggris: `POST /auth/users` dengan `name: 123` (angka) → 422 "The name field must be a valid string."; `POST /auth/change-password` dengan `old_password` array → 422 "The old_password field must be a valid string." Status 422 sudah benar; yang tidak konsisten hanya bahasanya (jalur lain "Isian harus berupa teks.") | Role 1: `POST /api/v1/auth/users` `{"username":"qa_num","name":123,"password":"<sah>","user_level":4,...}`; role 2: `POST /api/v1/auth/change-password` `{"old_password":["x"],...}` | Usulan: pesan kustom untuk aturan `string` di `UserController` dan `PasswordController` |
| INFO-R1-1 | — | Tampilan "Login terakhir" mengikuti zona browser, tanpa label "WIB". Pengguna di zona lain melihat jam lokalnya. Ini sesuai desain CR-023 | — | — |
| INFO-R1-2 | — | Instance production dengan `.env` development (`auth.resetTokenNotifier = log`) juga menolak forgot-password karena guard CR-008, walau `exposeResetTokenInResponse=false`. Ini perilaku yang benar (fail-closed); production wajib `email` | — | — |
| SKIP | — | Balapan HTTP pada satu proses `php -S` (Windows single-thread) tidak relevan; balapan diuji dengan 8 proses server terpisah | — | — |

## Bersih-bersih

Semua server QA (API development, 8 instance paralel, 3 instance production, tiruan siteverify, Vite, Chrome headless) dihentikan; DB scratch QA di-drop dengan nama persis; worktree QA tidak mengubah kode aplikasi. Kredensial uji hanya dicatat di folder scratch lokal, tidak di laporan.
