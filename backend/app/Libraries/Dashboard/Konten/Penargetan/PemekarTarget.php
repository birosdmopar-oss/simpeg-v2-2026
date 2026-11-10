<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Penargetan;

use CodeIgniter\Database\BaseConnection;

/**
 * Memekarkan {@see SpesifikasiTarget} menjadi daftar NIP unik (snapshot penerima; PRD Fase 7 v2.0 Q7-15, legacy
 * `L_announcement.php:447-620`). Aturan:
 * - `"0"` → semua pegawai `status = 1`;
 * - `"<id_unit>"` / `"sat_<id_satker>"` → pegawai `status = 1` yang `pegawai_mutasi_jabatan.id_unit` / `id_satker`-nya cocok
 *   (snapshot jabatan aktif, PK nip);
 * - pegawai yang dipilih eksplisit ikut APA ADANYA, tanpa cek status [K] (legacy `L_announcement.php:561-586` menambahkan NIP
 *   tanpa filter status; hanya turunan unit/satker `peg.status = 1`, :477/:542). Hanya NIP yang ADA di `pegawai` yang
 *   ikut (FK `user_*` -> pegawai); pilihan pegawai DIABAIKAN bila target seluruh kementerian (`!$allKem`, :561);
 * - `kecuali` dikurangkan terakhir, dan menang atas semua sumber lain.
 * Urutan hasil: NIP menaik (deterministik untuk test dan sinkron).
 */
final class PemekarTarget
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return list<string>
     */
    public function pekarkan(SpesifikasiTarget $target): array
    {
        $nip = [];

        if ($target->seluruhKementerian()) {
            $nip = $this->kolomNip($this->db->table('pegawai peg')->select('peg.nip')->where('peg.status', 1));
        } else {
            $idUnit   = $target->idUnit();
            $idSatker = $target->idSatker();

            if ($idUnit !== [] || $idSatker !== []) {
                $b = $this->db->table('pegawai peg')
                    ->select('peg.nip')
                    ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip')
                    ->where('peg.status', 1)
                    ->groupStart();

                if ($idUnit !== []) {
                    $b->whereIn('pmj.id_unit', $idUnit);
                }

                if ($idSatker !== []) {
                    $idUnit !== [] ? $b->orWhereIn('pmj.id_satker', $idSatker) : $b->whereIn('pmj.id_satker', $idSatker);
                }

                $nip = $this->kolomNip($b->groupEnd());
            }
        }

        if ($target->pegawai !== [] && ! $target->seluruhKementerian()) {
            $nip = array_merge($nip, $this->kolomNip($this->db->table('pegawai peg')->select('peg.nip')->whereIn('peg.nip', $target->pegawai)));
        }

        $nip = array_values(array_unique($nip));

        if ($target->kecuali !== []) {
            $nip = array_values(array_diff($nip, $target->kecuali));
        }

        sort($nip, SORT_STRING);

        return $nip;
    }

    /**
     * @return list<string>
     */
    private function kolomNip(\CodeIgniter\Database\BaseBuilder $b): array
    {
        return array_map('strval', array_column($b->get()->getResultArray(), 'nip'));
    }
}
