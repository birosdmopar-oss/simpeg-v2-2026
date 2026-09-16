<?php

declare(strict_types=1);

namespace App\Libraries\Siasn;

use App\Interfaces\SiasnGatewayInterface;

/**
 * Mock adapter SIASN/SAPK (F0-16). Tidak pernah melakukan koneksi jaringan.
 */
class MockSiasnAdapter implements SiasnGatewayInterface
{
    /**
     * Payload terakhir yang "dikirim" — untuk assert di test consumer.
     *
     * @var list<array<string, mixed>>
     */
    private array $synced = [];

    public function getPegawai(string $nip): array
    {
        log_message('info', '[MockSiasnAdapter] getPegawai nip={nip}', ['nip' => $nip]);

        return [
            'status_code' => '200',
            'message'     => 'OK (mock)',
            'data'        => [
                'nip'          => $nip,
                'nama'         => 'Pegawai Mock ' . substr($nip, -4),
                'golongan'     => 'III/c',
                'jabatan'      => 'Analis Kepegawaian (mock)',
                'unit_kerja'   => 'Biro Kepegawaian (mock)',
                'status_asn'   => 'PNS',
                'last_sync_at' => date('Y-m-d H:i:s'),
            ],
        ];
    }

    public function getRiwayat(string $nip, string $jenis): array
    {
        log_message('info', '[MockSiasnAdapter] getRiwayat nip={nip} jenis={jenis}', ['nip' => $nip, 'jenis' => $jenis]);

        return [
            'status_code' => '200',
            'message'     => 'OK (mock)',
            'data'        => [
                ['nip' => $nip, 'jenis' => $jenis, 'keterangan' => 'Riwayat mock #1', 'tmt' => '2020-04-01'],
                ['nip' => $nip, 'jenis' => $jenis, 'keterangan' => 'Riwayat mock #2', 'tmt' => '2024-10-01'],
            ],
        ];
    }

    public function syncPegawai(string $nip, array $payload): array
    {
        $this->synced[] = ['nip' => $nip, 'payload' => $payload];

        log_message('info', '[MockSiasnAdapter] syncPegawai nip={nip} fields={fields}', ['nip' => $nip, 'fields' => implode(',', array_keys($payload))]);

        return [
            'status_code' => '200',
            'message'     => 'Data tersinkron (mock)',
            'data'        => ['nip' => $nip] + $payload,
            'synced_at'   => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function synced(): array
    {
        return $this->synced;
    }
}
