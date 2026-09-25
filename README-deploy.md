# SIMPEG v2 — Deploy ke Server Dev (F0-18)

Pipeline deploy dev memakai **git bare repo + hook `post-receive`** (Tech Spec 1.4 [P]). Tidak ada CI/CD server (ADR-017): quality gate dijalankan lokal lewat `./check.sh` sebelum push.

```
developer ── git push dev ──▶ bare repo (server dev) ── hook post-receive ──▶ build otomatis
                                                                          composer install --no-dev
                                                                          php spark migrate --all
                                                                          npm ci && npm run build
                                                                          symlink current -> release baru
```

File terkait di repo ini:

| File | Fungsi |
|---|---|
| `deploy/post-receive` | Hook yang dipasang di `<BARE_REPO>/hooks/post-receive` di server dev |
| `deploy/rollback.sh` | Pindahkan symlink `current` ke release sebelumnya / release tertentu |
| `check.sh` | Quality gate lokal (wajib lolos sebelum push) |

---

## 1. Setup satu kali di server dev

Prasyarat di server: `git`, `php >= 8.2` (+ ext mysqli, intl, mbstring, json), `composer`, `node >= 20` + `npm`, MySQL 8.x, web server (nginx/Apache).

```bash
# 1) Bare repo
sudo mkdir -p /srv/git/simpeg-v2.git && cd /srv/git/simpeg-v2.git
sudo git init --bare
sudo chown -R deploy:deploy /srv/git/simpeg-v2.git

# 2) Struktur aplikasi
sudo mkdir -p /var/www/simpeg-v2/{releases,shared/writable,logs}
sudo chown -R deploy:deploy /var/www/simpeg-v2

# 3) .env backend (berisi secret — TIDAK dari git). Isi dari backend/.env.example
cp backend/.env.example /var/www/simpeg-v2/shared/backend.env
vi /var/www/simpeg-v2/shared/backend.env      # CI_ENVIRONMENT=development, database.*, jwt.secret, cors.allowedOrigins, dst.
# Lupa password (ISSUE-006): auth.resetLinkBase = URL frontend server ini + /reset-password. Driver
# auth.resetTokenNotifier=log menulis tautan reset ke writable/logs dan DITOLAK di production (driver email menyusul).

# (opsional) .env.local frontend — VITE_PASSWORD_RESET_ENABLED=true hanya kalau kanal reset di backend aktif
printf 'VITE_API_BASE_URL=https://simpegdev.example.go.id/api/v1\n' > /var/www/simpeg-v2/shared/frontend.env.local

# 4) writable/ persisten (cache, logs, uploads) — salin dari backend/writable repo
cp -r backend/writable/. /var/www/simpeg-v2/shared/writable/ && chmod -R ug+rw /var/www/simpeg-v2/shared/writable

# 5) Pasang hook + konfigurasinya
cp deploy/post-receive /srv/git/simpeg-v2.git/hooks/post-receive
chmod +x /srv/git/simpeg-v2.git/hooks/post-receive
cat > /srv/git/simpeg-v2.git/hooks/deploy.env <<'EOF'
DEPLOY_ROOT=/var/www/simpeg-v2
DEPLOY_BRANCH=dev
KEEP_RELEASES=5
RUN_MIGRATIONS=1
# PHP_BIN=/usr/bin/php8.3  COMPOSER_BIN=/usr/local/bin/composer  NPM_BIN=/usr/bin/npm
EOF

# 6) rollback helper
cp deploy/rollback.sh /var/www/simpeg-v2/rollback.sh && chmod +x /var/www/simpeg-v2/rollback.sh
```

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
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

> Catatan infrastruktur (ADR Bagian 5): BSrE (10.10.100.132) dan server Arsip (172.17.100.84) berada di jaringan internal — server dev harus berada di jaringan yang sama / VPN saat integrasi real (Fase 6).

---

## 2. Cara push (developer)

Satu kali: tambahkan remote ke bare repo server dev.

```bash
git remote add dev deploy@simpegdev.example.go.id:/srv/git/simpeg-v2.git
```

Setiap task selesai (SOP Bagian 2, langkah 5-6):

```bash
./check.sh                       # WAJIB lolos bersih (exit 0)
git push dev HEAD:dev            # push branch kerja ke branch `dev` di server
```

Output hook tampil langsung di terminal `git push` (`remote: ==> [deploy] ...`). Deploy dianggap sukses kalau baris terakhir `==> [deploy] SUKSES: current -> <release-id>`.

Push ke branch selain `dev` **tidak** memicu build (hook hanya mendeploy `DEPLOY_BRANCH`).

---

## 3. Kalau build gagal

Hook bersifat **atomic**: kalau `composer install`, `migrate`, `npm ci`, atau `npm run build` gagal, direktori release baru dihapus dan symlink `current` **tidak diubah** — aplikasi yang sedang jalan tetap versi sebelumnya. Output `git push` akan menampilkan:

```
remote: ==> [deploy] GAGAL: build 20260916143000-ab12cd34 dihentikan. current tetap -> /var/www/simpeg-v2/releases/20260916120000-9f8e7d6c
remote:     lihat log: /var/www/simpeg-v2/logs/deploy-20260916143000-ab12cd34.log
```

Langkah:

1. Baca log di server: `less /var/www/simpeg-v2/logs/deploy-<release-id>.log`
2. Perbaiki di lokal, jalankan `./check.sh`, push ulang.

Tidak perlu rollback — tidak ada yang berubah di `current`.

## 4. Rollback (release yang sudah aktif ternyata bermasalah)

Setiap deploy sukses menyimpan release sebelumnya di symlink `previous`, dan `KEEP_RELEASES` release terakhir tetap ada di `releases/`.

```bash
# di server dev
cd /var/www/simpeg-v2
./rollback.sh                          # current -> previous
./rollback.sh 20260916120000-9f8e7d6c  # atau ke release tertentu (ls releases/)
```

Rollback hanya memindahkan symlink (detik). **Database tidak ikut di-rollback**: kalau release bermasalah membawa migration baru yang harus dibatalkan, jalankan manual di release yang bermasalah:

```bash
cd /var/www/simpeg-v2/releases/<release-bermasalah>/backend
php spark migrate:status            # lihat batch terakhir
php spark migrate:rollback -b <N>   # kembalikan ke batch N
```

Untuk maju lagi setelah perbaikan: cukup `git push dev` seperti biasa.

---

## 5. Verifikasi lokal hook (tanpa server)

Alur di atas bisa disimulasikan di mesin lokal untuk memastikan hook bekerja (dipakai saat validasi F0-18):

```bash
BARE=/tmp/simpeg-bare.git; ROOT=/tmp/simpeg-deploy
git init --bare "$BARE"
mkdir -p "$ROOT/shared" && cp backend/.env.example "$ROOT/shared/backend.env"   # isi database.* & jwt.secret lokal
cp deploy/post-receive "$BARE/hooks/post-receive" && chmod +x "$BARE/hooks/post-receive"
printf 'DEPLOY_ROOT=%s\nDEPLOY_BRANCH=dev\n' "$ROOT" > "$BARE/hooks/deploy.env"
git push "$BARE" HEAD:dev          # hook jalan: composer install, migrate, npm ci, build
ls -l "$ROOT/current"              # -> releases/<id>
```
