<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 501 — fitur yang servicenya masih stub (Modul B: S0-A MAKE-002, App\Libraries\Kepegawaian\Stub). Dipetakan ke
 * envelope error oleh ApiController::_remap()/ApiExceptionHandler, sehingga endpoint yang memanggil stub menjawab 501
 * terkendali, bukan 500.
 */
class BelumTersediaException extends ApiException
{
    public function __construct(string $fitur)
    {
        parent::__construct("Fitur {$fitur} belum tersedia.", 501);
    }
}
