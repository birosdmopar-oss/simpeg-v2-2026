<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use Config\Chatbot;

/**
 * Ranking jawaban chatbot Vanya — port apa adanya `dev:libraries/hr/L_chatbot.php:707-738` + pengali feedback `:647-659`
 * (PRD Fase 8 §7.10.2 langkah 7, F8-34). Kelas murni tanpa DB: korpus dan rekap feedback disuplai pemanggil.
 *
 * - `skor_akhir = skor × pengali`; pengali = 1 tanpa feedback, selain itu `1 + bobot × (up − down) / (up + down + smoothing)`.
 * - Kandidat lolos = skor ASLI ≥ ambang, diurutkan `skor_akhir` menurun; tidak lolos diurutkan skor asli menurun; urutan akhir =
 *   lolos lalu tidak lolos. Seri → urutan sebelumnya (skor asli menurun, lalu `id_qna` menaik), sama dengan `usort` stabil dev
 *   atas korpus urut id.
 * - Cocok bila ada kandidat lolos. Kandidat yang dicatat dibulatkan 4 desimal.
 */
final class MesinJawab
{
    public function __construct(private readonly Chatbot $config)
    {
    }

    /**
     * @param list<array{id_qna: int, teks: string}>      $korpus urut id menaik
     * @param array<int, array{up: int, down: int}>        $rekap  feedback log cocok per QnA teratas
     *
     * @return array{cocok: bool, teratas: array{id_qna: int, skor: float, skor_akhir: float}, kandidat: list<array{id_qna: int, skor: float, skor_akhir: float}>}|null
     *               null bila korpus kosong
     */
    public function rangking(string $pesan, array $korpus, array $rekap): ?array
    {
        if ($korpus === []) {
            return null;
        }

        $hasil = TfIdf::skor($pesan, $korpus);

        foreach ($hasil as $i => $h) {
            $fb                      = $rekap[$h['id_qna']] ?? ['up' => 0, 'down' => 0];
            $hasil[$i]['skor_akhir'] = $h['skor'] * $this->pengali($fb['up'], $fb['down']);
            $hasil[$i]['_urut']      = $i;
        }

        // Nlp_engine dev: usort skor menurun (stabil di PHP 8, korpus urut id).
        usort($hasil, static fn (array $a, array $b): int => [$b['skor'], $a['_urut']] <=> [$a['skor'], $b['_urut']]);

        $lolos = [];
        $tidak = [];

        foreach ($hasil as $i => $h) {
            $h['_urut'] = $i;

            if ($h['skor'] >= $this->config->ambangCocok) {
                $lolos[] = $h;
            } else {
                $tidak[] = $h;
            }
        }

        usort($lolos, static fn (array $a, array $b): int => [$b['skor_akhir'], $a['_urut']] <=> [$a['skor_akhir'], $b['_urut']]);

        $urutan   = array_merge($lolos, $tidak);
        $kandidat = [];

        foreach (array_slice($urutan, 0, $this->config->kandidatDicatat) as $h) {
            $kandidat[] = ['id_qna' => $h['id_qna'], 'skor' => round($h['skor'], 4), 'skor_akhir' => round($h['skor_akhir'], 4)];
        }

        $t = $urutan[0];

        return [
            'cocok'    => $lolos !== [],
            'teratas'  => ['id_qna' => $t['id_qna'], 'skor' => $t['skor'], 'skor_akhir' => $t['skor_akhir']],
            'kandidat' => $kandidat,
        ];
    }

    public function pengali(int $up, int $down): float
    {
        $total = $up + $down;

        if ($total === 0) {
            return 1.0;
        }

        return 1 + ($this->config->bobotFeedback * (($up - $down) / ($total + $this->config->smoothingFeedback)));
    }
}
