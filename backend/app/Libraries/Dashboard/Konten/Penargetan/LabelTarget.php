<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Penargetan;

use CodeIgniter\Database\BaseConnection;

/**
 * Target tersimpan + label "NIP - nama" untuk pegawai yang dipilih/dikecualikan, agar form ubah menampilkan nama
 * (pencarian pegawai di form memakai `GET pegawai?search=`, bukan daftar semua pegawai). Dipakai popup/banner (Jalur A)
 * dan detail form digital (Jalur B) supaya `TargetPicker` bisa dipakai ulang (CR-115).
 */
final class LabelTarget
{
    /**
     * @return array{unit_satker: list<string>, pegawai: list<string>, kecuali: list<string>, label_pegawai: array<string, string>}
     */
    public static function untuk(BaseConnection $db, SpesifikasiTarget $spes): array
    {
        $nip   = array_values(array_unique([...$spes->pegawai, ...$spes->kecuali]));
        $label = [];

        if ($nip !== []) {
            foreach ($db->table('pegawai')->select('nip, glr_awal, nama, glr_akhir')->whereIn('nip', $nip)->get()->getResultArray() as $p) {
                $label[(string) $p['nip']] = $p['nip'] . ' - ' . self::namaLengkap($p);
            }
        }

        return $spes->keArray() + ['label_pegawai' => $label];
    }

    /**
     * Nama dengan gelar: "glr_awal nama glr_akhir" (bagian kosong dilewati).
     *
     * @param array<string, mixed> $r
     */
    public static function namaLengkap(array $r): string
    {
        return trim(implode(' ', array_filter([(string) ($r['glr_awal'] ?? ''), (string) ($r['nama'] ?? ''), (string) ($r['glr_akhir'] ?? '')], static fn (string $s): bool => $s !== '')));
    }
}
