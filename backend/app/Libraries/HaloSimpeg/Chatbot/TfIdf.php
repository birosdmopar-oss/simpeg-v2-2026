<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

/**
 * TF-IDF + cosine chatbot Vanya — port apa adanya mesin legacy dev (`dev:libraries/Nlp_engine.php:7-96`, PRD Fase 8 §7.10.2
 * langkah 4–6, F8-34). Kelas murni tanpa DB.
 *
 * Semantik yang dipertahankan:
 * - tokenisasi `strtolower(trim())` (ASCII), buang selain `a-z0-9` dan spasi (tanpa /u: byte non-ASCII terbuang), pecah per
 *   spasi, `array_filter` tanpa callback (token kosong DAN token "0" terbuang);
 * - kesetaraan token = perbandingan longgar PHP dev (`in_array`/`==`): dua token string numerik ("01", "1", "1e1") sama bila
 *   nilainya sama; token lain dibandingkan sebagai string ({@see self::kunci()});
 * - dokumen = korpus + pesan sebagai dokumen ke-N; `idf = ln(N/df)`; `tf = kemunculan / jumlah token` (0 bila tanpa token);
 * - cosine = dot / (|pesan| × |dok|), 0 bila magnitudo 0; penjumlahan mengikuti urutan kosakata dev (urutan kemunculan
 *   pertama), sehingga hasil floating point identik dengan dev.
 * Struktur data: peta hash per dokumen (A9), bukan `in_array` O(kosakata).
 */
final class TfIdf
{
    /**
     * @param list<array{id_qna: int, teks: string}> $korpus urutan korpus (id menaik, seperti GROUP BY dev)
     *
     * @return list<array{id_qna: int, skor: float}> urutan sama dengan korpus
     */
    public static function skor(string $pesan, array $korpus): array
    {
        /** @var list<array<string, int>> $tfDok kunci token => jumlah kemunculan */
        $tfDok  = [];
        $jumlah = [];

        /** @var array<string, int> $indeks kunci token => urutan kemunculan pertama di kosakata */
        $indeks = [];

        $teks   = array_map(static fn (array $q): string => $q['teks'], $korpus);
        $teks[] = $pesan;

        foreach ($teks as $i => $t) {
            $tf = [];
            $n  = 0;

            foreach (self::token($t) as $tok) {
                $k      = self::kunci($tok);
                $tf[$k] = ($tf[$k] ?? 0) + 1;
                $n++;

                if (! isset($indeks[$k])) {
                    $indeks[$k] = count($indeks);
                }
            }

            $tfDok[$i]  = $tf;
            $jumlah[$i] = $n;
        }

        $nDok = count($teks);
        $df   = [];

        foreach ($tfDok as $tf) {
            foreach ($tf as $k => $_) {
                $df[$k] = ($df[$k] ?? 0) + 1;
            }
        }

        $idf = [];

        foreach ($df as $k => $d) {
            $idf[$k] = log($nDok / $d);
        }

        $vektor = [];

        foreach ($tfDok as $i => $tf) {
            $v = [];

            foreach ($tf as $k => $c) {
                $bobot = $jumlah[$i] > 0 ? ($c / $jumlah[$i]) : 0;
                $v[$k] = $bobot * $idf[$k];
            }

            uksort($v, static fn (string $a, string $b): int => $indeks[$a] <=> $indeks[$b]);
            $vektor[$i] = $v;
        }

        $vPesan   = $vektor[$nDok - 1];
        $magPesan = 0.0;

        foreach ($vPesan as $w) {
            $magPesan += pow($w, 2);
        }

        $hasil = [];

        foreach ($korpus as $i => $q) {
            $vQ   = $vektor[$i];
            $dot  = 0.0;
            $magQ = 0.0;

            foreach ($vQ as $k => $w) {
                $magQ += pow($w, 2);
            }

            // Hanya istilah bersama yang menyumbang dot (istilah lain bernilai 0 dan menambah 0,0 secara tepat di dev).
            foreach ($vPesan as $k => $w) {
                if (isset($vQ[$k])) {
                    $dot += $w * $vQ[$k];
                }
            }

            $mag     = sqrt($magPesan) * sqrt($magQ);
            $hasil[] = ['id_qna' => $q['id_qna'], 'skor' => $mag > 0 ? $dot / $mag : 0.0];
        }

        return $hasil;
    }

    /**
     * Tokenisasi dev (`Nlp_engine::tokenize`).
     *
     * @return list<string>
     */
    public static function token(string $teks): array
    {
        $teks   = strtolower(trim($teks));
        $teks   = (string) preg_replace('/[^a-z0-9\s]/', '', $teks);
        $tokens = preg_split('/\s+/', $teks) ?: [];

        return array_values(array_filter($tokens));
    }

    /**
     * Kunci kesetaraan token setara `==` PHP dev untuk alfabet `[a-z0-9]`: string numerik (digit, atau digit dengan eksponen
     * `e`) dinormalisasi ke nilainya; selain itu string apa adanya (awalan `s:` agar tidak bertabrakan).
     */
    public static function kunci(string $token): string
    {
        if (! is_numeric($token)) {
            return 's:' . $token;
        }

        if (ctype_digit($token)) {
            $int = ltrim($token, '0');
            $int = $int === '' ? '0' : $int;

            // Bilangan bulat di luar int64 dibandingkan PHP sebagai float.
            if (strlen($int) < 19 || (strlen($int) === 19 && strcmp($int, (string) PHP_INT_MAX) <= 0)) {
                return 'n:' . $int;
            }
        }

        $f = (float) $token;

        if (is_finite($f) && floor($f) === $f && abs($f) < 9.007199254740992E15) {
            return 'n:' . (string) (int) $f;
        }

        return 'f:' . var_export($f, true);
    }
}
