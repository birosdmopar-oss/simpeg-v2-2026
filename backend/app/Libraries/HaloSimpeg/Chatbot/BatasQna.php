<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

/**
 * Batas panjang isi QnA yang sama untuk form admin ({@see QnaService}) dan impor Excel ({@see ImporQna}) (F8-41). `pertanyaan`
 * dan `jawaban` = TEXT (maks 65.535 byte, DBV-033); melewatinya di DB = galat 1406 tanpa laporan, jadi diperiksa aplikasi.
 */
final class BatasQna
{
    public const TEXT_MAKS_BYTE = 65535;

    public const PESAN_PERTANYAAN = 'Pertanyaan terlalu panjang.';

    public const PESAN_JAWABAN = 'Jawaban terlalu panjang.';

    /** Pesan galat bila pertanyaan melewati batas; null bila muat. */
    public static function pertanyaan(string $pertanyaan): ?string
    {
        return strlen($pertanyaan) > self::TEXT_MAKS_BYTE ? self::PESAN_PERTANYAAN : null;
    }

    /** Pesan galat bila jawaban (sesudah sanitasi) melewati batas; null bila muat. */
    public static function jawaban(string $jawaban): ?string
    {
        return strlen($jawaban) > self::TEXT_MAKS_BYTE ? self::PESAN_JAWABAN : null;
    }
}
