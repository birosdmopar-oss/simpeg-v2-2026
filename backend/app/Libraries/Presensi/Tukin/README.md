# CR-037/CR-039 — Kalkulasi Tukin murni

Library ini menerima seluruh data dan konfigurasi sebagai argumen. Library tidak membaca DB, HTTP, session, jam sistem,
atau `web_config`; "hari ini" diinjeksikan lewat parameter `hariIni`. Kemurnian dijaga
`tests/unit/Presensi/Tukin/KalkulasiMurniTest.php`. Adapter Fase 5 akan menyediakan data dan konfigurasinya.

## Acuan

- Legacy `HEAD = 39b6b15` (Tukin identik dengan `9545385`), `application/libraries/hr/L_presensi.php`, method rekap
  **`laporan_tukin_us_skp` (:11752–13492)**. Ini jalur aktif Tukin sejak April 2024: controller
  `controllers/hr/Presensi.php:248-257` memakai `tukinVer = 'skp'` sebagai bawaan, periode ≤ Maret 2024 dialihkan ke
  `original` (`:353-361`), dan `skp` → `laporan_tukin_skp` (`:396-401`). Rekap admin "Laporan Tukin Baru"
  (`views/hr/employee/presensi/list_lt.php:8-13`, `Presensi.php:151`, `:488-566`) → `laporan_tukin_us_skp`, yang
  diekspor ke Excel untuk pembayaran (kolom TL, PSW, TK, TA, CUTI, TB, total presensi, total SKP, total potongan,
  `:13380-13392`).
- **K-1 (keputusan user 02-10-2026): v2 ikut rekap `laporan_tukin_us_skp`** bila rekap dan laporan per pegawai
  `laporan_tukin_skp` (:6510–7752) berbeda (lihat tabel perbedaan di bawah).
- CR-037 sebelumnya mengacu `laporan_tukin` (:3628). Itu **jalur lama** (tab "Uang Makan"/"Lama", periode ≤ Maret
  2024); kolom potongannya dikomentari. Bukan acuan lagi.
- Rujukan baris belum dicocokkan dengan kode produksi yang sedang berjalan (asumsi AS-09).
- Label: **[K]** ikut legacy, **[V2]** sengaja berbeda dari legacy (alasan dicatat).

## Tabel aturan

| Aturan | Sumber rekap | Label | Implementasi |
|---|---|---|---|
| Periode = baris `bkn_periode_ekinper`, atau 16 bulan lalu – 15 bulan berjalan bila tidak ada | `:11852-11876` | [K] | `PeriodeTukin::dariBulan`; periode BKN diinjeksikan dan akhirnya wajib di (tahun, bulan) yang diminta |
| Januari: awal selalu 16 Desember tahun lalu walau ada baris BKN | `:11859-11862` | [K] | `PeriodeTukin::dariBulan` |
| Loop harian dipotong ke hari ini | `:11881-11885`, `:12612-12616` | [K] | Parameter opsional `hariIni`; `null` = tidak dipotong |
| Hari kerja: libur dan Sabtu/Minggu dilewati | `:12749-12757` | [K] | `HariKerja`; libur di akhir pekan tidak dihitung ganda |
| Precedence: masa kerja → libur/akhir pekan → TB → cuti → konket → presensi | `:12739-12849` | [K] | `KalkulasiTukin::hitung` + `StatusHarian::evaluasi` |
| Jam normal 07:30–16:00, Jumat 16:30; puasa 08:00–15:00, Jumat 15:30; rentang puasa inklusif | `:12722-12725` | [K] | `JamKerja`; nilai dari `KonfigurasiTukin` |
| Selisih menit `DateInterval` (detik dibuang ke arah nol) | `:12871-12883` | [K] | `JamKerja::selisihMenit` |
| TL/PSW: 1–30 = 1, 31–60 = 2, > 60 = 3; satu arah | `:12888-12931` | [K] | `StatusHarian::golongan` |
| Kompensasi TL hadir lengkap: TL2 gugur bila `psw <= -60`, TL1 bila `psw <= -30`; TL3 tidak pernah gugur | `:12895`, `:12902` | [K] | `StatusHarian::presensi` |
| TPM = TA + denda PSW; TPP = TA + denda TL tanpa kompensasi; tanpa jam masuk & pulang = TK | `:12853-12859`, `:12933-13020` | [K] | `StatusHarian::presensi`; `rincian` per kolom TL/PSW/TA/TK/CUTI |
| **Faktor presensi 0,2** untuk TL/PSW/TA/TK | `:12854`, `:12889-13015` | [K] | `KonfigurasiTukin::potonganHarian` (tarif bp × 20/100; wajib bulat, tarif yang tidak bulat ditolak saat konstruksi) |
| Konket bebas cukup `affect_tukin = 1`; `kategori = 13` hanya uang makan | `:12346`, `:12828-12846` | [K] | `StatusHarian::konketBebas` |
| **Tanpa potongan LKH** (`total_lkh` tidak pernah ditambah) | `:12654` | [K] | LKH dihapus dari kunci wajib, status harian, dan total |
| Cuti sakit: 1% × 0,2 per hari kerja mulai hari ke-15 berurutan | `:12796-12804` | [K] | `CUTI_SAKIT` × faktor |
| Urutan cuti sakit naik pada cuti sakit, direset **hanya** oleh baris presensi; cuti lain, konket, TB, libur tidak memutus | `:12798`, `:12828-12849` | [K] (K-1) | `KalkulasiTukin::hitung` |
| Lookback cuti sakit 1 periode: periode lalu = 16 dua bulan lalu – 15 bulan lalu; dibawa hanya bila hari kerja terakhir periode lalu **dan** hari kerja pertama periode ini sakit; di periode lalu hari selain cuti sakit memutus | `:11890-11891`, `:12686-12697` | [K] | `KalkulasiTukin::sakitSebelumPeriode` |
| Cuti tahunan, besar, melahirkan, alasan penting, CLTN tanpa potongan harian | `:12807-12820` | [K] | `StatusHarian::evaluasi` |
| **Cuti alasan penting tidak dipotong** (`$listPotonganCuti` dihitung tetapi tidak dipakai) | `:12415-12466` | [K] | Jadwal alasan penting dihapus |
| **Tugas belajar tanpa potongan 25%** (`$potonganTB` tidak pernah ditambahkan; `$isPegTB` direset tiap hari sehingga tidak berefek) | `:12296-12330`, `:12720`, `:12761-12778` | [K] | Hari TB bebas; potongan TB dan `isPegTB` dihapus |
| **Pemutihan TB**: bila tanggal terakhir yang diproses berstatus TB atau hari kerja terakhirnya TB, total = 0 | `:12726-12735`, `:13026-13030` | [K] (K-1) | Penanda di-set pada tanggal TB apa pun (termasuk akhir pekan) dan direset oleh hari kerja tanpa TB |
| **Cuti besar 5 / 5 / 5**: tanggal potong ke-1 = tgl 16 bulan `mulai` bila hari ≥ 16, selain itu tgl 16 bulan sebelumnya; ke-2/ke-3 bila `lama_cuti` > 35 / > 65; hanya cuti yang beririsan dengan periode | `:12470-12523` | [K] | `PotonganCutiPeriode`; berlaku bila tanggal potong = awal periode |
| **Cuti melahirkan 40 / 70 / 80**, hanya bila jumlah anak > 3; jadwal sama dengan cuti besar | `:12527-12575` | [K] | `PotonganCutiPeriode`; jumlah anak dari `DataPegawaiTukin::jumlahAnak` |
| Jumlah anak = `detail_anak` dengan `tgl_lahir` ≤ akhir periode pada `riwayat_keluarga` aktif | `:12170-12198` | [K] (K-1) | Dihitung adapter |
| **SKP periodik**: "sangat baik"/"baik"/"butuh perbaikan" = 0, "kurang" = 16, lainnya atau tidak ada data = 32 (`strtolower`, tanpa trim) | `:12394-12410`, `:12636` | [K] (K-1) | `DataPegawaiTukin::potonganSkp`; tarif `SKP_KURANG`/`SKP_TIDAK_ADA` |
| **Cuti sepanjang periode**: semua hari kerja (dalam masa kerja) cuti jenis apa pun → SKP = 0; tanpa hari kerja juga dianggap sepanjang periode (`0 == 0`) | `:12664-12680` | [K] | `KalkulasiTukin::cutiSepanjangPeriode` |
| **Urutan pengganti total**: pemutihan TB (0) → cuti besar (presensi = 5, SKP 0) → cuti melahirkan (total = 40/70/80) → cuti sepanjang periode (SKP 0) → presensi + SKP | `:13026-13057` | [K] | `HasilTukin::alasan` |
| Total tanpa batas 100% | `:13053-13057` | [K] | — |
| Pegawai baru: hari sebelum TMT (`tmtsk_pangkat`) dilewati tanpa potongan, bila CPNS (`gol_ruang` diawali "CPNS") atau PPPK dengan TMT setelah hari kerja pertama | `:12699-12716`, `:12738-12742` | [K] | `DataPegawaiTukin::tmtMasuk` (syarat CPNS/PPPK diputuskan adapter) |
| Pegawai keluar (`status = 2`) di tengah periode: hari ≥ `tmt_status` dikecualikan | Rekap memasukkan pegawai bila `tmt_status > awal periode` (`L_employee.php:6414`), tetapi presensi/cuti/TB/konket hanya dimuat bila `tmt_status > akhir periode` (`:12209` dst.), sehingga semua hari kerja menjadi TK | **[V2]** | `DataPegawaiTukin::tmtKeluar`. Perbaikan bug: legacy memotong TK pada hari yang masih masa kerja |
| Potongan dalam integer basis poin | — | [V2] | Lihat "Presisi" |

Tarif yang di rekap di-hard-code menjadi kunci konfigurasi opsional dengan nilai bawaan rekap
(`KonfigurasiTukin::TARIF_BAWAAN`): `CUTI_SAKIT` = 1 (× 0,2), `CUTI_BESAR_1..3` = 5, `CUTI_MELAHIRKAN_1..3` =
40/70/80, `SKP_KURANG` = 16, `SKP_TIDAK_ADA` = 32. Kunci `web_config` wajib: `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`,
`TA`, `TK` (`LKH` tidak dipakai).

## Kontrak data

- `KalkulasiTukin::hitung(PeriodeTukin, list<string> $hariLibur, array $harian, KonfigurasiTukin, ?string $hariIni = null, ?DataPegawaiTukin $pegawai = null)`.
- Input harian per tanggal: `tugas_belajar` (`true` atau array), `cuti` (`{jenis, mulai?, akhir?, lama?}`), `konket`
  (`{affect_tukin, kategori?}`), `presensi` (`{masuk?, pulang?}`).
- Jenis cuti: `tahunan` (1), `besar` (2), `sakit` (3), `melahirkan` (4), `alasan_penting` (5), `cltn` (6) sesuai
  `id_jenis_cuti` legacy. Jenis lain ditolak. Cuti besar dan melahirkan wajib `mulai`/`akhir`/`lama`
  (`riwayat_cuti.lama_cuti`).
- Adapter menyertakan: periode sebelumnya (16 dua bulan lalu – 15 bulan lalu) untuk urutan cuti sakit; seluruh hari
  periode (termasuk setelah `hariIni`) untuk cek cuti sepanjang periode dan irisan cuti besar/melahirkan; libur pada
  rentang tersebut.
- `DataPegawaiTukin`: `predikatSkp` (`riwayat_skp_periodik.hasil_akhir` periode BKN; `null` bila tidak ada baris BKN
  atau tidak ada data), `jumlahAnak`, `tmtMasuk`, `tmtKeluar`.
- `HasilTukin`: `hariKerja`, `jumlah` (TL1–3, PSW1–3, TPM, TPP, TK, CS), `rincian` (TL, PSW, TK, TA, CUTI),
  `potonganPresensi` (jumlah harian sebelum pengganti), `totalPresensi`, `totalSkp`, `totalPotongan`, `alasan`
  (`pemutihan_tb`, `cuti_besar`, `cuti_melahirkan`, `cuti_sepanjang_periode`, atau `null`), dan `harian`.

## Presisi dan pembulatan

- Tarif dimasukkan dalam **persen** seperti `web_config` legacy (int atau string desimal, titik atau koma), lalu
  dikonversi **tepat** ke basis poin integer (1% = 100 bp) lewat parsing string. Float ditolak; nilai dengan lebih dari
  dua desimal atau negatif ditolak, bukan dibulatkan.
- Faktor 0,2 diterapkan sebagai `bp × 20 / 100`. Bila hasilnya tidak bulat (mis. tarif `0.01`), konstruksi
  `KonfigurasiTukin` melempar `InvalidArgumentException`; tidak ada pembulatan diam-diam.
- Setelah konversi, seluruh perhitungan adalah penjumlahan integer. Total tidak dibatasi 100%.

## Perbedaan rekap (acuan) dengan laporan per pegawai `laporan_tukin_skp`

| Aturan | Rekap `laporan_tukin_us_skp` (acuan, K-1) | Per pegawai `laporan_tukin_skp` |
|---|---|---|
| Pemutihan TB | Hari kerja terakhir yang diproses masih TB (`:12726-12735`) | TB menutup seluruh periode (`:6654-6702`, `:7660-7665`) |
| SKP tanpa data | Selalu 32 (`:12636`) | 32 hanya setelah batas pengisian BKN lewat, sebelumnya 0 "Data Belum Tersedia" (`:6854-6880`) |
| Jumlah anak cuti melahirkan | `detail_anak` lahir s.d. akhir periode (`:12170-12198`) | `SUM(riwayat_keluarga.jumlah_anak)` (`:6886-6893`) |
| Konket memutus urutan cuti sakit | Tidak (`:12828-12846`) | Ya (`:7425`) |
| Dipotong sampai hari ini | Ya (`:11881-11885`) | Ya (`:6640-6643`, `:7124-7128`) — tidak berbeda |

Perhitungan lain (faktor 0,2, tanpa LKH, tanpa TB 25%, cuti besar/melahirkan, urutan pengganti, cuti sepanjang
periode, tanpa batas 100%) sama di kedua method.

## Perbedaan disengaja [V2]

1. **Pegawai keluar di tengah periode**: hari kerja ≥ `tmt_status` dikecualikan (bug rekap, lihat tabel).
2. **Cuti sepanjang periode** dihitung atas hari kerja periode tukin (`PeriodeTukin`, tidak dipotong `hariIni`) dalam
   masa kerja `DataPegawaiTukin`. Rekap menghitungnya atas `perBKN_st..perBKN_en` (`:11960-11974`): tanpa override
   Januari, dan bila tidak ada baris BKN rentangnya menjadi "hari ini" saja (`new DateTime('')`), sehingga hasilnya
   bergantung pada tanggal ekspor. Rekap juga memakai `tmtsk_pangkat` untuk **semua** pegawai (`:12668`), sehingga
   kenaikan pangkat di tengah periode ikut memangkas hari yang dihitung; v2 hanya memakai TMT masuk pegawai baru.
3. **Rentang puasa dari konfigurasi** dan divalidasi (tanggal valid, mulai ≤ selesai), bukan hard-code per tahun
   (`:12588-12601`). Nilainya data operasional per tahun; tempat simpan diputuskan Fase 5.
4. **Integer basis poin** untuk tarif dan potongan, bukan float.
5. **Daftar NIP penyesuaian tukin** yang di-hard-code (`:11755-11805`, hanya keterangan "+X%"/"-X%") tidak di-port;
   itu data, bukan aturan.
6. **Konfigurasi wajib lengkap**: kunci `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK` wajib ada; legacy diam-diam
   memakai nilai kosong.

Tidak di-port karena di luar lingkup kalkulasi potongan: uang makan, varian `perbaikan`/`original`, varian Instansi
Lain (`laporan_tukin_us_skp_il`), dan method yang tidak dipanggil controller aktif (`laporan_tukin_us_skp_2`,
`laporan_tukin_skp_2`, `rekap_full_xls_*`).

## Pertanyaan

Terjawab dari legacy (CR-039). Pertanyaan 1–11 README CR-037 dijawab dari jalur aktif di atas; satu-satunya
pertentangan antarjalur (K-1) diputuskan user pada 02-10-2026: ikut rekap. Tidak ada pertanyaan terbuka untuk Biro
SDM/Keuangan.

Uji memakai data sintetis saja. Implementasi ini tidak mencakup tabel, endpoint, uang makan, atau PDF/Excel.
