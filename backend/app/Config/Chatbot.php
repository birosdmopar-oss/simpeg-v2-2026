<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Chatbot "Vanya" (Fase 8 E-10, MAKE-094; PRD Fase 8 v1.1 F8-34..F8-37, §7.10). Semua angka mesin, throttle, token, dan
 * batas impor di satu tempat. Angka mesin = konstanta legacy dev (`dev:libraries/hr/L_chatbot.php:574-577`); mengubahnya =
 * keputusan baru (F8-34).
 *
 * Kunci token feedback dibaca dari `.env` (`chatbot.kunciTokenFeedback`, minimal 32 byte, BUKAN rahasia JWT). Kosong atau
 * kurang dari 32 byte = penilaian tidak tersedia (F8-35); balas tetap jalan.
 */
class Chatbot extends BaseConfig
{
    /** Ambang cocok dari skor ASLI (cosine). */
    public float $ambangCocok = 0.25;

    /** Pengaruh maksimum feedback pada ranking (1 ± bobot). */
    public float $bobotFeedback = 0.10;

    /** Peredam sampel kecil pengali feedback. */
    public int $smoothingFeedback = 5;

    /** Bubble pertanyaan per kategori. */
    public int $maksBubblePertanyaan = 6;

    /** QnA unggulan pada "Populer" / saran fallback. */
    public int $jumlahPopuler = 3;

    /** Kandidat yang dicatat di `log_chatbot.top_3_kandidat`. */
    public int $kandidatDicatat = 3;

    /** Panjang pesan jalur ketikan (`mb_strlen` sesudah trim). */
    public int $maksPesan = 300;

    /** Throttle per akun per menit (F8-36): balas, feedback, dan kuota gabungan endpoint baca widget. */
    public int $throttleBalas = 20;

    public int $throttleFeedback = 30;

    public int $throttleBaca = 60;

    /** Kunci HMAC token feedback (`.env`: chatbot.kunciTokenFeedback). Jangan diisi di sini. */
    public string $kunciTokenFeedback = '';

    /** Masa berlaku token feedback (detik). */
    public int $masaTokenFeedback = 86400;

    /** Batas impor Excel (F8-37). */
    public int $imporMaksByte = 2 * 1024 * 1024;

    public int $imporMaksBaris = 1000;

    /** Batas per halaman daftar admin/riwayat. */
    public int $perHalamanBawaan = 20;

    public int $perHalamanMaks = 100;
}
