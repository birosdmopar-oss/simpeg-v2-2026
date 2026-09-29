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

Prasyarat di server: `git`, `php >= 8.2` (+ ext mysqli, intl, mbstring, json), `composer`, `node >= 20` + `npm`, MySQL 8.x, web server (nginx/Apache).

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
# Lupa password (ISSUE-006): auth.resetLinkBase = URL frontend server ini + /reset-password. Driver
# auth.resetTokenNotifier=log menulis tautan reset ke writable/logs dan DITOLAK di production (driver email menyusul).
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
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

> Catatan infrastruktur (ADR Bagian 5): BSrE (10.10.100.132) dan server Arsip (172.17.100.84) berada di jaringan internal — server dev harus berada di jaringan yang sama / VPN saat integrasi real (Fase 6).

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
   Pastikan grup `database.default.*` di `shared/backend.env` menunjuk database target (`-g` tidak memindahkan koneksi).
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
