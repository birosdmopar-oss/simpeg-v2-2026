<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\ApiException;

/**
 * 501 — fitur Modul B yang servicenya masih stub S0-A (MAKE-002). Dipetakan ke envelope error oleh
 * ApiController::_remap()/ApiExceptionHandler, sehingga endpoint yang memanggil stub menjawab 501 terkendali, bukan 500.
 */
class BelumTersediaException extends ApiException
{
    public function __construct(string $fitur)
    {
        parent::__construct("Fitur {$fitur} belum tersedia.", 501);
    }
}
