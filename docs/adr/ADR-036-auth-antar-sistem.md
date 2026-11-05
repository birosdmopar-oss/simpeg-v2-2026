# ADR-036 — Auth antar-sistem: klien layanan (HTTP Basic) dan token SIMAPI

| | |
|---|---|
| **Status** | **Diusulkan 05-11-2026** (Fase 6 Jalur A, MAKE-058; disetujui lewat review CR) |
| **Cakupan** | Semua endpoint masuk dari sistem lain: SIMAPI `/simapi/v1/*` (`a_auth`, `a_employee`, `a_dm`, `a_sinkron`) dan `services/*` (`s_stat`, `s_dm`, `s_esign`) |
| **Pemilik pelaksanaan** | Fase 6 Jalur A (MAKE-058); dipakai MAKE-059/060/061 (Jalur A) dan `s_esign` (Jalur B, MAKE-065) |
| **Terkait** | PRD Fase 6 F6-03, F6-05, F6-08, BP-5, BP-8, BP-12, §6.3, §7.0.1, AC-F.1..F.5; DBV-026 (`klien_layanan`, `simapi_token`) §13.2; ADR-037 (penjadwalan); ADR-005 (JWT untuk pengguna aplikasi — tidak dipakai di sini) |

## 1. Konteks

- Legacy memakai tiga mekanisme terpisah: HTTP Basic dengan konstanta `MUDIG_*`/`CRON_*` di konfigurasi, tabel `sal_user`/`simapi_auth` dengan
  kata sandi **plaintext**, dan token SIMAPI acak dari `mt_rand` disimpan **mentah**. Sebagian endpoint (`A_dm/jabatan`, `S_stat` selain
  `rekomendasi_kp`, `S_dm p_*`) **publik**.
- Keputusan Horii (PRD F6-03, Q6-01): satu pola untuk semua endpoint antar-sistem, **semua wajib auth**, klien diberi kredensial baru saat
  re-handshake; kredensial lama tidak diimpor (F6-08).
- JWT aplikasi (ADR-005) untuk pengguna manusia tidak cocok: klien adalah sistem dengan Basic/handshake tetap dan kontrak legacy 1:1 (F6-04).

## 2. Keputusan

1. **Klien layanan** = baris `klien_layanan`: `username` (UNIQUE), `rahasia_hash` (`password_hash`, Argon2id; rahasia mentah tidak disimpan),
   `jenis` (1 SIMAPI lama, 2 SIMAPI baru, 3 layanan), `hak` (SET `simapi, stat, dm, esign, sinkron`), `ip_allowlist` (IP/CIDR dipisah koma;
   NULL = semua IP), `status` (1 aktif, 2 nonaktif, 10 dihapus lunak). Jenis 1/2 wajib berhak `simapi`; jenis 3 tidak boleh (audit B4).
2. **Filter `klienLayanan:<hak>`** (HTTP Basic) untuk `services/*` dan `a_auth`. Urutan: throttle per IP → urai header (panjang dibatasi,
   bentuk wajib `Basic <base64>`) → cari username → `password_verify` SELALU dijalankan (hash tiruan untuk username tak dikenal) → status 1 →
   allowlist IP → hak → throttle per klien. Semua kegagalan memberi respons **seragam** "Authentication failed"; alasan (`tidak_sah`,
   `tidak_aktif`, `ip_ditolak`, `hak_kurang`, `throttle_*`) hanya masuk `integrasi_log` (arah masuk, `id_klien`), tanpa username/rahasia/IP.
3. **Allowlist IP**: dinormalkan saat simpan (tanpa spasi/duplikat, IPv6 kanonik), entri kosong atau bukan IPv4/IPv6/CIDR **ditolak**; string
   kosong tidak pernah berarti "semua IP" (disimpan NULL); filter gagal tertutup. IP klien = `getIPAddress()` CI4 (proxy tepercaya lewat
   konfigurasi aplikasi, bukan header mentah).
4. **Token SIMAPI** (handshake `a_auth/token`, F6-05): 30 karakter `[A-Za-z0-9]` dari `random_int`, masa aktif 1 jam, **disimpan SHA-256 heks huruf
   kecil** sebagai PK `simapi_token.token_hash`; satu baris per handshake (token lama tetap sah sampai kedaluwarsa). Filter **`simapiToken`**
   mengambil token dari **elemen kedua** header `Authorization` [K `AE:30-47`] (skema bebas), membatasi panjang header dan bentuk token
   **sebelum** di-hash, mencari lewat PK, memeriksa `status = 1 AND expired_at > UTC_TIMESTAMP()`, klien `status = 1` dan berhak `simapi`,
   lalu `hash_equals`. (Syarat DBV-026 §13.2 "A4" dipenuhi di MAKE-058 — filter token adalah bagian Jalur A fondasi, 6_2.md §A3.)
5. **Respons gagal per grup** (BP-12): SIMAPI = HTTP 200 `{"status":"02","msg":"Authentication failed"|"Token is not valid","data":{}}`
   [K]; `services/*` = HTTP 401 `{"status":2,"msg":"Authentication failed","data":{}}` + `WWW-Authenticate: Basic` [V2]; throttle = 429
   dengan `Retry-After` pada kedua grup. Tidak ada `Set-Cookie`, `Cache-Control: no-store`.
6. **Throttle** (`Config\KlienLayanan`): bucket KEGAGALAN per IP (hanya percobaan gagal yang dihitung; bucket penuh ditolak 429 sebelum `password_verify`) dan per klien terautentikasi; jendela 60 detik; ambang bawaan
   120 kegagalan/IP dan 600 permintaan/klien dapat diubah lewat konfigurasi. Penolakan throttle ditulis ke `integrasi_log` paling banyak sekali per kunci per jendela (bukan per request). Hash tiruan untuk username tak dikenal adalah konstanta berparameter sama dengan hash nyata (tidak dibuat per request).
7. **Pengelolaan lewat command** `php spark integrasi:klien buat|rotasi|nonaktif|aktifkan|hapus|daftar|bersihkan-token`. Rahasia dibangkitkan
   `random_bytes` dan **ditampilkan sekali** di keluaran; tidak masuk DB mentah, audit, atau log. Semua perubahan satu transaksi pada satu
   koneksi bersama `audit_logs` (aktor NULL = CLI).
8. **Pencabutan token**: `nonaktif`, `hapus`, dan `rotasi` mencabut semua token aktif klien (`status = 2`) di transaksi yang sama (DBV-026 §13.2 A1,
   audit B3 = 0). `rotasi` ikut mencabut karena rahasia lama dianggap bocor [keputusan MAKE-058]; klien hapus (status 10) tidak dapat
   diaktifkan atau dirotasi lagi.
9. **Pembersihan token kedaluwarsa** (§13.2 A2): `integrasi:klien bersihkan-token [--hari=N] [--dry-run]` menghapus token yang kedaluwarsa
   lebih dari N hari (bawaan 7); dijadwalkan mingguan di runbook (README-deploy bagian 1h).
10. **Identitas klien di request**: service bersama `klienSaatIni` (id, jenis) diisi filter; dipakai penyaji SIMAPI/`services/*`
    (log akses, ack `change_history`). Pencatatan akses masuk memakai antarmuka bersama `IntegrasiLogInterface` (CR-091): hanya kegagalan yang
    ditulis (sukses di-throttle saja, volume SIMAPI tinggi).

## 3. Alternatif

| Alternatif | Alasan ditolak |
|---|---|
| JWT/OAuth untuk klien | Kontrak legacy 1:1 (Basic + token opak); klien eksternal tidak diubah selain kredensial (F6-04) |
| Token tanpa hash (seperti legacy) | Kebocoran DB membuka akses; BP-8 |
| Satu token per klien (UPDATE) | Handshake serentak saling membatalkan; F6-05 memilih banyak baris + pembersihan berkala |
| Respons berbeda per alasan gagal | Memungkinkan enumerasi username/IP; legacy sudah seragam |
| Allowlist di tabel terpisah | Jarang dipakai; VARCHAR + validasi saat simpan cukup (DBV-026-Q2) |

## 4. Konsekuensi

- Runbook: server web harus meneruskan header `Authorization` ke PHP (Nginx `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`);
  tanpa itu semua klien Basic gagal. Dicatat di README-deploy bagian 1h.
- Klien baru dibuat operator lewat command; kredensial dikirim lewat kanal aman di luar repo.
- Menambah grup endpoint = menambah nilai SET `hak` (ALTER ber-DBV, DBV-026-Q1) dan memasang filter `klienLayanan:<hak>`.
