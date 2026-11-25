<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\Layanan\PenerbitSuratCutiInterface;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\KopDokumen;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Surat cuti (PRD Fase 4 v1.3 §6.5.2, C-05): satu generator PDF untuk semua jalur (perbaikan E10) dengan kop kementerian
 * (`KopDokumen::htmlSurat()`, K1/Q-10; kop seragam CR-191). Versi BELUM TTE dibuat otomatis saat efek final status 1/4
 * ({@see PenerbitSuratCutiInterface}, di dalam transaksi persetujuan) dan disimpan `document_attachment` kode
 * {@see CutiService::ID_SURAT_BELUM_TTE}; versi ber-TTE disimpan terpisah dengan kode {@see CutiService::ID_SURAT_TTE}.
 */
class SuratCutiService implements PenerbitSuratCutiInterface
{
    public const BATAS_MB = 5;

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?AttachmentServiceInterface $lampiran = null,
        private readonly ?TokenSuratCutiService $token = null,
    ) {
    }

    /**
     * Isi QR kaki surat (PRD Fase 6 v1.0f Q6-23, AC-02.8): URL halaman verifikasi publik ber-token — tanpa NIP (dulu teks
     * `SIMPEG/CUTI/{id}/{nip}`). Token sama dengan URL PDF QR tanda tangan (Q6-22). Null bila token tidak dapat dipulihkan
     * (QR tidak dicetak, pola slip).
     *
     * @param array<string, mixed> $cuti baris `riwayat_cuti`
     */
    public function tautanVerifikasi(array $cuti): ?string
    {
        $token  = $this->token ?? service('tokenSuratCuti');
        $mentah = $token->untukCuti((int) $cuti['id_riwayat_cuti']);

        return $mentah === null ? null : $token->urlVerifikasi($mentah);
    }

    /**
     * Render + simpan surat belum TTE. Idempotensi: versi belum TTE yang sudah ada diganti (satu per cuti).
     *
     * @param array<string, mixed> $cuti baris `riwayat_cuti` setelah status final ditulis
     */
    public function terbitkanBelumTte(array $cuti): void
    {
        $nip = (string) $cuti['nip'];
        $id  = (int) $cuti['id_riwayat_cuti'];

        foreach ($this->lampiran()->daftar($nip, CutiService::ID_SURAT_BELUM_TTE, $id) as $lama) {
            $this->lampiran()->hapus($nip, (int) $lama['id_attachment']);
        }

        $this->simpanBerkas($nip, CutiService::ID_SURAT_BELUM_TTE, $id, $this->render($cuti), "surat_cuti_{$nip}_{$id}.pdf");
    }

    /**
     * Simpan PDF ber-TTE sebagai lampiran terpisah (tidak menimpa versi belum TTE). Dipanggil di dalam transaksi pemanggil.
     *
     * @return array<string, mixed> baris `document_attachment`
     */
    public function simpanTte(string $nip, int $idCuti, string $isi): array
    {
        return $this->simpanBerkas($nip, CutiService::ID_SURAT_TTE, $idCuti, $isi, "surat_cuti_ttd_{$nip}_{$idCuti}.pdf");
    }

    /**
     * Versi belum TTE terbaru milik cuti, atau null.
     *
     * @return array{lampiran: array<string, mixed>, isi: string}|null
     */
    public function belumTte(string $nip, int $idCuti): ?array
    {
        $daftar = $this->lampiran()->daftar($nip, CutiService::ID_SURAT_BELUM_TTE, $idCuti);

        if ($daftar === []) {
            return null;
        }

        usort($daftar, static fn (array $a, array $b): int => (int) $b['id_attachment'] <=> (int) $a['id_attachment']);

        return $this->lampiran()->ambil($nip, (int) $daftar[0]['id_attachment']);
    }

    /**
     * Versi ber-TTE terbaru milik cuti, atau null (dipakai route PDF publik Q6-22).
     *
     * @return array{lampiran: array<string, mixed>, isi: string}|null
     */
    public function tte(string $nip, int $idCuti): ?array
    {
        $daftar = $this->lampiran()->daftar($nip, CutiService::ID_SURAT_TTE, $idCuti);

        if ($daftar === []) {
            return null;
        }

        usort($daftar, static fn (array $a, array $b): int => (int) $b['id_attachment'] <=> (int) $a['id_attachment']);

        return $this->lampiran()->ambil($nip, (int) $daftar[0]['id_attachment']);
    }

    /**
     * CR-180a (K12 opsi d): cuti legacy hasil impor yang sudah ber-TTE di legacy. `L_cuti::process_esign` (allow_esign = 1)
     * mengisi `file_sc_signed_bef` (PDF pra-TTE, sama dengan `esign_hist.file_pdf_to_sign`) DAN `file_surat_cuti` (PDF
     * ber-TTE); v2 tidak pernah mengisi kedua kolom itu (berkas v2 di `document_attachment`, D13). Sampai pemetaan cutover
     * ke kode 45 (CR-180b) jalan, pasangan ini menutup TTE ulang (TTE ganda). Murni, tanpa kueri.
     *
     * `file_surat_cuti` saja BUKAN penanda: legacy juga mengisinya dengan surat tanpa TTE (allow_esign ≠ 1, insert,
     * regenerate_sc) — perlakuannya menunggu QA-30.
     *
     * Kasus tepi: `regenerate_sc` legacy (manual role 1) bisa menimpa `file_surat_cuti` baris ber-TTE dengan surat tanpa TTE,
     * sementara `file_sc_signed_bef` tetap — baris itu tetap terkunci (arah aman: tidak membuka TTE ganda).
     * Sengaja varian "dua kolom saja, tanpa `esign_hist`": syarat `esign_hist` (status 1) untuk klasifikasi kode 45 diputus
     * di pemetaan cutover CR-180b (kartu QA-29), bukan di pagar ini.
     *
     * @param array<string, mixed> $cuti baris `riwayat_cuti`
     */
    public static function tteLegacy(array $cuti): bool
    {
        return trim((string) ($cuti['file_sc_signed_bef'] ?? '')) !== '' && trim((string) ($cuti['file_surat_cuti'] ?? '')) !== '';
    }

    public function adaTte(string $nip, int $idCuti): bool
    {
        return $this->lampiran()->daftar($nip, CutiService::ID_SURAT_TTE, $idCuti) !== [];
    }

    /**
     * @param array<string, mixed> $cuti baris `riwayat_cuti`
     */
    public function render(array $cuti): string
    {
        $url  = $this->tautanVerifikasi($cuti);
        $html = view('pdf/surat_cuti', [
            'dataset'  => $cuti,
            'tglSurat' => $this->tanggalSurat((string) ($cuti['created_at'] ?? '')),
            'qr'       => $url === null ? '' : Pdf::qrImg($url),
        ]);

        return Pdf::renderer()->render(new DokumenPdf('Surat Cuti ' . (string) $cuti['nama'], $html, KopDokumen::htmlSurat($this->db), marginAtasKop: 42));
    }

    /**
     * `created_at` tersimpan UTC; tanggal surat memakai tanggal bisnis (WIB).
     */
    private function tanggalSurat(string $createdAt): string
    {
        if ($createdAt === '') {
            return (new DateTimeImmutable('now', new DateTimeZone(TanggalBisnis::ZONA)))->format('Y-m-d');
        }

        return (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(TanggalBisnis::ZONA))->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function simpanBerkas(string $nip, int $kode, int $id, string $isi, string $nama): array
    {
        $path = tempnam(sys_get_temp_dir(), 'surat_cuti');

        if ($path === false || file_put_contents($path, $isi) === false) {
            throw new RuntimeException('Surat cuti gagal dibuat.');
        }

        try {
            // Berkas buatan server memakai jalur validasi & penyimpanan yang sama dengan unggahan.
            $berkas = new UploadedFile($path, $nama, 'application/pdf', (int) filesize($path), UPLOAD_ERR_OK);

            return $this->lampiran()->simpan($nip, $kode, $id, $berkas, new AturanLampiran($kode, true, self::BATAS_MB, ['pdf']));
        } finally {
            @unlink($path);
        }
    }

    private function lampiran(): AttachmentServiceInterface
    {
        return $this->lampiran ?? service('attachmentService');
    }
}
