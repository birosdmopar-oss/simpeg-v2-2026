# Modul C — Layanan & Approval (Fase 4)

Service/business logic modul ini (`App\Libraries\Layanan\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

## Cuti — logika murni (`Cuti/`, MAKE-022 Tahap 2b, PRD Fase 4 v1.1 §6.1.4)

Dikerjakan **sebelum** DBV-022 disetujui karena tidak bergantung tabel Modul C: semua kelas `final` dengan method statis
(kecuali value object `readonly`), **tanpa DB, HTTP, session, jam sistem, angka acak, konfigurasi, lingkungan, atau I/O**
— dijaga `tests/unit/Layanan/Cuti/CutiMurniTest.php` (`PemindaiKemurnian`, pengecualian hanya `createFromFormat('!Y-m-d')`).
"Hari ini", "sekarang", daftar tanggal libur (`HariLiburService::tanggalLibur()`, status 1), baris saldo, dan jumlah
"menunggu" diterima sebagai parameter. Konstanta runtime ada di `Config\Cuti` dan disalurkan lewat `Config\Cuti::aturan()`
→ `AturanCuti` (library tidak boleh membaca `Config\*`). Service ber-DB M3 (`KuotaCutiService`, `CutiApprovalService`)
membungkus kelas ini dengan transaksi, `FOR UPDATE`, dan `audit_logs`; DILARANG ada implementasi potong/refund lain.

Label sumber: **[K]** kode legacy (`L` = `libraries/hr/L_cuti.php`, `C` = `controllers/hr/rwy/Cuti.php`, `FH` =
`helpers/function_helper.php`), **[V2]** keputusan PRD v1.1 (13 keputusan 26-10-2026).

| Kelas | Isi |
|---|---|
| `AturanCuti` | value object konstanta §6.3.1 (`KUOTA_N` 12, `MAKS_N1`/`MAKS_N2` 6, `MAKS_TOTAL` 24, `MAKS_UTANG` 12, jenis hari kerja [1,3,5] / kalender [2,4,6], `DURASI_BESAR` [1,2,3], alasan penting 1 bulan, CLTN 3 tahun, `JAM_BATAS_TUNDA` 12:00) + konstanta `JENIS_*`; `bawaan()`; `satuanHariKerja(jenis)` |
| `StatusCuti` | kode 0/1/2/3/4 [K] COMMENT D1 + label, `FINAL` {1,2,4}, `MENUNGGU` {0,3}, `EFEK_FINAL` {1,4}, domain `status_al`/`status_pyb`; tanpa 10 (D6) |
| `SaldoCuti` | value object `pegawai_sisa_cuti` (N, N-1, N-2, utang, ditangguhkan): `dariBaris`/`keBaris`, `total()`, `saldoTahunDepan()`, `periksaBatas()` D8 per kolom (§6.3.6) |
| `HitungLamaCuti` | §6.3.2: hari kerja Senin–Jumat minus libur (dihitung sendiri, paritas `Presensi\Tukin\HariKerja` diuji), hari kalender inklusif, `tglAkhirBesar()` = `addMonth` legacy dijepit −1 hari [K `FH:960-966`], `liburTerpakai()` untuk respons kalkulator |
| `PorsiTahunCuti` | §6.3.3: pecah lama ke `tahun_ini`/`tahun_depan` pada 31-12/01-01; hanya tahun acuan + 1 (di luar itu = kesalahan pemanggil); `tahunAcuan(created_at)` |
| `ValidasiTanggalCuti` | §6.2.3 + §6.3.2: `tgl_mulai`/`tgl_akhir`/`durasi` dengan semua galat dikumpulkan (A-09); batas jenis 5 (`+1 month` PHP) dan 6 (`+3 year` PHP) inklusif; batas 31-12 tahun acuan + 1 **hanya jenis 1** (lihat catatan); `periksaLama(0)` |
| `KuotaCuti` | §6.3.4/§6.3.5: `periksa()` (pesan [K `L:1648`], `saldo_tidak_terdaftar`), `potong()` N-2 → N-1 → N + utang/ditangguhkan (gagal = 422, saldo tidak berubah), `kembalikan()` N → N-1 → N-2 batas 12/6/6 tanpa negatif; jaminan impor T13 diuji |
| `TransisiCuti` | §6.4.2/§6.4.3: `langkahTunggal`, `tahapAktif`, `nipPelaku`, `proses()` → status/`status_al`/`status_pyb` (T4: `status_pyb` tidak ditulis, keputusan #3), galat `aksi`/`reason_note`/`status`; `keteranganPetugas()` pemisah `"\n"` (keputusan #7) |
| `EfekFinalCuti` | §6.4.5 predikat: efek hanya ke 1/4; `perluPotongSaldo` jenis 1; `cltnAktif` TM ≤ hari ini ≤ TA (T18); `perluSurat` |
| `PengingatCuti` | §6.4.7: `batasTunda` `tgl_mulai` 12:00:00 WIB, `bolehTunda(sekarang)` inklusif (T17), `flagTahap`, pesan [K `C:971-976`] |

Test: `tests/unit/Layanan/Cuti/{CutiMurniTest, HitungLamaCutiTest, KuotaBoundaryTest (§6.3.8 K1–K21 + §6.3.10 T9, T11–T14),
ValidasiTanggalCutiTest (T2, T3, T7, T8), TransisiCutiTest (T4–T10, T16–T18, single end node BFS)}`.

Catatan keputusan coder (dicatat untuk reviewer):

1. Batas `tgl_akhir ≤ 31-12 tahun acuan + 1` (§6.2.3) diterapkan **hanya untuk jenis 1**: batas itu berasal dari kuota
   dua tahun (keputusan #6), sedangkan CLTN sah sampai 3 tahun dan jenis 2–6 tidak memotong kuota.
2. Paritas T13 berlaku untuk saldo berbentuk ternormalisasi legacy (N terisi dulu sampai 12, lalu N-1 sampai 6, lalu N-2);
   untuk bentuk lain (mis. (5,6,6)) rumus N-2 → N-1 → N dan normalisasi memang berbeda — bentuk itu tidak pernah
   dihasilkan legacy maupun v2.
3. Pesan batas jenis 5/6 ("Lama cuti karena alasan penting maksimal 1 bulan." / "… di luar tanggungan negara maksimal 3
   tahun.") dan "Tanggal … tidak valid." adalah teks [V2] (legacy tidak punya teks server untuk kasus itu).
4. Berkas ke-10 `AturanCuti` di luar daftar §6.1.4 — perlu karena library tidak boleh membaca `Config\Cuti`.

Belum ada di Tahap 2b (M3+): Model, controller, route, migration, penentuan AL/PyB, tumpang tindih atas baris DB,
notifikasi, `KuotaCutiService`/`CutiApprovalService`.

## TB, IB, PMK — logika murni (`Studi/`, `Tb/`, `Pmk/`, MAKE-024 bagian awal, PRD Fase 4 v1.1 §6.7–§6.9)

Pola sama dengan Cuti Tahap 2b (§6.1.4): kelas `final` statis / value object `readonly`, **tanpa DB, HTTP, session, jam
sistem, angka acak, konfigurasi, lingkungan, atau I/O** — dijaga `tests/unit/Layanan/LayananStudiPmkMurniTest.php`
(`PemindaiKemurnian` pada `Studi/`, `Tb/`, `Pmk/`, dan `app/Interfaces/Layanan/`; pengecualian hanya
`createFromFormat('!Y-m-d')`). "Hari ini", master, TMT CPNS, dan baris pembanding (tumpang tindih, perpanjangan) diterima
sebagai parameter. Konstanta runtime di `Config\Layanan` → `aturanStudi()` / `aturanPmk()`. Service ber-DB M5 (`Tb/TbService`,
`Tb/StatusTugasBelajar`, `Ib/IbService`, `Pmk/PmkPengajuanService`, …, PRD §6.7–§6.9 file boundary) membungkus kelas ini.

`Studi/` = bagian yang sama untuk TB dan IB (PRD §6.8.1 "sama TB kecuali disebut"); `Tb/` = khusus TB. Label sumber: **[K]**
kode legacy (`L_tb.php`, `L_ib.php`, `L_pmk.php`, `controllers/hr/services/Local.php`, DDL `simpeg01_struktur_lengkap`),
**[V2]** keputusan PRD (Q-03, Q-11, Q-12, Q-15, Q-16, Q-23).

| Kelas | Isi |
|---|---|
| `Studi/AturanStudi` | value object: jenjang > 3, `up` bawaan, `reason_note` ≤ 255 byte; `JENIS_TB`/`JENIS_IB`, `namaLayanan()` |
| `Studi/StatusStudi` | kode 0/1/2/3 [K DDL] + label, `FINAL` {1,2}, `TUMPANG_TINDIH` {0,1,3,4} ([K] {0,3,4} + 1 [V2 TB-04]) |
| `Studi/RentangTanggal` | urai `!Y-m-d`, selisih `DateTime::diff`, irisan inklusif, tambah hari |
| `Studi/LamaTahunStudi` | `lama_tahun` = `diff.y + diff.m/12` 1 desimal [K TB-17 `Local.php:3203-3214`], dihitung server (Q-03); kolom `"3.90"` |
| `Studi/ValidasiStudi` | isian wajib TB/IB (semua galat dikumpulkan, TB-03), `tgl_akhir ≥ tgl_mulai`, jenjang, status awal TB 0\|1 + data SK bila 1, IB selalu 0; `bentrok()` + `pesanBentrok()` [K]; TB vs IB tidak dibandingkan (IB-09, Q-11) |
| `Studi/TransisiStudi` | satu langkah 0 → setujui (data SK wajib, `show_pl = 1`) / tolak (`reason_note` wajib ≤ 255 byte) → final; selain dari 0 → `errors.status` |
| `Tb/PerpanjanganTb` | validasi (wajib, `akhir ≥ mulai`, induk status 1 [V2 TB-13]), saran mulai = akhir efektif + 1 [K], akhir efektif = MAX, `ringkasan()` = MAX terpisah pengganti trigger `riwayat_tb_perpan_af*` [K] |
| `Tb/BebasPresensiTb` | `bebasPresensiPenuh(daftarTb, tanggal)`, `rentangBebasPresensi(daftarTb, dari, sampai)`, `rentangBebasPresensiBanyak(nip, daftarTbPerNip, dari, sampai)` (MAKE-044, aditif), `statusPenuh()` (D15, turunan) — inti murni untuk `App\Interfaces\Layanan\StatusTugasBelajarInterface` (Fase 5 D-06) |
| `Pmk/AturanPmk` | value object: `jenis_pmk` {Swasta, Negeri} [V2 P-5], `reason_note` ≤ 255 byte, `mker` ≤ 255 (TINYINT) |
| `Pmk/StatusPmk` | kode 0/1/2/3 [K DDL] + label kolom & label UI v2, `FINAL` {1,2}, `ikutTumpangTindih` (≠ 2 [K]), `detailBolehDibuka` (Q-16), `bolehHapus` (≠ 1 [K]) |
| `Pmk/MasaKerjaPmk` | kalkulator `pmk/hitung-mker`: `mker_th = diff.y`, `mker_bl = diff.m` [K `Local.php:4574-4601`]; nilai pengajuan tetap dari klien (D19/P-4) |
| `Pmk/ValidasiPmk` | isian wajib (semua galat), enum `jenis_pmk`, `tgl_akhir ≥ tgl_awal`, `tgl_akhir < TMT CPNS` [V2 P-3], `mker` 0..255; `bentrok()` status ≠ 2 [K] |
| `Pmk/TransisiPmk` | 0 → proses → 3; 0/3 → setujui → 1, tolak → 2 (`reason_note` wajib [K] ≤ 255 byte); 1/2 final terkunci [V2 P-14]; `aksiTersedia()` |

Test: `tests/unit/Layanan/{LayananStudiPmkMurniTest, Studi/{LamaTahunStudiTest, ValidasiStudiTest, TransisiStudiTest},
Tb/{PerpanjanganTbTest, BebasPresensiTbTest}, Pmk/PmkMurniTest}` — boundary kabisat, akhir bulan, perpanjangan berganda,
lintas tahun, single end node (BFS PMK).

Catatan keputusan coder (dicatat untuk reviewer):

1. **Bebas presensi = rentang TB ATAU salah satu rentang perpanjangan** (teks PRD §6.7.5), bukan satu rentang
   `tgl_mulai..MAX(tgl_akhir, tgl_akhir_perpan)`. Akibatnya celah antara akhir TB dan awal perpanjangan **tidak** bebas,
   sama dengan `laporan_tukin`/`laporan_tukin_us_skp` (acuan Tukin). `rekap_full_xls`/`rekap_uma*` legacy menganggap
   celah itu TB (anomali, tidak dibawa). `PerpanjanganTb::tglAkhirEfektif()` (MAX) hanya untuk tampilan/saran tanggal.
2. **Status TB/IB 3 (Diproses) dan 4** hanya mungkin dari data impor; v2 tidak menulisnya dan `TransisiStudi` menolak
   memproses keduanya (`errors.status`), karena PRD hanya mendefinisikan transisi dari 0. Data impor berstatus 3/4 perlu
   keputusan cutover (dibiarkan atau dinormalkan ke 0) — tidak diputuskan di sini.
3. **Teks pesan.** Wajib isi memakai format PRD "{Label} wajib diisi." dengan label legacy. Teks tumpang tindih, urutan tanggal
   PMK, TMT CPNS, aksi, dan alasan PMK dipakai persis [K]. Teks [V2] baru: jenjang/tanggal tidak valid, status awal,
   perpanjangan, status tidak dapat diproses, dan batas 255 byte. Pesan "Alasan ditolak tidak boleh kosong." [K PMK]
   dipakai juga untuk TB/IB, karena alasan tolak TB/IB di legacy hanya wajib di klien.
4. **PMK `tgl_akhir ≥ tgl_awal`** dicek bila kedua tanggal sah. Legacy mengeceknya hanya bila belum ada galat lain, tetapi v2
   mengumpulkan semua galat.
5. **TMT CPNS tidak diketahui** (`null`, mis. data impor tanpa `pegawai_cpns`) → cek P-3 dilewati. Service M5 memutuskan
   sumber TMT (snapshot `pegawai_cpns` atau `MasaKerja::tmtCpnsDariNip`).
6. **`mker_th`/`mker_bl` divalidasi bilangan bulat 0..255** (tipe TINYINT) [V2]. Legacy hanya mengecek kosong. Hasil 0 th
   0 bl tetap diterima server [K]; peringatan "harus melebihi 1 bulan" hanya di kalkulator klien (`MasaKerjaPmk::kurangSebulan`).
7. **`App\Interfaces\Layanan\StatusTugasBelajarInterface`** dibuat sekarang (kontrak murni, tanpa implementasi) supaya
   Fase 5B bisa mengandalkan nama dan signature PRD §6.7.5. Implementasi DB dan `Config\Services::statusTugasBelajar()`
   menyusul di M5 setelah DBV-022.
8. **Hak ubah/hapus TB/IB** (pemilik status 0 dan `tgl_mulai > hari ini`, dsb., §6.7.2) belum dibuat predikatnya; bagian itu
   bergantung pada role/lingkup dan dikerjakan di service M5.

Belum ada di bagian ini (M5 setelah DBV-022 & M2): Model, controller, route, migration, berkas, PIC (`PetugasLayananService`),
notifikasi, efek `id_jenis_status`, surat TB/IB.

## TB, IB, PMK — lapisan DB (MAKE-024 M5, PRD Fase 4 §6.7–§6.9)

Service ber-DB membungkus kelas murni di atas; DILARANG ada aturan tumpang tindih/transisi/validasi/lama tahun kedua.

| Kelas | Isi |
|---|---|
| `LayananStudi` (abstrak) | dasar TB/IB: kunci pegawai `FOR UPDATE` + baca ulang baris, hak lihat (pemilik, PIC aktif, role 1, role 3 `bolehLihat`), pelaku tulis (`admin1`/`admin3`/`pemilik`), tampilan (`label_status`, `nada_status`, `tahap[]`, `aksi {}`), lampiran ber-slot, notifikasi, transaksi pola RiwayatEngine (push sesudah commit, berkas direkonsiliasi) |
| `StudiPengajuanService` (abstrak) | daftar `mode`, opsi, detail, tambah, ubah, hapus, unduh lampiran — `ValidasiStudi`, `BerkasStudi`, master jenjang, snapshot pemohon server |
| `StudiApprovalService` (abstrak) | proses PIC satu langkah — `TransisiStudi`; `show_notif`/`notif_date` pengganti trigger `riwayat_*_beUpd` |
| `PetugasLayananService` | `adalahPic(nip, jenis)`, `nipPicAktif(jenis)` (status 1, tanpa batas unit) — dipakai M5 dan M6 |
| `PenandaTanganKabiro` | penanda tangan surat TB/IB (`id_jabatan_kabiro_kepegawaian`, `tmtsk` terbaru) + unit tujuan laporan (`id_unit_kepegawaian`), lewat `WebConfigService::value()` (Q-06(a)) |
| `NotifikasiNoOp` | binding sementara `NotifikasiLayananInterface` (`Config\Services::notifikasiLayanan()`); diganti NotifikasiService di M4 (MAKE-023) |
| `Tb/TbService`, `Tb/TbApprovalService`, `Tb/EfekTbTrait` | C-07; efek final `pegawai.id_jenis_status` 2 bila hari ini dalam rentang (dibalik saat hapus) |
| `Tb/PerpanjanganTbService` | C-07 perpanjangan role 1; ringkasan `tgl_*_perpan` = `PerpanjanganTb::ringkasan` (pengganti trigger `riwayat_tb_perpan_af*`) |
| `Tb/StatusTugasBelajar` | implementasi `StatusTugasBelajarInterface` (`Config\Services::statusTugasBelajar()`, Fase 5 D-06) — dua kueri lalu `BebasPresensiTb`; versi massal `rentangBebasPresensiBanyak(nip[], dari, sampai)` (MAKE-044, PRD Fase 5B F5B-18): per batch `BATCH_NIP` = 1000 NIP satu kueri `riwayat_tb` status 1 (WHERE IN) + satu kueri `riwayat_tb_perpan` (WHERE IN id, dilewati bila batch tanpa TB); masukan kosong → `[]` tanpa kueri; hasil per NIP identik dengan versi tunggal; metode tunggal tidak berubah |
| `Tb/SuratTbService` | SK unggahan (kode 47) atau render `Views/pdf/surat_tb.php` |
| `Ib/IbService`, `Ib/IbApprovalService` | C-08; status selalu 0, tiga slot wajib semua role, efek final = surat |
| `Pmk/DasarPmkService` | dasar PMK di atas LayananStudi: hak lihat/tulis PMK (role 3 lihat saja, ubah tanpa syarat status [K D19], hapus ditolak bila status 1), tampilan `TampilanPmk`, isi notifikasi |
| `Pmk/PmkPengajuanService` | C-09 pengajuan: daftar `mode`, opsi (`tmt_cpns` dari `pegawai_cpns.tmtsk`), `hitung-mker`, CRUD; berkas kolom lewat `KolomBerkas` |
| `Pmk/PmkApprovalService` | C-09 proses PIC (`TransisiPmk`); tanpa efek `riwayat_pmk`/masa kerja (D16) |
| `Pmk/RiwayatPmkService` | riwayat PMK final: daftar, arsip SK kode 42 (min 1 tersisa), hapus |
| `Ib/SuratIbService` | render `Views/pdf/surat_ib.php` + simpan kode 50 di transaksi approval (berkas buatan server lewat jalur validasi unggahan yang sama, `UploadedFile` atas berkas sementara), unduh surat terbaru |

Kelas murni baru M5: `Studi/BerkasStudi` (slot berkas & kode `jenis_rwy` 46–50, `slotKosong()`), `Studi/TampilanStudi`
(badge/stepper §6.11.4). Penjaga kemurnian (`LayananStudiPmkMurniTest`) memakai daftar eksplisit: setiap berkas di `Studi/`, `Tb/`, `Pmk/`,
`Interfaces/Layanan/` wajib terdaftar sebagai murni (dipindai) atau lapisan DB (dikecualikan; hanya di `Tb/`/`Pmk/`, file
boundary PRD) — berkas baru tanpa pendaftaran membuat test gagal.

Catatan keputusan coder M5 (dicatat untuk reviewer):

1. **[V2] Slot berkas lewat `document_attachment.tag`** (keputusan reviewer CR 27-10 butir 3, pola Arsip Data Umum): satu kode
   per jenis (46 TB, 49 IB), `tag` = nama kolom legacy; slot berisi bila ada lampiran ber-`tag` itu atau kolom `file_*` impor
   terisi; berkas pengganti menghapus lampiran lama slot itu (fisik + audit) di transaksi yang sama. Kolom `file_*` tidak
   diisi v2 (DQ-20). Kode satu-slot 47/48/50 tanpa `tag`. `riwayat_tb_perpan.file_sperpan` NOT NULL [K] diisi `basename`
   lampiran kode 48.
2. **[V2] Rentang efek `id_jenis_status` per tanggal inklusif** (disetujui Horii 27-10). Legacy `L_tb.php:1169-1179` /
   `:746-757` membandingkan dengan jam sekarang sehingga hari terakhir TB tidak ikut. Gap "TB disetujui sebelum mulai / TB
   berakhir → status tidak berubah otomatis" sama dengan legacy, tidak dikerjakan M5.
3. **`dalam_negeri` tidak divalidasi [K]**; kolom NOT NULL → 1 (Dalam Negeri) bila kosong/di luar 1|2 (bukan 422).
4. **Ubah oleh role 1 pada TB disetujui** menyimpan perubahan data tetapi status tetap 1 (`status` lain → 422
   `errors.status`): status final tidak punya transisi keluar (single end node). Status 0 → 1 lewat ubah role 1 memberi efek
   final yang sama dengan approval (TB-07).
5. **Status salah → 422 `errors.status`; bukan pelaku → 403.** Pemilik yang mengubah pada/sesudah `tgl_mulai` juga 422
   `errors.status` ("hanya dapat diubah sebelum tanggal mulai").
6. **Model** `Models/Layanan/LayananModel` (BaseAuditableModel + `withActor`); `riwayat_tb/ib/pmk*` tidak memakai
   BaseSnapshotModel karena tidak punya tabel snapshot — efek final ditulis lewat model beraudit pemiliknya.
7. **`NotifikasiLayananInterface::kirim()` mengembalikan `list<int>`** id `inbox_msg`: §6.6.1 menulis tipe `void` sekaligus
   "mengembalikan daftar id untuk di-enqueue"; kontrak memakai kalimat kedua. Judul/isi notifikasi TB/IB dari tabel §6.6.2.

## Dashboard Status Layanan & PIC (MAKE-025 M6, PRD Fase 4 §6.10)

| Kelas | Isi |
|---|---|
| `LayananDashboardService` | `GET layanan/status`: `UNION ALL` empat tabel pengajuan (id, jenis, `created_at`) dengan `PegawaiScope::terapkanKeQuery` per cabang, hitung + halaman di SQL, lalu muat baris lengkap halaman itu per jenis. Tampilan lewat penyaji murni modul (tanpa aturan tahap/label kedua); PIC dari `PetugasLayananService::nipPicAktif` |
| `PetugasLayananService` (diperluas) | CRUD `petugas_layanan` + `jenisAktif()`; tulis role 1, model `PetugasLayananModel` (`LayananModel`, `withActor`) |

Model baru: `Models/Layanan/PetugasLayananModel` (whitelist `id_jenis_layanan, nip, status`), `JenisLayananModel` (baca saja).

Catatan keputusan coder M6 (dicatat untuk reviewer):

1. Route di berkas sendiri `Config/RoutesLayananDashboard.php` (pola M5).
2. `GET layanan/petugas` role 2–7 hanya PIC aktif (kolom Lihat §5.5 "PIC aktif"); role 1 semua status.
3. `url_detail` = rute FE M7; role 3 diberi "Lihat detail" hanya bila `PegawaiScope::bolehLihat` (jalur detail) — daftar
   memakai jalur daftar yang bisa lebih luas (unit 20/21, README `Libraries/Kepegawaian`).
4. Filter `status` angka wajib bersama `jenis` (422 `errors.status` bila tanpa `jenis`, keputusan reviewer CR 27-10).
5. Status PIC 10 tidak dipakai (hapus fisik [K]); daftar tetap menyaring `status != 10` untuk data impor.
6. [V2] `GET layanan/petugas/kandidat` (role 1, gap PRD §5.5, disetujui Horii 27-10): aturan kandidat satu sumber
   (`kueriKandidat`) untuk daftar dan validasi tulis. Detail modul tetap menolak role 4/5 lewat route modul (diuji
   `LayananStatusRoleTest::testDetailModulTetapMenolakRole4Dan5`), bukan hanya flag `aksi.lihat_detail`.

## Stempel UTC (CR-086)

Sesi DB berzona UTC (driver `App\Database\MySQLi`, DBV-025), jadi default/ON UPDATE kolom audit berisi UTC. Pembaca yang menampilkan tanggal pengajuan memakai `StempelUtc` (WIB): dashboard Status Layanan mengirim `tanggal_pengajuan` ISO `+07:00`; ekspor memotong tanggal WIB; surat TB/IB memakai tanggal WIB. Aturan lengkap: [`../Waktu/README.md`](../Waktu/README.md).
