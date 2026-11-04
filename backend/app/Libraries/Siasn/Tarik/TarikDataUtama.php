<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:tarik --jenis=data-utama` — satu langkah (staging `siasn_simpeg.data_utama` tidak dibuat, DBV-Q5) yang
 * menggabungkan `SS/Data_utama::sync_siasn` + `SI::sync_data_utama` [K `SI:380-453`] + `SI::sync_pns_id` [K `SI:455-515`]
 * (endpoint sama `pns/data-utama/{nip}`):
 *
 * - salin ke `pegawai` [K `SI:411-434`]: `nip_lama` bila lokal kosong, `npwp` (tanpa titik) bila berbeda, `no_hp` bila
 *   lokal kosong; ada perubahan → juga `keterangan = 'Sync SIASN'`, `updated_by` akun sistem;
 * - pairing [K `SI:487-500`]: `id_pns_siasn` lokal kosong + SIASN mengembalikan `id` → `id_pns_siasn`, `siasn_flag = 1`,
 *   `siasn_lu`; gagal/tanpa id → `siasn_flag = 2` + `siasn_lu` (dicoba lagi pada siklus harian berikutnya).
 */
final class TarikDataUtama implements JenisTarikSiasn
{
    public function nama(): string
    {
        return 'data-utama';
    }

    public function kolomStatus(): string
    {
        return 'du';
    }

    public function jenisPegawai(): ?array
    {
        return null;
    }

    public function ambil(SiasnGatewayInterface $siasn, string $nip): array
    {
        return $siasn->getPegawai($nip);
    }

    public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
    {
        $rencana = new RencanaTarik();

        if ($hasil === []) {
            return $rencana;
        }

        $lokal = array_column($db->table('pegawai')->select('nip, nip_lama, npwp, no_hp, id_pns_siasn')
            ->whereIn('nip', array_map('strval', array_keys($hasil)))->get()->getResultArray(), null, 'nip');

        foreach ($hasil as $nip => $h) {
            $nip = (string) $nip;
            $peg = $lokal[$nip] ?? null;

            if ($peg === null) {
                continue;
            }

            $data   = $h['status_code'] === SiasnAdapter::SUKSES && is_array($h['data']) ? $h['data'] : null;
            $ubah   = $data === null ? [] : $this->salin($peg, $data, $rencana);
            $pasang = [];

            if (trim((string) $peg['id_pns_siasn']) === '') {
                $pasang = ['siasn_flag' => 2, 'siasn_lu' => $sekarang];

                try {
                    $id = $data === null ? null : NilaiSiasn::teks($data, 'id', 100);
                } catch (NilaiTidakSah $e) {
                    $rencana->tolak($e->alasan);
                    $id = null;
                }

                if ($id !== null) {
                    $pasang = ['id_pns_siasn' => $id, 'siasn_flag' => 1, 'siasn_lu' => $sekarang];
                }
            }

            if ($ubah !== []) {
                $ubah += ['keterangan' => 'Sync SIASN', 'updated_by' => $idAkun, 'updated_at' => $sekarang];
            }

            if ($ubah === [] && $pasang === []) {
                continue;
            }

            $rencana->ubah('pegawai', ['nip'   => $nip], $ubah + $pasang, match (true) {
                $ubah !== []                   => 'ubah',
                isset($pasang['id_pns_siasn']) => 'pasang',
                default                        => null,
            });

            if ($ubah !== [] && isset($pasang['id_pns_siasn'])) {
                $rencana->jumlah['pasang']++;
            }
        }

        return $rencana;
    }

    /**
     * @param array<string, mixed> $peg
     * @param array<string, mixed> $siasn
     *
     * @return array<string, string>
     */
    private function salin(array $peg, array $siasn, RencanaTarik $rencana): array
    {
        try {
            $nipLama = NilaiSiasn::teks($siasn, 'nipLama', 18);
            $npwp    = NilaiSiasn::teks(['noNpwp' => str_replace('.', '', (string) (is_scalar($siasn['noNpwp'] ?? null) ? $siasn['noNpwp'] : ''))], 'noNpwp', 20);
            $noHp    = NilaiSiasn::teks($siasn, 'noHp', 20);
        } catch (NilaiTidakSah $e) {
            $rencana->tolak($e->alasan);

            return [];
        }

        $ubah = [];

        if (trim((string) $peg['nip_lama']) === '' && $nipLama !== null) {
            $ubah['nip_lama'] = $nipLama;
        }

        if ($npwp !== null && $npwp !== (string) $peg['npwp']) {
            $ubah['npwp'] = $npwp;
        }

        if (trim((string) $peg['no_hp']) === '' && $noHp !== null) {
            $ubah['no_hp'] = $noHp;
        }

        return $ubah;
    }
}
