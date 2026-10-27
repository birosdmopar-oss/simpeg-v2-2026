<?php

declare(strict_types=1);

namespace Tests\Support\Layanan;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\AttachmentService;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\BerkasStudi;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use Config\Services;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * Fixture layanan studi TB/IB (MAKE-024 M5): master (MasterDataSeeder: jenjang 3 SLTA / 8 S.1 / 9 S.2, jenis_status 1/2,
 * jenis_pegawai 1), aktor ber-akun, PIC, isian sah, berkas per slot, AttachmentService nyata ke folder sementara,
 * PegawaiScope nyata, "hari ini" 2026-10-27 (WIB). Dipakai turunan DatabaseTestCase dengan `$seed = MasterDataSeeder`.
 */
trait LayananStudiUjiTrait
{
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    protected const HARI_INI = '2026-10-27';

    protected function siapkanLayananStudi(): void
    {
        Time::setTestNow(self::HARI_INI . ' 10:00:00', 'Asia/Jakarta');
        Services::injectMock('pegawaiScope', new PegawaiScope());
        Services::injectMock('attachmentService', new AttachmentService(new LocalStorageAdapter($this->akarLampiranUji())));
    }

    protected function bersihkanLayananStudi(): void
    {
        Time::setTestNow();
        $this->bersihkanLampiranUji();
        Services::reset(true);
    }

    /**
     * Pegawai ber-akun. Bawaan PNS (`id_jenis_pegawai = 1`), status jenis 1 (Aktif).
     *
     * @param array<string, mixed> $pegawai override kolom `pegawai`
     *
     * @return array{0: string, 1: AuthContext}
     */
    protected function aktorStudi(int $role, array $pegawai = [], ?int $idSatker = null): array
    {
        $override = $pegawai + ['id_jenis_pegawai' => 1, 'id_jenis_status' => 1];
        $nip      = $idSatker === null ? $this->buatPegawai($override) : $this->buatPegawaiDiSatker($idSatker, $override);

        return [$nip, $this->authUntukAkun($this->buatAkunUntuk($nip, $role))];
    }

    protected function jadikanPic(string $nip, int $idJenisLayanan, int $status = PetugasLayananService::STATUS_AKTIF): void
    {
        $this->db->table('petugas_layanan')->insert(['id_jenis_layanan' => $idJenisLayanan, 'nip' => $nip, 'status' => $status]);
    }

    /**
     * Isian TB/IB sah (S.1, mulai sebulan lagi, dua tahun).
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    protected function isianStudi(array $override = []): array
    {
        return $override + [
            'tgl_mulai'             => '2026-12-01',
            'tgl_akhir'             => '2028-11-30',
            'id_jenjang_pendidikan' => '8',
            'institusi_pendidikan'  => 'Universitas Uji',
            'jurusan_pendidikan'    => 'Manajemen Pariwisata',
            'lokasi_pendidikan'     => 'Jakarta',
            'lembaga_yang_mendanai' => 'Beasiswa Uji',
            'up'                    => 'Kepala Biro Sumber Daya Manusia dan Organisasi',
            'dalam_negeri'          => '1',
        ];
    }

    /**
     * Data SK sah untuk setujui / input role 1 status 1.
     *
     * @return array<string, string>
     */
    protected function dataSkTb(): array
    {
        return ['no_sk' => 'SK/TB/001', 'tgl_sk' => '2026-10-20', 'ds_tb' => 'Surat Rektor', 'no_ds_tb' => 'DS/001'];
    }

    /**
     * Satu PDF sah per slot jenis itu.
     *
     * @return array<string, UploadedFile>
     */
    protected function berkasSemuaSlot(string $jenis, ?array $slot = null): array
    {
        $out = [];

        foreach ($slot ?? array_keys(BerkasStudi::slot($jenis)) as $nama) {
            $out[$nama] = $this->berkasPdf("{$nama}.pdf");
        }

        return $out;
    }

    /**
     * Tag lampiran v2 baris itu (urut).
     *
     * @return list<string>
     */
    protected function tagLampiran(int $kode, int $id): array
    {
        $rows = $this->db->table('document_attachment')->select('tag')->where('id_riwayat', $kode)->where('id_entri', (string) $id)
            ->orderBy('tag')->get()->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['tag'], $rows);
    }
}
