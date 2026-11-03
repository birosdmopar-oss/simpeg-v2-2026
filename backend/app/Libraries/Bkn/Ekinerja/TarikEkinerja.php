<?php

declare(strict_types=1);

namespace App\Libraries\Bkn\Ekinerja;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Orkestrasi tarik e-Kinerja (H-10, F6-15) untuk command `bkn:tarik-*` dan job `bkn-tarik`: panggil API (di luar
 * transaksi, BP-4) → {@see PemetaEkinerja} → {@see SinkronEkinerja} (kecuali `dryRun`) → satu baris ringkasan di
 * `integrasi_log` (jumlah saja; kode alasan penolakan tanpa NIP/nama).
 *
 * Status hasil: `ok`, `gagal` (HTTP/respons tidak sah), `periode_tidak_ada` (periode bulan itu belum ada di
 * `bkn_periode_ekinper`; jalankan `bkn:tarik-periode` dulu), `dilewati_manual` (id berawalan `SKPManual`).
 */
final class TarikEkinerja
{
    public const STATUS_OK = 'ok';

    public const STATUS_GAGAL = 'gagal';

    public const STATUS_TANPA_PERIODE = 'periode_tidak_ada';

    public const STATUS_MANUAL = 'dilewati_manual';

    public function __construct(
        private readonly EkinerjaKlien $klien,
        private readonly SinkronEkinerja $sinkron,
        private readonly BaseConnection $db,
        private readonly IntegrasiLogInterface $log,
    ) {
    }

    /**
     * Rakitan produksi: token BKN + klien HTTP bersama + log integrasi (konfigurasi e-Kinerja diperiksa saat dibuat, AC-K.4).
     */
    public static function dariLayanan(): self
    {
        $db = db_connect();

        return new self(
            new EkinerjaKlien(service('tokenBkn'), (string) config('Integrations')->bkn['baseUrlEkinerja']),
            new SinkronEkinerja($db),
            $db,
            service('integrasiLog'),
        );
    }

    /**
     * @return array{status: string, http: int|null, baru: int, sudah_ada: int, ditolak: int, alasan: array<string, int>}
     */
    public function periode(bool $dryRun = false): array
    {
        $respons = $this->klien->periode();
        $peta    = PemetaEkinerja::periode($respons->json());
        $alasan  = $this->hitungAlasan($peta['ditolak']);

        if (! $respons->sukses() || ! $peta['sah']) {
            return $this->ringkas('tarik_periode', self::STATUS_GAGAL, $respons->status, ['baru' => 0, 'sudah_ada' => 0], $alasan, null, $dryRun) + ['baru' => 0, 'sudah_ada' => 0];
        }

        $hasil = $dryRun ? $this->perkiraanPeriode($peta['baris']) : $this->sinkron->simpanPeriode($peta['baris']);

        return $this->ringkas('tarik_periode', self::STATUS_OK, $respons->status, $hasil, $alasan, null, $dryRun) + $hasil;
    }

    /**
     * @return array{status: string, http: int|null, periode_id: string|null, tahun: int, bulan: string, baru: int, diperbarui: int, tetap: int, tidak_terpasang: int, tidak_dikenal: int, ditolak: int, alasan: array<string, int>}
     */
    public function skpPeriodik(?int $tahun = null, ?int $bulan = null, ?DateTimeImmutable $sekarang = null, bool $dryRun = false): array
    {
        $pilih = $tahun !== null && $bulan !== null
            ? ['tahun' => $tahun, 'bulan' => sprintf('%02d', $bulan)]
            : PemilihPeriode::untuk($sekarang ?? new DateTimeImmutable('now'));

        $periode = $this->db->table('bkn_periode_ekinper')->select('id')
            ->where('tahun', $pilih['tahun'])->where('bulan', $pilih['bulan'])->where('jenis_periode', null)
            ->orderBy('id', 'ASC')->get(1)->getRowArray();
        $id    = $periode === null ? null : (string) $periode['id'];
        $nol   = ['baru' => 0, 'diperbarui' => 0, 'tetap' => 0, 'tidak_terpasang' => 0, 'tidak_dikenal' => 0];
        $dasar = ['periode_id' => $id, 'tahun' => $pilih['tahun'], 'bulan' => $pilih['bulan']];

        if ($id === null) {
            return $dasar + $this->ringkas('tarik_skp_periodik', self::STATUS_TANPA_PERIODE, null, $nol, [], null, $dryRun) + $nol;
        }

        if (PemilihPeriode::manual($id)) {
            return $dasar + $this->ringkas('tarik_skp_periodik', self::STATUS_MANUAL, null, $nol, [], $id, $dryRun) + $nol;
        }

        $respons = $this->klien->penilaian($pilih['tahun'], $id);
        $peta    = PemetaEkinerja::skpPeriodik($respons->json());
        $alasan  = $this->hitungAlasan($peta['ditolak']);

        if (! $respons->sukses() || ! $peta['sah']) {
            return $dasar + $this->ringkas('tarik_skp_periodik', self::STATUS_GAGAL, $respons->status, $nol, $alasan, $id, $dryRun) + $nol;
        }

        $hasil = $dryRun ? ['baru' => count($peta['baris'])] + $nol : $this->sinkron->simpanSkpPeriodik($peta['baris']);

        $hasil += $nol;

        return $dasar + $this->ringkas('tarik_skp_periodik', self::STATUS_OK, $respons->status, $hasil, $alasan, $id, $dryRun) + $hasil;
    }

    /**
     * @param list<array<string, int|string|null>> $baris
     *
     * @return array{baru: int, sudah_ada: int}
     */
    private function perkiraanPeriode(array $baris): array
    {
        $ada = $baris === [] ? 0 : $this->db->table('bkn_periode_ekinper')->whereIn('id', array_column($baris, 'id'))->countAllResults();

        return ['baru' => count($baris) - $ada, 'sudah_ada' => $ada];
    }

    /**
     * @param list<array{urutan: int, alasan: string}> $ditolak
     *
     * @return array<string, int>
     */
    private function hitungAlasan(array $ditolak): array
    {
        $hitung = [];

        foreach ($ditolak as $d) {
            $hitung[$d['alasan']] = ($hitung[$d['alasan']] ?? 0) + 1;
        }

        ksort($hitung);

        return $hitung;
    }

    /**
     * @param array<string, int> $angka
     * @param array<string, int> $alasan
     *
     * @return array{status: string, http: int|null, ditolak: int, alasan: array<string, int>}
     */
    private function ringkas(string $operasi, string $status, ?int $http, array $angka, array $alasan, ?string $refId, bool $dryRun): array
    {
        $ditolak = array_sum($alasan);
        $bagian  = [];

        foreach ($angka as $k => $v) {
            $bagian[] = "{$k}={$v}";
        }

        foreach ($alasan as $k => $v) {
            $bagian[] = "ditolak:{$k}={$v}";
        }

        $this->log->catat(new CatatanIntegrasi(
            arah: CatatanIntegrasi::ARAH_KELUAR,
            layanan: EkinerjaKlien::LAYANAN,
            operasi: $operasi . ($dryRun ? '_dry_run' : ''),
            status: in_array($status, [self::STATUS_OK, self::STATUS_MANUAL], true) ? CatatanIntegrasi::SUKSES : CatatanIntegrasi::GAGAL,
            httpStatus: $http,
            kode: $status,
            pesan: implode(', ', $bagian),
            refId: $refId,
        ));

        return ['status' => $status, 'http' => $http, 'ditolak' => $ditolak, 'alasan' => $alasan];
    }
}
