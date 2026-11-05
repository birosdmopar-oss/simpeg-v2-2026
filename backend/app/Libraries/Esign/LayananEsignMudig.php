<?php

declare(strict_types=1);

namespace App\Libraries\Esign;

use App\Interfaces\EsignGatewayInterface;
use App\Models\Layanan\EsignHistModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Logika `services/s_esign` untuk MuDig (H-02, F6-16 / Q6-05 = A, MAKE-065 B4b) — port `controllers/services/S_esign.php`.
 *
 * Ikut legacy [K]: nama & bawaan parameter (`nik`, `passphrase`, `tampilan`, `width`, `height`, `tag_koordinat`, `reason`,
 * `location`), pesan "Invalid parameter." / "Berhasil menandatangani dokumen secara digital.", status 1 hanya bila gateway
 * menyatakan bersertifikat (`check_user`) atau mengembalikan PDF (`sign_pdf`), `ip_address`/`user_agent` dari parameter POST.
 *
 * Deviasi [V2] (F6-16): auth klien layanan (filter `klienLayanan:esign`, bukan konstanta `MUDIG_*`), berkas & gambar tanda
 * tangan sebagai unggahan multipart (bukan path server), `esign_hist` WAJIB tercatat untuk setiap percobaan tanda tangan
 * (`nama_lengkap` & `modul` wajib), PDF ber-TTE dikembalikan base64 di `data.signed_file` (legacy: path berkas server).
 *
 * NIK & passphrase: hanya diteruskan ke gateway (NIK disimpan di `esign_hist.nik` [K], PII) — tidak pernah masuk respons,
 * log, atau pesan galat; parameter bertanda `#[\SensitiveParameter]`.
 */
final class LayananEsignMudig
{
    public const MODUL_MAKS = 50;

    public const PESAN_PARAMETER = 'Invalid parameter.';

    public const PESAN_SUKSES = 'Berhasil menandatangani dokumen secara digital.';

    /** Galat tak terduga (exception adapter/DB) — rincian hanya di log teredaksi. */
    public const PESAN_GALAT = 'Penandatanganan gagal diproses.';

    /** `esign_hist.status_code` untuk exception adapter (bukan kode gateway). */
    public const KODE_GALAT = 'galat';

    /** Bawaan legacy `S_esign.php:78-84` [K] (teks `reason` milik s_esign, berbeda dari bawaan surat cuti). */
    public const BAWAAN = [
        'tampilan'      => 'visible',
        'width'         => '50',
        'height'        => '50',
        'tag_koordinat' => '##',
        'reason'        => 'Dokumen ini ditandatangani secara elektronik menggunakan Aplikasi SIMPEG KEMENPAR',
        'location'      => 'Jakarta',
    ];

    public function __construct(private readonly EsignGatewayInterface $gateway, private readonly BaseConnection $db)
    {
    }

    /**
     * `check_user?nik=` [K `S_esign.php:9-52`].
     *
     * @return array{status: int, msg: string|null, data: array<never>}
     */
    public function cekPengguna(#[\SensitiveParameter] string $nik): array
    {
        if (trim($nik) === '') {
            return ['status' => 2, 'msg' => self::PESAN_PARAMETER, 'data' => []];
        }

        $hasil = $this->gateway->checkUser(trim($nik));

        // [K] bug legacy: `S_esign.php:41` membaca `$resp['message']`, padahal `Esign::checkStatus` mengembalikan kunci
        // `msg` — `msg` legacy selalu null (usulan B4b no. 2, menunggu putusan reviewer).
        return ['status' => $hasil['status_code'] === '200' ? 1 : 2, 'msg' => null, 'data' => []];
    }

    /**
     * `sign_pdf` [K `S_esign.php:54-167`, V2 F6-16]. `$param` = field POST (tanpa berkas); `$pdf`/`$gambar` = isi unggahan
     * yang sudah divalidasi controller (null = tidak ada/tidak sah).
     *
     * @param array<string, mixed> $param
     *
     * @return array{status: int, msg: string, data: array<string, string>}
     */
    public function tandaTangani(#[\SensitiveParameter] array $param, ?string $pdf, ?string $namaBerkas, ?string $gambar): array
    {
        $nik        = self::teks($param['nik'] ?? null);
        $passphrase = is_string($param['passphrase'] ?? null) ? $param['passphrase'] : '';
        $nama       = self::teks($param['nama_lengkap'] ?? null);
        $modul      = self::teks($param['modul'] ?? null);

        if ($nik === null || $passphrase === '' || $pdf === null || $gambar === null || $nama === null || $modul === null
            || mb_strlen($modul) > self::MODUL_MAKS || mb_strlen($nik) > 20) {
            return ['status' => 2, 'msg' => self::PESAN_PARAMETER, 'data' => []];
        }

        $opsi = ['imageTTD' => $gambar, 'namaBerkas' => $namaBerkas ?? 'dokumen.pdf'];

        foreach (self::BAWAAN as $k => $bawaan) {
            $opsi[$k] = self::teks($param[$k] ?? null) ?? $bawaan;
        }

        $idRw = self::teks($param['id_rw'] ?? null);
        $idRw = $idRw !== null && ctype_digit($idRw) && strlen($idRw) <= 9 ? (int) $idRw : null;

        if ($idRw !== null) {
            $opsi['id_rw'] = $idRw;
        }

        try {
            $hasil = $this->gateway->signPdf($pdf, $nik, $passphrase, $opsi);
        } catch (\Throwable $e) {
            log_message('error', '[s_esign] adapter e-sign melempar {kelas}.', ['kelas' => $e::class]);
            $hasil = ['status_code' => self::KODE_GALAT, 'message' => self::PESAN_GALAT, 'signed_pdf' => null];
        }

        $sukses = $hasil['status_code'] === '200' && is_string($hasil['signed_pdf']) && $hasil['signed_pdf'] !== '';
        $pesan  = $sukses ? self::PESAN_SUKSES : (trim($hasil['message']) !== '' ? $hasil['message'] : self::PESAN_GALAT);

        $this->catat($param, $nik, $nama, $modul, $idRw, $namaBerkas, $sukses, $hasil['status_code'], $sukses ? null : $pesan);

        if (! $sukses) {
            return ['status' => 2, 'msg' => $pesan, 'data' => []];
        }

        return ['status' => 1, 'msg' => self::PESAN_SUKSES, 'data' => ['signed_file' => base64_encode((string) $hasil['signed_pdf'])]];
    }

    /**
     * Satu baris `esign_hist` per percobaan (F6-16 wajib) [K kolom `S_esign.php:115-126`]. NIP yang tidak dikenal `pegawai`
     * disimpan NULL (FK RESTRICT; percobaan tetap tercatat).
     *
     * @param array<string, mixed> $param
     */
    private function catat(#[\SensitiveParameter] array $param, #[\SensitiveParameter] string $nik, string $nama, string $modul, ?int $idRw, ?string $namaBerkas, bool $sukses, string $kode, ?string $keterangan): void
    {
        $nip = self::teks($param['nip'] ?? null);

        if ($nip !== null && $this->db->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            $nip = null;
        }

        $sumber = self::teks($param['file_pdf_to_sign'] ?? null) ?? $namaBerkas ?? '-';
        $ip     = self::teks($param['ip_address'] ?? null);
        $ua     = self::teks($param['user_agent'] ?? null);

        (new EsignHistModel($this->db))->insert([
            'nip'              => $nip,
            'nik'              => $nik,
            'nama_lengkap'     => mb_substr($nama, 0, 256),
            'modul'            => $modul,
            'id_rw'            => $idRw,
            'file_pdf_to_sign' => mb_substr($sumber, 0, 256),
            'status'           => $sukses ? EsignHistModel::STATUS_BERHASIL : EsignHistModel::STATUS_GAGAL,
            'status_code'      => $kode !== '' ? mb_substr($kode, 0, 10) : null,
            'ip_address'       => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'user_agent'       => $ua !== null ? mb_substr($ua, 0, 255) : null,
            'keterangan'       => $keterangan !== null ? mb_substr($keterangan, 0, 255) : null,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private static function teks(mixed $nilai): ?string
    {
        return is_scalar($nilai) && trim((string) $nilai) !== '' ? trim((string) $nilai) : null;
    }
}
