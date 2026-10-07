# Architecture Decision Records (ADR) SIMPEG v2

Dokumen ADR utama ada di luar repo: `SIMPEG_v2_ADR.docx` (folder Bahan Baku SIMPEG), berisi ADR-001 s.d. ADR-030. Kode dan dokumen di repo merujuk ADR dengan nomornya (mis. "ADR-006").

Folder ini menyimpan ADR yang ditulis setelah dokumen utama dan perlu dibaca coder langsung dari repo. Penomoran melanjutkan dokumen utama, dan satu nomor hanya dipakai untuk satu keputusan.

## Indeks

| No | Judul | Status | Lokasi |
|---|---|---|---|
| ADR-001 … ADR-030 | (lihat dokumen utama) | — | `SIMPEG_v2_ADR.docx` (di luar repo) |
| ADR-031 | Cutover S1 & read-only legacy | — | Di luar repo (bersama dokumen utama) |
| ADR-032 | Library PDF/Excel/QR & pola rute publik (Slip Gaji Fase 5) | Draf, belum diputus | Di luar repo (bersama dokumen utama) |
| [ADR-033](ADR-033-library-pdf-tcpdf.md) | Library PDF: TCPDF untuk Fase 3 (LKH, Cetak DRH) | Diterima 07-10-2026 | Repo |

## Format

Satu berkas per ADR: `ADR-0NN-judul-singkat.md`, dengan bagian Status, Konteks, Keputusan, Alternatif, dan Konsekuensi. Tanpa IP/host internal, kredensial, atau detail celah keamanan (repo publik).
