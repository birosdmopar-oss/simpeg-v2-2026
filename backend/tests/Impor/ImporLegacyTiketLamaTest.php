<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\AturanTiketLama;
use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use CodeIgniter\Test\StreamFilterTrait;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * F-02 Sortir inbox legacy (MAKE-086, PRD Fase 7 v2.0 §5.2, Q7-28) — tier 13 "tiket lama" dengan data SINTETIS: semua
 * `inbox`/`inbox_msg` legacy = tiket → `disiplin_ticket(_msg)`; `inbox` v2 tetap kosong; baris ambigu (A-ESK/M-ESK)
 * tidak ditebak (tanpa keputusan → gagal, tidak ada yang ditulis); manifest sumber = ditulis + dibuang.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyTiketLamaTest extends ImporLegacyTestCase
{
    use StreamFilterTrait;

    private const TIER = 13;

    private const NIP_A = '000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        // Bentuk legacy: tabel bernama inbox/inbox_msg (rename 1:1 di v2), tanpa CHECK, inbox_msg.id_inbox nullable (D1).
        // (tabel dibuat ImporLegacyTestCase dari `DefinisiTabel::$sumber`).
        $this->lepasCheck('inbox');
        $this->lepasCheck('inbox_msg');

        $this->src->query('ALTER TABLE `inbox_msg` MODIFY `id_inbox` INT NULL');
        $this->db->table('question_category')->insert(['id_question_category' => 3, 'question_category' => 'Kepegawaian', 'status' => 1]);

        $tiket = static fn (int $id, array $kolom = []): array => $kolom + [
            'id_inbox'   => $id, 'id_question_category' => 3, 'subject' => "Pertanyaan uji {$id}", 'sender' => 11, 'sender_name' => 'Pegawai Uji',
            'nip'        => self::NIP_A, 'receiver' => 0, 'allow_reply' => 1, 'status' => 0, 'is_read' => 1, 'is_read_off' => 2, 'notify_pic' => 2,
            'created_at' => '2026-01-05 10:00:00', 'closed_by' => null,
        ];
        $this->src->table('inbox')->insertBatch([
            $tiket(1),
            $tiket(2, ['status' => 1, 'allow_reply' => 0, 'is_read_off' => 1, 'closed_by' => 12]),
            $tiket(3, ['sender' => 0]),
            $tiket(4),
            $tiket(5, ['id_question_category' => null]),
        ]);

        $pesan = static fn (int $id, ?int $induk, array $kolom = []): array => $kolom + [
            'id_inbox_msg' => $id, 'id_inbox' => $induk, 'msg_sender' => 11, 'msg_sender_name' => 'Pegawai Uji', 'msg_receiver' => 0,
            'msg'          => 'Isi pesan uji', 'show_notif' => 1, 'is_read' => 0, 'created_at' => '2026-01-05 10:00:00',
        ];
        $this->src->table('inbox_msg')->insertBatch([
            $pesan(10, 1, ['msg' => "Pegawai\\'s pertanyaan"]),
            $pesan(11, 1, ['msg_sender' => 12, 'msg_receiver' => 11]),
            $pesan(12, 2),
            $pesan(13, 3),
            $pesan(14, null),
            $pesan(15, 5),
        ]);
    }

    public function testTanpaKeputusanAmbiguGagalDanTidakAdaYangDitulis(): void
    {
        foreach ([true, false] as $dryRun) {
            try {
                $this->jalankanImpor([self::TIER], dryRun: $dryRun);
                $this->fail('ambigu tanpa keputusan wajib gagal');
            } catch (ImporGagal $e) {
                $this->assertStringContainsString('inbox: 2 baris ambigu belum diputuskan', $e->getMessage());
                $this->assertStringContainsString(AturanTiketLama::A_PENGIRIM . ': 1', $e->getMessage());
                $this->assertStringContainsString(AturanTiketLama::A_TANPA_PESAN . ': 1', $e->getMessage());
                $this->assertStringNotContainsString(self::NIP_A, $e->getMessage(), 'pesan galat tanpa NIP');
            }
        }

        $this->assertSame(0, $this->db->table('disiplin_ticket')->countAllResults());
        $this->assertSame(0, $this->db->table('disiplin_ticket_msg')->countAllResults());
    }

    public function testDenganKeputusanSemuaTiketKeDisiplinTicketDanManifestSeimbang(): void
    {
        $laporan = $this->jalankanImpor([self::TIER], k: $this->keputusan());

        $tiket = $laporan['disiplin_ticket<inbox'];
        $this->assertSame(5, $tiket['sumber']);
        $this->assertSame(4, $tiket['ditulis']);
        $this->assertSame([AturanTiketLama::DIBUANG_KEPUTUSAN => 1], $tiket['dibuang']);
        $this->assertSame(4, $tiket['koreksi'][AturanTiketLama::INFO_PENGIRIM] ?? null);
        $this->assertSame(1, $tiket['koreksi'][AturanTiketLama::INFO_KATEGORI_NULL] ?? null, 'N6: tiket berkategori NULL');
        $this->assertArrayNotHasKey(AturanTiketLama::INFO_TAK_TERPAKAI_TIKET, $tiket['koreksi']);

        $pesan = $laporan['disiplin_ticket_msg<inbox_msg'];
        $this->assertSame(6, $pesan['sumber']);
        $this->assertSame(4, $pesan['ditulis']);
        $this->assertEqualsCanonicalizing([AturanTiketLama::DIBUANG_KEPUTUSAN => 1, AturanTiketLama::DIBUANG_IKUT_INDUK => 1], $pesan['dibuang']);
        $this->assertSame($pesan['sumber'], $pesan['ditulis'] + $pesan['dilewati'] + array_sum($pesan['dibuang']), 'total sebelum = sesudah');

        $this->assertSame([1, 2, 4, 5], array_map('intval', array_column($this->db->table('disiplin_ticket')->orderBy('id_inbox')->get()->getResultArray(), 'id_inbox')));
        $this->assertSame([10, 11, 12, 15], array_map('intval', array_column($this->db->table('disiplin_ticket_msg')->orderBy('id_inbox_msg')->get()->getResultArray(), 'id_inbox_msg')));

        $tutup = $this->db->table('disiplin_ticket')->where('id_inbox', 2)->get()->getRowArray();
        $this->assertSame(['1', '0', '12'], [(string) $tutup['status'], (string) $tutup['allow_reply'], (string) $tutup['closed_by']]);
        $this->assertNull($this->db->table('disiplin_ticket')->where('id_inbox', 5)->get()->getRow()->id_question_category, 'kategori dihapus (FK legacy SET NULL) tetap tiket');
        $this->assertSame('2026-01-05 03:00:00', $this->db->table('disiplin_ticket')->where('id_inbox', 1)->get()->getRow()->created_at, 'R1a WIB → UTC');
        $this->assertSame("Pegawai's pertanyaan", $this->db->table('disiplin_ticket_msg')->where('id_inbox_msg', 10)->get()->getRow()->msg, 'addslashes legacy dibersihkan (R8-5)');

        $this->assertSame(0, $this->db->table('inbox')->countAllResults(), 'inbox v2 (notifikasi) tidak ditulis (Q7-28)');
        $this->assertSame(0, $this->db->table('inbox_msg')->countAllResults());
    }

    public function testKeputusanTakTerpakaiDilaporkan(): void
    {
        $k       = $this->keputusan([AturanTiketLama::KUNCI_TIKET => ['3' => AturanTiketLama::BUANG, '4' => AturanTiketLama::IMPOR, '1' => AturanTiketLama::IMPOR, '99' => AturanTiketLama::BUANG]]);
        $laporan = $this->jalankanImpor([self::TIER], k: $k);

        $this->assertSame(2, $laporan['disiplin_ticket<inbox']['koreksi'][AturanTiketLama::INFO_TAK_TERPAKAI_TIKET] ?? null, 'N1: id tidak ambigu (1) dan tidak ada di snapshot (99)');
        $this->assertSame(['tiket' => 2, 'pesan' => 0], (new AturanTiketLama())->manifest($this->src, 'inbox', 'inbox_msg', $k)['keputusan_tak_terpakai']);
    }

    public function testTiketYangSemuaPesannyaDibuangMenjadiTanpaPesan(): void
    {
        // N2: satu-satunya pesan tiket 5 diputus "__buang" → tiket 5 = A-ESK tanpa pesan, wajib diputus juga.
        $k = $this->keputusan([AturanTiketLama::KUNCI_PESAN => ['14' => AturanTiketLama::BUANG, '15' => AturanTiketLama::BUANG]]);

        try {
            $this->jalankanImpor([self::TIER], dryRun: true, k: $k);
            $this->fail('tiket tanpa pesan sesudah keputusan pesan wajib dieskalasi');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString('inbox: 1 baris ambigu belum diputuskan (' . AturanTiketLama::A_TANPA_PESAN . ': 1)', $e->getMessage());
        }

        $this->assertSame(['5' => AturanTiketLama::A_TANPA_PESAN], (new AturanTiketLama())->manifest($this->src, 'inbox', 'inbox_msg', $k)['eskalasi']['tiket']);
    }

    public function testPesanTanpaIndukHanyaBolehDibuang(): void
    {
        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('pesan tanpa induk tidak bisa diimpor');

        $this->jalankanImpor([self::TIER], dryRun: true, k: $this->keputusan(['disiplin_ticket_msg.f02' => ['14' => AturanTiketLama::IMPOR]]));
    }

    public function testNilaiKeputusanSelainImporAtauBuangDitolak(): void
    {
        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('hanya boleh "impor" atau "__buang"');

        $this->jalankanImpor([self::TIER], dryRun: true, k: $this->keputusan(['disiplin_ticket.f02' => ['3' => 'notifikasi', '4' => AturanTiketLama::IMPOR]]));
    }

    public function testFlagDiLuarDomainLewatBerkasKeputusan(): void
    {
        $this->src->table('inbox')->where('id_inbox', 1)->update(['is_read' => 0]);

        try {
            $this->jalankanImpor([self::TIER], dryRun: true, k: $this->keputusan());
            $this->fail('nilai di luar domain CHECK wajib dipetakan');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString("disiplin_ticket.is_read '0' di luar domain", $e->getMessage());
        }

        $manifest = (new AturanTiketLama())->manifest($this->src, 'inbox', 'inbox_msg', $this->keputusan());
        $this->assertSame(['disiplin_ticket.is_read' => ['0' => 1]], $manifest['di_luar_domain'], 'N3: nilai di luar domain dihitung sejak dry-run');
        $this->assertSame(1, $manifest['tiket']['kelas']['T-NORM'] ?? null);

        $isi           = $this->isiKeputusan();
        $isi['status'] = ['disiplin_ticket.is_read' => ['0' => 2]];
        $laporan       = $this->jalankanImpor([self::TIER], k: Keputusan::dariArray($isi));
        $this->assertSame(4, $laporan['disiplin_ticket<inbox']['ditulis']);
        $this->assertSame(2, (int) $this->db->table('disiplin_ticket')->where('id_inbox', 1)->get()->getRow()->is_read);
    }

    public function testManifestDanCommandSortirInboxTanpaMenulisDb(): void
    {
        $manifest = (new AturanTiketLama())->manifest($this->src, 'inbox', 'inbox_msg', Keputusan::kosong());

        $this->assertSame(['total' => 5, 'kelas' => ['T-OK' => 3, 'A-ESK belum diputuskan' => 2]], $manifest['tiket']);
        $this->assertSame(['3' => AturanTiketLama::A_PENGIRIM, '4' => AturanTiketLama::A_TANPA_PESAN], $manifest['eskalasi']['tiket']);
        $this->assertSame(['14' => AturanTiketLama::M_TANPA_INDUK], $manifest['eskalasi']['pesan']);
        $this->assertSame(6, $manifest['pesan']['total']);
        $this->assertSame(6, array_sum($manifest['pesan']['kelas']));

        $sesudah = (new AturanTiketLama())->manifest($this->src, 'inbox', 'inbox_msg', $this->keputusan());
        $this->assertSame(['T-OK' => 3, 'A-ESK dibuang' => 1, 'A-ESK diimpor' => 1], $sesudah['tiket']['kelas']);
        $this->assertSame(['M-OK' => 4, 'M-IKUT dibuang' => 1, 'M-ESK dibuang' => 1], $sesudah['pesan']['kelas']);
        $this->assertSame(['tiket' => [], 'pesan' => []], $sesudah['eskalasi']);

        $keluaran = str_replace('\\', '/', WRITEPATH) . 'sortir_inbox_' . $this->db->getDatabase() . '.json';

        try {
            command("impor:sortir-inbox --sumber={$this->lgc} --keluaran={$keluaran}");
            $layar = $this->getStreamFilterBuffer();
            $this->assertStringContainsString('Eskalasi: 3 baris ambigu belum diputuskan', $layar);
            $this->assertStringNotContainsString(self::NIP_A, $layar);

            $berkas = json_decode((string) file_get_contents($keluaran), true);
            $this->assertSame($manifest['eskalasi'], $berkas['eskalasi']);
            $this->assertStringNotContainsString(self::NIP_A, (string) file_get_contents($keluaran), 'manifest tanpa NIP');
            $this->assertStringNotContainsString('Pertanyaan uji', (string) file_get_contents($keluaran), 'manifest tanpa isi');
        } finally {
            is_file($keluaran) && unlink($keluaran);
        }

        $this->assertSame(0, $this->db->table('disiplin_ticket')->countAllResults(), 'command tidak menulis DB');
    }

    /**
     * @param array<string, array<int|string, string>> $tambahan
     */
    private function keputusan(array $tambahan = []): Keputusan
    {
        $isi          = $this->isiKeputusan();
        $isi['baris'] = $tambahan + $isi['baris'];

        return Keputusan::dariArray($isi);
    }

    /**
     * Keputusan contoh (dibuat test, bukan coder untuk data nyata): tiket ber-pengirim 0 dibuang, tiket tanpa pesan
     * tetap diimpor, pesan tanpa induk dibuang.
     *
     * @return array{baris: array<string, array<int|string, string>>, status?: array<string, mixed>}
     */
    private function isiKeputusan(): array
    {
        return ['baris' => [
            AturanTiketLama::KUNCI_TIKET => ['3' => AturanTiketLama::BUANG, '4' => AturanTiketLama::IMPOR],
            AturanTiketLama::KUNCI_PESAN => ['14' => AturanTiketLama::BUANG],
        ]];
    }
}
