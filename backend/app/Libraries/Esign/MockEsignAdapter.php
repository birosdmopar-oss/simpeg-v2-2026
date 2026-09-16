<?php

declare(strict_types=1);

namespace App\Libraries\Esign;

use App\Interfaces\EsignGatewayInterface;

/**
 * Mock adapter BSrE (F0-15). Tidak pernah melakukan koneksi jaringan.
 * Mengembalikan "signed document" dummy: PDF asli + trailer penanda mock.
 */
class MockEsignAdapter implements EsignGatewayInterface
{
    public const MOCK_TRAILER = "\n%%SIMPEG-MOCK-ESIGN-SIGNATURE\n";

    /**
     * NIP yang dianggap tidak punya sertifikat — untuk uji jalur gagal di consumer.
     *
     * @var list<string>
     */
    private array $withoutCertificate;

    /**
     * @param list<string> $withoutCertificate
     */
    public function __construct(array $withoutCertificate = [])
    {
        $this->withoutCertificate = $withoutCertificate;
    }

    public function checkUser(string $nip): array
    {
        $has = ! in_array($nip, $this->withoutCertificate, true);

        log_message('info', '[MockEsignAdapter] checkUser nip={nip} has_certificate={has}', ['nip' => $nip, 'has' => $has ? 'true' : 'false']);

        return [
            'status_code'            => $has ? '200' : '404',
            'message'                => $has ? 'Sertifikat aktif (mock)' : 'Sertifikat tidak ditemukan (mock)',
            'nip'                    => $nip,
            'has_certificate'        => $has,
            'certificate_expires_at' => $has ? date('Y-m-d', strtotime('+1 year')) : null,
        ];
    }

    public function signPdf(string $pdfContent, string $nip, string $passphrase, array $options = []): array
    {
        if ($pdfContent === '') {
            return [
                'status_code'  => '400',
                'message'      => 'Dokumen PDF kosong (mock)',
                'signed_pdf'   => null,
                'signed_at'    => null,
                'signature_id' => null,
            ];
        }

        if ($passphrase === '') {
            return [
                'status_code'  => '401',
                'message'      => 'Passphrase salah (mock)',
                'signed_pdf'   => null,
                'signed_at'    => null,
                'signature_id' => null,
            ];
        }

        log_message('info', '[MockEsignAdapter] signPdf nip={nip} bytes={bytes}', ['nip' => $nip, 'bytes' => strlen($pdfContent)]);

        return [
            'status_code'  => '200',
            'message'      => 'Dokumen berhasil ditandatangani (mock)',
            'signed_pdf'   => $pdfContent . self::MOCK_TRAILER,
            'signed_at'    => date('Y-m-d H:i:s'),
            'signature_id' => 'mock-' . substr(hash('sha256', $nip . $pdfContent), 0, 16),
        ];
    }
}
