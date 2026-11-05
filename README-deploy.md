# SIMPEG v2 — Deploy ke Server Dev (F0-18)

Pipeline deploy dev memakai **git bare repo + hook `post-receive`** (Tech Spec 1.4 [P]). Tidak ada CI/CD server (ADR-017): quality gate dijalankan lokal lewat `./check.sh` sebelum merge ke `main`.

**Gerbang migration (ISSUE-014 opsi A, keputusan user 29-09-2026; CR-021):** server Dev hanya dideploy dari `main` (tetap di hook, tidak bisa dikonfigurasi), hook **tidak menjalankan migration** (`RUN_MIGRATIONS=0` default), dan migration dijalankan manual lewat runbook setelah approval DB Validator. Release yang membawa migration tertunda dibangun tetapi **ditahan** (tidak diaktifkan) sampai migrate manual selesai, supaya kode baru tidak berjalan di atas skema lama; `rollback.sh` juga menolak mengaktifkan release yang migration-nya masih tertunda.

```
operator ── git push dev (main) ──▶ bare repo (server dev) ── hook post-receive ──▶ build release baru
                                                                          composer install --no-dev
                                                                          npm ci && npm run build
                                                                          php spark migrate:status (hanya membaca)
                                                                           ├─ tidak ada yang tertunda → symlink current -> release baru
                                                                           └─ ada yang tertunda       → release DITAHAN, migrate manual (§3a)
```

File terkait di repo ini:

| File | Fungsi |
|---|---|
| `deploy/post-receive` | Hook yang dipasang di `<BARE_REPO>/hooks/post-receive` di server dev |
| `deploy/rollback.sh` | Pindahkan symlink `current` ke release sebelumnya / release tertentu (juga untuk mengaktifkan release yang ditahan); menolak release yang migration-nya masih tertunda |
| `check.sh` | Quality gate lokal (wajib lolos sebelum merge) |

---

## 1. Setup satu kali di server dev

Prasyarat di server: `git`, `php` dengan patch aman (+ ext mysqli, intl, mbstring, json), `composer`, `node` dengan patch aman + `npm`, MySQL 8.x, web server (nginx/Apache).

**Versi minimum aman (CR-094, ISSUE-053 dan ISSUE-054):**

| Runtime | Versi minimum aman | Alasan |
|---|---|---|
| PHP | 8.2.35 (cabang 8.2); atau 8.3.36, 8.4.27, 8.5.12 | PHP2Shell, CVE-2026-109618 / CVE-2026-109619 (RCE di PHP core) |
| Node.js | 22.23.4 (cabang 22); 24.22.1 (cabang 24, dipakai proyek, lihat `frontend/.nvmrc`); 26.12.1 | Node2Shell, CVE-2026-111497 (dekoder Brotli). Node 20 sudah EOL dan tidak mendapat patch. |

Versi di bawah daftar di atas tidak boleh dipakai di server, termasuk Node 20 dan PHP 8.2.x di bawah 8.2.35. `composer.json` masih `php ^8.2`; menaikkan batas itu adalah keputusan terpisah.

PHP untuk unduhan PDF dokumen F-09 (MAKE-037, ADR-034): `memory_limit` ≥ 256M dan batas waktu request ≥ 30 detik (`max_execution_time` PHP-FPM serta timeout proxy web server, mis. `fastcgi_read_timeout`). PDF 1.700 baris terukur ±238 MB dan ±10 detik di mesin dev.

Database: koneksi aplikasi berjalan strict (`strictOn = true`) dan tabel memakai `utf8mb4_unicode_ci`. Sebelum deploy yang memuat DBV-010 (migration `2026-09-25-1300xx`), ikuti runbook `backend/docs/db-review/A-01-auth-schema.md` Bagian 9.8: cek versi/`sql_mode`/collation server (MySQL 8 vs MariaDB 10.4, D-11), `ALTER DATABASE` oleh DBA, bersihkan data QA, deploy di luar jam kerja (semua pengguna login ulang sekali). Migration-nya sendiri dijalankan manual (§3a), bukan oleh hook.

```bash
# 1) Bare repo
sudo mkdir -p /srv/git/simpeg-v2.git && cd /srv/git/simpeg-v2.git
sudo git init --bare
# main di server Dev hanya boleh maju: tolak force-push dan penghapusan branch
sudo git config receive.denyNonFastForwards true
sudo git config receive.denyDeletes true
sudo chown -R deploy:deploy /srv/git/simpeg-v2.git

# 2) Struktur aplikasi
sudo mkdir -p /var/www/simpeg-v2/{releases,shared/writable,logs}
sudo chown -R deploy:deploy /var/www/simpeg-v2

# 3) .env backend (berisi secret — TIDAK dari git). Isi dari backend/.env.example
cp backend/.env.example /var/www/simpeg-v2/shared/backend.env
vi /var/www/simpeg-v2/shared/backend.env      # CI_ENVIRONMENT=development, database.*, jwt.secret, cors.allowedOrigins, dst.
# Lupa password (ISSUE-006): auth.resetLinkBase = URL frontend server ini + /reset-password (production: https).
# Driver auth.resetTokenNotifier=log menulis tautan reset ke writable/logs dan DITOLAK di production; production memakai
# auth.resetTokenNotifier=email + blok email.* + encryption.key + worker antrean (§1a).
# Di production juga DITOLAK: auth.captchaDriver=mock dan auth.exposeResetTokenInResponse=true (ISSUE-021).

# (opsional) .env.local frontend — VITE_PASSWORD_RESET_ENABLED=true hanya kalau kanal reset di backend aktif
printf 'VITE_API_BASE_URL=https://simpegdev.example.go.id/api/v1\n' > /var/www/simpeg-v2/shared/frontend.env.local

# 4) writable/ persisten (cache, logs, uploads) — salin dari backend/writable repo
cp -r backend/writable/. /var/www/simpeg-v2/shared/writable/ && chmod -R ug+rw /var/www/simpeg-v2/shared/writable

# 5) Pasang hook + konfigurasinya
cp deploy/post-receive /srv/git/simpeg-v2.git/hooks/post-receive
chmod +x /srv/git/simpeg-v2.git/hooks/post-receive
cat > /srv/git/simpeg-v2.git/hooks/deploy.env <<'EOF'
DEPLOY_ROOT=/var/www/simpeg-v2
KEEP_RELEASES=5
# 0 = hook tidak menjalankan migration; migrate manual lewat runbook setelah approval DBV (§3a)
RUN_MIGRATIONS=0
# PHP_BIN=/usr/bin/php8.3  COMPOSER_BIN=/usr/local/bin/composer  NPM_BIN=/usr/bin/npm
# Tidak ada DEPLOY_BRANCH: hook selalu mendeploy main (ISSUE-014)
EOF

# 6) rollback helper + konfigurasi yang sama dengan hook (DEPLOY_ROOT, PHP_BIN)
cp deploy/rollback.sh /var/www/simpeg-v2/rollback.sh && chmod +x /var/www/simpeg-v2/rollback.sh
ln -sfn /srv/git/simpeg-v2.git/hooks/deploy.env /var/www/simpeg-v2/deploy.env
```

`RUN_MIGRATIONS` hanya dibaca dari `deploy.env`; nilai dari environment proses git diabaikan. `RUN_MIGRATIONS` selain `0`/`1` membuat hook menolak deploy (`DIBATALKAN`, tanpa build dan tanpa menyentuh database). `RUN_MIGRATIONS=1` (hook menjalankan `php spark migrate --all` setelah build) hanya untuk kasus khusus, mis. server uji sekali pakai tanpa prasyarat runbook; server Dev memakai `0`.

Branch yang dideploy selalu `main` dan tidak bisa diubah. `deploy.env` lama yang masih berisi `DEPLOY_BRANCH` dengan nilai selain `main` (dulu default hook `dev`) juga membuat hook menolak deploy (`DIBATALKAN`); hapus barisnya.

`rollback.sh` membaca `deploy.env` di sampingnya (symlink langkah 6) sama seperti hook: `DEPLOY_ROOT` dan `PHP_BIN` diambil dari sana. Tanpa file itu, `DEPLOY_ROOT` = direktori tempat `rollback.sh` dipasang; nilai `DEPLOY_ROOT` dari environment shell diabaikan.

Web server mengarah ke symlink `current` (di-resolve ulang tiap request, tidak perlu reload saat deploy):

- API (CI4): document root `/var/www/simpeg-v2/current/backend/public`
- SPA (Vue): root `/var/www/simpeg-v2/current/frontend/dist` dengan fallback `try_files $uri /index.html`

Contoh nginx (ringkas):

```nginx
server {
    server_name simpegdev.example.go.id;
    root /var/www/simpeg-v2/current/frontend/dist;
    location / { try_files $uri $uri/ /index.html; }

    location /api/ {
        root /var/www/simpeg-v2/current/backend/public;
        try_files $uri /index.php$is_args$args;
    }
    location ~ ^/(api/)?index\.php {
        root /var/www/simpeg-v2/current/backend/public;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        # php8.3-fpm harus ≥ 8.3.36 (PHP2Shell, CVE-2026-109618/109619)
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

> Catatan infrastruktur (ADR Bagian 5): BSrE dan server Arsip berada di jaringan internal — server dev harus berada di jaringan yang sama / VPN saat integrasi real (Fase 6).
>
> Keputusan reviewer CR 16-10-2026 (saran DB Validator, review PR #27 §7): satu alamat host DB internal yang tersisa di komentar migration `2026-09-23-000000_AlterBatch1KeSkemaLegacy.php` dan di riwayat git **diterima sebagai risiko rendah** (alamat privat, tidak terjangkau dari luar). Komentar migration tidak diedit (aturan migration di `main`) dan riwayat git tidak ditulis ulang; dokumen baru tetap tidak boleh memuat alamat/host internal (CR-048).

### 1a. Email tautan reset password (CR-014, ISSUE-006)

Driver `auth.resetTokenNotifier = email` **tidak** mengirim SMTP di dalam request forgot-password: request hanya memasukkan job terenkripsi ke queue `email` (tabel `queue_jobs`), lalu worker antrean yang mengirim. **Tanpa worker, email reset tidak pernah terkirim** (respons forgot-password tetap sukses generik).

1. Isi di `shared/backend.env` (jangan commit; kredensial SMTP legacy di `constants.php` lama **tidak dipakai ulang** dan wajib dirotasi):

   ```ini
   auth.resetTokenNotifier = email
   auth.resetLinkBase = https://simpegdev.example.go.id/reset-password
   encryption.key = <keluaran php spark key:generate --show, sudah berawalan hex2bin:>   # minimal 32 byte; SAMA untuk web dan worker
   email.protocol = smtp
   email.fromEmail = <alamat pengirim domain instansi>
   email.fromName = SIMPEG
   email.SMTPHost = <host relay instansi>
   email.SMTPPort = 587          # 587 + tls (STARTTLS) atau 465 + '' (TLS implisit)
   email.SMTPCrypto = tls
   email.SMTPUser = <akun SMTP>  # kosongkan bersama SMTPPass bila relay per IP
   email.SMTPPass = <password SMTP>
   email.SMTPTimeout = 10
   ```

   `.env` disalin hook ke setiap release saat deploy, jadi perubahan `shared/backend.env` baru berlaku sesudah deploy berikutnya (atau salin manual ke `current/backend/.env`). Salah konfigurasi membuat forgot-password gagal 500 yang sama untuk semua username (pesan log hanya menyebut nama key). Pengirim wajib domain instansi lewat relay instansi (Gmail dengan `fromEmail` domain kementerian gagal SPF/DKIM dan masuk spam). Sertifikat server SMTP harus valid: jangan mematikan verifikasi TLS.

2. Worker lewat cron user `deploy` (pakai symlink `current`, jadi kode baru ikut terpakai sesudah deploy; `flock` mencegah dua worker berjalan bersamaan):

   ```bash
   # /etc/cron.d/simpeg-v2-queue-email
   * * * * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-queue-email.lock php spark queue:work email --stop-when-empty --max-time 55 >> /var/www/simpeg-v2/logs/queue-email.log 2>&1
   ```

   Job dicoba ulang tiap 60 detik sampai 5 percobaan (masih di dalam masa berlaku token 30 menit). Setiap percobaan memeriksa ulang token (belum dipakai/kedaluwarsa) dan akun (aktif, email valid); akun tanpa email dilewati dengan log `warning` "tidak memiliki email valid" — bahan admin melengkapi email akun.

3. Smoke test sesudah konfigurasi: `cd /var/www/simpeg-v2/current/backend && php spark email:test <alamat uji>` (email uji tanpa token; hanya mencetak hasil dan ringkasan balasan SMTP, tidak pernah password). Lalu uji alur penuh dari halaman lupa password dan periksa `php spark queue:failed` (job gagal permanen; isi tetap terenkripsi). Setelah gagal diperbaiki: `php spark queue:retry all -queue email` aman karena pemeriksaan token diulang.

4. Pantau: baris `queue_jobs` queue `email` yang lebih tua dari 10 menit berarti worker tidak berjalan. `VITE_PASSWORD_RESET_ENABLED=true` di frontend baru dinyalakan setelah kirim nyata lolos di Dev. Hook `post-receive` tidak menjalankan worker (ranah CR-021).

### 1b. Push notifikasi layanan (MAKE-023, PRD Fase 4 §6.6.3)

Notifikasi layanan (cuti, TB, IB, PMK) ditulis ke `inbox`/`inbox_msg` v2 di dalam transaksi bisnis; push-nya **tidak** dikirim di dalam request, melainkan satu job `push-notif` per `inbox_msg` di queue `push` (tabel `queue_jobs`) yang diantrekan sesudah commit. Di Fase 4 adapter push = mock (tidak ada koneksi jaringan; payload hanya tercatat di log), jadi tanpa worker notifikasi `inbox` tetap tersimpan tetapi `inbox_msg.push_status` tinggal `0` (antre).

Worker lewat cron user `deploy` (pola sama dengan §1a):

```bash
# /etc/cron.d/simpeg-v2-queue-push
* * * * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-queue-push.lock php spark queue:work push --stop-when-empty --max-time 55 >> /var/www/simpeg-v2/logs/queue-push.log 2>&1
```

Job dicoba ulang tiap 60 detik sampai 5 percobaan, lalu pindah ke `queue_jobs_failed` (`php spark queue:failed`); tidak retry selamanya. Penerima tanpa token perangkat (`firebase_token`) ditandai `push_status = 3` tanpa galat. Pantau: baris `queue_jobs` queue `push` yang lebih tua dari 10 menit berarti worker tidak berjalan; `inbox_msg.push_status = 2` berarti push gagal (log `[NotifikasiService]` / `[MockFcmAdapter]`).

### 1c. E-sign BSrE (MAKE-065, PRD Fase 6 §7.2 H-02)

Driver real: `integrations.esign.driver = bsre` di `.env` server, dengan `baseUrl` (alamat gateway BSrE), `username`/`password` (client id/secret Basic), `tlsVerify`, `caBundle`, dan `timeout`. Nilai hanya di `.env` server, tidak di repo. Konfigurasi kosong atau tidak sah membuat `esignGateway` gagal saat dibuat (pesan menyebut nama kunci, tanpa nilai).

**TLS — keputusan Horii 03-11-2026 (kartu Catatan Teknis): ikut legacy.** `integrations.esign.tlsVerify = false` diizinkan **khusus e-sign**. Dengan nilai itu peer dan host gateway tidak diverifikasi, sama dengan legacy. Bawaan repo tetap `true`, dan BKN selalu wajib `true`. Risikonya: koneksi bisa disadap/dipalsukan oleh pihak di jaringan antara server dan gateway (*man-in-the-middle*), padahal NIK dan passphrase ikut terkirim. Selama `false`, aplikasi menulis peringatan sekali per proses di log, dan `php spark integrasi:status` serta `php spark integrasi:uji-koneksi esign` menampilkan `TLS BSrE: TIDAK DIVERIFIKASI`. Pengaman lain tetap berlaku: NIK, passphrase, dan kredensial disensor dari semua log; passphrase tidak disimpan; tanda tangan sukses hanya bila balasan berupa PDF; redirect tidak diikuti.

Langkah upgrade ke TLS ketat (cukup lewat `.env`, tanpa ubah kode):

1. Pastikan gateway diakses lewat `https://` dan cek sertifikatnya, mis. `openssl s_client -connect <host>:443 -showcerts` dari server aplikasi. Catat penerbitnya; jangan tulis host/IP di repo.
2. Bila sertifikat diterbitkan CA internal, simpan berkas CA (PEM) **di luar repo dan webroot**, lalu isi `integrations.esign.caBundle = <path berkas>`. Kosongkan bila CA publik (memakai CA sistem).
3. Set `integrations.esign.tlsVerify = true`.
4. Jalankan `php spark integrasi:uji-koneksi esign`: harus `TLS BSrE: diverifikasi.` dan `Uji koneksi esign OK`. Path `caBundle` yang salah gagal saat konfigurasi dibaca.
5. Lakukan satu tanda tangan uji (surat cuti uji oleh PyB bersertifikat), lalu periksa `esign_hist` status `1`.

**URL publik QR surat cuti (Q6-22/Q6-23).** Isi `layanan.urlPublikSurat = https://<alamat publik SIMPEG>/` di `.env` server **sebelum tanda tangan pertama**. QR kaki surat berisi `<dasar>/verify/surat-cuti/{token}` dan QR tanda tangan berisi `<dasar>/api/v1/publik/surat-cuti/{token}/pdf`; keduanya dibekukan permanen di PDF ber-TTE, jadi alamat ini tidak boleh berubah sesudah dipakai. Di production nilai kosong, bukan URL absolut, atau bukan `https` membuat layanan token gagal saat dibuat (pesan menyebut kunci, tanpa nilai). Di luar production nilai kosong memakai `app.baseURL`.

**Cabut akses publik satu surat cuti** (mis. surat dibatalkan, PDF beredar ke pihak yang tidak berhak). Akses berlaku sampai dicabut (Q6-22):

```bash
php spark surat-cuti:token --id=<id_riwayat_cuti>             # lihat status: aktif / nonaktif / belum ada
php spark surat-cuti:token --id=<id_riwayat_cuti> --nonaktif  # halaman verifikasi + PDF publik → 404
php spark surat-cuti:token --id=<id_riwayat_cuti> --aktif     # pulihkan token yang sama
```

Setiap perubahan tercatat di `audit_logs` (`entity` `surat_cuti_token`, `jenis_audit` `cuti.token_surat`, `sumber` `cli`). Keluaran hanya status, tanpa token, URL, atau NIP. Token surat yang sudah ber-TTE tidak bisa diganti (DBV-030 Q3), hanya dinonaktifkan. Menghapus cuti menghapus tokennya juga (DBV-030 Q2).

### 1d. Push FCM HTTP v1 (Fase 6 H-04, MAKE-066)

Driver `fcm` mengganti mock dengan FCM **HTTP v1** (OAuth2 service account; FCM Legacy API/server key legacy sudah dihentikan Google). Worker tetap cron `queue:work push` di §1b; job di queue `push`:

| Job | Isi | Kanal |
|---|---|---|
| `push-notif` | notifikasi layanan (`inbox_msg`) cuti/TB/IB/PMK [K `fcmQueue_layanan`] | web (akun ber-NIP penerima) |
| `push-riwayat` | riwayat/usulan/LKH/Konket ke penerima legacy (admin pembawah, atasan LKH, petugas Karpeg/Kariskarsu, pegawai) | web |
| `push-pesan` | Android dengan teks & `data` legacy `andr_notif_queue`: cuti (ajukan/ubah → atasan, keputusan → pemohon + PyB), LKH (ajukan/ubah → atasan, proses → pegawai), Konket (proses → pemohon + peserta dinas gabungan); titik sambung Halo Simpeg Fase 8 | Android (`firebase_token.platform` 2, per NIP) |
| `fcm-topik` | keanggotaan topik `pegawai_blm_masuk` / `pegawai_blm_pulang` / `pegawai_blm_lkh` sesudah presensi/LKH | Android |

1. Proyek Firebase **sama dengan legacy** (PN-3): topik presensi dan app Android memakai proyek itu. Pesan ke topik tetap dikirim **di luar SIMPEG** (Firebase console / penjadwal luar) seperti legacy.
2. Buat service account dengan peran pengirim FCM, unduh berkas JSON-nya ke server di **luar repo dan luar webroot**, mis. `/etc/simpeg-v2/fcm-sa.json`, pemilik user PHP-FPM/`deploy`, `chmod 600`. Jangan pernah commit berkas ini atau menempelkan isinya ke tiket/chat.
3. Isi `shared/backend.env`:

   ```ini
   integrations.push.driver = fcm
   integrations.push.projectId = <ID proyek Firebase>
   integrations.push.credentialsPath = /etc/simpeg-v2/fcm-sa.json
   ```

   Konfigurasi kosong atau berkas tidak terbaca → adapter gagal saat dibuat (job masuk `queue_jobs_failed`, pesan menyebut nama key saja).

   Uji dari server sesudah konfigurasi (CR-101): `php spark integrasi:uji-koneksi fcm` — meminta token akses OAuth baru lalu
   `messages:send` dengan `validate_only` ke topik uji, jadi **tidak ada pesan yang sampai ke perangkat**. Harus
   `Uji koneksi fcm OK`. `GAGAL: token akses OAuth …` = berkas/kunci service account salah atau dicabut; `PERMISSION_DENIED`
   = service account tanpa peran pengirim FCM; `NOT_FOUND` = `projectId` salah. Keluaran tanpa projectId/token/kunci.
4. Frontend (web push): isi konfigurasi web Firebase proyek yang sama di `frontend/.env.production` (`VITE_FIREBASE_*`, lihat `frontend/.env.example`). Nilai web Firebase bukan rahasia, tetapi tetap tidak disalin dari `header.php` legacy; ambil dari Firebase console.
5. Token web (`platform` 1) didaftarkan SPA lewat `POST api/v1/integrasi/fcm-token`. **Token Android (`platform` 2) tidak punya endpoint di SIMPEG v2** (legacy pun tidak): backend app mobile di luar repo yang menulis `firebase_token` legacy harus diarahkan menulis ke `firebase_token` DB v2 — `nip` pemilik, `platform` = 2, `fcm_token`, `token_hash` = SHA-256 heks token (unik per `nip` + `token_hash`), `id_pengguna` NULL. Siapa dan bagaimana caranya = pertanyaan cutover DEPLOY-001.1 (PRD Fase 6 §7.4); sampai terjawab, push Android dan topik presensi tidak punya penerima. Token yang dilaporkan FCM tidak terdaftar dihapus otomatis.
   Kontrak `data` push Android mengikuti legacy apa adanya (`{title, body, data: JSON baris sumber + kolom tambahan legacy}`, mis. cuti `file_bukti_url` + `status_str`) karena app Android bergantung padanya; TB/IB/PMK tidak dikirim ke Android (legacy pun tidak).
6. Berkas service account wajib ber-`token_uri` `https://oauth2.googleapis.com/token` (berkas lain ditolak). Kunci privat dibaca dari berkas hanya saat menandatangani JWT; **token akses OAuth (±1 jam) disimpan di cache aplikasi** (handler cache CI4, bawaan berkas di `writable/cache`) — pastikan `writable/` tidak terjangkau dari web dan hanya dibaca user PHP.
7. Batas [V2]: maks 10 token web per akun (yang terlama dibuang), pendaftaran token dibatasi 20 permintaan/60 detik per akun (429).
8. Pantau: `integrasi_log` layanan `fcm` (status code + pesan teredaksi; token tidak pernah tercatat) dan `php spark queue:failed`; galat per token pada topik presensi (HTTP 200 dengan `results[].error`) tercatat sebagai `warning` `[fcm-topik]` di log aplikasi dan hitungannya ada di hasil job.

### 1e. BKN e-Kinerja & SIASN (Fase 6 H-10/H-03, MAKE-063/064/067)

Integrasi keluar ke BKN berjalan lewat command terjadwal dan satu worker antrean, tanpa panggilan BKN di dalam request
pengguna. Ringkasan modul: `backend/app/Libraries/Bkn/README.md`, `backend/app/Libraries/Siasn/README.md`.

**Urutan aktivasi (sekali, sesudah cutover data):**

1. Isi `shared/backend.env` (nilai hanya di server; daftar kunci lengkap di `backend/.env.example` blok "BKN e-Kinerja &
   SIASN"): `integrations.bkn.baseUrlApi`, `baseUrlEkinerja`, `tokenUrlGateway`, `tokenUrlSso`, `clientId`/`clientSecret`,
   `ssoClientId`/`ssoUsername`/`ssoPassword` (+ `ssoTotp` bila dipakai), token e-Kinerja (`ekinerjaToken` statis **atau**
   `ekinerjaApiUser`/`ekinerjaApiPass` + `ekinerjaSso*`), untuk dorong jabatan `siasnInstansiId`/`siasnSatkerId`/
   `siasnUnorBawaan`, lalu `integrations.siasn.driver = bkn`. `integrations.bkn.tlsVerify` **wajib** `true` (berbeda
   dengan e-sign §1c). Konfigurasi kosong/tidak sah → command/job gagal dengan nama kunci saja, tanpa nilai.
2. **Sesudah impor `pengguna` dan sebelum cron diaktifkan:** `php spark integrasi:siapkan-akun-sistem` (idempoten). Akun
   `sistem.integrasi` dipakai sebagai pelaku `updated_by`; tanpa akun ini semua command BKN/SIASN keluar 1 (DBV-027 §4,
   Z-1 opsi c). Akun menyimpang dilaporkan nama kolomnya saja dan tidak diperbaiki otomatis.
3. Uji koneksi manual dari server: `php spark integrasi:uji-koneksi bkn` (token e-Kinerja + `GET referensi/periode`, hanya
   baca) dan `php spark integrasi:uji-koneksi siasn` (token gateway + SSO, tanpa data pegawai). Uji ini **tidak** bagian
   `check.sh`.
4. Uji kering satu per satu dengan `--dry-run` (tanpa tulis; permintaan GET ke BKN tetap dikirim untuk `siasn:tarik`), lalu
   aktifkan cron di bawah.

**Worker antrean `integrasi`** — job `bkn-tarik` dari tombol admin "tarik ulang e-Kinerja" (`POST api/v1/integrasi/bkn/tarik`,
super admin). Pola sama dengan §1a/§1b (antrean ini terpisah dari `push`):

```bash
# /etc/cron.d/simpeg-v2-queue-integrasi
* * * * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-queue-integrasi.lock php spark queue:work integrasi --stop-when-empty --max-time 55 >> /var/www/simpeg-v2/logs/queue-integrasi.log 2>&1
```

**Jadwal command (PRD Fase 6 §6.4).** Jam = WIB; sesuaikan bila zona waktu server bukan WIB. Jadwal SIASN di bawah adalah
**usulan sementara** dengan batas usang legacy (6 jam / 1 hari [K `SS/*`]) dan wajib dicocokkan dengan crontab server
legacy (**PN-5**) sebelum go-live. Pola baku crontab (lock, log, pemantauan) ditetapkan ADR-037 (Jalur A); bila ADR-037
berbeda, ADR-037 yang berlaku dan baris di bawah disesuaikan.

```bash
# /etc/cron.d/simpeg-v2-integrasi-bkn  (contoh; variabel lingkungan cron, satu per baris)
B=/var/www/simpeg-v2/current/backend
L=/var/www/simpeg-v2/logs
# e-Kinerja (H-10): periode baru harian; SKP periodik periode terpilih + satu periode sebelumnya (--sebelumnya)
0  1 * * *   deploy cd $B && php spark bkn:tarik-periode                    >> $L/bkn.log 2>&1
0  2 * * *   deploy cd $B && php spark bkn:tarik-skp-periodik --sebelumnya  >> $L/bkn.log 2>&1
# SIASN tarik (H-03): batch kecil berulang; tiap jalan hanya mengambil NIP yang sudah usang (data utama 1 hari, lainnya 6 jam)
*/15 * * * * deploy cd $B && php spark siasn:tarik --jenis=data-utama --limit=50 >> $L/siasn.log 2>&1
3-59/15 * * * * deploy cd $B && php spark siasn:tarik --jenis=golongan   --limit=50 >> $L/siasn.log 2>&1
6-59/15 * * * * deploy cd $B && php spark siasn:tarik --jenis=skp        --limit=50 >> $L/siasn.log 2>&1
9-59/15 * * * * deploy cd $B && php spark siasn:tarik --jenis=pendidikan --limit=50 >> $L/siasn.log 2>&1
12-59/30 * * * * deploy cd $B && php spark siasn:tarik --jenis=pmk       --limit=50 >> $L/siasn.log 2>&1
27-59/30 * * * * deploy cd $B && php spark siasn:tarik --jenis=ak        --limit=50 >> $L/siasn.log 2>&1
30 4 * * *   deploy cd $B && php spark siasn:tarik --jenis=ref-unor                >> $L/siasn.log 2>&1
# SIASN dorong (H-03): berkala, tidak bersamaan dengan tarik jenis yang sama (skp vs skp22)
10 */2 * * * deploy cd $B && php spark siasn:dorong --jenis=jabatan     >> $L/siasn.log 2>&1
20 */2 * * * deploy cd $B && php spark siasn:dorong --jenis=diklat      >> $L/siasn.log 2>&1
25 */2 * * * deploy cd $B && php spark siasn:dorong --jenis=sertifikasi >> $L/siasn.log 2>&1
35 */2 * * * deploy cd $B && php spark siasn:dorong --jenis=kursus      >> $L/siasn.log 2>&1
50 */2 * * * deploy cd $B && php spark siasn:dorong --jenis=skp22       >> $L/siasn.log 2>&1
# Arsip SIASN: unggah 1 baris tiap menit [K "every minute" limit 1]; antre lebih jarang, di luar menit dorong jabatan/sertifikasi
* * * * *    deploy cd $B && php spark siasn:unggah-arsip --langkah=unggah >> $L/siasn-arsip.log 2>&1
45 * * * *   deploy cd $B && php spark siasn:unggah-arsip --langkah=antre  >> $L/siasn-arsip.log 2>&1
# Pemeliharaan & pemantauan log integrasi (F6-24)
0  4 * * 0   deploy cd $B && php spark integrasi:bersihkan-log             >> $L/integrasi.log 2>&1
5  * * * *   deploy cd $B && php spark integrasi:status --jam=1 > /dev/null 2>&1 || echo "integrasi:status: ada kegagalan/peringatan" | logger -t simpeg-v2
```

Setiap command memegang kunci DB (`GET_LOCK`) per jenis: jalan kedua jenis yang sama ditolak "sedang berjalan" (exit 1,
tanpa panggilan API), jadi tumpang tindih tidak merusak data, tetapi baris log exit 1 itu tanda jadwal terlalu rapat.
`--limit` bawaan 20 baris/NIP per jalan (tarik maks 500); naikkan hanya bila antrean menumpuk.

**Kode keluar & pemantauan.** `0` = berhasil (termasuk sebagian NIP/baris gagal di sisi BKN, tercatat); `1` = konfigurasi
belum lengkap, akun sistem belum siap, driver bukan `bkn`, jenis yang sama sedang berjalan, token/otorisasi BKN ditolak
(batch dihentikan tanpa menulis baris), atau galat DB; `7` = opsi salah. Keluaran dan `integrasi_log` hanya jumlah, tanpa
NIP/nama/token. `php spark integrasi:status` keluar 1 bila ada kegagalan dalam jendela atau periode Tukin berjalan belum punya
SKP periodik e-Kinerja (F6-15). Job `bkn-tarik` yang gagal permanen ada di `php spark queue:failed`.

**Risiko duplikat di BKN (wajib dibaca operator).** POST ke SIASN (dorong riwayat, unggah dokumen) tidak pernah di-retry
otomatis, tetapi BKN bisa sudah memproses permintaan yang balasannya tidak sampai:

- `status_tidak_diketahui` di ringkasan `dorong_<jenis>` / `unggah_arsip` = timeout/koneksi putus saat POST. Periksa riwayat
  itu di SIASN sebelum didorong lagi; jabatan gagal otomatis dicoba lagi setelah 6 jam [K], jenis lain tidak.
- `hasil_tidak_tersimpan` (`integrasi_log` kode itu, `ref_id` = `<tabel>:<id>` atau `siasn_upload_arsip:<id>`) = POST
  terkirim tetapi hasilnya gagal ditulis ke DB v2 (dicoba dua kali, juga log `critical`). Baris sengaja **tidak** dipilih
  lagi (sekali-dorong flag 3 + `siasn_error` "Hasil tidak tersimpan…"; jabatan flag 3/1 dengan `siasn_lu` sekarang; arsip
  tetap status 3). Operator memeriksa SIASN, lalu menyetel baris itu secara manual sesuai hasilnya.
- 5xx berulang pada `siasn_error` jabatan = kemungkinan riwayat ganda di BKN (legacy pun begitu); periksa di SIASN.

**Lokasi berkas rahasia.** Semua kredensial BKN/BSrE/FCM hanya di `shared/backend.env`. Berkas service account FCM dan
CA bundle BSrE disimpan **di luar repo dan luar webroot** (mis. `/etc/simpeg-v2/`, `chmod 600`, pemilik user PHP) — lihat
§1c dan §1d. Uji koneksi FCM: `php spark integrasi:uji-koneksi fcm` (validate_only, tanpa kirim ke perangkat, §1d).

### 1f. Job terjadwal Fase 6 (ADR-037, PRD Fase 6 §6.4) — bagian Jalur A

Job = command `php spark` + `cron.d` user `deploy` + `flock -n` (tanpa endpoint HTTP cron). Jam di bawah mengikuti zona waktu server: pastikan server berzona WIB (Asia/Jakarta) atau geser jamnya. Kode keluar ≠ 0 berarti ada yang gagal. Keluaran hanya jumlah, tanpa NIP/nama.

| Command | Jadwal (WIB) | Task |
|---|---|---|
| `layanan:status-harian` | 00:30 setiap hari | H-11 (MAKE-062) |
| `pengguna:sinkron-nama` | 01:00 setiap hari (USULAN, PN-5: dicocokkan dengan crontab legacy) | F6-21 bersyarat (MAKE-062) |

```bash
# /etc/cron.d/simpeg-v2-status-harian
30 0 * * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-status-harian.lock php spark layanan:status-harian >> /var/www/simpeg-v2/logs/status-harian.log 2>&1
# /etc/cron.d/simpeg-v2-sinkron-nama (usulan)
0 1 * * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-sinkron-nama.lock php spark pengguna:sinkron-nama >> /var/www/simpeg-v2/logs/sinkron-nama.log 2>&1
```

`layanan:status-harian` (H-11, Q6-24..Q6-27):

1. Prasyarat: `php spark integrasi:siapkan-akun-sistem` sudah dijalankan sesudah impor `pengguna` (pelaku audit = akun sistem; belum siap → kode keluar 1 tanpa menulis). `--dry-run` tidak butuh akun.
2. **Run pertama di lingkungan baru WAJIB `--dry-run`** dan jumlahnya direview Biro SDM sebelum run nyata: `php spark layanan:status-harian --dry-run`. Baris INFO "status 2/3 tanpa riwayat pendukung" = pegawai berstatus TB/CLTN tanpa riwayat TB/CLTN (isian manual/impor) yang sengaja tidak disentuh; "bentrok TB+CLTN" dan "celah TB" dilaporkan untuk ditinjau.
3. `--tanggal=YYYY-MM-DD` hanya bersama `--dry-run` untuk tanggal lampau (dihitung terhadap status pegawai SAAT INI); tanggal masa depan ditolak. Run nyata selalu hari ini WIB dan aman diulang (idempoten); run yang terlewat cukup dijalankan ulang hari itu.
4. Hanya `pegawai.id_jenis_status` 1/2/3 dari pegawai berstatus aktif yang diubah; setiap perubahan tercatat di `audit_logs` (`jenis_audit` `layanan.status_harian`).

`pengguna:sinkron-nama` (padanan legacy `sync_nama_pengguna`): menyamakan `pengguna.name` akun pegawai (role 2, status aktif, ber-NIP, pegawai aktif) dengan nama bergelar; hanya mengubah yang berbeda, idempoten, akun sistem dikecualikan, `--dry-run` tersedia, prasyarat akun sistem sama. Jadwal di atas masih usulan.

**Server berzona UTC.** Jam `cron.d` mengikuti zona server. Bila server UTC, geser 7 jam: `layanan:status-harian` pukul 00:30 WIB = `30 17 * * *` (hari sebelumnya, UTC) dan `pengguna:sinkron-nama` pukul 01:00 WIB = `0 18 * * *`; atau pasang `CRON_TZ=Asia/Jakarta` di baris pertama berkas `cron.d` bila cron server mendukungnya. Run nyata `layanan:status-harian` selalu memakai tanggal WIB hari ini dari aplikasi, bukan dari jam cron.

**Pemantauan.** Kode keluar ≠ 0 berarti ada kegagalan (`1` = galat/kunci sibuk/akun sistem belum siap/ada pegawai gagal, `7` = opsi salah). Pantau dengan pembungkus yang mengirim peringatan bila keluar ≠ 0 (mis. `|| echo "layanan:status-harian gagal" | mail ...` atau pemantau cron yang ada) dan lihat baris `ERROR` di `writable/logs` (cabang, kelas, kode; tanpa NIP/nama). Baris INFO "celah TB" = hari ini jatuh di celah antara akhir TB (atau perpanjangan) dan perpanjangan berikutnya; "segmen riwayat tidak sah" = riwayat TB/CLTN bertanggal rusak yang dilewati (perlu ditinjau Biro SDM).

### 1g. Auto-approval LKH tanggal 5 (H-12, MAKE-068, PRD Fase 6 §6.4, §7.10, F6-25)

Job = command `php spark` + `cron.d` user `deploy` + `flock -n` (tanpa endpoint HTTP cron). Jam mengikuti zona waktu
server: pastikan server berzona WIB (Asia/Jakarta) atau geser jamnya. Kode keluar ≠ 0 berarti ada yang gagal. Keluaran
hanya jumlah, tanpa NIP/nama.

| Command | Jadwal (WIB) | Task |
|---|---|---|
| `lkh:auto-approval` | tanggal 5 pukul 00:30, ulang 01:00 / 01:30 / 02:00 | H-12 (MAKE-068) |

```bash
# /etc/cron.d/simpeg-v2-lkh-auto-approval
30 0 5 * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-lkh-auto-approval.lock php spark lkh:auto-approval >> /var/www/simpeg-v2/logs/lkh-auto-approval.log 2>&1
0 1 5 * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-lkh-auto-approval.lock php spark lkh:auto-approval >> /var/www/simpeg-v2/logs/lkh-auto-approval.log 2>&1
30 1 5 * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-lkh-auto-approval.lock php spark lkh:auto-approval >> /var/www/simpeg-v2/logs/lkh-auto-approval.log 2>&1
0 2 5 * * deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-lkh-auto-approval.lock php spark lkh:auto-approval >> /var/www/simpeg-v2/logs/lkh-auto-approval.log 2>&1
```

`lkh:auto-approval` (H-12):

1. Prasyarat: `php spark integrasi:siapkan-akun-sistem` sudah dijalankan sesudah impor `pengguna` (pelaku = akun sistem; belum
   siap → kode keluar 1 tanpa menulis). `--dry-run` tidak butuh akun.
2. Hanya LKH bulan lalu berstatus 0 (menunggu atasan) yang disetujui: `status=1`, `auto_approval=1`. Status 3 (revisi) tidak
   diproses. PDF tidak dibuat atau disimpan oleh job; PDF LKH Disetujui dibuat saat diminta (CR-093).
3. Run nyata hanya untuk bulan lalu. `--bulan=YYYY-MM` dan `--dry-run` dipakai untuk pemeriksaan: `--dry-run` boleh bulan
   lampau lain, bulan berjalan/masa depan ditolak. Jalankan `--dry-run` dahulu di lingkungan baru dan periksa jumlahnya.
4. Aman diulang: run kedua tidak menyetujui apa pun dan tidak menambah audit. Ulangan 01:00–02:00 hanya menutup LKH yang
   terlewat atau gagal sebelumnya.

### 1h. Auth antar-sistem — klien layanan dan token SIMAPI (MAKE-058, ADR-036) — bagian Jalur A

Semua endpoint masuk dari sistem lain (SIMAPI `/simapi/v1/*` dan `services/*`) wajib auth; klien dibuat operator, bukan diimpor (F6-08).

1. **Header `Authorization` harus sampai ke PHP.** Nginx: pada blok `location ~ \.php$` tambahkan `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`
   (Apache: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`). Tanpa ini semua klien Basic ditolak "Authentication failed". Uji:
   panggil endpoint dengan kredensial sah dari alamat yang diizinkan dan pastikan bukan 401.
2. **Buat klien** (rahasia tampil SEKALI; kirim lewat kanal aman di luar repo, jangan ditempel di tiket/chat):
   ```bash
   php spark integrasi:klien buat --nama="<sistem>" --username=<huruf-kecil> --jenis=<1|2|3> --hak=<simapi|stat|dm|esign|sinkron,…> [--ip=<ip/cidr,…>]
   ```
   Jenis 1/2 (SIMAPI) wajib berhak `simapi`; jenis 3 (layanan) tidak boleh. Tanpa `--ip` = semua IP; isi `--ip` dengan IP/CIDR keluar klien bila
   diketahui (kosong ditolak). Lainnya: `rotasi <username>` (rahasia baru, token dicabut), `nonaktif|aktifkan|hapus <username>`, `daftar`.
3. **Re-handshake klien** (cutover): beri tiap klien username + rahasia baru dan alamat endpoint baru; kredensial lama tidak berlaku.
4. **Pembersihan token kedaluwarsa** (mingguan, idempoten; `--dry-run` untuk menghitung):
   ```bash
   # /etc/cron.d/simpeg-v2-bersihkan-token
   15 4 * * 0 deploy cd /var/www/simpeg-v2/current/backend && flock -n /tmp/simpeg-v2-bersihkan-token.lock php spark integrasi:klien bersihkan-token >> /var/www/simpeg-v2/logs/bersihkan-token.log 2>&1
   ```
   Retensi bawaan 7 hari sejak kedaluwarsa (`Config\KlienLayanan::$tokenRetensiHari`). Jam mengikuti zona waktu server (lihat §1f).
5. **IP klien di belakang proxy/load balancer (wajib dicek sebelum go-live).** Allowlist dan bucket kegagalan memakai `getIPAddress()` CI4. Bila server
   di belakang proxy, `REMOTE_ADDR` = IP proxy: allowlist membandingkan IP proxy dan semua klien berbagi satu bucket (satu penyerang anonim dapat
   mengunci semua klien). Cek topologi dulu. Bila ada proxy/LB, buat `REMOTE_ADDR` = IP asli klien di **Nginx** (modul `real_ip`), TANPA
   konfigurasi aplikasi (`$proxyIPs` di `Config/App.php` tidak dapat diisi lewat `.env`):
   ```nginx
   set_real_ip_from <CIDR proxy/LB tepercaya>;
   real_ip_header X-Forwarded-For;
   real_ip_recursive on;
   ```
   Hanya CIDR proxy tepercaya yang boleh di `set_real_ip_from` (header dari sumber lain diabaikan, jadi klien tidak dapat memalsukan IP).
   Uji pasca-deploy (wajib): (a) klien dengan allowlist yang HANYA berisi IP proxy/LB harus DITOLAK; (b) klien dengan allowlist berisi IP asli
   klien harus DITERIMA; (c) 6 percobaan gagal dari satu IP uji tidak boleh mengunci klien lain.
6. **Pemantauan.** Kegagalan auth tercatat di `integrasi_log` (arah masuk; kode `tidak_sah`, `tidak_aktif`, `ip_ditolak`, `hak_kurang`,
   `token_tidak_sah`, `throttle_*`; tanpa username/rahasia/IP). Lonjakan `tidak_sah` dari satu klien = rahasia salah/dirotasi; `ip_ditolak` =
   IP keluar klien berubah.

### 1i. Endpoint kompatibilitas legacy SIMAPI dan `services/s_dm` (MAKE-059) — bagian Jalur A

1. **Routing web server.** Path tetap sama dengan legacy: `/simapi/v1/...` dan `/services/...` harus diteruskan ke `backend/public/index.php`
   v2 (front controller CI4), sama seperti `/api/`. Tanpa ini klien SIMAPI/layanan menerima 404 dari web server. Uji: `GET /simapi/v1/a_dm/jabatan`
   tanpa token harus menjawab HTTP 200 `{"status":"02","msg":"Token is not valid",...}` dari v2.
2. **Endpoint yang sengaja tidak ada di v2** (404): `simapi/v1/a_sinkron/ambil_seluruh_pengguna`, `services/s_dm/p_web_config`. Klien yang masih
   memanggilnya diberi tahu saat re-handshake (§1h langkah 3).
   Sampaikan juga: `a_sinkron/ambil_riwayat_mutasi` kini menjawab token salah/kedaluwarsa dengan "Token is not valid" (legacy: "Invalid Token or
   IP Address"/"Token Expired"), dan `a_dm/jabatan` kini butuh token.
   Foto pegawai (`foto` di `data_umum`/`detil`) kini URL `simapi/v1/a_employee/foto/{nip}` yang WAJIB dipanggil dengan token SIMAPI; foto yang
   belum dimigrasi ke storage v2 bernilai "".
3. **Klien `services/s_dm`** dibuat dengan hak `dm` (§1h langkah 2); seluruh method `s_dm` kini butuh Basic, termasuk `p_*` yang di legacy publik.

---

## 2. Cara push (operator deploy)

Satu kali: tambahkan remote ke bare repo server dev.

```bash
git remote add dev deploy@simpegdev.example.go.id:/srv/git/simpeg-v2.git
```

Yang dideploy hanya `main` yang sudah di-merge lewat alur review (`AGENTS.md`: CR langsung ke `main` setelah quality gate; perubahan skema lewat PR yang disetujui DB Validator). **Jangan push branch kerja ke server Dev** — migration di branch kerja belum lolos DBV.

```bash
git fetch origin
git log -1 --oneline origin/main              # pastikan commit yang akan dideploy
git push dev origin/main:refs/heads/main      # ref tujuan ditulis lengkap: aman juga untuk push pertama
```

Output hook tampil langsung di terminal `git push` (`remote: ==> [deploy] ...`). Baris `==> [deploy]` terakhir menentukan hasilnya (`git push` sendiri tetap sukses, karena ref sudah diterima sebelum hook berjalan):

| Baris `==> [deploy]` terakhir | Arti | Tindakan |
|---|---|---|
| `==> [deploy] SUKSES: current -> <release-id>` | tidak ada migration tertunda, release baru aktif | smoke test |
| `==> [deploy] DITAHAN: release <release-id> ...` | ada migration tertunda; release dibangun tetapi tidak aktif, `current` tetap | §3a |
| `==> [deploy] GAGAL ...` | build atau migrate gagal; `current` tetap | §3 |
| `==> [deploy] DIBATALKAN: ...` | `deploy.env` tidak valid (`RUN_MIGRATIONS` bukan `0`/`1`, atau `DEPLOY_BRANCH` selain `main`); tidak ada yang dibangun | perbaiki `deploy.env` |

Push ke branch selain `main` **tidak** memicu build. Bare repo menolak force-push dan penghapusan branch (`receive.denyNonFastForwards`, `receive.denyDeletes`, §1), jadi `main` di server Dev hanya bisa maju; kalau push ditolak `non-fast-forward`, `main` lokal/`origin/main` Anda tertinggal atau berbeda — `git fetch origin` lalu ulangi, jangan dipaksa.

---

## 3. Kalau deploy gagal

Hook atomic **hanya untuk kode**: symlink `current` baru dipindah setelah semua langkah lolos, jadi aplikasi yang sedang jalan tetap versi sebelumnya. **Database tidak ikut atomic** — migration tidak dibungkus satu transaksi (DDL MySQL auto-commit per statement) dan tidak ikut di-rollback oleh hook maupun `rollback.sh`. Karena itu hook membedakan dua jenis kegagalan.

**a. Gagal sebelum migrate — database tidak diubah.** `composer install`, `npm ci`, dan `npm run build` berjalan sebelum hook menyentuh database, lalu `php spark migrate:status` hanya membaca. Kalau salah satunya gagal (termasuk status migration yang tidak bisa dibaca, mis. DB tidak terjangkau), direktori release baru dihapus:

```
remote: ==> [deploy] GAGAL (exit 1): build 20260916143000-ab12cd34 dihentikan sebelum migrate — database tidak diubah. current tetap -> /var/www/simpeg-v2/releases/20260916120000-9f8e7d6c
remote:     lihat log: /var/www/simpeg-v2/logs/deploy-20260916143000-ab12cd34.log
```

Langkah: baca log (`less /var/www/simpeg-v2/logs/deploy-<release-id>.log`), perbaiki lewat alur review biasa sampai di `main`, lalu push ulang (§2). Tidak perlu rollback.

Satu-satunya efek `migrate:status` ke database: pada DB yang belum punya tabel `migrations` (deploy pertama), CI4 membuat tabel pencatat itu dalam keadaan kosong.

**b. Gagal saat atau setelah migrate — database bisa sudah berubah sebagian.** Terjadi bila `RUN_MIGRATIONS=1`, atau saat migrate manual (§3a). Migration sebelum yang gagal tetap ter-apply, dan migration yang gagal bisa meninggalkan sebagian perubahannya (mis. tabel sudah dibuat tetapi belum tercatat di tabel `migrations`). `php spark migrate` **keluar dengan kode 0 walau migration gagal**; hook karenanya memeriksa ulang `migrate:status` sesudah migrate, dan operator yang menjalankan migrate manual juga wajib memeriksanya. `current` tidak diubah dan release **disimpan**, karena file migration beserta `down()`-nya hanya ada di release itu:

```
remote: ==> [deploy] GAGAL saat/setelah migrate (exit 1): current tetap -> /var/www/simpeg-v2/releases/20260916120000-9f8e7d6c
remote:     DATABASE BISA SUDAH BERUBAH SEBAGIAN (DDL MySQL auto-commit). Release 20260916143000-ab12cd34 DISIMPAN untuk
remote:     migrate:status / migrate:rollback manual dari /var/www/simpeg-v2/releases/20260916143000-ab12cd34/backend — README-deploy.md §3.
```

Langkah:

1. `cd /var/www/simpeg-v2/releases/<release-id>/backend && php spark migrate:status` — baris dengan Batch `---` belum tercatat; bandingkan dengan log.
2. Ikuti bagian rollback di runbook dokumen DBV migration itu (`backend/docs/db-review/`, mis. A-01 Bagian 9.8 langkah 10). Sisa perubahan dari migration yang gagal tidak tercatat, jadi tidak ikut dibatalkan `migrate:rollback`; bereskan sesuai runbook. Batch yang sudah tercatat dibatalkan dari release ini: `php spark migrate:rollback -b <N>` (kembali ke batch N).
3. Jangan aktifkan release ini sebelum `migrate:status` bersih dan runbook terpenuhi. Perbaikan migration masuk lewat PR DBV baru ke `main`, lalu deploy ulang.

Tangani segera: release yang disimpan ikut dipangkas (`KEEP_RELEASES`) setelah beberapa deploy sukses berikutnya. Release ini tidak bertanda `.deploy-ditahan`, jadi tidak ikut dihapus saat release baru ditahan (§3a). `rollback.sh` menolak mengaktifkannya selama `migrate:status`-nya masih punya baris `---`.

## 3a. Migration tertunda: release DITAHAN (`RUN_MIGRATIONS=0`, default)

Bila `migrate:status` menemukan migration yang belum dijalankan, hook mencetak daftarnya dan menahan release. Release itu diberi tanda `releases/<release-id>/.deploy-ditahan` (isinya daftar migration tertunda saat ditahan):

```
remote: !!  Ada migration tertunda:
remote:       App  2026-09-25-130000  AlterAuthKeUnicodeCi
remote:       ...
remote: ==> [deploy] DITAHAN: release 20260930190000-ab12cd34 sudah dibangun tetapi TIDAK diaktifkan — ada migration tertunda,
```

Migration dijalankan manual oleh operator, di luar hook:

1. Pastikan setiap migration di daftar itu sudah disetujui DB Validator (masuk `main` lewat PR DBV) dan baca runbook di dokumen review-nya (`backend/docs/db-review/<dokumen>.md`, mis. A-01 Bagian 9.8): pengecekan server, langkah DBA, backup, pra-cek data, jadwal/pengumuman.
2. Dari release yang ditahan (bukan dari `current`):
   ```bash
   cd /var/www/simpeg-v2/releases/<release-id>/backend
   php spark migrate:status          # daftar harus sama dengan output hook
   php spark migrate --all
   php spark migrate:status          # semua baris harus punya Batch (tidak ada `---`); exit code spark tidak cukup
   ```
   Pastikan grup `database.default.*` di `releases/<release-id>/backend/.env` menunjuk database target (`-g` tidak memindahkan koneksi). File itu salinan `shared/backend.env` saat build — mengubah `shared/backend.env` sesudah release dibangun tidak berpengaruh ke release ini.
3. Verifikasi sesuai runbook (mis. `SHOW CREATE TABLE`, query `information_schema`).
4. Aktifkan release: `/var/www/simpeg-v2/rollback.sh <release-id>` (current -> release itu; release lama jadi `previous`; tanda `.deploy-ditahan` dihapus). `rollback.sh` menjalankan `migrate:status` dari release itu lebih dulu dan **menolak** (`DIBATALKAN`, `current` tidak diubah) bila masih ada migration tertunda atau status tidak bisa dibaca. Jadi langkah ini tidak bisa mendahului langkah 2.
5. Smoke test sesuai runbook.

Kalau langkah 2 gagal: jangan aktifkan release; ikuti §3 bagian **b** (gagal saat atau setelah migrate). Selama langkah 2–4, kode lama (`current`) berjalan di atas skema baru; karena itu runbook menjadwalkan migration di luar jam kerja.

**Selama menunggu approval DBV atau langkah DBA, server Dev tidak menerima kode baru.** Setiap push `main` berikutnya, termasuk perbaikan CR saja, juga `DITAHAN`, karena release barunya memuat migration yang sama. Supaya release yang ditahan tidak menumpuk, hook menghapus release `DITAHAN` yang lebih lama setiap kali release baru ditahan atau diaktifkan. Yang dihapus hanya release bertanda `.deploy-ditahan` dengan id lebih lama. `current`, `previous`, release yang disimpan karena migrate gagal (§3 bagian b), dan release yang sudah diaktifkan tidak disentuh. Aman karena `main` di server Dev hanya maju (§1), jadi release terbaru memuat semua commit dan migration release yang digantikannya. Akibatnya:

- Selalu pakai release-id dari pesan `DITAHAN` **terakhir**.
- **Jangan push ke server Dev selama langkah 2–4 berjalan**, karena release yang sedang dipakai untuk migrate bisa terhapus. Kalau release itu terhapus setelah migrate, `rollback.sh` hanya gagal dengan `Release tidak ditemukan` (tidak ada yang diaktifkan). Kalau terhapus di tengah migrate, migrate bisa berhenti di tengah; perlakukan sebagai §3 bagian b. Dalam kedua kasus, lanjutkan langkah 2–4 dari release `DITAHAN` terbaru. Release itu memuat migration yang sama, dan migration yang sudah tercatat tidak dijalankan ulang.

Membersihkan manual release yang tidak dipakai lagi (mis. release yang disimpan karena migrate gagal, setelah DB dibereskan): pastikan bukan target `current`/`previous` (`readlink -f current previous`), lalu `rm -rf /var/www/simpeg-v2/releases/<release-id>`. Log build di `logs/` boleh tetap ada.

## 4. Rollback (release yang sudah aktif ternyata bermasalah)

Setiap deploy sukses menyimpan release sebelumnya di symlink `previous`, dan `KEEP_RELEASES` release terakhir tetap ada di `releases/`.

```bash
# di server dev
cd /var/www/simpeg-v2
./rollback.sh                          # current -> previous
./rollback.sh 20260916120000-9f8e7d6c  # atau ke release tertentu (ls releases/)
./rollback.sh --force <release-id>     # DARURAT: lewati pemeriksaan migration (lihat di bawah)
```

Sebelum memindah symlink, `rollback.sh` menjalankan `php spark migrate:status` dari release tujuan dan menolak bila ada migration tertunda. Rollback ke release lama tetap lolos, karena semua migration release lama sudah tercatat di database. Pemeriksaan ini fail-closed: kalau status tidak bisa dibaca (mis. database mati) atau formatnya tidak dikenal, `rollback.sh` juga menolak. Dalam keadaan darurat (DB tidak terjangkau tetapi kode harus dikembalikan), pakai `--force`. Opsi ini melewati pemeriksaan, jadi operator sendiri yang memastikan skema database cocok dengan release tujuan. Jangan pakai `--force` untuk mengaktifkan release `DITAHAN` sebelum migrate (§3a).

Rollback hanya memindahkan symlink (detik). **Database tidak ikut di-rollback**: kalau release bermasalah membawa migration yang harus dibatalkan, ikuti runbook DBV-nya dan jalankan dari direktori release **yang membawa migration itu**. Dari release lama CI4 menolak dengan `There is a gap in the migration sequence`, karena file migration-nya tidak ada di sana.

```bash
cd /var/www/simpeg-v2/releases/<release-bermasalah>/backend
php spark migrate:status            # lihat batch terakhir
php spark migrate:rollback -b <N>   # kembali ke batch N (batch > N dibatalkan)
```

Untuk maju lagi setelah perbaikan: merge perbaikan ke `main`, lalu push seperti §2.

---

## 5. Verifikasi lokal hook (tanpa server)

Alur di atas bisa disimulasikan di mesin lokal (dipakai saat validasi F0-18 dan CR-021). Pakai database uji sekali pakai, jangan database dev/test yang sedang dipakai, dan jangan push ke remote mana pun selain bare repo sementara ini.

```bash
BARE=/tmp/simpeg-bare.git; ROOT=/tmp/simpeg-deploy
git init --bare "$BARE"
git -C "$BARE" config receive.denyNonFastForwards true && git -C "$BARE" config receive.denyDeletes true
mkdir -p "$ROOT/shared/writable" && cp -r backend/writable/. "$ROOT/shared/writable/"
cp backend/.env.example "$ROOT/shared/backend.env"   # isi database.default.* (DB uji kosong) & jwt.secret lokal
cp deploy/post-receive "$BARE/hooks/post-receive" && chmod +x "$BARE/hooks/post-receive"
cp deploy/rollback.sh "$ROOT/rollback.sh"
printf 'DEPLOY_ROOT=%s\n' "$ROOT" > "$BARE/hooks/deploy.env"   # main, RUN_MIGRATIONS=0 (default)
ln -s "$BARE/hooks/deploy.env" "$ROOT/deploy.env"             # rollback.sh membaca konfigurasi yang sama
git push "$BARE" HEAD:refs/heads/main   # DB kosong → semua migration tertunda → DITAHAN
ls "$ROOT/releases"                     # release ada, symlink current belum ada
```

Di Windows (Git Bash), jalankan dengan `export MSYS=winsymlinks:nativestrict` (Developer Mode aktif) agar `ln -s` membuat symlink asli, dan pakai path gaya `/tmp/...` untuk `DEPLOY_ROOT` agar hasil `readlink -f` sebanding. Hapus database uji dengan nama persis (``DROP DATABASE `<nama>` ``, bukan pola `LIKE`) dan hapus folder simulasi (berisi salinan `.env`) setelah selesai.

`migration_tertunda` (parser `migrate:status`) sengaja disalin identik di `deploy/post-receive` dan `deploy/rollback.sh`, karena keduanya dipasang terpisah. Setelah mengubah salah satunya, pastikan keduanya masih sama:

```bash
diff <(sed -n '/^migration_tertunda() {/,/^}/p' deploy/post-receive) <(sed -n '/^migration_tertunda() {/,/^}/p' deploy/rollback.sh)
```

Skenario minimal yang diuji ulang bila hook atau `rollback.sh` diubah:

| Skenario | Cara | Harapan |
|---|---|---|
| Push biasa, ada migration tertunda | `RUN_MIGRATIONS` tidak diisi | `DITAHAN`; migration tidak dijalankan; `current` tidak berubah; ada `.deploy-ditahan` |
| Push lagi saat masih ditahan | commit apa pun | `DITAHAN`; release `DITAHAN` lama dihapus, release yang disimpan karena migrate gagal tetap ada |
| Aktifkan sebelum migrate | `rollback.sh <release-ditahan>` | `DIBATALKAN` berisi daftar migration tertunda; `current` tidak berubah |
| Migrate manual + aktifkan | §3a langkah 2 dan 4 dari release yang ditahan | migrate bersih; `current` -> release itu; `previous` tidak dibuat bila sebelumnya belum ada `current`; tanda `.deploy-ditahan` hilang |
| Push biasa tanpa migration baru | commit tanpa migration | `SUKSES`; tabel `migrations` tidak berubah |
| Rollback ke release lama | `rollback.sh` tanpa argumen setelah release pembawa migration aktif | lolos pemeriksaan (migration release lama sudah tercatat) |
| Status tidak terbaca / format asing | DB release tidak ada; `PHP_BIN` palsu yang mengganti header `Batch` | hook: `GAGAL ... sebelum migrate`; `rollback.sh`: `DIBATALKAN`; `--force` melewati pemeriksaan |
| Build gagal | commit dengan impor modul frontend yang tidak ada, `RUN_MIGRATIONS=1`, plus migration baru | `GAGAL ... database tidak diubah`; release dihapus; migration baru tidak jalan |
| `RUN_MIGRATIONS=1` eksplisit | `deploy.env` berisi `RUN_MIGRATIONS=1` | migrate setelah build, lalu `SUKSES` |
| Migration gagal di tengah | `RUN_MIGRATIONS=1`, migration yang melempar exception | `GAGAL saat/setelah migrate`; release disimpan tanpa `.deploy-ditahan`; `current` tetap |
| Branch lain / konfigurasi salah | push ke branch lain; `DEPLOY_BRANCH=dev` di `deploy.env`; `RUN_MIGRATIONS=yes`; `RUN_MIGRATIONS=1`/`DEPLOY_BRANCH=dev` hanya di environment `git push` | dilewati; `DIBATALKAN`; `DIBATALKAN`; diabaikan (tetap `main`, `0`) |
| Force-push / hapus branch | `git push --force ... HEAD~1:refs/heads/main`; `git push ... :refs/heads/main` | ditolak bare repo (`non-fast-forward`, `deletion prohibited`) |
| `DEPLOY_ROOT` rollback | panggil `rollback.sh` dengan `DEPLOY_ROOT=/salah` di environment, dengan dan tanpa symlink `deploy.env` | environment diabaikan; root dari `deploy.env` atau lokasi skrip |
