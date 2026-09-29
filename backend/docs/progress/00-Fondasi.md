# Progress Fase 0 — Fondasi

Catatan progres Fase 0 yang masih terbuka. Hasil QA seluruh task Fase 0: `docs/qa/QAFUNC-001-fase-0-fondasi.md` (17 dari 18 QASMTASK Pass; sisa QASMTASK-018). Panduan deploy: `README-deploy.md`.

**Terakhir diperbarui:** 30 September 2026 (CR-021: ISSUE-014 opsi A di branch `cr-021/deploy-migrate-manual`, CR saja tanpa DBV, menunggu review)

| Task | Status | Ringkas |
|---|---|---|
| F0-18 Deploy Dev (bare repo + hook `post-receive`) | IN_PROGRESS | Hook + README + `rollback.sh` ada di main; push-trigger build terbukti lewat simulasi lokal. QASMTASK-018 tetap Todo sampai hook diuji di server Dev (server belum ada). CR-021: hook tidak lagi menjalankan migration (ISSUE-014) |

## CR-021 — ISSUE-014 opsi A: migration Dev manual lewat runbook (branch `cr-021/deploy-migrate-manual`, menunggu review)

Keputusan user 29-09-2026 membuka HOLD ISSUE-014 (sejak 23-09) dengan opsi A: `RUN_MIGRATIONS=0` default di hook deploy Dev, deploy hanya dari `main`, migrate manual lewat runbook setelah approval DB Validator.

Perubahan:

- `deploy/post-receive`
  - `RUN_MIGRATIONS` default `0` dan `DEPLOY_BRANCH` default `main`. Keduanya hanya dibaca dari `deploy.env`; environment proses git diabaikan.
  - `RUN_MIGRATIONS=1` hanya diizinkan untuk `main`. Nilai selain `0`/`1` → `DIBATALKAN`.
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

Sisa:

- Review CR-021 (CR saja → merge ke `main` setelah quality gate).
- Server Dev (QASMTASK-018).
- Keputusan D-11 (`sql_mode`) dan zona waktu (ISSUE-022) lewat DBV.
