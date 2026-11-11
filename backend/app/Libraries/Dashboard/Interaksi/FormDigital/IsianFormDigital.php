<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\FormDigital;

use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

/**
 * F-06 sisi pegawai (MAKE-083, PRD Fase 7 §5.7, role 2/6/7 lewat filter route):
 *
 * - {@see self::saya()} — padanan legacy `core/MY_Controller.php:246-255`: form status 1, hari ini (WIB) dalam periode,
 *   aktor ada di `user_form_digital`, dan belum ada di `filled_form_digital`. Urut `order`, lalu id [K].
 * - {@see self::isi()} — padanan `controllers/hr/Announcement.php:495-584` dengan perbaikan [V2]:
 *   - form harus aktif + dalam periode + aktor ditarget, selain itu 404 (legacy tidak memeriksa);
 *   - jawaban divalidasi terhadap skema ({@see ValidatorJawaban});
 *   - berkas diperiksa dari ISI ({@see ValidasiBerkas}) dan disimpan di `form-digital/<id>/<acak>.<ext>`;
 *   - satu jawaban per pegawai per form dijamin UNIQUE `uk_filled_form_digital_id_fd_nip` → 409 `sudah_diisi` (Q7-17),
 *     berkas yang baru diunggah dihapus lagi;
 *   - baris target `user_form_digital` TIDAK dihapus (permanen, Q7-20; legacy menghapusnya saat submit).
 */
final class IsianFormDigital
{
    public const KODE_SUDAH_DIISI = 'sudah_diisi';

    public const PESAN_SUDAH_DIISI = 'Form ini sudah pernah Anda isi.';

    private const UNIQUE_JAWABAN = 'uk_filled_form_digital_id_fd_nip';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly StorageAdapterInterface $storage,
    ) {
    }

    /**
     * @return list<array{id: int, judul_form: string, keterangan: ?string, tgl_mulai: string, tgl_akhir: string, form: array{versi: int, pertanyaan: list<array<string, mixed>>}}>
     */
    public function saya(AuthContext $auth): array
    {
        $nip  = $this->nipAktor($auth);
        $hari = TanggalBisnis::hariIni();

        $rows = $this->db->table('form_digital f')
            ->select('f.id_form_digital, f.judul_form, f.keterangan, f.tgl_mulai, f.tgl_akhir, f.form_digital')
            ->join('user_form_digital u', 'u.id_form_digital = f.id_form_digital')
            ->join('filled_form_digital j', 'j.id_form_digital = f.id_form_digital AND j.nip = u.nip', 'left')
            ->where('u.nip', $nip)
            ->where('f.status', 1)
            ->where('f.tgl_mulai <=', $hari)
            ->where('f.tgl_akhir >=', $hari)
            ->where('j.id_filled_form_digital IS NULL', null, false)
            ->orderBy('f.order', 'ASC')
            ->orderBy('f.id_form_digital', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id_form_digital'],
            'judul_form' => (string) $r['judul_form'],
            'keterangan' => $r['keterangan'] === null ? null : (string) $r['keterangan'],
            'tgl_mulai'  => (string) $r['tgl_mulai'],
            'tgl_akhir'  => (string) $r['tgl_akhir'],
            'form'       => SkemaForm::dariJson((string) $r['form_digital']),
        ], $rows);
    }

    /**
     * @param mixed                       $jawaban map id pertanyaan => nilai (sudah di-decode dari field `jawaban`)
     * @param array<string, UploadedFile> $berkas  id pertanyaan => berkas (`berkas_<id>`)
     *
     * @return array{id: int, created_at: string}
     */
    public function isi(AuthContext $auth, int $idForm, mixed $jawaban, array $berkas): array
    {
        $nip   = $this->nipAktor($auth);
        $form  = $this->formTerbukaUntuk($idForm, $nip);
        $skema = SkemaForm::dariJson((string) $form['form_digital']);

        $adaBerkas = [];

        foreach ($berkas as $id => $b) {
            if ($b->getError() === UPLOAD_ERR_OK) {
                $adaBerkas[] = (string) $id;
            }
        }

        $bersih = ValidatorJawaban::periksa($skema, $jawaban, $adaBerkas);

        if ($this->sudahDiisi($idForm, $nip)) {
            throw new ApiException(self::PESAN_SUDAH_DIISI, 409, null, self::KODE_SUDAH_DIISI);
        }

        $tersimpan = [];

        try {
            foreach ($skema['pertanyaan'] as $p) {
                $id = (string) $p['id'];

                if ($p['tipe'] !== 'berkas' || ! in_array($id, $adaBerkas, true)) {
                    continue;
                }

                $aturan = new AturanLampiran(0, false, (int) $p['berkas']['maks_mb'], self::ekstensi($p['berkas']['tipe']));
                $hasil  = ValidasiBerkas::periksa($berkas[$id], $aturan, 'berkas_' . $id);
                $kunci  = 'form-digital/' . $idForm . '/' . bin2hex(random_bytes(16)) . '.' . $hasil['ekstensi'];
                $this->storage->simpan($kunci, $hasil['isi']);
                $tersimpan[] = $kunci;
                $bersih[$id] = ['berkas' => $kunci, 'nama' => $hasil['nama_tampil'], 'ukuran' => $hasil['ukuran']];
            }

            $sekarang = gmdate('Y-m-d H:i:s');
            $respons  = ['versi' => SkemaForm::VERSI, 'jawaban' => $bersih];

            $this->db->transException(true)->transStart();
            $this->db->table('filled_form_digital')->insert([
                'id_form_digital' => $idForm,
                'nip'             => $nip,
                'response'        => (string) json_encode($respons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at'      => $sekarang,
                'created_by'      => $auth->idPengguna(),
            ]);
            $idJawaban = (int) $this->db->insertID();
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->db->transException(false);

            foreach ($tersimpan as $kunci) {
                try {
                    $this->storage->hapus($kunci);
                } catch (Throwable) {
                    // Galat asli lebih penting.
                }
            }

            if ($e instanceof DatabaseException && str_contains($e->getMessage(), self::UNIQUE_JAWABAN)) {
                throw new ApiException(self::PESAN_SUDAH_DIISI, 409, null, self::KODE_SUDAH_DIISI);
            }

            throw $e;
        }

        $this->db->transException(false);

        return ['id' => $idJawaban, 'created_at' => $sekarang];
    }

    /**
     * @return array<string, mixed>
     */
    private function formTerbukaUntuk(int $idForm, string $nip): array
    {
        $hari = TanggalBisnis::hariIni();
        $form = $this->db->table('form_digital f')
            ->select('f.id_form_digital, f.form_digital')
            ->join('user_form_digital u', 'u.id_form_digital = f.id_form_digital')
            ->where('f.id_form_digital', $idForm)
            ->where('u.nip', $nip)
            ->where('f.status', 1)
            ->where('f.tgl_mulai <=', $hari)
            ->where('f.tgl_akhir >=', $hari)
            ->get()->getRowArray();

        if (! is_array($form)) {
            throw new NotFoundException('Form tidak ditemukan.');
        }

        return $form;
    }

    private function sudahDiisi(int $idForm, string $nip): bool
    {
        return $this->db->table('filled_form_digital')->where('id_form_digital', $idForm)->where('nip', $nip)->countAllResults() > 0;
    }

    private function nipAktor(AuthContext $auth): string
    {
        $nip = $auth->nip();

        if ($nip === null || $nip === '') {
            throw new ForbiddenException('Akun tanpa NIP tidak dapat mengisi form digital.');
        }

        return $nip;
    }

    /**
     * Jenis skema → ekstensi ValidasiBerkas (`jpg` juga menerima `jpeg`).
     *
     * @param list<string> $tipe
     *
     * @return list<string>
     */
    private static function ekstensi(array $tipe): array
    {
        return in_array('jpg', $tipe, true) ? array_values(array_unique([...$tipe, 'jpeg'])) : $tipe;
    }

    /**
     * Decode field multipart `jawaban` (JSON). Bukan JSON objek → 422.
     */
    public static function decodeJawaban(mixed $mentah): mixed
    {
        if ($mentah === null || $mentah === '') {
            return [];
        }

        if (is_array($mentah)) {
            return $mentah;
        }

        $d = is_string($mentah) ? json_decode($mentah, true) : null;

        if (! is_array($d)) {
            throw ValidationException::forField('jawaban', 'Format jawaban tidak valid.');
        }

        return $d;
    }
}
