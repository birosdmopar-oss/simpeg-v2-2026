<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\SlipGaji\AksesSlip;
use App\Libraries\Presensi\SlipGaji\GerbangPeriode;
use App\Libraries\Presensi\SlipGaji\NominalGaji;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use App\Libraries\Presensi\SlipGaji\StatusSlip;
use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * D-11 jalur pegawai Slip Gaji (PRD 5A §6.1, §7.3.1–7.3.3, §7.3.6–7.3.7) — port `Sl_gaji.php` @`771f8b4` [K]:
 *
 * - Pelaku role 2/6/7 dengan NIP (`AksesSlip::bolehMelihatMilikSendiri`); NIP selalu dari token, parameter NIP klien
 *   diabaikan. Gerbang rilis (`GerbangPeriode`, WIB tetap, tanpa zonasi) di daftar, status, buka, cetak.
 * - Daftar & status hanya metadata; **nominal hanya di respons buka dan PDF** (§7.3.1).
 * - Buka: satu transaksi — token baru (hash + terenkripsi), snapshot uang makan N−1 (§7.3.7, hanya bila
 *   `uang_makan_periode` NULL), UPDATE bersyarat `viewed_at IS NULL` (cek 1 baris, balapan → 422 `slip_sudah_dibuka`),
 *   riwayat token + snapshot verifikasi, audit `update` tanpa IP/token/nominal.
 * - PDF pegawai hanya bila dibuka (`StatusSlip::bolehCetakPegawai`) dan lolos gerbang.
 */
final class SlipGajiService
{
    public const PESAN_TIDAK_ADA = 'Slip gaji periode %s belum tersedia.';

    private readonly Presensi $config;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    private readonly TokenSlipService $token;

    /**
     * @param (Closure(): DateTimeInterface)|null $jam       instan sekarang (bawaan jam server); disuntik test
     * @param (Closure(int): void)|null           $kaitBuka  seam test konkurensi buka (AC-D11.4): dipanggil di transaksi
     *                                                         sebelum UPDATE bersyarat, dengan id slip
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        ?Presensi $config = null,
        ?Closure $jam = null,
        ?TokenSlipService $token = null,
        private readonly ?UangMakanSlipInterface $uangMakan = null,
        private readonly ?PdfSlipGaji $pdf = null,
        private readonly ?Closure $kaitBuka = null,
    ) {
        $this->config = $config ?? config(Presensi::class);
        $this->jam    = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->token  = $token ?? new TokenSlipService();
    }

    /**
     * `GET slip-gaji/saya` — periode tersedia (Januari 2024 → terbaru) + status per periode, tanpa nominal.
     *
     * @return array{items: list<array<string, mixed>>, periode_terbaru: string|null}
     */
    public function daftar(AuthContext $auth): array
    {
        $nip     = $this->nip($auth);
        $periode = GerbangPeriode::daftarTersedia(($this->jam)());
        $baris   = [];

        foreach ($this->koneksi()->table('gaji_pegawai')->select('bulan, tahun, viewed_at')->where('nip', $nip)->get()->getResultArray() as $r) {
            $baris[sprintf('%04d-%02d', (int) $r['tahun'], (int) $r['bulan'])] = $r;
        }

        $items = [];

        foreach (array_reverse($periode) as $p) {
            $r       = $baris[$p->kunci()] ?? null;
            $items[] = ['periode' => $p->kunci(), 'label' => $p->label(), 'ada_data' => $r !== null, 'dibuka' => $r !== null && $r['viewed_at'] !== null, ...SlipGajiData::waktuDibuka($r ?? [])];
        }

        $terbaru = $periode === [] ? null : $periode[count($periode) - 1]->kunci();

        return ['items' => $items, 'periode_terbaru' => $terbaru];
    }

    /**
     * `GET slip-gaji/saya/{periode}` — metadata saja.
     *
     * @return array<string, mixed>
     */
    public function status(AuthContext $auth, string $periodeTeks): array
    {
        $nip     = $this->nip($auth);
        $periode = $this->periode($periodeTeks);
        $row     = $this->data()->barisPeriode($nip, $periode) ?? throw $this->tidakAda($periode);

        return ['periode' => $periode->kunci(), 'label' => $periode->label(), 'ada_data' => true, 'dibuka' => $row['viewed_at'] !== null, ...SlipGajiData::waktuDibuka($row)];
    }

    /**
     * `POST slip-gaji/saya/{periode}/buka` — atomik; respons memuat nominal.
     *
     * @return array<string, mixed>
     */
    public function buka(AuthContext $auth, string $periodeTeks, string $ip): array
    {
        $nip     = $this->nip($auth);
        $periode = $this->periode($periodeTeks);
        $db      = $this->koneksi();
        $data    = $this->data();
        $row     = $data->barisPeriode($nip, $periode) ?? throw $this->tidakAda($periode);
        $this->gagal(StatusSlip::periksaBuka($row['viewed_at']));

        $sekarang = gmdate('Y-m-d H:i:s', (($this->jam)())->getTimestamp());
        $token    = $this->token->terbit();
        $ubah     = ['viewed_at' => $sekarang, 'viewed_ip' => $ip, 'qr_token' => $token['hash'], 'qr_token_enkripsi' => $token['enkripsi']];
        $ubah += $this->snapshotUangMakan($row, $nip, $periode);
        $pegawai = $data->pegawai($nip);

        $db->transBegin();

        try {
            if ($this->kaitBuka !== null) {
                ($this->kaitBuka)((int) $row['id']);
            }

            $db->table('gaji_pegawai')->where('id', (int) $row['id'])->where('viewed_at', null)->update($ubah);

            if ($db->affectedRows() !== 1) {
                throw new SlipSudahDibuka();
            }

            $ok = $db->table('gaji_pegawai_qr_history')->insert([
                'id_gaji_pegawai' => (int) $row['id'], 'qr_token' => $token['hash'], 'viewed_at' => $sekarang, 'viewed_ip' => $ip,
                'created_at'      => $sekarang, 'snapshot_satker' => $pegawai['satker_nama'] ?? null,
                'snapshot_nama'   => self::namaTersamar($pegawai['nama'] ?? null),
            ]);

            $audit = (new AuditLogModel($db))->record('gaji_pegawai', (string) $row['id'], AuditLogModel::EVENT_UPDATE, ['viewed_at' => null], [
                'viewed_at' => $sekarang, 'uang_makan_periode' => $ubah['uang_makan_periode'] ?? $row['uang_makan_periode'],
            ], $auth->idPengguna(), $auth->nip());

            if ($ok === false || $audit <= 0 || $db->transStatus() === false) {
                throw new RuntimeException('Buka slip gagal.');
            }

            $db->transCommit();
        } catch (SlipSudahDibuka) {
            $this->rollback($db);
            $this->gagal(StatusSlip::periksaBuka($sekarang));
        } catch (Throwable $e) {
            $this->rollback($db);

            throw $e;
        }

        $baru = $data->barisId((int) $row['id']) ?? throw new RuntimeException('Slip hilang setelah dibuka.');

        return ['periode' => $periode->kunci(), 'label' => $periode->label(), ...SlipGajiData::waktuDibuka($baru), ...SlipGajiData::nominal($baru)];
    }

    /**
     * `GET slip-gaji/saya/{periode}/pdf` — hanya bila dibuka.
     *
     * @return array{isi: string, nama: string}
     */
    public function pdf(AuthContext $auth, string $periodeTeks): array
    {
        $nip     = $this->nip($auth);
        $periode = $this->periode($periodeTeks);
        $row     = $this->data()->barisPeriode($nip, $periode) ?? throw $this->tidakAda($periode);

        if (! StatusSlip::bolehCetakPegawai($row['viewed_at'])) {
            throw new ApiException('Slip gaji belum dibuka. Buka slip terlebih dahulu sebelum mencetak.', 422, ['periode' => ['Slip gaji belum dibuka.']], kode: KodeGalatSlip::SLIP_BELUM_DIBUKA);
        }

        return $this->data()->pdf($row, ($this->jam)());
    }

    /**
     * Nama tersamar verifikasi (Q5A-08): tanpa gelar, setiap kata → huruf pertama kapital + `***`.
     */
    public static function namaTersamar(?string $nama): ?string
    {
        $kata = preg_split('/\s+/u', trim((string) $nama), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($kata === []) {
            return null;
        }

        return implode(' ', array_map(static fn (string $k): string => mb_strtoupper(mb_substr($k, 0, 1)) . '***', $kata));
    }

    /**
     * Snapshot uang makan N−1 (§7.3.7, aturan [K `Lsl_gaji.php:157-205`]) — kolom yang perlu ditulis (bisa kosong).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function snapshotUangMakan(array $row, string $nip, PeriodeSlip $periode): array
    {
        if ($row['uang_makan_periode'] !== null) {
            return [];
        }

        $kehadiran = $periode->sebelumnya();
        $hasil     = $this->sumberUangMakan()->hitung($nip, $kehadiran);

        if ($hasil === null) {
            return [];
        }

        $lama = NominalGaji::dariDesimal((string) $row['uang_makan']);

        if ($hasil->totalSen <= 0 && $lama > 0) {
            log_message('warning', 'Snapshot uang makan slip {id}: hasil 0 sedangkan nilai lama > 0; nilai lama dipertahankan.', ['id' => $row['id']]);

            return [];
        }

        return [
            'uang_makan'         => NominalGaji::keDesimal($hasil->totalSen),
            'uang_makan_hari'    => $hasil->hari,
            'uang_makan_periode' => $kehadiran->bulan . '-' . $kehadiran->tahun,
        ];
    }

    private function sumberUangMakan(): UangMakanSlipInterface
    {
        if ($this->uangMakan !== null) {
            return $this->uangMakan;
        }

        $kelas = $this->config->sumberUangMakan;

        if (! class_exists($kelas) || ! is_subclass_of($kelas, UangMakanSlipInterface::class)) {
            throw new RuntimeException('Config\Presensi::$sumberUangMakan bukan implementasi UangMakanSlipInterface.');
        }

        return new $kelas();
    }

    private function periode(string $teks): PeriodeSlip
    {
        $hasil = PeriodeSlip::dariTeks($teks);
        $this->gagal($hasil);
        /** @var PeriodeSlip $periode */
        $periode = $hasil->nilai;
        $this->gagal(GerbangPeriode::periksa($periode, ($this->jam)()));

        return $periode;
    }

    private function gagal(\App\Libraries\Presensi\SlipGaji\HasilSlip $hasil): void
    {
        if (! $hasil->valid) {
            throw new ApiException((string) $hasil->pesan, 422, ['periode' => [(string) $hasil->pesan]], kode: $hasil->kode);
        }
    }

    private function tidakAda(PeriodeSlip $periode): ApiException
    {
        return new ApiException(sprintf(self::PESAN_TIDAK_ADA, $periode->label()), 404, kode: KodeGalatSlip::SLIP_TIDAK_DITEMUKAN);
    }

    private function nip(AuthContext $auth): string
    {
        $nip  = $auth->nip();
        $role = $auth->role();

        if ($role === null || ! AksesSlip::bolehMelihatMilikSendiri($role) || $nip === null || $nip === '') {
            throw new ForbiddenException('Slip gaji hanya dapat dilihat pegawai pemiliknya.');
        }

        return $nip;
    }

    private function data(): SlipGajiData
    {
        return new SlipGajiData($this->koneksi(), $this->config, $this->token, $this->pdf);
    }

    private function rollback(BaseConnection $db): void
    {
        $db->transRollback();
        $db->resetTransStatus();
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
