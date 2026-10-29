# Pertanyaan & keputusan terbuka UP-1 (untuk Horii)

Dijawab sebelum Runbook langkah 7. Jawaban yang memuat host, akun, atau lokasi pribadi **tidak** ditulis di repo.
Cukup tulis "sudah diberikan ke DBA, tanggal …".

| No | Pertanyaan | Usulan | Jawaban |
|---|---|---|---|
| P-01 | Siapa DBA UP-1? | DBV, bila diberi izin memegang salinan data nyata. Usulan ini **ditunda** per 29-10-2026 | **DBV** (DB Validator) ditetapkan Horii 29-10-2026 sebagai DBA UP-1 (DBA = Database Administrator, pelaksana operasi data; di proyek ini DBV biasanya me-review skema DBV-###) |
| P-02 | Akun **baca-saja** ke DB server dev SIMPEG: siapa yang membuat, untuk siapa, dan kapan dicabut? | Akun khusus UP-1 dengan hak `SELECT` + `SHOW VIEW` pada skema SIMPEG saja, dicabut setelah UP-1 | Akses sudah ada pada DBV (29-10-2026). **Masih terbuka:** apakah akun yang dipakai baca-saja, dan kapan akses dicabut/ditinjau setelah UP-1 |
| P-03 | Persetujuan memakai data server dev (salinan produksi, berisi data pribadi) untuk UP-1? | Disetujui untuk rentang 2 periode + tabel di Runbook bagian c saja | |
| P-04 | Di mana salinan disimpan, dan berapa lama? | Satu folder lokal di mesin DBA, terenkripsi bila memungkinkan, di luar repo/cloud; dihapus segera setelah UP-1 lolos | |
| P-05 | Dua periode yang diuji? | Bulan Tukin September & Oktober 2026 (rentang data `UP1_AWAL` 16-07 s.d. 15-10-2026 karena v2 membaca sejak periode sebelumnya; lookback cuti/izin sejak 16-01-2026); dijalankan setelah 15-10 | |
| P-06 | **GAP-1:** alat impor legacy → v2 belum ada di repo (hanya aturan tertulis). Pilih jalur | (1) DBA menulis SQL `INSERT…SELECT` sekali pakai mengikuti aturan DBV-011/022/023; (2) kartu MAKE baru untuk alat impor resmi yang dipakai ulang di cutover; (3) UP-1 menunggu alat cutover. Disarankan **(2)**: UP-1 sekaligus menjadi gladi impor cutover | |
| P-07 | Apakah aplikasi legacy di server dev siap dipakai untuk ekspor rekap Tukin SKP, dan siapa yang memegang akun legacy untuk ekspor? | DBA atau pegawai Biro SDM yang punya akses rekap | |
| P-08 | Jadwal: kapan DBA, akun, dan data tersedia? Berapa lama slot UP-1? | ±3 hari kerja setelah P-01..P-07 terjawab, belum termasuk perbaikan bug (c) | |
| P-09 | Isi `penyesuaian_tukin` untuk rentang uji: siapa yang menyiapkan daftarnya dari legacy tanpa membuka `constants.php`? | Ekstraktor redaksi khusus (pola verifikasi kop MAKE-041) yang mengeluarkan hanya daftar penyesuaian, dijalankan reviewer CR dengan izin Horii; `pack_legacy.py` sudah tidak ada | |
