<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Gambar;

use App\Libraries\Dashboard\Konten\Penargetan\TabelPenerima;

/**
 * Dua jenis konten bergambar yang berbagi bentuk dan penargetan (PRD Fase 7 v2.0 §5.3/5.4): popup (periode DATETIME WIB)
 * dan banner (periode DATE). Nama tabel/kolom mengikuti draf DBV-032 ⚑.
 */
enum JenisGambar: string
{
    case Popup  = 'popup';
    case Banner = 'banner';

    public function kolomId(): string
    {
        return 'id_' . $this->value;
    }

    public function tabelPenerima(): TabelPenerima
    {
        return match ($this) {
            self::Popup  => TabelPenerima::UserPopup,
            self::Banner => TabelPenerima::UserBanner,
        };
    }

    /** Periode popup membawa jam:menit (DATETIME), banner tanggal saja (DATE). */
    public function periodeDenganJam(): bool
    {
        return $this === self::Popup;
    }

    public function label(): string
    {
        return $this === self::Popup ? 'Popup' : 'Banner';
    }
}
