# CR-037 — Kalkulasi Tukin murni

Library ini menerima seluruh data dan konfigurasi sebagai argumen. Library tidak membaca DB, HTTP, session, jam sistem,
atau `web_config`; "hari ini" diinjeksikan lewat parameter `hariIni`. Kemurnian dijaga
`tests/unit/Presensi/Tukin/KalkulasiMurniTest.php`. Adapter Fase 5 akan menyediakan data dan konfigurasinya.

## Acuan

- Baseline legacy `9545385`, `application/libraries/hr/L_presensi.php`, method aktif **`laporan_tukin` (:3628–5054)**.
  Method `rekap_full_xls_2222` bukan acuan.
- **Baseline 9545385 belum dicocokkan dengan kode produksi** yang sedang berjalan (asumsi AS-09). Setiap aturan di bawah
  bersumber dari baseline tersebut.
- Di baseline, kolom `potongan_absen` dan `potongan_lkh` baris presensi pada `laporan_tukin` **dikomentari**
  (:4801-4804, :4885-4888, :4943-4946, :5001-5004); laporan aktif hanya menampilkan uang makan, sedangkan rumus TL/PSW/
  TPM/TPP tetap dihitung (:4825-4993). Rumus potongan diambil dari ekspresi tersebut dan dicocokkan dengan
  `laporan_tukin_us_skp` (:11752), method rekap yang dipanggil controller.
- Label: **[K]** ikut legacy, **[V2]** sengaja berbeda dari legacy (alasan dicatat).

## Tabel aturan

| Aturan | Sumber baseline | Label | Implementasi |
|---|---|---|---|
| Periode 16 bulan sebelumnya – 15 bulan berjalan, atau periode BKN | `:5162-5180` (`laporan_tukin_skp_2`) | [V2] | `PeriodeTukin::dariBulan`. `laporan_tukin` sendiri memakai bulan kalender (:4046-4054); PRD S-1 menetapkan 16–15. Periode BKN diinjeksikan dan akhirnya wajib berada di (tahun, bulan) yang diminta |
| Hari kerja: libur dan Sabtu/Minggu disingkirkan paling awal | `:4611-4641` | [K] | `HariKerja`; libur di akhir pekan tidak dihitung ganda |
| Batas akhir = hari ini (inklusif) | `:4051-4053` | [K] | Parameter `hariIni` |
| Jam normal 07:30–16:00, Jumat 16:30; puasa 08:00–15:00, Jumat 15:30 | `:4597-4600` | [K] | `JamKerja`; nilai dari `KonfigurasiTukin` |
| Rentang puasa inklusif | `:4069-4070`, `:4597` | [V2] | Dari konfigurasi (`puasaMulai`/`puasaSelesai`, divalidasi), bukan hard-code per tahun |
| Precedence: libur/akhir pekan → TB → cuti → konket → presensi | `:4611-4810` | [K] | `StatusHarian::evaluasi` + `KalkulasiTukin` |
| Konket bebas potongan cukup `affect_tukin = 1` | `:4758` | [K] | `kategori = 13` hanya untuk uang makan (`:4763`), tidak memengaruhi potongan. Konket `affect_tukin = 2` diteruskan ke penilaian presensi |
| Selisih menit: `DateInterval` (detik dibuang ke arah nol) | `:4835-4847` | [K] | `JamKerja::selisihMenit`; jam menerima `HH:MM` dan `HH:MM:SS` |
| TL: 1–30 = TL1, 31–60 = TL2, > 60 = TL3 | `:4851-4864` | [K] | Batas inklusif 30 → TL1, 31 → TL2, 60 → TL2, 61 → TL3 |
| Kompensasi TL oleh jam pulang | `:4856`, `:4861` | [K] | TL2 gugur bila pulang ≥ 60 menit setelah standar (`psw <= -60`); TL1 gugur bila ≥ 30 menit (`psw <= -30`); TL3 tidak pernah gugur |
| PSW analog, satu arah | `:4866-4877` | [K] | Pulang lebih lambat / datang lebih awal tidak didenda |
| TPM = TA + denda PSW; TPP = TA + denda TL (tanpa kompensasi) | `:4910-4935`, `:4968-4993` | [K] | Denda dicatat juga di kolom PSW/TL (`kategori`) |
| TK = tarif TK | `:4803` | [K] | Hari kerja tanpa presensi dan tanpa status pembebas |
| LKH: tarif LKH bila wajib (sub group jabatan ≠ 1) dan LKH belum disetujui | `:4012`, `:4804`, `:4888`, `:4946`, `:5004` | [K] | Hanya pada baris presensi (TK, hadir, TPM, TPP); input `wajibLkh`, `lkhTerisi` |
| Cuti sakit: 1% per hari mulai hari kerja berurutan ke-15 | `:4671-4693` | [K] | Urutan dihitung internal per hari kerja; libur/akhir pekan/TB tidak memutus, cuti lain/konket (apa pun `affect_tukin`)/presensi memutus (`:4681`, `:4742`, `:4779`). Urutan dari sebelum periode dibaca dari input harian sebelum `awal` (`:4573-4590`) |
| Cuti alasan penting: lama > 14 hari → 50% sekali | `:4310-4356` | [K] | Pada hari kerja pertama bulan dengan hari kerja cuti terbanyak |
| Cuti besar: bulan ke-1/2/3 = 50/75/90%, ke-2 bila > 35 hari kalender, ke-3 bila > 65 | `:4430-4478` | [K] | Sekali per bulan pada tanggal 1; bulan ke-1 = bulan mulai bila tanggal mulai ≤ ⌊hari dalam bulan ÷ 2⌋, selain itu bulan berikutnya (`:4436-4447`) |
| Cuti melahirkan anak ke-4 dst.: 60/30/20%, ke-2 bila lama ≥ 32, ke-3 bila ≥ 62 | `:4488-4548` | [K] | Syarat `anak_sebelumnya >= 3` (`:4501`); anak ke-1 s.d. ke-3 tidak dipotong |
| Cuti tahunan dan CLTN tidak dipotong | `:4725-4733` | [K] | — |
| Tugas belajar: 25% sekali per bulan kalender; bulan mulai bebas bila mulai > tanggal 1 (kecuali perpanjangan) | `:4136-4178`, `:4645-4652` | [K] | Setelah potongan TB pertama (`isPegTB`), seluruh hari berikutnya dan potongan cuti bulanan berikutnya dikecualikan (`:4605`, `:4775`) |
| Total = presensi + LKH + TB + cuti | view `laporan_tukin.php:293-295` | [K] | `HasilTukin::totalPotongan`; `totalPresensi` dan `totalPresensiLkh` mengikuti kolom `total_presensi`/`total_presensi_lkh` (`:12653-12656`) |
| Potongan dalam integer basis poin | — | [V2] | Lihat "Presisi" |

Tarif yang di legacy di-hard-code (cuti, TB) menjadi kunci konfigurasi opsional dengan nilai bawaan baseline
(`KonfigurasiTukin::TARIF_BAWAAN`): `CUTI_SAKIT`, `CUTI_ALASAN_PENTING`, `CUTI_BESAR_1..3`, `CUTI_MELAHIRKAN_1..3`, `TB`.

## Precedence (urutan tertulis)

Per tanggal kalender dalam `[awal, min(akhir, hariIni)]`:

1. Potongan cuti bulanan yang dijadwalkan pada tanggal itu, bila belum `isPegTB`.
2. Hari libur atau Sabtu/Minggu → dilewati (tidak dihitung hari kerja).
3. Tugas belajar → tanpa potongan harian; potongan TB bulanan; set `isPegTB`.
4. Bila `isPegTB` → hari dikecualikan (`dikecualikan_tb`).
5. Cuti (jenis apa pun) → potongan hanya cuti sakit hari ke-15+.
6. Konket `affect_tukin = 1` → tanpa potongan.
7. Presensi: tanpa jam masuk & pulang → TK; lengkap → TL/PSW; salah satu → TPM/TPP. LKH ditambahkan di langkah ini.

## Kontrak data

- `KalkulasiTukin::hitung(PeriodeTukin, list<string> $hariLibur, array $harian, KonfigurasiTukin, ?string $hariIni)`.
- Input harian per tanggal: `tugas_belajar` (`true` atau `{id?, mulai?, perpanjangan?}`), `cuti`
  (`{jenis, mulai?, akhir?, lama?, anak_sebelumnya?}`), `konket` (`{affect_tukin, kategori?}`), `presensi`
  (`{masuk?, pulang?}`), `wajibLkh`, `lkhTerisi`.
- Jenis cuti: `tahunan` (1), `besar` (2), `sakit` (3), `melahirkan` (4), `alasan_penting` (5), `cltn` (6) sesuai
  `id_jenis_cuti` legacy (`rwy/L_cuti.php:295-375`). Jenis lain ditolak.
- Cuti besar wajib `mulai`/`akhir`; melahirkan wajib `mulai`/`akhir`/`lama`/`anak_sebelumnya`; alasan penting wajib
  `mulai`/`akhir`/`lama` (`lama` = `riwayat_cuti.lama_cuti`).
- Input harian boleh memuat tanggal di luar periode (untuk urutan cuti sakit dan riwayat cuti bulanan); `hariLibur` perlu
  mencakup rentang tersebut.
- `HasilTukin`: `hariKerja`, `jumlah` (TL1–3, PSW1–3, TPM, TPP, TK, LKH, CS), `potonganPresensi`, `potonganLkh`,
  `potonganTb`, `potonganCuti`, `totalPresensi`, `totalPresensiLkh`, `totalPotongan`, dan `harian` per hari kerja.

## Presisi dan pembulatan

- Tarif dimasukkan dalam **persen** seperti `web_config` legacy (int atau string desimal, titik atau koma), lalu
  dikonversi **tepat** ke basis poin integer (1% = 100 bp) lewat parsing string. Float ditolak; nilai dengan lebih dari
  dua desimal atau negatif ditolak, bukan dibulatkan.
- Setelah konversi, seluruh perhitungan adalah penjumlahan integer, sehingga tidak ada pembulatan. Total tidak dibatasi
  100% (legacy juga tidak membatasi).

## Perbedaan antarvarian legacy (untuk perbandingan)

| Aturan | `laporan_tukin` (acuan) | `laporan_tukin_us_skp` (:11752, rekap) | `laporan_tukin_us_skp_2` (:9669, tidak dipanggil controller) |
|---|---|---|---|
| Periode | Bulan kalender | 16–15 / BKN | 16–15 / BKN |
| Tarif TL/PSW/TA/TK | `web_config` | `web_config` × 0,2 (:12854 dst.) | — |
| Cuti besar per bulan | 50 / 75 / 90 | 5 / 5 / 5 pada tanggal 16 (:12474-12523) | 25 / 25 / 25 (:10602-10663) |
| Cuti melahirkan per bulan | 60 / 30 / 20, anak sebelumnya ≥ 3 | 40 / 70 / 80, anak > 3 (:12527-12575) | 40 / 70 / 20, anak ≥ 3 (:10670-10720) |
| Syarat bulan ke-2/3 | besar > 35/65 hari kalender; melahirkan lama ≥ 32/62 | lama > 35/65 | sama dengan acuan |

PRD TK-FR-08 menyebut 40/70/80% (sama dengan `laporan_tukin_us_skp` untuk melahirkan). Library memakai angka acuan
`laporan_tukin` sebagai bawaan; angka lain cukup diinjeksikan lewat konfigurasi.

## Perbedaan disengaja [V2]

1. **Periode 16–15** (PRD S-1), bukan bulan kalender seperti `laporan_tukin`. Akibatnya TB yang aktif sepanjang periode
   terpotong dua kali (bulan kalender awal dan akhir periode), karena aturan TB legacy per bulan kalender.
2. **Rentang puasa dari konfigurasi** dan divalidasi (tanggal valid, mulai ≤ selesai), bukan hard-code per tahun
   (`:4059-4070`).
3. **Integer basis poin** untuk tarif dan potongan, bukan float/string persen.
4. **Pengecualian per ID riwayat cuti** yang di-hard-code (`:4370-4426`) dan daftar NIP
   penyesuaian SKP (`:3643-3711`) tidak di-port; keduanya data, bukan aturan.
5. **Varian `tukinVer = perbaikan`** (semua konket membebaskan, `:4748-4756`) dan varian SKP/US belum di-port (PRD X-4).
6. **Konfigurasi wajib lengkap**: kunci `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK`, `LKH` wajib ada; legacy diam-diam
   memakai nilai kosong.

## Pertanyaan untuk Biro SDM/Keuangan

1. **Periode**: laporan memakai 16–15/BKN (PRD) atau bulan kalender (`laporan_tukin`)? Bila 16–15, apakah TB dan cuti
   bulanan tetap dihitung per bulan kalender (TB bisa terpotong dua kali per periode)?
2. **Tarif cuti besar/melahirkan**: mana yang berlaku — 50/75/90 & 60/30/20 (`laporan_tukin`), 5/5/5 & 40/70/80
   (`laporan_tukin_us_skp`), 25/25/25 & 40/70/20 (`laporan_tukin_us_skp_2`), atau 40/70/80 (PRD)? Nilainya persen
   tukin. Syarat melahirkan: anak sebelumnya ≥ 3 atau > 3?
3. **Tarif rekap × 0,2**: `laporan_tukin_us_skp` mengalikan tarif TL/PSW/TA/TK dengan 0,2. Apakah faktor ini berlaku
   untuk rekap pembayaran?
4. **Kompensasi TL**: TL1 gugur bila pulang ≥ 30 menit lebih lambat, TL2 bila ≥ 60 menit. Apakah juga berlaku untuk
   TPP (legacy: tidak) dan hari Jumat/puasa (legacy: ya, terhadap jam standar hari itu)?
5. **Rentang puasa**: sumber resminya (kunci `web_config` per tahun atau tabel tersendiri), dan apakah kedua ujung
   inklusif?
6. **Tugas belajar**: PRD TK-FR-05 menyebut TB bebas potongan, legacy memotong 25% per bulan dan membebaskan bulan mulai
   bila TB dimulai setelah tanggal 1. Mana yang berlaku? Apakah setelah TB seluruh hari berikutnya dalam periode
   memang dikecualikan (`isPegTB`)?
7. **Cuti sakit**: ambang hari ke-15 dihitung per hari kerja (legacy) atau hari kalender; tarif 1% per hari tetap?
8. **Cuti alasan penting**: legenda view menyebut 1,75% per hari maksimal 25% per bulan, kode memotong 50% sekali
   bila lama > 14 hari. Mana yang berlaku?
9. **LKH**: potongan per hari kerja bila LKH belum disetujui (status menunggu dihitung belum terisi). Apakah LKH juga
   dikenakan pada hari TK/TPM/TPP (legacy: ya)?
10. **Batas total**: apakah total potongan dibatasi 100%?
11. Perlakuan hari kerja di luar masa kerja pegawai (masuk/pensiun di tengah periode).

Uji memakai data sintetis saja. Implementasi ini tidak mencakup tabel, endpoint, uang makan, PDF/Excel, atau varian
laporan.
