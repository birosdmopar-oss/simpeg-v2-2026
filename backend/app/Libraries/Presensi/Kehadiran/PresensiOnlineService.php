<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Kehadiran;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\ListQuery;
use App\Libraries\Presensi\Geo\HasilGeofence;
use App\Libraries\Presensi\Geo\Koordinat;
use App\Libraries\Presensi\Geo\LokasiGeofence;
use App\Libraries\Presensi\Geo\PenilaianGeofence;
use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Presensi;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * D-02 presensi online + geofence server (PRD 5A §7.5, QASMTASK-077) — port `po_in`/`po_out` (`Local.php:1737-2048`) [K]
 * dengan geofence server [V2] (F5-9, F5-10).
 *
 * - Pelaku role 2/6/7 dengan NIP (selain itu 403). Tidak menolak cuti/TB/konket/libur (F5-13, PO-7 [K]).
 * - Waktu: jam server Asia/Jakarta + zonasi satker ({@see ZonaSatker}); waktu klien diabaikan. "Sekarang" disuntik test.
 * - Urutan cek: role → jendela → (masuk) sudah terekam → koordinat & geofence → simpan (§7.5.5).
 * - Geofence: aturan lokasi dinamis ({@see ResolverLokasiPresensi}) + {@see PenilaianGeofence} (jarak ≤ radius, salah
 *   satu lokasi cukup); tidak tercakup → tanpa syarat koordinat. `cek-lokasi` memakai penilaian yang sama, tanpa menulis.
 * - Tulis satu transaksi, baris `(nip, tgl)` dikunci `FOR UPDATE`; masuk first-wins, pulang last-wins; 1062 (dua permintaan
 *   bersamaan) → baca ulang dan terapkan aturan yang sama (tidak pernah 500). `sumber` 1, tetap 2 bila baris manual.
 *   Audit `create`/`update` `dh_online` `{nip}:{tgl}` di transaksi yang sama (§8.1; PK komposit → audit manual).
 */
final class PresensiOnlineService
{
    public const SUMBER_ONLINE = 1;

    public const SUMBER_MANUAL = 2;

    public const PESAN_KOORDINAT_WAJIB = 'Lokasi perangkat belum terbaca. Aktifkan GPS dan izinkan akses lokasi, lalu coba lagi.';

    public const PESAN_KOORDINAT_TIDAK_SAH = 'Koordinat tidak valid.';

    public const PESAN_LOKASI_TIDAK_VALID = 'Lokasi presensi untuk Anda belum dikonfigurasi dengan benar. Hubungi admin.';

    private const ER_DUP_ENTRY = 1062;

    private readonly Presensi $config;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeInterface)|null   $jam              instan sekarang (bawaan jam server); disuntik test
     * @param (Closure(string, string): void)|null $kaitSebelumTulis seam test konkurensi (AC-D02.10): dipanggil setelah
     *                                                                 pemeriksaan awal, tepat sebelum transaksi tulis
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        ?Presensi $config = null,
        ?Closure $jam = null,
        private readonly ?Closure $kaitSebelumTulis = null,
    ) {
        $this->config = $config ?? config(Presensi::class);
        $this->jam    = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * `GET presensi/saya/hari-ini`.
     *
     * @return array<string, mixed>
     */
    public function hariIni(AuthContext $auth): array
    {
        $nip    = $this->nipPegawai($auth);
        $db     = $this->koneksi();
        $zonasi = (new ZonaSatker($db))->zonasi($nip);
        $lokal  = ZonaSatker::waktuLokal(($this->jam)(), $zonasi);
        $tgl    = $lokal->format('Y-m-d');
        $jam    = $lokal->format('H:i:s');
        $row    = $this->baris($db, $nip, $tgl);
        $aturan = (new ResolverLokasiPresensi($db))->untuk($nip, $lokal);

        return [
            'tanggal_lokal' => $tgl,
            'jam_lokal'     => $jam,
            'zonasi_menit'  => $zonasi,
            'jendela'       => [
                'masuk'  => [$this->config->jamMasukMulai, $this->config->jamMasukSelesai],
                'pulang' => [$this->config->jamPulangMulai, $this->config->jamPulangSelesai],
            ],
            'boleh_masuk'    => $this->dalamJendela($jam, true) && ($row === null || $row['jam_masuk'] === null),
            'boleh_pulang'   => $this->dalamJendela($jam, false),
            'presensi'       => $row === null ? null : ['jam_masuk' => $row['jam_masuk'], 'jam_pulang' => $row['jam_pulang']],
            'wajib_geofence' => $aturan['tercakup'],
            'lokasi'         => array_map(static fn (LokasiGeofence $l): array => [
                'id_lokasi_presensi' => $l->id,
                'nama_lokasi'        => $l->nama,
                'latitude'           => $l->titik->latBulat(),
                'longitude'          => $l->titik->lngBulat(),
                'radius'             => $l->radius,
            ], $aturan['lokasi']),
        ];
    }

    /**
     * `POST presensi/cek-lokasi` — putusan geofence yang sama dengan rekam, tanpa menulis apa pun.
     *
     * @param array<string, mixed> $body
     *
     * @return array{wajib_geofence: bool, dalam_radius: bool, lokasi_terdekat: array<string, mixed>|null}
     */
    public function cekLokasi(AuthContext $auth, array $body): array
    {
        $nip                      = $this->nipPegawai($auth);
        $db                       = $this->koneksi();
        $lokal                    = ZonaSatker::waktuLokal(($this->jam)(), (new ZonaSatker($db))->zonasi($nip));
        [$wajib, $posisi, $hasil] = $this->nilaiLokasi($db, $nip, $lokal, $body);

        return [
            'wajib_geofence'  => $wajib,
            'dalam_radius'    => ! $wajib || ($hasil?->dalamRadius() ?? false),
            'lokasi_terdekat' => $hasil?->terdekat === null ? null : [
                'id'      => $hasil->terdekat->id,
                'nama'    => $hasil->terdekat->nama,
                'radius'  => $hasil->terdekat->radius,
                'jarak_m' => $hasil->jarakTerdekatMeter(),
            ],
        ];
    }

    /**
     * `POST presensi/masuk` → [201|200, data].
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function masuk(AuthContext $auth, array $body): array
    {
        return $this->rekam($auth, $body, true);
    }

    /**
     * `POST presensi/pulang` → [201|200, data].
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function pulang(AuthContext $auth, array $body): array
    {
        return $this->rekam($auth, $body, false);
    }

    /**
     * `GET presensi/saya?bulan=YYYY-MM&page=&per_page=` — riwayat pribadi, `tgl` turun; bawaan bulan lokal berjalan.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function riwayatSaya(AuthContext $auth, array $query): array
    {
        $nip   = $this->nipPegawai($auth);
        $q     = ListQuery::from($query, ['bulan', 'page', 'per_page']);
        $db    = $this->koneksi();
        $bulan = $q->string('bulan');

        if ($bulan === '') {
            $bulan = ZonaSatker::waktuLokal(($this->jam)(), (new ZonaSatker($db))->zonasi($nip))->format('Y-m');
        } elseif (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $bulan) !== 1) {
            throw ValidationException::forField('bulan', 'Bulan harus berformat YYYY-MM.');
        }

        $page    = $q->page();
        $perPage = $q->perPage(20, 100);
        $awal    = $bulan . '-01';
        $akhir   = (new DateTimeImmutable($awal))->modify('last day of this month')->format('Y-m-d');
        $builder = static fn (BaseConnection $db) => $db->table('dh_online')->where('nip', $nip)->where('tgl >=', $awal)->where('tgl <=', $akhir);
        $total   = $builder($db)->countAllResults();
        $items   = $builder($db)->select('tgl, jam_masuk, jam_pulang, sumber')->orderBy('tgl', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['bulan' => $bulan, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function rekam(AuthContext $auth, array $body, bool $masuk): array
    {
        $nip   = $this->nipPegawai($auth);
        $db    = $this->koneksi();
        $lokal = ZonaSatker::waktuLokal(($this->jam)(), (new ZonaSatker($db))->zonasi($nip));
        $tgl   = $lokal->format('Y-m-d');
        $jam   = $lokal->format('H:i:s');

        if (! $this->dalamJendela($jam, $masuk)) {
            throw new ApiException(
                $masuk ? 'Presensi masuk hanya dapat direkam pukul 04:00–12:30 waktu setempat.' : 'Presensi pulang hanya dapat direkam pukul 12:31–23:59 waktu setempat.',
                422,
                ['jam' => [$masuk ? 'Presensi masuk hanya dapat direkam pukul 04:00–12:30 waktu setempat.' : 'Presensi pulang hanya dapat direkam pukul 12:31–23:59 waktu setempat.']],
                kode: KodeGalatPresensi::DI_LUAR_JENDELA,
            );
        }

        if ($masuk) {
            $this->tolakBilaSudahMasuk($this->baris($db, $nip, $tgl), $tgl);
        }

        [$wajib, $posisi, $hasil] = $this->nilaiLokasi($db, $nip, $lokal, $body);

        if ($wajib && ($hasil === null || ! $hasil->dalamRadius())) {
            $t = $hasil?->terdekat;
            $p = $t === null ? self::PESAN_LOKASI_TIDAK_VALID
                : sprintf('Anda berada di luar radius lokasi presensi. Lokasi terdekat: %s (jarak ±%d m, radius %s m).', $t->nama, (int) $hasil->jarakTerdekatMeter(), self::angka($t->radius));

            throw new ApiException($p, 422, ['lokasi' => [$p]], kode: KodeGalatPresensi::DI_LUAR_RADIUS);
        }

        $akurasi  = $this->akurasi($body);
        $pengesah = $wajib ? $hasil->pengesah : null;
        $data     = [
            ($masuk ? 'jam_masuk' : 'jam_pulang')             => $jam,
            ($masuk ? 'lat_masuk' : 'lat_pulang')             => $posisi?->latBulat(),
            ($masuk ? 'lng_masuk' : 'lng_pulang')             => $posisi?->lngBulat(),
            ($masuk ? 'akurasi_masuk' : 'akurasi_pulang')     => $akurasi === null ? null : number_format($akurasi, 2, '.', ''),
            ($masuk ? 'id_lokasi_masuk' : 'id_lokasi_pulang') => $pengesah?->id,
        ];
        $status = $this->tulis($db, $auth, $nip, $tgl, $masuk, $data);
        $row    = $this->baris($db, $nip, $tgl) ?? throw new RuntimeException('Baris presensi hilang setelah ditulis.');

        return [$status, [
            'tgl'                                             => $tgl,
            'jam_masuk'                                       => $row['jam_masuk'],
            'jam_pulang'                                      => $row['jam_pulang'],
            ($masuk ? 'id_lokasi_masuk' : 'id_lokasi_pulang') => $pengesah?->id,
            'jarak_m'                                         => $pengesah === null ? null : (int) round((float) $hasil->jarakPengesah),
        ]];
    }

    /**
     * Tulis satu transaksi dengan kunci baris; 1062 → ulang sekali dengan baris yang kini ada.
     *
     * @param array<string, mixed> $data kolom jam & bukti lokasi
     */
    private function tulis(BaseConnection $db, AuthContext $auth, string $nip, string $tgl, bool $masuk, array $data): int
    {
        if ($this->kaitSebelumTulis !== null) {
            ($this->kaitSebelumTulis)($nip, $tgl);
        }

        for ($percobaan = 0; ; $percobaan++) {
            $db->transBegin();

            try {
                $sql   = $db->table('dh_online')->where(['nip' => $nip, 'tgl' => $tgl])->getCompiledSelect() . ' FOR UPDATE';
                $lama  = $db->query($sql)->getRowArray();
                $waktu = gmdate('Y-m-d H:i:s');
                $id    = $auth->idPengguna();

                if ($lama === null) {
                    $baru = ['nip' => $nip, 'tgl' => $tgl, 'sumber' => self::SUMBER_ONLINE, 'created_at' => $waktu, 'created_by' => $id, ...$data];
                    $this->sisip($db, $baru);
                    $this->audit($db, $auth, $nip, $tgl, AuditLogModel::EVENT_CREATE, null, $baru);
                    $status = 201;
                } else {
                    if ($masuk) {
                        $this->tolakBilaSudahMasuk($lama, $tgl);
                    }

                    $ubah = [...$data, 'sumber' => (int) $lama['sumber'] === self::SUMBER_MANUAL ? self::SUMBER_MANUAL : self::SUMBER_ONLINE, 'updated_at' => $waktu, 'updated_by' => $id];
                    $db->table('dh_online')->where(['nip' => $nip, 'tgl' => $tgl])->update($ubah);
                    $this->audit($db, $auth, $nip, $tgl, AuditLogModel::EVENT_UPDATE, array_intersect_key($lama, $ubah), $ubah);
                    $status = 200;
                }

                if ($db->transStatus() === false) {
                    throw new RuntimeException('Transaksi presensi gagal.');
                }

                $db->transCommit();

                return $status;
            } catch (DuplikatPresensi) {
                $this->rollback($db);

                if ($percobaan >= 1) {
                    throw new RuntimeException('Presensi bentrok berulang.');
                }
            } catch (Throwable $e) {
                $this->rollback($db);

                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function sisip(BaseConnection $db, array $baris): void
    {
        try {
            $ok = $db->table('dh_online')->insert($baris);
        } catch (DatabaseException $e) {
            if ((int) $e->getCode() === self::ER_DUP_ENTRY) {
                throw new DuplikatPresensi();
            }

            throw $e;
        }

        if ($ok === false) {
            if ((int) ($db->error()['code'] ?? 0) === self::ER_DUP_ENTRY) {
                throw new DuplikatPresensi();
            }

            throw new RuntimeException('Tulis dh_online gagal.');
        }
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function audit(BaseConnection $db, AuthContext $auth, string $nip, string $tgl, string $event, ?array $before, ?array $after): void
    {
        $id = (new AuditLogModel($db))->record('dh_online', "{$nip}:{$tgl}", $event, $before, $after, $auth->idPengguna(), $auth->nip());

        if ($id <= 0) {
            throw new RuntimeException('Audit presensi gagal ditulis.');
        }
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function tolakBilaSudahMasuk(?array $row, string $tgl): void
    {
        if ($row === null || $row['jam_masuk'] === null) {
            return;
        }

        $tanggal = DateTimeImmutable::createFromFormat('!Y-m-d', $tgl);
        $pesan   = sprintf('Presensi masuk tanggal %s sudah terekam pukul %s.', $tanggal === false ? $tgl : $tanggal->format('d-m-Y'), substr((string) $row['jam_masuk'], 0, 5));

        throw new ApiException($pesan, 422, ['jam' => [$pesan]], kode: KodeGalatPresensi::SUDAH_PRESENSI_MASUK);
    }

    /**
     * Penilaian lokasi bersama rekam & cek-lokasi: [wajib geofence, posisi tersimpan, hasil penilaian].
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: bool, 1: Koordinat|null, 2: HasilGeofence|null}
     */
    private function nilaiLokasi(BaseConnection $db, string $nip, DateTimeInterface $lokal, array $body): array
    {
        $aturan  = (new ResolverLokasiPresensi($db))->untuk($nip, $lokal);
        $dikirim = ($body['latitude'] ?? null) !== null || ($body['longitude'] ?? null) !== null;
        $posisi  = $dikirim ? Koordinat::dariInput($body['latitude'] ?? null, $body['longitude'] ?? null) : null;

        if ($dikirim && $posisi === null) {
            throw new ApiException(self::PESAN_KOORDINAT_TIDAK_SAH, 422, ['latitude' => [self::PESAN_KOORDINAT_TIDAK_SAH]], kode: KodeGalatPresensi::KOORDINAT_TIDAK_SAH);
        }

        $this->akurasi($body);

        if (! $aturan['tercakup']) {
            return [false, $posisi, null];
        }

        if ($aturan['lokasi'] === []) {
            throw new ApiException(self::PESAN_LOKASI_TIDAK_VALID, 422, ['lokasi' => [self::PESAN_LOKASI_TIDAK_VALID]], kode: KodeGalatPresensi::LOKASI_TIDAK_VALID);
        }

        if ($posisi === null) {
            throw new ApiException(self::PESAN_KOORDINAT_WAJIB, 422, ['latitude' => [self::PESAN_KOORDINAT_WAJIB]], kode: KodeGalatPresensi::KOORDINAT_WAJIB);
        }

        return [true, $posisi, PenilaianGeofence::nilai($posisi, $aturan['lokasi'])];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function akurasi(array $body): ?float
    {
        $nilai = $body['akurasi'] ?? null;

        if ($nilai === null || $nilai === '') {
            return null;
        }

        return Koordinat::akurasiDariInput($nilai)
            ?? throw ValidationException::forField('akurasi', 'Akurasi harus angka 0 sampai 100000.');
    }

    private function dalamJendela(string $jam, bool $masuk): bool
    {
        return $masuk
            ? $jam >= $this->config->jamMasukMulai && $jam <= $this->config->jamMasukSelesai
            : $jam >= $this->config->jamPulangMulai && $jam <= $this->config->jamPulangSelesai;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(BaseConnection $db, string $nip, string $tgl): ?array
    {
        return $db->table('dh_online')->where(['nip' => $nip, 'tgl' => $tgl])->get()->getRowArray();
    }

    private function nipPegawai(AuthContext $auth): string
    {
        $nip = $auth->nip();

        if (! in_array($auth->role(), Role::UL_PEGAWAI, true) || $nip === null || $nip === '') {
            throw new ForbiddenException('Presensi online hanya untuk pegawai.');
        }

        return $nip;
    }

    private static function angka(float $nilai): string
    {
        return floor($nilai) === $nilai ? (string) (int) $nilai : rtrim(rtrim(number_format($nilai, 2, '.', ''), '0'), '.');
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
