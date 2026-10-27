<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Interfaces\Layanan\PenerbitSuratCutiInterface;

/**
 * Pengikat bawaan M3: tidak menerbitkan apa pun. M4 mengganti binding `penerbitSuratCuti` di `Config\Services`.
 */
final class PenerbitSuratCutiNoOp implements PenerbitSuratCutiInterface
{
    public function terbitkanBelumTte(array $cuti): void
    {
    }
}
