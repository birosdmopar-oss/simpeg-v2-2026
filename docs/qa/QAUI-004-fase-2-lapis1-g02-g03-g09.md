# QAUI-004 (G-02, G-03, G-09): QA Lapis 1 Master Data Gelombang 3 — QASMTASK-034, 035, 041

| | |
|---|---|
| **Jenis** | QA UI/UX (Lapis 1, visual = G-TC #7), versi singkat, dijalankan atas persetujuan reviewer CR (penutupan Fase 2) |
| **Tanggal eksekusi** | 7 Oktober 2026 |
| **Commit yang diuji** | `e99bd7b` (`main`, sama dengan QAFUNC-004). Saat laporan dikomit `main` maju ke `51d3543` (CR-043, hanya `AGENTS.md`, tanpa perubahan kode aplikasi), sehingga hasil tetap berlaku |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 Laragon, `php spark serve` + Vite dev server di worktree QA (`VITE_API_BASE_URL` + origin di `cors.allowedOrigins` `.env` scratch, tanpa ubah kode), DB scratch baru hasil `migrate --all` + `MasterDataSeeder`, captcha `mock`, akun Super Admin dari seeder test, Chrome 154 headless (CDP) di 1440×900, 600×844, 390×844 |
| **Acuan** | Kriteria QAUI-003-R1 (QAUI-002 dengan tafsir keputusan ISSUE-007: komponen & token redesign, label di atas field, grid 2 kolom di 1440 / 1 kolom di 600 dan 390, error merah di bawah field, Batal/Simpan redesign, menu ⋮ urutan `AGENTS.md` §1 + aria-label/testid, badge status, ConfirmDialog, kolom Aksi sticky, tidak terpotong/tumpang tindih) |
| **Hasil** | **034 PASS, 035 PASS, 041 FAIL** (satu temuan Minor: kolom Aksi halaman Web Config tidak sticky di 600/390 px, tombol ⋮ di luar area terlihat). Catatan INFO lama (INFO-1..5 QAUI-003) masih ada dan tidak dihitung FAIL; INFO-6 sudah tertutup. |

## Cara uji

Skrip CDP (pola sama dengan QAUI-003-R1), per viewport:

- **12 master** (G-02: `unit`, `satker`, `group-jabatan`, `sub-group-jabatan`, `kelas-jabatan`, `jabatan`, `rumpun-jabatan`, `subrumpun-jabatan`, `jabatan-akademik`, `periode-struktur-jabatan`; G-03: `lokasi-presensi`, `aturan-lokasi-presensi`): daftar (kolom, jumlah kontrol per baris, aria-label/testid ⋮, badge), kolom Aksi (`position: sticky`, header & tombol terlihat, klik mouse sungguhan pada tombol mendarat di tombol, bayangan hanya saat tabel bisa digeser), menu ⋮ (isi, urutan, item di dalam viewport dan teratas di posisinya), form tambah (grid, label di atas field, tombol), form kosong disubmit (error merah di bawah field, `aria-invalid`), pemeriksaan elemen terpotong/di luar layar.
- **Keadaan** untuk satu master per kartu (`unit`, `lokasi-presensi`): baris Tidak Aktif, ConfirmDialog Hapus, filter Dihapus (Pulihkan), form Edit, 422 nama duplikat; `satker` dengan filter Unit Kerja (item urutan per induk).
- **Web Config**: daftar per kelompok, kolom Aksi, dialog Edit untuk tipe text, decimal, time, email_list, html, id_ref, textarea; nilai salah per tipe (decimal `1,5`, time `24:00`, email `bukan-email`, id_ref `01`) → error dari server; ConfirmDialog Hapus.

Tangkapan layar: 219 file di folder screenshot lokal QA `screenshots-lapis1` (tidak dikomit), pola `<kartu>-<master>-<d1440|m600|m390>-<n>-<keadaan>.png` (`1-daftar`, `2-menu`, `2b-menu-per-unit`, `3-form-tambah`, `4-form-error`, `5-menu-tidak-aktif`, `6-konfirmasi-hapus`, `7-menu-dihapus`, `8-form-edit`, `9-form-error-duplikat`; Web Config `041-G09-web-config-<vp>-1-daftar`, `2-edit-<tipe>`, `3-error-<tipe>`, `4-konfirmasi-hapus`). Konsol browser tanpa error di ketiga viewport.

## Checklist kriteria

| # | Kriteria | 034 G-02 | 035 G-03 | 041 G-09 | Bukti |
|---|---|---|---|---|---|
| 1 | Form dialog memakai komponen redesign | PASS | PASS | PASS | `*-d1440-3-form-tambah.png`, `041-G09-web-config-d1440-2-edit-*.png` |
| 2 | Label di atas field | PASS | PASS | PASS | 12/12 master × 3 viewport + 7 dialog Web Config; checkbox (UPT, daftar centang aturan) berlabel di samping kotak (pola checkbox), judul grup di atas kotak |
| 3 | 2 kolom di 1440, 1 kolom di 600/390 | PASS | PASS | PASS | grid 2 kolom di 1440 (12/12), 1 kolom di 600/390 (12/12); daftar centang aturan lokasi dan textarea membentang penuh; dialog Web Config 2 kolom di 1440 (dua field penuh lebar), 1 kolom di 600/390 |
| 4 | Error merah di bawah field | PASS | PASS | PASS | `rgb(234,84,85)`, di bawah input/grup, `aria-invalid` (12/12 × 3 viewport; Web Config 4 tipe × 3 viewport). Pesan khusus per field, mis. "Unit Kerja wajib dipilih.", "Zonasi Presensi (menit) wajib diisi.", "Butuh Satuan Kerja wajib dipilih.", "Radius (meter) wajib diisi.", "Nilai harus jam dengan format HH:MM atau HH:MM:SS…" |
| 5 | Tombol Batal/Simpan gaya redesign | PASS | PASS | PASS | Batal `rgb(241,245,249)` / teks slate, Simpan `#217AFF`, radius 8px |
| 6 | Aksi baris lewat menu ⋮ dengan urutan baku | PASS | PASS | PASS | Satu tombol per baris; aria-label "Aksi untuk <nama>"; testid `master-actions-<id>` / `web-config-actions-<key>`. Unit: Edit → Nonaktifkan → Naikkan urutan (disabled di baris 1) → Turunkan urutan → Hapus merah; Tidak Aktif: Edit → Aktifkan → …; Dihapus: Edit → Pulihkan; master tanpa urutan (kelas jabatan, jabatan, akademik, periode, lokasi, aturan): Edit → Nonaktifkan/Aktifkan → Hapus; satker/sub group/subrumpun: item urutan baru muncul setelah induk dipilih (hint "Pilih Unit Kerja untuk mengubah urutan (urutan berlaku per induk)."), pola sama dengan G-07; Web Config: key bawaan hanya Edit, key bernilai Edit → Hapus merah |
| 7 | Kolom Status hanya badge | PASS | PASS | N/A | Aktif hijau `rgb(220,252,231)`, Tidak Aktif abu `rgb(226,232,240)`, Dihapus merah `rgb(254,226,226)`. Web Config tanpa status (badge "Bawaan"/"Tidak valid — memakai bawaan" di kolom Keterangan) |
| 8 | ConfirmDialog untuk Hapus | PASS | PASS | PASS | `*-6-konfirmasi-hapus.png` (unit menyebut status satker di bawahnya), `041-G09-web-config-*-4-konfirmasi-hapus.png` "Hapus nilai …? … kembali memakai nilai bawaan"; gaya lama = INFO-1 |
| 9 | Teks Bahasa Indonesia konsisten | PASS | PASS | PASS | lihat INFO-7 (baru) |
| 10a | Tidak terpotong/tumpang tindih di 1440 | PASS | PASS | PASS | tanpa scroll horizontal halaman, tanpa teks terpotong di tabel/form/dialog; kolom Aksi Web Config terlihat |
| 10b | Tidak terpotong/tumpang tindih di 600/390 | PASS | PASS | **FAIL** | Master: kolom Aksi sticky, header & ⋮ terlihat, klik mendarat di tombol, menu utuh dan teratas (12/12 × 2 viewport), bayangan cocok dengan bisa-digeser (390: unit, satker, group, sub group, rumpun, subrumpun bisa digeser dan berbayang); daftar master kiri = strip geser (INFO-5). Web Config: lihat F-UI-4 |

## Status per QASMTASK

| QASMTASK | Grup | Hasil QA Lapis 1 |
|---|---|---|
| 034 | G-02 Jabatan, Unit & Satker | **PASS** |
| 035 | G-03 Lokasi Presensi | **PASS** |
| 041 | G-09 Web Config | **FAIL** (F-UI-4, Minor) |

## Temuan

| ID | Tingkat | Temuan | Langkah reproduksi | Status |
|---|---|---|---|---|
| F-UI-4 | Minor | Halaman Web Config di 600 dan 390 px: tabel bisa digeser horizontal, tetapi kolom Aksi tidak sticky (`position: static`) sehingga header "Aksi" dan tombol ⋮ berada di luar area terlihat; admin harus menggeser tabel untuk menemukan aksi Edit/Hapus. Halaman Master Data sudah memakai kolom Aksi sticky (perbaikan F-UI-2/CR-038) — pola itu belum diterapkan ke Web Config | Super Admin → Web Config di lebar 390 px: kolom terlihat hanya Nama dan sebagian Nilai, tanpa ⋮. `041-G09-web-config-m390-1-daftar.png`, `…-m600-1-daftar.png` | Baru — usulan CR: terapkan sel Aksi sticky + bayangan seperti tabel master |
| INFO-7 (baru) | — | Error grup centang aturan lokasi berbunyi "Target Lokasi wajib diisi.", "Target Unit/Satker wajib diisi.", "Jenis Pegawai wajib diisi.", sedangkan pilihan lain memakai "wajib dipilih" dan hint grup sendiri "Pilih minimal satu." | Master Data → Aturan Lokasi Presensi → Tambah → Simpan kosong. `035-G03-aturan-lokasi-presensi-*-4-form-error.png` | Usulan opsional: "… wajib dipilih minimal satu." |
| INFO-8 (baru) | — | Kolom Nilai Web Config menampilkan nilai tipe html sebagai markup mentah (`<b>KEMENTERIAN QA</b>`) | `041-G09-web-config-*-1-daftar.png` | Dicatat (bisa jadi disengaja agar admin melihat sumbernya) |
| INFO-9 (baru) | — | Daftar centang "Target Unit/Satker" di form aturan lokasi memakai area gulir sendiri (± 6 baris terlihat); pada data produksi (puluhan unit/satker) admin perlu menggulir di dalam kotak, tanpa pencarian | `035-G03-aturan-lokasi-presensi-*-3-form-tambah.png` | Dicatat untuk Fase berikut |
| INFO-6 | — | Header kolom Aksi kini `data-testid="master-col-actions"` | — | **Tertutup** |
| INFO-1 | — | ConfirmDialog masih bergaya lama (radius 6px, Batal transparan) — juga di Web Config | `*-6-konfirmasi-hapus.png`, `041-…-4-konfirmasi-hapus.png` | Masih ada |
| INFO-2 | — | Error 422 backend tampil dua kali (banner + field) | `034-G02-unit-*-9-form-error-duplikat.png`, `035-G03-lokasi-presensi-*-9-…` | Masih ada |
| INFO-3 | — | Tombol "Tambah …" navy lama vs Simpan biru redesign | `*-3-form-tambah.png` | Masih ada |
| INFO-4 | — | Baris Dihapus masih menampilkan Edit di samping Pulihkan | `*-7-menu-dihapus.png` | Masih ada |
| INFO-5 | — | Di layar sempit daftar master kiri berupa strip geser horizontal (by design) | `*-m390-1-daftar.png` | Masih ada |

Catatan uji: field Nilai tipe text adalah input satu baris, sehingga baris baru yang ditempel dibuang browser dan nilai tersimpan tanpa error — bukan temuan (validasi server "satu baris" tetap berlaku untuk klien API, lihat QAFUNC-004).

## Bersih-bersih

Server API, Vite, dan Chrome headless QA dihentikan; DB scratch QA di-drop dengan nama persis; worktree QA tidak mengubah kode aplikasi. Data uji hanya di DB scratch.
