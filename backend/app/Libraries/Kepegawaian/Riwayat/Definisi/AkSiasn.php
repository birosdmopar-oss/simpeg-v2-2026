<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\Database\BaseBuilder;

/**
 * B-15 Riwayat Angka Kredit SIASN (WS-1 M5 MAKE-008): data hasil tarik SIASN (`riwayat_ak_siasn`), snapshot
 * `pegawai_ak_siasn`.
 *
 * Sumber [K] = kode legacy (`application/`): `libraries/hr/rwy/L_ak.php` list_siasn() :931-940 (status 0/1,
 * `tgl_sk DESC`), form_siasn_ad()/update_siasn_ad() :958-1048 — satu-satunya ubah = "pairing" jabatan SIMPEG
 * (`id_jabatan`, jabatan JF tertentu), oleh role 1 (`controllers/hr/rwy/Ak.php:143`, `:217`); status tidak berubah. Tanpa
 * tambah/hapus/proses. Hak lihat sama dengan AK (termasuk role 2 dan 7 hanya bila JF tertentu). Aturan snapshot
 * `pegawai_ak_siasn` (tgl_sk terbaru) masih ⏳ di dok DBV-012 #6.
 */
final class AkSiasn extends RiwayatDefinisi
{
    /**
     * @var list<string>
     */
    private const KOLOM_SNAPSHOT = [
        'id_riwayat_ak_siasn', 'id_rw_siasn', 'id_pns', 'id_jabatan', 'id_pangkat', 'no_sk', 'tgl_sk', 'bulan_mulai_penilaian',
        'tahun_mulai_penilaian', 'bulan_selesai_penilaian', 'tahun_selesai_penilaian', 'kredit_utama_baru',
        'kredit_penunjang_baru', 'kredit_baru_total', 'id_rw_jabatan_siasn', 'nama_jabatan', 'is_angka_kredit_pertama',
        'is_integrasi', 'is_konversi', 'f_pak_siasn', 'f_pak', 'sumber', 'is_pemenuhan_kp', 'keterangan',
    ];

    public function jenis(): string
    {
        return 'ak-siasn';
    }

    public function label(): string
    {
        return 'Riwayat Angka Kredit SIASN';
    }

    public function tabel(): string
    {
        return 'riwayat_ak_siasn';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_ak_siasn';
    }

    public function fields(): array
    {
        return [new MasterField('id_jabatan', 'Jabatan SIMPEG', MasterField::TYPE_REF, required: true, entity: 'jabatan')];
    }

    public function izin(): array
    {
        return [
            'lihat' => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::PPPK],
            'ubah'  => [Role::SUPER_ADMIN],
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Admin;
    }

    /**
     * CHECK `status IN (0, 1, 2, 10)` — tanpa 3 Diproses.
     */
    public function pemetaanStatus(): array
    {
        return Karpeg::statusTanpaDiproses();
    }

    /**
     * Sama dengan AK: role 2 dan 7 hanya bila pegawai sasaran pejabat JF tertentu.
     */
    public function bolehAksiUntuk(AuthContext $auth, AksiRiwayat $aksi, string $nip): bool
    {
        return $aksi !== AksiRiwayat::Lihat || ! Ak::lihatHanyaJft($auth) || Ak::groupJabatan($nip) === Ak::GROUP_JFT;
    }

    public function saringDaftar(BaseBuilder $builder): void
    {
        $builder->whereIn($this->kolomStatus(), [StatusRiwayat::Menunggu->value, StatusRiwayat::Disetujui->value]);
    }

    public function urutanDaftar(): array
    {
        return ['tgl_sk' => 'DESC', $this->primaryKey() => 'DESC'];
    }

    public function snapshot(): array
    {
        return [new AturanSnapshot('pegawai_ak_siasn', self::KOLOM_SNAPSHOT, urutan: ['tgl_sk' => 'DESC', 'id_riwayat_ak_siasn' => 'DESC'])];
    }

    public function urutanTab(): int
    {
        return 86;
    }

    /**
     * Pilihan jabatan legacy hanya jabatan JF tertentu yang aktif (`L_ak.php:962`).
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        if (! array_key_exists('id_jabatan', $data)) {
            return [];
        }

        $jft = db_connect()->table('jabatan')->where('id_jabatan', $data['id_jabatan'])
            ->where('id_group_jabatan', Ak::GROUP_JFT)->where('status', 1)->countAllResults();

        return $jft > 0 ? [] : ['id_jabatan' => ['Pilih jabatan fungsional tertentu yang aktif.']];
    }
}
