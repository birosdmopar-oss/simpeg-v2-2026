# Libraries/HaloSimpeg/Chatbot — chatbot "Vanya" (Fase 8 E-10, MAKE-094)

Logika chatbot. Acuan: PRD Fase 8 v1.1 §7.10 dan F8-34..F8-51. Kontrak API ada di
`Controllers/Api/HaloSimpeg/Chatbot/README.md`. Modul ini terpisah dari helpdesk Halo Simpeg: tidak memakai tabel, service,
atau model helpdesk.

| Kelas | Isi |
|---|---|
| `TfIdf` | Mesin murni TF-IDF + cosine, port apa adanya `dev:libraries/Nlp_engine.php`. Tokenisasi ASCII; token `"0"` terbuang; kesetaraan token numerik longgar ("01" = "1", "1e1" = "10"). Memakai peta hash (A9) dengan urutan penjumlahan kosakata dev, sehingga floating point identik |
| (beda yang diharapkan) | Token angka ≥ 2^53 dalam bentuk eksponen vs digit-saja: `==` dev tidak transitif sehingga tidak bisa dihash sempurna; v2 menganggapnya berbeda (docstring `TfIdf::kunci`, dikunci test) |
| `MesinJawab` | Ranking murni, port `dev:LC:707-738`: ambang dari skor asli; pengali feedback hanya untuk kandidat lolos; seri → `id_qna` menaik; 3 kandidat dibulatkan 4 desimal |
| `ChatbotService` | Endpoint widget: bubble, populer, balas, feedback |
| `TokenFeedback` | Token HMAC-SHA256 kepemilikan penilaian (F8-35) |
| `MasterChatbotService` | CRUD kategori/tag, judul unik status 1/2 (F8-40, catatan DBV N2), hapus = status 10 |
| `QnaService` | CRUD QnA + set tag atomik, sanitasi jawaban, status induk F8-49, audit relasi manual (`entity = qna_has_tag`) |
| `ImporQna` | Impor `.xlsx` template (F8-37) dalam satu transaksi + template (F8-47). Berkas TIDAK dimuat dengan `Reader\Xlsx::load()`/`listWorksheetInfo()` PhpSpreadsheet (CR-182 R5): `load()` membangun seluruh workbook (semua sheet bernama sama, semua sel, definisi kolom, terjemahan rumus shared per sel), dan setiap pagar yang meniru perilakunya dari luar (R1-R4) membuka celah baru. Urutan: ukuran isi dari direktori ZIP ≤ 32 MB → `canRead` vendor → `PembacaQnaXlsx` (PemindaiXlsx impor slip tidak dipakai lagi sejak R5) |
| `PembacaQnaXlsx` | Pembaca mandiri sheet pertama, sel A1:D(maks+1) dan E1, lewat aliran XMLReader (biaya linear terhadap byte, memori ≤ sel yang dipakai + shared string yang dirujuk). Nilai identik dengan main (read filter + `rangeToArray(A1:D, null, false, false, false)`): resolusi path, XmlScanner, dan konversi sel memakai kode vendor lewat subkelas `Reader\Xlsx` (`getFromZipArchive`, `castToFormula` termasuk anak rumus shared, `castTo*`), lalu ditulis ke Worksheet PhpSpreadsheet dengan `setValueExplicit`/`setValue` yang sama. Pesan jumlah baris: begitu ada sel berisi di baris > 1.001, pembacaan berhenti dan keluar "Jumlah baris data maksimal 1000." Perubahan perilaku yang disengaja (perbaikan, diterima reviewer): berkas dengan urutan `r` acak atau baris lebih yang dulu lolos `totalRows` `listWorksheetInfo` (baris di atas 1.001 dibuang diam-diam) kini mendapat pesan baris. Definisi kolom `<col>` tidak dibaca sama sekali, sehingga `<col>` tidak sah (yang di main membuat vendor gagal/fatal) tidak lagi berpengaruh; isi datanya sama dengan yang dibaca main dari berkas yang sama tanpa `<cols>`. Batas: isi direktori ZIP ≤ 32 MB, byte terbaca aliran ≤ 32 MB, elemen part sheet ≤ 400 ribu (±1,4 s), elemen shared strings ≤ 1 juta; melewati → "rusak". Beda dengan main hanya pada berkas yang di main fatal/lambat atau tidak sah; trade-off yang tersisa: arsip ZIP berisi > 32 MB (termasuk bukan-xlsx) dan sheet dengan > 400 ribu elemen → "Berkas Excel rusak atau tidak dapat dibaca." |
| `StatistikChatbot` | Statistik/riwayat (F8-42) + ekspor tag xlsx (F8-47) |

**`ChatbotService` (widget):**
- **Korpus** = QnA status 1 + nama tag status 1 (F8-39, DBV N3). Korpus dimuat tanpa kolom jawaban, hanya untuk jalur ketikan
  sesudah validasi pesan; jawaban diambil untuk kandidat teratas saja. Jalur bubble membaca satu baris QnA (R8-11).
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
