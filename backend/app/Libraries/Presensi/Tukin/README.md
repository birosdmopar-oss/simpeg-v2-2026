# CR-037 — Kalkulasi Tukin murni

Library ini menerima seluruh data dan konfigurasi sebagai argumen. Library tidak membaca DB, HTTP, session, jam sistem, atau `web_config`; adapter Fase 5 akan menyediakannya kemudian.

## Aturan dan sumber

| Aturan | Sumber | Keputusan |
|---|---|---|
| Periode default tanggal 16 bulan sebelumnya–15 bulan berjalan | [K] baseline `L_presensi.php:5163-5201` | Ikut, tetapi periode BKN diinjeksikan |
| Hari kerja Senin–Jumat dikurangi hari libur | [V2] PRD TK-FR-02 | Perbaiki agar libur akhir pekan tidak dihitung ganda |
| Jam normal 07:30–16:00, Jumat 16:30 | [K] baseline `L_presensi.php:~202-267` | Ikut |
| Jam puasa dan rentangnya | [K] baseline `L_presensi.php:~197-205` | Perbaiki: seluruh nilai di konfigurasi |
| Precedence TB → cuti → konket → libur → presensi | [K] baseline `L_presensi.php:~1950-2080` dan [V2] TK-FR-04 | Eksplisit untuk mencegah status bertumpuk ambigu |
| TL/PSW 1–30, 31–60, >60 menit | [K] baseline `L_presensi.php:~2157-2220`, [V2] TK-FR-10/11 | Ikut batas inklusif |
| Konket bebas potongan | [K] baseline kategori 13 + `affect_tukin=1` | Ikut keduanya, bukan `affect_tukin` saja |
| Tarif | [K] baseline `L_presensi.php:~1483` | Diinjeksikan dan divalidasi |

## Precedence

`tugas_belajar` → `cuti` → `konket(affect_tukin=1,kategori=13)` → `libur` → `presensi`. Hari tanpa status/presensi menjadi TK. Presensi sebagian menjadi TPM/TPP dan mengenakan TA plus denda keterlambatan terkait.

## Perbedaan dan pertanyaan terbuka

Legacy meng-hard-code rentang puasa dan memiliki varian laporan yang berulang; CR-037 memakai konfigurasi tunggal. Cuti sakit setelah hari ke-14 diberi potongan 1 poin per hari berdasarkan baseline, sedangkan cuti besar/melahirkan memakai 40/70/80% dari PRD karena implementasi legacy yang dibaca berupa blok komentar. Biro SDM/Keuangan perlu menetapkan: basis 40/70/80% (bulan kalender atau periode Tukin), definisi berurutan cuti sakit (kalender atau hari kerja), perlakuan pegawai masuk/pensiun di tengah periode, dan apakah varian SKP/perbaikan memakai tarif berbeda.

Uji memakai data sintetis saja. Implementasi ini tidak mencakup tabel, endpoint, uang makan, PDF/Excel, atau varian laporan.
