<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Bkn\Ekinerja\ParserTanggalBkn;
use App\Libraries\Bkn\Ekinerja\PemetaEkinerja;
use App\Libraries\Bkn\Ekinerja\PemilihPeriode;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-064 (H-10) — pemeta e-Kinerja tanpa panggilan API: parser tanggal ketat dua format (BP-10), pemilihan periode
 * tanggal > 19 → bulan berikutnya + lewati `SKPManual*` [K `EB:209-234`], pemetaan periode & SKP periodik [K `EB:27-44`,
 * `EB:260-298`] dari fixture sintetis.
 *
 * @internal
 */
final class PemetaEkinerjaTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{string, bool, string|null}>
     */
    public static function tanggal(): array
    {
        return [
            'iso'              => ['2026-10-15', false, '2026-10-15'],
            'iso berjam'       => ['2026-10-15 08:30:05', true, '2026-10-15 08:30:05'],
            'iso tanpa jam'    => ['2026-10-15', true, '2026-10-15 00:00:00'],
            'dmy'              => ['15-10-2026', false, '2026-10-15'],
            'dmy berjam'       => ['15-10-2026 23:59:59', true, '2026-10-15 23:59:59'],
            'jam dibuang'      => ['2026-10-15 08:30:05', false, '2026-10-15'],
            'tanggal mustahil' => ['2026-02-30', false, null],
            'garis miring'     => ['15/10/2026', false, null],
            'jam tidak sah'    => ['2026-10-15 24:00:00', true, null],
            'tanpa detik'      => ['2026-10-15 08:30', true, null],
            'kosong'           => ['', false, null],
            'teks'             => ['kemarin', false, null],
        ];
    }

    #[DataProvider('tanggal')]
    public function testParserTanggalKetat(string $masukan, bool $jam, ?string $harap): void
    {
        $this->assertSame($harap, ParserTanggalBkn::urai($masukan, $jam));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function periode(): array
    {
        return [
            'tanggal 19'         => ['2026-10-19 23:00:00', 2026, '10'],
            'tanggal 20'         => ['2026-10-20 00:10:00', 2026, '11'],
            'akhir tahun'        => ['2026-12-25 10:00:00', 2027, '01'],
            'awal bulan'         => ['2026-01-01 00:00:00', 2026, '01'],
            'UTC 19 tapi WIB 20' => ['2026-10-19 18:00:00 UTC', 2026, '11'],
        ];
    }

    #[DataProvider('periode')]
    public function testPemilihanPeriodeTanggalLebihDariSembilanBelas(string $waktu, int $tahun, string $bulan): void
    {
        $zona = str_ends_with($waktu, 'UTC') ? new DateTimeZone('UTC') : new DateTimeZone('Asia/Jakarta');
        $this->assertSame(['tahun' => $tahun, 'bulan' => $bulan], PemilihPeriode::untuk(new DateTimeImmutable(str_replace(' UTC', '', $waktu), $zona)));
    }

    public function testPeriodeManualBerawalanSkpManual(): void
    {
        $this->assertTrue(PemilihPeriode::manual('SKPManualFeb2025'));
        $this->assertTrue(PemilihPeriode::manual('SKPManualUji2026'));
        $this->assertFalse(PemilihPeriode::manual('PER-UJI-2026-10'));
        $this->assertFalse(PemilihPeriode::manual('skpmanualfeb2025'), 'peka huruf seperti literal legacy');
    }

    public function testPemetaanPeriodeDariFixture(): void
    {
        $h = PemetaEkinerja::periode($this->fixture('periode_ok.json'));

        $this->assertTrue($h['sah']);
        $this->assertSame(['PER-UJI-2026-10', 'PER-UJI-2026-11', 'PER-UJI-TAHUNAN-2026', 'SKPManualUji2026'], array_column($h['baris'], 'id'));
        $this->assertSame([
            'id'            => 'PER-UJI-2026-10', 'nama' => 'Periode Uji Oktober 2026', 'tahun' => 2026, 'bulan' => '10', 'periode_awal' => '09-16',
            'periode_akhir' => '10-15', 'batas_pengisian' => '10-20', 'jenis_periode' => null, 'status' => 1,
        ], $h['baris'][0]);
        $this->assertNull($h['baris'][1]['jenis_periode'], 'null JSON → NULL');
        $this->assertNull($h['baris'][1]['batas_pengisian'], 'string kosong → NULL');
        $this->assertSame('TAHUNAN', $h['baris'][2]['jenis_periode']);
        $this->assertSame([['urutan' => 5, 'alasan' => 'periode_akhir_tidak_sah'], ['urutan' => 6, 'alasan' => 'id_tidak_sah']], $h['ditolak']);
    }

    public function testResponsTidakSuksesTidakSah(): void
    {
        foreach ([null, ['success' => '0', 'data' => []], ['success' => '1'], ['success' => '1', 'data' => 'x']] as $r) {
            $this->assertSame(['sah' => false, 'baris' => [], 'ditolak' => []], PemetaEkinerja::periode($r));
            $this->assertSame(['sah' => false, 'baris' => [], 'ditolak' => []], PemetaEkinerja::skpPeriodik($r));
        }
    }

    public function testPemetaanSkpPeriodikDariFixture(): void
    {
        $h = PemetaEkinerja::skpPeriodik($this->fixture('skp_periodik_ok.json'));

        $this->assertTrue($h['sah']);
        $this->assertSame(['PNS-UJI-001', 'PNS-UJI-002'], array_column($h['baris'], 'id_pns'));
        $satu = $h['baris'][0];
        $this->assertSame(['2026-01-01', '2026-12-31', '2026-10-20 08:30:00', 'BAIK', '199001012020011001', '1'], [
            $satu['periode_awal_skp'], $satu['periode_akhir_skp'], $satu['waktu_dinilai'], $satu['hasil_akhir'], $satu['nip'], $satu['status'],
        ]);
        $this->assertCount(1 + count(PemetaEkinerja::KOLOM_SKP) + 4, $satu, 'id_pns + 25 kolom + 3 tanggal + status');
        $dua = $h['baris'][1];
        $this->assertSame(['2026-01-01', '2026-12-31', null, null, null], [$dua['periode_awal_skp'], $dua['periode_akhir_skp'], $dua['hasil_akhir'], $dua['waktu_dinilai'], $dua['skp_penilaian_id']]);
        $this->assertSame([
            ['urutan' => 3, 'alasan' => 'periode_awal_skp_tidak_sah'],
            ['urutan' => 4, 'alasan' => 'skp_id_kosong'],
            ['urutan' => 5, 'alasan' => 'waktu_dinilai_tidak_sah'],
        ], $h['ditolak']);
        $this->assertSame('PNS-UJI-001**PER-UJI-2026-10**SKP-UJI-001', PemetaEkinerja::kunci($satu));
    }

    /**
     * @return array<mixed>
     */
    private function fixture(string $nama): array
    {
        $data = json_decode((string) file_get_contents(SUPPORTPATH . 'Integrasi/Bkn/' . $nama), true);
        $this->assertIsArray($data);

        return $data;
    }
}
