<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\ListQuery;
use App\Libraries\Presensi\Tukin\HariKerja;
use CodeIgniter\Database\BaseConnection;

/**
 * Parameter bersama rekap D-09b (PRD 5B §6.4): `bulan` wajib `YYYY-MM` dan tidak melewati bulan berjalan (422
 * `errors.bulan`); `jenis_pegawai[]` id yang ada di master `jenis_pegawai` (bawaan `[1]` = legacy `str_jp` "1",
 * `Presensi.php:827`; 422 `errors.jenis_pegawai`); `page`/`per_page` (maks 100) lewat `ListQuery`.
 */
final readonly class ParameterRekap
{
    /**
     * @param list<int> $jenisPegawai
     */
    private function __construct(
        public int $tahun,
        public int $bulan,
        public string $awal,
        public string $akhir,
        public array $jenisPegawai,
        public int $page,
        public int $perPage,
        public mixed $unitSatker,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function dari(array $query, string $hariIni, BaseConnection $db, bool $denganJenis = true): self
    {
        $q     = ListQuery::from($query, ['bulan', 'unit_satker', 'page', 'per_page']);
        $bulan = $q->string('bulan');

        if (preg_match('/^(\d{4})-(\d{2})$/', $bulan, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw ValidationException::forField('bulan', 'Bulan wajib berformat YYYY-MM.');
        }

        if ($bulan > substr($hariIni, 0, 7)) {
            throw ValidationException::forField('bulan', 'Bulan tidak boleh melewati bulan berjalan.');
        }

        $jenis = [1];

        if ($denganJenis && array_key_exists('jenis_pegawai', $query)) {
            $mentah = $query['jenis_pegawai'];
            $daftar = is_array($mentah) ? array_values($mentah) : [$mentah];
            $jenis  = [];

            foreach ($daftar as $j) {
                if (! is_scalar($j) || preg_match('/^[1-9]\d{0,3}$/', (string) $j) !== 1) {
                    throw ValidationException::forField('jenis_pegawai', 'Jenis pegawai tidak valid.');
                }

                $jenis[] = (int) $j;
            }

            $jenis = array_values(array_unique($jenis));

            if ($jenis === [] || $db->table('jenis_pegawai')->whereIn('id_jenis_pegawai', $jenis)->countAllResults() !== count($jenis)) {
                throw ValidationException::forField('jenis_pegawai', 'Jenis pegawai tidak ditemukan.');
            }

            sort($jenis);
        }

        $awal = $bulan . '-01';

        return new self(
            (int) $m[1],
            (int) $m[2],
            $awal,
            HariKerja::kemarin(TanggalBisnis::tambahBulan($awal, 1)),
            $jenis,
            $q->page(),
            $q->perPage(25, 100),
            $query['unit_satker'] ?? null,
        );
    }

    public function kunci(): string
    {
        return sprintf('%04d-%02d', $this->tahun, $this->bulan);
    }

    public function label(): string
    {
        return TanggalBisnis::NAMA_BULAN[$this->bulan] . ' ' . $this->tahun;
    }
}
