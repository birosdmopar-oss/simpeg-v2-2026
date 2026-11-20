<?php

declare(strict_types=1);

namespace Tests\Database\Struktur;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\I18n\Time;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;
use Tests\Support\Ukur\PenghitungKueri;

/**
 * CR-166 — bagan kementerian memuat Es.II semua sekretaris/deputi dengan SATU kueri, bukan satu kueri per es1. Isi dan
 * urutan `list_es2` dikunci test pagar `StrukturPejabatParitasTest::testListEs2PerEs1IsiDanUrutan`; di sini jumlah kueri
 * dan kasus tepi pemilahan di PHP (unit es1 berbeda, satker tanpa unit). Data sintetis.
 *
 * @internal
 */
final class StrukturEs2SekaliMuatTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    private int $idPeriode;

    /**
     * @var array<string, int>
     */
    private array $jab = [];

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow('2026-10-13 10:00:00', 'Asia/Jakarta');

        $this->pastikan('group_jabatan', ['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1]);
        $this->pastikan('sub_group_jabatan', ['id_sub_group_jabatan' => 1, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon I', 'need_satker' => 1, 'status' => 1]);
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Uji', 'status' => 1]);
        $this->idPeriode = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testEs2DimuatSatuKueriUntukSemuaEs1(): void
    {
        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon II Uji', 'need_satker' => 1, 'status' => 1]);
        $es2Sub  = (int) $this->db->insertID();
        $unitSek = $this->buatUnit(['unit' => 'Sekretariat Uji']);
        $satSek  = $this->buatSatker($unitSek, ['satker' => 'Biro Uji']);
        $this->jabatanStruktur('sekretaris', 'Sekretaris Kementerian', $satSek, 1, 2, null, 1);
        $this->jabatanStruktur('sek_es2', 'Kepala Biro Uji', $satSek, $es2Sub, 3, 'sekretaris', 1);

        $satDep = [];

        foreach (['a', 'b', 'c'] as $i => $k) {
            $satDep[$k] = $this->buatSatker($this->buatUnit(['unit' => "Deputi {$k} Uji"]), ['satker' => "Direktorat {$k} Uji"]);
            $this->jabatanStruktur("dep_{$k}", "Deputi {$k} Uji", $satDep[$k], 1, 2, null, 2 + $i);
            $this->jabatanStruktur("dir_{$k}2", "Direktur {$k} Dua", $satDep[$k], $es2Sub, 3, "dep_{$k}", 2);
            $this->jabatanStruktur("dir_{$k}1", "Direktur {$k} Satu", $satDep[$k], $es2Sub, 3, "dep_{$k}", 1);
        }

        // Atasan deputi a tetapi satkernya di unit deputi b → bukan es2 deputi a, dan bukan es2 deputi b (atasan lain).
        $this->jabatanStruktur('dir_silang', 'Direktur Silang', $satDep['b'], $es2Sub, 3, 'dep_a', 0);
        // Satker tanpa unit (id_unit NULL) tidak pernah cocok dengan unit es1.
        $satTanpaUnit = $this->buatSatker($unitSek, ['satker' => 'Satker Tanpa Unit']);
        $this->db->table('satker')->where('id_satker', $satTanpaUnit)->update(['id_unit' => null]);
        $this->jabatanStruktur('dir_tanpa_unit', 'Direktur Tanpa Unit', $satTanpaUnit, $es2Sub, 3, 'dep_c', 0);

        $hitung = new PenghitungKueri();
        $hitung->mulai();

        try {
            $d = (new StrukturService())->pohon($this->admin())['data'];
        } finally {
            $hitung->selesai();
        }

        $this->assertSame(['Kepala Biro Uji'], array_column($d['sekretaris']['list_es2'], 'jabatan'));
        $this->assertSame(['Deputi a Uji', 'Deputi b Uji', 'Deputi c Uji'], array_column($d['deputi'], 'jabatan'));

        foreach (['a', 'b', 'c'] as $i => $k) {
            $this->assertSame(["Direktur {$k} Satu", "Direktur {$k} Dua"], array_column($d['deputi'][$i]['list_es2'], 'jabatan'), "deputi {$k}");
            $this->assertSame(["Direktorat {$k} Uji", "Direktorat {$k} Uji"], array_column($d['deputi'][$i]['list_es2'], 'satker'));
        }

        $es2 = array_values(array_filter($hitung->kueri(), static fn (string $sql): bool => (bool) preg_match('/level_struktur`?\s*=\s*3\b/', $sql)));
        $this->assertCount(1, $es2, "satu kueri Es.II untuk empat es1:\n" . implode("\n", $es2));
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function pastikan(string $tabel, array $baris): void
    {
        $pk = (string) array_key_first($baris);

        if ($this->db->table($tabel)->where($pk, $baris[$pk])->countAllResults() === 0) {
            $this->db->table($tabel)->insert($baris);
        }
    }

    private function jabatanStruktur(string $kunci, string $nama, int $satker, int $sub, int $level, ?string $atasan, int $urutan): void
    {
        $this->db->table('jabatan')->insert(['id_group_jabatan' => 1, 'id_sub_group_jabatan' => $sub, 'id_satker' => $satker, 'jabatan' => $nama, 'status' => 1]);
        $this->jab[$kunci] = (int) $this->db->insertID();
        $this->db->table('struktur_jabatan')->insert([
            'id_periode_struktur_jabatan' => $this->idPeriode, 'id_jabatan' => $this->jab[$kunci], 'id_jabatan_atasan' => $atasan === null ? null : $this->jab[$atasan],
            'periode_struktur_jabatan'    => 'Periode Uji', 'jabatan' => $nama, 'level_struktur' => $level, 'urutan' => $urutan, 'status' => 1,
        ]);
    }
}
