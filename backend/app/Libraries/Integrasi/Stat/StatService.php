<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Stat;

use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * MAKE-061 (H-08) — data `services/s_stat` (pull MuDig): populasi dari tabel v2 lewat query builder, deteksi lewat kelas
 * murni ({@see RekomendasiKp}, {@see RekomendasiKgb}, {@see RekomendasiPensiun}), penyajian lewat {@see PenyajiStat}.
 * Port `controllers/services/S_stat.php` [K]; perbedaan [V2]: auth klien wajib (F6-03, filter), arsip dicek di storage v2
 * (bukan HTTP fetch), URL berkas lewat endpoint unduh klien (Q6-38), KGB diurut `tmtsk_kgb` (F6-09; legacy tidak terurut),
 * dan `file_*` daftar tidak mengeluarkan path internal (P3). Hanya SELECT; tidak menulis tabel/notifikasi.
 *
 * `WHERE … IN (…)` dipecah per {@see self::BATCH} NIP; tabel ber-prefix lewat query builder (prefix-aman).
 */
final class StatService
{
    public const BATCH = 500;

    /** Status `riwayat_tb`/`riwayat_ib` yang dikeluarkan `list_tb`/`list_ib` [K S_stat:1301]. */
    public const STATUS_TB_IB = [0, 1, 3];

    /** Status `riwayat_karpeg`/`riwayat_kariskarsu` yang dikeluarkan [K S_stat:1385, 1431]. */
    public const STATUS_KARTU = [0, 1];

    public const KOLOM_KP = ['nama', 'nip', 'gol_ruang', 'tmtsk_pangkat', 'tgl_kp', 'periode_kp', 'kp_bl', 'pangkat', 'jabatan', 'eselon', 'id_unit', 'id_satker', 'satker', 'strata'];

    public const KOLOM_MUDIG = ['nama', 'nip', 'tgl_lulus', 'jen_pend_sh', 'jen_pen', 'bid_pen', 'id_pangkat_old', 'tmt_kp_old', 'pangkat_old', 'gol_ruang_old', 'mker_th_old', 'mker_bl_old', 'mker_old', 'gaji_pokok_old', 'tmt_jab', 'id_gr_jab', 'gr_jab', 'sub_gr_jab', 'jabatan', 'ak_old', 'id_unit', 'id_satker', 'unit', 'satker', 'ins_induk', 'id_jenis_kp', 'jenis_kp', 'id_pangkat_new', 'tmt_kp_new', 'pangkat_new', 'gol_ruang_new', 'mker_th_new', 'mker_bl_new', 'mker_new', 'gaji_pokok_new', 'lu_ppen', 'order_pangkat_old'];

    public const KOLOM_PENSIUN = ['nama', 'nip', 'tgl_lahir', 'jabatan', 'tmtsk_jabatan', 'satker', 'eselon', 'pensiun_th', 'pensiun_bl', 'tgl_pensiun', 'id_unit', 'id_satker'];

    public const KOLOM_KGB = ['nama', 'nip', 'tmtsk_kgb', 'kgb_th', 'kgb_bl', 'no_sk_last_kgb', 'tmtsk_last_kgb', 'gaji_last_kgb', 'eselon', 'jabatan', 'satker', 'id_unit', 'id_satker', 'gol_ruang', 'id_jenis_pegawai', 'jenis_pegawai'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly StorageAdapterInterface $storage,
        private readonly PenyajiStat $penyaji,
        private readonly string $insInduk = '',
    ) {
    }

    // ------------------------------------------------------------------------------------------------------------------
    // rekomendasi_kp (S_stat:21-250) — format SIMPEG
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function rekomendasiKp(string $hariIni): array
    {
        $tahun  = (int) substr($hariIni, 0, 4);
        $master = [];

        foreach ($this->deteksiKp($hariIni) as $nip => [$row, $det, $m]) {
            $nip = (string) $nip; // kunci array NIP numerik dinormalkan PHP menjadi int

            if ($det['tgl_kp'] === '' || KalenderStat::tahunLegacy($det['tgl_kp']) !== $tahun) {
                continue;
            }

            $r                  = array_fill_keys(self::KOLOM_KP, '');
            $r['nama']          = PenyajiStat::namaGelar($row['glr_awal'], (string) $row['nama'], $row['glr_akhir']);
            $r['nip']           = $nip;
            $r['gol_ruang']     = self::teks($row['gol_ruang'] ?? null);
            $r['tmtsk_pangkat'] = PenyajiStat::tanggalPengguna($row['tmtsk_pangkat'] ?? null);
            $r['tgl_kp']        = PenyajiStat::tanggalPengguna($det['tgl_kp']);
            $r['periode_kp']    = PenyajiStat::periodeKp($det['tgl_kp']);
            $r['kp_bl']         = PenyajiStat::bulanKp($det['tgl_kp']);
            $r['pangkat']       = self::teks($row['pangkat'] ?? null);
            $r['jabatan']       = self::teks($row['jabatan'] ?? null);
            $r['eselon']        = PenyajiStat::eselon($row['id_sub_group_jabatan'] ?? null);
            $r['id_unit']       = self::teks($row['id_unit'] ?? null);
            $r['id_satker']     = self::teks($row['id_satker'] ?? null);
            $r['satker']        = self::teks($row['satker'] ?? null);
            $r['strata']        = self::teks($row['jenjang_pendidikan_singkat'] ?? null);
            $r['id_jenis_kp']   = self::teks($row['id_jenis_kp'] ?? null); // [K] LE:2480 ikut keluar walau di luar arrColumn

            $master[$nip] = $r;
        }

        // Urut gol_ruang DESC, id_satker ASC [K S_stat:46-48] — array_multisort SORT_REGULAR; sort stabil PHP 8.
        $daftar = array_values($master);
        usort($daftar, static fn (array $a, array $b): int => [$b['gol_ruang'], $a['id_satker']] <=> [$a['gol_ruang'], $b['id_satker']]);

        $nips     = array_column($daftar, 'nip');
        $cpns     = $this->snapshotPerNip('pegawai_cpns', $nips);
        $pns      = $this->snapshotPerNip('pegawai_pns', $nips);
        $kp       = $this->snapshotPerNip('pegawai_kp', $nips);
        $skp      = $this->skpPerNip($nips, [$tahun - 1, $tahun - 2], false);
        $arsipKp  = $this->arsipPerEntri($nips, 'kp', self::entriSnapshot([$cpns, $pns, $kp], 'id_riwayat_kp'));
        $arsipSkp = $this->arsipPerEntri($nips, 'skp', self::entriSkp($skp));

        foreach ($daftar as &$r) {
            $nip                = $r['nip'];
            $r['arsip_cpns']    = $this->urlArsip($arsipKp, $nip, $cpns[$nip]['id_riwayat_kp'] ?? null);
            $r['arsip_pns']     = $this->urlArsip($arsipKp, $nip, $pns[$nip]['id_riwayat_kp'] ?? null);
            $r['arsip_last_kp'] = $this->urlArsip($arsipKp, $nip, $kp[$nip]['id_riwayat_kp'] ?? null);
            // [K] S_stat:230: kunci tahun dibuat Y-2 dulu (assignment berantai kanan → kiri), lalu Y-1.
            $r['arsip_skp'] = $this->arsipSkp($arsipSkp, $skp[$nip] ?? [], [$tahun - 2, $tahun - 1]);
        }
        unset($r);

        return $daftar;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // rekomendasi_kp_uns (S_stat:433-736) — format MuDig; Q6-37 ikut legacy
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Dengan `id_satker`/`id_unit` tidak kosong → daftar KOSONG: `[K] bug legacy Q6-37` (S_stat:456-485 memanggil
     * `get_kp_satker`/`get_kp_unit` berformat SIMPEG tanpa `tmt_kp_new`, sehingga semua baris dilewati). Keputusan Horii
     * 05-11-2026 (PRD Q6-37, AC-08.4): direplikasi, bukan diperbaiki.
     *
     * @return list<array<string, mixed>>
     */
    public function rekomendasiKpUns(string $hariIni, string $idUnit, string $idSatker): array
    {
        if ($idUnit !== '' || $idSatker !== '') {
            return []; // [K] bug legacy Q6-37
        }

        $tahun  = (int) substr($hariIni, 0, 4);
        $kelas  = $this->kelasKp($hariIni);
        $daftar = [];

        foreach ($this->deteksiKp($hariIni, $kelas) as $nip => [$row, $det, $m]) {
            $nip = (string) $nip;

            if ($det['tgl_kp'] === '' || KalenderStat::tahunLegacy($det['tgl_kp']) !== $tahun) {
                continue;
            }

            $m['tmt_kp_new']  = $det['tgl_kp'];
            $m['id_jenis_kp'] = $det['id_jenis_kp'];
            $m['jenis_kp']    = $det['jenis_kp'];
            $m                = array_merge($m, $kelas->turunanMudig($m, $det['tgl_kp']));
            $daftar[]         = $m;
        }

        // Urut gol_ruang_old DESC, id_satker ASC [K S_stat:477-479] (P1).
        usort($daftar, static fn (array $a, array $b): int => [$b['gol_ruang_old'], $a['id_satker']] <=> [$a['gol_ruang_old'], $b['id_satker']]);

        $nips     = array_column($daftar, 'nip');
        $cpns     = $this->snapshotPerNip('pegawai_cpns', $nips);
        $pns      = $this->snapshotPerNip('pegawai_pns', $nips);
        $kp       = $this->snapshotPerNip('pegawai_kp', $nips);
        $pmj      = $this->snapshotPerNip('pegawai_mutasi_jabatan', $nips);
        $pak      = $this->snapshotPerNip('pegawai_ak', $nips);
        $skp      = $this->skpPerNip($nips, [$tahun - 1, $tahun - 2], true);
        $arsipKp  = $this->arsipPerEntri($nips, 'kp', self::entriSnapshot([$cpns, $pns, $kp], 'id_riwayat_kp'));
        $arsipSkp = $this->arsipPerEntri($nips, 'skp', self::entriSkp($skp));
        $arsipJab = $this->arsipPerEntri($nips, 'jabatan', self::entriSnapshot([$pmj], 'id_riwayat_mutasi_jabatan'));

        foreach ($daftar as &$r) {
            $nip = $r['nip'];
            // [K] S_stat:638 assignment berantai (kanan → kiri): urutan kunci arsip_last_pak, arsip_last_jab, arsip_skp
            // {Y-2, Y-1}, arsip_last_kp, arsip_pns, arsip_cpns (P3).
            $r['arsip_last_pak'] = $this->urlFilePak($pak[$nip] ?? null);
            $r['arsip_last_jab'] = $this->urlArsip($arsipJab, $nip, $pmj[$nip]['id_riwayat_mutasi_jabatan'] ?? null);
            $r['arsip_skp']      = $this->arsipSkp($arsipSkp, $skp[$nip] ?? [], [$tahun - 2, $tahun - 1]);
            $r['arsip_last_kp']  = $this->urlArsip($arsipKp, $nip, $kp[$nip]['id_riwayat_kp'] ?? null);
            $r['arsip_pns']      = $this->urlArsip($arsipKp, $nip, $pns[$nip]['id_riwayat_kp'] ?? null);
            $r['arsip_cpns']     = $this->urlArsip($arsipKp, $nip, $cpns[$nip]['id_riwayat_kp'] ?? null);

            // [K] S_stat:687-703
            $r['lu_ppak'] = null;

            if (isset($pak[$nip])) {
                $r['lu_ppak'] = (string) ($pak[$nip]['updated_at'] ?? '') !== '' ? $pak[$nip]['updated_at'] : ($pak[$nip]['created_at'] ?? null);
                $r['ak_old']  = $pak[$nip]['nilai_ak'] ?? null;
            }

            $r['lu_pcpns']   = $cpns[$nip]['updated_at'] ?? null;
            $r['lu_ppns']    = $pns[$nip]['updated_at'] ?? null;
            $r['lu_pkp']     = $kp[$nip]['updated_at'] ?? null;
            $r['lu_pmj']     = $pmj[$nip]['updated_at'] ?? null;
            $r['lu_ppen']    = null; // [K] S_stat:722 membaca `lu_ppen` dari baris pegawai_cpns yang tidak punya kolom itu → selalu NULL (P2)
            $r['lu_rskp_y1'] = $skp[$nip][$tahun - 1]['updated_at'] ?? null;
            $r['lu_rskp_y2'] = $skp[$nip][$tahun - 2]['updated_at'] ?? null;
        }
        unset($r);

        return $daftar;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // rekomendasi_pensiun (S_stat:737-935)
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function rekomendasiPensiun(string $hariIni): array
    {
        $tahun = (int) substr($hariIni, 0, 4);
        $kelas = new RekomendasiPensiun($hariIni);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.glr_awal, peg.nama, peg.glr_akhir, peg.tgl_lahir, '
                . 'pmj.jabatan, pmj.tmtsk AS tmtsk_jabatan, pmj.id_group_jabatan, pmj.id_sub_group_jabatan, pmj.id_unit, pmj.id_satker, pmj.satker, '
                . 'jab.umur_pensiun')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'inner') // [K] LE:1346-1372: hanya pegawai ber-snapshot jabatan
            ->join('jabatan jab', 'jab.id_jabatan = pmj.id_jabatan', 'left')
            ->where('peg.status', 1)
            ->where('peg.id_jenis_pegawai', 1)
            ->get()->getResultArray();

        $daftar = [];

        foreach ($rows as $row) {
            $p = $kelas->hitung($row['tgl_lahir'] ?? null, $row['umur_pensiun'] ?? null, $row['id_group_jabatan'] ?? null, $row['id_sub_group_jabatan'] ?? null);

            if ($p['tgl_pensiun'] === '' || KalenderStat::tahunLegacy($p['tgl_pensiun']) !== $tahun) {
                continue;
            }

            [$usiaTh, $usiaBl] = $kelas->usia($row['tgl_lahir'] ?? null);

            $r                  = array_fill_keys(self::KOLOM_PENSIUN, '');
            $r['nama']          = PenyajiStat::namaGelar($row['glr_awal'], (string) $row['nama'], $row['glr_akhir']);
            $r['nip']           = (string) $row['nip'];
            $r['tgl_lahir']     = PenyajiStat::tanggalPengguna($row['tgl_lahir'] ?? null);
            $r['jabatan']       = self::teks($row['jabatan'] ?? null);
            $r['tmtsk_jabatan'] = PenyajiStat::tanggalPengguna($row['tmtsk_jabatan'] ?? null);
            $r['satker']        = self::teks($row['satker'] ?? null);
            $r['eselon']        = PenyajiStat::eselon($row['id_sub_group_jabatan'] ?? null);
            $r['pensiun_th']    = $p['pensiun_th'];
            $r['pensiun_bl']    = $p['pensiun_bl'];
            $r['tgl_pensiun']   = $p['tgl_pensiun'];
            $r['id_unit']       = self::teks($row['id_unit'] ?? null);
            $r['id_satker']     = self::teks($row['id_satker'] ?? null);
            $r['usia_th']       = $usiaTh;
            $r['usia_bl']       = $usiaBl;
            $daftar[]           = $r;
        }

        // Urut tgl_pensiun DESC, id_satker ASC [K S_stat:751-753].
        usort($daftar, static fn (array $a, array $b): int => [$b['tgl_pensiun'], $a['id_satker']] <=> [$a['tgl_pensiun'], $b['id_satker']]);

        $nips     = array_column($daftar, 'nip');
        $cpns     = $this->snapshotPerNip('pegawai_cpns', $nips);
        $pns      = $this->snapshotPerNip('pegawai_pns', $nips);
        $kp       = $this->snapshotPerNip('pegawai_kp', $nips);
        $skp      = $this->skpPerNip($nips, [$tahun - 1], false);
        $arsipKp  = $this->arsipPerEntri($nips, 'kp', self::entriSnapshot([$cpns, $pns, $kp], 'id_riwayat_kp'));
        $arsipSkp = $this->arsipPerEntri($nips, 'skp', self::entriSkp($skp));

        foreach ($daftar as &$r) {
            $nip                = $r['nip'];
            $r['arsip_cpns']    = $this->urlArsip($arsipKp, $nip, $cpns[$nip]['id_riwayat_kp'] ?? null);
            $r['arsip_pns']     = $this->urlArsip($arsipKp, $nip, $pns[$nip]['id_riwayat_kp'] ?? null);
            $r['arsip_last_kp'] = $this->urlArsip($arsipKp, $nip, $kp[$nip]['id_riwayat_kp'] ?? null);
            $r['arsip_skp']     = $this->arsipSkp($arsipSkp, $skp[$nip] ?? [], [$tahun - 1]);
        }
        unset($r);

        return $daftar;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // rekomendasi_kgb (S_stat:936-1101)
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function rekomendasiKgb(string $hariIni): array
    {
        $tahun = (int) substr($hariIni, 0, 4);
        $kelas = new RekomendasiKgb($hariIni);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.glr_awal, peg.nama, peg.glr_akhir, peg.id_jenis_pegawai, peg.jenis_pegawai, '
                . 'pkgb.no_sk AS no_sk_last_kgb, pkgb.tmtsk AS tmtsk_kgb, pkgb.gaji_pokok AS gaji_last_kgb, '
                . 'pcpns.tmtsk AS tmtsk_cpns, pkp.gol_ruang, '
                . 'pmj.jabatan, pmj.id_sub_group_jabatan, pmj.id_unit, pmj.id_satker, pmj.satker, pmj.tmtsk AS tmtsk_pmj, pmj.gol_pppk')
            ->join('pegawai_kgb pkgb', 'pkgb.nip = peg.nip', 'left')
            ->join('pegawai_cpns pcpns', 'pcpns.nip = peg.nip', 'left')
            ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->where('peg.status', 1)
            ->whereIn('peg.id_jenis_pegawai', [1, 6])
            ->get()->getResultArray();

        $daftar = [];

        foreach ($rows as $row) {
            $k = $kelas->hitung($row);

            if ($k['tmtsk_kgb'] === '' || ! in_array(KalenderStat::tahunLegacy($k['tmtsk_kgb']), [$tahun, $tahun + 1], true)) {
                continue;
            }

            $r                     = array_fill_keys(self::KOLOM_KGB, '');
            $r['nama']             = PenyajiStat::namaGelar($row['glr_awal'], (string) $row['nama'], $row['glr_akhir']);
            $r['nip']              = (string) $row['nip'];
            $r['tmtsk_kgb']        = $k['tmtsk_kgb'];
            $r['kgb_th']           = $k['kgb_th'];
            $r['kgb_bl']           = $k['kgb_bl'];
            $r['no_sk_last_kgb']   = $k['no_sk_last_kgb'];
            $r['tmtsk_last_kgb']   = $k['tmtsk_last_kgb'];
            $r['gaji_last_kgb']    = $k['gaji_last_kgb'];
            $r['eselon']           = PenyajiStat::eselon($row['id_sub_group_jabatan'] ?? null);
            $r['jabatan']          = self::teks($row['jabatan'] ?? null);
            $r['satker']           = self::teks($row['satker'] ?? null);
            $r['id_unit']          = self::teks($row['id_unit'] ?? null);
            $r['id_satker']        = self::teks($row['id_satker'] ?? null);
            $r['gol_ruang']        = self::teks((string) $row['id_jenis_pegawai'] === RekomendasiKgb::JENIS_PPPK ? ($row['gol_pppk'] ?? null) : ($row['gol_ruang'] ?? null));
            $r['id_jenis_pegawai'] = self::teks($row['id_jenis_pegawai'] ?? null);
            $r['jenis_pegawai']    = self::teks($row['jenis_pegawai'] ?? null);
            $daftar[]              = $r;
        }

        // [V2] F6-09: urut tmtsk_kgb ASC lalu NIP (legacy array_multisort pada kolom `tgl_pensiun` yang tidak ada → tak terurut).
        usort($daftar, static fn (array $a, array $b): int => [$a['tmtsk_kgb'], $a['nip']] <=> [$b['tmtsk_kgb'], $b['nip']]);

        $nips     = array_column($daftar, 'nip');
        $kgb      = $this->snapshotPerNip('pegawai_kgb', $nips);
        $arsipKgb = $this->arsipPerEntri($nips, 'kgb', self::entriSnapshot([$kgb], 'id_riwayat_kgb'));

        foreach ($daftar as &$r) {
            // [K] S_stat:1085-1088: hanya arsip_last_kgb (arsip KP kode 11 di-fetch legacy tetapi tidak dikeluarkan).
            $r['arsip_last_kgb'] = $this->urlArsip($arsipKgb, $r['nip'], $kgb[$r['nip']]['id_riwayat_kgb'] ?? null);
        }
        unset($r);

        return $daftar;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // list_tb / list_ib / list_karpeg / list_kariskarsu (S_stat:1268-1442)
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function listTb(): array
    {
        return $this->daftarStudi('tb');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIb(): array
    {
        return $this->daftarStudi('ib');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listKarpeg(): array
    {
        return $this->daftarKartu('karpeg', 'r.updated_at DESC, r.id_riwayat_karpeg DESC');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listKariskarsu(): array
    {
        return $this->daftarKartu('kariskarsu', 'r.id_riwayat_kariskarsu DESC');
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Unduh berkas (Q6-38)
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Isi lampiran `document_attachment` untuk klien layanan: jenis dalam daftar putih, milik `$nip`, entrinya memang bisa
     * muncul di keluaran s_stat, dan ada di storage; selain itu `null` (404 seragam).
     *
     * @return array{isi: string, nama: string}|null
     */
    public function berkasLampiran(string $jenis, string $nip, int $idAttachment, string $hariIni): ?array
    {
        $kode = PenyajiStat::JENIS_LAMPIRAN[$jenis] ?? null;

        if ($kode === null || $idAttachment <= 0) {
            return null;
        }

        $row = $this->db->table('document_attachment')
            ->select('id_attachment, id_entri, path')
            ->where('id_attachment', $idAttachment)
            ->where('NIP', $nip)
            ->where('id_riwayat', $kode)
            ->get()->getRowArray();

        if ($row === null || ! $this->entriBolehKeluar($jenis, $nip, (string) ($row['id_entri'] ?? ''), $hariIni)) {
            return null;
        }

        return $this->bacaStorage((string) ($row['path'] ?? ''), $jenis, $idAttachment);
    }

    /**
     * Isi berkas kolom (`pegawai_ak.file_pak`, `riwayat_tb/ib/karpeg/kariskarsu.file_*`): jenis & kolom dalam daftar
     * putih, baris milik `$nip` dengan status yang muncul di keluaran; selain itu `null`.
     *
     * @return array{isi: string, nama: string}|null
     */
    public function berkasKolom(string $jenis, string $nip, int $id, string $kolom): ?array
    {
        $spec = PenyajiStat::JENIS_KOLOM[$jenis] ?? null;

        if ($spec === null || $id <= 0 || ! in_array($kolom, $spec[2], true)) {
            return null;
        }

        [$tabel, $pk] = $spec;
        $builder      = $this->db->table($tabel)->select($kolom)->where($pk, $id)->where('nip', $nip);

        if (in_array($jenis, ['tb', 'ib'], true)) {
            $builder->whereIn('status', self::STATUS_TB_IB);
        } elseif ($jenis !== 'pak') {
            $builder->whereIn('status', self::STATUS_KARTU);
        }

        $row = $builder->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        return $this->bacaStorage((string) ($row[$kolom] ?? ''), $jenis, $id);
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------------------------------------------------------

    private function kelasKp(string $hariIni): RekomendasiKp
    {
        $perOrder = [];

        foreach ($this->db->table('pangkat')->select('id_pangkat, pangkat, gol_ruang, `order`')->where('status', 1)->orderBy('order', 'ASC')->get()->getResultArray() as $p) {
            $perOrder[(int) $p['order']] = ['id_pangkat' => $p['id_pangkat'], 'pangkat' => (string) $p['pangkat'], 'gol_ruang' => (string) $p['gol_ruang']];
        }

        return new RekomendasiKp($hariIni, $perOrder);
    }

    /**
     * Populasi `get_all_kp` (LE:2471-2485) + deteksi per NIP. Hasil: nip => [baris, deteksi, master_mudig].
     *
     * @return array<string, array{0: array<string, mixed>, 1: array{tgl_kp: string, id_jenis_kp: string|null, jenis_kp: string|null}, 2: array<string, mixed>}>
     */
    private function deteksiKp(string $hariIni, ?RekomendasiKp $kelas = null): array
    {
        $kelas ??= $this->kelasKp($hariIni);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.glr_awal, peg.nama, peg.glr_akhir, '
                . 'pkp.id_pangkat, pkp.gol_ruang, pkp.pangkat, pkp.tmtsk AS tmtsk_pangkat, pkp.id_jenis_kp, pkp.mker_th, pkp.mker_bl, pkp.gaji_pokok, '
                . 'pk.`order` AS order_pangkat_old, '
                . 'pmj.tmtsk AS tmtsk_jabatan, pmj.id_group_jabatan, pmj.group_jabatan, pmj.id_sub_group_jabatan, pmj.sub_group_jabatan, pmj.jabatan, pmj.id_unit, pmj.unit, pmj.id_satker, pmj.satker, pmj.kredit_jft, '
                . 'ppen.tgl_lulus, ppen.jenjang_pendidikan_singkat, ppen.bidang_pendidikan, ppen.bidang_pendidikan_lain, ppen.updated_at AS lu_ppen, '
                . 'jpen.jenjang_pendidikan')
            ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
            ->join('pangkat pk', 'pk.id_pangkat = pkp.id_pangkat', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->join('pegawai_pendidikan ppen', 'ppen.nip = peg.nip', 'left')
            ->join('jenjang_pendidikan jpen', 'jpen.id_jenjang_pendidikan = ppen.id_jenjang_pendidikan', 'left')
            ->where('peg.status', 1)
            ->where('peg.id_jenis_pegawai', 1)
            ->orderBy('peg.nip', 'ASC')
            ->get()->getResultArray();

        $struktural = $this->tmtStrukturalTerawal(array_column($rows, 'nip'));
        $hasil      = [];

        foreach ($rows as $row) {
            $nip    = (string) $row['nip'];
            $eselon = PenyajiStat::eselon($row['id_sub_group_jabatan'] ?? null);
            $subId  = RekomendasiKp::SUB_GROUP_ESELON[$eselon] ?? null;
            $tmtStr = $subId === null ? null : ($struktural[$nip][$subId] ?? null);

            $det = $kelas->deteksi([
                'gol_ruang'     => $row['gol_ruang'] ?? '',
                'tmtsk_pangkat' => $row['tmtsk_pangkat'] ?? '',
                'id_jenis_kp'   => $row['id_jenis_kp'] ?? '',
                'strata'        => $row['jenjang_pendidikan_singkat'] ?? '',
                'eselon'        => $eselon,
            ], $tmtStr);

            $m                      = array_fill_keys(self::KOLOM_MUDIG, '');
            $m['nip']               = $nip;
            $m['nama']              = PenyajiStat::namaGelar($row['glr_awal'], (string) $row['nama'], $row['glr_akhir']);
            $m['tgl_lulus']         = self::teks($row['tgl_lulus'] ?? null);
            $m['jen_pend_sh']       = self::teks($row['jenjang_pendidikan_singkat'] ?? null);
            $m['jen_pen']           = self::teks($row['jenjang_pendidikan'] ?? null);
            $m['bid_pen']           = ($row['bidang_pendidikan'] ?? '') === 'LAIN-LAIN' ? self::teks($row['bidang_pendidikan_lain'] ?? null) : self::teks($row['bidang_pendidikan'] ?? null);
            $m['lu_ppen']           = $row['lu_ppen'] ?? null;
            $m['id_pangkat_old']    = self::teks($row['id_pangkat'] ?? null);
            $m['tmt_kp_old']        = self::teks($row['tmtsk_pangkat'] ?? null);
            $m['pangkat_old']       = self::teks($row['pangkat'] ?? null);
            $m['gol_ruang_old']     = self::teks($row['gol_ruang'] ?? null);
            $m['mker_th_old']       = self::teks($row['mker_th'] ?? null);
            $m['mker_bl_old']       = self::teks($row['mker_bl'] ?? null);
            $m['mker_old']          = "{$m['mker_th_old']} Tahun {$m['mker_bl_old']} Bulan";
            $m['gaji_pokok_old']    = self::teks($row['gaji_pokok'] ?? null);
            $m['order_pangkat_old'] = self::teks($row['order_pangkat_old'] ?? null);
            $m['tmt_jab']           = self::teks($row['tmtsk_jabatan'] ?? null);
            $m['id_gr_jab']         = self::teks($row['id_group_jabatan'] ?? null);
            $m['gr_jab']            = self::teks($row['group_jabatan'] ?? null);
            $m['sub_gr_jab']        = self::teks($row['sub_group_jabatan'] ?? null);
            $m['jabatan']           = self::teks($row['jabatan'] ?? null);
            $m['ak_old']            = self::teks($row['kredit_jft'] ?? null);
            $m['id_unit']           = self::teks($row['id_unit'] ?? null);
            $m['id_satker']         = self::teks($row['id_satker'] ?? null);
            $m['unit']              = self::teks($row['unit'] ?? null);
            $m['satker']            = self::teks($row['satker'] ?? null);
            $m['ins_induk']         = $this->insInduk;

            $hasil[$nip] = [$row, $det, $m];
        }

        return $hasil;
    }

    /**
     * TMT jabatan struktural paling awal per (nip, id_sub_group_jabatan) dari `riwayat_mutasi_jabatan` status 1 — padanan
     * `get(... "tmtsk ASC")` legacy (LE:2621): baris pertama urut `tmtsk ASC` (NULL paling awal di MySQL → dianggap kosong,
     * seperti legacy).
     *
     * @param list<string> $nips
     *
     * @return array<string, array<int, string|null>>
     */
    private function tmtStrukturalTerawal(array $nips): array
    {
        $hasil = [];

        foreach (array_chunk(array_values(array_unique($nips)), self::BATCH) as $potongan) {
            $rows = $this->db->table('riwayat_mutasi_jabatan')
                ->select('nip, id_sub_group_jabatan, tmtsk')
                ->whereIn('nip', $potongan)
                ->whereIn('id_sub_group_jabatan', [1, 2, 3, 4])
                ->where('status', 1)
                ->orderBy('nip', 'ASC')->orderBy('id_sub_group_jabatan', 'ASC')->orderBy('tmtsk', 'ASC')
                ->get()->getResultArray();

            foreach ($rows as $r) {
                $nip = (string) $r['nip'];
                $sub = (int) $r['id_sub_group_jabatan'];

                if (! array_key_exists($sub, $hasil[$nip] ?? [])) {
                    $hasil[$nip][$sub] = $r['tmtsk'] === null ? null : (string) $r['tmtsk'];
                }
            }
        }

        return $hasil;
    }

    /**
     * Baris snapshot per NIP (`pegawai_cpns`, `pegawai_pns`, `pegawai_kp`, `pegawai_kgb`, `pegawai_mutasi_jabatan`, `pegawai_ak`).
     *
     * @param list<string> $nips
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshotPerNip(string $tabel, array $nips): array
    {
        $hasil = [];

        foreach (array_chunk(array_values(array_unique($nips)), self::BATCH) as $potongan) {
            foreach ($this->db->table($tabel)->whereIn('nip', $potongan)->get()->getResultArray() as $r) {
                $hasil[(string) $r['nip']] = $r;
            }
        }

        return $hasil;
    }

    /**
     * `riwayat_skp` status 1 per NIP per tahun [K]: format SIMPEG (`rekomendasi_kp`/`pensiun`, S_stat:170/886) hanya
     * `tahun IN (…)`; format MuDig (`rekomendasi_kp_uns`, S_stat:566) ditambah `YEAR(tgl_mulai)=tahun AND MONTH(tgl_mulai)
     * IN (1,2) AND YEAR(tgl_akhir)=tahun AND MONTH(tgl_akhir)=12`. Urut `nip DESC, tahun DESC, tgl_mulai DESC`; per tahun
     * baris terakhir menang (MuDig) / semua baris dipakai (SIMPEG, lihat {@see self::arsipSkp()}).
     *
     * @param list<string> $nips
     * @param list<int>    $tahun
     *
     * @return array<string, array<int|string, mixed>> nip => tahun => baris, ditambah kunci `_semua` (list semua baris)
     */
    private function skpPerNip(array $nips, array $tahun, bool $ketat): array
    {
        $hasil = [];

        foreach (array_chunk(array_values(array_unique($nips)), self::BATCH) as $potongan) {
            $b = $this->db->table('riwayat_skp')
                ->select('id_riwayat_skp, nip, tahun, tgl_mulai, updated_at')
                ->where('status', 1)
                ->whereIn('nip', $potongan)
                ->whereIn('tahun', $tahun);

            if ($ketat) {
                $b->where('YEAR(tgl_mulai) = tahun', null, false)
                    ->where('MONTH(tgl_mulai) IN (1, 2)', null, false)
                    ->where('YEAR(tgl_akhir) = tahun', null, false)
                    ->where('MONTH(tgl_akhir) = 12', null, false);
            }

            foreach ($b->orderBy('nip', 'DESC')->orderBy('tahun', 'DESC')->orderBy('tgl_mulai', 'DESC')->get()->getResultArray() as $r) {
                $nip                     = (string) $r['nip'];
                $th                      = (int) $r['tahun'];
                $hasil[$nip][$th]        = $r;
                $hasil[$nip]['_semua'][] = $r;
            }
        }

        return $hasil;
    }

    /**
     * Arsip `document_attachment` per NIP per `id_entri` untuk satu jenis (kode `jenis_rwy`), hanya yang berkasnya ADA di
     * storage v2 — menggantikan HTTP fetch legacy [V2]. Nilai = URL unduh klien.
     *
     * @param list<string>                     $nips
     * @param array<string, array<string, true>> $entriPerNip nip => id_entri yang dirujuk keluaran (S6: baris lain dilewati
     *                                                      sebelum cek storage)
     *
     * @return array<string, array<string, list<string>>>
     */
    private function arsipPerEntri(array $nips, string $jenis, array $entriPerNip): array
    {
        $hasil = [];
        $kode  = PenyajiStat::JENIS_LAMPIRAN[$jenis];

        foreach (array_chunk(array_values(array_unique($nips)), self::BATCH) as $potongan) {
            $rows = $this->db->table('document_attachment')
                ->select('id_attachment, NIP, id_entri, path')
                ->where('id_riwayat', $kode)
                ->whereIn('NIP', $potongan)
                ->orderBy('id_attachment', 'ASC')
                ->get()->getResultArray();

            foreach ($rows as $r) {
                $path = (string) ($r['path'] ?? '');

                if (! isset($entriPerNip[(string) $r['NIP']][(string) $r['id_entri']]) || $path === '' || ! $this->adaDiStorage($path)) {
                    continue;
                }

                $hasil[(string) $r['NIP']][(string) $r['id_entri']][] = $this->penyaji->urlLampiran($jenis, (string) $r['NIP'], (int) $r['id_attachment']);
            }
        }

        return $hasil;
    }

    /**
     * @param array<string, array<string, list<string>>> $arsip
     *
     * @return list<string>
     */
    private function urlArsip(array $arsip, string $nip, mixed $idEntri): array
    {
        if ($idEntri === null || $idEntri === '') {
            return [];
        }

        return $arsip[$nip][(string) $idEntri] ?? [];
    }

    /**
     * `arsip_skp` = {tahun: [url…]} untuk setiap tahun yang diminta (kunci tahun selalu ada, mungkin kosong) [K].
     *
     * @param array<string, array<string, list<string>>> $arsip
     * @param array<int|string, mixed>                   $skpNip
     * @param list<int>                                  $tahun
     *
     * @return array<int, list<string>>
     */
    private function arsipSkp(array $arsip, array $skpNip, array $tahun): array
    {
        $hasil = [];

        foreach ($tahun as $th) {
            $hasil[$th] = [];
        }

        /** @var list<array<string, mixed>> $baris */
        $baris = $skpNip['_semua'] ?? [];

        foreach ($baris as $r) {
            $th = (int) $r['tahun'];

            foreach ($arsip[(string) $r['nip']][(string) $r['id_riwayat_skp']] ?? [] as $url) {
                $hasil[$th][] = $url;
            }
        }

        return $hasil;
    }

    /**
     * `arsip_last_pak` (Q8): `pegawai_ak.file_pak` lewat storage v2 → URL berkas kolom; kosong bila tidak ada.
     *
     * @param array<string, mixed>|null $pak
     *
     * @return list<string>
     */
    private function urlFilePak(?array $pak): array
    {
        $path = (string) ($pak['file_pak'] ?? '');
        $id   = $pak['id_riwayat_ak'] ?? null;

        // S3: tanpa id_riwayat_ak URL unduh tidak dapat dibentuk → dilewati (bukan URL rusak).
        if ($pak === null || $id === null || $id === '' || $path === '' || ! $this->adaDiStorage($path)) {
            return [];
        }

        return [$this->penyaji->urlBerkas('pak', (string) $pak['nip'], (string) $id, 'file_pak')];
    }

    /**
     * `list_tb`/`list_ib` [K S_stat:1297-1301]: `r.*` + `pmj.id_unit`, `pmj.id_satker`; status (0,1,3); tanpa ORDER BY legacy
     * → [V2] urut PK ASC (deterministik). Kolom `file_*` → URL/kosong (P3).
     *
     * @return list<array<string, mixed>>
     */
    private function daftarStudi(string $jenis): array
    {
        [$tabel, $pk, $kolomBerkas] = PenyajiStat::JENIS_KOLOM[$jenis];

        $rows = $this->db->table("{$tabel} r")
            ->select('r.*, pmj.id_unit, pmj.id_satker')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = r.nip', 'left')
            ->whereIn('r.status', self::STATUS_TB_IB)
            ->orderBy("r.{$pk}", 'ASC')
            ->get()->getResultArray();

        return $this->gantiKolomBerkas($rows, $jenis, $pk, $kolomBerkas, $this->lampiranStudi($jenis, $rows, $pk));
    }

    /**
     * P4 (keputusan reviewer 05-11-2026): berkas TB/IB yang dibuat lewat v2 tersimpan di `document_attachment` kode 46–50
     * (`BerkasStudi`: tag = nama kolom untuk 46/49, tanpa tag untuk 47/50), bukan di kolom `file_*`. Agar MuDig tetap
     * menerima berkas pasca-cutover, kolom `file_*` yang kosong diisi URL lampiran v2 (berkas terbaru per slot yang ada di
     * storage). Hasil: pk => kolom => URL.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, string>>
     */
    private function lampiranStudi(string $jenis, array $rows, string $pk): array
    {
        $sumber = PenyajiStat::SUMBER_LAMPIRAN_STUDI[$jenis] ?? [];
        $hasil  = [];

        if ($sumber === [] || $rows === []) {
            return $hasil;
        }

        $pkPerNip = [];

        foreach ($rows as $r) {
            $pkPerNip[(string) $r['nip']][(string) $r[$pk]] = true;
        }

        $kodeKeJenis = [];

        foreach ($sumber as [$jenisLampiran]) {
            $kodeKeJenis[PenyajiStat::JENIS_LAMPIRAN[$jenisLampiran]] = $jenisLampiran;
        }

        foreach (array_chunk(array_keys($pkPerNip), self::BATCH) as $potongan) {
            $da = $this->db->table('document_attachment')
                ->select('id_attachment, NIP, id_riwayat, id_entri, tag, path')
                ->whereIn('id_riwayat', array_keys($kodeKeJenis))
                ->whereIn('NIP', $potongan)
                ->orderBy('id_attachment', 'DESC')
                ->get()->getResultArray();

            foreach ($da as $r) {
                $nip   = (string) $r['NIP'];
                $entri = (string) $r['id_entri'];

                if (! isset($pkPerNip[$nip][$entri])) {
                    continue;
                }

                $jenisLampiran = $kodeKeJenis[(int) $r['id_riwayat']];
                $tag           = $r['tag'] === null || $r['tag'] === '' ? null : (string) $r['tag'];

                foreach ($sumber as $kolom => [$jenisKolom, $tagKolom]) {
                    if ($jenisKolom !== $jenisLampiran || $tagKolom !== $tag || isset($hasil[$entri][$kolom])) {
                        continue;
                    }

                    $path = (string) ($r['path'] ?? '');

                    if ($path !== '' && $this->adaDiStorage($path)) {
                        $hasil[$entri][$kolom] = $this->penyaji->urlLampiran($jenisLampiran, $nip, (int) $r['id_attachment']);
                    }
                }
            }
        }

        return $hasil;
    }

    /**
     * `list_karpeg`/`list_kariskarsu` [K S_stat:1381-1389, 1427-1435].
     *
     * @return list<array<string, mixed>>
     */
    private function daftarKartu(string $jenis, string $urutan): array
    {
        [$tabel, $pk, $kolomBerkas] = PenyajiStat::JENIS_KOLOM[$jenis];

        $rows = $this->db->table("{$tabel} r")
            ->select('r.*, peg.glr_awal, peg.nama, peg.glr_akhir, peg.jenis_kelamin, '
                . 'pmj.id_sub_group_jabatan, pmj.sub_group_jabatan, pmj.id_unit, pmj.id_satker, pmj.unit, pmj.satker, pmj.jabatan')
            ->join('pegawai peg', 'peg.nip = r.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = r.nip', 'left')
            ->whereIn('r.status', self::STATUS_KARTU)
            ->orderBy($urutan, '', false)
            ->get()->getResultArray();

        return $this->gantiKolomBerkas($rows, $jenis, $pk, $kolomBerkas);
    }

    /**
     * Ganti path internal kolom berkas dengan URL unduh klien (ada di storage) atau '' (P3 [V2]).
     *
     * @param list<array<string, mixed>>           $rows
     * @param list<string>                         $kolomBerkas
     * @param array<string, array<string, string>> $lampiranV2  pk => kolom => URL lampiran v2 (P4), dipakai bila kolom kosong
     *
     * @return list<array<string, mixed>>
     */
    private function gantiKolomBerkas(array $rows, string $jenis, string $pk, array $kolomBerkas, array $lampiranV2 = []): array
    {
        foreach ($rows as &$r) {
            foreach ($kolomBerkas as $kolom) {
                $path = (string) ($r[$kolom] ?? '');

                if ($path !== '' && $this->adaDiStorage($path)) {
                    $r[$kolom] = $this->penyaji->urlBerkas($jenis, (string) $r['nip'], (string) $r[$pk], $kolom);
                } else {
                    $r[$kolom] = $lampiranV2[(string) $r[$pk]][$kolom] ?? '';
                }
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Entri lampiran yang URL-nya bisa muncul di keluaran s_stat (keputusan reviewer 05-11-2026: kepemilikan NIP + jenis +
     * status/tahun, tanpa pembatasan populasi rekomendasi).
     */
    private function entriBolehKeluar(string $jenis, string $nip, string $idEntri, string $hariIni): bool
    {
        if ($idEntri === '') {
            return false;
        }

        $tahun = (int) substr($hariIni, 0, 4);

        return match ($jenis) {
            'kp'      => $this->adaSnapshot(['pegawai_cpns', 'pegawai_pns', 'pegawai_kp'], 'id_riwayat_kp', $nip, $idEntri),
            'kgb'     => $this->adaSnapshot(['pegawai_kgb'], 'id_riwayat_kgb', $nip, $idEntri),
            'jabatan' => $this->adaSnapshot(['pegawai_mutasi_jabatan'], 'id_riwayat_mutasi_jabatan', $nip, $idEntri),
            'skp'     => $this->db->table('riwayat_skp')->where('nip', $nip)->where('id_riwayat_skp', $idEntri)->where('status', 1)
                ->whereIn('tahun', [$tahun - 1, $tahun - 2])->countAllResults() > 0,
            'lampiran_tb', 'surat_tb' => $this->db->table('riwayat_tb')->where('nip', $nip)->where('id_riwayat_tb', $idEntri)
                ->whereIn('status', self::STATUS_TB_IB)->countAllResults() > 0,
            'lampiran_ib', 'surat_ib' => $this->db->table('riwayat_ib')->where('nip', $nip)->where('id_riwayat_ib', $idEntri)
                ->whereIn('status', self::STATUS_TB_IB)->countAllResults() > 0,
            default => false,
        };
    }

    /**
     * Himpunan `id_entri` per NIP dari baris snapshot (S6).
     *
     * @param list<array<string, array<string, mixed>>> $snapshot
     *
     * @return array<string, array<string, true>>
     */
    private static function entriSnapshot(array $snapshot, string $kolom): array
    {
        $hasil = [];

        foreach ($snapshot as $perNip) {
            foreach ($perNip as $nip => $row) {
                $id = $row[$kolom] ?? null;

                if ($id !== null && $id !== '') {
                    $hasil[(string) $nip][(string) $id] = true;
                }
            }
        }

        return $hasil;
    }

    /**
     * @param array<string, array<int|string, mixed>> $skp hasil {@see self::skpPerNip()}
     *
     * @return array<string, array<string, true>>
     */
    private static function entriSkp(array $skp): array
    {
        $hasil = [];

        foreach ($skp as $nip => $perTahun) {
            /** @var list<array<string, mixed>> $baris */
            $baris = $perTahun['_semua'] ?? [];

            foreach ($baris as $r) {
                $hasil[(string) $nip][(string) $r['id_riwayat_skp']] = true;
            }
        }

        return $hasil;
    }

    /**
     * @param list<string> $tabel
     */
    private function adaSnapshot(array $tabel, string $kolom, string $nip, string $idEntri): bool
    {
        foreach ($tabel as $t) {
            if ($this->db->table($t)->where('nip', $nip)->where($kolom, $idEntri)->countAllResults() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{isi: string, nama: string}|null
     */
    private function bacaStorage(string $path, string $jenis, int|string $id): ?array
    {
        if ($path === '' || ! $this->adaDiStorage($path)) {
            return null;
        }

        try {
            $isi = $this->storage->baca($path);
        } catch (Throwable) {
            return null;
        }

        return ['isi' => $isi, 'nama' => PenyajiStat::namaUnduhan($jenis, $id, $path)];
    }

    private function adaDiStorage(string $path): bool
    {
        try {
            return $this->storage->ada($path);
        } catch (Throwable) {
            return false; // path ditolak storage (traversal, dsb.) → dianggap tidak ada
        }
    }

    /**
     * Nilai kolom seperti CI3: NULL tetap `null`, selain itu string (S5).
     */
    private static function teks(mixed $nilai): ?string
    {
        return $nilai === null ? null : (string) $nilai;
    }
}
