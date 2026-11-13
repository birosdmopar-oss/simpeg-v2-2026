# Libraries/HaloSimpeg/Chatbot — chatbot "Vanya" (Fase 8 E-10, MAKE-094)

Logika chatbot. Acuan: PRD Fase 8 v1.1 §7.10 dan F8-34..F8-51. Kontrak API ada di
`Controllers/Api/HaloSimpeg/Chatbot/README.md`. Modul ini terpisah dari helpdesk Halo Simpeg: tidak memakai tabel, service,
atau model helpdesk.

| Kelas | Isi |
|---|---|
| `TfIdf` | Mesin murni TF-IDF + cosine, port apa adanya `dev:libraries/Nlp_engine.php`. Tokenisasi ASCII; token `"0"` terbuang; kesetaraan token numerik longgar ("01" = "1", "1e1" = "10"). Memakai peta hash (A9) dengan urutan penjumlahan kosakata dev, sehingga floating point identik |
| `MesinJawab` | Ranking murni, port `dev:LC:707-738`: ambang dari skor asli; pengali feedback hanya untuk kandidat lolos; seri → `id_qna` menaik; 3 kandidat dibulatkan 4 desimal |
| `ChatbotService` | Endpoint widget: bubble, populer, balas, feedback |
| `TokenFeedback` | Token HMAC-SHA256 kepemilikan penilaian (F8-35) |
| `MasterChatbotService` | CRUD kategori/tag, judul unik status 1/2 (F8-40, catatan DBV N2), hapus = status 10 |
| `QnaService` | CRUD QnA + set tag atomik, sanitasi jawaban, status induk F8-49, audit relasi manual (`entity = qna_has_tag`) |
| `ImporQna` | Impor `.xlsx` template (F8-37) dalam satu transaksi + template (F8-47) |
| `StatistikChatbot` | Statistik/riwayat (F8-42) + ekspor tag xlsx (F8-47) |

**`ChatbotService` (widget):**
- **Korpus** = QnA status 1 + nama tag status 1 (F8-39, DBV N3).
- **Log:** satu baris `log_chatbot` anonim per balas, lewat `LogChatbotModel` tanpa audit (BP8-15). Isi pesan tidak pernah ditulis ke
  log aplikasi.
- **Korpus kosong:** 200 tanpa log (F8-44, DBV N1). Tidak ada log ber-`top_1_id_qna = 0`.
- **Throttle** per akun lewat `Services::throttler()` dengan tiga kunci terpisah: `chatbot_balas_*`, `chatbot_feedback_*`,
  `chatbot_baca_*` (F8-36).

**`TokenFeedback`:**
- Konteks tanda tangan `vanya-fb|v1|`; kedaluwarsa dalam detik Unix; verifikasi `hash_equals`.
- Kunci `chatbot.kunciTokenFeedback` (`.env`, minimal 32 byte); bila kurang, penilaian dimatikan. Lihat README-deploy §1m.

**Angka konfigurasi:** semuanya di `Config\Chatbot`.

**Test:**
- `tests/unit/HaloSimpeg/Chatbot/MesinJawabPembandingTest.php` membandingkan mesin dengan fixture
  `tests/_support/HaloSimpeg/Chatbot/pembanding.json`. Nilai harapan fixture dihasilkan sekali dari mesin dev di luar repo
  (F8-34b); kode dev tidak masuk repo.
- Test fitur ada di `tests/database/HaloSimpeg/Chatbot/`.
- `KontrakFixtureTest` memastikan bentuk respons sama dengan contoh JSON yang disalin FE.
