# Progress Fase 0 — Fondasi

Catatan progres Fase 0 yang masih terbuka. Hasil QA seluruh task Fase 0: `docs/qa/QAFUNC-001-fase-0-fondasi.md` (17 dari 18 QASMTASK Pass; sisa QASMTASK-018). Panduan deploy: `README-deploy.md`.

**Terakhir diperbarui:** 30 September 2026 (CR-021: ISSUE-014 opsi A di branch `cr-021/deploy-migrate-manual`, CR saja tanpa DBV; temuan review adversarial sudah ditangani, menunggu review ulang)

| Task | Status | Ringkas |
|---|---|---|
| F0-18 Deploy Dev (bare repo + hook `post-receive`) | IN_PROGRESS | Hook + README + `rollback.sh` ada di main; push-trigger build terbukti lewat simulasi lokal. QASMTASK-018 tetap Todo sampai hook diuji di server Dev (server belum ada). CR-021: hook tidak lagi menjalankan migration, deploy hanya dari `main`, `rollback.sh` menolak release yang migration-nya tertunda (ISSUE-014) |

## CR-021 — ISSUE-014 opsi A: migration Dev manual lewat runbook (branch `cr-021/deploy-migrate-manual`, menunggu review)

Keputusan user 29-09-2026 membuka HOLD ISSUE-014 (sejak 23-09) dengan opsi A: `RUN_MIGRATIONS=0` default di hook deploy Dev, deploy hanya dari `main`, migrate manual lewat runbook setelah approval DB Validator.

Perubahan:

- `deploy/post-receive`
  - `RUN_MIGRATIONS` default `0`, hanya dibaca dari `deploy.env`; environment proses git diabaikan. Nilai selain `0`/`1` → `DIBATALKAN`.
  - Branch yang dideploy selalu `main` (revisi review: `DEPLOY_BRANCH` bukan lagi pilihan; `deploy.env` yang masih mengisinya dengan nilai selain `main` → `DIBATALKAN`).
  - Urutan baru: composer → `npm ci`/`npm run build` → `migrate:status` (hanya membaca). Build yang gagal tidak menyentuh DB.
  - Bila ada migration tertunda, release **ditahan**: dibangun tetapi tidak diaktifkan, dengan daftar migration dan langkah manual. Kode baru tidak berjalan di atas skema lama.
  - Dengan `RUN_MIGRATIONS=1`, `migrate --all` dijalankan setelah build lalu dicek ulang lewat `migrate:status`. Alasannya, `spark migrate` keluar 0 walau migration melempar exception. Pada hook lama, migration yang gagal tetap berlanjut ke build dan pindah symlink.
  - Bila gagal saat/setelah migrate, release disimpan (file `down()` ada di sana) dan hook memperingatkan bahwa DB bisa sudah berubah sebagian.
- `deploy/rollback.sh`
  - Dipakai juga untuk mengaktifkan release yang ditahan.
  - Tidak lagi membuat `previous` yang menunjuk ke `current` bila `current` belum ada (terjadi pada release pertama yang ditahan).
  - Komentar rollback DB dikoreksi: rollback DB dijalankan dari release yang membawa migration, bukan dari `current` lama, karena CI4 menolak dengan "gap".
- `README-deploy.md`
  - §1: contoh `deploy.env`.
  - §2: push `origin/main:refs/heads/main`, bukan branch kerja.
  - §3: koreksi klaim atomic. Atomic hanya untuk kode; DB tidak ikut rollback dan bisa ter-migrate sebagian.
  - §3a (baru): langkah migrate manual.
  - §4: lokasi rollback DB.
  - §5: simulasi dan skenario uji.
- Dokumen DBV (prosedur saja, tanpa perubahan skema):
  - Runbook A-01 Bagian 9.8 langkah 6, 8, 9 (sebelumnya "hook `migrate --all`"), dan 10, serta paragraf kompatibilitas 9.4.
  - Status G-01 dan G-10: frasa "HOLD" diganti rujukan ke §3a.

Simulasi lokal (README-deploy §5; bare repo + DB uji sekali pakai di scratchpad, MySQL 8.0.30, Git Bash + symlink asli; tidak ada remote yang disentuh):

| Skenario | Hasil |
|---|---|
| `RUN_MIGRATIONS=1` + `DEPLOY_BRANCH=dev`; `RUN_MIGRATIONS=yes` | `DIBATALKAN`, tidak ada release, DB tidak disentuh ✅ |
| Push ke branch `dev` | dilewati ✅ |
| Push `main` ke DB kosong, `RUN_MIGRATIONS=1` dan `DEPLOY_BRANCH=dev` hanya di environment `git push` | environment diabaikan; `DITAHAN` dengan 23 migration tertunda; `current` tidak dibuat; DB hanya berisi tabel `migrations` kosong (dibuat CI4 saat `migrate:status`) ✅ |
| Migrate manual dari release yang ditahan + `rollback.sh <id>` | 23 migration batch 1; `current` → release itu, tanpa `previous` palsu ✅ |
| Push biasa tanpa migration baru | `SUKSES`; tabel `migrations` identik (CHECKSUM sama) ✅ |
| `RUN_MIGRATIONS=1` eksplisit + 1 migration baru | migrate setelah build, `SUKSES`, batch 2 ✅ |
| Build frontend gagal (impor modul tidak ada) + migration baru, `RUN_MIGRATIONS=1` | `GAGAL ... database tidak diubah`; release dihapus; migration baru tidak jalan; `current` tetap ✅ |
| `RUN_MIGRATIONS=1`, migration melempar exception setelah membuat tabel | `spark migrate` exit 0, hook mendeteksi lewat `migrate:status`; `GAGAL saat/setelah migrate`; release disimpan; `current` tetap ✅ |
| `RUN_MIGRATIONS=0` eksplisit, DB berisi, 1 migration tertunda | `DITAHAN` dengan tepat 1 migration; DB tidak berubah ✅ |
| `migrate:rollback -b 2` dari release lama vs release pembawa migration | release lama ditolak CI4 ("gap"), release pembawa berhasil ✅ |
| Database tidak ada (status tidak terbaca) | `GAGAL (exit 1) ... sebelum migrate`; release dihapus ✅ |

Setelah perbaikan terakhir, versi final hook diuji ulang dengan `DEPLOY_ROOT` baru. Semua skenario berikut lolos:

- `DITAHAN` tanpa `current` (pesan "current tetap -> (belum ada)").
- Aktivasi pertama lewat `rollback.sh`.
- `SUKSES` tanpa migration.
- Build gagal.
- `RUN_MIGRATIONS=1` + migration baru.
- `DIBATALKAN`.
- Branch lain dilewati.

### Revisi setelah review adversarial CR-021 (30-09-2026, di-rebase ke `main` `ff74bf9`)

Review menyetujui (tanpa blocker/major). Semua temuan minor dan nit ditangani:

| Temuan | Penanganan |
|---|---|
| "Deploy hanya dari main" masih bisa dilanggar lewat `DEPLOY_BRANCH` | Branch tetap `main` di hook; `DEPLOY_BRANCH` selain `main` di `deploy.env` → `DIBATALKAN` (tidak diabaikan diam-diam, karena default lama `dev`) |
| `rollback.sh` mengaktifkan release DITAHAN tanpa cek | `rollback.sh` menjalankan `migrate:status` dari release tujuan dan menolak bila ada migration tertunda atau status tidak terbaca (fail-closed); darurat: `--force` (README §4) |
| `rollback.sh` memakai `DEPLOY_ROOT` default, bukan root hook | `rollback.sh` membaca `deploy.env` di sampingnya (symlink ke `hooks/deploy.env`, README §1 langkah 6) untuk `DEPLOY_ROOT`/`PHP_BIN`; tanpa itu root = lokasi skrip; environment diabaikan |
| Parser `migrate:status` tidak memvalidasi header | Kolom dicari dari header (Namespace, Version, Filename, Batch); jumlah kolom tiap baris wajib sama; Batch wajib `---`/angka; selain itu fail-closed. Fungsi identik di hook dan `rollback.sh` (dicek `diff`, README §5) |
| README §3a merujuk "§3b" yang tidak ada | Diganti "§3 bagian b" |
| Release DITAHAN menumpuk | Release diberi tanda `.deploy-ditahan`; release DITAHAN yang lebih lama dihapus saat release baru ditahan/diaktifkan (bukan `current`/`previous`/release gagal-migrate); `rollback.sh` menghapus tanda saat mengaktifkan; README §3a menjelaskan akibatnya (Dev tidak menerima kode baru sampai migrate, jangan push selama migrate manual, cara bersih manual) |
| `main` di bare repo bisa di-force-push/dihapus | README §1 dan §5: `receive.denyNonFastForwards true`, `receive.denyDeletes true` |
| Bentrok merge `01-Auth.md` dengan cr-022 | Tidak mengubah kode; diselesaikan saat urutan merge (cr-022 belum ada di `main` saat rebase) |

Simulasi ulang (README §5; bare repo + DB uji sekali pakai `cr21b_deploysim`, `DEPLOY_ROOT` gaya `/tmp`, stub composer/npm, PHP asli; tidak ada remote yang disentuh):

| Skenario | Hasil |
|---|---|
| `DEPLOY_BRANCH=dev` di `deploy.env` (push `main` dan `dev`); `DEPLOY_BRANCH=` kosong; `RUN_MIGRATIONS=yes` | `DIBATALKAN`, tidak ada release, DB kosong ✅ |
| Push `main` ke DB kosong, `DEPLOY_BRANCH=main` eksplisit, `RUN_MIGRATIONS=1`/`DEPLOY_BRANCH=dev` hanya di environment `git push` | environment diabaikan; `DITAHAN` 23 migration; `.deploy-ditahan` 23 baris; `current` tidak dibuat ✅ |
| Push kedua saat masih ditahan | `DITAHAN`; release DITAHAN lama dihapus, tinggal satu ✅ |
| `rollback.sh <release-ditahan>` sebelum migrate (dari `cwd=/`, `DEPLOY_ROOT=/tidak/ada` di environment) | `DIBATALKAN` dengan daftar 23 migration, exit 1; `current` tidak dibuat ✅ |
| Migrate manual + `rollback.sh <id>` | 23 migration batch 1; `current` aktif, tanpa `previous`; tanda `.deploy-ditahan` hilang ✅ |
| Push tanpa migration | `SUKSES`; CHECKSUM `migrations` sama ✅ |
| Push dengan 1 migration baru (`RUN_MIGRATIONS=0`) | `DITAHAN` 1 migration; CHECKSUM sama ✅ |
| `rollback.sh` ke `previous` (release lama) lalu kembali | keduanya lolos pemeriksaan ✅ |
| `rollback.sh <release-ditahan>` dengan 1 migration tertunda | `DIBATALKAN` (1 migration), exit 1 ✅ |
| DB release tidak ada | `rollback.sh`: `DIBATALKAN` "tidak bisa dibaca" (spark exit 8); `--force`: lolos dengan peringatan ✅ |
| `PHP_BIN` palsu yang mengganti header `Batch` | hook: `GAGAL ... sebelum migrate`, release dihapus, CHECKSUM sama; `rollback.sh`: `DIBATALKAN` ✅ |
| Argumen salah (`--paksa`, dua argumen, `../x`); `rollback.sh` dijalankan dari repo | ditolak (exit 2/2/1); "DEPLOY_ROOT tidak valid" ✅ |
| `RUN_MIGRATIONS=1` + migration tertunda + release DITAHAN lama | migrate, `SUKSES`, release DITAHAN lama dihapus ✅ |
| Build gagal + migration baru, `RUN_MIGRATIONS=1` | `GAGAL ... database tidak diubah`; release dihapus; tabel baru tidak dibuat ✅ |
| Migration melempar exception, `RUN_MIGRATIONS=1` | `GAGAL saat/setelah migrate`; release disimpan tanpa `.deploy-ditahan`; `current` tetap ✅ |
| Dua push `DITAHAN` berikutnya | release DITAHAN lama dihapus; release gagal-migrate tetap ada; `rollback.sh` ke release gagal-migrate `DIBATALKAN` ✅ |
| `KEEP_RELEASES=1` saat `SUKSES` | tersisa `current` + `previous` ✅ |
| `rollback.sh` tanpa symlink `deploy.env`, `DEPLOY_ROOT=/salah` di environment | root = lokasi skrip ✅ |
| Force-push `HEAD~3:main`; hapus `main`; push branch lain | ditolak `non-fast-forward`; ditolak `deletion prohibited`; dilewati ✅ |

Uji unit tambahan: parser dengan 14 kasus output palsu, dijalankan untuk fungsi dari kedua skrip (juga dengan `gawk --posix`): semua sudah jalan, tertunda, CRLF, warna ANSI, urutan kolom lain → benar; header lain, tanpa header, kolom kurang, Batch aneh, tabel tanpa baris, "No migrations were found", output kosong, exit 8, exit 1 → fail-closed. `prune_ditahan` dengan release sintetis: hanya release DITAHAN lebih lama yang bukan `current`/`previous` yang dihapus. Release tanpa tanda dan release DITAHAN yang lebih baru tetap ada.

Sisa:

- Review CR-021 (CR saja → merge ke `main` setelah quality gate).
- Server Dev (QASMTASK-018).
- Keputusan D-11 (`sql_mode`) dan zona waktu (ISSUE-022) lewat DBV.
