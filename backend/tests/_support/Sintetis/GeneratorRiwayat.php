<?php

declare(strict_types=1);

namespace Tests\Support\Sintetis;

use CodeIgniter\Database\BaseConnection;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;

/**
 * Generator data sintetis riwayat dalam (CR-168, izin #18.3): `pegawai` → `riwayat_mutasi_jabatan`, `riwayat_kp`,
 * `riwayat_lckh`. Dipakai untuk menyemai DB scratch sebelum EXPLAIN/uji volume. Data SINTETIS: NIP = `9999` + 14 digit
 * nomor urut, nama "Pegawai Sintetis <n>", tanpa data nyata. Deterministik: benih yang sama → isi yang sama.
 *
 * - Kolom FK G-02 (`id_unit`, `id_satker`, `id_jabatan`, …) dibiarkan NULL; nama jabatan/unit hanya teks.
 * - Riwayat urut `tmtsk` naik per NIP; sebagian baris berstatus 0/3/10 (bukan aktif) dan sebagian NIP punya dua baris
 *   aktif ber-`tmtsk` sama (kembar, untuk menguji tie-breaker `pk DESC`).
 * - LKH: hari kerja (Senin–Jumat) mundur dari `tanggalAkhir`; status 1/0/3/2/10 dengan sebaran tetap; `nip_atasan` =
 *   pegawai pertama kelompok `bawahanPerAtasan`.
 * - Penulisan per batch (`insertBatch`), tanpa transaksi sendiri: pemanggil yang menentukan (bingkai uji atau commit).
 *
 * @internal
 */
final class GeneratorRiwayat
{
    public const AWALAN_NIP = '9999';

    private const GOLONGAN = ['II/a', 'II/b', 'II/c', 'II/d', 'III/a', 'III/b', 'III/c', 'III/d', 'IV/a', 'IV/b', 'IV/c', 'IV/d', 'IV/e'];

    /** Sebaran status LKH (persen kumulatif): 1 disetujui, 0 menunggu, 3 ditolak, 2 dibatalkan, 10 dihapus. */
    private const STATUS_LKH = [[84, 1], [92, 0], [96, 3], [98, 2], [100, 10]];

    /** @var array<string, int> */
    private array $jumlah = ['pegawai' => 0, 'riwayat_mutasi_jabatan' => 0, 'riwayat_kp' => 0, 'riwayat_lckh' => 0];

    /** @var array<string, list<array<string, mixed>>> */
    private array $antre = [];

    private int $acak;

    public function __construct(private readonly BaseConnection $db, private readonly int $ukuranBatch = 2000)
    {
        if ($ukuranBatch < 1) {
            throw new RuntimeException('ukuranBatch minimal 1.');
        }
    }

    public static function nip(int $urut): string
    {
        return self::AWALAN_NIP . str_pad((string) $urut, 14, '0', STR_PAD_LEFT);
    }

    /**
     * Menyemai seluruh profil. Mengembalikan jumlah baris per tabel.
     *
     * @return array<string, int>
     */
    public function semai(ProfilSintetis $p): array
    {
        $this->acak   = $p->benih;
        $this->jumlah = array_map(static fn (): int => 0, $this->jumlah);
        $akhir        = new DateTimeImmutable($p->tanggalAkhir);
        $hariKerja    = $this->hariKerja($akhir, $p->lkh * $p->faktorDalam);

        for ($i = 1; $i <= $p->jumlahPegawai; $i++) {
            $this->tambahPegawai($p, $i, $akhir);
        }

        $this->kuras('pegawai');

        for ($i = 1; $i <= $p->jumlahPegawai; $i++) {
            $dalam  = $i <= $p->jumlahDalam;
            $faktor = $dalam ? $p->faktorDalam : 1;
            $nip    = self::nip($i);
            $kembar = $this->acak(1, 100) <= $p->persenKembar;

            $this->tambahMutasi($nip, $i, $this->acak(...$p->mutasi) * $faktor, $kembar, $akhir);
            $this->tambahKp($nip, $this->acak(...$p->kp) * $faktor, $kembar, $akhir);
            $this->tambahLkh($p, $nip, $i, array_slice($hariKerja, 0, $p->lkh * $faktor));
        }

        foreach (array_keys($this->antre) as $tabel) {
            $this->kuras($tabel);
        }

        return $this->jumlah;
    }

    private function tambahPegawai(ProfilSintetis $p, int $i, DateTimeImmutable $akhir): void
    {
        $keluar = $this->acak(1, 100) <= $p->persenKeluar;
        $tahun  = 1965 + ($i % 35);

        $this->antre('pegawai', [
            'nip'              => self::nip($i),
            'nama'             => 'Pegawai Sintetis ' . $i,
            'tgl_lahir'        => sprintf('%04d-%02d-%02d', $tahun, 1 + ($i % 12), 1 + ($i % 28)),
            'jenis_kelamin'    => 1 + ($i % 2),
            'id_jenis_pegawai' => $p->idJenisPegawai,
            'id_jenis_status'  => $keluar ? null : $p->idJenisStatus,
            'status'           => $keluar ? 2 : 1,
            'tmt_status'       => $keluar ? $akhir->sub(new DateInterval('P' . $this->acak(1, 730) . 'D'))->format('Y-m-d') : null,
            'created_at'       => '2020-01-01 00:00:00',
        ]);
    }

    private function tambahMutasi(string $nip, int $i, int $n, bool $kembar, DateTimeImmutable $akhir): void
    {
        $tmt = $akhir->sub(new DateInterval('P' . ($n * 400 + $this->acak(0, 200)) . 'D'));

        for ($k = 1; $k <= $n; $k++) {
            $tmt    = $tmt->add(new DateInterval('P' . $this->acak(200, 400) . 'D'));
            $jenis  = $this->acak(1, 100);
            $status = $k === $n ? 1 : $this->statusRiwayat();
            $baris  = [
                'nip'           => $nip,
                'jenis_jabatan' => $jenis <= 10 ? 1 : ($jenis <= 30 ? 2 : 3),
                'tmtsk'         => min($tmt->format('Y-m-d'), $akhir->format('Y-m-d')),
                'jabatan'       => 'Jabatan Sintetis ' . (($i * 7 + $k) % 400),
                'kelas_jabatan' => $this->acak(1, 17),
                'status'        => $status,
            ];
            $this->antre('riwayat_mutasi_jabatan', $baris);

            if ($kembar && $k === $n) {
                $this->antre('riwayat_mutasi_jabatan', ['jabatan' => $baris['jabatan'] . ' (kembar)'] + $baris);
            }
        }
    }

    private function tambahKp(string $nip, int $n, bool $kembar, DateTimeImmutable $akhir): void
    {
        $tmt = $akhir->sub(new DateInterval('P' . ($n * 4 + $this->acak(0, 2)) . 'Y'));
        $gol = $this->acak(0, max(0, count(self::GOLONGAN) - $n));

        for ($k = 1; $k <= $n; $k++) {
            $tmt   = $tmt->add(new DateInterval('P4Y'));
            $baris = [
                'nip'       => $nip,
                'tmtsk'     => min($tmt->format('Y-m-d'), $akhir->format('Y-m-d')),
                'gol_ruang' => self::GOLONGAN[min($gol + $k - 1, count(self::GOLONGAN) - 1)],
                'status'    => $k === $n ? 1 : $this->statusRiwayat(),
            ];
            $this->antre('riwayat_kp', $baris);

            if ($kembar && $k === $n) {
                $this->antre('riwayat_kp', $baris);
            }
        }
    }

    /**
     * @param list<string> $hari
     */
    private function tambahLkh(ProfilSintetis $p, string $nip, int $i, array $hari): void
    {
        $kepala = intdiv($i - 1, $p->bawahanPerAtasan) * $p->bawahanPerAtasan + 1;
        $atasan = $kepala === $i ? ($i > $p->bawahanPerAtasan ? self::nip(1) : null) : self::nip($kepala);

        foreach ($hari as $t) {
            $r = $this->acak(1, 100);

            foreach (self::STATUS_LKH as [$batas, $status]) {
                if ($r <= $batas) {
                    break;
                }
            }

            $this->antre('riwayat_lckh', [
                'nip'         => $nip,
                'tgl_laporan' => $t,
                'nama'        => 'Pegawai Sintetis ' . $i,
                'nip_atasan'  => $atasan,
                'nama_atasan' => $atasan === null ? '' : 'Atasan Sintetis',
                'kegiatan'    => 'Kegiatan sintetis ' . ($r % 37) . ': menyusun bahan dan laporan harian unit kerja.',
                'output'      => 'Dokumen ' . ($r % 11),
                'status'      => $status,
                'show_notif'  => $r % 20 === 0 ? 1 : 0,
            ]);
        }
    }

    /** Status riwayat non-terakhir: sebagian besar 1, sisanya 0/3/10 (tidak ikut "terbaru"). */
    private function statusRiwayat(): int
    {
        $r = $this->acak(1, 100);

        return $r <= 85 ? 1 : ($r <= 92 ? 0 : ($r <= 96 ? 3 : 10));
    }

    /**
     * Hari kerja (Senin–Jumat) mundur dari `$akhir`, terbaru dulu.
     *
     * @return list<string>
     */
    private function hariKerja(DateTimeImmutable $akhir, int $n): array
    {
        $hasil = [];
        $t     = $akhir;

        while (count($hasil) < $n) {
            if ((int) $t->format('N') <= 5) {
                $hasil[] = $t->format('Y-m-d');
            }

            $t = $t->sub(new DateInterval('P1D'));
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function antre(string $tabel, array $baris): void
    {
        $this->antre[$tabel][] = $baris;

        if (count($this->antre[$tabel]) >= $this->ukuranBatch) {
            $this->kuras($tabel);
        }
    }

    private function kuras(string $tabel): void
    {
        $baris = $this->antre[$tabel] ?? [];

        if ($baris === []) {
            return;
        }

        if ($this->db->table($tabel)->insertBatch($baris) === false) {
            throw new RuntimeException("Gagal menulis {$tabel} sintetis.");
        }

        $this->jumlah[$tabel] += count($baris);
        $this->antre[$tabel] = [];
    }

    /** LCG deterministik (tanpa mt_rand global, supaya tidak terpengaruh/mempengaruhi kode lain). */
    private function acak(int $min, int $max): int
    {
        $this->acak = ($this->acak * 1103515245 + 12345) & 0x7FFFFFFF;

        return $min + ($this->acak % ($max - $min + 1));
    }
}
