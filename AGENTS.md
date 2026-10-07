# Aturan untuk coder & agen AI — SIMPEG v2

File ini dibaca oleh setiap coder dan agen AI (Claude Code lewat `CLAUDE.md`, Codex, Cursor, Copilot, dll.) yang
mengerjakan repo ini. Aturan di sini **wajib** diikuti; bila ada aturan yang bertentangan dengan permintaan tugas,
tanyakan dulu ke reviewer CR sebelum menyimpang.

## 1. UI: aksi baris tabel lewat menu titik tiga (⋮)

Tujuannya supaya semua tabel **sama/seragam**.

**Berlaku untuk:** setiap aksi per baris di halaman tabel / daftar data (Master Data, Manajemen Akun, Hari Libur,
FAQ admin, dan semua tabel baru di fase berikutnya).

**Tidak berlaku untuk:** menu navigasi, top bar, sidebar, tombol level halaman (mis. "Tambah …"), filter & pencarian,
paginasi, serta tombol di dalam form atau dialog.

Wajib:

1. Semua aksi baris ada di **satu tombol ⋮** di kolom **Aksi** paling kanan, memakai komponen
   `frontend/src/shared/components/RowActionsMenu.vue` (tipe `RowAction` di `rowActions.ts`). Jangan membuat tombol
   ikon aksi, tautan aksi, atau switch status terpisah per baris.
2. Urutan item: **Edit** → ubah status (**Aktifkan** / **Nonaktifkan**) → urutan (**Naikkan urutan** /
   **Turunkan urutan**) → aksi lain (mis. **Pulihkan**) → aksi destruktif (**Hapus**) paling bawah dengan
   `danger: true` (merah, otomatis diberi garis pemisah).
3. Label item: kata kerja Bahasa Indonesia yang sama persis di semua tabel — "Edit", "Aktifkan", "Nonaktifkan",
   "Naikkan urutan", "Turunkan urutan", "Pulihkan", "Hapus". Aksi baru memakai kata kerja singkat yang serupa.
4. Aksi yang tidak berlaku untuk baris itu → `hidden: true` (mis. Pulihkan pada baris aktif). Aksi yang berlaku tetapi
   sedang tidak boleh → `disabled: true` (mis. menghapus akun sendiri, baris pertama tidak bisa dinaikkan, sedang
   memproses).
5. Kolom **Status** hanya menampilkan badge (Aktif / Tidak Aktif / Dihapus); perubahan status lewat menu ⋮.
6. Aksi destruktif atau berisiko tetap memakai `ConfirmDialog` sebelum dijalankan.
7. Tombol ⋮ wajib diberi `label` "Aksi untuk <nama baris>" (jadi aria-label) dan `testid` `<fitur>-actions-<id>`.
   Test Vitest membuka menu lalu memilih item berdasarkan `data-action`, bukan mengklik tombol ikon.

## 2. Aturan proyek yang sudah berlaku (ringkas)

- **Commit & PR:** pesan Bahasa Indonesia gaya `feat(scope): …` / `fix(scope): …` dengan key review (`CR-###`,
  `DBV-###`). Jangan menyebut AI/assistant/tool apa pun dan jangan menambah trailer `Co-Authored-By`.
- **Rahasia:** jangan commit `.env`, kredensial, token, password, atau API key.
- **Alur merge:** hanya `[CR]` → langsung ke `main` setelah quality gate lolos; `[CR]` + `[DBV]` → PR, DB Validator
  review/approve, reviewer CR yang merge; hanya `[DBV]` → PR, DB Validator yang merge.
- **Quality gate:** `./check.sh` (PHPStan level 5, PHP-CS-Fixer, PHPUnit, ESLint, vue-tsc, Vitest, build) harus lolos
  sebelum push ke `main` — cara menjalankannya secara efisien ada di bagian 3.
- **Skema database:** ikut kode & DDL legacy (label `[K]` / `[V2]` / `[I]`), status 1 / 2 / 10, collation
  `utf8mb4_unicode_ci`, migration yang sudah ada di `main` tidak diedit, dan setiap perubahan skema direview DB
  Validator lewat dokumen di `backend/docs/db-review/`.

## 3. Quality gate efisien (gate penuh sekali, gate cepat di tahap lain)

Tujuannya supaya satu perubahan tidak di-gate penuh berulang kali (PHPUnit penuh ±80 menit di mesin lokal).

**Gate penuh — hanya SEKALI, tepat sebelum push ke `main`**, pada commit yang benar-benar akan di-push (untuk PR:
commit merge-nya). `./check.sh` / setara: PHPStan, PHP-CS-Fixer, **PHPUnit penuh**, ESLint, vue-tsc, Vitest, build.

**Gate penuh di CI (CR-044).** Workflow `.github/workflows/quality-gate.yml` (GitHub Actions) menjalankan gate penuh
pada setiap push ke branch mana pun dan PR dari fork: PHPStan + CS-Fixer, frontend, dan PHPUnit ter-shard di
**MySQL 8.0** dan **MariaDB 10.4**. Gate CI ini **menggantikan** gate penuh lokal — di lokal cukup gate cepat. Push
branch dulu, tunggu CI **hijau** untuk SHA itu, baru push SHA yang sama ke `main` / merge PR. **Jangan push/merge ke
`main` bila CI merah atau belum selesai.** Perubahan yang hanya dokumen (`*.md`, `docs/**`) tidak memicu CI.

**Gate cepat — untuk tahap lain** (mengerjakan PR, perbaikan review, sinkron branch dengan `main`), ±5–10 menit:

1. PHPStan level 5 + PHP-CS-Fixer dry-run (`cd backend && composer analyse && composer cs-check`).
2. Frontend bila ada perubahan frontend (`cd frontend && npm run check`).
3. PHPUnit **hanya file/folder yang disentuh** dan area yang bersinggungan, mis.
   `cd backend && vendor/bin/phpunit --no-coverage tests/MasterData/LokasiPresensiTest.php tests/MasterData/MasterGenericTcTest.php`.

**Kapan gate penuh TIDAK perlu diulang:**

- `main` bergeser **hanya karena dokumen** (`*.md`, `docs/**`) setelah gate penuh lolos → cukup rebase/merge, tanpa gate ulang.
- Tree commit yang akan di-push **identik** dengan commit yang sudah lolos gate penuh (cek `git diff --stat <commit-gate> HEAD` kosong,
  atau hanya berisi dokumen).
- `main` bergeser karena **kode** → cukup PHPUnit terarah pada area yang bersinggungan dengan perubahan baru di `main`;
  gate penuh ulang hanya bila keduanya menyentuh berkas/modul yang sama.

**Jalankan gate panjang sebagai proses lepas (detached).** Jangan jalankan gate penuh sebagai proses anak yang bisa
terhenti oleh batas waktu terminal/agen (umumnya 60–120 menit) — gate yang terpotong harus diulang dari awal.
Jalankan sebagai proses terpisah dengan output ke berkas log, lalu pantau lognya, mis.:

```powershell
Start-Process -FilePath bash -ArgumentList '-lc', './check.sh > gate.log 2>&1; echo "EXIT=$?" >> gate.log' -WindowStyle Hidden
```

```bash
nohup ./check.sh > gate.log 2>&1 &
```

Tambahan:

- **Satu gate penuh pada satu waktu** per mesin; gate paralel di satu MySQL membuat semuanya lambat. Tunggu gate lain selesai.
- Reviewer/verifikator **tidak** menjalankan gate penuh — cukup test terarah; gate penuh dijalankan sekali oleh yang akan push.
- Gate memakai **database test/scratch tersendiri**, bukan database dev; jangan menyalin `.env` dev ke worktree lain.
