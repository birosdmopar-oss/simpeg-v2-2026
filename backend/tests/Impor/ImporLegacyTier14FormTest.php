<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Dashboard\Interaksi\FormDigital\SkemaForm;
use App\Libraries\Impor\AturanFormDigital;
use App\Libraries\Impor\AturanKonten;
use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-089 M2 — form digital tier 14: formBuilder → SkemaForm v2, jawaban → id v2 + kunci berkas
 * `form-digital/<id_form>/…`, `user_form_digital` dilengkapi dari `filled_form_digital` (Q7-20), anak form yang dibuang ikut
 * dibuang, ambigu tanpa keputusan gagal. Skema legacy D1 tiruan + data SINTETIS.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyTier14FormTest extends ImporLegacyTestCase
{
    private const NIP_A = '000000000000000001';

    private const NIP_B = '000000000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('pegawai')->insertBatch([
            ['nip' => self::NIP_A, 'nama' => 'Pegawai Uji A', 'tgl_lahir' => '1990-01-01', 'jenis_kelamin' => 1, 'status' => 1],
            ['nip' => self::NIP_B, 'nama' => 'Pegawai Uji B', 'tgl_lahir' => '1991-02-02', 'jenis_kelamin' => 2, 'status' => 1],
        ]);

        $l = "`{$this->lgc}`";

        foreach (['filled_form_digital', 'user_form_digital', 'form_digital'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$l}.`{$t}`");
        }

        $this->db->query("CREATE TABLE {$l}.`form_digital` (`id_form_digital` int NOT NULL AUTO_INCREMENT, `tgl_mulai` date NOT NULL, `tgl_akhir` date NOT NULL, `judul_form` varchar(255) NOT NULL, `form_digital` longtext NOT NULL, `target` tinyint NOT NULL DEFAULT 1, `id_unit_satker` varchar(20) DEFAULT NULL, `unit_satker` varchar(255) DEFAULT NULL, `target_uns` varchar(255) DEFAULT NULL, `target_uns_desc` mediumtext, `target_peg` mediumtext, `target_peg_desc` mediumtext, `excl_peg` mediumtext, `excl_peg_desc` mediumtext, `keterangan` tinytext, `order` int NOT NULL DEFAULT 1, `created_at` datetime NOT NULL, `created_by` int DEFAULT NULL, `updated_at` datetime DEFAULT NULL, `updated_by` int DEFAULT NULL, PRIMARY KEY (`id_form_digital`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->db->query("CREATE TABLE {$l}.`user_form_digital` (`nip` varchar(30) NOT NULL, `id_form_digital` int NOT NULL, PRIMARY KEY (`nip`,`id_form_digital`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->db->query("CREATE TABLE {$l}.`filled_form_digital` (`nip` varchar(30) NOT NULL, `id_form_digital` int NOT NULL, `response` longtext NOT NULL, `created_at` datetime NOT NULL, `created_by` int DEFAULT NULL, PRIMARY KEY (`nip`,`id_form_digital`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $sah = (string) json_encode([
            ['type' => 'text', 'name' => 'text-1', 'label' => 'Nama', 'required' => true],
            ['type' => 'radio-group', 'name' => 'radio-group-1', 'label' => 'Setuju?', 'values' => [['label' => 'Ya', 'value' => 'y'], ['label' => 'Tidak', 'value' => 't']]],
            ['type' => 'file', 'name' => 'file-1', 'label' => 'Lampiran'],
        ]);
        $rusak = (string) json_encode([['type' => 'starRating', 'name' => 's-1', 'label' => 'Bintang']]);
        $form  = static fn (int $id, string $json): array => [
            'id_form_digital' => $id, 'tgl_mulai' => '2026-01-01', 'tgl_akhir' => '2026-01-31', 'judul_form' => "Form {$id}",
            'form_digital'    => addslashes($json), 'target' => 3, 'target_uns' => addslashes('["0"]'), 'created_at' => '2026-01-01 08:00:00',
        ];
        $this->src->table('form_digital')->insertBatch([$form(1, $sah), $form(2, $rusak)]);
        $this->src->table('user_form_digital')->insertBatch([
            ['nip' => self::NIP_A, 'id_form_digital' => 1],
            ['nip' => self::NIP_B, 'id_form_digital' => 2],
        ]);
        $this->src->table('filled_form_digital')->insertBatch([
            ['nip' => self::NIP_B, 'id_form_digital' => 1, 'created_at' => '2026-01-05 10:00:00', 'response' => (string) json_encode([
                'text-1' => 'Budi é', 'radio-group-1' => 'y', 'file' => ['file-1' => 'assets/upload/fd/' . self::NIP_B . '/1_1234_20260105.pdf'],
            ])],
            ['nip' => self::NIP_A, 'id_form_digital' => 2, 'created_at' => '2026-01-05 10:00:00', 'response' => '{}'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['filled_form_digital', 'user_form_digital', 'form_digital'] as $t) {
            $this->db->table($t)->where('1 = 1', null, false)->delete();
        }

        $this->db->table('pegawai')->whereIn('nip', [self::NIP_A, self::NIP_B])->delete();

        parent::tearDown();
    }

    public function testFormTakBisaDikonversiTanpaKeputusanGagal(): void
    {
        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('form_digital: 1 baris ambigu belum diputuskan (' . AturanFormDigital::A_SKEMA . ': 1)');

        $this->jalankanImpor([14], dryRun: true);
    }

    public function testImporFormDigital(): void
    {
        $laporan = $this->jalankanImpor([14], k: Keputusan::dariArray(['baris' => ['form_digital.f7' => ['2' => AturanKonten::BUANG]]]));

        // form_digital: id legacy dipertahankan; skema v2 kanonik lolos normalisasi; JSON target bersih.
        $form = $this->db->table('form_digital')->get()->getResultArray();
        $this->assertSame([1], array_map('intval', array_column($form, 'id_form_digital')));
        $skema = json_decode((string) $form[0]['form_digital'], true);
        $this->assertSame($skema, SkemaForm::normalisasi($skema));
        $this->assertSame(['text_1', 'radio_group_1', 'file_1'], array_column($skema['pertanyaan'], 'id'));
        $this->assertSame(['0'], json_decode((string) $form[0]['target_uns'], true));
        $this->assertSame('1', (string) $form[0]['status']);

        // user_form_digital: target legacy (A) + turunan filled (B); anak form 2 ikut dibuang.
        $ufd = $this->db->table('user_form_digital')->orderBy('nip')->get()->getResultArray();
        $this->assertSame([[1, self::NIP_A], [1, self::NIP_B]], array_map(static fn (array $r): array => [(int) $r['id_form_digital'], (string) $r['nip']], $ufd));
        $this->assertSame([AturanKonten::IKUT_INDUK => 1], $laporan['user_form_digital']['dibuang']);
        $this->assertSame(1, $laporan['user_form_digital<filled_form_digital']['ditulis']);

        // filled: jawaban ke id v2 + label opsi + kunci berkas form-digital/<id form>/…, unicode utuh; form 2 ikut dibuang.
        $isi = $this->db->table('filled_form_digital')->get()->getResultArray();
        $this->assertCount(1, $isi);
        $j = json_decode((string) $isi[0]['response'], true);
        $this->assertSame('Budi é', $j['text_1']);
        $this->assertSame('Ya', $j['radio_group_1']);
        $this->assertMatchesRegularExpression('#^form-digital/1/[0-9a-f]{32}\.pdf$#', $j['file_1']['berkas']);
        $this->assertNull($j['file_1']['ukuran']);
        $this->assertSame([AturanKonten::IKUT_INDUK => 1], $laporan['filled_form_digital']['dibuang']);

        $teks = (string) json_encode($laporan);
        $this->assertStringNotContainsString('Budi', $teks, 'isi jawaban tidak masuk laporan');
        $this->assertStringNotContainsString(self::NIP_B, $teks);

        foreach ($laporan as $kunci => $t) {
            $this->assertSame($t['sumber'], $t['ditulis'] + $t['dilewati'] + array_sum($t['dibuang']), "rekonsiliasi {$kunci}");
        }
    }
}
