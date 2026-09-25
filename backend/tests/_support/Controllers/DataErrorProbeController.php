<?php

declare(strict_types=1);

namespace Tests\Support\Controllers;

use App\Controllers\Api\ApiController;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Controller KHUSUS test (CR-007) — tidak terdaftar di Config\Routes; route-nya dipasang test lewat withRoutes().
 * Menulis nilai yang pasti ditolak MySQL strict ke tabel dummy (Tests\Support CreateDummyTables), sehingga error DB
 * yang diuji adalah error nyata dari koneksi `tests` (strictOn=true), lewat ApiController::_remap() yang sama dengan
 * controller produksi.
 */
class DataErrorProbeController extends ApiController
{
    protected bool $castNumericParams = false;

    /**
     * Kasus → kode error MySQL yang diharapkan, tabel dummy, baris. riwayat_dummy: nip VARCHAR(20) NOT NULL tanpa
     * default, id_jabatan INT NULL, tmt DATE NULL, status TINYINT. pegawai_dummy: PK nip.
     *
     * @return array<string, array{code: int, table: string, row: array<string, int|string>}>
     */
    public static function cases(): array
    {
        return [
            'terlalu-panjang'   => ['code' => 1406, 'table' => 'riwayat_dummy', 'row' => ['nip' => str_repeat('1', 21)]],
            'di-luar-rentang'   => ['code' => 1264, 'table' => 'riwayat_dummy', 'row' => ['nip' => '1', 'status' => 300]],
            'bukan-angka'       => ['code' => 1366, 'table' => 'riwayat_dummy', 'row' => ['nip' => '1', 'id_jabatan' => 'abc']],
            'bukan-utf8'        => ['code' => 1366, 'table' => 'riwayat_dummy', 'row' => ['nip' => "a\xC3("]],
            'tanggal-salah'     => ['code' => 1292, 'table' => 'riwayat_dummy', 'row' => ['nip' => '1', 'tmt' => '2026-02-30']],
            'terpotong'         => ['code' => 1265, 'table' => 'riwayat_dummy', 'row' => ['nip' => '1', 'id_jabatan' => '12abc']],
            'wajib-tanpa-nilai' => ['code' => 1364, 'table' => 'riwayat_dummy', 'row' => ['id_jabatan' => 1]],
            'duplikat'          => ['code' => 1062, 'table' => 'pegawai_dummy', 'row' => ['nip' => 'NIP-GANDA']],
            'tabel-tidak-ada'   => ['code' => 1146, 'table' => 'probe_tidak_ada', 'row' => ['nip' => '1']],
        ];
    }

    /**
     * Kasus → kode error DB non-data yang sulit dipicu nyata secara deterministik di test (lock wait, deadlock, SIGNAL
     * dari trigger, DatabaseException tanpa kode seperti "Reset password gagal disimpan."); dilempar apa adanya dari
     * dalam controller seperti service produksi.
     *
     * @return array<string, int>
     */
    public static function simulatedServerErrorCases(): array
    {
        return [
            'lock-wait'  => 1205,
            'deadlock'   => 1213,
            'signal'     => 1644,
            'tanpa-kode' => 0,
        ];
    }

    public function write(string $case): ResponseInterface
    {
        $cases = self::cases();

        if (! isset($cases[$case])) {
            throw new InvalidArgumentException("Kasus probe tidak dikenal: {$case}");
        }

        db_connect()->table($cases[$case]['table'])->insert($cases[$case]['row']);

        return $this->respondSuccess(['written' => true], 201);
    }

    public function throwDbError(string $case): never
    {
        $cases = self::simulatedServerErrorCases();

        if (! isset($cases[$case])) {
            throw new InvalidArgumentException("Kasus probe tidak dikenal: {$case}");
        }

        throw new DatabaseException("Simulasi error database: {$case}", $cases[$case]);
    }

    public function echoParam(string $value): ResponseInterface
    {
        return $this->respondSuccess(['value' => $value]);
    }
}
