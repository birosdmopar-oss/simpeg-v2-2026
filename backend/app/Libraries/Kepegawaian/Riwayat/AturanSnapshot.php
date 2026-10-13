<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use InvalidArgumentException;

/**
 * Aturan pengisi satu tabel snapshot `pegawai_*` dari tabel riwayat sebagai data (kontrak beku S0-A MAKE-002; dipakai
 * SnapshotSync WS-1 M1). Acuan aturan per tabel: dok DBV-012 `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md`
 * §5.1. Satu Definisi boleh punya beberapa aturan (multi-target, mis. KP → pegawai_kp, pegawai_cpns, pegawai_pns).
 *
 * Pemilihan baris: baris riwayat milik NIP itu berstatus Disetujui (filter status ditambahkan engine, tidak ditulis di
 * sini) + $filter, diurutkan $urutan, ambil satu; tidak ada → baris snapshot dihapus. Sinkron hanya di approval final
 * (ADR-006), termasuk saat baris aktif ditolak/dihapus.
 */
final class AturanSnapshot
{
    /**
     * @param string                                       $tabel  tabel snapshot target, mis. 'pegawai_kp'
     * @param array<int|string, string>                    $kolom  pemetaan kolom riwayat => kolom snapshot; key numerik
     *                                                             = nama kolom sama (pola SyncsToSnapshot::snapshotFields)
     * @param array<string, scalar|list<scalar>|null>      $filter kolom riwayat => nilai; key boleh berakhiran ' !='
     *                                                             (mis. 'id_jenis_kp !=' => 6); nilai list = IN / NOT IN
     * @param array<string, string>                        $urutan kolom => arah ASC|DESC, berurutan prioritas; kolom tabel join
     *                                                             ditulis `tabel.kolom`
     * @param array<string, string>                        $join   tabel => kondisi ON (LEFT JOIN), mis.
     *                                                             ['jenjang_pendidikan' => 'jenjang_pendidikan.id_jenjang_pendidikan = riwayat_pendidikan.id_jenjang_pendidikan']
     * @param string                                       $kunci  kolom identitas riwayat & snapshot (satu baris snapshot per nilai)
     */
    public function __construct(
        public readonly string $tabel,
        public readonly array $kolom,
        public readonly array $filter = [],
        public readonly array $urutan = [],
        public readonly array $join = [],
        public readonly string $kunci = 'nip',
    ) {
        if (preg_match('/^pegawai_[a-z0-9_]+$/', $tabel) !== 1) {
            throw new InvalidArgumentException("Tabel snapshot '{$tabel}' tidak valid (harus pegawai_*).");
        }

        if ($kolom === []) {
            throw new InvalidArgumentException("Pemetaan kolom snapshot {$tabel} wajib diisi.");
        }

        foreach ($urutan as $arah) {
            if (! in_array($arah, ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException("Arah urutan snapshot {$tabel} harus ASC atau DESC.");
            }
        }
    }
}
