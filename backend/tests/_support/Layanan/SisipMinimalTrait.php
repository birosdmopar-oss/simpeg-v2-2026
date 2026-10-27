<?php

declare(strict_types=1);

namespace Tests\Support\Layanan;

/**
 * Sisip satu baris uji dengan kolom NOT NULL tanpa default diisi nilai sintetis per tipe (pola NipCascadeTest::sisip) —
 * untuk tabel Fase 3 yang banyak kolom wajibnya (mis. `riwayat_kp` → `pegawai_cpns` sebagai sumber TMT CPNS PMK).
 * Dipakai turunan DatabaseTestCase.
 */
trait SisipMinimalTrait
{
    /**
     * @param array<string, mixed> $isi
     */
    protected function sisipMinimal(string $tabel, array $isi): int
    {
        $kolom = $this->db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($tabel)],
        )->getResultArray();

        $baris = [];

        foreach ($kolom as $k) {
            $nama = (string) $k['COLUMN_NAME'];

            if (array_key_exists($nama, $isi) || $k['IS_NULLABLE'] === 'YES' || $k['COLUMN_DEFAULT'] !== null || str_contains((string) $k['EXTRA'], 'auto_increment')) {
                continue;
            }

            $baris[$nama] = match ((string) $k['DATA_TYPE']) {
                'date'                                                                            => '2020-01-01',
                'datetime', 'timestamp'                                                           => '2020-01-01 00:00:00',
                'year'                                                                            => 2020,
                'int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'double', 'float' => 1,
                default                                                                           => substr('Uji ' . $nama, 0, max(1, (int) ($k['CHARACTER_MAXIMUM_LENGTH'] ?? 10))),
            };
        }

        $this->db->table($tabel)->insert(array_merge($baris, $isi));

        return (int) $this->db->insertID();
    }

    /**
     * TMT CPNS pegawai (sumber batas P-3 PMK, `pegawai_cpns.tmtsk`).
     */
    protected function aturTmtCpns(string $nip, string $tmt): void
    {
        $idKp = $this->sisipMinimal('riwayat_kp', ['nip' => $nip]);
        $this->sisipMinimal('pegawai_cpns', ['nip' => $nip, 'id_riwayat_kp' => $idKp, 'tmtsk' => $tmt]);
    }
}
