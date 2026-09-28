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
- **Quality gate:** `./check.sh` (PHPStan level 5, PHP-CS-Fixer, PHPUnit, ESLint, vue-tsc, Vitest, build) harus lolos.
- **Skema database:** ikut kode & DDL legacy (label `[K]` / `[V2]` / `[I]`), status 1 / 2 / 10, collation
  `utf8mb4_unicode_ci`, migration yang sudah ada di `main` tidak diedit, dan setiap perubahan skema direview DB
  Validator lewat dokumen di `backend/docs/db-review/`.
