# Perbandingan P-06 — jalur impor legacy → v2 untuk UP-1

**Keputusan Horii 29-10-2026: Opsi 2.** Alat impor resmi dibuat lewat kartu MAKE, dengan cakupan awal jalur Tukin.
UP-1 sekaligus menjadi gladi impor cutover.

## Latar

Aturan impor sudah tertulis di dokumen berikut:

- DBV-011 §2.4–2.12 (tier, audit/dedupe, zona waktu R1a/R1b, counter AUTO_INCREMENT, sesi `time_zone='+00:00'` + strict);
- DBV-022 §5/§13.1 (cuti/TB, normalisasi sisa cuti);
- DBV-023 §7 (`dh_online`);
- DBV-024 §5 (`penyesuaian_tukin` lewat CRUD);
- B-01/B-02 §6 (pegawai & riwayat).

Repo **belum punya alat** yang menjalankan aturan itu (GAP-1). UP-1 butuh sekitar 35 tabel jalur Tukin; cutover butuh semua tabel.

## Opsi 1 — SQL sekali pakai oleh DBA

| | |
|---|---|
| Kapan cocok | UP-1 harus cepat dan alat cutover direncanakan terpisah |
| Saat UP-1 | DBA menulis `INSERT … SELECT` lokal dari skema salinan ke DB scratch |
| Saat cutover | Semua ditulis ulang sebagai alat resmi |
| Gap & risiko | Hasil paritas mungkin tidak sah untuk cutover, karena R1a/R1b, pembersihan `dh_online` tanpa jam, dan dedupe sisa cuti bisa berbeda dari alat cutover. Skrip tidak direview dan sulit diulang. Risiko data sedang (skrip/log lokal) |
| Pelaku | DBA |
| Dampak Fase 6 | Terbuka setelah data siap; pekerjaan impor dikerjakan dua kali |

## Opsi 2 — kartu MAKE alat impor resmi (gladi cutover) ✅ dipilih

| | |
|---|---|
| Kapan cocok | UP-1 harus membuktikan "v2 + data hasil impor = legacy" untuk hari H |
| Saat UP-1 | Coder membuat alat impor di repo (command `spark` / SQL transformasi), dengan test data sintetis dan review CR + DBV; DBA menjalankannya ke DB scratch |
| Saat cutover | Alat yang sama dijalankan pada dump final |
| Gap & risiko | Pekerjaan terbesar; cakupan awal sekitar 35 tabel jalur Tukin. Mungkin perlu kartu DBV bila ada penyesuaian skema. Hasil sah untuk cutover, bisa diulang, dan diaudit lewat skrip audit DBV-022/023/024. Risiko data paling rendah: kode ada di repo, data hanya di mesin DBA |
| Pelaku | Coder membuat alat, lalu DBA menjalankannya |
| Dampak Fase 6 | Terbuka setelah alat selesai dan dijalankan; pekerjaannya tetap terpakai saat cutover |

## Opsi 3 — menunggu alat cutover

| | |
|---|---|
| Kapan cocok | Fase 6 boleh ditunda lama |
| Saat UP-1 | Belum ada yang dikerjakan sampai alat cutover jadi |
| Saat cutover | Sama dengan opsi 2, tetapi UP-1 jatuh di akhir proyek |
| Gap & risiko | Alat cutover belum dijadwalkan; bug Tukin (c) baru ketahuan menjelang go-live |
| Pelaku | Belum ada sekarang; total sama dengan opsi 2 |
| Dampak Fase 6 | Terkunci tanpa batas waktu yang jelas; bertentangan dengan keputusan "UP-1 di antara Fase 5 dan 6" |

## Ringkasan

| | Opsi 1 | **Opsi 2** | Opsi 3 |
|---|---|---|---|
| Hasil sah untuk cutover | Tidak pasti | **Ya** | Ya |
| Bisa diulang & direview | Rendah | **Tinggi** | Tinggi |
| Risiko keamanan data | Sedang | **Rendah** | Rendah |
| Pekerjaan dobel saat cutover | Ya | **Tidak** | Tidak |
| Fase 6 terbuka | Setelah data siap | Setelah alat selesai | Tidak jelas |

## Alasan pemilihan

Paritas UP-1 hanya sah bila data diimpor dengan alat yang sama dengan cutover. Waktu tambahan dibanding opsi 1 bukan
pemborosan, karena alat itu tetap harus dibuat untuk cutover. DBA bisa menyiapkan salinan dan audit secara paralel
selama alat dibuat.
