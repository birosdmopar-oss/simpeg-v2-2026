<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Integrasi\Simapi\PencatatPerubahan;
use App\Models\Auth\PenggunaModel;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-060 A5b — pemancar `pegawai` (jd 1) dan `pengguna` lewat penulis nyata: akun pegawai baru (AccountProvisioner) →
 * baris "nip|email"; ubah pegawai (pola sesudahUbah) aktif → "Aktif", aktif → nonaktif → "Tidak Aktif"; akun tanpa email
 * berubah tidak dicatat.
 *
 * @internal
 */
final class SimapiPemancarPegawaiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    /**
     * @return list<array<string, mixed>>
     */
    private function ch(): array
    {
        return $this->db->table('simapi_ch')->where('id_simapi_jd', 1)->orderBy('id_simapi_ch')->get()->getResultArray();
    }

    public function testAkunPegawaiBaruDicatat(): void
    {
        $nip = $this->buatPegawai();
        $pengguna = new PenggunaModel();
        (new AccountProvisioner($pengguna, new PasswordVerifier($pengguna)))->provisionForPegawai(['nip' => $nip, 'nama' => 'Uji', 'email' => 'uji@contoh.test']);

        $ch = $this->ch();
        $this->assertCount(1, $ch);
        $this->assertSame([$nip, 'nip|email', $nip . '|uji@contoh.test'], [$ch[0]['nip'], $ch[0]['field_name'], $ch[0]['field_value']]);
    }

    public function testUbahPegawaiAktifDanNonaktif(): void
    {
        $nip  = $this->buatPegawai(['agama' => 'Islam', 'jenis_pegawai' => 'PNS']);
        $ubah = function (array $nilai) use ($nip): void {
            $lama = PencatatPerubahan::baca($this->db, 'pegawai', 'nip', $nip);
            $this->db->table('pegawai')->where('nip', $nip)->update($nilai);
            PencatatPerubahan::sesudahUbah($this->db, 'pegawai', 'nip', $nip, $lama);
        };

        $ubah(['nama' => 'Nama Baru']);
        $ubah(['status' => 2]);
        $ubah(['nama' => 'Lagi']);

        $nilai = array_map(static fn (array $r): array => explode('|', (string) $r['field_value']), $this->ch());
        $this->assertCount(2, $nilai, 'nonaktif → nonaktif tidak dicatat');
        $this->assertSame(['Nama Baru', 'Aktif'], [$nilai[0][1], $nilai[0][11]]);
        $this->assertSame('Tidak Aktif', $nilai[1][11]);
    }
}
