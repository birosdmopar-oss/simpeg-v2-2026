# Modul C — Layanan & Approval (Fase 4)

Model modul ini (`App\Models\Layanan\*`). Model bisnis WAJIB extends `App\Models\BaseAuditableModel` (audit otomatis, ADR-011/012); model `riwayat_*` extends `App\Models\BaseSnapshotModel` (ADR-006).

## Stempel UTC (CR-086)

Kolom stempel (`created_at`/`updated_at`) wajib ada di `$allowedFields` supaya `BaseAuditableModel` mengisinya dengan UTC (bukan default DB yang mengikuti zona sesi). Lihat [`app/Libraries/Waktu/README.md`](../../Libraries/Waktu/README.md).
