# QA ulang R1 Fase 3 bagian F: verifikasi perbaikan CR-050 (kartu ISSUE-039), QASMTASK-043, 044, 047, 055, 061, 062

> Catatan: rujukan `scratchpad/…` di laporan ini menunjuk berkas kerja QA lokal (harness, log mentah, tangkapan layar) yang **tidak dikomit**. Seluruh data uji sintetis (NIP & nama fiktif); kredensial ditulis "password seed lokal".

| | |
|---|---|
| **Jenis** | QA Functional ulang (R1), black-box API + isi DB + PHPUnit, atas izin user (reviewer CR) |
| **Tanggal eksekusi** | 15 Oktober 2026 (hari bisnis WIB Kamis 15-10-2026, dipakai untuk aturan tanggal Konket/LKH/Struktur) |
| **Commit yang diuji** | `main` = `91264e3` (Merge CR-049) yang memuat `8fc27b5` Merge CR-050 (`d57481c` fix CR-050). Worktree detached `scratchpad\wt\qa3r1f` |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 instance test 127.0.0.1:3307. DB API `simpeg_v2_qa3r1f`, DB PHPUnit `simpeg_v2_qa3r1f_test` (keduanya `utf8mb4_unicode_ci`). `php spark migrate --all` + `Tests\Support\Database\Seeds\MasterDataSeeder` (memanggil `AuthSeeder`) dari commit yang diuji (seeder baru CR-050). `.env` baru dari `.env.example`: `CI_ENVIRONMENT = development`, `auth.captchaDriver = mock`, JWT/encryption key acak lokal, `cors.allowedOrigins` bawaan. `php spark serve --port 8125` (tanpa Vite; UI = bagian lain). Akun & pegawai uji sintetis (NIP sintetis `198001012005011…`, `199001012015011…`, `199201012020011…`, nama "… Fiktif"/"Pegawai QA …", password seed lokal) |
| **Harness** | `scratchpad\qa3r1f\`: `lib.php` + `seed.php` (dipakai ulang dari `qa3b`, port/DB diganti; PTT = id 2), `t_konket.php`, `t_konket_env.php` + `restart.sh` (override `.env`, server di-restart tiap skenario), `t_export.php`, `t_struktur_pdf.php`, `t_akun.php`. Keluaran mentah: `out-konket.txt`, `out-konket-env.txt`, `out-export.txt`, `out-struktur-pdf.txt`, `out-akun.txt`, berkas `ekspor.csv`, `ekspor-pencarian.csv`, `ekspor.xlsx`, PHPUnit `phpunit.log` + `junit.xml` |
| **Rujukan** | Laporan QA pertama `qa3-a.md` (A-F1..A-F3), `qa3-b.md` (B-F1, B-F2, B-F4), `qa3-c.md` (C-F1); diff `576ee8f..91264e3` bagian backend; README `Controllers/Api/Kepegawaian` (kontrak ekspor & Konket yang diperbarui CR-050), `backend/docs/progress/03-Kepegawaian.md`, legacy `L_konket.php`, `L_employee.php:158`, `L_presensi` (arti `affect_tukin`) |
| **Hasil** | **Ketujuh perbaikan CR-050 lolos** (A-F1, A-F2, A-F3, B-F1, B-F2, B-F4, C-F1). PHPUnit penuh di DB scratch: **OK 1807 test / 33.016 assertion / 0 gagal**. QASMTASK-043, 044, 047, 055, 061, 062 = **PASS** (055 dengan catatan). Temuan baru: 3 Info (R1F-F1..R1F-F3), tidak menggagalkan butir |

Catatan metodologi: FAIL di keluaran mentah yang **bukan** cacat aplikasi (harapan harness salah), sudah dicek ulang:
`out-export.txt` 2 baris (nama berawalan TAB sudah di-trim server jadi "Tab Ekspor Fiktif" → tidak ada sel berbahaya;
cek XLSX `="` cocok dengan atribut `xmlns="…"` di XML, isi sel sendiri benar, lihat A-F1), `out-konket-env.txt` S7
(harness salah berharap kategori 17 = id 6 boleh padahal id 6 justru yang dilarang skenario itu → 422 benar),
`out-akun.txt` 2 login (422 sekali di run pertama, tidak terulang di 6 percobaan berikutnya termasuk akun baru yang
langsung login → 200 dengan `user_level` 6/7; dicatat di bawah, tidak dijadikan temuan).

## Verifikasi per temuan R0

| Temuan R0 | Uji R1 | Hasil R1 | Bukti konkret |
|---|---|---|---|
| **A-F1** (Minor) ekspor CSV: NIP/NIP lama jadi angka di Excel | `GET pegawai/export?format=csv&kolom=nip_lama,jabatan&search=Ekspor` role 1 atas 14 pegawai sintetis (nilai normal, nol depan, dan nilai berbahaya di NIP/NIP lama/nama) + `POST pegawai/pencarian/export` + XLSX | **LOLOS** | 200 `text/csv`, BOM, header `Nama,NIP,NIP Lama,Jabatan`. Baris mentah: `"Ekspor Satu Fiktif","=""199001012015011201""","=""012345""",` → sel `="199001012015011201"` / `="012345"`; NIP berawalan nol `019001012015011202` → `="019001012015011202"`, NIP lama `000777` → `="000777"`. Nilai berbahaya tetap apostrof: NIP lama `=1+2` → `'=1+2`, `+cmd` → `'+cmd`, `@x` → `'@x`, TAB+`abc` → `'⇥abc`, ` =1` → `' =1`, `=HYPERLINK("x")` → `'=HYPERLINK("x")`; nama `=1+2 …`/`+cmd …`/`@SUM(A1) …`/`-2+3 …` → diawali `'`. Tidak bisa menyisipkan rumus lewat NIP: NIP `QA"=1+2` → `QA"=1+2` polos (kutip di-escape CSV `"QA""=1+2"`, tidak dibungkus `="…"`), NIP `SUM(1)` → `SUM(1)` polos, NIP `=HYPERLINK(1)` → `'=HYPERLINK(1)`, NIP `-1+1` → `'-1+1`, NIP lama `X(1)`/`A(B)`/`ab"c` → polos (tidak diawali karakter formula). Hanya nilai `[0-9A-Za-z./ -]+` yang dibungkus (`-1` → `="-1"`, `12/34.5 A-b` → `="12/34.5 A-b"`, keduanya literal string, bukan rumus). Cek otomatis: satu-satunya sel berawalan `=` adalah `="<[0-9A-Za-z./ -]+>"` di kolom NIP/NIP Lama (0 pelanggaran). `POST pegawai/pencarian/export` → 200, bentuk sel sama (`="199001012015011201"` / `="012345"`, `'=1+2`, `'=HYPERLINK(1)`). XLSX → 45 sel semua `t="inlineStr"`, tanpa `<f>`, isi sel `199001012015011201`, `012345`, `019001012015011202`, `=1+2` sebagai teks murni (tidak dibungkus `="…"`). Kode: `EksporTabel.php:44-47` (`teks()`), `PegawaiService.php:181,184`. Regresi hak: role 1/3/4/5/8 → 200, 2/6/7 → 403, tanpa token 401, `format=pdf` → 422 |
| **A-F2** (Trivial) status dokumen DBV basi | Baca dokumen di commit yang diuji | **LOLOS** (lihat R1F-F2 Info) | `B-01-B-02-pegawai-riwayat-schema.md:16-20` "✅ DISETUJUI DB VALIDATOR (DBV-012/DBV-013 … PR #20 06-10-2026)", merge `957729f`; tidak ada lagi "MENUNGGU REVIEW" (sisa ⏳ hanya baris riwayat gate pra-review :715, bagian verifikasi developer). `DBV-019-fk-g02-pegawai-schema.md` header "✅ DISETUJUI DB VALIDATOR (… PR #24 07-10-2026 …)", tabel Bagian 7 #1–#6 kini berisi keputusan ✅ per butir (sebelumnya ⏳). `progress/03-Kepegawaian.md` entry criteria "DBV-019 (PR #24, disetujui DB Validator 07-10-2026, di `main` 13-10-2026 `191efec`)" dan bagian Skema Fase 3 "✅ disetujui …"; tidak ada lagi "menunggu review DB Validator" untuk DBV-019 |
| **A-F3** (Trivial) seed `jenis_pegawai` ≠ pemetaan akun | DB setelah seed + `POST pegawai` per jenis + pemakai id lain | **LOLOS** | `jenis_pegawai` = {1 Pegawai Negeri Sipil, 2 Pegawai Tidak Tetap, 6 Pegawai Pemerintah dengan Perjanjian Kerja} (`MasterDataSeeder.php:33-37`). `POST pegawai` role 1: jenis 1 → 201 akun `user_level` 2; jenis 2 → 201 `user_level` 6 (PTT); jenis 6 → 201 `user_level` 7 (PPPK) — DB `pengguna` dan respons `akun.user_level` sama; jenis 3 (tidak ada lagi) → 422 `id_jenis_pegawai` "Jenis pegawai tidak ditemukan atau tidak aktif.". Login akun PTT/PPPK baru (password awal = NIP) → 200, `user_level` 6/7. Konsistensi id lain: Konket Sakit 2 hari untuk jenis 2 → 201 (aturan PTT `KonketService::JENIS_PEGAWAI_PTT = 2`), untuk jenis 6 → 422 "…harus melalui Cuti Sakit"; `Jabatan::JENIS_PEGAWAI_PPPK = 6`; `master/jenis-pegawai/options` → 1/2/6 |
| **B-F1** (Info) `affect_tukin` tidak dari master | `POST konket` per role + `PUT` + ubah master | **LOLOS** (lihat R1F-F1 Info) | Master seeder: Dinas (old 8) 2, Cuti (5) 2, lainnya 1. Pegawai: role 2 Dinas → `absen_ijin.affect_tukin` 2 (kiriman `affect_tukin=1` dari pegawai diabaikan), Sakit → 1, Izin Terlambat → 1; role 6 Dinas → 2, Sakit 2 hari (PTT) → 1; role 7 Dinas → 2, Sakit → 1; dinas gabungan oleh pegawai → induk + 2 peserta 2; `GET konket/{id}` → `affect_tukin: 2`. Admin: role 1 Dinas + `affect_tukin=1` → 1 (nilai admin menang atas master 2); tanpa kiriman → 2; Cuti → 2; Izin Lainnya + `affect_tukin=2` → 2 (master 1); `affect_tukin=3` → 422; role 3 Dinas + `1` → 1, tanpa → 2, Sakit + `2` → 2. PUT: master Dinas diubah 2→1 lewat DB, lalu PUT pemilik → baris tetap 2; PUT role 1 tanpa `affect_tukin` → tetap 2; PUT role 1 `affect_tukin=1` → 1; POST baru saat master = 1 → 1 (master dibaca saat tambah); master dikembalikan 2. Setujui role 1 → V, `affect_tukin` tetap 2. Kode `KonketService.php:446-456` |
| **B-F2** (Info) deprecation TCPDF di log | Render PDF lewat HTTP + hitung log `writable/logs` worktree + percobaan kendali | **LOLOS** | 6 render (struktur organisasi, DRH role 1, DRH pemilik `inline=1`, LKH Disetujui, tanda terima Karpeg; semua 200 `application/pdf` `%PDF-1.7`): `log-2026-10-15.log` jumlah "TCPDF is deprecated" 0 → 0, ukuran berkas tidak berubah (137.402 byte). Kendali di worktree (CLI, vendor TCPDF 6.11.4): `new TCPDF()` tanpa konstanta → `E_USER_DEPRECATED` "TCPDF is deprecated: migrate to tecnickcom/tc-lib-pdf …"; dengan `TCPDF_SILENCE_DEPRECATION` → notice TCPDF hilang tetapi `trigger_error('lain', E_USER_DEPRECATED)` tetap tertangkap (peredaman terarah, bukan global). Bukti di log nyata: deprecation lain tetap ditulis CI4 (`WARNING … [DEPRECATED] str_getcsv() … PegawaiPencarianEksporTest.php on line 193`, 3 baris saat PHPUnit, lihat R1F-F3); `Config/Boot/development.php:15` `error_reporting(E_ALL)` tidak diubah. Kode `TcpdfRenderer.php:88-90`; `Pdf::qr()` (`TCPDF2DBarcode`) tidak memicu notice (hanya `tcpdf.php:1905`) |
| **B-F4** (Info) jenis Konket terlarang hard-code, Dinas/Sakit tak bisa diajukan pegawai | `GET konket/opsi` per role, `POST` id terlarang, override `.env` 8 skenario (server di-restart tiap skenario) | **LOLOS** | Bawaan: role 2/6/7/3 (dan 4/5 pengamat) → kategori {1, 8, 10} (Izin Terlambat, Dinas, Sakit = id 1/3/7; tanpa id 2/4/5/6); role 1 → {1, 5, 8, 17, 10} (tanpa id 4/5); role 8 → 403. POST terlarang → 422 `kategori` "Jenis ketidakhadiran tidak tersedia.": role 2 kategori 5/13/16/17, role 3 5/17, role 6 13, role 7 16, role 1 13/16, kategori tak ada 99. Pegawai kini bisa Dinas (8) & Sakit (10): role 2/6/7 → 201 (lihat B-F1). Override `.env`: S1 `konket.jenisTerlarangPegawai = 2,4,5` + `konket.jenisTerlarangAdmin = 4` → pegawai/role 3 {1,8,10,17}, role 1 {1,5,8,10,16,17}, role 7 POST kategori 17 → 201; S2 `" 4 , 6 "` → {1,5,8,10,16} (spasi ditoleransi); S7 `6` → {1,5,8,10,13,16} dan POST 17 → 422; nilai salah → bawaan legacy: S3 `2,x,5`/`4,abc`, S4 kosong, S5 `0,2`/`-4`, S6 `2;4;5`/`4.5` → pegawai {1,8,10}, role 1 {1,5,8,10,17}, POST 17 → 422; S8 tanpa override → bawaan. Kode `Config/Konket.php:41-73`, `KonketService.php:800-805`. `.env` dikembalikan ke dasar dan server di-restart sesudahnya |
| **C-F1** (Trivial) `bulan[]` diterima diam-diam | `GET struktur-organisasi` dan `/pdf` | **LOLOS** | `?bulan[]=2026-09`, `bulan[]=…&bulan[]=…`, `bulan[x]=2026-09`, `id_unit_satker=0&bulan[]=2026-09` → 422 `errors.bulan` "Data struktur organisasi untuk bulan tersebut tidak tersedia." (pohon dan PDF, 8/8); role 2/6/8 juga 422. Bulan sah tetap 200: `2026-09` → `tanggal 2026-09-30`, `2025-01` → `2025-01-31`, kosong / `bulan=` → bulan berjalan `2026-10`/`2026-10-15`, ` 2026-09 ` (spasi) → `2026-09`. Regresi: `2024-12`, `2026-11`, `2026-13`, `Okt` → 422. Kode `StrukturService.php:557-559` |

## QASMTASK terkait (nilai ulang)

| QASMTASK | Task | R0 | R1 | Dasar |
|---|---|---|---|---|
| 043 | B-01 skema pegawai & snapshot | PASS dengan catatan (A-F2 Trivial, A-F9 Info) | **PASS** | A-F2 lolos; A-F9 tetap Info (keputusan skema tercatat). R1F-F2 Info. Regresi skema: lihat PHPUnit `tests/database` |
| 044 | B-02 skema riwayat & lampiran | PASS dengan catatan (A-F2, A-F9) | **PASS** | Sama dengan 043 |
| 047 | B-05 tambah & hapus pegawai | PASS sesuai keputusan user (A-F3 Trivial, A-F7 Info) | **PASS** (sesuai keputusan user 13-10-2026 hapus fisik) | A-F3 lolos (seed 1/2/6, akun 2/6/7). A-F7 tetap Info (ADR-004) |
| 055 | B-13 Konket | PASS dengan catatan (B-F1, B-F4 Info) | **PASS dengan catatan** | B-F1 & B-F4 lolos; alur inti (opsi, validasi, Dinas/Sakit pegawai, dinas gabungan, proses V, hak) lolos. Catatan R1F-F1 (Info): `affect_tukin` tidak dihitung ulang saat pemilik mengganti jenis lewat PUT. Butir "verifikasi arti id 2/4/5/6 di data produksi saat cutover" tetap terbuka di runbook DBV-011 2.4 #3 (bukan butir QA) |
| 061 | B-19 Struktur Organisasi + Plt/Plh | PASS dengan catatan (C-F1 Trivial + C-F2..F4 Info) | **PASS** | C-F1 lolos; C-F2..C-F4 tetap Info ([K] / kosmetik). MTC-025 N/A |
| 062 | B-20 bagian API | PASS dengan catatan (A-F1 Minor, A-F6/A-F10 Info) | **PASS** | A-F1 lolos (CSV `="…"` aman, XLSX teks). A-F6/A-F10 tetap Info |

## Regresi

| Uji | Hasil | Bukti |
|---|---|---|
| PHPUnit penuh di `simpeg_v2_qa3r1f_test` | **PASS** | `php -d memory_limit=2G vendor/bin/phpunit --no-coverage` (seluruh `./tests`, mencakup `tests/Kepegawaian`, `tests/MasterData`, `tests/feature`, `tests/database`, `tests/unit`) → **OK (1807 test, 33.016 assertion)**, 0 error/failure/skip, 12:35, stderr kosong (`qa3r1f/phpunit.log`, `junit.xml`). Termasuk 761 testcase Kepegawaian, 242 MasterData, 42 Konket (+ test baru CR-050 `KonketConfigTest`, `TcpdfRendererDeprecationTest`). R0: 1801 test |
| Konket B-13 (alur inti) | PASS | `out-konket.txt` 43/43 PASS: opsi per role, 422 tanggal/jenis, pegawai tidak bisa proses (403), role 1 setujui → V, dinas gabungan peserta ikut, `GET konket/{id}` |
| Ekspor B-20 | PASS | `out-export.txt`: hak per role, 401/422, lingkup role 3 (200), CSV/XLSX |
| Struktur B-19 | PASS | `out-struktur-pdf.txt`: pohon & PDF 200, validasi bulan 422, bulan sah 200 |
| Akun B-05 | PASS | `out-akun.txt`: tambah pegawai + akun otomatis per jenis, login akun baru |
| PDF (DRH, struktur, LKH, Karpeg) | PASS | 200 `%PDF-1.7`; alur LKH (tambah 201 → atasan setujui 200 → PDF 200) dan Karpeg (ajukan 201 → role 1 setujui 200 → tanda terima 200) |

## Temuan baru

| ID | Severity | Ringkasan | Butir | Keputusan tercatat? |
|---|---|---|---|---|
| R1F-F1 | Info | `affect_tukin` tidak dihitung ulang dari master bila pemilik mengganti jenis (kategori) lewat `PUT konket/{id}` | 055 | Sebagian: komentar kode "ubah tidak menyalin ulang dari master" (`KonketService.php:451-455`) |
| R1F-F2 | Info | Dokumen DBV menyebut `191efec` sebagai "merge commit" PR #24, padahal commit biasa (satu induk) | 043, 044 | Tidak |
| R1F-F3 | Info | Test `PegawaiPencarianEksporTest.php:193` memanggil `str_getcsv` tanpa `$escape` → WARNING `[DEPRECATED]` di log saat PHPUnit (sudah ada sebelum CR-050) | 062 (test) | Tidak |

**R1F-F1 — `affect_tukin` basi saat jenis diganti (Info).**
- Langkah: pegawai role 2 `POST konket` kategori 1 (Izin Terlambat, master `affect_tukin` 1) → 201 baris `affect_tukin` 1;
  lalu `PUT konket/{id}` (`_method=PUT`, multipart) dengan `kategori` 8 (Dinas, master 2) → 200.
- Hasil: baris id 3 `{"kategori":"8","jenis_konket":"Dinas","affect_tukin":"1","status":"W"}`; pengajuan Dinas baru oleh
  pegawai yang sama mendapat 2. Arah sebaliknya (Dinas → Izin Terlambat) menyisakan 2 pada jenis yang masternya 1.
- Harapan (tafsir [V2] CR-050 "nilai awal = `jenis_konket.affect_tukin` jenis terpilih"): bila pegawai (yang tidak boleh
  mengisi `affect_tukin`) mengganti jenis, nilai awal mengikuti jenis baru; nilai pilihan admin tidak ditimpa.
- Penyebab: `KonketService::validasi()` hanya mengisi dari master saat `$lama === null` (`KonketService.php:451`).
- Dampak: flag dipakai perhitungan Tukin (legacy `L_presensi` hanya menghitung `absen_ijin` V dengan `affect_tukin='1'`);
  admin tetap bisa mengoreksi saat proses. Data seeder fiktif, arti nyata bergantung data produksi.
- Opsi: (a) di `validasi()` isi ulang dari master bila `$lama !== null`, pemanggil bukan admin, dan `kategori` berubah;
  (b) catat eksplisit di README kontrak `PUT konket/{id}` bahwa flag tidak ikut berubah. Mohon reviewer memilih; bila (b),
  temuan gugur.

**R1F-F2 — rujukan commit DBV-019 (Info).** `DBV-019-fk-g02-pegawai-schema.md` (header Status) menulis "di-merge ke `main`
13-10-2026 (merge commit `191efec`)". `git log -1 --format=%P 191efec` = satu induk (`7b10bd7`), subjek "docs(db): DBV-019
catat sinkron main 6663168 di verifikasi developer" — ini ujung branch PR #24 yang masuk `main`, bukan merge commit. Isi
status (disetujui, sudah di `main`) benar; cukup ubah kata "merge commit" menjadi "ujung branch"/commit terakhir PR. Rujukan
yang sama di `B-01-B-02-…md:19` dan `progress/03-Kepegawaian.md` (tanpa kata "merge") tidak keliru.

**R1F-F3 — deprecation `str_getcsv` di test (Info).** Selama PHPUnit, `writable/logs/log-2026-10-15.log` mendapat 3 baris
`WARNING … [DEPRECATED] str_getcsv(): the $escape parameter must be provided … PegawaiPencarianEksporTest.php on line 193`
(PHP 8.4). Baris itu sudah ada sebelum CR-050 (bukan regresi) dan sekaligus membuktikan deprecation lain tidak ikut diredam
oleh perbaikan B-F2. Opsi: `str_getcsv($s, ',', '"', '')` seperti kode produksi `EksporTabel::csv()`.

## Tidak diuji / di luar cakupan
- Perubahan frontend CR-049 (ISSUE-033..038) dan tampilan UI → agen QA UI lain (DB `simpeg_v2_qa3r1d`, tidak disentuh).
- Membuka CSV di Excel/LibreOffice sungguhan (tidak tersedia di lingkungan QA): dinilai dari bentuk sel `="…"` yang standar
  dibaca sebagai teks; pembaca CSV non-Excel akan melihat `="…"` apa adanya (tercatat di docblock `EksporTabel` dan README).
- Arti id `jenis_konket` 2/4/5/6 di data produksi (runbook cutover DBV-011 2.4 #3).

## Bersih-bersih
Server `php spark serve` port 8125 dihentikan (0 proses tersisa). `.env` worktree dihapus (override Konket sudah dikembalikan ke dasar sebelum itu). DB `simpeg_v2_qa3r1f` dan `simpeg_v2_qa3r1f_test` di-DROP (nama persis). Log worktree: 0 baris "TCPDF is deprecated" sampai akhir. Worktree `scratchpad\wt\qa3r1f` dilepas (`git worktree remove --force`). Instance MySQL 3307 tetap berjalan; DB `simpeg_v2_qa3r1d` (agen QA UI) dan DB lain tidak disentuh. Tidak ada perubahan kode, commit, push, atau tulisan ke Trello/Notion/GitHub. Harness dan keluaran mentah tetap di `scratchpad\qa3r1f\` (data sintetis; `lib.php` memakai password seed lokal).
