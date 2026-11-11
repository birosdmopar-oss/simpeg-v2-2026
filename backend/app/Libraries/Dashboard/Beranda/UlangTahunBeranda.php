<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use App\Libraries\Integrasi\Stat\PenyajiStat;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use CodeIgniter\Database\BaseConnection;
use Config\Dashboard;
use DateTimeImmutable;

/**
 * Kartu "Yang Berulangtahun Hari Ini / Besok" beranda semua role (Q7-45 ikut legacy).
 *
 * Pemilihan [K] `helpers/function_helper.php:1419-1475` (`getTodayBday`/`getTomorrowBday`): bulan & hari `pegawai.tgl_lahir` =
 * hari ini / besok (WIB), `pegawai.status = 1`, `pegawai_mutasi_jabatan.id_satker` ∉ `Config\Dashboard::$idSatkerDikecualikanUlangTahun`
 * (legacy `!= '171'`; karena LEFT JOIN + pembanding, pegawai tanpa snapshot jabatan / id_satker NULL ikut tersaring), satu
 * baris per NIP, seluruh kementerian untuk semua role. Urutan [I]: NIP ASC (legacy tanpa ORDER).
 * Pengecualian NIP legacy (1 NIP hardcode + `NIP_TESTER`): Q-D18 (A) → daftar `.env` rahasia
 * `dashboard.nipDikecualikanUlangTahun` diisi operator ({@see self::nipDikecualikan()}); berlaku juga untuk foto (404).
 *
 * Field = yang ditampilkan view (`views/user/dashboard_ad.php:250-298`, sama di view pegawai): foto, nama bergelar,
 * tanggal lahir ("1 April 1970"), "jenis pegawai / NIP", jabatan (+ " (jabatan koordinator)" bila ada), satker. Baris
 * `nip = '1'`: hanya nama, tanggal lahir, dan label jabatan `Config\Dashboard::$labelJabatanNipSatu` (`khusus = true`).
 */
final class UlangTahunBeranda
{
    public const HARI_INI = 'ini';

    public const BESOK = 'besok';

    public function __construct(private readonly BaseConnection $db, private readonly Dashboard $config, private readonly string $hariIni)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function daftar(string $hari): array
    {
        $tanggal = new DateTimeImmutable($this->hariIni);

        if ($hari === self::BESOK) {
            $tanggal = $tanggal->modify('+1 day');
        }

        $b = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.glr_awal, peg.nama, peg.glr_akhir, peg.tgl_lahir, peg.jenis_pegawai, peg.foto, '
                . 'MIN(pmj.satker) AS satker, MIN(pmj.jabatan) AS jabatan, MIN(pmj.jabatan_koord) AS jabatan_koord', false)
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->where('MONTH(peg.tgl_lahir)', (int) $tanggal->format('n'))
            ->where('DAY(peg.tgl_lahir)', (int) $tanggal->format('j'))
            ->where('peg.status', 1)
            ->whereNotIn('pmj.id_satker', $this->config->idSatkerDikecualikanUlangTahun === [] ? [0] : $this->config->idSatkerDikecualikanUlangTahun);

        $nip = $this->nipDikecualikan();

        if ($nip !== []) {
            $b->whereNotIn('peg.nip', $nip);
        }

        $rows  = $b->groupBy(['peg.nip', 'peg.glr_awal', 'peg.nama', 'peg.glr_akhir', 'peg.tgl_lahir', 'peg.jenis_pegawai', 'peg.foto'])->orderBy('peg.nip', 'ASC')->get()->getResultArray();
        $hasil = [];

        foreach ($rows as $r) {
            $nipRow  = (string) $r['nip'];
            $khusus  = $nipRow === '1';
            $jabatan = (string) ($r['jabatan'] ?? '');
            $koord   = (string) ($r['jabatan_koord'] ?? '');

            $hasil[] = [
                'nip'           => $khusus ? null : $nipRow,
                'nama'          => PenyajiStat::namaGelar($r['glr_awal'], $r['nama'], $r['glr_akhir']),
                'tanggal_lahir' => TanggalBisnis::formatIndonesia(substr((string) $r['tgl_lahir'], 0, 10)),
                'jenis_pegawai' => $khusus ? null : (string) ($r['jenis_pegawai'] ?? ''),
                'jabatan'       => $khusus ? $this->config->labelJabatanNipSatu : ($koord !== '' ? "{$jabatan} ({$koord})" : $jabatan),
                'satker'        => $khusus ? null : (string) ($r['satker'] ?? ''),
                'khusus'        => $khusus,
                'ada_foto'      => (string) ($r['foto'] ?? '') !== '',
                'foto_kunci'    => $nipRow,
            ];
        }

        return $hasil;
    }

    /**
     * NIP ada di daftar ulang tahun hari ini ATAU besok (syarat endpoint foto, Q-D19).
     */
    public function adaDiDaftar(string $nip): bool
    {
        foreach ([self::HARI_INI, self::BESOK] as $hari) {
            if (in_array($nip, array_column($this->daftar($hari), 'foto_kunci'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Q-D18 (A): pengecualian NIP legacy `getTodayBday`/`getTomorrowBday` dari `.env` rahasia
     * `dashboard.nipDikecualikanUlangTahun` ({@see Dashboard::daftarNipDikecualikanUlangTahun()}); kosong = tanpa pengecualian.
     *
     * @return list<string>
     */
    private function nipDikecualikan(): array
    {
        return $this->config->daftarNipDikecualikanUlangTahun();
    }
}
