<?php

declare(strict_types=1);

namespace Tests\Unit\HaloSimpeg\Chatbot;

use App\Libraries\HaloSimpeg\Chatbot\MesinJawab;
use App\Libraries\HaloSimpeg\Chatbot\TfIdf;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Chatbot;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AC-E10.3/AC-E10.4 — test pembanding mesin jawab Vanya terhadap hasil mesin legacy dev (PRD Fase 8 §7.10.7). Fixture
 * `tests/_support/HaloSimpeg/Chatbot/pembanding.json`: korpus + pesan SINTETIS, nilai harapan dihasilkan sekali dari mesin dev
 * di luar repo (F8-34b). Toleransi 0,0001. Skenario berlabel "BEDA YANG DIHARAPKAN" (tag ganda dev, §7.10.2 langkah 2) tidak
 * dibandingkan; skenario 13 (masukan ber-HTML, dev `xss_clean`) tidak dijalankan.
 *
 * @internal
 */
final class MesinJawabPembandingTest extends CIUnitTestCase
{
    private const TOLERANSI = 0.0001;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function skenario(): array
    {
        $isi = json_decode((string) file_get_contents(SUPPORTPATH . 'HaloSimpeg/Chatbot/pembanding.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];

        foreach ($isi['skenario'] as $kode => $s) {
            if ($s['bandingkan'] === true) {
                $out[$kode] = [$s];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $s
     */
    #[DataProvider('skenario')]
    public function testSamaDenganMesinDev(array $s): void
    {
        $hasil = $this->mesin()->rangking($s['pesan'], self::korpus($s['korpus']), self::rekap($s['feedback']));
        $h     = $s['harapan'];

        $this->assertNotNull($hasil);
        $this->assertSame($h['top_1_id_qna'], $hasil['teratas']['id_qna'], 'top-1');
        $this->assertSame($h['cocok'], $hasil['cocok'], 'cocok');
        $this->assertEqualsWithDelta($h['skor'], round($hasil['teratas']['skor'], 4), self::TOLERANSI, 'skor');
        $this->assertSame(array_column($h['kandidat'], 'id_qna'), array_column($hasil['kandidat'], 'id_qna'), 'urutan kandidat');

        foreach ($h['kandidat'] as $i => $k) {
            $this->assertEqualsWithDelta($k['skor'], $hasil['kandidat'][$i]['skor'], self::TOLERANSI, "kandidat {$i} skor");
            $this->assertEqualsWithDelta($k['skor_akhir'], $hasil['kandidat'][$i]['skor_akhir'], self::TOLERANSI, "kandidat {$i} skor_akhir");
        }
    }

    public function testSkorTepatAmbangDianggapCocok(): void
    {
        $s     = self::skenario()['s02_ambang_tepat'][0];
        $hasil = $this->mesin()->rangking($s['pesan'], self::korpus($s['korpus']), []);

        $this->assertNotNull($hasil);
        $this->assertSame(0.25, $hasil['teratas']['skor']);
        $this->assertTrue($hasil['cocok']);
    }

    public function testFeedbackTidakMeloloskanTetapiMembalikUrutanLolos(): void
    {
        $s = self::skenario()['s08_feedback_membalik'][0];
        $k = self::korpus($s['korpus']);

        $tanpa  = $this->mesin()->rangking($s['pesan'], $k, []);
        $dengan = $this->mesin()->rangking($s['pesan'], $k, self::rekap($s['feedback']));
        $this->assertSame([1, 2], array_column(array_slice($tanpa['kandidat'] ?? [], 0, 2), 'id_qna'));
        $this->assertSame([2, 1], array_column(array_slice($dengan['kandidat'] ?? [], 0, 2), 'id_qna'));

        $s9 = self::skenario()['s09_feedback_di_bawah_ambang'][0];
        $h9 = $this->mesin()->rangking($s9['pesan'], self::korpus($s9['korpus']), self::rekap($s9['feedback']));
        $this->assertNotNull($h9);
        $this->assertFalse($h9['cocok']);
        $this->assertGreaterThan($h9['teratas']['skor'], $h9['teratas']['skor_akhir']);
    }

    public function testKorpusKosong(): void
    {
        $this->assertNull($this->mesin()->rangking('cuti', [], []));
    }

    public function testTokenisasiDanKesetaraanAngka(): void
    {
        $this->assertSame(['cutiizin', 'cut', 'rsum'], TfIdf::token("  Cuti/Izin  cutí\tRÉSUMÉ "));
        $this->assertSame(['00', '1'], TfIdf::token('0 00 1'));
        $this->assertSame([], TfIdf::token('?!... ,,,'));
        $this->assertSame(TfIdf::kunci('1'), TfIdf::kunci('01'));
        $this->assertSame(TfIdf::kunci('7'), TfIdf::kunci('007'));
        $this->assertSame(TfIdf::kunci('10'), TfIdf::kunci('1e1'));
        $this->assertNotSame(TfIdf::kunci('1'), TfIdf::kunci('1a'));
        $this->assertNotSame(TfIdf::kunci('10'), TfIdf::kunci('s:10'));
        $this->assertSame(TfIdf::kunci('99999999999999999999'), TfIdf::kunci('99999999999999999998'), 'di luar int64 = float seperti PHP');
    }

    public function testPengaliFeedback(): void
    {
        $m = $this->mesin();
        $this->assertSame(1.0, $m->pengali(0, 0));
        $this->assertEqualsWithDelta(1 + 0.10 * 10 / 15, $m->pengali(10, 0), 1e-12);
        $this->assertEqualsWithDelta(1 - 0.10 * 10 / 15, $m->pengali(0, 10), 1e-12);
    }

    private function mesin(): MesinJawab
    {
        return new MesinJawab(new Chatbot());
    }

    /**
     * Teks dokumen = pertanyaan + spasi + nama tag digabung spasi (`dev:libraries/hr/L_chatbot.php:679-685`).
     *
     * @param list<array{id_qna: int, pertanyaan: string, tag: list<string>}> $korpus
     *
     * @return list<array{id_qna: int, teks: string}>
     */
    private static function korpus(array $korpus): array
    {
        return array_map(static fn (array $q): array => ['id_qna' => $q['id_qna'], 'teks' => $q['pertanyaan'] . ' ' . implode(' ', $q['tag'])], $korpus);
    }

    /**
     * @param list<array{id_qna: int, up: int, down: int}> $fb
     *
     * @return array<int, array{up: int, down: int}>
     */
    private static function rekap(array $fb): array
    {
        $r = [];

        foreach ($fb as $f) {
            $r[$f['id_qna']] = ['up' => $f['up'], 'down' => $f['down']];
        }

        return $r;
    }
}
