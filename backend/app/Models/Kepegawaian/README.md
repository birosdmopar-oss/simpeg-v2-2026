# Modul B — Kepegawaian Core (Fase 3)

Model modul ini (`App\Models\Kepegawaian\*`). Model bisnis WAJIB extends `App\Models\BaseAuditableModel` (audit otomatis, ADR-011/012); model `riwayat_*` extends `App\Models\BaseSnapshotModel` (ADR-006).

`RiwayatModel` (WS-1 M1 MAKE-004) = model generik semua tabel riwayat jenis engine, dikonfigurasi dari `RiwayatDefinisi` dan extends `BaseAuditableModel`. Snapshot jenis engine disinkron `SnapshotSync` (pilih ulang multi-target dari aturan Definisi, tetap eksplisit di approval final sesuai ADR-006), bukan `BaseSnapshotModel::syncToActiveSnapshot()` yang satu target dan menyalin satu baris.
