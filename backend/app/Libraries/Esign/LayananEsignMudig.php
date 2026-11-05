<?php

declare(strict_types=1);

namespace App\Libraries\Esign;

use App\Interfaces\EsignGatewayInterface;
use App\Models\Layanan\EsignHistModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * Logika `services/s_esign` untuk MuDig (H-02, F6-16 / Q6-05 = A, MAKE-065 B4b) — port `controllers/services/S_esign.php`.
 *
 * Ikut legacy [K]: `status` STRING `"1"`/`"2"` (`S_esign.php:12,57`; putusan reviewer B4b U1 — beda dengan `s_stat`/`s_dm`
 * yang int), parameter wajib hanya `nik`, `passphrase`, `file`, `imageTTD`, nama & bawaan parameter lain (`tampilan`, `width`,
 * `height`, `tag_koordinat`, `reason`, `location`), pesan "Invalid parameter." / "Berhasil menandatangani dokumen secara
 * digital.", status 1 hanya bila gateway menyatakan bersertifikat (`check_user`) atau mengembalikan PDF (`sign_pdf`),
 * `ip_address`/`user_agent` dari parameter POST.
 *
 * Deviasi [V2] (F6-16): auth klien layanan (filter `klienLayanan:esign`, bukan konstanta `MUDIG_*`), berkas & gambar tanda
 * tangan sebagai unggahan multipart (bukan path server), `esign_hist` SELALU tercatat untuk setiap percobaan yang sampai ke
 * gateway tanpa menambah parameter wajib (legacy hanya mencatat bila `nama_lengkap`, `modul`, `file_pdf_to_sign` dikirim;
 * isian kosong diisi bawaan, lihat {@see self::catat()}), PDF ber-TTE dikembalikan base64 di `data.signed_file` (legacy:
 * path berkas server).
 *
 * NIK & passphrase: hanya diteruskan ke gateway (NIK disimpan di `esign_hist.nik` [K], PII) — tidak pernah masuk respons,
 * log, atau pesan galat; parameter bertanda `#[\SensitiveParameter]`. NIK harus {@see self::POLA_NIK} (cegah segmen path
 * `.`/`..` di URL gateway, review B4b S3).
 *
 * Pengecualian "esign_hist wajib" (review B4b P1b): bila pencatatan gagal SESUDAH gateway mengembalikan PDF ber-TTE, hasil
 * sukses + PDF tetap dikembalikan (menolak sesudah dokumen ditandatangani memicu MuDig mengulang = tanda tangan ganda);
 * kegagalan dicatat `log_message('error')` tanpa PII.
 *
 * Keterbatasan (review B4b S4): tidak ada pembatas percobaan passphrase per penanda tangan (legacy juga tidak punya); yang
 * berlaku throttle per klien filter A3.
 */
final class LayananEsignMudig
{
    public const MODUL_MAKS = 50;

    /** `esign_hist.modul` bila pemanggil tidak mengirim `modul` [V2] (bawaan kolom 'Cuti Online' tidak tepat untuk s_esign). */
    public const MODUL_BAWAAN = 'MuDig';

    public const PESAN_PARAMETER = 'Invalid parameter.';

    public const PESAN_SUKSES = 'Berhasil menandatangani dokumen secara digital.';

    /** Galat tak terduga (exception adapter/DB) — rincian hanya di log teredaksi. */
    public const PESAN_GALAT = 'Penandatanganan gagal diproses.';

    /** `esign_hist.status_code` untuk exception adapter (bukan kode gateway). */
    public const KODE_GALAT = 'galat';

    /** NIK yang diteruskan ke gateway (review B4b S3). */
    public const POLA_NIK = '/^[0-9A-Za-z]{1,32}$/';

    /** Kolom TINYTEXT `esign_hist` dibatasi BYTE (review B4b P1a): dipotong `mb_strcut`, bukan `mb_substr`. */
    public const TINYTEXT_BYTE = 255;

    /** `image` bawaan legacy `S_esign.php:79` (`true`, lewat `http_build_query` menjadi "1"). */
    public const IMAGE_BAWAAN = '1';

    /** Bawaan legacy `S_esign.php:78-84` [K] (teks `reason` milik s_esign, berbeda dari bawaan surat cuti). */
    public const BAWAAN = [
        'tampilan'      => 'visible',
        'width'         => '50',
        'height'        => '50',
        'tag_koordinat' => '##',
        'reason'        => 'Dokumen ini ditandatangani secara elektronik menggunakan Aplikasi SIMPEG KEMENPAR',
        'location'      => 'Jakarta',
    ];

    /** @var Closure(array<string, mixed>): void */
    private readonly Closure $simpanHist;

    /**
     * @param (Closure(array<string, mixed>): void)|null $simpanHist penulis baris `esign_hist` (test: galat insert)
     */
    public function __construct(private readonly EsignGatewayInterface $gateway, private readonly BaseConnection $db, ?Closure $simpanHist = null)
    {
        $this->simpanHist = $simpanHist ?? static function (#[\SensitiveParameter] array $baris) use ($db): void {
            if ((new EsignHistModel($db))->insert($baris) === false) {
                throw new RuntimeException('Insert esign_hist gagal.');
            }
        };
    }

    /**
     * `check_user?nik=` [K `S_esign.php:9-52`].
     *
     * @return array{status: string, msg: string|null, data: array<never>}
     */
    public function cekPengguna(#[\SensitiveParameter] string $nik): array
    {
        if (preg_match(self::POLA_NIK, trim($nik)) !== 1) {
            return ['status' => '2', 'msg' => self::PESAN_PARAMETER, 'data' => []];
        }

        $hasil = $this->gateway->checkUser(trim($nik));

        // [K] bug legacy: `S_esign.php:41` membaca `$resp['message']`, padahal `Esign::checkStatus` mengembalikan kunci
        // `msg` — `msg` legacy selalu null (kunci message vs msg; putusan reviewer B4b U2).
        return ['status' => $hasil['status_code'] === '200' ? '1' : '2', 'msg' => null, 'data' => []];
    }

    /**
     * `sign_pdf` [K `S_esign.php:54-167`, V2 F6-16]. `$param` = field POST (tanpa berkas); `$pdf`/`$gambar` = unggahan
     * yang sudah divalidasi controller (null = tidak ada/tidak sah). `$gambar['mime']`/`['nama']` diteruskan ke gateway seperti
     * legacy `mime_content_type` + basename (review B4b S1).
     *
     * @param array<string, mixed>                              $param
     * @param array{isi: string, nama: string, mime: string}|null $gambar
     *
     * @return array{status: string, msg: string, data: array<string, string>}
     */
    public function tandaTangani(#[\SensitiveParameter] array $param, #[\SensitiveParameter] ?string $pdf, ?string $namaBerkas, ?array $gambar): array
    {
        $nik        = self::teks($param['nik'] ?? null);
        $passphrase = is_string($param['passphrase'] ?? null) ? $param['passphrase'] : '';

        // Wajib [K `S_esign.php:85`]: nik, passphrase, file, imageTTD.
        if ($nik === null || $passphrase === '' || $pdf === null || $gambar === null || preg_match(self::POLA_NIK, $nik) !== 1) {
            return ['status' => '2', 'msg' => self::PESAN_PARAMETER, 'data' => []];
        }

        $opsi = [
            'imageTTD'     => $gambar['isi'],
            'imageTTDMime' => $gambar['mime'],
            'imageTTDNama' => $gambar['nama'],
            'namaBerkas'   => $namaBerkas ?? 'dokumen.pdf',
            // `image` [K `S_esign.php:79`]: nilai POST diteruskan apa adanya, bawaan legacy (review B4b S2).
            'image' => self::teks($param['image'] ?? null) ?? self::IMAGE_BAWAAN,
        ];

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

        // Berlapis (review B4b S5): sukses hanya bila hasil benar-benar PDF, walau adapter sudah memeriksa `%PDF-`.
        $sukses = $hasil['status_code'] === '200' && is_string($hasil['signed_pdf']) && str_starts_with(ltrim($hasil['signed_pdf']), '%PDF-');
        $kode   = $hasil['status_code'] === '200' && ! $sukses ? '502' : $hasil['status_code'];
        $pesan  = $sukses ? self::PESAN_SUKSES : ($kode === '502' && $hasil['status_code'] === '200' ? 'Respons gateway bukan PDF.' : (trim($hasil['message']) !== '' ? $hasil['message'] : self::PESAN_GALAT));

        try {
            $this->catat($param, $nik, $idRw, $namaBerkas, $sukses, $kode, $sukses ? null : $pesan);
        } catch (Throwable $e) {
            // P1b: dokumen sudah ber-TTE → jangan ditolak; tanpa PII (kelas & kode saja, bukan pesan/SQL).
            log_message('error', '[s_esign] esign_hist gagal dicatat ({kelas}, kode {kode}, status {status}).', ['kelas' => $e::class, 'kode' => $e->getCode(), 'status' => $sukses ? '1' : '2']);

            if (! $sukses) {
                throw $e;
            }
        }

        if (! $sukses) {
            return ['status' => '2', 'msg' => $pesan, 'data' => []];
        }

        return ['status' => '1', 'msg' => self::PESAN_SUKSES, 'data' => ['signed_file' => base64_encode((string) $hasil['signed_pdf'])]];
    }

    /**
     * Satu baris `esign_hist` per percobaan (F6-16 wajib) [K kolom `S_esign.php:115-126`], tanpa menolak permintaan yang di
     * legacy lolos (putusan reviewer B4b U3):
     *  - `nip` tidak dikenal `pegawai` → NULL (FK RESTRICT; percobaan tetap tercatat);
     *  - `nama_lengkap` kosong → nama bergelar `pegawai` bila NIP dikenal, selain itu '' (kolom NOT NULL);
     *  - `modul` kosong → {@see self::MODUL_BAWAAN}; dipotong {@see self::MODUL_MAKS};
     *  - `file_pdf_to_sign` kosong → nama berkas unggahan; `id_rw` hanya angka (lainnya NULL);
     *  - `ip_address`/`user_agent` dari POST [K], dipotong panjang kolom (VARCHAR = karakter `mb_substr`, TINYTEXT = byte
     *    `mb_strcut`); `status_code` = kode gateway.
     *
     * @param array<string, mixed> $param
     */
    private function catat(#[\SensitiveParameter] array $param, #[\SensitiveParameter] string $nik, ?int $idRw, ?string $namaBerkas, bool $sukses, string $kode, ?string $keterangan): void
    {
        $nip     = self::teks($param['nip'] ?? null);
        $pegawai = $nip !== null ? $this->db->table('pegawai')->select('glr_awal, nama, glr_akhir')->where('nip', $nip)->get(1)->getRowArray() : null;

        if ($pegawai === null) {
            $nip = null;
        }

        $nama  = self::teks($param['nama_lengkap'] ?? null) ?? ($pegawai !== null ? self::namaBergelar($pegawai) : '');
        $modul = self::teks($param['modul'] ?? null) ?? self::MODUL_BAWAAN;

        $sumber = self::teks($param['file_pdf_to_sign'] ?? null) ?? $namaBerkas ?? '-';
        $ip     = self::teks($param['ip_address'] ?? null);
        $ua     = self::teks($param['user_agent'] ?? null);

        ($this->simpanHist)([
            'nip'              => $nip,
            'nik'              => mb_substr($nik, 0, 20),
            'nama_lengkap'     => mb_substr($nama, 0, 256),
            'modul'            => mb_substr($modul, 0, self::MODUL_MAKS),
            'id_rw'            => $idRw,
            'file_pdf_to_sign' => mb_substr($sumber, 0, 256),
            'status'           => $sukses ? EsignHistModel::STATUS_BERHASIL : EsignHistModel::STATUS_GAGAL,
            'status_code'      => $kode !== '' ? mb_substr($kode, 0, 10) : null,
            'ip_address'       => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'user_agent'       => $ua !== null ? mb_strcut($ua, 0, self::TINYTEXT_BYTE, 'UTF-8') : null,
            'keterangan'       => $keterangan !== null ? mb_strcut($keterangan, 0, self::TINYTEXT_BYTE, 'UTF-8') : null,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Nama bergelar seperti surat cuti (`EsignCutiService::catat`): "glr_awal nama, glr_akhir".
     *
     * @param array<string, mixed> $p
     */
    private static function namaBergelar(array $p): string
    {
        $nama  = trim(trim((string) ($p['glr_awal'] ?? '')) . ' ' . trim((string) ($p['nama'] ?? '')));
        $akhir = trim((string) ($p['glr_akhir'] ?? ''));

        return $akhir !== '' ? $nama . ', ' . $akhir : $nama;
    }

    private static function teks(mixed $nilai): ?string
    {
        return is_scalar($nilai) && trim((string) $nilai) !== '' ? trim((string) $nilai) : null;
    }
}
