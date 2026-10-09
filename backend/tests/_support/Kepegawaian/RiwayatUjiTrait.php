<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use App\Libraries\Kepegawaian\Riwayat\RiwayatRegistry;
use App\Libraries\Kepegawaian\Riwayat\SnapshotSync;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Services;
use Tests\Support\Kepegawaian\Riwayat\UjiKp;
use Tests\Support\Kepegawaian\Riwayat\UjiPendidikan;

/**
 * Helper test mesin riwayat (WS-1 M1 MAKE-004) untuk test turunan Tests\Support\DatabaseTestCase: memasang engine
 * dengan registry berisi Definisi uji (atau Definisi nyata di milestone berikutnya), fake PegawaiScope/lampiran, dan
 * pencatat event notifikasi. Hanya INSERT (cocok bingkai transaksi MAKE-001).
 *
 * Panggil lepasEngine() di tearDown() (mencabut pendengar event).
 */
trait RiwayatUjiTrait
{
    use PegawaiFixtureTrait;

    protected FakePegawaiScope $scopeUji;
    protected FakeAttachmentService $lampiranUji;
    protected UjiPendidikan $pendidikanUji;
    protected UjiKp $kpUji;
    protected int $idPangkatUji = 0;

    /**
     * @var list<array<string, mixed>> payload event RiwayatEngine::EVENT_NOTIFIKASI
     */
    protected array $notifikasiUji = [];

    /**
     * @param list<RiwayatDefinisi>|null $definisi null = UjiPendidikan + UjiKp
     */
    protected function pasangEngine(?FakePegawaiScope $scope = null, ?array $definisi = null): void
    {
        $this->scopeUji      = $scope ?? FakePegawaiScope::izinkanSemua();
        $this->lampiranUji   = new FakeAttachmentService();
        $this->pendidikanUji = new UjiPendidikan();
        $this->kpUji         = new UjiKp();

        Services::injectMock('pegawaiScope', $this->scopeUji);
        Services::injectMock('attachmentService', $this->lampiranUji);
        Services::injectMock('riwayatRegistry', new RiwayatRegistry(
            static fn () => service('pegawaiScope'),
            $definisi ?? [$this->pendidikanUji, $this->kpUji],
        ));
        Services::injectMock('snapshotSync', new SnapshotSync());
        Services::injectMock('riwayatService', new RiwayatEngine());

        $this->notifikasiUji = [];
        Events::on(RiwayatEngine::EVENT_NOTIFIKASI, function (array $payload): void {
            $this->notifikasiUji[] = $payload;
        });
    }

    protected function lepasEngine(): void
    {
        Events::removeAllListeners(RiwayatEngine::EVENT_NOTIFIKASI);
        Services::reset(true);
    }

    /**
     * Pegawai baru + akun ber-role $role untuknya; mengembalikan [nip, AuthContext, baris pengguna].
     *
     * @param array<string, mixed> $akun override kolom `pengguna`
     *
     * @return array{0: string, 1: AuthContext, 2: array<string, mixed>}
     */
    protected function aktor(int $role, array $akun = [], ?int $idSatker = null): array
    {
        $nip = $idSatker === null ? $this->buatPegawai() : $this->buatPegawaiDiSatker($idSatker);
        $id  = $this->buatAkunUntuk($nip, $role, $akun);

        /** @var array<string, mixed> $row */
        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();

        return [$nip, $this->authUntukAkun($id), $row];
    }

    protected function admin(): AuthContext
    {
        return $this->aktor(Role::SUPER_ADMIN)[1];
    }

    protected function buatJenisKp(int $id, string $nama, int $status = 1): void
    {
        $this->db->table('jenis_kp')->insert(['id_jenis_kp' => $id, 'jenis_kp' => $nama, 'order' => $id, 'status' => $status]);
    }

    protected function buatJenjang(string $singkat, int $order, int $status = 1): int
    {
        $this->db->table('jenjang_pendidikan')->insert([
            'jenjang_pendidikan_singkat' => $singkat,
            'jenjang_pendidikan'         => 'Jenjang ' . $singkat,
            'order'                      => $order,
            'status'                     => $status,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Baris riwayat langsung (tanpa engine), mis. data impor atau pengajuan yang menunggu.
     *
     * @param array<string, mixed> $data
     */
    protected function sisipRiwayat(RiwayatDefinisi $d, string $nip, array $data): int
    {
        $this->db->table($d->tabel())->insert(array_merge([$d->kolomNip() => $nip], $data));

        return (int) $this->db->insertID();
    }

    /**
     * Master KP untuk UjiKp: jenis_kp 1 CPNS, 2 PNS, 3 Reguler, 6 Lainnya + satu pangkat III/a ($this->idPangkatUji).
     */
    protected function siapkanMasterKp(): void
    {
        foreach ([1 => 'CPNS', 2 => 'PNS', 3 => 'Reguler', 6 => 'Lainnya'] as $id => $nama) {
            $this->buatJenisKp($id, $nama);
        }

        $this->db->table('pangkat')->insert(['pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'order' => 9, 'status' => 1]);
        $this->idPangkatUji = (int) $this->db->insertID();
    }

    /**
     * Payload KP dari klien untuk UjiKp (rujukan master saja; nama master diturunkan beforeSave).
     *
     * @return array<string, int|string>
     */
    protected function dataKp(int $idJenisKp, string $tmt): array
    {
        return [
            'id_jenis_kp' => $idJenisKp,
            'id_pangkat'  => $this->idPangkatUji,
            'tmtsk'       => $tmt,
            'tgl_sk'      => $tmt,
            'no_sk'       => 'SK/' . $tmt,
        ];
    }

    /**
     * Baris `riwayat_kp` siap-INSERT (payload + kolom teks master), untuk menyiapkan data tanpa engine.
     *
     * @return array<string, int|string>
     */
    protected function barisKp(int $idJenisKp, string $tmt): array
    {
        return $this->dataKp($idJenisKp, $tmt) + [
            'jenis_kp'  => 'Jenis ' . $idJenisKp,
            'gol'       => 'III',
            'ruang'     => 'a',
            'gol_ruang' => 'III/a',
            'pangkat'   => 'Penata Muda',
        ];
    }

    /**
     * Berkas unggahan PDF (isi PDF minimal, dikenali finfo) atau teks.
     */
    protected function berkasUji(string $nama = 'ijazah.pdf', bool $pdf = true, int $tambahByte = 0): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rwy');
        $isi  = $pdf ? "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n" : "bukan pdf\n";
        file_put_contents($path, $isi . str_repeat(' ', $tambahByte));

        return new UploadedFile($path, $nama, $pdf ? 'application/pdf' : 'text/plain', (int) filesize($path), UPLOAD_ERR_OK);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function snapshotUji(string $tabel, string $nip): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->db->table($tabel)->where('nip', $nip)->get()->getRowArray();

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function auditUji(string $entity, string $entityId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table('audit_logs')->where('entity', $entity)->where('entity_id', $entityId)->orderBy('id_log', 'ASC')->get()->getResultArray();

        return $rows;
    }
}
