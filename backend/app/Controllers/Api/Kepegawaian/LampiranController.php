<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Libraries\Kepegawaian\Lampiran\AttachmentService;
use App\Libraries\Kepegawaian\Lampiran\OtorisasiLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Endpoint lampiran riwayat B-18 (MAKE-009) — kontrak: README.md bagian "Lampiran".
 *
 *   GET    pegawai/{nip}/lampiran?id_riwayat=&id_entri=   daftar lampiran satu baris riwayat (izin lihat)
 *   POST   pegawai/{nip}/lampiran                         multipart `berkas`, `id_riwayat`, `id_entri` (tambah/ubah)
 *   GET    pegawai/{nip}/lampiran/{id}/unduh              unduh berkas (izin lihat)
 *   DELETE pegawai/{nip}/lampiran/{id}                    hapus keras + audit (izin hapus/ubah)
 *
 * Otorisasi: OtorisasiLampiran (Definisi pemilik kode × role → PegawaiScope → keterikatan id_entri). NIP dari route
 * selalu string (castNumericParams mati: NIP 18 digit tidak boleh menjadi int).
 */
class LampiranController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(string $nip): ResponseInterface
    {
        [$idRiwayat, $idEntri] = $this->kodeDanEntri($this->request->getGet());

        $this->otorisasi()->bolehDaftar(service('authContext'), $nip, $idRiwayat, $idEntri);

        return $this->respondSuccess($this->lampiran()->daftar($nip, $idRiwayat, $idEntri));
    }

    public function create(string $nip): ResponseInterface
    {
        [$idRiwayat, $idEntri] = $this->kodeDanEntri($this->payload());

        $aturan = $this->otorisasi()->bolehUnggah(service('authContext'), $nip, $idRiwayat, $idEntri);
        $berkas = $this->request->getFile('berkas');

        if (! $berkas instanceof UploadedFile) {
            throw ValidationException::forField('berkas', 'Berkas wajib diunggah.');
        }

        $row = $this->dalamTransaksi(fn (): array => $this->lampiran()->simpan($nip, $idRiwayat, $idEntri, $berkas, $aturan));

        return $this->respondSuccess($row, 201);
    }

    public function unduh(string $nip, string $id): ResponseInterface
    {
        $row = $this->otorisasi()->barisMilik($nip, (int) $id);
        $this->otorisasi()->bolehUnduh(service('authContext'), $nip, $row);

        $hasil = $this->lampiran()->ambil($nip, (int) $id);
        $nama  = (string) ($hasil['lampiran']['display_name'] ?? 'lampiran');
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $nama);

        return $this->response
            ->setStatusCode(200)
            ->setContentType((string) ($hasil['lampiran']['file_type'] ?? '') !== '' ? (string) $hasil['lampiran']['file_type'] : 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nama))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($hasil['isi']);
    }

    public function delete(string $nip, string $id): ResponseInterface
    {
        $row = $this->otorisasi()->barisMilik($nip, (int) $id);
        $this->otorisasi()->bolehHapus(service('authContext'), $nip, $row);

        $this->dalamTransaksi(function () use ($nip, $id): void {
            $this->lampiran()->hapus($nip, (int) $id);
        });

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false, 'item' => $row]);
    }

    /**
     * `id_riwayat` (kode jenis_rwy, bilangan bulat positif) dan `id_entri` (PK baris riwayat) dari input; 422 bila
     * kosong/tidak valid.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: int, 1: string}
     */
    private function kodeDanEntri(array $input): array
    {
        $errors    = [];
        $idRiwayat = $input['id_riwayat'] ?? null;
        $idEntri   = $input['id_entri'] ?? null;

        if (! (is_int($idRiwayat) || is_string($idRiwayat)) || ! ctype_digit((string) $idRiwayat) || (int) $idRiwayat <= 0) {
            $errors['id_riwayat'] = ['Kode jenis lampiran wajib diisi angka.'];
        }

        if (! (is_int($idEntri) || is_string($idEntri)) || trim((string) $idEntri) === '' || strlen((string) $idEntri) > 100) {
            $errors['id_entri'] = ['Data riwayat wajib dipilih.'];
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        return [(int) $idRiwayat, trim((string) $idEntri)];
    }

    /**
     * Jalankan $kerja dalam satu transaksi (baris + audit), lalu rekonsiliasi berkas — juga saat rollback.
     *
     * @template T
     *
     * @param callable(): T $kerja
     *
     * @return T
     */
    private function dalamTransaksi(callable $kerja): mixed
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $hasil = $kerja();

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaksi lampiran gagal.');
            }

            $db->transCommit();

            return $hasil;
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $lampiran = $this->lampiran();

            if ($lampiran instanceof AttachmentService) {
                $lampiran->selesaikan();
            }
        }
    }

    private function lampiran(): AttachmentServiceInterface
    {
        return service('attachmentService');
    }

    private function otorisasi(): OtorisasiLampiran
    {
        return new OtorisasiLampiran(service('riwayatRegistry'), service('pegawaiScope'));
    }
}
