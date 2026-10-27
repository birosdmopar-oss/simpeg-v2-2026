<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\TokenQr;
use CodeIgniter\Encryption\EncrypterInterface;
use Throwable;

/**
 * Token QR verifikasi slip (PRD 5A §7.3.3 langkah 3, F5-6, Q5A-01 opsi a): `random_bytes(32)` → `TokenQr::dariByteAcak`
 * (64 heks). DB hanya menyimpan `hash('sha256', token)` (`qr_token`, riwayat) dan token terenkripsi Encrypter CI4
 * (`qr_token_enkripsi`, VARBINARY) untuk mencetak ulang QR; token mentah tidak pernah dikirim ke klien atau ditulis ke log.
 */
final class TokenSlipService
{
    public function __construct(private readonly ?EncrypterInterface $encrypter = null)
    {
    }

    /**
     * @return array{mentah: string, hash: string, enkripsi: string}
     */
    public function terbit(): array
    {
        $mentah = TokenQr::dariByteAcak(random_bytes(TokenQr::PANJANG_BYTE));

        return ['mentah' => $mentah, 'hash' => self::hash($mentah), 'enkripsi' => $this->encrypter()->encrypt($mentah)];
    }

    public static function hash(string $mentah): string
    {
        return hash('sha256', $mentah);
    }

    /**
     * Token mentah dari kolom terenkripsi; null bila kosong, gagal didekripsi, atau hasilnya bukan token sah.
     */
    public function mentah(?string $enkripsi): ?string
    {
        if ($enkripsi === null || $enkripsi === '') {
            return null;
        }

        try {
            $mentah = $this->encrypter()->decrypt($enkripsi);
        } catch (Throwable) {
            log_message('warning', 'Token QR slip terenkripsi tidak dapat didekripsi; PDF dicetak tanpa QR.');

            return null;
        }

        return TokenQr::valid($mentah) ? $mentah : null;
    }

    private function encrypter(): EncrypterInterface
    {
        return $this->encrypter ?? service('encrypter');
    }
}
