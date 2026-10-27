<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Dokumen;

use CodeIgniter\Database\BaseBuilder;

/**
 * Urutan dokumen daftar F-09 (PRD Fase 7 F7-17/F7-18) — kalkulasi murni + penerapan ORDER BY.
 *
 * DUK = urutan inti [V2]: golongan (`pangkat.order` DESC, tanpa pangkat terakhir) → TMT pangkat ASC → masa kerja (TMT
 * CPNS ASC) → usia (`tgl_lahir` ASC) → NIP (penstabil). Legacy hanya `gol_ruang DESC` (string) + `tmtsk_pangkat ASC`
 * (`L_document.php:14-16`, `array_multisort` tidak stabil). Kriteria lain (eselon/jabatan, TMT jabatan, diklat,
 * pendidikan) menunggu kutipan sumber resmi (PRD §13 Q-1).
 * Nominatif = `unit.order, satker.order, kelas_jabatan DESC` [K `:87-90`]; Baperjakat = `kelas_jabatan DESC,
 * pangkat.order DESC, tmtsk_pangkat ASC, id_satker ASC` [K `L_employee.php:5061-5065`] + NIP penstabil.
 */
final class UrutanDuk
{
    private function __construct()
    {
    }

    /**
     * Pembanding DUK untuk dua baris ber-key `pangkat_order`, `tmtsk_pangkat`, `tmtsk_cpns`, `tgl_lahir`, `nip`
     * (null = kosong). Negatif bila $a di atas $b.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function bandingkan(array $a, array $b): int
    {
        $oa = self::int($a['pangkat_order'] ?? null);
        $ob = self::int($b['pangkat_order'] ?? null);

        if ($oa !== $ob) {
            // Tanpa pangkat (null) selalu di bawah; pangkat lebih tinggi (order besar) di atas.
            if ($oa === null || $ob === null) {
                return $oa === null ? 1 : -1;
            }

            return $ob <=> $oa;
        }

        foreach (['tmtsk_pangkat', 'tmtsk_cpns', 'tgl_lahir'] as $k) {
            $c = self::tanggalAsc((string) ($a[$k] ?? ''), (string) ($b[$k] ?? ''));

            if ($c !== 0) {
                return $c;
            }
        }

        return strcmp((string) ($a['nip'] ?? ''), (string) ($b['nip'] ?? ''));
    }

    /**
     * ORDER BY DUK pada builder beralias `peg`, `pkp`, `pcp`, `pang` (KueriDaftarPegawai).
     */
    public static function terapkanDuk(BaseBuilder $b): BaseBuilder
    {
        return $b->orderBy('pang.order IS NULL', 'ASC', false)
            ->orderBy('pang.order', 'DESC')
            ->orderBy('pkp.tmtsk IS NULL', 'ASC', false)
            ->orderBy('pkp.tmtsk', 'ASC')
            ->orderBy('pcp.tmtsk IS NULL', 'ASC', false)
            ->orderBy('pcp.tmtsk', 'ASC')
            ->orderBy('peg.tgl_lahir', 'ASC')
            ->orderBy('peg.nip', 'ASC');
    }

    /**
     * ORDER BY Nominatif (alias `unt`, `sat`, `jab`, `peg`).
     */
    public static function terapkanNominatif(BaseBuilder $b): BaseBuilder
    {
        return $b->orderBy('unt.order', 'ASC')->orderBy('sat.order', 'ASC')
            ->orderBy('jab.kelas_jabatan IS NULL', 'ASC', false)->orderBy('jab.kelas_jabatan', 'DESC')
            ->orderBy('peg.nip', 'ASC');
    }

    /**
     * ORDER BY Baperjakat (alias `jab`, `pang`, `pkp`, `pmj`, `peg`).
     */
    public static function terapkanBaperjakat(BaseBuilder $b): BaseBuilder
    {
        return $b->orderBy('jab.kelas_jabatan IS NULL', 'ASC', false)->orderBy('jab.kelas_jabatan', 'DESC')
            ->orderBy('pang.order IS NULL', 'ASC', false)->orderBy('pang.order', 'DESC')
            ->orderBy('pkp.tmtsk IS NULL', 'ASC', false)->orderBy('pkp.tmtsk', 'ASC')
            ->orderBy('pmj.id_satker', 'ASC')
            ->orderBy('peg.nip', 'ASC');
    }

    private static function int(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    /**
     * Tanggal lebih awal di atas; kosong di bawah.
     */
    private static function tanggalAsc(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        if ($a === '' || $b === '') {
            return $a === '' ? 1 : -1;
        }

        return strcmp($a, $b);
    }
}
