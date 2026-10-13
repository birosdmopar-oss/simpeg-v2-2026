<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use InvalidArgumentException;

/**
 * Aturan lampiran untuk SATU kode `jenis_rwy` (value object, kontrak beku S0-A MAKE-002; pemilik berkas WS-1, dipakai
 * WS-2 lewat AttachmentServiceInterface::simpan()). Satu Definisi riwayat memuat daftar aturan ini, satu per kode.
 *
 * Batas ukuran per kode ikut legacy: 1, 2, atau 5 MB (keputusan #6 Sprint 0).
 */
final class AturanLampiran
{
    /**
     * @var list<int>
     */
    public const BATAS_MB = [1, 2, 5];

    /**
     * @var list<string>
     */
    public readonly array $ekstensi;

    /**
     * @param int          $idRiwayat kode `jenis_rwy.id_jenis_rwy` (kolom `document_attachment.id_riwayat`)
     * @param bool         $wajib     lampiran wajib: ditegakkan RiwayatService::tambah()/ubah() (baris wajib punya
     *                                lampiran kode ini setelah request) dalam transaksi engine yang sama
     * @param int          $batasMb   1, 2, atau 5
     * @param list<string> $ekstensi  ekstensi tanpa titik, mis. ['pdf']; dinormalkan huruf kecil
     */
    public function __construct(
        public readonly int $idRiwayat,
        public readonly bool $wajib,
        public readonly int $batasMb,
        array $ekstensi,
    ) {
        if ($idRiwayat < 0) {
            throw new InvalidArgumentException('Kode jenis_rwy lampiran tidak valid.');
        }

        if (! in_array($batasMb, self::BATAS_MB, true)) {
            throw new InvalidArgumentException("Batas lampiran {$batasMb} MB tidak dikenal (1, 2, atau 5 MB).");
        }

        $normal = [];

        foreach ($ekstensi as $ext) {
            $ext = strtolower(ltrim(trim($ext), '.'));

            if ($ext === '' || preg_match('/^[a-z0-9]+$/', $ext) !== 1) {
                throw new InvalidArgumentException('Ekstensi lampiran tidak valid.');
            }

            $normal[$ext] = true;
        }

        if ($normal === []) {
            throw new InvalidArgumentException('Daftar ekstensi lampiran wajib diisi.');
        }

        $this->ekstensi = array_keys($normal);
    }

    public function batasByte(): int
    {
        return $this->batasMb * 1024 * 1024;
    }

    public function ekstensiDiizinkan(string $ekstensi): bool
    {
        return in_array(strtolower(ltrim($ekstensi, '.')), $this->ekstensi, true);
    }

    /**
     * Bentuk snake_case untuk respons API/metadata form.
     *
     * @return array{id_riwayat: int, wajib: bool, batas_mb: int, ekstensi: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id_riwayat' => $this->idRiwayat,
            'wajib'      => $this->wajib,
            'batas_mb'   => $this->batasMb,
            'ekstensi'   => $this->ekstensi,
        ];
    }
}
