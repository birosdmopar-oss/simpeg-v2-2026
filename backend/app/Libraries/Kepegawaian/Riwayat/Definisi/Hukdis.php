<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\HukumanDisiplin;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\AturanSnapshot;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;

/**
 * B-14 Riwayat Hukuman Disiplin (WS-1 M3 MAKE-006).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Hukdis.php`, `libraries/hr/rwy/L_hukdis.php`
 * (`validate_param()` :369-461, `set_param()` :463-497, `list()` :13, unggah :84-93), form
 * `views/hr/employee/rwy/hukdis/form.php`. Snapshot `pegawai_hukdis`: dok DBV-012 §5.1 (status 1, `MAX(tmtsk)`).
 *
 * - Hak akses: Matriks v2 `hr/rwy/hukdis/*` = role 1, 3 (legacy hanya role 1). Tanpa lihat pegawai (Matriks §5).
 * - Status selalu 1 (DBV #10; legacy menulis `status = '1'` saat tambah dan ubah, `Hukdis.php:73`, :130). Legacy tidak
 *   punya alur proses (setujui/tolak), jadi izin `proses` kosong.
 * - [V2] SRS-FR-027 (DoD B-14, keputusan #10 / K5b): `akhir_hukdis` yang dikosongkan dihitung otomatis = TMT + masa
 *   sanksi jenis (`jenis_hukdis.masa_sanksi_bulan`, `HukumanDisiplin::hitungTanggalBerakhir`), dan `masa_hukuman` yang
 *   dikosongkan diisi teks masanya (`HukumanDisiplin::masaTeks`). Jenis tanpa masa → keduanya wajib diisi manual
 *   (setara legacy yang mewajibkan keduanya, `validate_param()` :400-407). Akhir yang diisi manual harus setelah TMT.
 */
final class Hukdis extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran SK hukdis (konstanta legacy `ARSIP_RWY['hukdis']`, `config/constants.php:197`).
     */
    public const LAMPIRAN_SK_HUKDIS = 7;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    public function jenis(): string
    {
        return 'hukdis';
    }

    public function label(): string
    {
        return 'Riwayat Hukuman Disiplin';
    }

    public function tabel(): string
    {
        return 'riwayat_hukdis';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_hukdis';
    }

    /**
     * Field form legacy (`hukdis/form.php`); wajib = `validate_param()` :376-415 kecuali masa & akhir (lihat kelas).
     * Nama tingkat/jenis/golongan diturunkan server (beforeSave).
     */
    public function fields(): array
    {
        return [
            new MasterField('id_tingkat_hukdis', 'Tingkat Hukuman Disiplin', MasterField::TYPE_REF, required: true, entity: 'tingkat-hukdis'),
            new MasterField('id_jenis_hukdis', 'Jenis Hukuman Disiplin', MasterField::TYPE_REF, required: true, entity: 'jenis-hukdis', dependsOn: 'id_tingkat_hukdis'),
            new MasterField('id_pangkat', 'Golongan', MasterField::TYPE_REF, required: true, entity: 'pangkat'),
            new MasterField('tmtsk', 'TMT SK', MasterField::TYPE_DATE, required: true),
            new MasterField('no_sk', 'Nomor SK', required: true, maxBytes: 100),
            new MasterField('tgl_sk', 'Tanggal SK', MasterField::TYPE_DATE, required: true),
            new MasterField('masa_hukuman', 'Masa Hukuman', MasterField::TYPE_TEXTAREA, maxBytes: 255),
            new MasterField('akhir_hukdis', 'Tanggal Berakhir', MasterField::TYPE_DATE),
            new MasterField('aturan_dilanggar', 'Aturan yang Dilanggar', MasterField::TYPE_TEXTAREA, required: true, maxBytes: 255),
            new MasterField('alasan_hukuman', 'Alasan Hukuman', MasterField::TYPE_TEXTAREA, required: true, maxBytes: 255),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255),
        ];
    }

    public function izin(): array
    {
        $admin = [Role::SUPER_ADMIN, Role::ADMIN_SATKER];

        return [
            'lihat'  => $admin,
            'tambah' => $admin,
            'ubah'   => $admin,
            'hapus'  => $admin,
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Admin;
    }

    /**
     * Legacy `Hukdis.php:130`: ubah selalu menulis status 1 (baris lama ber-status 0 sisa impor ikut menjadi 1).
     */
    public function statusSetelahUbah(int $role, ?StatusRiwayat $lama): StatusRiwayat
    {
        return StatusRiwayat::Disetujui;
    }

    /**
     * Legacy `list()` :13 `ORDER BY status ASC, tmtsk ASC`.
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tmtsk' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    public function snapshot(): array
    {
        return [
            new AturanSnapshot('pegawai_hukdis', [
                'id_riwayat_hukdis', 'id_tingkat_hukdis', 'id_jenis_hukdis', 'id_pangkat', 'tmtsk', 'tgl_sk', 'no_sk',
                'tingkat_hukdis', 'jenis_hukdis', 'gol', 'ruang', 'gol_ruang', 'pangkat', 'masa_hukuman', 'akhir_hukdis',
                'aturan_dilanggar', 'alasan_hukuman', 'keterangan',
            ], urutan: ['tmtsk' => 'DESC']),
        ];
    }

    /**
     * Legacy: SK wajib saat tambah, dan saat ubah bila baris belum punya lampiran (`validate_param()` :418-423,
     * :438-445); 5 MB (:84), jpg|jpeg|png|gif|pdf (:92).
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_SK_HUKDIS, true, 5, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 90;
    }

    /**
     * - TMT tidak boleh kembar (status selain 2/10) — legacy :426-433, :448-455.
     * - Masa & akhir: wajib diisi bila tidak dapat dihitung dari masa sanksi jenis; akhir manual harus setelah TMT.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $nip    = (string) $nilai[$this->kolomNip()];
        $errors = [];

        // Cek duplikat atas NILAI AKHIR (pola Kp, MAKE-005): ubah selalu menulis status 1, jadi baris lama berstatus 0/2
        // yang diubah ikut diperiksa walau tmtsk tidak dikirim.
        if (($nilai['tmtsk'] ?? '') !== '') {
            $kembar = db_connect()->table($this->tabel())
                ->where($this->kolomNip(), $nip)
                ->where('tmtsk', $nilai['tmtsk'])
                ->whereNotIn($this->kolomStatus(), [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value]);

            if ($lama !== null) {
                $kembar->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
            }

            if ($kembar->countAllResults() > 0) {
                $errors['tmtsk'] = ['Riwayat hukuman disiplin dengan TMT SK ini sudah terdaftar. Gunakan TMT SK yang berbeda.'];
            }
        }

        $tmt   = (string) $nilai['tmtsk'];
        $akhir = $this->kosong($nilai['akhir_hukdis'] ?? null) ? null : (string) $nilai['akhir_hukdis'];
        $masa  = $this->masaSanksi($nilai['id_jenis_hukdis'] ?? null);

        if ($akhir !== null) {
            $hasil = HukumanDisiplin::validasiTanggalBerakhir($tmt, $akhir);

            if (! $hasil->valid) {
                $errors['akhir_hukdis'] = [(string) $hasil->pesan];
            }
        } elseif ($masa === null) {
            $errors['akhir_hukdis'] = ['Tanggal berakhir wajib diisi: jenis hukuman disiplin ini tidak memiliki masa sanksi.'];
        }

        if ($this->kosong($nilai['masa_hukuman'] ?? null) && $masa === null) {
            $errors['masa_hukuman'] = ['Masa hukuman wajib diisi: jenis hukuman disiplin ini tidak memiliki masa sanksi.'];
        }

        return $errors;
    }

    /**
     * Salin nama master (legacy `set_param()` :475-490) dan isi masa/akhir yang dikosongkan dari masa sanksi jenis.
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $db = db_connect();

        if (array_key_exists('id_tingkat_hukdis', $data)) {
            $data['tingkat_hukdis'] = $db->table('tingkat_hukdis')->select('tingkat_hukdis')->where('id_tingkat_hukdis', $data['id_tingkat_hukdis'])->get()->getRow()->tingkat_hukdis ?? null;
        }

        if (array_key_exists('id_jenis_hukdis', $data)) {
            $data['jenis_hukdis'] = $db->table('jenis_hukdis')->select('jenis_hukdis')->where('id_jenis_hukdis', $data['id_jenis_hukdis'])->get()->getRow()->jenis_hukdis ?? null;
        }

        if (array_key_exists('id_pangkat', $data)) {
            $pangkat = $db->table('pangkat')->select('gol, ruang, gol_ruang, pangkat')->where('id_pangkat', $data['id_pangkat'])->get()->getRowArray();

            foreach (['gol', 'ruang', 'gol_ruang', 'pangkat'] as $kolom) {
                $data[$kolom] = $pangkat[$kolom] ?? null;
            }
        }

        $nilai = $data + ($lama ?? []);
        $masa  = $this->masaSanksi($nilai['id_jenis_hukdis'] ?? null);

        if ($masa !== null) {
            if ($this->kosong($nilai['akhir_hukdis'] ?? null)) {
                $data['akhir_hukdis'] = HukumanDisiplin::hitungTanggalBerakhir((string) $nilai['tmtsk'], null, $masa)->tanggal;
            }

            if ($this->kosong($nilai['masa_hukuman'] ?? null)) {
                $data['masa_hukuman'] = HukumanDisiplin::masaTeks($masa);
            }
        }

        return $data;
    }

    /**
     * Masa sanksi bawaan jenis (`jenis_hukdis.masa_sanksi_bulan`); null = tanpa masa atau jenis tidak ada.
     */
    private function masaSanksi(mixed $idJenis): ?int
    {
        if ($this->kosong($idJenis)) {
            return null;
        }

        $masa = db_connect()->table('jenis_hukdis')->select('masa_sanksi_bulan')->where('id_jenis_hukdis', $idJenis)->get()->getRow()->masa_sanksi_bulan ?? null;

        return $masa === null ? null : (int) $masa;
    }

    private function kosong(mixed $nilai): bool
    {
        return $nilai === null || trim((string) $nilai) === '';
    }
}
