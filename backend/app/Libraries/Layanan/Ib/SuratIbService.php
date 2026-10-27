<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Ib;

use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Layanan\PenandaTanganKabiro;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\KopDokumen;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/**
 * Surat IB (PRD Fase 4 §6.8.1) — efek final IB: saat PIC menyetujui, PDF dirender dari `pdf/surat_ib` [K
 * `L_ib.php:896-964`, templat `print/surat_ib`] dengan kop kementerian (`KopDokumen::html(null, null)`) dan penanda tangan
 * Kabiro kepegawaian (`PenandaTanganKabiro`, Q-06(a)), lalu disimpan `document_attachment` kode 50 di DALAM transaksi
 * approval (rollback → berkas dikompensasi AttachmentService). `GET ib/{id}/surat` mengunduh surat tersimpan terbaru;
 * hanya IB disetujui, selain itu 404.
 */
class SuratIbService
{
    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?AttachmentServiceInterface $lampiran = null,
    ) {
    }

    /**
     * Render + simpan surat IB untuk baris yang baru disetujui (dipanggil di dalam transaksi pemanggil).
     *
     * @param array<string, mixed> $row baris `riwayat_ib` sesudah update
     */
    public function buatDanSimpan(array $row): void
    {
        $nip  = (string) $row['nip'];
        $id   = (int) $row['id_riwayat_ib'];
        $path = tempnam(sys_get_temp_dir(), 'surat_ib');

        if ($path === false || file_put_contents($path, $this->render($row)) === false) {
            throw new RuntimeException('Surat izin belajar gagal dibuat.');
        }

        try {
            // Berkas buatan server memakai jalur validasi & penyimpanan yang sama dengan unggahan (ValidasiBerkas membaca
            // isi dari path sementara).
            $berkas = new UploadedFile($path, "surat_ib_{$nip}_{$id}.pdf", 'application/pdf', (int) filesize($path), UPLOAD_ERR_OK);
            $this->lampiran()->simpan($nip, BerkasStudi::KODE_SURAT_IB, $id, $berkas, new AturanLampiran(BerkasStudi::KODE_SURAT_IB, true, BerkasStudi::BATAS_MB, BerkasStudi::EKSTENSI_SK));
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $row baris `riwayat_ib` yang terlihat pemanggil
     *
     * @return array{isi: string, nama: string}
     */
    public function unduh(AuthContext $auth, array $row): array
    {
        $nip   = (string) $row['nip'];
        $surat = (int) $row['status'] === StatusStudi::DISETUJUI ? $this->lampiran()->daftar($nip, BerkasStudi::KODE_SURAT_IB, (int) $row['id_riwayat_ib']) : [];

        if ($surat === []) {
            throw new NotFoundException('Surat izin belajar belum tersedia.');
        }

        usort($surat, static fn (array $a, array $b): int => (int) $b['id_attachment'] <=> (int) $a['id_attachment']);
        $hasil = $this->lampiran()->ambil($nip, (int) $surat[0]['id_attachment']);

        return ['isi' => $hasil['isi'], 'nama' => (string) ($hasil['lampiran']['display_name'] ?? 'surat_ib.pdf')];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function render(array $row): string
    {
        $ttd    = new PenandaTanganKabiro($this->db);
        $tujuan = $ttd->unitKepegawaian() ?? 'Sekretaris ' . (string) service('webConfigService')->value('nama_kementerian');
        $html   = view('pdf/surat_ib', ['ib' => $row, 'kabiro' => $ttd->kabiro(), 'tujuan' => $tujuan]);

        return Pdf::renderer()->render(new DokumenPdf('Surat Izin Belajar ' . (string) $row['nama'], $html, KopDokumen::html(null, null, $this->db), marginAtasKop: 42));
    }

    private function lampiran(): AttachmentServiceInterface
    {
        return $this->lampiran ?? service('attachmentService');
    }
}
