<?php

declare(strict_types=1);

namespace Tests\Support\Layanan;

use App\Libraries\Esign\MockEsignAdapter;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Libraries\Layanan\Cuti\EsignCutiService;
use App\Libraries\Layanan\Cuti\SuratCutiService;

/**
 * Fixture bersama test e-sign cuti (MAKE-023 M4): cuti final langsung di DB (tanpa melewati approval) dan perakit
 * {@see EsignCutiService} dengan gateway mock, lampiran palsu, dan notifikasi opsional.
 */
trait EsignUjiTrait
{
    use CutiUjiTrait;

    protected const PASSPHRASE_UJI = 'RAHASIA-PASS-9X7Q';

    /**
     * Cuti berstatus final (1 atau 4) dengan PyB bernik terdaftar.
     *
     * @return array{id: int, a: array<string, mixed>}
     */
    protected function cutiFinal(int $status = 1, bool $nik = true): array
    {
        $a = $this->buatAlurDuaLangkah();

        if ($nik) {
            $this->db->table('pegawai')->where('nip', $a['pyb']['nip'])->update(['nik' => '3171000000000001']);
        }

        $id = $this->buatBarisCuti($a['pemohon']['nip'], [
            'nama'                => 'Pemohon Uji', 'jabatan' => 'Analis', 'satker' => 'Biro Uji', 'mker_th' => 10, 'mker_bl' => 3,
            'status'              => $status, 'status_al' => 1, 'status_pyb' => $status,
            'nip_atasan_langsung' => $a['al']['nip'], 'nama_atasan_langsung' => 'Atasan Uji', 'jabatan_atasan_langsung' => 'Kabag',
            'nip_yang_menyetujui' => $a['pyb']['nip'], 'nama_yang_menyetujui' => 'Pejabat Uji', 'jabatan_yang_menyetujui' => 'Karo',
            'alamat_saat_cuti'    => 'Jl. Uji No. 1', 'no_telp_saat_cuti' => '08123456789', 'keterangan' => 'Keperluan keluarga',
        ]);

        return ['id' => $id, 'a' => $a];
    }

    /**
     * @param list<string> $tanpaSertifikat
     */
    protected function esignService(array $tanpaSertifikat = []): EsignCutiService
    {
        $svc = $this->cutiService();

        return new EsignCutiService(
            $this->db,
            $svc,
            new SuratCutiService($this->db, $this->lampiranUji),
            new MockEsignAdapter($tanpaSertifikat),
            new PegawaiScope($this->db),
        );
    }
}
