<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\MasterData\WebConfigService;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Lingkup role untuk semua laporan/rekap 5B (PRD 5B §7.3.6; D-08 & D-09b). Parameter `unit_satker` = `0` / `<id_unit>` /
 * `sat_<id_satker>` (bawaan `0`), divalidasi ada & aktif di master (422 `errors.unit_satker`).
 *
 * - Role 3: `unit_satker` **diabaikan** dan dipaksa ke satker akun; akun tanpa satker → unit akun; tanpa keduanya, atau
 *   satker akun bukan angka kanonik → 403 (fail-closed). Menutup celah legacy L5B-02 (legacy membiarkan admin satker tanpa
 *   satker memilih satker lain di unitnya, `Presensi.php:747-775`) [V2].
 * - Role lain yang lolos route: lingkup sesuai parameter (legacy bawaan `sat_47` hard-code tidak di-port; bawaan v2 `0`).
 */
final class CakupanLaporan
{
    public function __construct(private readonly BaseConnection $db, private readonly ?WebConfigService $webConfig = null)
    {
    }

    public function lingkup(AuthContext $auth, mixed $unitSatker): LingkupLaporan
    {
        if ($auth->role() === Role::ADMIN_SATKER) {
            return $this->lingkupAkun($auth);
        }

        $kode = is_string($unitSatker) ? trim($unitSatker) : ($unitSatker === null ? '' : null);

        if ($kode === '' || $kode === '0') {
            return new LingkupLaporan(LingkupLaporan::KEMENTERIAN, null, $this->namaKementerian());
        }

        if ($kode !== null && preg_match('/^sat_([1-9]\d{0,9})$/', $kode, $m) === 1) {
            return $this->satker((int) $m[1]) ?? throw ValidationException::forField('unit_satker', 'Satuan kerja tidak ditemukan.');
        }

        if ($kode !== null && preg_match('/^[1-9]\d{0,9}$/', $kode) === 1) {
            return $this->unit((int) $kode) ?? throw ValidationException::forField('unit_satker', 'Unit kerja tidak ditemukan.');
        }

        throw ValidationException::forField('unit_satker', 'Lingkup harus 0, id unit, atau sat_<id satker>.');
    }

    private function lingkupAkun(AuthContext $auth): LingkupLaporan
    {
        $satker = (string) ($auth->idSatker() ?? '');

        if ($satker !== '') {
            $lingkup = preg_match('/^[1-9]\d{0,9}$/', $satker) === 1 ? $this->satker((int) $satker) : null;

            return $lingkup ?? throw new ForbiddenException('Lingkup akun tidak valid.');
        }

        $unit    = (string) ($auth->idUnit() ?? '');
        $lingkup = preg_match('/^[1-9]\d{0,9}$/', $unit) === 1 ? $this->unit((int) $unit) : null;

        return $lingkup ?? throw new ForbiddenException('Lingkup akun tidak valid.');
    }

    private function unit(int $id): ?LingkupLaporan
    {
        $nama = $this->db->table('unit')->select('unit')->where('id_unit', $id)->where('status', 1)->get()->getRow('unit');

        return $nama === null ? null : new LingkupLaporan(LingkupLaporan::UNIT, $id, (string) $nama);
    }

    private function satker(int $id): ?LingkupLaporan
    {
        $nama = $this->db->table('satker')->select('satker')->where('id_satker', $id)->where('status', 1)->get()->getRow('satker');

        return $nama === null ? null : new LingkupLaporan(LingkupLaporan::SATKER, $id, (string) $nama);
    }

    private function namaKementerian(): string
    {
        try {
            $nama = ($this->webConfig ?? service('webConfigService'))->value('nama_kementerian');
        } catch (Throwable) {
            $nama = null;
        }

        return is_string($nama) && trim($nama) !== '' ? trim($nama) : 'Kementerian';
    }
}
