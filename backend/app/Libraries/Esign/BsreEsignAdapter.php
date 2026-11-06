<?php

declare(strict_types=1);

namespace App\Libraries\Esign;

use App\Interfaces\EsignGatewayInterface;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\OpsiTransport;
use App\Libraries\Integrasi\Http\Permintaan;
use App\Libraries\Integrasi\Http\Redaktor;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\KonfigurasiIntegrasi;
use Closure;

/**
 * Adapter BSrE real (PRD Fase 6 §7.2, H-02, MAKE-065) — implementasi {@see EsignGatewayInterface} tanpa mengubah kontrak.
 * Bentuk ikut legacy `libraries/Esign.php` + `libraries/bsre_esign/bsre_esign.php` (F6-17):
 *
 * - Auth `Authorization: Basic base64(username:password)` (`integrations.esign.username`/`password` = client id/secret).
 * - `checkUser`: `GET {baseUrl}/api/user/status/{nik}` — NIK penanda tangan dari `pegawai.nik` (F6-17); `status_code`
 *   gateway `1111` = bersertifikat → `status_code` '200'. NIK kosong → '404' tanpa panggilan keluar.
 * - `signPdf`: `POST {baseUrl}/api/sign/pdf?nik=…&passphrase=…&tampilan=…` multipart `file` (+ `imageTTD` bila ada) —
 *   `nik` & `passphrase` di query string ikut legacy. Sukses HANYA bila badan respons PDF (`%PDF-`); legacy menulis JSON
 *   non-error sebagai berkas PDF — pengaman F6-17. Galat gateway (JSON `error`/`message`/`status_code`) → `status_code`
 *   kode gateway (atau HTTP), `message` teredaksi. POST tidak di-retry (BP-3).
 * - TLS: ikut legacy atas keputusan Horii 03-11-2026 (kartu Catatan Teknis): `esign.tlsVerify = false` dari `.env` server
 *   mematikan verifikasi peer & host seperti `bsre_esign.php:92-93` — peringatan dicatat sekali per proses dan tampil di
 *   `integrasi:status`/`integrasi:uji-koneksi esign`. Upgrade ke TLS ketat cukup lewat `.env` (`tlsVerify = true`, plus
 *   `caBundle` bila CA internal). Bawaan repo tetap `true`. Pengaman lain tetap berlaku (redaksi, PDF-only, tanpa redirect).
 *   Konfigurasi divalidasi saat dibuat (AC-K.4).
 * - Kegagalan jaringan tidak senyap: `status_code` `timeout`/`koneksi` + pesan teredaksi (kontrak).
 * - NIK, passphrase, dan kredensial tidak pernah masuk log/DB/respons/pesan galat (BP-5, AC-02.3): diteruskan ke
 *   {@see Permintaan::$rahasia} (URL ber-query & path teredaksi oleh `Redaktor`), parameter bertanda
 *   `#[\SensitiveParameter]`, dan pesan gateway dibersihkan dari nilai literalnya.
 */
final class BsreEsignAdapter implements EsignGatewayInterface
{
    public const LAYANAN = 'bsre';

    public const KODE_BERSERTIFIKAT = '1111';

    /** Opsi tampilan yang diteruskan ke query string [K `Esign::sign`, `S_esign.php:74-84`]. */
    public const OPSI_QUERY = ['tampilan', 'width', 'height', 'tag_koordinat', 'reason', 'location', 'halaman', 'page', 'xAxis', 'yAxis', 'linkQR'];

    /** Nilai bawaan legacy surat cuti [K `L_cuti.php`]. */
    public const BAWAAN = [
        'tampilan'      => 'visible',
        'width'         => '50',
        'height'        => '50',
        'tag_koordinat' => '##',
        'reason'        => 'Dokumen ini ditandatangani secara elektronik menggunakan Aplikasi SIMPEG Kemenpar',
        'location'      => 'Jakarta',
    ];

    private readonly string $baseUrl;

    private readonly OpsiTransport $opsi;

    /** @var Closure(string): ?string */
    private readonly Closure $nikDari;

    /** @var Closure(): string */
    private readonly Closure $jam;

    private static bool $sudahDiperingatkan = false;

    /**
     * @param array<string, mixed>          $konfigurasi `Config\Integrations::$esign`
     * @param (Closure(string): ?string)|null $nikDari     NIP → NIK penanda tangan (bawaan `pegawai.nik`)
     * @param (Closure(): string)|null      $jam         stempel `signed_at` (test)
     */
    public function __construct(
        private readonly KlienHttp $klien,
        #[\SensitiveParameter]
        private readonly array $konfigurasi,
        ?Closure $nikDari = null,
        ?Closure $jam = null,
    ) {
        KonfigurasiIntegrasi::pastikan('esign', $konfigurasi, ['baseUrl', 'username', 'password']);
        $this->opsi    = KonfigurasiIntegrasi::opsiTransport('esign', $konfigurasi);
        $this->baseUrl = rtrim(trim((string) $konfigurasi['baseUrl']), '/');
        $this->nikDari = $nikDari ?? static function (#[\SensitiveParameter] string $nip): ?string {
            $nik = db_connect()->table('pegawai')->select('nik')->where('nip', $nip)->get(1)->getRow()?->nik;

            return is_string($nik) && trim($nik) !== '' ? trim($nik) : null;
        };
        $this->jam = $jam ?? static fn (): string => date('Y-m-d H:i:s');

        if (! $this->opsi->verifyTls && ! self::$sudahDiperingatkan) {
            self::$sudahDiperingatkan = true;
            log_message('warning', 'TLS BSrE TIDAK DIVERIFIKASI (integrations.esign.tlsVerify = false, ikut legacy atas keputusan Horii 03-11-2026).');
        }
    }

    public function tlsDiverifikasi(): bool
    {
        return $this->opsi->verifyTls;
    }

    /**
     * Uji koneksi nyata (`integrasi:uji-koneksi esign`, BP-11): satu `GET /api/user/status/{nik-uji}` dengan NIK nol fiktif —
     * berhasil bila gateway menjawab JSON dan kredensial diterima (bukan 401/403). Tidak menandatangani apa pun.
     *
     * @return array{ok: bool, http: string, pesan: string}
     */
    public function ujiKoneksi(): array
    {
        try {
            $respons = $this->klien->kirim(new Permintaan(
                metode: 'GET',
                url: $this->baseUrl . '/api/user/status/0000000000000000',
                layanan: self::LAYANAN,
                operasi: 'uji_koneksi',
                header: $this->header(),
                rahasia: $this->rahasia(),
            ), $this->opsi);
        } catch (GalatTransport $e) {
            return ['ok' => false, 'http' => $e->timeout ? 'timeout' : 'koneksi', 'pesan' => $this->bersih($e->getMessage())];
        }

        $json = $respons->json();
        $ok   = is_array($json) && ! in_array($respons->status, [401, 403], true) && $respons->status < 500;

        return ['ok' => $ok, 'http' => (string) $respons->status, 'pesan' => $this->bersih($this->pesan($json ?? [], $ok ? 'Gateway menjawab.' : 'Gateway menolak/galat.'))];
    }

    public function checkUser(#[\SensitiveParameter] string $nip): array
    {
        $dasar = ['nip' => $nip, 'has_certificate' => false, 'certificate_expires_at' => null];
        $nik   = ($this->nikDari)($nip);

        if ($nik === null) {
            return ['status_code' => '404', 'message' => 'NIK penanda tangan belum terdaftar.'] + $dasar;
        }

        try {
            $respons = $this->klien->kirim(new Permintaan(
                metode: 'GET',
                url: $this->baseUrl . '/api/user/status/' . rawurlencode($nik),
                layanan: self::LAYANAN,
                operasi: 'cek_status',
                header: $this->header(),
                rahasia: $this->rahasia($nik),
            ), $this->opsi);
        } catch (GalatTransport $e) {
            return ['status_code' => $e->timeout ? 'timeout' : 'koneksi', 'message' => $this->bersih($e->getMessage(), $nik)] + $dasar;
        }

        $json = $respons->json() ?? [];
        $kode = $this->teks($json['status_code'] ?? null);

        if ($respons->sukses() && $kode === self::KODE_BERSERTIFIKAT) {
            return ['status_code' => '200', 'message' => $this->bersih($this->pesan($json, 'Sertifikat aktif.'), $nik), 'has_certificate' => true] + $dasar;
        }

        return ['status_code' => $kode ?? (string) $respons->status, 'message' => $this->bersih($this->pesan($json, 'Sertifikat tidak aktif.'), $nik)] + $dasar;
    }

    /**
     * `$nip` bertanda `SensitiveParameter`: pada `services/s_esign` berisi NIK (factory `esignGatewayNikLangsung`, review B4b P2).
     *
     * @param array<string, mixed> $options opsi tampilan {@see self::OPSI_QUERY} + `imageTTD` (biner gambar; `imageTTDMime`/
     *                                      `imageTTDNama` opsional, bawaan PNG) + `image` (override nilai query, s_esign) +
     *                                      `namaBerkas`
     */
    public function signPdf(#[\SensitiveParameter] string $pdfContent, #[\SensitiveParameter] string $nip, #[\SensitiveParameter] string $passphrase, array $options = []): array
    {
        $gagal = ['signed_pdf' => null, 'signed_at' => null, 'signature_id' => null];

        if ($pdfContent === '') {
            return ['status_code' => '400', 'message' => 'Dokumen PDF kosong.'] + $gagal;
        }

        if (trim($passphrase) === '') {
            return ['status_code' => '400', 'message' => 'Passphrase tanda tangan digital tidak boleh kosong.'] + $gagal;
        }

        $nik = ($this->nikDari)($nip);

        if ($nik === null) {
            return ['status_code' => '404', 'message' => 'NIK penanda tangan belum terdaftar.'] + $gagal;
        }

        $gambar = is_string($options['imageTTD'] ?? null) && $options['imageTTD'] !== '' ? $options['imageTTD'] : null;
        $query  = ['nik' => $nik, 'passphrase' => $passphrase];

        foreach (self::OPSI_QUERY as $k) {
            $nilai = $options[$k] ?? self::BAWAAN[$k] ?? null;

            if (is_scalar($nilai) && (string) $nilai !== '') {
                $query[$k] = (string) $nilai;
            }
        }

        // Visualisasi gambar hanya bila imageTTD dikirim [K `Esign::sign` opsi 2/3]; tanpa gambar = `image=false`.
        $query['image'] = $gambar !== null ? 'true' : 'false';

        // `services/s_esign` meneruskan nilai `image` dari pemanggil [K `S_esign.php:79`] (review B4b S2).
        if (is_scalar($options['image'] ?? null) && (string) $options['image'] !== '') {
            $query['image'] = (string) $options['image'];
        }

        $bagian = [['nama' => 'file', 'isi' => $pdfContent, 'namaBerkas' => is_string($options['namaBerkas'] ?? null) ? $options['namaBerkas'] : 'dokumen.pdf']];

        if ($gambar !== null) {
            $mime     = is_string($options['imageTTDMime'] ?? null) && $options['imageTTDMime'] !== '' ? $options['imageTTDMime'] : 'image/png';
            $nama     = is_string($options['imageTTDNama'] ?? null) && $options['imageTTDNama'] !== '' ? $options['imageTTDNama'] : 'ttd.png';
            $bagian[] = ['nama' => 'imageTTD', 'isi' => $gambar, 'namaBerkas' => $nama, 'mime' => $mime];
        }

        try {
            $respons = $this->klien->kirim(new Permintaan(
                metode: 'POST',
                url: $this->baseUrl . '/api/sign/pdf',
                layanan: self::LAYANAN,
                operasi: 'sign_pdf',
                query: $query,
                header: $this->header(),
                multipart: $bagian,
                refId: is_scalar($options['id_rw'] ?? null) ? (string) $options['id_rw'] : null,
                rahasia: $this->rahasia($nik, $passphrase),
            ), $this->opsi);
        } catch (GalatTransport $e) {
            return ['status_code' => $e->timeout ? 'timeout' : 'koneksi', 'message' => $this->bersih($e->getMessage(), $nik, $passphrase)] + $gagal;
        }

        return $this->hasilTandaTangan($respons, $nik, $passphrase);
    }

    /**
     * @return array{status_code: string, message: string, signed_pdf: string|null, signed_at: string|null, signature_id: string|null}
     */
    private function hasilTandaTangan(Respons $respons, #[\SensitiveParameter] string $nik, #[\SensitiveParameter] string $passphrase): array
    {
        if ($respons->sukses() && str_starts_with(ltrim($respons->body), '%PDF-')) {
            $id = $respons->header('id_dokumen');

            return [
                'status_code'  => '200',
                'message'      => 'Dokumen berhasil ditandatangani secara digital.',
                'signed_pdf'   => $respons->body,
                'signed_at'    => ($this->jam)(),
                'signature_id' => is_string($id) && $id !== '' ? mb_substr($id, 0, 100) : null,
            ];
        }

        $json = $respons->json() ?? [];
        $kode = $this->teks($json['status_code'] ?? null);

        return [
            'status_code'  => $kode ?? ($respons->sukses() ? '502' : (string) $respons->status),
            'message'      => $this->bersih($this->pesan($json, $respons->sukses() ? 'Respons gateway bukan PDF.' : 'Penandatanganan gagal.'), $nik, $passphrase),
            'signed_pdf'   => null,
            'signed_at'    => null,
            'signature_id' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function header(): array
    {
        return ['Authorization' => 'Basic ' . $this->basic(), 'cache-control' => 'no-cache', 'accept' => 'application/json'];
    }

    private function basic(): string
    {
        return base64_encode(trim((string) $this->konfigurasi['username']) . ':' . trim((string) $this->konfigurasi['password']));
    }

    /**
     * @return list<string>
     */
    private function rahasia(#[\SensitiveParameter] string ...$nilai): array
    {
        return array_values(array_filter([...$nilai, $this->basic(), trim((string) $this->konfigurasi['password'])], static fn (string $s): bool => $s !== ''));
    }

    /**
     * Pesan gateway untuk pemanggil: diredaksi (tanpa NIK/passphrase literal, kunci sensitif, bentuk NIP) dan dipotong.
     */
    private function bersih(string $teks, #[\SensitiveParameter] string ...$nilai): string
    {
        return mb_substr((new Redaktor())->teks($teks, $this->rahasia(...$nilai)), 0, 255);
    }

    /**
     * @param array<mixed> $json
     */
    private function pesan(array $json, string $bawaan): string
    {
        foreach (['message', 'error', 'msg'] as $k) {
            $t = $this->teks($json[$k] ?? null);

            if ($t !== null) {
                return $t;
            }
        }

        return $bawaan;
    }

    private function teks(mixed $nilai): ?string
    {
        return is_scalar($nilai) && trim((string) $nilai) !== '' ? trim((string) $nilai) : null;
    }
}
