<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use App\Constants\Role;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\TestResponse;
use Config\Presensi;
use Config\Services;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Bantuan test impor Slip Gaji (PRD 5A §7.2, MAKE-030): berkas Excel sintetis dibangkitkan writer PhpSpreadsheet,
 * direktori pratinjau sementara per test, unggah multipart & commit lewat HTTP, akun admin role 1/3.
 *
 * Dipakai bersama AuthTestTrait + FeatureTestTrait + PegawaiFixtureTrait di kelas `DatabaseTestCase`.
 */
trait ImporSlipUjiTrait
{
    private string $dirPratinjau = '';

    /**
     * @var list<string>
     */
    private array $berkasSementara = [];

    protected function siapkanImporSlip(): void
    {
        $this->dirPratinjau = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slip-impor-uji-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        config(Presensi::class)->direktoriPratinjau = $this->dirPratinjau;
    }

    protected function bersihkanImporSlip(): void
    {
        foreach ([...$this->berkasSementara, ...(glob($this->dirPratinjau . '*') ?: [])] as $berkas) {
            @unlink($berkas);
        }

        @rmdir($this->dirPratinjau);
        $this->berkasSementara = [];
        Services::resetSingle('request');
        config(Presensi::class)->direktoriPratinjau = WRITEPATH . 'uploads/slip-impor/';
    }

    /**
     * Isi direktori pratinjau (nama berkas).
     *
     * @return list<string>
     */
    protected function isiDirPratinjau(): array
    {
        return array_map('basename', glob($this->dirPratinjau . '*') ?: []);
    }

    /**
     * Akun admin tanpa NIP. Role 3: `$satker` = id_satker akun ('' = tanpa satker).
     *
     * @return array<string, mixed>
     */
    protected function adminSlip(int $role = Role::SUPER_ADMIN, ?string $satker = null): array
    {
        return $this->buatAkunTanpaNip('slip' . bin2hex(random_bytes(3)), $role, ['id_satker' => $satker, 'must_change_password' => 0]);
    }

    /**
     * Satu baris data sheet: kunci + nominal bawaan sintetis (ditimpa $isi).
     *
     * @param array<string, mixed> $isi
     *
     * @return array<string, mixed>
     */
    protected function barisGpp(string $nip, int $bulan, int $tahun, ?string $nama, array $isi = []): array
    {
        return array_merge(['nip' => $nip, 'bulan' => $bulan, 'tahun' => $tahun, 'nama' => $nama, 'gjpokok' => 3000000, 'tjistri' => 300000, 'potpfk10' => 330000, 'bersih' => 2970000], $isi);
    }

    /**
     * @param array<string, mixed> $isi
     *
     * @return array<string, mixed>
     */
    protected function barisTk(string $nip, int $bulan, int $tahun, ?string $nama, array $isi = []): array
    {
        return array_merge(['nip' => $nip, 'bulan' => $bulan, 'tahun' => $tahun, 'nama' => $nama, 'kotor' => 2000000, 'potongan' => 100000, 'bersih' => 1900000, 'pajak' => 0, 'tunj_pajak' => 0, 'bersih_2' => 1900000], $isi);
    }

    /**
     * Berkas Excel sintetis sheet GPP + TK (header = kunci + nama + kolom sumber `KolomGaji`). NIP ditulis sebagai teks.
     *
     * @param list<array<string, mixed>> $gpp
     * @param list<array<string, mixed>> $tk
     */
    protected function berkasSlip(array $gpp, array $tk, string $format = 'Xlsx', ?string $namaKlien = null): UploadedFile
    {
        $buku = new Spreadsheet();
        $this->isiSheet($buku->getActiveSheet()->setTitle(KolomGaji::SHEET_GPP), array_keys(KolomGaji::SUMBER_GPP), $gpp);
        $this->isiSheet($buku->createSheet()->setTitle(KolomGaji::SHEET_TK), array_keys(KolomGaji::SUMBER_TK), $tk);

        $ekstensi = strtolower($format);
        $path     = $this->tempSlip('.' . $ekstensi);
        IOFactory::createWriter($buku, $format)->save($path);
        $buku->disconnectWorksheets();

        return $this->unggahanSlip($path, $namaKlien ?? 'slip_gaji_uji.' . $ekstensi);
    }

    /**
     * UploadedFile uji dari isi mentah (untuk berkas salah format/rusak).
     */
    protected function berkasMentah(string $isi, string $namaKlien): UploadedFile
    {
        $path = $this->tempSlip('');
        file_put_contents($path, $isi);

        return $this->unggahanSlip($path, $namaKlien);
    }

    /**
     * @param array<string, mixed>  $akun
     * @param array<string, string> $isian filter_bulan/filter_tahun
     */
    protected function unggahSlip(array $akun, ?UploadedFile $berkas, array $isian = []): TestResponse
    {
        if ($berkas !== null) {
            service('superglobals')->setFilesArray(['berkas' => [
                'name'     => $berkas->getClientName(),
                'type'     => $berkas->getClientMimeType(),
                'tmp_name' => $berkas->getTempName(),
                'error'    => $berkas->getError(),
                'size'     => (int) filesize($berkas->getTempName()),
            ]]);
        }

        try {
            return $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token'],
                'Content-Type'  => 'multipart/form-data; boundary=x',
            ])->post('api/v1/slip-gaji/impor/pratinjau', $isian);
        } finally {
            service('superglobals')->setFilesArray([]);
            Services::resetSingle('request');
        }
    }

    /**
     * @param array<string, mixed> $akun
     * @param array<string, mixed> $body
     */
    protected function commitSlip(array $akun, string $token, array $body = []): TestResponse
    {
        try {
            return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token']])
                ->withBodyFormat('json')
                ->post("api/v1/slip-gaji/impor/pratinjau/{$token}/commit", $body);
        } finally {
            Services::resetSingle('request');
        }
    }

    /**
     * Respons 200 → `data`; status lain → test gagal dengan isi respons.
     *
     * @return array<string, mixed>
     */
    protected function dataOk(TestResponse $res): array
    {
        $this->assertSame(200, $res->response()->getStatusCode(), (string) $res->response()->getBody());

        /** @var array<string, mixed> $data */
        $data = $this->json($res)['data'];

        return $data;
    }

    /**
     * @param list<string>               $kolomSumber
     * @param list<array<string, mixed>> $baris
     */
    private function isiSheet(Worksheet $sheet, array $kolomSumber, array $baris): void
    {
        $header = ['nip', 'bulan', 'tahun', 'nama', ...$kolomSumber];
        $sheet->fromArray([$header], null, 'A1');

        foreach ($baris as $i => $isi) {
            $nomor = $i + 2;

            foreach ($header as $kolom => $nama) {
                if (! array_key_exists($nama, $isi) || $isi[$nama] === null) {
                    continue;
                }

                $alamat = [$kolom + 1, $nomor];

                if ($nama === 'nip') {
                    // NIP teks tetap teks; NIP angka ditulis sel numerik sungguhan (binder bawaan mengubah >15 digit jadi teks).
                    $sheet->setCellValueExplicit($alamat, $isi[$nama], is_string($isi[$nama]) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValue($alamat, $isi[$nama]);
                }
            }
        }
    }

    private function unggahanSlip(string $path, string $namaKlien): UploadedFile
    {
        return new UploadedFile($path, $namaKlien, 'application/octet-stream', (int) filesize($path), UPLOAD_ERR_OK);
    }

    private function tempSlip(string $akhiran): string
    {
        $path                    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slipuji' . bin2hex(random_bytes(5)) . $akhiran;
        $this->berkasSementara[] = $path;

        return $path;
    }
}
