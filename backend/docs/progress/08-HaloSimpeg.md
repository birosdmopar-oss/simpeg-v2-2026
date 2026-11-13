# Progress Fase 8 — Modul E: Halo Simpeg

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak: `docs/fase8/PRD_Fase8_HaloSimpeg.md` (v1.1), rincian jalur
`docs/fase8/8_1.md` (BE) dan `8_2.md` (FE). Berkas ini dipakai bersama jalur helpdesk dan jalur chatbot: tiap jalur menambah
bagiannya sendiri secara aditif.

## E-10 Chatbot "Vanya" — bagian BE (DBV-033, MAKE-094)

**Terakhir diperbarui:** 13 November 2026 — MAKE-094 di branch `fase8/make-094-chatbot-be`, menunggu gate dan review reviewer CR.
Kontrak endpoint (beku, untuk MAKE-095): `backend/app/Controllers/Api/HaloSimpeg/Chatbot/README.md`.

| Task | Key | Status | Ringkas |
|---|---|---|---|
| C1 Skema chatbot | DBV-033 | DONE (main `3fb8c5f7`, PR #40) | 6 tabel, status 1/2/10, `judul_unik` STORED + UNIQUE, relasi PK komposit, `log_chatbot` anonim + `idx_log_waktu`; dokumen `backend/docs/db-review/DBV-033-skema-chatbot.md` |
| C2 Backend chatbot | MAKE-094 | IN_PROGRESS (menunggu review) | rincian di bawah |
| C4 Impor isi QnA dev | (key menyusul, F8-51) | TODO | menunggu keputusan PN8-6 saat deploy |

**C2 MAKE-094, isi:**
- **Mesin:** `TfIdf` + `MesinJawab` (murni), port legacy dev apa adanya.
  - Test pembanding dengan 11 skenario §7.10.7 sama dengan hasil mesin dev (toleransi 0,0001).
  - Skenario 12 (tag ganda dev) dan 13 (HTML, `xss_clean` dev) dicatat sebagai beda yang diharapkan.
- **Endpoint (§6.5):**
  - widget (role 2/6/7): kategori, pertanyaan, populer, balas, feedback;
  - admin (role 1): kategori/tag/QnA CRUD + `PATCH …/status`, `qna/opsi`, `qna/template`, `qna/impor`, `tag/ekspor`,
    `statistik`, `riwayat`.
- **Route:** `Config/RoutesChatbot.php`, didaftarkan dengan satu entri di `Config/Routing.php`.
- **Konfigurasi:** `Config\Chatbot` memuat semua angka.
- **Token feedback F8-35:** kunci `chatbot.kunciTokenFeedback` di `.env.example` (nama saja); pemeriksaannya di README-deploy §1m.
- **Throttle F8-36:** tiga kelompok per akun.
- **Impor F8-37** dalam satu transaksi; **ekspor tag/template F8-47** lewat `EksporTabel::xlsx`.
- **Statistik/riwayat F8-42:** hari WIB dikonversi ke rentang UTC.
- **Audit:** tulis admin tercatat di `audit_logs`; `log_chatbot` tanpa audit.
- **Contoh respons sintetis** untuk fixture FE: `backend/tests/_support/HaloSimpeg/Chatbot/*.json`.

**Test:**
- unit `MesinJawabPembandingTest`;
- fitur `WidgetEndpointTest`, `AdminEndpointTest`, `ImporQnaTest`, `StatistikRiwayatTest`, `KontrakFixtureTest`.

Ditambah `SkemaChatbotTest` dari DBV-033.

**Perbaikan review CR (W-1..W-11, 13-11-2026):**
- **Impor:** ukuran sheet diperiksa lewat `listWorksheetInfo` sebelum dimuat. Sel di baris 1.048.576 → 422, tidak lagi kehabisan
  memori. Pembacaan memakai sheet pertama saja, read filter A..D, dan `disconnectWorksheets`.
- **Batas panjang** TEXT bersama untuk form dan impor (`BatasQna`).
- **Balapan 1062** saat impor → 422 `errors.berkas`.
- **Putaran V-1..V-8:**
  - Pra-cek baris memakai `setReadEmptyCells(false)`; di PhpSpreadsheet 5.10 terbukti: border sampai baris 1.500 + 2 baris data
    → `totalRows` 3, bukan 1.500.
  - Judul kolom ekstra (E1) → 422.
  - **Perubahan batas form:** pertanyaan sebelumnya ±16.383 karakter, sekarang 65.535 byte (ikut tipe TEXT, F8-41, sah). Jawaban
    tetap 65.535 byte sesudah sanitasi.
- **R8-11 (W-4):** jalur bubble membaca satu baris; korpus tanpa kolom jawaban dan dimuat sesudah validasi; agregasi bubble per
  kategori. Ukur lokal (MySQL 8.0.30, mesin dev, korpus sintetis 303 QnA, satu balas lewat HTTP test): ±23 ms. Angka ini indikatif,
  tanpa assertion waktu.
- **Beda yang diharapkan W-5:** token angka ≥ 2^53 dalam bentuk eksponen (docstring `TfIdf::kunci`, dikunci test).
- **Test tambahan:**
  - token terikat `id_log`;
  - tag status 2 tidak menambah skor;
  - throttle pulih sesudah 61 detik;
  - nilai bawaan `Config\Chatbot`;
  - batas 6 bubble, 3 populer/saran, log tidak cocok tidak dihitung;
  - top-10 statistik;
  - literal dan nama berkas template;
  - 1062 → 422 di kategori dan impor;
  - 22 fixture FE dicocokkan dengan respons asli, termasuk impor HTTP multipart.

**Catatan DB Validator N1–N5 (DBV-033 §11):**
- N1: korpus kosong tanpa log.
- N2: cek unik `_ci` + trim; 1062 → 422 yang sama.
- N3: korpus hanya QnA dan tag status 1; ada test tag status 10 yang masih berelasi.
- N4: `judul_unik` tidak ditulis dan tidak dikirim.
- N5: tanpa purge log.

**Di luar cakupan MAKE-094:**
- `KontrakMatriksRoleTest` (berkas bersama) belum mencakup prefiks `halo-simpeg`. Role chatbot diuji di `WidgetEndpointTest` dan
  `AdminEndpointTest`.
- Penambahan prefiks itu menunggu keputusan reviewer, karena jalur helpdesk memakai prefiks yang sama.
