<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Gambar;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Konten;

/**
 * Validasi dan penyimpanan gambar popup/banner (PRD Fase 7 v2.0 Q7-06, Q7-14). Tipe dari ISI berkas dan ukuran lewat
 * {@see ValidasiBerkas} (jpg/jpeg/png/gif, ≤ 5 MB), lalu dimensi dari isi gambar: banner wajib rasio 4:1 (toleransi) dalam
 * rentang lebar; popup rasio bebas dalam rentang sisi. Disimpan privat (bukan webroot) dengan kunci acak; yang masuk DB
 * hanya kunci relatif `<jenis>/<hex32>.<ext>`. Pembacaan lewat endpoint terautentikasi, tidak pernah URL statis.
 */
final class PenyimpananGambar
{
    private readonly Konten $config;

    private readonly LocalStorageAdapter $adapter;

    public function __construct(?Konten $config = null, ?LocalStorageAdapter $adapter = null)
    {
        $this->config  = $config ?? config(Konten::class);
        $this->adapter = $adapter ?? new LocalStorageAdapter($this->config->akarPenyimpanan);
    }

    /**
     * Validasi saja (tanpa menulis). Mengembalikan isi dan ekstensi untuk {@see self::simpan()}.
     *
     * @return array{isi: string, ekstensi: string, mime: string}
     *
     * @throws ValidationException 422 pada field `berkas`
     */
    public function periksa(UploadedFile $berkas, JenisGambar $jenis): array
    {
        $aturan = new AturanLampiran(0, false, $this->config->gambarMaksMb, $this->config->gambarEkstensi);
        $hasil  = ValidasiBerkas::periksa($berkas, $aturan, 'berkas');

        $info = @getimagesizefromstring($hasil['isi']);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ValidationException::forField('berkas', 'Isi berkas bukan gambar yang valid.');
        }

        $this->periksaDimensi($jenis, (int) $info[0], (int) $info[1]);

        return ['isi' => $hasil['isi'], 'ekstensi' => $hasil['ekstensi'], 'mime' => $hasil['mime']];
    }

    /**
     * @return string kunci relatif untuk kolom `file`
     */
    public function simpan(JenisGambar $jenis, string $isi, string $ekstensi): string
    {
        $kunci = $jenis->value . '/' . bin2hex(random_bytes(16)) . '.' . $ekstensi;
        $this->adapter->simpan($kunci, $isi);

        return $kunci;
    }

    public function baca(string $kunci): string
    {
        return $this->adapter->baca($kunci);
    }

    public function ada(string $kunci): bool
    {
        return $this->adapter->ada($kunci);
    }

    public function hapus(string $kunci): void
    {
        $this->adapter->hapus($kunci);
    }

    private function periksaDimensi(JenisGambar $jenis, int $lebar, int $tinggi): void
    {
        $c = $this->config;

        if ($jenis === JenisGambar::Banner) {
            if ($lebar < $c->bannerLebarMin || $lebar > $c->bannerLebarMaks) {
                throw ValidationException::forField('berkas', "Lebar banner harus {$c->bannerLebarMin}–{$c->bannerLebarMaks} px (gambar {$lebar} px).");
            }

            if (abs($lebar / $tinggi - $c->bannerRasio) > $c->bannerRasio * $c->bannerToleransi) {
                throw ValidationException::forField('berkas', 'Rasio banner harus 4:1 (lebar : tinggi), gambar ' . $lebar . '×' . $tinggi . ' px.');
            }

            return;
        }

        foreach ([$lebar, $tinggi] as $sisi) {
            if ($sisi < $c->popupSisiMin || $sisi > $c->popupSisiMaks) {
                throw ValidationException::forField('berkas', "Sisi gambar popup harus {$c->popupSisiMin}–{$c->popupSisiMaks} px (gambar {$lebar}×{$tinggi} px).");
            }
        }
    }
}
