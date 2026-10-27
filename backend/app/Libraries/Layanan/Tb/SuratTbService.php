<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Tb;

use App\Exceptions\NotFoundException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\PenandaTanganKabiro;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\KopDokumen;
use App\Libraries\Pdf\Pdf;

/**
 * Surat TB (PRD Fase 4 §6.7.6) — `GET tb/{id}/surat`. Hak = hak lihat detail; hanya TB disetujui (status 1), selain itu
 * 404. Bila ada SK unggahan PIC (`document_attachment` kode 47, terbaru) → itu yang diunduh; bila tidak → dirender
 * on-the-fly dari templat `pdf/surat_tb` [K `Tb.php:326-473`] dengan kop kementerian (`KopDokumen::html(null, null)`)
 * dan penanda tangan Kabiro kepegawaian (`PenandaTanganKabiro`, Q-06(a)).
 */
class SuratTbService
{
    public function __construct(private readonly ?TbService $tb = null)
    {
    }

    /**
     * @return array{isi: string, nama: string, pdf: bool, mime: string}
     */
    public function surat(AuthContext $auth, int $id): array
    {
        $tb  = $this->tb ?? new TbService();
        $row = $tb->barisTerlihat($auth, $id);

        if ((int) $row['status'] !== StatusStudi::DISETUJUI) {
            throw new NotFoundException('Surat tugas belajar belum tersedia.');
        }

        $nip = (string) $row['nip'];
        $sk  = service('attachmentService')->daftar($nip, BerkasStudi::KODE_SURAT_TB, $id);

        if ($sk !== []) {
            usort($sk, static fn (array $a, array $b): int => (int) $b['id_attachment'] <=> (int) $a['id_attachment']);
            $hasil = service('attachmentService')->ambil($nip, (int) $sk[0]['id_attachment']);

            return [
                'isi'  => $hasil['isi'],
                'nama' => (string) ($hasil['lampiran']['display_name'] ?? "sk_tb_{$id}.pdf"),
                'pdf'  => true,
                'mime' => (string) ($hasil['lampiran']['file_type'] ?? 'application/pdf'),
            ];
        }

        $ttd    = new PenandaTanganKabiro();
        $tujuan = $ttd->unitKepegawaian() ?? 'Sekretaris ' . (string) service('webConfigService')->value('nama_kementerian');
        $html   = view('pdf/surat_tb', ['tb' => $row, 'kabiro' => $ttd->kabiro(), 'tujuan' => $tujuan]);
        $isi    = Pdf::renderer()->render(new DokumenPdf('Surat Tugas Belajar ' . (string) $row['nama'], $html, KopDokumen::html(null, null), marginAtasKop: 42));

        return ['isi' => $isi, 'nama' => "surat_tb_{$nip}_{$id}.pdf", 'pdf' => true, 'mime' => 'application/pdf'];
    }
}
