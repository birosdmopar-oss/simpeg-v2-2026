# Modul E — Halo Simpeg (Fase 8)

Service/business logic modul ini (`App\Libraries\HaloSimpeg\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

Kontrak API beku: `backend/app/Controllers/Api/HaloSimpeg/README.md` (master MAKE-070, tiket MAKE-071, push MAKE-072, ekspor
MAKE-074). Chatbot "Vanya" punya folder sendiri (`Chatbot/`, README sendiri). Skema: DBV-028 (helpdesk). Acuan: PRD Fase 8 v1.4.

| Kelas | Paket | Isi |
|---|---|---|
| `MasterHaloSimpeg` | MAKE-070 | basis service: status 0/1, `teksDari`, galat per kolom, `dalamTransaksi` (`transException` + reset), `kunci` (FOR UPDATE urut id), `galatDb`, waktu UTC → ISO `Z` |
| `KategoriService`, `KontakService`, `PetugasService` | MAKE-070 | master kategori, kontak helpdesk, petugas (kandidat F8-07 lewat `WebConfigService::satkerKepegawaian()`, CR-139; `petugas/saya` + `bisa_ekspor`) |
| `AksesTiket` | MAKE-071 | kewenangan objek tiket satu pengguna (BP8-2): pemilik, petugas aktif kategori (S12), flag `bisa_*` = hasil aksi; tiket NULL tanpa petugas (H8-16 (a1)) |
| `TiketService` | MAKE-071 | daftar, detail, buat, balas, baca, tutup, hapus, unduh lampiran; penanda baca §6.2; lampiran lewat `PenjagaBerkas` |
| `RingkasanTiket` | MAKE-071 | `RingkasanTiketInterface` untuk lonceng F-08 (`tiket_belum_dibaca` = `total`) dan `GET tiket/ringkasan` |
| `TicketNotifService` | MAKE-072 | push sesudah commit lewat `AntrePushPesan::antreAkun` (CR-137); gagal antre hanya dicatat log |
| `EksporTiketService` | MAKE-074 | ekspor rekap petugas E-09 (xlsx `EksporTabel`, WIB → UTC, pengaman baris/karakter, potong sel, audit unduhan) |

Aturan bersama:
- BP8-1: hanya `disiplin_ticket(_msg)` dan tabel master helpdesk; tidak pernah menulis `inbox`/`inbox_msg` (rumah notifikasi
  approval Fase 4).
- Petugas aktif = `support_officer.status = 1`, akun aktif, role 1–7 (F8-06, S12). Definisi yang sama dipakai
  `AksesTiket::kategoriAktif`, `PetugasService` (`petugas/saya`, `bisa_ekspor`), dan `TicketNotifService::petugasAktif`.
- W1: di dalam transaksi berkunci, semua baca sebelum kunci terakhir = locking read (`FOR UPDATE` / `LOCK IN SHARE MODE`; MariaDB
  10.4 tanpa `FOR SHARE`). Ekspor hanya baca, tanpa transaksi.
- Status terbuka (bukan bagian kontrak beku):
  - H8-8 (payload push lengkap, PN8-1) belum ada, jadi payload tetap terbatas F8-16; uji push real = DEPLOY-001.1.
  - Saran terpisah dari MAKE-072: validasi `msg` tiket memakai trim Unicode. Saat ini pesan yang hanya berisi spasi Unicode lolos
    validasi, dan isi push-nya memakai teks cadangan.
