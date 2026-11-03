<?php

declare(strict_types=1);

namespace App\Libraries\Lampiran;

use App\Libraries\Kepegawaian\Lampiran\PathLegacy;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Menarik berkas lampiran legacy lewat URL (MAKE-069 K-M4 opsi 1) ke akar storage v2 dengan struktur relatif sama
 * (K-M3 opsi A). URL = alamat dasar (opsi CLI/env operator) + path manifest tervalidasi; tidak ada host/skema dari
 * path (SSRF), redirect tidak diikuti, TLS terverifikasi (transport). Tulis atomik; berkas yang checksum-nya sama
 * dilewati sehingga bisa dilanjutkan/diulang untuk delta. 5xx/galat transport di-retry terbatas, 404 tidak. Respons
 * halaman login (HTML / redirect ke login) dihitung `butuh_login` dan penarikan berhenti setelah ambang.
 *
 * Laporan hanya jumlah per sumber dan per `tabel.kolom` — tanpa nama berkas, URL, atau host.
 */
final class PenarikLampiran
{
    public const HASIL = ['berhasil', 'dilewati', '404', 'galat', 'butuh_login'];

    private const SIMPAN_TIAP = 50;

    /**
     * @var array<string, string> sumber → alamat dasar (berakhiran `/`)
     */
    private readonly array $dasar;

    /**
     * @var Closure(int): void
     */
    private readonly Closure $tidur;

    /**
     * @param array<string, string> $dasar sumber (`arsip`/`aplikasi`) → alamat dasar http(s)
     * @param (Closure(int): void)|null $tidur jeda ms (diganti di test)
     */
    public function __construct(
        private readonly TransportHttp $transport,
        array $dasar,
        private readonly string $akar,
        private readonly int $jedaMs = 200,
        private readonly int $ulang = 2,
        private readonly int $ambangLogin = 5,
        ?Closure $tidur = null,
    ) {
        $bersih = [];

        foreach ($dasar as $sumber => $url) {
            $bersih[$sumber] = self::alamatDasar($url);
        }

        $this->dasar = $bersih;
        $this->tidur = $tidur ?? static function (int $ms): void {
            usleep($ms * 1000);
        };

        if ($jedaMs < 0 || $ulang < 0 || $ambangLogin < 1) {
            throw new InvalidArgumentException('Jeda/ulang/ambang tidak sah.');
        }
    }

    /**
     * Alamat dasar wajib http(s) dengan host, tanpa user-info/query/fragmen; dikembalikan berakhiran `/`.
     */
    public static function alamatDasar(string $url): string
    {
        $u = parse_url(trim($url));

        if (! is_array($u) || ! in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || ($u['host'] ?? '') === ''
            || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])) {
            throw new InvalidArgumentException('Alamat dasar wajib http(s)://host[/path] tanpa kredensial, query, atau fragmen.');
        }

        return rtrim(trim($url), '/') . '/';
    }

    /**
     * URL berkas dari alamat dasar + path manifest; null bila path tidak sah.
     */
    public function url(string $sumber, string $path): ?string
    {
        $normal = PathLegacy::normalisasi($path);

        if ($normal === null || $normal !== $path || ! isset($this->dasar[$sumber])) {
            return null;
        }

        return $this->dasar[$sumber] . implode('/', array_map('rawurlencode', explode('/', $normal)));
    }

    /**
     * @param array{versi: int, tujuan: string, berkas: array<string, array{sumber: string, rujukan: array<string, int>, sha256: string|null, hasil: string|null}>, tidak_sah: array<string, int>} $manifest diperbarui di tempat (sha256 + hasil)
     * @param (Closure(array<string, mixed>): void)|null $simpan dipanggil berkala untuk menulis manifest (resume)
     *
     * @return array{per_sumber: array<string, array<string, int>>, per_kolom: array<string, array<string, int>>, berhenti: bool}
     */
    public function tarik(array &$manifest, ?Closure $simpan = null): array
    {
        $perSumber = [];
        $perKolom  = [];
        $login     = 0;
        $n         = 0;
        $berhenti  = false;

        foreach ($manifest['berkas'] as $path => &$b) {
            $hasil      = $this->satu((string) $path, $b);
            $b['hasil'] = $hasil;

            $perSumber[$b['sumber']][$hasil] = ($perSumber[$b['sumber']][$hasil] ?? 0) + 1;

            foreach (array_keys($b['rujukan']) as $label) {
                $perKolom[$label][$hasil] = ($perKolom[$label][$hasil] ?? 0) + 1;
            }

            if ($hasil === 'butuh_login' && ++$login >= $this->ambangLogin) {
                $berhenti = true;

                break;
            }

            if ($simpan !== null && ++$n % self::SIMPAN_TIAP === 0) {
                $simpan($manifest);
            }
        }

        unset($b);

        if ($simpan !== null) {
            $simpan($manifest);
        }

        return ['per_sumber' => $perSumber, 'per_kolom' => $perKolom, 'berhenti' => $berhenti];
    }

    /**
     * @param array{sumber: string, rujukan: array<string, int>, sha256: string|null, hasil: string|null} $b
     */
    private function satu(string $path, array &$b): string
    {
        $url = $this->url($b['sumber'], $path);

        if ($url === null) {
            return 'galat';
        }

        $lokal = $this->akar . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        if ($b['sha256'] !== null && is_file($lokal) && hash_file('sha256', $lokal) === $b['sha256']) {
            return 'dilewati';
        }

        for ($coba = 0; ; $coba++) {
            if ($this->jedaMs > 0) {
                ($this->tidur)($this->jedaMs);
            }

            $r = $this->transport->ambil($url);

            if ($r['status'] === 0 || $r['status'] >= 500) {
                if ($coba < $this->ulang) {
                    continue;
                }

                return 'galat';
            }

            break;
        }

        if ($r['status'] === 404) {
            return '404';
        }

        if ($r['status'] >= 300 && $r['status'] < 400) {
            return $r['lokasi'] !== null && preg_match('/log[\-_]?in|sign[\-_]?in|auth|sso/i', $r['lokasi']) === 1 ? 'butuh_login' : 'galat';
        }

        if ($r['status'] === 401 || $r['status'] === 403) {
            return 'butuh_login';
        }

        if ($r['status'] !== 200) {
            return 'galat';
        }

        if (stripos($r['tipe'], 'text/html') !== false && ! preg_match('/\.html?$/i', $path)) {
            return 'butuh_login';
        }

        $sha = hash('sha256', $r['isi']);

        if (is_file($lokal) && hash_file('sha256', $lokal) === $sha) {
            $b['sha256'] = $sha;

            return 'dilewati';
        }

        $this->tulisAtomik($lokal, $r['isi']);
        $b['sha256'] = $sha;

        return 'berhasil';
    }

    private function tulisAtomik(string $lokal, string $isi): void
    {
        $akar = realpath($this->akar);

        if ($akar === false) {
            throw new RuntimeException('Akar storage tidak ada.');
        }

        $folder = dirname($lokal);

        if (! is_dir($folder) && ! @mkdir($folder, 0750, true) && ! is_dir($folder)) {
            throw new RuntimeException('Folder tujuan tidak dapat dibuat.');
        }

        $nyata = realpath($folder);

        if ($nyata === false || ($nyata !== $akar && ! str_starts_with($nyata, $akar . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Folder tujuan keluar dari akar storage.');
        }

        $sementara = @tempnam($folder, '.tarik-');

        if ($sementara === false || @file_put_contents($sementara, $isi) !== strlen($isi) || ! @rename($sementara, $lokal)) {
            if ($sementara !== false) {
                @unlink($sementara);
            }

            throw new RuntimeException('Berkas tidak dapat ditulis ke storage.');
        }

        @chmod($lokal, 0640);
    }
}
