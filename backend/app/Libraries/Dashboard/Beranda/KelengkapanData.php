<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Integrasi\Stat\PenyajiStat;
use CodeIgniter\Database\BaseConnection;
use DateTime;
use Throwable;

/**
 * Kartu "kelengkapan data" beranda pegawai — port `L_user::dashboard_pns` blok KELENGKAPAN DATA (`L_user.php:644-701`) dan
 * `L_employee::kd_r*` (`L_employee.php:9497-10770`) [K]; view `dashboard_pns.php:104-119` (kartu hanya bila persen < 100).
 * Keputusan Horii 10-11-2026 (Q-D13..Q-D17, semua A) — rincian README `Libraries/Dashboard`:
 * - hanya role 2 dengan `pegawai.id_jenis_pegawai = 1` [K]; PPPK (role 7) tanpa kartu (Q-D16); pengecualian NIP awalan
 *   `123456789` legacy TIDAK dibawa (Q-D17);
 * - Pelatihan (`kd_rdiklat`) tidak pernah tampil: legacy mulai dari 100 sehingga selalu ≥ 100 (Q-D13);
 * - persen tanpa batas atas (KGB/Tanda Jasa bisa > 100 → tidak tampil, Q-D14);
 * - Pangkat = `kd_rpangkat_2` (rentang golongan + penyesuaian ijazah); "terisi" = riwayat KP v2 status ≠ 2/10 yang punya
 *   lampiran `document_attachment` kode KP (11) dengan berkas ADA di storage v2 (mekanisme lampiran s_stat MAKE-061, Q-D15);
 *   jalur legacy yang tak berujung / TypeError → 0 (Q-D15).
 */
final class KelengkapanData
{
    /**
     * Urutan & label kartu = `L_user.php:647-700` [K] (tanpa Pelatihan, Q-D13). `kode` dipetakan FE ke tab Detail Pegawai.
     *
     * @var list<array{kode: string, label: string}>
     */
    public const KARTU = [
        ['kode' => 'data-umum', 'label' => 'Data Umum'],
        ['kode' => 'alamat', 'label' => 'Data Alamat'],
        ['kode' => 'keluarga', 'label' => 'Data Keluarga'],
        ['kode' => 'pendidikan', 'label' => 'Data Pendidikan'],
        ['kode' => 'kp', 'label' => 'Data Pangkat'],
        ['kode' => 'jabatan', 'label' => 'Data Jabatan'],
        ['kode' => 'kgb', 'label' => 'Data KGB'],
        ['kode' => 'tanda-jasa', 'label' => 'Data Tanda Jasa'],
    ];

    /** Kolom `kd_rumum` [K L_employee:9500]. */
    private const KOLOM_UMUM = ['nama', 'tgl_lahir', 'id_provinsi_lahir', 'id_kabupaten_kota_lahir', 'id_agama', 'jenis_kelamin', 'npwp', 'no_hp', 'no_telp_kerabat', 'status_pernikahan', 'foto'];

    private const GOL = ['I/a' => 1, 'I/b' => 2, 'I/c' => 3, 'I/d' => 4, 'II/a' => 5, 'II/b' => 6, 'II/c' => 7, 'II/d' => 8, 'III/a' => 9, 'III/b' => 10, 'III/c' => 11, 'III/d' => 12, 'IV/a' => 13, 'IV/b' => 14, 'IV/c' => 15, 'IV/d' => 16, 'IV/e' => 17];

    private const JP_CPNS = ['I/a' => 'SD', 'I/c' => 'SLTP', 'II/a' => 'SLTA', 'II/c' => 'D.III', 'III/a' => 'S.1', 'III/b' => 'S.2'];

    private const JP_NILAI = ['SD' => 1, 'SLTP' => 2, 'SLTA' => 3, 'D.I' => 4, 'D.II' => 5, 'D.III' => 6, 'D.IV' => 7, 'S.1' => 8, 'S.2' => 9, 'S.3' => 10];

    private const JP_ATURAN = [
        'SD'    => ['SD'],
        'SLTP'  => ['SD', 'SLTP'],
        'SLTA'  => ['SD', 'SLTP', 'SLTA'],
        'D.I'   => ['SD', 'SLTP', 'SLTA', 'D.I'],
        'D.II'  => ['SD', 'SLTP', 'SLTA', 'D.II'],
        'D.III' => ['SD', 'SLTP', 'SLTA', 'D.III'],
        'D.IV'  => ['SD', 'SLTP', 'SLTA', 'D.IV'],
        'S.1'   => ['SD', 'SLTP', 'SLTA', 'S.1'],
        'S.2'   => ['SD', 'SLTP', 'SLTA', 'S.1', 'S.2'],
        'S.3'   => ['SD', 'SLTP', 'SLTA', 'S.1', 'S.2', 'S.3'],
    ];

    private const JENIS_KP_CPNS = '1';

    private const JENIS_KP_PNS = '2';

    private const JENIS_KP_IJAZAH = '5';

    private const ID_TJ_XXX = ProyeksiTandaJasa::ID_XXX;

    private const ID_TJ_XX = ProyeksiTandaJasa::ID_XX;

    private const ID_TJ_X = ProyeksiTandaJasa::ID_X;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly string $hariIni,
        private readonly ?StorageAdapterInterface $storage = null,
    ) {
    }

    /**
     * Kartu yang persennya < 100, urut {@see self::KARTU}.
     *
     * @return list<array{kode: string, label: string, persen: int}>
     */
    public function kartu(int $role, string $nip): array
    {
        if ($role !== Role::PEGAWAI) {
            return [];
        }

        $peg = $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();

        if (! is_array($peg) || (string) ($peg['id_jenis_pegawai'] ?? '') !== '1') {
            return [];
        }

        $persen = [
            'data-umum'  => $this->umum($peg),
            'alamat'     => $this->ada('pegawai_alamat', $nip) ? 100 : 0,
            'keluarga'   => $this->keluarga($peg, $nip),
            'pendidikan' => $this->pendidikan($nip),
            'kp'         => $this->pangkat($nip),
            'jabatan'    => $this->ada('pegawai_mutasi_jabatan', $nip) ? 100 : 0,
            'kgb'        => $this->kgb($nip),
            'tanda-jasa' => $this->tandaJasa($nip),
        ];

        $hasil = [];

        foreach (self::KARTU as $k) {
            $p = $persen[$k['kode']];

            if ($p !== null && $p < 100) {
                $hasil[] = ['kode' => $k['kode'], 'label' => $k['label'], 'persen' => (int) $p];
            }
        }

        return $hasil;
    }

    /**
     * `kd_rumum` [K]: tiap kolom terisi (≠ NULL dan ≠ '') menambah 100/11.
     *
     * @param array<string, mixed> $peg
     */
    private function umum(array $peg): int|float
    {
        $persen = 0;
        $kolom  = 100 / count(self::KOLOM_UMUM);

        foreach (self::KOLOM_UMUM as $k) {
            if (self::terisi($peg[$k] ?? null)) {
                $persen += $kolom;
            }
        }

        return self::bulat($persen);
    }

    /**
     * `kd_rkeluarga` [K]: menikah (`status_pernikahan` = 1) tanpa snapshot keluarga → 0, selain itu 100.
     *
     * @param array<string, mixed> $peg
     */
    private function keluarga(array $peg, string $nip): int
    {
        return (string) ($peg['status_pernikahan'] ?? '') === '1' && ! $this->ada('pegawai_keluarga', $nip) ? 0 : 100;
    }

    /**
     * `kd_rpendidikan` [K]: jenjang tertinggi = maks(jenjang awal dari golongan CPNS [bawaan S.1], jenjang snapshot pendidikan);
     * tiap jenjang wajib pada aturan jenjang itu yang punya riwayat pendidikan status ≠ 2/10 menambah 100/n (S.1 juga
     * menerima D.IV).
     */
    private function pendidikan(string $nip): int|float
    {
        $idJenjang = [];

        foreach ($this->db->table('jenjang_pendidikan')->where('status', 1)->orderBy('order', 'ASC')->get()->getResultArray() as $r) {
            $idJenjang[(string) $r['jenjang_pendidikan_singkat']] = (string) $r['id_jenjang_pendidikan'];
        }

        $jpAkhir = 'S.1';
        $cpns    = $this->baris('pegawai_cpns', $nip);

        if ($cpns !== null) {
            $golRuang = ($cpns['gol'] ?? '') . '/' . ($cpns['ruang'] ?? '');

            if (isset(self::JP_CPNS[$golRuang])) {
                $jpAkhir = self::JP_CPNS[$golRuang];
            }
        }

        $nilaiAkhir = self::JP_NILAI[$jpAkhir];
        $pend       = $this->baris('pegawai_pendidikan', $nip);

        if ($pend !== null) {
            $jp    = (string) ($pend['jenjang_pendidikan_singkat'] ?? '');
            $nilai = self::JP_NILAI[$jp] ?? null; // [K] jenjang di luar daftar → null (tidak pernah lebih tinggi)

            if ($nilai !== null && $nilai > $nilaiAkhir) {
                $jpAkhir = $jp;
            }
        }

        $daftar = self::JP_ATURAN[$jpAkhir];
        $kolom  = 100 / count($daftar);
        $persen = 0;

        foreach ($daftar as $jp) {
            $id = [$idJenjang[$jp] ?? ''];

            if ($jp === 'S.1') {
                $id[] = $idJenjang['D.IV'] ?? '';
            }

            $id = array_values(array_filter($id, static fn (string $v): bool => $v !== ''));

            if ($id !== [] && $this->db->table('riwayat_pendidikan')->where('nip', $nip)->whereIn('id_jenjang_pendidikan', $id)
                ->whereNotIn('status', [2, 10])->countAllResults() > 0) {
                $persen += $kolom;
            }
        }

        return self::bulat($persen);
    }

    /**
     * `kd_rpangkat_2` [K] (Q-D15 A). Riwayat KP status ≠ 2/10 urut `tmtsk` ASC: golongan awal = baris Pengangkatan CPNS/PNS
     * terakhir (bawaan III/a tanpa cek CPNS), golongan akhir = baris terakhir; rentang awal..akhir dikurangi golongan yang
     * "dilompati" penyesuaian ijazah (jenis 5, lompatan per 4 tahun dari baris sebelumnya). Kartu = (rentang [+ CPNS] −
     * belum lengkap) / total; "lengkap" = ada riwayat dengan `gol_ruang` itu (CPNS: "CPNS <gol>") yang punya lampiran v2.
     */
    private function pangkat(string $nip): int|float
    {
        $rows = $this->db->table('riwayat_kp')
            ->select('id_riwayat_kp, nip, id_jenis_kp, gol, ruang, gol_ruang, tmtsk')
            ->where('nip', $nip)->whereNotIn('status', [2, 10])
            ->orderBy('tmtsk', 'ASC')->orderBy('id_riwayat_kp', 'ASC')
            ->get()->getResultArray();

        $berlampiran = $this->entriBerlampiranKp($nip);

        try {
            return self::persenPangkat($rows, $berlampiran);
        } catch (JalurLegacyBuntu) {
            return 0;
        }
    }

    /**
     * Inti {@see self::pangkat()} tanpa DB (diuji unit). Pointer array PHP legacy (`key/next/prev` atas `$list_rwyKP`)
     * dimodelkan eksplisit: posisi 0..n, n = di luar akhir; menambah kunci baru saat di luar akhir membuat pointer menunjuk
     * kunci baru itu (perilaku PHP 8). `while(key() !== gol)` yang tidak pernah menemukan golongan → {@see JalurLegacyBuntu}
     * (legacy berputar tanpa akhir); `prev()` = false lalu `count(false)` → {@see JalurLegacyBuntu} (legacy TypeError).
     *
     * @param list<array<string, mixed>> $rows        riwayat_kp urut tmtsk ASC
     * @param array<int|string, true>        $berlampiran id_riwayat_kp yang punya lampiran v2
     *
     * @throws JalurLegacyBuntu
     */
    public static function persenPangkat(array $rows, array $berlampiran): int|float
    {
        $isCpns     = false;
        $golAwal    = '';
        $nilaiAwal  = 0;
        $nilaiAkhir = 0;

        /** @var list<string> $kunci */
        $kunci = [];
        /** @var array<string, array<string, mixed>> $daftar */
        $daftar  = [];
        $pointer = 0;
        $ijazah  = [];
        $n       = count($rows);

        foreach ($rows as $i => $row) {
            $golRuang         = trim(($row['gol'] ?? '') . '/' . ($row['ruang'] ?? ''));
            $nilai            = self::GOL[$golRuang] ?? 0;
            $key              = (string) ($row['gol_ruang'] ?? '');
            $row['_lampiran'] = isset($berlampiran[(string) $row['id_riwayat_kp']]);

            if (! array_key_exists($key, $daftar)) {
                $kunci[] = $key;
            }
            $daftar[$key] = $row;

            $jenis = (string) ($row['id_jenis_kp'] ?? '');

            if ($jenis === self::JENIS_KP_CPNS || $jenis === self::JENIS_KP_PNS) {
                // [K] cabang PNS memeriksa `$listKP_cpns` yang tidak pernah diisi → selalu menimpa.
                $golAwal   = $golRuang;
                $nilaiAwal = $nilai;
            } elseif ($jenis === self::JENIS_KP_IJAZAH) {
                while (($kunci[$pointer] ?? null) !== $golRuang) {
                    if ($pointer >= count($kunci)) {
                        throw new JalurLegacyBuntu();
                    }
                    $pointer++;
                }

                if ($pointer > 0) {
                    $pointer--;
                    $row['prev'] = $daftar[$kunci[$pointer]];
                } else {
                    $pointer     = count($kunci);
                    $row['prev'] = false;
                }

                $ijazah[$golRuang] = $row;
            }

            if ($i === $n - 1) {
                $nilaiAkhir = $nilai;
                $isCpns     = $jenis === self::JENIS_KP_CPNS;
            }
        }

        $cekCpns = true;

        if ($golAwal === '') {
            $cekCpns   = false;
            $nilaiAwal = self::GOL['III/a'];
        }

        $flip    = array_flip(self::GOL);
        $rentang = [];

        if ($nilaiAwal > 0 && $nilaiAkhir > 0 && $nilaiAkhir >= $nilaiAwal) {
            for ($i = $nilaiAwal; $i <= $nilaiAkhir; $i++) {
                $rentang[$i] = $flip[$i];
            }
        }

        foreach ($ijazah as $row) {
            if ($row['prev'] === false) {
                throw new JalurLegacyBuntu(); // [K] count(false) → TypeError di PHP 8
            }

            $tmt     = new DateTime((string) $row['tmtsk']);
            $akhir   = self::GOL[trim(($row['gol'] ?? '') . '/' . ($row['ruang'] ?? ''))] ?? null;
            $prev    = $row['prev'];
            $lompat  = self::GOL[trim(($prev['gol'] ?? '') . '/' . ($prev['ruang'] ?? ''))] ?? null;
            $tmtPrev = new DateTime((string) $prev['tmtsk']);
            $tmtPrev->modify('+4 year');
            $lompat = (int) $lompat; // [K] null++ = 1 di PHP; (int) null = 0 lalu ++ setara

            while ($tmtPrev < $tmt) {
                $lompat++;
                $tmtPrev->modify('+4 year');
            }
            $lompat++;

            if ($akhir !== null && $lompat < $akhir) {
                for ($j = $lompat; $j < $akhir; $j++) {
                    unset($rentang[$j]);
                }
            }
        }

        if ($rentang === []) {
            return 0;
        }

        $total  = $isCpns ? count($rentang) : ($cekCpns ? count($rentang) + 1 : count($rentang));
        $belum  = 0;
        $urutan = 0;

        foreach ($rentang as $gr) {
            if ($urutan === 0 && $cekCpns) {
                $r = $daftar['CPNS ' . $gr] ?? null;

                if ($r === null || ! $r['_lampiran']) {
                    $belum++;
                }
            }

            if (! $isCpns) {
                $r = $daftar[$gr] ?? null;

                if ($r === null || ! $r['_lampiran']) {
                    $belum++;
                }
            }
            $urutan++;
        }

        return self::bulat((($total - $belum) / $total) * 100);
    }

    /**
     * `kd_rkgb` [K] (tanpa batas atas, Q-D14): dari TMT CPNS (snapshot, atau tahun-bulan dari NIP) maju 1 tahun bila tahun
     * berikutnya punya KGB, selain itu 2 tahun, sampai hari ini; entri terakhir dibuang. Persen = tahun KGB unik / jumlah
     * jadwal × 100. TMT CPNS tak terbaca → kartu tidak dihitung ([I]: legacy galat fatal).
     */
    private function kgb(string $nip): int|float|null
    {
        $mulai = $this->tmtCpns($nip);

        if ($mulai === null) {
            return null;
        }

        $tahun = [];

        foreach ($this->db->table('riwayat_kgb')->select('tmtsk')->where('nip', $nip)->whereNotIn('status', [2, 10])->orderBy('tmtsk', 'ASC')->get()->getResultArray() as $r) {
            if ((string) ($r['tmtsk'] ?? '') !== '') {
                $th         = (new DateTime((string) $r['tmtsk']))->format('Y');
                $tahun[$th] = $th;
            }
        }

        $sekarang = $this->sekarang();
        $jadwal   = [];

        while ($mulai < $sekarang) {
            $berikut = (string) ((int) $mulai->format('Y') + 1);
            $mulai->modify(isset($tahun[$berikut]) ? '+1 year' : '+2 year');
            $jadwal[] = $mulai->format('Y-m-d');
        }

        if ($jadwal !== []) {
            array_pop($jadwal);
        }

        if ($jadwal === []) {
            return 100;
        }

        return self::bulat((count($tahun) / count($jadwal)) * 100);
    }

    /**
     * `kd_rtj` [K] (tanpa batas atas, Q-D14): masa kerja (tahun) sejak TMT CPNS; ≥ 30 → jumlah riwayat XXX/XX/X × 100/3;
     * 20–29 → XX/X × 100/2; 10–19 → X × 100; < 10 → 100. Baris dihitung apa adanya (duplikat ikut, [K]).
     */
    private function tandaJasa(string $nip): int|float|null
    {
        $mulai = $this->tmtCpns($nip);

        if ($mulai === null) {
            return null;
        }

        $th = $this->sekarang()->diff($mulai)->y;

        [$id, $kolom] = match (true) {
            $th >= 30 => [[self::ID_TJ_XXX, self::ID_TJ_XX, self::ID_TJ_X], 100 / 3],
            $th >= 20 => [[self::ID_TJ_XX, self::ID_TJ_X], 100 / 2],
            $th >= 10 => [[self::ID_TJ_X], 100],
            default   => [[], 0],
        };

        if ($id === []) {
            return 100;
        }

        $jumlah = $this->db->table('riwayat_tanda_jasa')->where('nip', $nip)->whereIn('id_tanda_jasa', $id)->whereNotIn('status', [2, 10])->countAllResults();

        return self::bulat($kolom * $jumlah);
    }

    /**
     * TMT CPNS legacy (`kd_rkgb`/`kd_rtj`): `pegawai_cpns.tmtsk`, atau "YYYY-MM-01" dari NIP posisi 9–14.
     */
    private function tmtCpns(string $nip): ?DateTime
    {
        $cpns = $this->baris('pegawai_cpns', $nip);
        $teks = $cpns !== null ? (string) ($cpns['tmtsk'] ?? '') : substr($nip, 8, 4) . '-' . substr($nip, 12, 2) . '-01';

        try {
            return new DateTime($teks);
        } catch (Throwable) {
            return null;
        }
    }

    /** Legacy `new DateTime()` (jam berjalan): tanggal ≤ hari ini dianggap sudah lewat. */
    private function sekarang(): DateTime
    {
        return new DateTime($this->hariIni . ' 23:59:59');
    }

    /**
     * `id_riwayat_kp` yang punya lampiran `document_attachment` kode KP dengan berkas ada di storage v2 (Q-D15, pola s_stat).
     *
     * @return array<int|string, true>
     */
    private function entriBerlampiranKp(string $nip): array
    {
        $storage = $this->storage ?? service('storageAdapter');
        $hasil   = [];

        $rows = $this->db->table('document_attachment')->select('id_entri, path')
            ->where('id_riwayat', PenyajiStat::JENIS_LAMPIRAN['kp'])->where('NIP', $nip)
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $id   = (string) ($r['id_entri'] ?? '');
            $path = (string) ($r['path'] ?? '');

            if ($id === '' || isset($hasil[$id]) || $path === '') {
                continue;
            }

            try {
                if ($storage->ada($path)) {
                    $hasil[$id] = true;
                }
            } catch (Throwable) {
                // path ditolak storage → dianggap tidak ada (pola StatService::adaDiStorage)
            }
        }

        return $hasil;
    }

    private function ada(string $tabel, string $nip): bool
    {
        return $this->db->table($tabel)->where('nip', $nip)->countAllResults() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(string $tabel, string $nip): ?array
    {
        $r = $this->db->table($tabel)->where('nip', $nip)->get()->getRowArray();

        return is_array($r) ? $r : null;
    }

    private static function terisi(mixed $v): bool
    {
        return $v !== null && (string) $v !== '';
    }

    /** Legacy `is_float($persen) ? round($persen) : $persen`. */
    private static function bulat(int|float $v): int|float
    {
        return is_float($v) ? round($v) : $v;
    }
}
