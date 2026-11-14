# Models/HaloSimpeg/Chatbot — chatbot "Vanya" (DBV-033, MAKE-094)

| Model | Tabel | Audit |
|---|---|---|
| `QnaKategoriModel` | `qna_kategori` | `BaseAuditableModel` |
| `QnaTagModel` | `qna_tag` | `BaseAuditableModel` |
| `QnaModel` | `qna` | `BaseAuditableModel`; relasi `qna_has_tag` ditulis service lewat query builder + `writeAudit(..., 'qna_has_tag')` (before/after = daftar id tag) |
| `LogChatbotModel` | `log_chatbot` | **tanpa audit** (model biasa): isi pesan pengguna tidak boleh masuk `audit_logs` (BP8-15) |

- **Kolom bangkitan:** `judul_unik` (kategori/tag) adalah kolom bangkitan DB. Kolom ini tidak ada di `allowedFields`, tidak
  pernah ditulis, dan tidak dikirim ke klien (DBV N4).
- **Status:** `1` Aktif, `2` Tidak Aktif, `10` Dihapus (master `qna_status`).
- **Waktu:** UTC, diisi service.
