<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Layanan\Cuti\TokenSuratCutiService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * Jalur operator pencabutan akses publik surat cuti (PRD Fase 6 v1.0f Q6-22 "akses sampai dicabut"; review B4a P4,
 * MAKE-065):
 *
 *   php spark surat-cuti:token --id=<id cuti>              (lihat status)
 *   php spark surat-cuti:token --id=<id cuti> --nonaktif   (URL verifikasi + PDF publik → 404)
 *   php spark surat-cuti:token --id=<id cuti> --aktif      (pulihkan token yang sama)
 *
 * Perubahan tercatat `audit_logs` (`jenis_audit` `cuti.token_surat`, `sumber` `cli`, tanpa aktor & nilai token). Keluaran
 * hanya id cuti dan status — tidak pernah token, URL, atau NIP. Rotasi tidak tersedia di CLI (URL di PDF ber-TTE tidak bisa
 * diganti, DBV-030 Q3).
 */
class SuratCutiToken extends BaseCommand
{
    protected $group       = 'Cuti';
    protected $name        = 'surat-cuti:token';
    protected $description = 'Lihat, nonaktifkan, atau aktifkan kembali token publik surat cuti (beraudit).';
    protected $usage       = 'surat-cuti:token --id=<id cuti> [--nonaktif | --aktif]';
    protected $options     = [
        '--id'       => 'id_riwayat_cuti (wajib)',
        '--nonaktif' => 'Cabut akses publik (verifikasi + PDF → 404)',
        '--aktif'    => 'Aktifkan kembali token yang sama',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['id' => true, 'nonaktif' => false, 'aktif' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $id = $opsi['id'] ?? null;

        if ($argumen !== [] || ! is_string($id) || ! ctype_digit($id) || (int) $id < 1 || (isset($opsi['nonaktif']) && isset($opsi['aktif']))) {
            CLI::error('Isi --id=<id cuti> (angka) dengan paling banyak satu dari --nonaktif / --aktif. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        /** @var TokenSuratCutiService $token */
        $token = service('tokenSuratCuti');

        try {
            $status = match (true) {
                isset($opsi['nonaktif']) => $token->nonaktifkan(null, (int) $id),
                isset($opsi['aktif'])    => $token->aktifkan(null, (int) $id),
                default                  => $token->status((int) $id),
            };
        } catch (NotFoundException) {
            CLI::error("Cuti {$id} tidak ditemukan.");

            return EXIT_ERROR;
        } catch (ValidationException|ApiException $e) {
            CLI::error($e instanceof ValidationException ? implode(' ', array_merge(...array_values((array) $e->getErrors()))) : $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("Token surat cuti {$id}: " . match ($status) {
            TokenSuratCutiService::AKTIF    => 'aktif',
            TokenSuratCutiService::NONAKTIF => 'nonaktif',
            default                         => 'belum ada',
        }, $status === TokenSuratCutiService::NONAKTIF ? 'yellow' : 'green');

        return EXIT_SUCCESS;
    }
}
