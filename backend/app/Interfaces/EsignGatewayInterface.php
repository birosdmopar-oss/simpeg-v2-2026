<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Kontrak gateway e-sign BSrE/BSSN (F0-15, ADR-015).
 * Dipakai Modul C (surat cuti) & Modul B (SK). Implementasi: MockEsignAdapter (Fase 0-5), adapter real BSrE (Fase 6).
 *
 * Seluruh method mengembalikan array dengan minimal:
 *   status_code : string  kode hasil gateway ('200' sukses)
 *   message     : string  pesan gateway (disimpan ke esign_hist.pesan_gateway)
 * Kegagalan jaringan/gateway TIDAK boleh silent — kembalikan status_code non-200 + message, atau lempar exception.
 */
interface EsignGatewayInterface
{
    /**
     * Cek status sertifikat elektronik ASN.
     *
     * @return array{status_code: string, message: string, nip: string, has_certificate: bool, certificate_expires_at: string|null}
     */
    public function checkUser(string $nip): array;

    /**
     * Tanda tangani PDF. signed_pdf berisi biner PDF hasil tanda tangan (null kalau gagal).
     *
     * @param string               $pdfContent isi biner PDF
     * @param array<string, mixed> $options    opsi tambahan (posisi ttd, halaman, dsb.)
     *
     * @return array{status_code: string, message: string, signed_pdf: string|null, signed_at: string|null, signature_id: string|null}
     */
    public function signPdf(string $pdfContent, string $nip, string $passphrase, array $options = []): array;
}
