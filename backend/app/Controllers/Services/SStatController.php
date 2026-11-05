<?php

declare(strict_types=1);

namespace App\Controllers\Services;

use App\Controllers\BaseController;
use App\Libraries\Integrasi\Layanan\ResponsLayanan;
use App\Libraries\Integrasi\Stat\PenyajiStat;
use App\Libraries\Integrasi\Stat\StatService;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use finfo;

/**
 * MAKE-061 (H-08) — `services/s_stat/*`: endpoint tarik MuDig kompatibel legacy `controllers/services/S_stat.php` [K]
 * (rekomendasi KP/KGB/pensiun, daftar TB/IB/Karpeg/Kariskarsu) + endpoint unduh berkas klien layanan [V2] (keputusan
 * Horii Q6-38). Semua di balik filter `klienLayanan:stat` (A3, F6-03): auth gagal → 401 dari filter; controller tidak
 * mengulang auth. Amplop bisnis INT `{"status":1|2,"msg","data"}` HTTP 200 ({@see ResponsLayanan}); 404 seragam untuk
 * berkas. "Hari ini" = tanggal bisnis WIB (Q4). Log hanya jumlah baris, tanpa NIP/nama.
 *
 * Varian legacy yang TIDAK di-port: `rekomendasi_kp_uns_20230516`, `rekomendasi_kgb_20230706` (kode mati), varian
 * satker/unit pensiun/KGB (dikomentari di legacy).
 */
final class SStatController extends BaseController
{
    public function rekomendasiKp(): ResponseInterface
    {
        return $this->daftar('rekomendasi_kp', $this->service()->rekomendasiKp($this->hariIni()));
    }

    /**
     * Q6-37 [K]: `id_unit`/`id_satker` tidak kosong → `data: []` (bug legacy direplikasi; AC-08.4).
     */
    public function rekomendasiKpUns(): ResponseInterface
    {
        $idUnit   = trim((string) $this->request->getGet('id_unit'));
        $idSatker = trim((string) $this->request->getGet('id_satker'));

        return $this->daftar('rekomendasi_kp_uns', $this->service()->rekomendasiKpUns($this->hariIni(), $idUnit, $idSatker));
    }

    public function rekomendasiPensiun(): ResponseInterface
    {
        return $this->daftar('rekomendasi_pensiun', $this->service()->rekomendasiPensiun($this->hariIni()));
    }

    public function rekomendasiKgb(): ResponseInterface
    {
        return $this->daftar('rekomendasi_kgb', $this->service()->rekomendasiKgb($this->hariIni()));
    }

    public function listTb(): ResponseInterface
    {
        return $this->daftar('list_tb', $this->service()->listTb());
    }

    public function listIb(): ResponseInterface
    {
        return $this->daftar('list_ib', $this->service()->listIb());
    }

    public function listKarpeg(): ResponseInterface
    {
        return $this->daftar('list_karpeg', $this->service()->listKarpeg());
    }

    public function listKariskarsu(): ResponseInterface
    {
        return $this->daftar('list_kariskarsu', $this->service()->listKariskarsu());
    }

    /**
     * `GET services/s_stat/berkas/{jenis}/{nip}/{id}` — lampiran `document_attachment` (kp|skp|jabatan|kgb).
     */
    public function berkasLampiran(string $jenis, string $nip, string $id): ResponseInterface
    {
        $berkas = ctype_digit($id) ? $this->service()->berkasLampiran($jenis, $nip, (int) $id, $this->hariIni()) : null;

        return $berkas === null ? ResponsLayanan::tidakDitemukan() : $this->unduh($berkas);
    }

    /**
     * `GET services/s_stat/berkas/{jenis}/{nip}/{id}/{kolom}` — berkas kolom (pak|tb|ib|karpeg|kariskarsu).
     */
    public function berkasKolom(string $jenis, string $nip, string $id, string $kolom): ResponseInterface
    {
        $berkas = ctype_digit($id) ? $this->service()->berkasKolom($jenis, $nip, (int) $id, $kolom) : null;

        return $berkas === null ? ResponsLayanan::tidakDitemukan() : $this->unduh($berkas);
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    private function daftar(string $aksi, array $data): ResponseInterface
    {
        log_message('debug', 's_stat {aksi}: {jumlah} baris', ['aksi' => $aksi, 'jumlah' => count($data)]);

        return ResponsLayanan::sukses($data);
    }

    /**
     * Stream berkas: mime dari isi, nama generik, `attachment`, `nosniff`, `no-store` (Q6-38 butir 5).
     *
     * @param array{isi: string, nama: string} $berkas
     */
    private function unduh(array $berkas): ResponseInterface
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($berkas['isi']);

        return $this->response
            ->setStatusCode(200)
            ->setContentType(is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $berkas['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($berkas['isi']);
    }

    private function hariIni(): string
    {
        return TanggalBisnis::hariIni();
    }

    private function service(): StatService
    {
        $insInduk = Services::webConfigService()->value('nama_kementerian');

        return new StatService(
            db_connect(),
            Services::storageAdapter(),
            new PenyajiStat(base_url()),
            is_scalar($insInduk) ? (string) $insInduk : '',
        );
    }
}
