<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Usulan;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kariskarsu;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Karpeg;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Waktu\StempelUtc;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;

/**
 * PDF tanda terima usulan Karpeg / Karis-Karsu (B-17; keputusan F3 WS-1 MAKE-007; pembangkit PDF WS-2 MAKE-013,
 * ADR-033).
 *
 * Sumber [K]: `libraries/hr/rwy/L_karpeg.php` process() :928-1012 dan `L_kariskarsu.php` :1020-1060 (PDF dibuat saat
 * status 1, disimpan di `file_tanda_terima`), template `views/hr/employee/rwy/{karpeg,kariskarsu}/print/tt_pdf.php`.
 * No. tiket = `0000<yy dibuat><1 karpeg | 0 karis><id>`; kode permohonan (QR) = `<yy>0<unix dibuat><1|0><id>`.
 *
 * [V2] dibangkitkan saat diminta (bukan disimpan ke `file_tanda_terima` saat diproses) supaya proses approval engine
 * tidak bergantung pada pembangkit PDF; hak lihat = hak `detail` engine riwayat (pemohon, antrean role 1/4/5); hanya
 * untuk usulan berstatus Disetujui (legacy hanya membuat tanda terima saat disetujui); kop tanpa logo.
 */
final class TandaTerimaUsulan
{
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @return array{isi: string, nama: string}
     */
    public function pdf(AuthContext $auth, string $jenis, int $id): array
    {
        $d = service('riwayatRegistry')->definisi($jenis);

        if ($d === null || $d->alur() !== AlurRiwayat::Usulan) {
            throw new NotFoundException('Jenis usulan tidak ditemukan.');
        }

        $db  = $this->db();
        $nip = $db->table($d->tabel())->select($d->kolomNip())->where($d->primaryKey(), $id)
            ->where($d->kolomStatus() . ' !=', StatusRiwayat::Dihapus->value)->get()->getRowArray()[$d->kolomNip()] ?? null;

        if ($nip === null) {
            throw new NotFoundException('Usulan tidak ditemukan.');
        }

        // Hak lihat & lingkup = engine riwayat (403/404 dari sana).
        $baris = service('riwayatService')->detail($auth, (string) $nip, $jenis, $id);

        if ((int) $baris[$d->kolomStatus()] !== StatusRiwayat::Disetujui->value) {
            throw ValidationException::forField('status', 'Tanda terima tersedia setelah permohonan disetujui.');
        }

        $pegawai = $db->table('pegawai p')
            ->select('p.glr_awal, p.nama, p.glr_akhir, kp.pangkat, kp.gol_ruang, pmj.jabatan, pmj.unit, pmj.satker')
            ->join('pegawai_kp kp', 'kp.nip = p.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
            ->where('p.nip', $nip)->get()->getRowArray() ?? [];

        $karpeg = $jenis === 'karpeg';
        // L4/CR-086: `created_at` v2 = UTC, legacy = jam dinding WIB; epoch dari UTC sama dengan legacy, tahun 'y' = tahun WIB.
        $kode   = self::kode((string) $baris['created_at'], $karpeg, $id);
        $berkas = $karpeg ? Karpeg::BERKAS : Kariskarsu::BERKAS;
        $wc     = $this->webConfig();

        $html = view('pdf/tanda_terima_usulan', [
            'judul'   => $karpeg ? 'KARPEG' : 'KARIS/KARSU',
            'baris'   => $baris,
            'nama'    => StrukturService::namaGelar($pegawai['glr_awal'] ?? null, (string) ($pegawai['nama'] ?? ''), $pegawai['glr_akhir'] ?? null),
            'pegawai' => $pegawai,
            'noTiket' => self::noTiket((string) $baris['created_at'], $karpeg, $id),
            'kode'    => $kode,
            'berkas'  => $berkas,
            'qr'      => Pdf::qrImg($kode, 100),
            'dicetak' => Time::now('Asia/Jakarta')->format('d-m-Y'),
        ]);

        $kop = '<table width="100%" cellpadding="0"><tr><td style="text-align:center;font-size:12pt;"><b>'
            . esc(mb_strtoupper((string) ($wc['pdf_header_nama_kementerian'] ?? $wc['nama_kementerian'] ?? ''))) . '</b></td></tr>'
            . '<tr><td style="text-align:center;font-size:8pt;">' . nl2br(esc((string) ($wc['pdf_header_alamat_kementerian'] ?? ''))) . '</td></tr></table><hr style="height:2px;" />';

        $isi = Pdf::renderer()->render(new DokumenPdf(
            'Tanda Terima Permohonan ' . ($karpeg ? 'KARPEG' : 'KARIS/KARSU'),
            $html,
            kopHtml: $kop,
            kakiHtml: 'SIMPEG v2, ' . Time::now('Asia/Jakarta')->format('d-m-Y H:i'),
            marginAtasKop: 42,
        ));

        return ['isi' => $isi, 'nama' => "tt_{$jenis}_{$nip}_{$id}.pdf"];
    }

    /**
     * @return array<string, string>
     */
    /**
     * Kode/QR tanda terima = padanan legacy `yy . '0' . strtotime(created_at) . jenis . id` (CR-086): legacy menyimpan jam
     * dinding WIB, v2 menyimpan UTC, jadi epoch dihitung dari UTC (instan sama) dan `yy` = tahun WIB.
     */
    public static function kode(string $createdAtUtc, bool $karpeg, int $id): string
    {
        return (StempelUtc::keWib($createdAtUtc, 'y') ?? gmdate('y')) . '0' . (StempelUtc::epoch($createdAtUtc) ?? 0) . ($karpeg ? '1' : '0') . $id;
    }

    /** No. tiket legacy `0000 . yy . jenis . id`; `yy` = tahun WIB dari `created_at` UTC (CR-086). */
    public static function noTiket(string $createdAtUtc, bool $karpeg, int $id): string
    {
        return '0000' . (StempelUtc::keWib($createdAtUtc, 'y') ?? gmdate('y')) . ($karpeg ? '1' : '0') . $id;
    }

    private function webConfig(): array
    {
        $db  = $this->db();
        $ada = $db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$db->prefixTable('web_config')],
        )->getRowArray() !== null;

        if (! $ada) {
            return [];
        }

        $hasil = [];

        foreach ($db->table('web_config')->select('config_name, config_value')->get()->getResultArray() as $row) {
            $hasil[(string) $row['config_name']] = (string) ($row['config_value'] ?? '');
        }

        return $hasil;
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
