<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Tb;

use App\Constants\Role;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Layanan\LayananStudi;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Models\Layanan\LayananModel;
use App\Models\Layanan\RiwayatTbModel;
use App\Models\Layanan\RiwayatTbPerpanModel;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Perpanjangan TB (PRD Fase 4 §6.7.4, D15, Q-12) — role 1 saja [K `Tb.php:614-674`], tanpa status approval, langsung
 * berlaku. `POST tb/{id}/perpanjangan` (`tgl_mulai_perpan` bawaan = akhir efektif + 1 hari [K], `tgl_akhir_perpan`,
 * berkas `file_sperpan` wajib 5 MB whitelist TB → `document_attachment` kode 48), `PUT tb/perpanjangan/{id}` (dua tanggal
 * saja [K]), `DELETE tb/perpanjangan/{id}` (fisik + audit + berkas). Cek `akhir ≥ mulai` dan induk status 1 (TB-13, Q-15).
 *
 * Setelah tiap tambah/ubah/hapus, di transaksi yang sama: `riwayat_tb.tgl_mulai_perpan/tgl_akhir_perpan` = MAX dari
 * semua perpanjangan induk (`PerpanjanganTb::ringkasan`), NULL bila tidak ada — pengganti trigger
 * `riwayat_tb_perpan_af*` (D1:10487-10527). `riwayat_tb.tgl_akhir` tidak diubah; tanpa notifikasi dan tanpa ubah
 * `id_jenis_status` [K].
 */
class PerpanjanganTbService extends LayananStudi
{
    protected function jenis(): string
    {
        return AturanStudi::JENIS_TB;
    }

    protected function rolePengaju(): array
    {
        return TbService::ROLE_PENGAJU;
    }

    protected function model(): LayananModel
    {
        return new RiwayatTbPerpanModel($this->koneksi());
    }

    protected function idJenisLayanan(): int
    {
        return PetugasLayananService::JENIS_TB;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return int id perpanjangan baru
     */
    public function tambah(AuthContext $auth, int $idTb, array $data, ?UploadedFile $berkas): int
    {
        $induk = $this->barisTerlihat($auth, $idTb);
        $this->hanyaRole1($auth);

        $nip = (string) $induk['nip'];

        return $this->transaksi(function () use ($auth, $idTb, $nip, $data, $berkas): int {
            $induk = $this->kunciBaris($nip, $idTb);
            $mulai = $this->tanggal($data, 'tgl_mulai_perpan') ?? PerpanjanganTb::saranMulai((string) $induk['tgl_akhir'], $induk['tgl_akhir_perpan'] ?? null);
            $akhir = $this->tanggal($data, 'tgl_akhir_perpan');

            $errors = PerpanjanganTb::periksa($mulai, $akhir, (int) $induk['status']);
            $aturan = $this->aturanBerkas(BerkasStudi::KODE_TB_PERPAN);

            if ($berkas === null) {
                $errors['file_sperpan'] = 'Surat perpanjangan wajib diunggah.';
            } else {
                try {
                    ValidasiBerkas::periksa($berkas, $aturan, 'file_sperpan');
                } catch (ValidationException $e) {
                    $errors += array_map(static fn (array|string $m): string => is_array($m) ? (string) reset($m) : $m, $e->getErrors() ?? []);
                }
            }

            $this->gagalBila($errors);

            $model = new RiwayatTbPerpanModel($this->koneksi());
            $param = ['id_riwayat_tb' => $idTb, 'tgl_mulai_perpan' => $mulai, 'tgl_akhir_perpan' => $akhir, 'file_sperpan' => '', 'created_by' => $auth->idPengguna()];
            $id    = (int) $model->withActor($auth, static fn () => $model->insert($param, true));

            /** @var UploadedFile $berkas */
            $lampiran = $this->lampiran()->simpan($nip, BerkasStudi::KODE_TB_PERPAN, $id, $berkas, $aturan);
            // `file_sperpan` NOT NULL [K]: diisi nama berkas tersimpan (berkas v2 tetap di document_attachment).
            $model->withActor($auth, static fn () => $model->update($id, ['file_sperpan' => (string) ($lampiran['basename'] ?? $lampiran['filename'] ?? '')]));

            $this->segarkanRingkasan($auth, $idTb);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function ubah(AuthContext $auth, int $idPerpan, array $data): int
    {
        [$perpan, $induk] = $this->perpanTerlihat($auth, $idPerpan);
        $nip              = (string) $induk['nip'];

        $this->transaksi(function () use ($auth, $idPerpan, $perpan, $nip, $data): void {
            $idTb  = (int) $perpan['id_riwayat_tb'];
            $induk = $this->kunciBaris($nip, $idTb);
            $mulai = $this->tanggal($data, 'tgl_mulai_perpan');
            $akhir = $this->tanggal($data, 'tgl_akhir_perpan');

            $this->gagalBila(PerpanjanganTb::periksa($mulai, $akhir, (int) $induk['status']));

            $model = new RiwayatTbPerpanModel($this->koneksi());
            $model->withActor($auth, static fn () => $model->update($idPerpan, ['tgl_mulai_perpan' => $mulai, 'tgl_akhir_perpan' => $akhir]));

            $this->segarkanRingkasan($auth, $idTb);
        });

        return (int) $perpan['id_riwayat_tb'];
    }

    public function hapus(AuthContext $auth, int $idPerpan): int
    {
        [$perpan, $induk] = $this->perpanTerlihat($auth, $idPerpan);
        $nip              = (string) $induk['nip'];
        $idTb             = (int) $perpan['id_riwayat_tb'];

        $this->transaksi(function () use ($auth, $idPerpan, $idTb, $nip): void {
            $this->kunciBaris($nip, $idTb);
            $this->hapusLampiran($nip, BerkasStudi::KODE_TB_PERPAN, $idPerpan);

            $model = new RiwayatTbPerpanModel($this->koneksi());
            $model->withActor($auth, static fn () => $model->delete($idPerpan));

            $this->segarkanRingkasan($auth, $idTb);
        });

        return $idTb;
    }

    /**
     * Ringkasan perpanjangan di induk (pengganti trigger), lewat model beraudit.
     */
    private function segarkanRingkasan(AuthContext $auth, int $idTb): void
    {
        $semua = $this->koneksi()->table('riwayat_tb_perpan')->select('tgl_mulai_perpan, tgl_akhir_perpan')
            ->where('id_riwayat_tb', $idTb)->get()->getResultArray();

        $ringkas = PerpanjanganTb::ringkasan(array_map(static fn (array $r): array => [
            'tgl_mulai_perpan' => (string) $r['tgl_mulai_perpan'],
            'tgl_akhir_perpan' => (string) $r['tgl_akhir_perpan'],
        ], $semua));

        $model = new RiwayatTbModel($this->koneksi());
        $model->withActor($auth, static fn () => $model->update($idTb, $ringkas));
    }

    /**
     * Perpanjangan + induk yang terlihat pemanggil (404), lalu syarat role 1 (403).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function perpanTerlihat(AuthContext $auth, int $idPerpan): array
    {
        $perpan = (new RiwayatTbPerpanModel($this->koneksi()))->find($idPerpan);

        if (! is_array($perpan)) {
            throw new NotFoundException('Perpanjangan tugas belajar tidak ditemukan.');
        }

        $induk = $this->barisTerlihat($auth, (int) $perpan['id_riwayat_tb']);
        $this->hanyaRole1($auth);

        return [$perpan, $induk];
    }

    private function hanyaRole1(AuthContext $auth): void
    {
        if ($auth->role() !== Role::SUPER_ADMIN) {
            $this->terlarang();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function tanggal(array $data, string $field): ?string
    {
        $nilai = isset($data[$field]) && is_scalar($data[$field]) ? trim((string) $data[$field]) : '';

        return $nilai === '' ? null : $nilai;
    }

    /**
     * @param array<string, string> $errors
     */
    private function gagalBila(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', array_map(static fn (string $m): array => [$m], $errors));
        }
    }
}
