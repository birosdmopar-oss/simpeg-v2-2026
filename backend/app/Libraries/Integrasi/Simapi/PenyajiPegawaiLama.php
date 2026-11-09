<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use App\Libraries\Integrasi\Stat\PenyajiStat;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * MAKE-076 (Q6-41) — data API SIMAPI lama `simapi/a_pegawai/{detail,pegawai_update}` [K `controllers/simapi/A_pegawai.php`].
 *
 * `detail($nip)` [K `:117-185`]: pegawai LEFT JOIN pengguna (nip), jenis_pegawai, pegawai_mutasi_jabatan; 11 kunci urutan legacy.
 * [V2]: `foto` = `PenyajiPegawai::urlFoto()` (Q6-39); `password` = hash yang SEDANG berlaku untuk akun ({@see AturanTriggerUpdate::hashBerlaku()},
 * Argon2id atau MD5 legacy — keputusan reviewer 09-11, opsi b); query builder ber-binding (legacy `addslashes`).
 *
 * `pegawaiUpdate($idKlien)` [K `:188-247`]: ≤100 baris `simapi_update` yang belum ada di `simapi_update_hist` klien itu, urut id
 * ASC, lalu semua ditandai (ack-on-read). [V2]: baca + tandai dalam SATU transaksi yang mengunci baris klien (`klien_layanan … FOR
 * UPDATE`, pola change_history A5a) sehingga panggilan serentak klien yang sama tidak menerima baris ganda dan klien berbeda tidak
 * saling menunggu; penanda `INSERT IGNORE`; galat → rollback dan tidak ada data terkirim (legacy tetap mengirim walau penanda gagal).
 * `last_update` disimpan UTC, dikirim WIB `Y-m-d H:i:s` (DBV-031 §3.4).
 */
final class PenyajiPegawaiLama
{
    public const BATAS = 100;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array<string, string|null>|null null = NIP tidak ada
     */
    public function detail(string $nip): ?array
    {
        $r = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.glr_awal, peg.nama, peg.glr_akhir, peg.status, peg.foto, jpeg.jenis_pegawai AS nama_jenis_pegawai, peng.username, peng.password, peng.password_legacy, peng.email, pmj.jabatan, pmj.unit, pmj.satker')
            ->join('pengguna peng', 'peng.nip = peg.nip', 'left')
            ->join('jenis_pegawai jpeg', 'jpeg.id_jenis_pegawai = peg.id_jenis_pegawai', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->where('peg.nip', $nip)
            ->limit(1)
            ->get()->getRowArray();

        if ($r === null) {
            return null;
        }

        $akunAda = $r['username'] !== null;

        return [
            'nip'           => Nilai::mentah($r['nip']),
            'nama_lengkap'  => PenyajiStat::namaGelar($r['glr_awal'], $r['nama'], $r['glr_akhir']),
            'foto'          => Nilai::teks($r['foto']) !== '' ? (new PenyajiPegawai($this->db))->urlFoto((string) $r['nip'], (string) $r['foto']) : '',
            'username'      => Nilai::mentah($r['username']),
            'password'      => $akunAda ? AturanTriggerUpdate::hashBerlaku($r) : null,
            'email'         => Nilai::mentah($r['email']),
            'jabatan'       => Nilai::mentah($r['jabatan']),
            'unit_kerja'    => Nilai::mentah($r['unit']),
            'satuan_kerja'  => Nilai::mentah($r['satker']),
            'jenis_pegawai' => Nilai::mentah($r['nama_jenis_pegawai']),
            'status'        => (string) $r['status'] === '1' ? 'Aktif' : 'Tidak Aktif',
        ];
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function pegawaiUpdate(int $idKlien): array
    {
        if ($this->db->transBegin() === false) {
            $this->db->resetTransStatus();

            throw new RuntimeException('Transaksi pegawai_update tidak dapat dimulai.');
        }

        try {
            $kunci = $this->db->table('klien_layanan')->select('id_klien')->where('id_klien', $idKlien)->getCompiledSelect() . ' FOR UPDATE';

            if ($this->db->query($kunci) === false) {
                throw new RuntimeException('Kunci klien pegawai_update gagal.', (int) ($this->db->error()['code'] ?? 0));
            }

            $hasil = $this->db->table('simapi_update su')
                ->select('su.id_simapi_update AS id, su.nip, su.last_action, su.last_update, su.nama_field, su.nilai_lama, su.nilai_baru')
                ->join('simapi_update_hist h', 'h.id_simapi_update = su.id_simapi_update AND h.id_klien = ' . $idKlien, 'left')
                ->where('h.id_simapi_update', null)
                ->orderBy('su.id_simapi_update', 'ASC')
                ->limit(self::BATAS)
                ->get();

            if ($hasil === false) {
                throw new RuntimeException('Baca pegawai_update gagal.', (int) ($this->db->error()['code'] ?? 0));
            }

            $rows = $hasil->getResultArray();

            if ($rows !== []) {
                $sekarang = gmdate('Y-m-d H:i:s');
                $this->db->table('simapi_update_hist')->ignore(true)->insertBatch(array_map(static fn (array $r): array => [
                    'id_klien'         => $idKlien,
                    'id_simapi_update' => (int) $r['id'],
                    'created_at'       => $sekarang,
                ], $rows));
            }

            if ($this->db->transStatus() === false || $this->db->transCommit() === false) {
                throw new RuntimeException('pegawai_update gagal ditandai (transaksi).');
            }

            $this->db->resetTransStatus();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }

        return array_map(static fn (array $r): array => [
            'id'          => Nilai::mentah($r['id']),
            'nip'         => Nilai::mentah($r['nip']),
            'last_action' => Nilai::mentah($r['last_action']),
            'last_update' => self::keWib($r['last_update']),
            'nama_field'  => Nilai::mentah($r['nama_field']),
            'nilai_lama'  => Nilai::mentah($r['nilai_lama']),
            'nilai_baru'  => Nilai::mentah($r['nilai_baru']),
        ], $rows);
    }

    private static function keWib(mixed $utc): ?string
    {
        if ($utc === null || (string) $utc === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s');
    }
}
