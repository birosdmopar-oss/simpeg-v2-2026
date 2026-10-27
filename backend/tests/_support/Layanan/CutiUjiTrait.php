<?php

declare(strict_types=1);

namespace Tests\Support\Layanan;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Libraries\Layanan\Cuti\ApproverTerjangkau;
use App\Libraries\Layanan\Cuti\AtasanLangsungCuti;
use App\Libraries\Layanan\Cuti\CutiApprovalService;
use App\Libraries\Layanan\Cuti\CutiService;
use App\Libraries\Layanan\Cuti\EfekFinalCutiService;
use App\Libraries\Layanan\Cuti\KuotaCutiService;
use App\Libraries\Layanan\Cuti\PejabatBerwenangCuti;
use App\Libraries\MasterData\HariLiburService;
use Closure;
use Config\Cuti as CutiConfig;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Kepegawaian\FakeAttachmentService;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * Fixture bersama test cuti (MAKE-022 M3): pemohon lengkap (pegawai + pangkat/masa kerja + jabatan + akun), atasan
 * langsung lewat `id_atasan_es_4`, petugas PyB lewat `pa_layanan`, saldo, baris cuti, dan perakit `CutiService` dengan
 * jam tetap dan lampiran palsu. Hanya INSERT/SELECT (aman di bingkai transaksi uji).
 */
trait CutiUjiTrait
{
    use PegawaiFixtureTrait;

    /** Hari uji tetap: Senin 2026-11-02 09:00 WIB. */
    protected string $sekarangUji = '2026-11-02 09:00:00';

    protected FakeAttachmentService $lampiranUji;

    protected ?PenerbitSuratCutiUji $penerbitSurat = null;

    /**
     * @param array<string, mixed> $pegawai kolom `pegawai`
     * @param array<string, mixed> $kp      kolom `pegawai_kp`
     * @param array<string, mixed> $pmj     kolom `pegawai_mutasi_jabatan`
     *
     * @return array{nip: string, id_pengguna: int, jabatan: array<string, int>, auth: AuthContext}
     */
    protected function buatPemohon(int $role = 2, array $pegawai = [], array $kp = [], array $pmj = [], ?int $idJabatan = null): array
    {
        $nip = $this->buatPegawai($pegawai + ['id_jenis_pegawai' => 1], $pmj + ($idJabatan === null ? [] : ['id_jabatan' => $idJabatan]));

        $this->db->table('pegawai_kp')->insert($kp + [
            'nip' => $nip, 'id_pangkat' => 13, 'id_jenis_kp' => 2, 'tmtsk' => '2012-04-01', 'tgl_sk' => '2012-03-01', 'jenis_kp' => 'Pengangkatan PNS',
            'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda', 'mker_th' => 10, 'mker_bl' => 3,
        ]);

        $idPengguna = $this->buatAkunUntuk($nip, $role);
        $jabatan    = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();

        return ['nip' => $nip, 'id_pengguna' => $idPengguna, 'jabatan' => $jabatan, 'auth' => $this->authUntukAkun($idPengguna)];
    }

    /**
     * Pegawai pemegang jabatan (atasan/pejabat) dengan akun role $role (null = tanpa akun).
     *
     * @return array{nip: string, id_jabatan: int, id_pengguna: int|null, auth: AuthContext|null}
     */
    protected function buatPejabat(?int $role = 2, array $pegawai = []): array
    {
        $nip    = $this->buatPegawai($pegawai + ['id_jenis_pegawai' => 1, 'id_jenis_status' => 1]);
        $pmj    = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();
        $idPeng = $role === null ? null : $this->buatAkunUntuk($nip, $role);

        return ['nip' => $nip, 'id_jabatan' => (int) $pmj['id_jabatan'], 'id_pengguna' => $idPeng, 'auth' => $idPeng === null ? null : $this->authUntukAkun($idPeng)];
    }

    /**
     * Pemohon dengan AL (es_4) dan PyB (aturan `pa_layanan` jenis 1) yang berbeda — alur dua langkah.
     *
     * @return array{pemohon: array<string, mixed>, al: array<string, mixed>, pyb: array<string, mixed>}
     */
    protected function buatAlurDuaLangkah(int $roleAl = 2, int $rolePyb = 2): array
    {
        $al  = $this->buatPejabat($roleAl);
        $pyb = $this->buatPejabat($rolePyb);

        $pemohon = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $this->aturanPyb($pemohon['jabatan'], $pyb['id_jabatan'], 'III/a');

        return ['pemohon' => $pemohon, 'al' => $al, 'pyb' => $pyb];
    }

    /**
     * @param array<string, mixed> $jabatanPemohon baris pmj pemohon
     */
    protected function aturanPyb(array $jabatanPemohon, int $idJabatanPetugas, string $golRuang): void
    {
        $this->db->table('pa_layanan')->insert([
            'id_jenis_pa'                  => 1,
            'id_jenis_pegawai'             => 1,
            'id_unit_pemohon'              => $jabatanPemohon['id_unit'],
            'id_group_jabatan_pemohon'     => $jabatanPemohon['id_group_jabatan'],
            'id_sub_group_jabatan_pemohon' => $jabatanPemohon['id_sub_group_jabatan'],
            'gol_ruang_pemohon'            => $golRuang,
            'id_jabatan'                   => $idJabatanPetugas,
            'status'                       => 1,
        ]);
    }

    /**
     * @param array{int, int, int, int, int} $n N, N-1, N-2, utang, ditangguhkan
     */
    protected function buatSaldo(string $nip, int $tahun, array $n = [12, 6, 6, 0, 0]): void
    {
        $this->db->table('pegawai_sisa_cuti')->insert([
            'nip'        => $nip, 'tahun' => $tahun, 'sisa_cuti' => $n[0], 'sisa_cuti_n1' => $n[1], 'sisa_cuti_n2' => $n[2],
            'utang_cuti' => $n[3], 'sisa_cuti_ditangguhkan' => $n[4],
        ]);
    }

    /**
     * Isian pengajuan sah (cuti Tahunan 3 hari kerja, Rabu–Jum 2026-11-04..06) untuk pemohon dari {@see buatAlurDuaLangkah}.
     *
     * @param array<string, mixed> $lebih
     *
     * @return array<string, mixed>
     */
    protected function isianCuti(?string $nipAl, ?string $nipPyb, array $lebih = []): array
    {
        return $lebih + [
            'id_jenis_cuti'       => 1, 'tgl_mulai' => '2026-11-04', 'tgl_akhir' => '2026-11-06',
            'no_telp_saat_cuti'   => '08123456789', 'alamat_saat_cuti' => 'Jl. Uji No. 1', 'keterangan' => 'Keperluan keluarga besar',
            'nip_atasan_langsung' => $nipAl, 'nip_yang_menyetujui' => $nipPyb,
        ];
    }

    /**
     * Baris `riwayat_cuti` langsung (tanpa service) untuk skenario state machine/hapus.
     *
     * @param array<string, mixed> $lebih
     */
    protected function buatBarisCuti(string $nip, array $lebih = []): int
    {
        $this->db->table('riwayat_cuti')->insert($lebih + [
            'nip'       => $nip, 'nama' => 'Pegawai Uji', 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'tgl_mulai' => '2026-11-04', 'tgl_akhir' => '2026-11-06',
            'lama_cuti' => 3, 'status' => 0, 'status_al' => 0, 'status_pyb' => 0, 'created_at' => '2026-11-02 02:00:00',
        ]);

        return (int) $this->db->insertID();
    }

    protected function cutiService(?Closure $sekarang = null): CutiService
    {
        $this->lampiranUji ??= new FakeAttachmentService();
        $sekarang ??= fn (): DateTimeImmutable => new DateTimeImmutable($this->sekarangUji, new DateTimeZone(\App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis::ZONA));

        $aturan = config(CutiConfig::class)->aturan();
        $kuota  = new KuotaCutiService($this->db, $aturan, new HariLiburService($this->db));

        return new CutiService(
            $this->db,
            $aturan,
            $kuota,
            new EfekFinalCutiService($this->db, $kuota, $this->penerbitUji()),
            new AtasanLangsungCuti($this->db),
            new PejabatBerwenangCuti($this->db),
            new ApproverTerjangkau($this->db),
            new PegawaiScope($this->db),
            $this->lampiranUji,
            null,
            $sekarang,
        );
    }

    protected function approvalService(?Closure $sekarang = null): CutiApprovalService
    {
        $svc    = $this->cutiService($sekarang);
        $aturan = config(CutiConfig::class)->aturan();
        $kuota  = new KuotaCutiService($this->db, $aturan, new HariLiburService($this->db));

        return new CutiApprovalService(
            $this->db,
            $aturan,
            $svc,
            $kuota,
            new EfekFinalCutiService($this->db, $kuota, $this->penerbitUji()),
            new ApproverTerjangkau($this->db),
        );
    }

    protected function penerbitUji(): PenerbitSuratCutiUji
    {
        return $this->penerbitSurat ??= new PenerbitSuratCutiUji();
    }
}
