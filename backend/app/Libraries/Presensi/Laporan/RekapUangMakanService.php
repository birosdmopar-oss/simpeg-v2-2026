<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\MasterData\HariLiburService;
use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Presensi\Slip\UangMakanSlipPresensi;
use Closure;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Rekap uang makan bulan kalender (D-09b, MAKE-042, PRD 5B §6.2, §7.4.2) — bentuk legacy `rekap_uma` / `rekap_uma_il`
 * (`Presensi.php:845`, `:944`; `L_presensi.php:2694-3212`) [K]: baris `{nip, tgl_absen}` satu per hari dibayar, urut
 * populasi lalu tanggal naik; XLSX dua kolom `NIP | TGL_ABSEN`; **tanpa** nominal, tarif, nama, atau ringkasan golongan
 * (F5B-20).
 *
 * Hari dibayar = **aturan slip** (F5B-2): {@see UangMakanSlipPresensi::hariDibayarBanyak()} (`Rekap\HariUangMakan`), bulan
 * kalender dipotong hari ini, sehingga jumlah baris pegawai X bulan M = `uang_makan_hari` snapshot slip X periode M+1
 * (AC-D09b.4). Gap terhadap `rekap_uma` legacy dicatat di PRD 5B §7.4.2 [CATAT] F5B-2 (kategori 13 dibayar, penanda TB
 * melekat, perpanjangan TB, pegawai baru dihitung).
 *
 * Populasi DH {@see PopulasiRekap::dh()} dalam lingkup {@see CakupanLaporan} (role 1, 3); varian IL
 * {@see PopulasiRekap::il()} hanya role 1 (F5B-6), tanpa lingkup & jenis pegawai [K].
 */
final class RekapUangMakanService
{
    private readonly BaseConnection $db;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeInterface)|null $jam instan sekarang (bawaan jam server); disuntik test
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?Closure $jam = null,
        private readonly ?HariLiburService $hariLibur = null,
        private readonly ?WebConfigService $webConfig = null,
    ) {
        $this->db  = $db ?? db_connect();
        $this->jam = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * `GET presensi/uang-makan/rekap` (atau `rekap-il` bila `$il`).
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query, bool $il = false): array
    {
        [$p, $lingkup, $baris] = $this->baris($auth, $query, $il);

        return [
            'items'    => array_slice($baris, ($p->page - 1) * $p->perPage, $p->perPage),
            'total'    => count($baris),
            'page'     => $p->page,
            'per_page' => $p->perPage,
            'bulan'    => $p->kunci(),
            'lingkup'  => $lingkup->toArray(),
        ];
    }

    /**
     * `GET presensi/uang-makan/rekap/xlsx` (atau `rekap-il/xlsx`).
     *
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function xlsx(AuthContext $auth, array $query, bool $il = false): array
    {
        [$p, $lingkup, $baris] = $this->baris($auth, $query, $il);

        return [
            'isi'  => EksporRekapXlsx::uangMakan($baris),
            'nama' => sprintf('rekap_uang_makan_%s_%s.xlsx', $il ? 'il' : $lingkup->kode(), $p->kunci()),
            'mime' => EksporRekapXlsx::MIME,
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: ParameterRekap, 1: LingkupLaporan, 2: list<array{nip: string, tgl_absen: string}>}
     */
    private function baris(AuthContext $auth, array $query, bool $il): array
    {
        $p = ParameterRekap::dari($query, TanggalBisnis::hariIni(($this->jam)()), $this->db, ! $il);

        if ($il) {
            $lingkup  = new LingkupLaporan(LingkupLaporan::KEMENTERIAN, null, 'Instansi Lain');
            $populasi = (new PopulasiRekap($this->db))->il($p->awal);
        } else {
            $lingkup  = (new CakupanLaporan($this->db, $this->webConfig))->lingkup($auth, $p->unitSatker);
            $populasi = (new PopulasiRekap($this->db))->dh($p->awal, $p->akhir, $lingkup, $p->jenisPegawai);
        }

        $nip   = array_map(static fn (array $peg): string => (string) $peg['nip'], $populasi);
        $hari  = (new UangMakanSlipPresensi($this->db, null, $this->hariLibur, $this->jam))->hariDibayarBanyak($nip, $p->tahun, $p->bulan);
        $baris = [];

        foreach ($nip as $n) {
            foreach ($hari[$n] ?? [] as $t) {
                $baris[] = ['nip' => $n, 'tgl_absen' => $t];
            }
        }

        return [$p, $lingkup, $baris];
    }
}
