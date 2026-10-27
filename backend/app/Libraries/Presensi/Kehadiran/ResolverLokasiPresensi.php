<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Kehadiran;

use App\Libraries\MasterData\AturanLokasiPresensiHooks;
use App\Libraries\Presensi\Geo\LokasiGeofence;
use CodeIgniter\Database\BaseConnection;
use DateTimeInterface;

/**
 * Resolver aturan lokasi presensi per pegawai per tanggal lokal (PRD 5A §7.5.3) — port dinamis `Lm_lokasi.php:540-735` +
 * `MYC:102-115` [K], pengganti `user_lokasi_presensi` (tidak dibuat di v2). Dihitung per permintaan, tanpa cache/tabel
 * (Q5A-04).
 *
 * Aturan `A` (`dm_user_lokasi_presensi`) berlaku untuk pegawai `P` (status pegawai 1) pada tanggal lokal `D` ⇔:
 *  1. `A.status = 1` [V2 G-03];
 *  2. `A.hari_berlaku` kosong atau memuat hari ISO `D` [V2 G3-FR-13];
 *  3. (a) `target_uns` tidak kosong, `P.id_jenis_pegawai ∈ target_jp`, dan (`'0' ∈ target_uns` atau `P.id_unit ∈ target_uns`
 *     atau `'sat_'.P.id_satker ∈ target_uns`) — unit/satker dari jabatan saat ini [K `:680-690`]; atau (b) `'0' ∉ target_uns`
 *     dan `P.nip ∈ target_peg` [K `:694-703`];
 *  4. `P.nip ∉ excl_peg` [K `:720-728`].
 * Lokasi berlaku = gabungan `target_lp` aturan berlaku (JSON lewat `AturanLokasiPresensiHooks::storedIds`), disaring
 * `lokasi_presensi.status = 1` [K `MYC:102-113`]; koordinat/radius tidak sah diabaikan + `log_message('warning')`.
 */
final class ResolverLokasiPresensi
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{tercakup: bool, lokasi: list<LokasiGeofence>, tidak_sah: int}
     */
    public function untuk(string $nip, DateTimeInterface $tanggalLokal): array
    {
        $pegawai = $this->db->table('pegawai p')
            ->select('p.nip, p.id_jenis_pegawai, pmj.id_unit, pmj.id_satker')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
            ->where('p.nip', $nip)
            ->where('p.status', 1)
            ->get(1)->getRowArray();

        if ($pegawai === null) {
            return ['tercakup' => false, 'lokasi' => [], 'tidak_sah' => 0];
        }

        $hari  = (string) (int) $tanggalLokal->format('N');
        $idLok = [];

        foreach ($this->db->table('dm_user_lokasi_presensi')->where('status', 1)->get()->getResultArray() as $aturan) {
            if ($this->berlaku($aturan, $pegawai, $hari)) {
                array_push($idLok, ...AturanLokasiPresensiHooks::storedIds($aturan['target_lp'] ?? null));
            }
        }

        $idLok = array_values(array_unique(array_filter($idLok, static fn (string $id): bool => ctype_digit($id) && (int) $id > 0)));

        if ($idLok === []) {
            return ['tercakup' => false, 'lokasi' => [], 'tidak_sah' => 0];
        }

        $rows = $this->db->table('lokasi_presensi')
            ->select('id_lokasi_presensi, nama_lokasi, latitude, longitude, radius')
            ->whereIn('id_lokasi_presensi', array_map('intval', $idLok))
            ->where('status', 1)
            ->orderBy('id_lokasi_presensi')
            ->get()->getResultArray();

        $lokasi   = [];
        $tidakSah = 0;

        foreach ($rows as $row) {
            $l = LokasiGeofence::dariBaris($row);

            if ($l === null) {
                $tidakSah++;
                log_message('warning', 'Lokasi presensi {id} diabaikan: koordinat/radius tidak sah.', ['id' => $row['id_lokasi_presensi']]);

                continue;
            }

            $lokasi[] = $l;
        }

        return ['tercakup' => $rows !== [], 'lokasi' => $lokasi, 'tidak_sah' => $tidakSah];
    }

    /**
     * @param array<string, mixed> $aturan
     * @param array<string, mixed> $pegawai
     */
    private function berlaku(array $aturan, array $pegawai, string $hari): bool
    {
        $hariBerlaku = trim((string) ($aturan['hari_berlaku'] ?? ''));

        if ($hariBerlaku !== '' && ! in_array($hari, array_map('trim', explode(',', $hariBerlaku)), true)) {
            return false;
        }

        $nip = (string) $pegawai['nip'];

        if (in_array($nip, AturanLokasiPresensiHooks::storedIds($aturan['excl_peg'] ?? null), true)) {
            return false;
        }

        $uns       = AturanLokasiPresensiHooks::storedIds($aturan['target_uns'] ?? null);
        $seluruh   = in_array('0', $uns, true);
        $jp        = AturanLokasiPresensiHooks::storedIds($aturan['target_jp'] ?? null);
        $idJp      = $pegawai['id_jenis_pegawai'] === null ? null : (string) $pegawai['id_jenis_pegawai'];
        $idUnit    = $pegawai['id_unit'] === null ? null : (string) $pegawai['id_unit'];
        $idSatker  = $pegawai['id_satker'] === null ? null : (string) $pegawai['id_satker'];
        $cocokUnit = $seluruh
            || ($idUnit !== null && in_array($idUnit, $uns, true))
            || ($idSatker !== null && in_array('sat_' . $idSatker, $uns, true));

        if ($uns !== [] && $idJp !== null && in_array($idJp, $jp, true) && $cocokUnit) {
            return true;
        }

        return ! $seluruh && in_array($nip, AturanLokasiPresensiHooks::storedIds($aturan['target_peg'] ?? null), true);
    }
}
