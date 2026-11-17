<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use App\Libraries\CacheService;
use App\Libraries\HaloSimpeg\PetugasService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-070 — master Halo Simpeg (kategori, petugas, kontak): AC-E02.1..AC-E02.9 dan AC-E07.1 (PRD Fase 8 v1.3 §7.2, §6.1b,
 * §7.3a). Role per route disapu `KontrakMatriksRoleTest`; di sini aturan service, validasi, audit, dan K-A4 (1').
 *
 * @internal
 */
final class MasterHaloSimpegTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/halo-simpeg/';

    protected $seed = AuthSeeder::class;

    private int $admin;

    private int $satkerKepeg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetWebConfig();
        $this->satkerKepeg = $this->buatSatker();
        service('webConfigService')->set('id_satker_kepegawaian', ['config_value' => (string) $this->satkerKepeg]);
        $this->admin = $this->idAkunRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        $this->resetWebConfig();
        parent::tearDown();
    }

    // -------------------------------------------------------------------- kategori (AC-E02.1, AC-E02.4, AC-E02.6)

    public function testKategoriTambahUbahHapusRole1TercatatAudit(): void
    {
        $res = $this->kirim('post', 'kategori', ['question_category' => '  Cuti  ']);
        $res->assertStatus(201);
        $baru = $this->data($res);
        $this->assertSame(['nama' => 'Cuti', 'status' => 1, 'jumlah_petugas' => 0, 'bisa_hapus' => true], array_intersect_key($baru, array_flip(['nama', 'status', 'jumlah_petugas', 'bisa_hapus'])));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $baru['dibuat_pada']);

        // Unik nama (trim, ci) [K LS:392-424].
        $this->harapGalat($this->kirim('post', 'kategori', ['question_category' => 'CUTI ']), 422, 'kategori_duplikat', 'Kategori sudah terdaftar.');
        $this->harapGalat($this->kirim('post', 'kategori', ['question_category' => '']), 422, null, 'Nama kategori wajib diisi.');
        $this->harapGalat($this->kirim('post', 'kategori', ['question_category' => str_repeat('é', 251)]), 422, null, 'Nama kategori maksimal 250 karakter.');
        $this->kirim('post', 'kategori', ['question_category' => str_repeat('é', 250)])->assertStatus(201);
        $this->harapGalat($this->kirim('post', 'kategori', ['question_category' => 'Lain', 'status' => 2]), 422, null, 'Status tidak valid.');

        $id   = $baru['id'];
        $ubah = $this->data($this->kirim('put', "kategori/{$id}", ['question_category' => 'Cuti Tahunan', 'status' => 0]));
        $this->assertSame(['Cuti Tahunan', 0], [$ubah['nama'], $ubah['status']]);
        $this->kirim('put', "kategori/{$id}", ['question_category' => 'Cuti Tahunan'])->assertStatus(200);
        $this->assertSame(0, $this->data($this->kirim('get', 'kategori?status=0'))['items'][0]['status'], 'PUT tanpa status = nilai lama');

        $this->assertSame(['id' => $id], $this->data($this->kirim('delete', "kategori/{$id}")));
        $this->harapGalat($this->kirim('delete', "kategori/{$id}"), 404, null, 'Data tidak ditemukan.');
        $event = array_column($this->db->table('audit_logs')->select('event, COUNT(*) AS n')->where('entity', 'question_category')->where('entity_id', $id)
            ->groupBy('event')->get()->getResultArray(), 'n', 'event');
        $event = array_map('intval', $event);
        ksort($event);
        $this->assertSame(['create' => 1, 'delete' => 1, 'update' => 2], $event, 'AC-E02.6');
    }

    public function testHapusKategoriDipakaiTiketAtauPetugasDitolak(): void
    {
        $diPetugas = $this->buatKategoriHs('Dipakai Petugas');
        $diTiket   = $this->buatKategoriHs('Dipakai Tiket');
        $bebas     = $this->buatKategoriHs('Bebas');
        $this->buatPetugasHs($this->admin, [$diPetugas]);
        $this->buatTiketHs($diTiket, $this->admin, 1);

        $items = array_column($this->data($this->kirim('get', 'kategori'))['items'], null, 'id');
        $this->assertSame([false, false, true], [$items[$diPetugas]['bisa_hapus'], $items[$diTiket]['bisa_hapus'], $items[$bebas]['bisa_hapus']]);
        $this->assertSame(1, $items[$diPetugas]['jumlah_petugas']);

        foreach ([$diPetugas, $diTiket] as $id) {
            $this->harapGalat($this->kirim('delete', "kategori/{$id}"), 422, 'kategori_dipakai', 'Kategori masih dipakai tiket atau petugas. Nonaktifkan kategori ini.');
        }

        $this->kirim('delete', "kategori/{$bebas}")->assertStatus(200);
    }

    public function testKategoriOpsiHanyaAktifUrutDibuatUntukRole1Sampai7(): void
    {
        $b = $this->buatKategoriHs('B', 1, '2026-11-17 02:00:00');
        $a = $this->buatKategoriHs('A', 1, '2026-11-17 01:00:00');
        $c = $this->buatKategoriHs('C', 1, '2026-11-17 01:00:00');
        $this->buatKategoriHs('Nonaktif', 0);

        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::PPPK] as $role) {
            $opsi = $this->data($this->asRole($role)->get(self::URL . 'kategori/opsi'));
            $this->assertSame([$a, $c, $b], array_column($opsi, 'id'), "role {$role}: created_at naik + id naik (DBV-028 N3)");
            $this->assertSame(['id', 'nama'], array_keys($opsi[0]));
        }

        $daftar = $this->data($this->kirim('get', 'kategori?q=nonak'));
        $this->assertSame(1, $daftar['total']);
        $this->harapGalat($this->kirim('get', 'kategori?status=9'), 422, null, 'Status tidak valid.');
        $this->kirim('get', 'kategori?page[]=1')->assertStatus(422);
    }

    // -------------------------------------------------------------------- petugas (AC-E02.2, AC-E02.3, AC-E02.5)

    public function testPetugasBanyakKategoriDanKategoriBanyakPetugasSetDiganti(): void
    {
        $k1        = $this->buatKategoriHs('Satu');
        $k2        = $this->buatKategoriHs('Dua');
        $k3        = $this->buatKategoriHs('Tiga');
        [, $akunB] = $this->pegawaiKandidat('Pegawai Kandidat');

        $a = $this->data($this->kirim('post', 'petugas', ['id_user' => $this->admin, 'category' => [$k2, $k1], 'email' => 'a@contoh.test', 'no_hp' => ' 0812 ']));
        $this->assertSame([$k1, $k2], array_column($a['kategori'], 'id'), 'urut id');
        $this->assertSame(['0812', 1, true, null], [$a['no_hp'], $a['status'], $a['bisa_hapus'], $a['alasan_tidak_bisa_hapus']]);
        $b = $this->data($this->kirim('post', 'petugas', ['id_user' => $akunB, 'category' => [$k1], 'email' => 'b@contoh.test']));
        $this->assertNull($b['no_hp']);
        $this->assertSame(2, array_column($this->data($this->kirim('get', 'kategori'))['items'], 'jumlah_petugas', 'id')[$k1], 'satu kategori ≥ 2 petugas');

        // Ganti set kategori: {1,2} → {2,3}, satu transaksi, audit per baris yang berubah.
        $auditOc = $this->jumlahAudit('officer_category');
        $ubah    = $this->data($this->kirim('put', "petugas/{$a['id']}", ['id_user' => $this->admin, 'category' => [$k3, $k2], 'email' => 'a2@contoh.test']));
        $this->assertSame([$k2, $k3], array_column($ubah['kategori'], 'id'));
        $this->assertSame($auditOc + 2, $this->jumlahAudit('officer_category'), 'lepas k1 + sisip k3');

        // Gagal validasi → tidak ada yang berubah.
        $this->harapGalat($this->kirim('put', "petugas/{$a['id']}", ['id_user' => $this->admin, 'category' => [$k1, 999999], 'email' => 'a@contoh.test']), 422, null, 'Kategori tidak tersedia.');
        $this->harapGalat($this->kirim('put', "petugas/{$a['id']}", ['id_user' => $this->admin, 'category' => [], 'email' => 'a@contoh.test']), 422, null, 'Minimal satu kategori.');
        $this->assertSame([$k2, $k3], array_column($this->data($this->kirim('get', 'petugas?q=a2@contoh'))['items'][0]['kategori'], 'id'));

        $this->harapGalat($this->kirim('post', 'petugas', ['id_user' => '', 'category' => [$k1], 'email' => 'x']), 422, null, 'Akun petugas wajib dipilih.');
        $galat = $this->json($this->kirim('post', 'petugas', ['id_user' => $this->admin + 1000, 'category' => [$k1], 'email' => 'x', 'no_hp' => str_repeat('9', 21)]))['errors'];
        $this->assertSame(['Akun tidak memenuhi syarat petugas.'], $galat['id_user']);
        $this->assertSame(['Format email tidak valid.'], $galat['email']);
        $this->assertSame(['Nomor HP maksimal 20 karakter.'], $galat['no_hp']);
        $this->assertGreaterThan(0, $this->jumlahAudit('support_officer'));
    }

    public function testPetugasDuplikatDitolak(): void
    {
        $k = $this->buatKategoriHs('Satu');
        $this->kirim('post', 'petugas', ['id_user' => $this->admin, 'category' => [$k], 'email' => 'a@contoh.test'])->assertStatus(201);
        $this->harapGalat($this->kirim('post', 'petugas', ['id_user' => $this->admin, 'category' => [$k], 'email' => 'b@contoh.test']), 422, 'petugas_duplikat', 'Akun ini sudah terdaftar sebagai petugas.');
    }

    public function testKandidatPegawaiAktifSatkerKepegawaianDanAkunRole1(): void
    {
        [$nip, $akun] = $this->pegawaiKandidat('Zaenal Kandidat');
        $lain         = $this->buatPegawai(['nama' => 'Zaenal Satker Lain']);
        $this->buatAkunUntuk($lain, Role::PEGAWAI);
        [, $akunNonaktif] = $this->pegawaiKandidat('Zaenal Akun Nonaktif');
        $this->db->table('pengguna')->where('id_pengguna', $akunNonaktif)->update(['status' => '0']);
        $pensiun = $this->buatPegawaiDiSatker($this->satkerKepeg, ['nama' => 'Zaenal Pensiun', 'status' => 2]);
        $this->buatAkunUntuk($pensiun, Role::PEGAWAI);

        $hasil = $this->data($this->kirim('get', 'petugas/kandidat?search=zaenal'));
        $this->assertNull($hasil['peringatan']);
        $this->assertSame([$akun], array_column($hasil['items'], 'id_user'));
        $this->assertStringStartsWith($nip . ' - ', $hasil['items'][0]['label'], 'label NIP - nama - jabatan');
        $this->assertStringEndsWith(' - ' . $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRow('jabatan'), $hasil['items'][0]['label']);

        $this->db->table('pengguna')->where('id_pengguna', $this->admin)->update(['name' => 'Admin Kepegawaian Uji']);
        $admin = $this->data($this->kirim('get', 'petugas/kandidat?search=kepegawaian%20uji'));
        $this->assertSame([['id_user' => $this->admin, 'label' => 'Admin Kepegawaian Uji']], $admin['items'], 'akun role 1 → nama akun');

        $this->harapGalat($this->kirim('get', 'petugas/kandidat?search=z'), 422, null, 'Kata kunci minimal 2 karakter.');
        $this->harapGalat($this->kirim('get', 'petugas/kandidat?search=' . str_repeat('z', 51)), 422, null, 'Kata kunci maksimal 50 karakter.');

        // Bukan kandidat → 422 saat tambah; satker kosong → hanya akun role 1 + peringatan.
        $k = $this->buatKategoriHs('Satu');
        $this->harapGalat($this->kirim('post', 'petugas', ['id_user' => $akunNonaktif, 'category' => [$k], 'email' => 'x@contoh.test']), 422, null, 'Akun tidak memenuhi syarat petugas.');
        service('webConfigService')->delete('id_satker_kepegawaian');
        $this->resetWebConfig();
        $kosong = $this->data($this->kirim('get', 'petugas/kandidat?search=zaenal'));
        $this->assertSame([[], PetugasService::PERINGATAN_SATKER_KOSONG], [$kosong['items'], $kosong['peringatan']]);
        $this->cocokFixtureHs('kandidat-peringatan', $this->kirim('get', 'petugas/kandidat?search=kepegawaian%20uji'), 200);
    }

    // -------------------------------------------------------------------- K-A4 (1') (AC-E02.8) + peringatan

    public function testHapusPetugasDuaArahDanBisaHapusSamaDenganAksi(): void
    {
        $open   = $this->buatKategoriHs('Kepegawaian');
        $tutup  = $this->buatKategoriHs('Selesai');
        [, $u2] = $this->pegawaiKandidat('Petugas Dua');
        [, $u3] = $this->pegawaiKandidat('Petugas Tiga');
        $this->buatTiketHs($open, $u3, 0);
        $this->buatTiketHs($tutup, $u3, 1);
        $a = $this->buatPetugasHs($this->admin, [$open, $tutup]);
        $b = $this->buatPetugasHs($u2, [$open], 0);
        $c = $this->buatPetugasHs($u3, [$tutup]);

        // Petugas kedua di kategori ber-tiket Open → boleh; kategori hanya tiket Closed → boleh.
        $this->assertSame([true, true, true], $this->bisaHapus([$a, $b, $c]));
        $this->kirim('delete', "petugas/{$c}")->assertStatus(200);
        $this->kirim('delete', "petugas/{$a}")->assertStatus(200);
        $this->assertSame(0, $this->db->table('officer_category')->where('id_support_officer', $a)->countAllResults());

        // Petugas terakhir (NONAKTIF pun) kategori ber-tiket Open → 422 + bisa_hapus false dengan teks yang sama.
        $pesan = PetugasService::pesanTerakhir(['Kepegawaian']);
        $baris = $this->data($this->kirim('get', 'petugas'))['items'][0];
        $this->assertSame([false, $pesan], [$baris['bisa_hapus'], $baris['alasan_tidak_bisa_hapus']]);
        $this->cocokFixtureHs('galat-422-petugas-terakhir', $this->harapGalat($this->kirim('delete', "petugas/{$b}"), 422, 'petugas_terakhir', $pesan));
        $this->assertSame(1, $this->db->table('support_officer')->where('id_support_officer', $b)->countAllResults());
    }

    public function testNonaktifkanPetugasAktifTerakhirBolehDenganPeringatan(): void
    {
        $k      = $this->buatKategoriHs('Kepegawaian');
        $k2     = $this->buatKategoriHs('Gaji');
        [, $u2] = $this->pegawaiKandidat('Petugas Dua');
        $this->buatTiketHs($k, $u2, 0);
        $this->buatTiketHs($k2, $u2, 0);
        $a = $this->buatPetugasHs($this->admin, [$k, $k2]);
        $this->buatPetugasHs($u2, [$k], 0);
        $c = $this->buatPetugasHs($this->buatAkunTambahan(), [$k2]);

        // k: petugas lain hanya nonaktif → peringatan; k2: masih ada petugas aktif lain → tidak disebut.
        $res = $this->kirim('patch', "petugas/{$a}/status", ['status' => 0]);
        $this->cocokFixtureHs('status-patch-peringatan', $res, 200);
        $this->assertSame('Petugas ini adalah petugas aktif terakhir pada kategori Kepegawaian. Tiket terbuka pada kategori tersebut tidak memiliki penjawab sampai petugas lain diaktifkan.', $this->data($res)['peringatan']);
        $this->assertSame(0, $this->data($res)['status']);

        // Status sama → 200 tanpa tulis/audit; Aktifkan → peringatan null.
        $audit = $this->jumlahAudit('support_officer');
        $this->assertNull($this->data($this->kirim('patch', "petugas/{$a}/status", ['status' => '0']))['peringatan']);
        $this->assertSame($audit, $this->jumlahAudit('support_officer'));
        $this->assertNull($this->data($this->kirim('patch', "petugas/{$a}/status", ['status' => 1]))['peringatan']);
        $this->assertSame($audit + 1, $this->jumlahAudit('support_officer'));
        $this->assertNull($this->data($this->kirim('patch', "petugas/{$c}/status", ['status' => 0]))['peringatan'], 'k2 masih dijawab petugas a');
    }

    public function testUbahPetugasMelepasKategoriAktifTerakhirMemberiPeringatan(): void
    {
        $open   = $this->buatKategoriHs('Kepegawaian');
        $lain   = $this->buatKategoriHs('Gaji');
        $tutup  = $this->buatKategoriHs('Selesai');
        $cuti   = $this->buatKategoriHs('Cuti');
        [, $u2] = $this->pegawaiKandidat('Petugas Dua');
        [, $u3] = $this->pegawaiKandidat('Petugas Tiga');
        $this->buatTiketHs($open, $u3, 0);
        $this->buatTiketHs($lain, $u3, 0);
        $this->buatTiketHs($tutup, $u3, 1);
        $a = $this->buatPetugasHs($this->admin, [$open, $lain, $tutup, $cuti]);
        $this->buatPetugasHs($u2, [$open], 0);
        $this->buatPetugasHs($u3, [$lain]);
        $isi = ['id_user' => $this->admin, 'email' => 'a@contoh.test'];

        // Tetap memegang semua kategori → null; lepas kategori yang masih dijawab petugas aktif lain / hanya tiket Closed → null.
        $this->assertNull($this->data($this->kirim('put', "petugas/{$a}", $isi + ['category' => [$open, $lain, $tutup, $cuti]]))['peringatan']);
        $this->assertNull($this->data($this->kirim('put', "petugas/{$a}", $isi + ['category' => [$open, $cuti]]))['peringatan']);

        // Lepas kategori ber-tiket Open yang petugas lainnya hanya nonaktif → peringatan (perubahan tetap disimpan, bukan 422).
        $res = $this->kirim('put', "petugas/{$a}", $isi + ['category' => [$cuti]]);
        $this->cocokFixtureHs('petugas-put-peringatan', $res, 200);
        $this->assertSame(PetugasService::peringatanAktifTerakhir(['Kepegawaian']), $this->data($res)['peringatan']);
        $this->assertSame([$cuti], array_column($this->data($res)['kategori'], 'id'));

        // Nonaktifkan lewat PUT sama dengan PATCH status.
        $this->kirim('put', "petugas/{$a}", $isi + ['category' => [$open, $cuti]])->assertStatus(200);
        $this->assertSame(PetugasService::peringatanAktifTerakhir(['Kepegawaian']), $this->data($this->kirim('put', "petugas/{$a}", $isi + ['category' => [$open, $cuti], 'status' => 0]))['peringatan']);
        $this->assertNull($this->data($this->kirim('put', "petugas/{$a}", $isi + ['category' => [$cuti]]))['peringatan'], 'petugas nonaktif bukan penjawab');
    }

    // -------------------------------------------------------------------- status terpisah (AC-E02.9)

    public function testStatusTerpisahIdempotenDomainDanAudit(): void
    {
        $k       = $this->buatKategoriHs('Kenaikan Pangkat', 0);
        $kontak  = $this->buatKontakHs('Helpdesk', '0800100');
        $petugas = $this->buatPetugasHs($this->admin, [$this->buatKategoriHs('Tanpa Tiket')]);

        foreach (['kategori' => [$k, 'question_category'], 'kontak' => [$kontak, 'help_desk_contact'], 'petugas' => [$petugas, 'support_officer']] as $jalur => [$id, $entitas]) {
            $this->harapGalat($this->kirim('patch', "{$jalur}/{$id}/status", ['status' => 2]), 422, null, 'Status tidak valid.');
            $this->harapGalat($this->kirim('patch', "{$jalur}/{$id}/status", []), 422, null, 'Status tidak valid.');
            $this->harapGalat($this->kirim('patch', "{$jalur}/999999/status", ['status' => 1]), 404, null, 'Data tidak ditemukan.');

            $audit = $this->jumlahAudit($entitas);
            $awal  = $jalur === 'kategori' ? 0 : 1;
            $this->assertNull($this->data($this->kirim('patch', "{$jalur}/{$id}/status", ['status' => $awal]))['peringatan']);
            $this->assertSame($audit, $this->jumlahAudit($entitas), "{$jalur}: status sama → tanpa audit");

            $res = $this->kirim('patch', "{$jalur}/{$id}/status", ['status' => 1 - $awal]);
            $this->assertSame([1 - $awal, null], [$this->data($res)['status'], $this->data($res)['peringatan']]);
            $this->assertSame($audit + 1, $this->jumlahAudit($entitas), "{$jalur}: berubah → audit");
        }

        $this->cocokFixtureHs('status-patch', $this->kirim('patch', "kategori/{$k}/status", ['status' => 0]), 200);
    }

    // -------------------------------------------------------------------- kontak (AC-E07.1)

    public function testKontakAktifRole1Sampai7DanKelolaRole1(): void
    {
        $b = $this->buatKontakHs('B', '0800200', 1, 'b@contoh.test', '2026-11-17 02:00:00');
        $a = $this->buatKontakHs('A', '0800100', 1, null, '2026-11-17 01:00:00');
        $this->buatKontakHs('Lama', '0800300', 0);

        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK] as $role) {
            $aktif = $this->data($this->asRole($role)->get(self::URL . 'kontak/aktif'));
            $this->assertSame([$a, $b], array_column($aktif, 'id'), "role {$role}");
            $this->assertSame(['id', 'nama', 'kontak', 'email'], array_keys($aktif[0]));
        }

        $this->asRole(Role::PEGAWAI)->get(self::URL . 'kontak')->assertStatus(403);
        $semua = $this->data($this->kirim('get', 'kontak'));
        $this->assertSame(3, $semua['total']);
        $this->assertSame(1, $this->data($this->kirim('get', 'kontak?status=0'))['total']);
        $this->assertSame(1, $this->data($this->kirim('get', 'kontak?q=0800200'))['total']);

        $baru = $this->data($this->kirim('post', 'kontak', ['name' => ' Layanan Gaji ', 'contact' => '0800400', 'email' => '']));
        $this->assertSame(['Layanan Gaji', '0800400', null, 1], [$baru['nama'], $baru['kontak'], $baru['email'], $baru['status']]);
        $this->harapGalat($this->kirim('post', 'kontak', ['name' => 'X', 'contact' => '0800400']), 422, 'kontak_duplikat', 'Nomor kontak sudah terdaftar.');
        $this->harapGalat($this->kirim('put', "kontak/{$a}", ['name' => 'A', 'contact' => '0800200']), 422, 'kontak_duplikat', 'Nomor kontak sudah terdaftar.');
        $galat = $this->json($this->kirim('post', 'kontak', ['name' => str_repeat('n', 151), 'contact' => str_repeat('1', 21), 'email' => 'bukan-email']))['errors'];
        $this->assertSame([['Nama maksimal 150 karakter.'], ['Nomor kontak maksimal 20 karakter.'], ['Format email tidak valid.']], [$galat['name'], $galat['contact'], $galat['email']]);
        $this->harapGalat($this->kirim('post', 'kontak', ['name' => '', 'contact' => '']), 422, null, 'Nama wajib diisi.');

        $this->kirim('put', "kontak/{$a}", ['name' => 'A2', 'contact' => '0800101', 'email' => 'a@contoh.test'])->assertStatus(200);
        $this->assertSame(['id' => $a], $this->data($this->kirim('delete', "kontak/{$a}")));
        $this->assertGreaterThanOrEqual(3, $this->jumlahAudit('help_desk_contact'));
    }

    // -------------------------------------------------------------------- kontrak beku: fixture = respons nyata

    public function testBentukResponsSamaDenganFixture(): void
    {
        $k1    = $this->buatKategoriHs('Cuti');
        $k2    = $this->buatKategoriHs('Kenaikan Pangkat', 0);
        [, $u] = $this->pegawaiKandidat('Pegawai Contoh');
        $this->buatTiketHs($k2, $u, 0);
        $this->buatPetugasHs($this->admin, [$k1]);
        $this->buatPetugasHs($u, [$k2], 0);
        $this->buatKontakHs('Helpdesk SDM', '0800100', 1, 'helpdesk@contoh.test');
        $this->buatKontakHs('Kontak Lama', '0800300', 0);

        $this->cocokFixtureHs('kategori-daftar', $this->kirim('get', 'kategori'), 200);
        $this->cocokFixtureHs('kategori-opsi', $this->asRole(Role::PEGAWAI)->get(self::URL . 'kategori/opsi'), 200);
        $this->cocokFixtureHs('petugas-daftar', $this->kirim('get', 'petugas'), 200);
        $this->cocokFixtureHs('kandidat', $this->kirim('get', 'petugas/kandidat?search=pegawai'), 200);
        $this->cocokFixtureHs('petugas-saya-bukan', $this->asRole(Role::PPPK)->get(self::URL . 'petugas/saya'), 200);
        $this->cocokFixtureHs('kontak-aktif', $this->asRole(Role::PEGAWAI)->get(self::URL . 'kontak/aktif'), 200);
        $this->cocokFixtureHs('kontak-daftar', $this->kirim('get', 'kontak'), 200);
        $this->cocokFixtureHs('galat-422-master', $this->kirim('post', 'kategori', ['question_category' => '']), 422);
        $this->cocokFixtureHs('galat-422-kategori-duplikat', $this->kirim('post', 'kategori', ['question_category' => 'cuti']), 422);
        $this->cocokFixtureHs('galat-404-master', $this->kirim('put', 'kontak/999999', ['name' => 'A', 'contact' => '1']), 404);
    }

    // -------------------------------------------------------------------- M6: batas field §7.3a (data per kolom)

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function batasKolom(): iterable
    {
        $email = static fn (int $n): string => str_repeat('a', 64) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', $n - 64 - 1 - 60 - 1 - 5) . '.test';

        yield 'kategori nama' => ['kategori', 'question_category', str_repeat('é', 250), str_repeat('é', 251), 'Nama kategori maksimal 250 karakter.'];
        yield 'petugas email' => ['petugas', 'email', $email(150), $email(151), 'Email maksimal 150 karakter.'];
        yield 'petugas no_hp' => ['petugas', 'no_hp', str_repeat('8', 20), str_repeat('8', 21), 'Nomor HP maksimal 20 karakter.'];
        yield 'kontak nama' => ['kontak', 'name', str_repeat('ñ', 150), str_repeat('ñ', 151), 'Nama maksimal 150 karakter.'];
        yield 'kontak nomor' => ['kontak', 'contact', str_repeat('7', 20), str_repeat('7', 21), 'Nomor kontak maksimal 20 karakter.'];
        yield 'kontak email' => ['kontak', 'email', $email(150), $email(151), 'Email maksimal 150 karakter.'];
    }

    #[DataProvider('batasKolom')]
    public function testBatasKolomLolosDiBatasDitolakLewatBatas(string $jalur, string $kolom, string $pas, string $lewat, string $pesan): void
    {
        $dasar = match ($jalur) {
            'kategori' => ['question_category' => 'Dasar'],
            'petugas'  => ['id_user' => $this->admin, 'category' => [$this->buatKategoriHs('Satu')], 'email' => 'a@contoh.test'],
            default    => ['name' => 'Dasar', 'contact' => '0800999'],
        };

        $this->assertSame([$pesan], $this->json($this->kirim('post', $jalur, [$kolom => $lewat] + $dasar))['errors'][$kolom]);
        $this->kirim('post', $jalur, [$kolom => $pas] + $dasar)->assertStatus(201);
    }

    public function testWajibKosongNonTeksDanIdTidakSah(): void
    {
        $k = $this->buatKategoriHs('Satu');
        $this->assertSame(
            ['question_category' => ['Nama kategori wajib diisi.']],
            $this->json($this->kirim('post', 'kategori', ['question_category' => '   ']))['errors'],
        );
        $this->assertSame(
            ['name' => ['Nama wajib diisi.'], 'contact' => ['Nomor kontak wajib diisi.']],
            $this->json($this->kirim('post', 'kontak', []))['errors'],
        );
        $this->assertSame(
            ['category' => ['Minimal satu kategori.'], 'id_user' => ['Akun petugas wajib dipilih.'], 'email' => ['Email wajib diisi.']],
            $this->json($this->kirim('post', 'petugas', []))['errors'],
        );

        // M4: isian bukan teks → 422 per kolom, bukan dianggap kosong.
        $galat = $this->json($this->kirim('post', 'petugas', ['id_user' => [$this->admin], 'category' => [$k], 'email' => [], 'no_hp' => ['x' => 1]]))['errors'];
        $this->assertSame([['Isian harus berupa teks.'], ['Isian harus berupa teks.'], ['Isian harus berupa teks.']], [$galat['id_user'], $galat['email'], $galat['no_hp']]);
        $this->assertSame(['Isian harus berupa teks.'], $this->json($this->kirim('post', 'kontak', ['name' => ['a'], 'contact' => '1', 'email' => true]))['errors']['name']);
        $this->assertSame(['Isian harus berupa teks.'], $this->json($this->kirim('post', 'kategori', ['question_category' => ['a']]))['errors']['question_category']);

        $this->harapGalat($this->kirim('put', 'kategori/0', ['question_category' => 'A']), 422, null, 'ID tidak valid.');
        $this->harapGalat($this->kirim('patch', 'petugas/0/status', ['status' => 1]), 422, null, 'ID tidak valid.');
    }

    // -------------------------------------------------------------------- M6: paginasi, filter, LIKE (S3), kandidat (S5)

    public function testParameterDaftarDanLikeLiteral(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->buatKategoriHs("Kategori {$i}");
        }

        $this->buatKategoriHs('Diskon 100% pegawai');
        $this->buatKontakHs('Promo 50%', '0800500');
        $this->buatKontakHs('Biasa', '0800600');

        $this->assertSame([100, 1], [$this->data($this->kirim('get', 'kategori?per_page=500'))['per_page'], $this->data($this->kirim('get', 'kategori?page=1'))['page']]);
        $this->assertSame(1, $this->data($this->kirim('get', 'kategori?per_page=abc'))['per_page']);
        $this->assertSame(2, count($this->data($this->kirim('get', 'kategori?per_page=2&page=2'))['items']));
        $this->kirim('get', 'kategori?page[]=1')->assertStatus(422);
        $this->assertArrayHasKey('page', $this->json($this->kirim('get', 'kontak?page=0'))['errors']);
        $this->assertSame(['Status tidak valid.'], $this->json($this->kirim('get', 'petugas?status=x'))['errors']['status']);

        // `%` dicari sebagai karakter, bukan wildcard.
        $this->assertSame(1, $this->data($this->kirim('get', 'kategori?q=%25'))['total']);
        $this->assertSame(1, $this->data($this->kirim('get', 'kontak?q=%25'))['total']);
        $this->assertSame(0, $this->data($this->kirim('get', 'kategori?q=_'))['total']);
        $this->assertSame([], $this->data($this->kirim('get', 'petugas/kandidat?search=%25%25'))['items']);
    }

    public function testKandidatMemuatPetugasTerdaftarTetapiSimpanDitolak(): void
    {
        $this->db->table('pengguna')->where('id_pengguna', $this->admin)->update(['name' => 'Admin Kepegawaian Uji']);
        $this->buatPetugasHs($this->admin, [$this->buatKategoriHs('Satu')]);

        $this->assertSame([$this->admin], array_column($this->data($this->kirim('get', 'petugas/kandidat?search=kepegawaian%20uji'))['items'], 'id_user'));
        $this->harapGalat($this->kirim('post', 'petugas', ['id_user' => $this->admin, 'category' => [$this->buatKategoriHs('Dua')], 'email' => 'a@contoh.test']), 422, 'petugas_duplikat', 'Akun ini sudah terdaftar sebagai petugas.');
        $this->assertSame(
            'Satker kepegawaian belum diatur di Web Config; hanya akun Super Admin yang dapat dipilih.',
            PetugasService::PERINGATAN_SATKER_KOSONG,
        );
    }

    // -------------------------------------------------------------------- M1 (S12): role 8 bukan petugas

    public function testAkunRole8BukanKandidatDanBukanPetugasAktif(): void
    {
        $nip  = $this->buatPegawaiDiSatker($this->satkerKepeg, ['nama' => 'Pimpinan Kandidat']);
        $akun = $this->buatAkunUntuk($nip, Role::PIMPINAN);
        $k    = $this->buatKategoriHs('Kepegawaian');

        $this->assertSame([], $this->data($this->kirim('get', 'petugas/kandidat?search=pimpinan'))['items']);
        $this->harapGalat($this->kirim('post', 'petugas', ['id_user' => $akun, 'category' => [$k], 'email' => 'p@contoh.test']), 422, null, 'Akun tidak memenuhi syarat petugas.');

        // Petugas role 8 hasil impor tidak dihitung penjawab aktif → menonaktifkan petugas lain memberi peringatan.
        $this->buatTiketHs($k, $akun, 0);
        $a = $this->buatPetugasHs($this->admin, [$k]);
        $this->buatPetugasHs($akun, [$k]);
        $this->assertSame(PetugasService::peringatanAktifTerakhir(['Kepegawaian']), $this->data($this->kirim('patch', "petugas/{$a}/status", ['status' => 0]))['peringatan']);
    }

    // -------------------------------------------------------------------- M6: AC-E02.8 cabang aktif, teks literal, akun nonaktif

    public function testPetugasTerakhirAktifDanTeksLiteralDuaKategori(): void
    {
        $k1     = $this->buatKategoriHs('Cuti');
        $k2     = $this->buatKategoriHs('Gaji');
        [, $u2] = $this->pegawaiKandidat('Pemilik Tiket');
        $this->buatTiketHs($k1, $u2, 0);
        $this->buatTiketHs($k2, $u2, 0);
        $a = $this->buatPetugasHs($this->admin, [$k1, $k2]);

        $pesan = 'Petugas ini petugas terakhir pada kategori Cuti, Gaji yang masih memiliki tiket terbuka. Tambahkan petugas lain lebih dulu.';
        $this->assertSame([false, $pesan], array_values(array_intersect_key($this->data($this->kirim('get', 'petugas'))['items'][0], array_flip(['bisa_hapus', 'alasan_tidak_bisa_hapus']))));
        $this->harapGalat($this->kirim('delete', "petugas/{$a}"), 422, 'petugas_terakhir', $pesan);
    }

    public function testPetugasBerakunNonaktifBukanPenjawabTanpaPeringatan(): void
    {
        $k      = $this->buatKategoriHs('Kepegawaian');
        $lain   = $this->buatKategoriHs('Lain');
        [, $u2] = $this->pegawaiKandidat('Petugas Dua');
        $this->buatTiketHs($k, $u2, 0);
        $a = $this->buatPetugasHs($u2, [$k, $lain]);
        $this->db->table('pengguna')->where('id_pengguna', $u2)->update(['status' => '0']);

        $this->assertNull($this->data($this->kirim('put', "petugas/{$a}", ['id_user' => $u2, 'category' => [$lain], 'email' => 'a@contoh.test']))['peringatan']);
        $this->assertNull($this->data($this->kirim('patch', "petugas/{$a}/status", ['status' => 0]))['peringatan']);
    }

    // -------------------------------------------------------------------- M6: AC-E02.6 audit per event

    public function testAuditPerEventPetugasRelasiDanKontak(): void
    {
        $k1 = $this->buatKategoriHs('Satu');
        $k2 = $this->buatKategoriHs('Dua');
        $id = $this->data($this->kirim('post', 'petugas', ['id_user' => $this->admin, 'category' => [$k1], 'email' => 'a@contoh.test']))['id'];
        $this->kirim('put', "petugas/{$id}", ['id_user' => $this->admin, 'category' => [$k2], 'email' => 'b@contoh.test'])->assertStatus(200);
        $this->kirim('delete', "petugas/{$id}")->assertStatus(200);
        $kontak = $this->data($this->kirim('post', 'kontak', ['name' => 'A', 'contact' => '0800100']))['id'];
        $this->kirim('put', "kontak/{$kontak}", ['name' => 'B', 'contact' => '0800100'])->assertStatus(200);
        $this->kirim('delete', "kontak/{$kontak}")->assertStatus(200);

        $this->assertSame(['create' => 1, 'delete' => 1, 'update' => 1], $this->eventAudit('support_officer'));
        $this->assertSame(['create' => 2, 'delete' => 2], $this->eventAudit('officer_category'), 'sisip k1, lepas k1 + sisip k2, hapus k2');
        $this->assertSame(['create' => 1, 'delete' => 1, 'update' => 1], $this->eventAudit('help_desk_contact'));

        foreach (['support_officer', 'officer_category', 'help_desk_contact'] as $entitas) {
            foreach ($this->db->table('audit_logs')->where('entity', $entitas)->where('event', 'delete')->get()->getResultArray() as $baris) {
                $this->assertNotNull($baris['before_json'], "{$entitas}: delete menyimpan before");
                $this->assertSame($this->admin, (int) $baris['id_pengguna_actor']);
            }
        }
    }

    // -------------------------------------------------------------------- M2/M3: transaksi, kunci, galat DB

    #[Group('db-isolasi-penuh')]
    public function testRelasiGagalMembatalkanSemuaDan1062KunciLainTidakDisamarkan(): void
    {
        $k1  = $this->buatKategoriHs('Satu');
        $k2  = $this->buatKategoriHs('Dua');
        $id  = $this->buatPetugasHs($this->admin, [$k1], 1, 'lama@contoh.test');
        $trg = $this->db->prefixTable('trg_uji_m070_oc');
        $this->db->query("DROP TRIGGER IF EXISTS {$trg}");
        $this->db->query("CREATE TRIGGER {$trg} BEFORE INSERT ON " . $this->db->prefixTable('officer_category')
            . " FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO = 1062, MESSAGE_TEXT = 'Duplicate entry for key uk_officer_category'");

        try {
            $audit = $this->db->table('audit_logs')->countAllResults();
            // Galat asli 1062 kunci relasi diteruskan (→ 500 di produksi), bukan diterjemahkan menjadi petugas_duplikat palsu (M3).
            $this->assertSame(1062, $this->kodeGalatDb(fn () => $this->kirim('put', "petugas/{$id}", ['id_user' => $this->admin, 'category' => [$k2], 'email' => 'baru@contoh.test'])));
            $this->assertSame('lama@contoh.test', $this->db->table('support_officer')->where('id_support_officer', $id)->get()->getRow('email'), 'update dibatalkan');
            $this->assertSame([$k1], array_map('intval', array_column($this->db->table('officer_category')->where('id_support_officer', $id)->get()->getResultArray(), 'id_question_category')), 'relasi lama utuh');
            $this->assertSame($audit, $this->db->table('audit_logs')->countAllResults(), 'audit ikut batal');
        } finally {
            $this->db->query("DROP TRIGGER IF EXISTS {$trg}");
        }
    }

    #[Group('db-isolasi-penuh')]
    public function testBalapanDuplikatKontakDanHapusKategoriDiterjemahkan(): void
    {
        $k     = $this->buatKategoriHs('Satu');
        $trgK  = $this->db->prefixTable('trg_uji_m070_kontak');
        $trgQc = $this->db->prefixTable('trg_uji_m070_qc');
        $this->db->query("DROP TRIGGER IF EXISTS {$trgK}");
        $this->db->query("DROP TRIGGER IF EXISTS {$trgQc}");
        $this->db->query("CREATE TRIGGER {$trgK} BEFORE INSERT ON " . $this->db->prefixTable('help_desk_contact')
            . " FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO = 1062, MESSAGE_TEXT = 'Duplicate entry for key uk_help_desk_contact_contact'");
        $this->db->query("CREATE TRIGGER {$trgQc} BEFORE DELETE ON " . $this->db->prefixTable('question_category')
            . " FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO = 1451, MESSAGE_TEXT = 'Cannot delete or update a parent row'");

        try {
            $this->harapGalat($this->kirim('post', 'kontak', ['name' => 'A', 'contact' => '0800100']), 422, 'kontak_duplikat', 'Nomor kontak sudah terdaftar.');
            $this->harapGalat($this->kirim('delete', "kategori/{$k}"), 422, 'kategori_dipakai', 'Kategori masih dipakai tiket atau petugas. Nonaktifkan kategori ini.');
        } finally {
            $this->db->query("DROP TRIGGER IF EXISTS {$trgK}");
            $this->db->query("DROP TRIGGER IF EXISTS {$trgQc}");
        }
    }

    /**
     * M2: hapus petugas dan Nonaktifkan menunggu kunci kategori yang dipegang transaksi lain (jalur FOR UPDATE terpanggil), sehingga
     * cek petugas terakhir tidak bisa dilangkahi balapan; sesudah kunci lepas, aksi berjalan normal.
     */
    #[Group('db-isolasi-penuh')]
    public function testHapusDanNonaktifkanMenungguKunciKategori(): void
    {
        $k    = $this->buatKategoriHs('Satu');
        $id   = $this->buatPetugasHs($this->admin, [$k]);
        $lain = Database::connect('tests', false);
        $lain->transBegin();
        $lain->query('SELECT id_question_category FROM ' . $lain->escapeIdentifiers($lain->prefixTable('question_category')) . ' WHERE id_question_category = ? FOR UPDATE', [$k]);
        $tunggu = (int) $this->db->query('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->getRow('t');
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertSame(1205, $this->kodeGalatDb(fn () => $this->asRole(Role::SUPER_ADMIN)->delete(self::URL . "petugas/{$id}")), 'hapus menunggu kunci');
            $this->assertSame(1205, $this->kodeGalatDb(fn () => $this->kirim('patch', "petugas/{$id}/status", ['status' => 0])), 'nonaktifkan menunggu kunci');

            $this->assertSame([1, 1], [$this->db->table('support_officer')->where('id_support_officer', $id)->countAllResults(), (int) $this->db->table('support_officer')->where('id_support_officer', $id)->get()->getRow('status')]);
        } finally {
            $lain->transRollback();
            $lain->close();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = ' . $tunggu);
        }

        $this->kirim('delete', "petugas/{$id}")->assertStatus(200);
    }

    /**
     * W1: cek petugas terakhir membaca data terbaru — petugas lain di kategori yang sama dihapus (commit) lewat koneksi kedua,
     * lalu hapus petugas ini ditolak 422 (bukan memakai keadaan sebelum penghapusan).
     */
    #[Group('db-isolasi-penuh')]
    public function testCekPetugasTerakhirMembacaDataTerbaru(): void
    {
        $k      = $this->buatKategoriHs('Kepegawaian');
        [, $u2] = $this->pegawaiKandidat('Petugas Dua');
        $this->buatTiketHs($k, $u2, 0);
        $a = $this->buatPetugasHs($this->admin, [$k]);
        $b = $this->buatPetugasHs($u2, [$k]);

        $lain = Database::connect('tests', false);

        try {
            $lain->transBegin();
            $lain->table('officer_category')->where('id_support_officer', $b)->delete();
            $lain->table('support_officer')->where('id_support_officer', $b)->delete();
            $lain->transCommit();
        } finally {
            $lain->close();
        }

        $this->harapGalat($this->kirim('delete', "petugas/{$a}"), 422, 'petugas_terakhir', PetugasService::pesanTerakhir(['Kepegawaian']));
    }

    public function testPutPetugasDenganPetugasLainRole8TetapPeringatanDanCariPetugasLiteral(): void
    {
        $k    = $this->buatKategoriHs('Kepegawaian');
        $lain = $this->buatKategoriHs('Lain');
        $nip  = $this->buatPegawaiDiSatker($this->satkerKepeg, ['nama' => 'Pimpinan Impor']);
        $p8   = $this->buatAkunUntuk($nip, Role::PIMPINAN);
        $this->buatTiketHs($k, $p8, 0);
        $a = $this->buatPetugasHs($this->admin, [$k, $lain]);
        $this->buatPetugasHs($p8, [$k]);

        $res = $this->kirim('put', "petugas/{$a}", ['id_user' => $this->admin, 'category' => [$lain], 'email' => 'a@contoh.test']);
        $this->assertSame(PetugasService::peringatanAktifTerakhir(['Kepegawaian']), $this->data($res)['peringatan'], 'petugas role 8 bukan penjawab');

        $this->db->table('pengguna')->where('id_pengguna', $this->admin)->update(['name' => 'Admin 100% Uji']);
        $this->assertSame([$a], array_column($this->data($this->kirim('get', 'petugas?q=%25'))['items'], 'id'));
        $this->assertSame(0, $this->data($this->kirim('get', 'petugas?q=_'))['total']);
    }

    // -------------------------------------------------------------------- petugas/saya

    public function testPetugasSayaBukanAktifNonaktif(): void
    {
        $this->assertSame(['petugas' => false, 'aktif' => false, 'kategori' => [], 'bisa_ekspor' => false], $this->data($this->asRole(Role::PEGAWAI)->get(self::URL . 'petugas/saya')));

        $k1  = $this->buatKategoriHs('Satu');
        $k2  = $this->buatKategoriHs('Dua', 0);
        $id  = $this->idAkunRole(Role::PEGAWAI);
        $so  = $this->buatPetugasHs($id, [$k2, $k1]);
        $res = $this->asRole(Role::PEGAWAI)->get(self::URL . 'petugas/saya');
        $this->cocokFixtureHs('petugas-saya-aktif', $res, 200);
        $this->assertSame([true, true, true], [$this->data($res)['petugas'], $this->data($res)['aktif'], $this->data($res)['bisa_ekspor']]);
        $this->assertSame([['id' => $k1, 'nama' => 'Satu', 'status' => 1], ['id' => $k2, 'nama' => 'Dua', 'status' => 0]], $this->data($res)['kategori']);
        $this->assertArrayNotHasKey('id_support_officer', $this->data($res));

        $this->db->table('support_officer')->where('id_support_officer', $so)->update(['status' => 0]);
        $this->cocokFixtureHs('petugas-saya-nonaktif', $this->asRole(Role::PEGAWAI)->get(self::URL . 'petugas/saya'), 200);
        $this->assertFalse($this->data($this->asRole(Role::PEGAWAI)->get(self::URL . 'petugas/saya'))['aktif']);
    }

    // -------------------------------------------------------------------- helper

    /**
     * @param array<string, mixed>|null $body
     */
    private function kirim(string $verb, string $path, ?array $body = null): TestResponse
    {
        $req = $this->asRole(Role::SUPER_ADMIN);

        if ($body !== null) {
            $req = $req->withBodyFormat('json');
        }

        return $req->{$verb}(self::URL . $path, $body ?? []);
    }

    private function harapGalat(TestResponse $res, int $status, ?string $kode, string $pesan): TestResponse
    {
        $res->assertStatus($status);
        $body = $this->json($res);
        $this->assertSame($pesan, $body['message'] ?? null);
        $this->assertSame($kode, $body['code'] ?? null);

        return $res;
    }

    /**
     * @return array<mixed>
     */
    private function data(TestResponse $res): array
    {
        $this->assertContains($res->response()->getStatusCode(), [200, 201], (string) $res->getJSON());

        return $this->json($res)['data'];
    }

    /**
     * @param list<int> $id
     *
     * @return list<bool>
     */
    private function bisaHapus(array $id): array
    {
        $items = array_column($this->data($this->kirim('get', 'petugas'))['items'], 'bisa_hapus', 'id');

        return array_map(static fn (int $i): bool => $items[$i], $id);
    }

    /**
     * Pegawai aktif di satker kepegawaian + akun aktif.
     *
     * @return array{0: string, 1: int} NIP, id_pengguna
     */
    private function pegawaiKandidat(string $nama): array
    {
        $nip = $this->buatPegawaiDiSatker($this->satkerKepeg, ['nama' => $nama]);

        return [$nip, $this->buatAkunUntuk($nip, Role::PEGAWAI)];
    }

    private function buatAkunTambahan(): int
    {
        return $this->pegawaiKandidat('Petugas Tambahan')[1];
    }

    private function idAkunRole(int $role): int
    {
        return (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole($role))->get()->getRow('id_pengguna');
    }

    /** Kode galat DB yang dilempar `$aksi` (null bila tidak ada galat). */
    private function kodeGalatDb(callable $aksi): ?int
    {
        try {
            $aksi();
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            return (int) $e->getCode();
        }

        return null;
    }

    /**
     * @return array<string, int> event => jumlah, urut kunci
     */
    private function eventAudit(string $entitas): array
    {
        $e = array_map('intval', array_column($this->db->table('audit_logs')->select('event, COUNT(*) AS n')->where('entity', $entitas)->groupBy('event')->get()->getResultArray(), 'n', 'event'));
        ksort($e);

        return $e;
    }

    private function jumlahAudit(string $entitas): int
    {
        return $this->db->table('audit_logs')->where('entity', $entitas)->countAllResults();
    }

    private function resetWebConfig(): void
    {
        foreach (['webConfigService', 'cacheService'] as $name) {
            Services::resetSingle($name);
        }

        Services::injectMock('cacheService', new CacheService(service('cache'), ''));
        service('cache')->delete('web_config_values');
    }
}
