# QAFUNC-003 (G-10): QA FAQ — QASMTASK-042, MTC-013, MTC-014

| | |
|---|---|
| **Jenis** | QA Functional (black-box API + UI) |
| **Tanggal eksekusi** | 28 September 2026 |
| **Commit yang diuji** | `db547d0` (`main`; G-10 FAQ masuk lewat PR #10, DBV-002/CR-003) |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 Laragon, `php spark serve` (API 8110 untuk QA API, 8089 + Vite 5173 untuk QA UI), DB scratch per area QA hasil `migrate` + `MasterDataSeeder`, captcha `mock`; akun dari seeder test (password seed lokal, lihat README seed) |
| **Rujukan** | Kartu QASMTASK-042 (DoD), Test Case Manual MTC-013 & MTC-014, `backend/docs/db-review/G-10-faq-schema.md`, README MasterData/FAQ |
| **Hasil** | **Pass.** QA API 108 PASS / 0 FAIL / 3 INFO, E2E CR-003 84/84, QA UI MTC-013 & MTC-014 PASS. Tidak ada FAIL. |

## Ringkasan per DoD

| DoD | Hasil | Bukti utama |
|---|---|---|
| CRUD topic/sub-topic/article oleh role 1 | PASS | create/update/nonaktif (2)/hapus (10)/pulihkan untuk ketiga entitas; urutan sisip & PATCH order; UNIQUE nama → 422 (beda huruf besar/kecil, spasi, per induk); induk wajib aktif sampai leluhur; isi HTML disanitasi (script, `on*`, `javascript:`, `data:`, iframe, svg, style, form dibuang; `<b>`, img https, `rel="noreferrer noopener"` dipertahankan) |
| View FAQ terbuka untuk UL_ALL, tidak bisa CRUD | PASS | ke-8 role: tree/search/detail 200; role 2–8: 175 request CRUD/admin semuanya 403 dan checksum tabel FAQ tidak berubah; tanpa token/token palsu → 401; pencarian publik meng-escape `%`, `_`, `!` |
| Rating pegawai tersimpan di `faq_rate` | PASS | 201 `{rated:true}`, baris `faq_rate` (artikel, NIP, rate, reason, created_at UTC, created_by) + audit `create faq_rate`; satu rating per pegawai per artikel (rating ulang → 422 "Artikel ini sudah Anda nilai."); nilai tidak valid → 422; artikel/sub-topik/topik nonaktif → 404 tanpa baris |
| Perubahan admin langsung terlihat pegawai | PASS | create/edit/rename/nonaktif/hapus/pulihkan langsung tercermin di tree, detail, dan search pegawai; induk dinonaktifkan → sub-topik & artikel di bawahnya hilang, muncul lagi setelah dipulihkan; 10 siklus toggle cepat tanpa respons basi; header `Cache-Control: no-store` |
| Lolos MTC-013 dan MTC-014 | PASS | lihat bagian UI di bawah |

## QA UI (browser, desktop pane)

| # | Langkah | Hasil |
|---|---|---|
| MTC-013-1 | Login Pegawai (role 2) → menu | Menu FAQ tampil; menu Master Data tidak ada; buka `/master/faq-topic` langsung → halaman 403 "Akses ditolak" |
| MTC-013-2 | Cari "syarat" | Hasil pencarian menampilkan artikel "Syarat kata sandi baru" beserta jejak topik › sub-topik |
| MTC-013-3 | Buka artikel → klik "Membantu" | Tampil "Terima kasih atas penilaian Anda."; baris `faq_rate` tersimpan (artikel 2, NIP pegawai, rate 1) |
| MTC-014-1 | Login Super Admin → Tambah Topik, Sub Topik, Artikel | Ketiganya tersimpan; dropdown berjenjang topik → sub-topik langsung memuat topik baru; `<script>` dan `onerror` di isi artikel dibuang, `<b>` tetap |
| MTC-014-2 | Edit judul artikel | Tersimpan; pegawai langsung melihat topik baru → sub-topik → artikel dengan judul baru dan isi aman (tanpa script/img) |
| MTC-014-3 | Nonaktifkan sub-topik | Pesan "… dinonaktifkan dan tidak lagi muncul di dropdown."; pegawai melihat "Belum ada sub topik." dan tautan langsung ke artikel → "Artikel FAQ tidak ditemukan." |

## E2E CR-003

84/84 PASS terhadap server QA (logika cek tidak diubah; adaptasi lingkungan: DB tidak di-drop ulang, origin preflight CORS 5173).

## Temuan (INFO, bukan FAIL)

1. **Pencarian daftar admin tidak meng-escape wildcard LIKE** (`GET /api/v1/master/faq-article?search=%` → semua baris). Berlaku di semua master (engine generik `MasterService` list), pencarian publik FAQ sudah benar. Tidak ada kebocoran data; hanya hasil terlalu luas untuk admin. Dicatat ke ISSUE-019.
2. **Kalimat DoD "rating" vs perilaku:** rating hanya sekali per pegawai per artikel dan tidak bisa diubah (ikut legacy, README, PK `(id_faq_article, nip)`). Perlu konfirmasi PO bila DoD dimaksudkan rating bisa diubah.
3. **Data seed FAQ** "Syarat kata sandi baru" masih berisi aturan lama (min 8 + angka), belum aturan K4 (huruf besar, huruf kecil, angka). Hanya data contoh test, bukan kode.
4. `rate` berupa string `"1"` diterima (konversi tipe longgar, nilainya sah); `href="java&#x09;script:…"` disanitasi menjadi URL relatif yang tidak bisa dieksekusi (aman).

## Bersih-bersih

Server QA dihentikan; DB scratch QA dihapus setelah QA selesai; worktree QA tidak mengubah kode yang diuji.
