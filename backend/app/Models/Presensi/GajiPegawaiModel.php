<?php

declare(strict_types=1);

namespace App\Models\Presensi;

use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Models\BaseAuditableModel;

/**
 * Tabel `gaji_pegawai` (DBV-023, migration `2026-10-27-100000_CreatePresensiGaji`): satu slip per `(nip, bulan, tahun)`,
 * 28 kolom nominal GPP/TK DECIMAL(14,2) (`KolomGaji::semuaField()`, sama dengan `CreatePresensiGaji::KOLOM_NOMINAL`), snapshot uang makan,
 * status dibuka (`viewed_at`/`viewed_ip`) dan token QR (`qr_token` hash, `qr_token_enkripsi`).
 *
 * Audit (PRD 5A §8.1): tulis per baris lewat model ini (koreksi admin, buka, buka ulang — M4) terekam otomatis
 * (`BaseAuditableModel`). Impor massal D-10 menulis lewat Query Builder di transaksi `ImporSlipService` dengan audit
 * **ringkasan** satu baris `event = import` (README `Models/Presensi`, aturan 10). Kolom token dan IP dimasking di audit.
 * Stempel waktu diisi aplikasi dalam UTC (bukan `useTimestamps`).
 */
class GajiPegawaiModel extends BaseAuditableModel
{
    protected $table         = 'gaji_pegawai';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    /**
     * @var list<string>
     */
    protected $allowedFields = [];

    /**
     * Token QR (hash & terenkripsi) dan IP tidak boleh masuk audit (PRD 5A §8.1).
     *
     * @var list<string>
     */
    protected array $auditMaskedFields = ['qr_token', 'qr_token_enkripsi', 'viewed_ip'];

    public function __construct(...$args)
    {
        $this->allowedFields = [
            'nip', 'bulan', 'tahun',
            ...KolomGaji::semuaField(),
            'uang_makan', 'uang_makan_hari', 'uang_makan_periode',
            'viewed_at', 'viewed_ip', 'qr_token', 'qr_token_enkripsi',
            'created_at', 'updated_at', 'created_by', 'updated_by',
        ];

        parent::__construct(...$args);
    }
}
