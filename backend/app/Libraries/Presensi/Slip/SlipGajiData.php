<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\FormatSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Libraries\Presensi\SlipGaji\NominalGaji;
use App\Libraries\Presensi\SlipGaji\PencocokanNama;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use App\Libraries\Presensi\SlipGaji\RingkasanSlip;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;
use Config\Services;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Bacaan & bentuk data slip bersama jalur pegawai dan admin (PRD 5A §7.3): baris `gaji_pegawai`, identitas jabatan
 * **saat ini** dari snapshot `pegawai_mutasi_jabatan` + pangkat `pegawai_kp` [K], rincian 28 kolom (string desimal kanonik),
 * ringkasan `RingkasanSlip` (total termasuk uang makan, F5-1), blok uang makan, dan PDF (`PdfSlipGaji`, kop & logo dari
 * `web_config` lewat `KopSlip`, QR dari token aktif terenkripsi).
 */
final class SlipGajiData
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Presensi $config,
        private readonly TokenSlipService $token,
        private readonly ?PdfSlipGaji $pdf = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function barisPeriode(string $nip, PeriodeSlip $periode): ?array
    {
        return $this->db->table('gaji_pegawai')->where(['nip' => $nip, 'bulan' => $periode->bulan, 'tahun' => $periode->tahun])->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function barisId(int $id): ?array
    {
        return $this->db->table('gaji_pegawai')->where('id', $id)->get()->getRowArray();
    }

    /**
     * Data pegawai untuk cakupan, identitas & snapshot verifikasi (null bila pegawai tidak ada).
     *
     * @return array{nama: string, nama_bergelar: string, id_satker: ?string, pangkat: string, jabatan: string, unit: string, satker: string, satker_nama: ?string}|null
     */
    public function pegawai(string $nip): ?array
    {
        $r = $this->db->table('pegawai p')
            ->select('p.nama, p.glr_awal, p.glr_akhir, kp.pangkat, kp.gol_ruang, pmj.id_satker, pmj.jabatan, pmj.unit, pmj.satker')
            ->join('pegawai_kp kp', 'kp.nip = p.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
            ->where('p.nip', $nip)->get(1)->getRowArray();

        if ($r === null) {
            return null;
        }

        $teks    = static fn (mixed $v): string => trim((string) ($v ?? ''));
        $pangkat = implode(' / ', array_filter([$teks($r['pangkat']), $teks($r['gol_ruang'])], static fn (string $s): bool => $s !== ''));

        return [
            'nama'          => (string) $r['nama'],
            'nama_bergelar' => PencocokanNama::denganGelar($r['glr_awal'] ?? null, (string) $r['nama'], $r['glr_akhir'] ?? null),
            'id_satker'     => $r['id_satker'] === null ? null : (string) $r['id_satker'],
            'pangkat'       => $pangkat === '' ? '-' : $pangkat,
            'jabatan'       => $teks($r['jabatan']) === '' ? '-' : $teks($r['jabatan']),
            'unit'          => $teks($r['unit']) === '' ? '-' : $teks($r['unit']),
            'satker'        => $teks($r['satker']) === '' ? '-' : $teks($r['satker']),
            'satker_nama'   => $teks($r['satker']) !== '' ? $teks($r['satker']) : ($teks($r['unit']) !== '' ? $teks($r['unit']) : null),
        ];
    }

    /**
     * 28 kolom + `uang_makan` dalam sen.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, int>
     */
    public static function sen(array $row): array
    {
        $sen = [];

        foreach ([...KolomGaji::semuaField(), 'uang_makan'] as $f) {
            $sen[$f] = NominalGaji::dariDesimal((string) ($row[$f] ?? '0.00'));
        }

        return $sen;
    }

    /**
     * Rincian + ringkasan + uang makan (nominal hanya untuk respons buka / admin / PDF).
     *
     * @param array<string, mixed> $row
     *
     * @return array{rincian: array<string, string>, ringkasan: array<string, string>, uang_makan: array<string, mixed>}
     */
    public static function nominal(array $row): array
    {
        $sen       = self::sen($row);
        $rincian   = [];
        $ringkasan = [];

        foreach (KolomGaji::semuaField() as $f) {
            $rincian[$f] = NominalGaji::keDesimal($sen[$f]);
        }

        foreach (RingkasanSlip::hitung($sen) as $k => $v) {
            $ringkasan[$k] = NominalGaji::keDesimal($v);
        }

        $ringkasan = ['jumlah_penghasilan_gpp' => $ringkasan['jumlah_penghasilan_gpp'], 'jumlah_potongan_gpp' => $ringkasan['jumlah_potongan_gpp'], 'bersih_gpp' => $ringkasan['bersih_gpp'], 'bersih_tk' => $ringkasan['bersih_tk'], 'bersih_2' => $ringkasan['bersih_2'], 'uang_makan' => NominalGaji::keDesimal($sen['uang_makan']), 'total_penghasilan' => $ringkasan['total_penghasilan']];

        return ['rincian' => $rincian, 'ringkasan' => $ringkasan, 'uang_makan' => self::uangMakan($row)];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{nominal: string, hari: int|null, periode_kehadiran: string|null, tersedia: bool}
     */
    public static function uangMakan(array $row): array
    {
        $periode = $row['uang_makan_periode'] ?? null;
        $kunci   = null;

        if (is_string($periode) && preg_match('/^(\d{1,2})-(\d{4})$/', $periode, $m) === 1) {
            $kunci = sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }

        return [
            'nominal'           => NominalGaji::keDesimal(NominalGaji::dariDesimal((string) ($row['uang_makan'] ?? '0.00'))),
            'hari'              => $row['uang_makan_hari'] === null ? null : (int) $row['uang_makan_hari'],
            'periode_kehadiran' => $kunci,
            'tersedia'          => $periode !== null,
        ];
    }

    public static function utc(?string $stempel): ?DateTimeImmutable
    {
        return $stempel === null || $stempel === '' ? null : new DateTimeImmutable($stempel, new DateTimeZone('UTC'));
    }

    /**
     * `viewed_at` UTC ISO 8601 + padanan WIB.
     *
     * @param array<string, mixed> $row
     *
     * @return array{viewed_at: string|null, viewed_at_wib: string|null}
     */
    public static function waktuDibuka(array $row): array
    {
        $t = self::utc($row['viewed_at'] ?? null);

        return ['viewed_at' => $t?->format('Y-m-d\TH:i:s\Z'), 'viewed_at_wib' => $t === null ? null : FormatSlip::waktuWib($t)];
    }

    /**
     * PDF slip: `['isi' => biner PDF, 'nama' => nama berkas]`. QR hanya bila ada token aktif (U-3).
     *
     * @param array<string, mixed> $row
     *
     * @return array{isi: string, nama: string}
     */
    public function pdf(array $row, DateTimeInterface $sekarang): array
    {
        $nip     = (string) $row['nip'];
        $periode = PeriodeSlip::buat((int) $row['tahun'], (int) $row['bulan']);
        $p       = $this->pegawai($nip);
        $kop     = KopSlip::dariWebConfig(Services::webConfigService()->values(), $this->config->akarAset);
        $mentah  = $row['qr_token'] === null ? null : $this->token->mentah($row['qr_token_enkripsi'] ?? null);

        $data = new DataPdfSlip(
            $periode,
            [
                'nama'    => $p['nama_bergelar'] ?? '-',
                'nip'     => $nip,
                'pangkat' => $p['pangkat'] ?? '-',
                'jabatan' => $p['jabatan'] ?? '-',
                'unit'    => $p['unit'] ?? '-',
                'satker'  => $p['satker'] ?? '-',
            ],
            self::sen($row),
            $row['uang_makan_periode'] ?? null,
            $kop['kop'],
            $kop['logoKiri'],
            $kop['logoKanan'],
            $mentah === null ? null : $this->config->urlVerifikasiSlip . $mentah,
            $sekarang,
            self::utc($row['viewed_at'] ?? null),
            $kop['namaInstansi'],
        );

        return [
            'isi'  => ($this->pdf ?? PdfSlipGaji::bawaan())->render($data),
            'nama' => FormatSlip::namaBerkasPdf($p['nama'] ?? null, $nip, $periode),
        ];
    }
}
