<?php

declare(strict_types=1);

namespace Tests\Support\Controllers;

use App\Controllers\Api\ApiController;
use App\Libraries\Presensi\Kehadiran\PresensiOnlineService;
use Closure;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Controller KHUSUS test (CR-185) — tidak terdaftar di Config\Routes; route-nya dipasang test lewat withRoutes() dengan
 * filter `jwt`, sehingga jaring 1452 sesi NIP basi diuji lewat JwtAuthFilter + ApiController::_remap() yang sama dengan
 * controller produksi.
 */
class SesiBasiProbeController extends ApiController
{
    /** Seam `kaitSebelumTulis` PresensiOnlineService untuk {@see presensiMasuk()} (balapan deterministik). */
    public static ?Closure $kaitPresensi = null;

    /** Jam WIB tetap untuk {@see presensiMasuk()}. */
    public static string $jamPresensi = '2026-11-10 10:00:00';

    protected bool $castNumericParams = false;

    /**
     * Simulasi balapan sesudah filter lolos: `ganti` = akun `sub` berhenti memegang NIP klaim (koreksi NIP / hapus pegawai
     * yang commit di sela filter dan tulis) lalu 1452; `tetap` = 1452 tanpa perubahan akun; `data` = 1406 (error data).
     */
    public function fk(string $mode): never
    {
        $id = service('authContext')->idPengguna();

        if ($mode === 'ganti' && $id !== null) {
            db_connect()->table('pengguna')->where('id_pengguna', $id)->update(['nip' => null]);
        }

        if ($mode === 'data') {
            throw new DatabaseException('Simulasi error data.', 1406);
        }

        throw new DatabaseException('Simulasi FK tanpa induk.', 1452);
    }

    /**
     * Presensi masuk lewat service produksi dengan seam (controller produksi membuat service dengan `new` tanpa seam).
     */
    public function presensiMasuk(): ResponseInterface
    {
        $jam = self::$jamPresensi;
        $svc = new PresensiOnlineService(null, null, static fn (): DateTimeImmutable => new DateTimeImmutable($jam, new DateTimeZone('Asia/Jakarta')), self::$kaitPresensi);

        [$status, $data] = $svc->masuk(service('authContext'), $this->payload());

        return $this->respondSuccess($data, $status);
    }
}
