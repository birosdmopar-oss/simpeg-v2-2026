<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Penargetan;

/**
 * Tabel snapshot penerima yang disinkronkan {@see SinkronPenerima} (DBV-032 §3.2/3.4, `UNIQUE (induk, nip)`).
 * Nama kolom induk ⚑ mengikuti draf DBV-032; `UserFormDigital` dipakai Jalur B (ReadOne).
 */
enum TabelPenerima: string
{
    case UserPopup       = 'user_popup';
    case UserBanner      = 'user_banner';
    case UserFormDigital = 'user_form_digital';

    public function kolomInduk(): string
    {
        return match ($this) {
            self::UserPopup       => 'id_popup',
            self::UserBanner      => 'id_banner',
            self::UserFormDigital => 'id_form_digital',
        };
    }
}
