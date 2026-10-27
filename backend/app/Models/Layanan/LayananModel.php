<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use App\Models\BaseAuditableModel;
use App\Models\Kepegawaian\PelakuAuditTrait;

/**
 * Dasar model Modul C (README `Models/Layanan`): audit otomatis BaseAuditableModel + pelaku audit eksplisit
 * (`withActor`, PelakuAuditTrait) agar service yang dipanggil langsung (test, worker) mencatat pelaku AuthContext
 * pemanggil; tulis yang gagal dilempar DatabaseException sehingga transaksi pemanggil di-rollback.
 */
abstract class LayananModel extends BaseAuditableModel
{
    use PelakuAuditTrait;
}
