<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use App\Libraries\HaloSimpeg\AksesTiket;
use App\Libraries\HaloSimpeg\RingkasanTiket;
use App\Libraries\HaloSimpeg\TiketService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-071 — tiket Halo Simpeg: AC-E03.*, AC-E04.*, AC-E05.*, §7.8 (1)(2)(4) (PRD Fase 8 v1.3 §6.1b, §6.2, §7.3–§7.5, H8-16 (a1),
 * D7 (a)). Data sintetis; lampiran di `TiketLampiranTest`, kunci lintas koneksi di `TiketKunciTest`, EXPLAIN di `TiketEksplainTest`.
 *
 * @internal
 */
final class TiketHaloSimpegTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/halo-simpeg/';

    protected $seed = AuthSeeder::class;

    /** Pemilik tiket (role 2) ber-pegawai + jabatan. */
    private int $pemilik;

    /** Pemilik kedua (role 7). */
    private int $pemilik2;

    /** Petugas aktif role 1 di kategori 1. */
    private int $admin;

    /** Petugas aktif role 3 di kategori 1. */
    private int $petugasB;

    /** Petugas aktif role 5 di kategori 2 saja. */
    private int $petugasK2;

    /** Petugas nonaktif role 6 di kategori 1. */
    private int $nonaktif;

    private int $k1;

    private int $k2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangThrottlerHs();

        $this->k1       = $this->buatKategoriHs('Kepegawaian');
        $this->k2       = $this->buatKategoriHs('Gaji');
        $this->pemilik  = $this->akunPegawai('Pelapor Satu', Role::PEGAWAI);
        $this->pemilik2 = $this->akunPegawai('Pelapor Dua', Role::PPPK);
        $this->admin    = $this->idAkunRole(Role::SUPER_ADMIN);
        $this->db->table('pengguna')->where('id_pengguna', $this->admin)->update(['name' => 'Admin Kepegawaian Uji']);
        $this->petugasB  = $this->akunPegawai('Petugas Bee', Role::ADMIN_SATKER);
        $this->petugasK2 = $this->akunPegawai('Petugas Gaji', Role::MENTERI);
        $this->nonaktif  = $this->akunPegawai('Petugas Cuti', Role::PTT);
        $this->buatPetugasHs($this->admin, [$this->k1]);
        $this->buatPetugasHs($this->petugasB, [$this->k1]);
        $this->buatPetugasHs($this->petugasK2, [$this->k2]);
        $this->buatPetugasHs($this->nonaktif, [$this->k1], 0);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    // ================================================================================================== AC-E03 buat

    public function testBuatTiketTersimpanOpenDenganPesanPertamaTanpaInbox(): void
    {
        $inbox = [$this->db->table('inbox')->countAllResults(), $this->db->table('inbox_msg')->countAllResults()];
        $res   = $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => '  Cuti "tahunan" \\ ', 'msg' => " Isi 'kutip' \\ garis "]);
        $res->assertStatus(201);
        $d = $this->data($res);

        $this->assertSame(['open', 'Cuti "tahunan" \\', false, true], [$d['status'], $d['subjek'], $d['sebagai_petugas'], $d['allow_reply']]);
        $this->assertSame(['id' => $this->k1, 'nama' => 'Kepegawaian'], $d['kategori']);
        $this->assertCount(1, $d['pesan']);
        $this->assertSame("Isi 'kutip' \\ garis", $d['pesan'][0]['isi'], 'BP8-4: teks utuh (AC-E04.9)');
        $this->assertNull($d['snapshot'], 'pemilik tidak melihat snapshot');

        $t = $this->db->table('disiplin_ticket')->where('id_inbox', $d['id'])->get()->getRowArray();
        $this->assertSame([0, 1, 1, 2, 2, 0, $this->pemilik], [(int) $t['status'], (int) $t['allow_reply'], (int) $t['is_read'], (int) $t['is_read_off'], (int) $t['notify_pic'], (int) $t['receiver'], (int) $t['sender']]);
        $this->assertSame('Pelapor Satu', $t['sender_name']);
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $t['nip'])->get()->getRowArray();
        $this->assertSame([$pmj['jabatan'], $pmj['satker']], [$t['jabatan'], $t['satker']], 'AC-E03.8 snapshot jabatan');
        $this->assertSame(1, $this->db->table('disiplin_ticket_msg')->where('id_inbox', $d['id'])->countAllResults());
        $this->assertSame($inbox, [$this->db->table('inbox')->countAllResults(), $this->db->table('inbox_msg')->countAllResults()], 'AC-E03.2');
        $this->assertGreaterThan(0, $this->db->table('audit_logs')->where('entity', 'disiplin_ticket')->where('entity_id', $d['id'])->countAllResults());
    }

    public function testBuatTiketValidasiRoleDanPegawai(): void
    {
        $nonaktif = $this->buatKategoriHs('Nonaktif', 0);
        $cases    = [
            [['id_question_category' => $nonaktif, 'subject' => 'S', 'msg' => 'M'], 'id_question_category', 'Kategori tidak tersedia.'],
            [['id_question_category' => 999999, 'subject' => 'S', 'msg' => 'M'], 'id_question_category', 'Kategori tidak tersedia.'],
            [['subject' => 'S', 'msg' => 'M'], 'id_question_category', 'Kategori wajib dipilih.'],
            [['id_question_category' => $this->k1, 'subject' => ' ', 'msg' => 'M'], 'subject', 'Subjek wajib diisi.'],
            [['id_question_category' => $this->k1, 'subject' => str_repeat('s', 251), 'msg' => 'M'], 'subject', 'Subjek maksimal 250 karakter.'],
            [['id_question_category' => $this->k1, 'subject' => 'S', 'msg' => ''], 'msg', 'Pesan wajib diisi.'],
            [['id_question_category' => $this->k1, 'subject' => 'S', 'msg' => str_repeat('é', 5001)], 'msg', 'Pesan maksimal 5.000 karakter.'],
        ];

        foreach ($cases as [$body, $kolom, $pesan]) {
            $this->pasangThrottlerHs();
            $res = $this->sebagai($this->pemilik)->post(self::URL . 'tiket', $body);
            $res->assertStatus(422);
            $this->assertSame([$pesan], $this->json($res)['errors'][$kolom], $kolom);
        }

        $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => str_repeat('s', 250), 'msg' => str_repeat('é', 5000)])->assertStatus(201);
        $this->assertSame(1, $this->db->table('disiplin_ticket')->countAllResults(), 'hanya kasus sah yang tersimpan');

        $this->asRole(Role::SUPER_ADMIN)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'S', 'msg' => 'M'])->assertStatus(403);
        $this->asRole(Role::PIMPINAN)->get(self::URL . 'tiket')->assertStatus(403);

        $tanpaPegawai = $this->buatAkunTanpaNipHs(Role::PTT);
        $res          = $this->sebagai($tanpaPegawai)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'S', 'msg' => 'M']);
        $res->assertStatus(422);
        $this->assertSame(TiketService::PESAN_PEGAWAI, $this->json($res)['message']);
    }

    public function testThrottleBuatTiketDanPesan(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => "S{$i}", 'msg' => 'M'])->assertStatus(201);
        }

        $res = $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'S6', 'msg' => 'M']);
        $this->harapGalat($res, 429, 'throttle', TiketService::PESAN_THROTTLE_TIKET);
        $this->assertSame(5, $this->db->table('disiplin_ticket')->countAllResults(), 'tanpa baris tertulis');

        $t = $this->tiket($this->pemilik2, $this->k1);

        for ($i = 1; $i <= 20; $i++) {
            $this->sebagai($this->admin)->post(self::URL . "tiket/{$t}/pesan", ['msg' => "balas {$i}"])->assertStatus(201);
        }

        $this->harapGalat($this->sebagai($this->admin)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'lagi']), 429, 'throttle', TiketService::PESAN_THROTTLE_PESAN);
    }

    // ================================================================================================== AC-E04 daftar & detail

    public function testDaftarSayaDanPetugasUrutanPesanTerakhirDanTiketTanpaPesanDiAkhir(): void
    {
        $a = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00');
        $b = $this->tiket($this->pemilik, $this->k1, '2026-11-17 03:00:00');
        $c = $this->tiket($this->pemilik, $this->k1, null);
        $d = $this->tiket($this->pemilik, $this->k1, '2026-11-17 03:00:00');
        $this->tiket($this->pemilik2, $this->k2, '2026-11-17 05:00:00');
        $milikAdmin = $this->tiketMilik($this->admin, $this->k1);

        // Urutan: waktu pesan terakhir turun, seri → id turun, tanpa pesan di akhir (B-20, AC-E04.15).
        $saya = $this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket'));
        $this->assertSame([$d, $b, $a, $c], array_column($saya['items'], 'id'));
        $this->assertNull($saya['items'][3]['pesan_terakhir']);

        // Petugas kategori 1 melihat tiket kategori 1 kecuali miliknya sendiri; petugas kategori 2 tidak (§7.8 (4)).
        $petugas = $this->data($this->sebagai($this->petugasB)->get(self::URL . 'tiket?peran=petugas'));
        $this->assertSame([$d, $b, $a, $milikAdmin, $c], array_column($petugas['items'], 'id'));
        $this->assertTrue($petugas['items'][0]['sebagai_petugas']);
        $this->assertNotContains($milikAdmin, array_column($this->data($this->sebagai($this->admin)->get(self::URL . 'tiket?peran=petugas'))['items'], 'id'), 'tiket sendiri hanya di sisi pemilik');
        $this->assertSame([], array_values(array_intersect([$a, $b, $c, $d], array_column($this->data($this->sebagai($this->petugasK2)->get(self::URL . 'tiket?peran=petugas'))['items'], 'id'))));

        // Petugas nonaktif: antrean kosong (AC-E04.5) tetapi tiket miliknya tetap terlihat.
        $this->assertSame(0, $this->data($this->sebagai($this->nonaktif)->get(self::URL . 'tiket?peran=petugas'))['total']);
        $milikNonaktif = $this->tiketMilik($this->nonaktif, $this->k1);
        $this->assertSame([$milikNonaktif], array_column($this->data($this->sebagai($this->nonaktif)->get(self::URL . 'tiket'))['items'], 'id'));

        // Filter dan paginasi.
        $this->assertSame(2, $this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket?per_page=2'))['per_page']);
        $this->assertSame([$d, $b], array_column($this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket?per_page=2'))['items'], 'id'));
        $this->db->table('disiplin_ticket')->where('id_inbox', $a)->update(['status' => 1]);
        $this->assertSame([$a], array_column($this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket?status=closed'))['items'], 'id'));
        $this->assertSame(1, $this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket?q=' . rawurlencode('subjek ' . $b)))['total']);
        $this->assertSame(0, $this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket?q=%25'))['total']);
        $this->buatPesanHs($a, $this->pemilik, 'kata-unik-dalam-isi', '2026-11-17 01:30:00');
        $this->assertSame([$a], array_column($this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket?q=kata-unik-dalam'))['items'], 'id'), 'q mencari isi pesan');
        $this->assertSame(4, $this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket?kategori=' . $this->k1))['total']);

        foreach (['peran=x' => 'peran', 'status=0' => 'status', 'baca=x' => 'baca', 'kategori=x' => 'kategori', 'perlu_tindak=1' => 'perlu_tindak'] as $q => $kolom) {
            $res = $this->sebagai($this->pemilik)->get(self::URL . "tiket?{$q}");
            $res->assertStatus(422);
            $this->assertArrayHasKey($kolom, $this->json($res)['errors'], $q);
        }
    }

    public function testPesanTerakhirSeriWaktuMemilihIdTerbesar(): void
    {
        $t  = $this->tiket($this->pemilik, $this->k1, null);
        $m1 = $this->buatPesanHs($t, $this->pemilik, 'pertama', '2026-11-17 05:00:00');
        $m2 = $this->buatPesanHs($t, $this->pemilik, 'kedua', '2026-11-17 05:00:00');
        $this->assertGreaterThan($m1, $m2);
        $baris = $this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket'))['items'][0];
        $this->assertSame([$m2, 'kedua'], [$baris['pesan_terakhir']['id_inbox_msg'], $baris['pesan_terakhir']['potongan']]);
        $this->assertSame([$m1, $m2], array_column($this->data($this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t}"))['pesan'], 'id_inbox_msg'));
    }

    public function testFilterBacaDanPerluTindakSamaDenganRingkasan(): void
    {
        $belum      = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00', ['is_read_off' => 2]);
        $sudah      = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00', ['is_read_off' => 1]);
        $tempo      = $this->tiket($this->pemilik2, $this->k1, '2026-11-17 01:00:00', ['is_read_off' => 1, 'notify_pic' => 1, 'notify_at' => '2020-01-01 00:00:00']);
        $belumTempo = $this->tiket($this->pemilik2, $this->k1, '2026-11-17 01:00:00', ['is_read_off' => 1, 'notify_pic' => 1, 'notify_at' => '2099-01-01 00:00:00']);
        $tutup      = $this->tiket($this->pemilik2, $this->k1, '2026-11-17 01:00:00', ['is_read_off' => 2, 'status' => 1]);

        $ids = fn (string $q): array => array_column($this->data($this->sebagai($this->admin)->get(self::URL . "tiket?peran=petugas&{$q}"))['items'], 'id');
        $this->assertEqualsCanonicalizing([$belum, $tutup], $ids('baca=belum'));
        $this->assertEqualsCanonicalizing([$sudah, $tempo, $belumTempo], $ids('baca=sudah'));
        $this->assertEqualsCanonicalizing([$belum, $tempo], $ids('perlu_tindak=1'));

        $r = $this->data($this->sebagai($this->admin)->get(self::URL . 'tiket/ringkasan'));
        $this->assertSame(['belum_dibaca_saya' => 0, 'belum_dibaca_petugas' => 2, 'perlu_tindak' => 2, 'badge' => 3, 'total' => 2], $r);
        $this->assertSame($r['perlu_tindak'], $this->data($this->sebagai($this->admin)->get(self::URL . 'tiket?peran=petugas&perlu_tindak=1'))['total'], 'AC-E04.13');
        $this->assertGreaterThan($r['total'], $r['badge'], 'badge > total (pengingat jatuh tempo, D8 (a))');

        $baris = array_column($this->data($this->sebagai($this->admin)->get(self::URL . 'tiket?peran=petugas'))['items'], null, 'id');
        $this->assertSame([true, false, true, false, false], [$baris[$belum]['perlu_tindak'], $baris[$sudah]['perlu_tindak'], $baris[$tempo]['perlu_tindak'], $baris[$belumTempo]['perlu_tindak'], $baris[$tutup]['perlu_tindak']]);
        $this->assertFalse($this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket'))['items'][0]['perlu_tindak'], 'pemilik: selalu false');
    }

    public function testDetailPihakLain404DanGetTanpaEfekSamping(): void
    {
        $t     = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00', ['is_read' => 2, 'is_read_off' => 2]);
        $sidik = $this->sidikTiket();

        foreach ([$this->pemilik, $this->admin, $this->petugasB] as $akun) {
            $this->sebagai($akun)->get(self::URL . "tiket/{$t}")->assertStatus(200);
            $this->sebagai($akun)->get(self::URL . 'tiket?peran=' . ($akun === $this->pemilik ? 'saya' : 'petugas'))->assertStatus(200);
            $this->sebagai($akun)->get(self::URL . 'tiket/ringkasan')->assertStatus(200);
        }

        $this->assertSame($sidik, $this->sidikTiket(), 'AC-E04.8: GET tidak mengubah baris');

        foreach ([$this->pemilik2, $this->petugasK2, $this->nonaktif] as $akun) {
            $this->harapGalat($this->sebagai($akun)->get(self::URL . "tiket/{$t}"), 404, null, AksesTiket::PESAN_404);
            $this->harapGalat($this->sebagai($akun)->post(self::URL . "tiket/{$t}/baca"), 404, null, AksesTiket::PESAN_404);
            $this->harapGalat($this->sebagai($akun)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'x']), 404, null, AksesTiket::PESAN_404);
            $this->harapGalat($this->sebagai($akun)->post(self::URL . "tiket/{$t}/tutup"), 404, null, AksesTiket::PESAN_404);
            $this->harapGalat($this->sebagai($akun)->delete(self::URL . "tiket/{$t}"), 404, null, AksesTiket::PESAN_404);
        }

        $this->harapGalat($this->sebagai($this->pemilik)->get(self::URL . 'tiket/999999'), 404, null, AksesTiket::PESAN_404);
    }

    public function testPenyamaranD7DaftarDanDetail(): void
    {
        $t = $this->tiket($this->pemilik, $this->k1, null);
        $this->buatPesanHs($t, $this->pemilik, 'tanya', '2026-11-17 01:00:00', null, 'Pelapor Satu');
        $this->buatPesanHs($t, $this->admin, 'jawab admin', '2026-11-17 02:00:00', null, 'Admin Satu');
        $this->buatPesanHs($t, $this->petugasB, 'jawab bee', '2026-11-17 03:00:00', null, 'Petugas Bee');

        $label = fn (int $akun): array => array_column($this->data($this->sebagai($akun)->get(self::URL . "tiket/{$t}"))['pesan'], 'pengirim_label');
        $this->assertSame(['Pelapor Satu', 'Admin', 'Admin'], $label($this->pemilik), 'pesan petugas dilihat pemilik');
        $this->assertSame(['Pelapor Satu', 'Admin', 'Admin'], $label($this->petugasB), 'pesan petugas lain + pesan sendiri dilihat petugas non-role-1');
        $this->assertSame(['Pelapor Satu', 'Admin Satu', 'Petugas Bee'], $label($this->admin), 'detail dilihat role 1 → nama');

        // Daftar: "Admin" bagi semua peninjau, termasuk role 1 (VI:110-117).
        $this->assertSame('Admin', $this->data($this->sebagai($this->admin)->get(self::URL . 'tiket?peran=petugas'))['items'][0]['pesan_terakhir']['pengirim_label']);

        $detail = $this->data($this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t}"));
        $this->assertSame([0, 1, 2], array_column($detail['pesan'], 'urutan_pengirim'));
        $this->assertSame([true, false, false], array_column($detail['pesan'], 'milik_saya'));
        $this->assertSame([true, false, false], array_column($detail['pesan'], 'dari_pemilik'));
        $this->assertStringNotContainsString('"msg_sender"', (string) $this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t}")->getJSON(), 'id akun petugas tidak dikirim');
        $this->assertSame(['nip', 'jabatan', 'satker'], array_keys($this->data($this->sebagai($this->petugasB)->get(self::URL . "tiket/{$t}"))['snapshot']));
    }

    // ================================================================================================== penanda baca §6.2

    public function testPenandaBacaPerSkenario(): void
    {
        $t    = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00', ['is_read' => 2, 'is_read_off' => 2]);
        $baca = fn (): array => array_map('intval', $this->db->table('disiplin_ticket')->select('is_read, is_read_off')->where('id_inbox', $t)->get()->getRowArray());

        // (i) pemilik POST baca → is_read = 1 saja.
        $r = $this->data($this->sebagai($this->pemilik)->post(self::URL . "tiket/{$t}/baca"));
        $this->assertSame(['is_read' => 1, 'is_read_off' => 2], $baca());
        $this->assertSame(['belum_dibaca_saya', 'belum_dibaca_petugas', 'perlu_tindak', 'badge', 'total'], array_keys($r));
        // (ii) petugas POST baca → is_read_off = 1; berulang idempoten tanpa audit.
        $this->sebagai($this->admin)->post(self::URL . "tiket/{$t}/baca")->assertStatus(200);
        $this->assertSame(['is_read' => 1, 'is_read_off' => 1], $baca());
        $audit = $this->db->table('audit_logs')->where('entity', 'disiplin_ticket')->countAllResults();
        $this->sebagai($this->admin)->post(self::URL . "tiket/{$t}/baca")->assertStatus(200);
        $this->assertSame($audit, $this->db->table('audit_logs')->where('entity', 'disiplin_ticket')->countAllResults());
        // (iii) petugas membalas → is_read_off = 1 [K], is_read = 2 [I].
        $this->sebagai($this->admin)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'jawab'])->assertStatus(201);
        $this->assertSame(['is_read' => 2, 'is_read_off' => 1], $baca());
        // (iv) pemilik membalas → is_read = 1, is_read_off = 2 [I].
        $this->sebagai($this->pemilik)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'balik'])->assertStatus(201);
        $this->assertSame(['is_read' => 1, 'is_read_off' => 2], $baca());
    }

    public function testPemilikYangPetugasKategoriItuMembacaHanyaSisiPemilik(): void
    {
        $t = $this->tiketMilik($this->petugasB, $this->k1);
        $this->db->table('disiplin_ticket')->where('id_inbox', $t)->update(['is_read' => 2, 'is_read_off' => 2]);

        $this->sebagai($this->petugasB)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'saya pemilik'])->assertStatus(201);
        $this->sebagai($this->petugasB)->post(self::URL . "tiket/{$t}/baca")->assertStatus(200);
        $this->assertSame(2, (int) $this->db->table('disiplin_ticket')->where('id_inbox', $t)->get()->getRow('is_read_off'), '§6.2: is_read_off tetap 2');

        $baris = array_column($this->data($this->sebagai($this->admin)->get(self::URL . 'tiket?peran=petugas'))['items'], null, 'id')[$t];
        $this->assertSame([true, true], [$baris['belum_dibaca'], $baris['perlu_tindak']], 'petugas lain tetap melihat belum dibaca + perlu ditindak');
        $this->assertSame(0, $this->data($this->sebagai($this->petugasB)->get(self::URL . 'tiket/ringkasan'))['belum_dibaca_petugas'], 'tidak dihitung ganda');
    }

    // ================================================================================================== balas

    public function testBalasanDitolakTidakMemakanKuota(): void
    {
        $tutup = $this->tiket($this->pemilik, $this->k1, null, ['status' => 1, 'allow_reply' => 0]);
        $buka  = $this->tiket($this->pemilik, $this->k1, null);

        for ($i = 0; $i < 21; $i++) {
            $this->sebagai($this->admin)->post(self::URL . "tiket/{$tutup}/pesan", ['msg' => "x{$i}"])->assertStatus(422);
        }

        $this->sebagai($this->admin)->post(self::URL . "tiket/{$buka}/pesan", ['msg' => 'sah'])->assertStatus(201);
    }

    /**
     * K3 #1: petugas status 1 dengan akun nonaktif, akun terhapus, atau role 8 bukan petugas aktif (JWT stateless, jadi
     * `AksesTiket::kategoriAktif` satu-satunya penjaga).
     */
    public function testPetugasBerakunNonaktifTerhapusAtauRole8BukanPenjawab(): void
    {
        $t        = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00', ['is_read_off' => 2]);
        $nonaktif = $this->akunPegawai('Akun Nonaktif', Role::ADMIN_SATKER);
        $terhapus = $this->akunPegawai('Akun Terhapus', Role::ADMIN_SATKER);
        $role8    = $this->akunPegawai('Akun Pimpinan', Role::PIMPINAN);

        foreach ([$nonaktif, $terhapus, $role8] as $akun) {
            $this->buatPetugasHs($akun, [$this->k1]);
        }

        $this->db->table('pengguna')->where('id_pengguna', $nonaktif)->update(['status' => '0']);
        $this->db->table('pengguna')->where('id_pengguna', $terhapus)->update(['deleted_at' => '2026-11-01 00:00:00']);

        foreach ([$nonaktif, $terhapus] as $akun) {
            foreach ([['get', "tiket/{$t}"], ['post', "tiket/{$t}/baca"], ['post', "tiket/{$t}/tutup"], ['delete', "tiket/{$t}"]] as [$verb, $jalur]) {
                $this->sebagai($akun)->{$verb}(self::URL . $jalur)->assertStatus(404);
            }

            $this->sebagai($akun)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'x'])->assertStatus(404);
            $this->assertSame(0, $this->data($this->sebagai($akun)->get(self::URL . 'tiket?peran=petugas'))['total']);
            $this->assertSame(0, $this->data($this->sebagai($akun)->get(self::URL . 'tiket/ringkasan'))['belum_dibaca_petugas']);
        }

        // Role 8 ditolak filter route (F8-04); di tingkat service pun bukan petugas.
        $this->sebagai($role8)->get(self::URL . "tiket/{$t}")->assertStatus(403);
        $this->assertSame([], AksesTiket::kategoriAktif($this->db, $role8));
        $this->assertSame(0, (new RingkasanTiket($this->db))->hitung($role8)['belum_dibaca_petugas']);
    }

    public function testBalasDitolakTutupTanpaBalasanDanPengingat24Jam(): void
    {
        $tutup = $this->tiket($this->pemilik, $this->k1, null, ['status' => 1, 'allow_reply' => 0]);
        $tanpa = $this->tiket($this->pemilik, $this->k1, null, ['allow_reply' => 0]);
        $this->harapGalat($this->sebagai($this->pemilik)->post(self::URL . "tiket/{$tutup}/pesan", ['msg' => 'x']), 422, 'tiket_ditutup', AksesTiket::PESAN_DITUTUP);
        $this->harapGalat($this->sebagai($this->admin)->post(self::URL . "tiket/{$tanpa}/pesan", ['msg' => 'x']), 422, 'tiket_tanpa_balasan', AksesTiket::PESAN_TANPA_BALASAN);

        $t = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00');
        $this->assertSame([TiketService::PESAN_PENGINGAT], $this->json($this->sebagai($this->pemilik)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'x', 'ingatkan_24_jam' => 1]))['errors']['ingatkan_24_jam']);
        $this->assertSame(['Nilai pengingat tidak valid.'], $this->json($this->sebagai($this->admin)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'x', 'ingatkan_24_jam' => 2]))['errors']['ingatkan_24_jam']);
        $this->assertSame(['Pesan wajib diisi.'], $this->json($this->sebagai($this->admin)->post(self::URL . "tiket/{$t}/pesan", ['msg' => '  ']))['errors']['msg']);
        $this->sebagai($this->pemilik)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'nol diabaikan', 'ingatkan_24_jam' => 0])->assertStatus(201);

        $res = $this->sebagai($this->admin)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'ingatkan', 'ingatkan_24_jam' => 1]);
        $res->assertStatus(201);
        $this->assertSame(['pesan', 'tiket'], array_keys($this->data($res)));
        $row = $this->db->table('disiplin_ticket')->where('id_inbox', $t)->get()->getRowArray();
        $this->assertSame(1, (int) $row['notify_pic']);
        $this->assertEqualsWithDelta(time() + 86400, strtotime($row['notify_at'] . ' UTC'), 120, 'AC-E04.10: +24 jam UTC');
        $pengingat = $this->data($this->sebagai($this->admin)->get(self::URL . "tiket/{$t}"))['pengingat'];
        $this->assertTrue($pengingat['aktif']);
        $this->assertNull($this->data($this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t}"))['pengingat'], 'pengingat hanya untuk petugas');

        $this->sebagai($this->petugasB)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'tanpa ingat'])->assertStatus(201);
        $row = $this->db->table('disiplin_ticket')->where('id_inbox', $t)->get()->getRowArray();
        $this->assertSame([2, null], [(int) $row['notify_pic'], $row['notify_at']], 'balasan petugas berikutnya mereset');

        $msg = $this->db->table('disiplin_ticket_msg')->where('id_inbox', $t)->orderBy('id_inbox_msg', 'DESC')->get()->getRowArray();
        $this->assertSame([$this->petugasB, $this->pemilik], [(int) $msg['msg_sender'], (int) $msg['msg_receiver']]);
        $this->assertSame('Petugas Bee', $msg['msg_sender_name'], 'nama bergelar akun ber-NIP (B-28)');
    }

    // ================================================================================================== AC-E05 tutup & hapus

    public function testTutupHapusDanAlurPenuhTanpaInbox(): void
    {
        $inbox = [$this->db->table('inbox')->countAllResults(), $this->db->table('inbox_msg')->countAllResults()];
        $id    = $this->data($this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'Alur', 'msg' => 'Tanya']))['id'];
        $this->sebagai($this->admin)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Jawab'])->assertStatus(201);
        $this->sebagai($this->pemilik)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Terima kasih'])->assertStatus(201);

        $res = $this->sebagai($this->admin)->post(self::URL . "tiket/{$id}/tutup");
        $res->assertStatus(200);
        $this->assertSame(['closed', false, false, true], [$this->data($res)['status'], $this->data($res)['bisa_balas'], $this->data($res)['bisa_tutup'], $this->data($res)['bisa_hapus']]);
        $row = $this->db->table('disiplin_ticket')->where('id_inbox', $id)->get()->getRowArray();
        $this->assertSame([1, 0, 1, 1, $this->admin], [(int) $row['status'], (int) $row['allow_reply'], (int) $row['is_read'], (int) $row['is_read_off'], (int) $row['closed_by']]);
        $this->harapGalat($this->sebagai($this->pemilik)->post(self::URL . "tiket/{$id}/tutup"), 422, 'tiket_sudah_ditutup', AksesTiket::PESAN_SUDAH_DITUTUP);
        $this->harapGalat($this->sebagai($this->pemilik)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'lagi']), 422, 'tiket_ditutup', AksesTiket::PESAN_DITUTUP);

        // ditutup_oleh_label (D7 (a) detail): pemilik/petugas non-role-1 → "Admin"; role 1 → nama penutup (nilai persis).
        $this->assertSame('Admin', $this->data($this->sebagai($this->pemilik)->get(self::URL . "tiket/{$id}"))['ditutup_oleh_label']);
        $this->assertSame('Admin', $this->data($this->sebagai($this->petugasB)->get(self::URL . "tiket/{$id}"))['ditutup_oleh_label']);
        $this->assertSame('Admin Kepegawaian Uji', $this->data($this->sebagai($this->admin)->get(self::URL . "tiket/{$id}"))['ditutup_oleh_label']);
        $this->assertSame($inbox, [$this->db->table('inbox')->countAllResults(), $this->db->table('inbox_msg')->countAllResults()], '§7.8 (2)');

        // Hapus: pemilik 403, petugas 200 {id}; pesan ikut terhapus dengan audit before.
        $this->harapGalat($this->sebagai($this->pemilik)->delete(self::URL . "tiket/{$id}"), 403, 'bukan_pihak_berwenang', AksesTiket::PESAN_BUKAN_BERWENANG);
        $this->assertSame(['id' => $id], $this->data($this->sebagai($this->petugasB)->delete(self::URL . "tiket/{$id}")));
        $this->assertSame([0, 0], [$this->db->table('disiplin_ticket')->where('id_inbox', $id)->countAllResults(), $this->db->table('disiplin_ticket_msg')->where('id_inbox', $id)->countAllResults()]);
        $this->assertNotNull($this->db->table('audit_logs')->where('entity', 'disiplin_ticket')->where('entity_id', $id)->where('event', 'delete')->get()->getRow('before_json'));
        $this->assertSame(3, $this->db->table('audit_logs')->where('entity', 'disiplin_ticket_msg')->where('event', 'delete')->countAllResults());
    }

    public function testTiketClosedTanpaPenutupDanIdAkunPetugasTidakDikirim(): void
    {
        $t = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00', ['status' => 1, 'allow_reply' => 0, 'closed_by' => null]);

        foreach ([$this->pemilik, $this->petugasB, $this->admin] as $akun) {
            $this->assertNull($this->data($this->sebagai($akun)->get(self::URL . "tiket/{$t}"))['ditutup_oleh_label'], 'Closed impor tanpa closed_by');
        }

        // AC-E04.12: id akun petugas (nilai khas) tidak muncul sebagai nilai di kunci mana pun bagi non-role-1.
        $khas = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Petugas Khas']), Role::ADMIN_SATKER, ['id_pengguna' => 987654]);
        $this->buatPetugasHs($khas, [$this->k1]);
        $u = $this->tiket($this->pemilik, $this->k1, '2026-11-17 01:00:00');
        $this->sebagai($khas)->post(self::URL . "tiket/{$u}/pesan", ['msg' => 'jawab'])->assertStatus(201);
        $this->sebagai($khas)->post(self::URL . "tiket/{$u}/tutup")->assertStatus(200);

        foreach ([$this->pemilik, $this->petugasB] as $akun) {
            foreach (["tiket/{$u}", 'tiket', 'tiket?peran=petugas'] as $jalur) {
                $nilai = [];
                array_walk_recursive($this->json($this->sebagai($akun)->get(self::URL . $jalur))['data'], static function ($v) use (&$nilai): void {
                    $nilai[] = is_scalar($v) ? (string) $v : '';
                });
                // Dibandingkan sebagai teks: id akun yang dikirim sebagai string pun tertangkap.
                $this->assertNotContains((string) $khas, $nilai, "{$jalur} memuat id akun petugas");
            }
        }
    }

    public function testPemilikMenutupTiketSendiriDanLabelPenutupPemilik(): void
    {
        $t = $this->tiketMilik($this->petugasK2, $this->k1);
        $this->data($this->sebagai($this->petugasK2)->post(self::URL . "tiket/{$t}/tutup"));
        $this->assertSame('Petugas Gaji', $this->data($this->sebagai($this->admin)->get(self::URL . "tiket/{$t}"))['ditutup_oleh_label'], 'penutup = pemilik → nama');
        $this->assertSame('Petugas Gaji', $this->data($this->sebagai($this->petugasB)->get(self::URL . "tiket/{$t}"))['ditutup_oleh_label']);
    }

    /**
     * T2 (b): pemilik tiket tidak bisa menghapus tiketnya walau ia petugas aktif kategori itu; petugas lain kategori itu bisa.
     */
    public function testPemilikYangPetugasKategoriItuTidakBisaMenghapus(): void
    {
        $t = $this->tiketMilik($this->petugasB, $this->k1);
        $this->assertFalse($this->data($this->sebagai($this->petugasB)->get(self::URL . "tiket/{$t}"))['bisa_hapus']);
        $this->harapGalat($this->sebagai($this->petugasB)->delete(self::URL . "tiket/{$t}"), 403, 'bukan_pihak_berwenang', AksesTiket::PESAN_BUKAN_BERWENANG);
        $this->assertTrue($this->data($this->sebagai($this->admin)->get(self::URL . "tiket/{$t}"))['bisa_hapus']);
        $this->sebagai($this->admin)->delete(self::URL . "tiket/{$t}")->assertStatus(200);
    }

    // ================================================================================================== H8-16 (a1)

    public function testTiketKategoriNullHanyaPemilik(): void
    {
        $t = $this->tiket($this->pemilik, null, '2026-11-17 01:00:00', ['is_read_off' => 2]);

        $baris = $this->data($this->sebagai($this->pemilik)->get(self::URL . 'tiket'))['items'][0];
        $this->assertSame([$t, null, false, true, false, false], [$baris['id'], $baris['kategori'], $baris['bisa_balas'], $baris['bisa_tutup'], $baris['bisa_hapus'], $baris['perlu_tindak']]);
        $this->assertNotContains($t, array_column($this->data($this->sebagai($this->admin)->get(self::URL . 'tiket?peran=petugas'))['items'], 'id'));
        $this->assertSame(0, $this->data($this->sebagai($this->admin)->get(self::URL . 'tiket/ringkasan'))['perlu_tindak']);
        $this->assertNull($this->data($this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t}"))['kategori']);
        $this->harapGalat($this->sebagai($this->pemilik)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'x']), 422, 'tiket_tanpa_kategori', AksesTiket::PESAN_TANPA_KATEGORI);
        $this->harapGalat($this->sebagai($this->pemilik)->delete(self::URL . "tiket/{$t}"), 403, 'bukan_pihak_berwenang', AksesTiket::PESAN_BUKAN_BERWENANG);

        foreach ([$this->admin, $this->petugasB] as $akun) {
            $this->sebagai($akun)->get(self::URL . "tiket/{$t}")->assertStatus(404);
            $this->assertSame(0, $this->data($this->sebagai($akun)->get(self::URL . 'tiket/ringkasan'))['belum_dibaca_petugas']);
        }

        $this->sebagai($this->pemilik)->post(self::URL . "tiket/{$t}/tutup")->assertStatus(200);
    }

    // ================================================================================================== AC-E04.14 flag = aksi

    public function testFlagSamaDenganHasilAksi(): void
    {
        $lain = $this->buatKategoriHs('Lain');
        $this->buatPetugasHs($this->pemilik2, [$lain]);

        $skenario = [
            'pemilik'                   => [$this->pemilik, $this->pemilik, $this->k1],
            'petugas aktif'             => [$this->petugasB, $this->pemilik, $this->k1],
            'petugas role 1'            => [$this->admin, $this->pemilik, $this->k1],
            'petugas nonaktif'          => [$this->nonaktif, $this->pemilik, $this->k1],
            'pemilik petugas kat. lain' => [$this->pemilik2, $this->pemilik2, $this->k1],
            'pemilik tiket NULL'        => [$this->pemilik, $this->pemilik, null],
            'petugas kategori lain'     => [$this->petugasK2, $this->pemilik, $this->k1],
            'pemilik petugas kat. itu'  => [$this->petugasB, $this->petugasB, $this->k1],
        ];

        foreach ($skenario as $nama => [$aktor, $pemilikTiket, $kat]) {
            $flag  = $this->flagUntuk($aktor, $this->tiket($pemilikTiket, $kat, '2026-11-17 01:00:00'));
            $hasil = [
                'bisa_balas' => $this->sebagai($aktor)->post(self::URL . 'tiket/' . $this->tiket($pemilikTiket, $kat, '2026-11-17 01:00:00') . '/pesan', ['msg' => 'x'])->response()->getStatusCode() === 201,
                'bisa_tutup' => $this->sebagai($aktor)->post(self::URL . 'tiket/' . $this->tiket($pemilikTiket, $kat, '2026-11-17 01:00:00') . '/tutup')->response()->getStatusCode() === 200,
                'bisa_hapus' => $this->sebagai($aktor)->delete(self::URL . 'tiket/' . $this->tiket($pemilikTiket, $kat, '2026-11-17 01:00:00'))->response()->getStatusCode() === 200,
            ];
            $this->assertSame($hasil, $flag, $nama);
        }
    }

    // ================================================================================================== §7.8 thread tidak ter-mix

    public function testThreadTidakTerMixDanTanpaDependensiNotifikasi(): void
    {
        $a = $this->tiket($this->pemilik, $this->k1, null);
        $b = $this->tiket($this->pemilik2, $this->k1, null);

        foreach ([[$a, 'A1'], [$b, 'B1'], [$a, 'A2'], [$b, 'B2']] as $i => [$t, $isi]) {
            $this->buatPesanHs($t, $this->admin, $isi, '2026-11-17 0' . ($i + 1) . ':00:00');
        }

        $this->assertSame(['A1', 'A2'], array_column($this->data($this->sebagai($this->pemilik)->get(self::URL . "tiket/{$a}"))['pesan'], 'isi'));
        $this->assertSame(['B1', 'B2'], array_column($this->data($this->sebagai($this->pemilik2)->get(self::URL . "tiket/{$b}"))['pesan'], 'isi'));

        // BP8-1: seluruh modul Halo Simpeg (library, controller, model; termasuk chatbot) tidak mengimpor notifikasi/inbox.
        $berkas = [];

        foreach (['Libraries/HaloSimpeg', 'Controllers/Api/HaloSimpeg', 'Models/HaloSimpeg'] as $folder) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH . $folder, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getExtension() === 'php') {
                    $berkas[] = $f->getPathname();
                }
            }
        }

        $this->assertGreaterThan(10, count($berkas));

        foreach ($berkas as $path) {
            $kode = '';

            // Komentar/docblock diabaikan (pola PusatNotifikasiArsitekturTest): yang dicek hanya kode.
            foreach (token_get_all((string) file_get_contents($path)) as $tok) {
                $kode .= is_array($tok) ? (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $tok[1]) : $tok;
            }

            foreach (['NotifikasiService', 'InboxModel', "'inbox'", "'inbox_msg'", '"inbox"', '"inbox_msg"'] as $kata) {
                $this->assertStringNotContainsString($kata, $kode, "{$path} memuat {$kata}");
            }
        }
    }

    // ================================================================================================== helper

    /**
     * @return array{bisa_balas: bool, bisa_tutup: bool, bisa_hapus: bool}
     */
    private function flagUntuk(int $aktor, int $tiket): array
    {
        $res = $this->sebagai($aktor)->get(self::URL . "tiket/{$tiket}");

        if ($res->response()->getStatusCode() === 404) {
            return ['bisa_balas' => false, 'bisa_tutup' => false, 'bisa_hapus' => false];
        }

        $d = $this->data($res);

        return ['bisa_balas' => $d['bisa_balas'], 'bisa_tutup' => $d['bisa_tutup'], 'bisa_hapus' => $d['bisa_hapus']];
    }

    /**
     * Tiket + (bila `$waktu` tidak null) satu pesan dari pemilik pada waktu itu.
     *
     * @param array<string, mixed> $kolom
     */
    private function tiket(int $pemilik, ?int $kategori, ?string $waktu = '2026-11-17 01:00:00', array $kolom = []): int
    {
        $id = $this->buatTiketHs($kategori, $pemilik);
        $this->db->table('disiplin_ticket')->where('id_inbox', $id)->update(['subject' => "Subjek {$id}"] + $kolom);

        if ($waktu !== null) {
            $this->buatPesanHs($id, $pemilik, "Isi {$id}", $waktu);
        }

        return $id;
    }

    private function tiketMilik(int $pemilik, int $kategori): int
    {
        return $this->tiket($pemilik, $kategori, '2026-11-17 00:30:00', ['sender_name' => (string) $this->db->table('pegawai p')->select('p.nama')->join('pengguna u', 'u.nip = p.nip')->where('u.id_pengguna', $pemilik)->get()->getRow('nama')]);
    }

    private function akunPegawai(string $nama, int $role): int
    {
        return $this->buatAkunUntuk($this->buatPegawai(['nama' => $nama]), $role);
    }

    private function buatAkunTanpaNipHs(int $role): int
    {
        $this->db->table('pengguna')->insert(['username' => 'tanpa.pegawai', 'name' => 'Tanpa Pegawai', 'user_level' => $role, 'status' => '1']);

        return (int) $this->db->insertID();
    }

    private function idAkunRole(int $role): int
    {
        return (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole($role))->get()->getRow('id_pengguna');
    }

    private function sebagai(int $idPengguna): static
    {
        return $this->asUser((array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray());
    }

    /**
     * @return array<mixed>
     */
    private function data(TestResponse $res): array
    {
        $this->assertContains($res->response()->getStatusCode(), [200, 201], (string) $res->getJSON());

        return $this->json($res)['data'];
    }

    private function harapGalat(TestResponse $res, int $status, ?string $kode, string $pesan): void
    {
        $res->assertStatus($status);
        $body = $this->json($res);
        $this->assertSame([$pesan, $kode], [$body['message'] ?? null, $body['code'] ?? null]);
    }

    private function sidikTiket(): string
    {
        return sha1(json_encode([
            $this->db->table('disiplin_ticket')->orderBy('id_inbox')->get()->getResultArray(),
            $this->db->table('disiplin_ticket_msg')->orderBy('id_inbox_msg')->get()->getResultArray(),
            $this->db->table('audit_logs')->countAllResults(),
        ], JSON_THROW_ON_ERROR));
    }
}
