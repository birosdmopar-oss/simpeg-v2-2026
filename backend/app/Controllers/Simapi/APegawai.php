<?php

declare(strict_types=1);

namespace App\Controllers\Simapi;

use App\Controllers\BaseController;
use App\Libraries\Integrasi\Klien\KlienTidakAktif;
use App\Libraries\Integrasi\Klien\ResponsAuthKlien;
use App\Libraries\Integrasi\Klien\TokenSimapi;
use App\Libraries\Integrasi\Simapi\PenyajiPegawaiLama;
use App\Libraries\Integrasi\Simapi\ResponsSimapi;
use CodeIgniter\HTTP\ResponseInterface;
use Config\KlienLayanan;
use RuntimeException;

/**
 * MAKE-076 (Q6-41) — API SIMAPI lama `simapi/a_pegawai/{token,detail,pegawai_update}` [K `controllers/simapi/A_pegawai.php`].
 * Auth lewat filter: `token` → `klienLayanan:simapi,ipwajib` (Basic), lainnya → `simapiToken:ipwajib`; allowlist kosong/IP tak cocok
 * → gagal untuk SEMUA klien, tanpa cek `type` [K] (DBV-031 §3.1). Amplop & pesan persis legacy ({@see ResponsSimapi}).
 *
 * [V2] token: hash + CSPRNG (F6-05), satu tabel dengan `simapi/v1/a_auth/token`; setiap handshake menerbitkan token BARU (legacy
 * mengembalikan token lama yang belum kedaluwarsa — tidak mungkin karena hanya hash yang disimpan) dan token aktif lain milik klien
 * TIDAK dicabut. Log hanya kelas + kode galat (tanpa NIP/password/token/IP).
 */
class APegawai extends BaseController
{
    public function token(): ResponseInterface
    {
        $klien = service('klienSaatIni');
        $sidik = $klien->sidikRahasia();

        if (! $klien->ada() || $sidik === null) {
            return ResponsAuthKlien::gagal(true);
        }

        $cfg = config(KlienLayanan::class);

        try {
            $hasil = (new TokenSimapi(db_connect(), $cfg->tokenPanjang, $cfg->tokenTtlDetik, $cfg->headerMaks))->terbitkan((int) $klien->id(), $sidik);
        } catch (KlienTidakAktif) {
            return ResponsAuthKlien::gagal(true);
        } catch (RuntimeException $e) {
            self::log('token', $e);

            return ResponsSimapi::gagal(ResponsSimapi::PESAN_SERVER);
        }

        return ResponsSimapi::sukses(['token' => $hasil['token']]);
    }

    public function detail(string $nip = ''): ResponseInterface
    {
        // [V2] legacy tanpa argumen = galat PHP; v2 mengikuti pola H-06 ("Invalid paramater" [K ejaan]).
        if ($nip === '') {
            return ResponsSimapi::gagal(ResponsSimapi::PESAN_PARAMETER);
        }

        try {
            $data = (new PenyajiPegawaiLama(db_connect()))->detail($nip);
        } catch (RuntimeException $e) {
            self::log('detail', $e);

            return ResponsSimapi::gagal(ResponsSimapi::PESAN_SERVER);
        }

        return $data === null ? ResponsSimapi::gagal(ResponsSimapi::PESAN_NIP) : ResponsSimapi::sukses($data);
    }

    public function pegawaiUpdate(): ResponseInterface
    {
        try {
            $data = (new PenyajiPegawaiLama(db_connect()))->pegawaiUpdate((int) service('klienSaatIni')->id());
        } catch (RuntimeException $e) {
            self::log('pegawai_update', $e);

            return ResponsSimapi::gagal(ResponsSimapi::PESAN_SERVER);
        }

        return ResponsSimapi::sukses($data);
    }

    private static function log(string $endpoint, RuntimeException $e): void
    {
        // Hanya kelas + kode: pesan DatabaseException bisa memuat SQL/nilai.
        log_message('error', 'SIMAPI a_pegawai/' . $endpoint . ' gagal: ' . (new \ReflectionClass($e))->getShortName() . ' kode ' . $e->getCode() . '.');
    }
}
