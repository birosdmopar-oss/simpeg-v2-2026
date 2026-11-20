<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Layanan;

use App\Jobs\PushPesanJob;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\Push\TemplatPushRiwayat;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * CR-164 butir 9 (bahan refactor CR-167 #18.2) — TEST KARAKTERISASI: mengunci perilaku SEKARANG approve cuti FINAL
 * (PyB menyetujui pada alur dua langkah) beserta pembanding non-final (AL menyetujui) dan tolak AL: isi job
 * `push-pesan` dari {@see \App\Libraries\Push\PushAndroidCuti::diproses()} (jumlah, penerima, judul, isi, data) dan hasil
 * `CutiService::itemDetail` yang dikembalikan `CutiApprovalService::process` sesudah commit. Tidak memperbaiki apa pun.
 *
 * @internal
 */
final class CutiApproveFinalPushDetailTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    /** Kunci itemDetail sesudah commit (urutan dikunci). */
    private const KUNCI_DETAIL = [
        'id_riwayat_cuti', 'nip', 'nama', 'id_sub_group_jabatan', 'pangkat', 'gol_ruang', 'jabatan', 'satker', 'mker_th', 'mker_bl',
        'id_jenis_cuti', 'nama_jenis_cuti', 'tgl_mulai', 'tgl_akhir', 'lama_cuti', 'satuan_lama_cuti', 'no_telp_saat_cuti',
        'alamat_saat_cuti', 'sisa_cuti', 'sisa_cuti_n1', 'sisa_cuti_n2', 'utang_cuti', 'keterangan', 'nip_atasan_langsung',
        'nama_atasan_langsung', 'jabatan_atasan_langsung', 'id_jabatan_al', 'status_al', 'reason_note_al', 'nip_yang_menyetujui',
        'nama_yang_menyetujui', 'jabatan_yang_menyetujui', 'id_jabatan_pyb', 'status_pyb', 'reason_note_pyb',
        'keterangan_petugas_approval', 'ketua_tim', 'status', 'status_regen', 'created_at', 'updated_at', 'updated_by', 'approved_by',
        'reason_note', 'rated', 'skip_al', 'skip_pyb', 'show_notif', 'notif_date', 'show_atasan', 'show_pejabat', 'show_history_atasan',
        'label_status', 'nada_status', 'tahap', 'aksi', 'ada_lampiran', 'ada_surat', 'ketua_tim_detail', 'lampiran', 'surat',
        'riwayat_approval',
    ];

    protected $seed = MasterDataSeeder::class;

    /** @var array<string, mixed> */
    private array $pushAsli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pushAsli                           = config(\Config\Integrations::class)->push;
        config(\Config\Integrations::class)->push = ['driver' => 'fcm', 'projectId' => '', 'credentialsPath' => ''];
    }

    protected function tearDown(): void
    {
        config(\Config\Integrations::class)->push = $this->pushAsli;
        Services::reset(true);

        parent::tearDown();
    }

    public function testApprovePybFinalMengirimPushPemohonDanDetailFinal(): void
    {
        [$a, $id] = $this->ajukan();
        $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
        $sebelum = count($this->pushAndroid());
        $detail  = $this->approvalService()->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);

        $push = array_slice($this->pushAndroid(), $sebelum);
        $tgl  = $this->rentang($id);

        // Final: tepat SATU push, hanya ke pemohon (tanpa "Monitoring Cuti" ke PyB karena status 1, bukan 3).
        $this->assertSame([
            [[$a['pemohon']['nip']], 'Cuti', "Pengajuan cuti anda anda pada tanggal {$tgl} disetujui oleh " . $this->namaGelar($a['pyb']['nip']), 'android'],
        ], $this->ringkasPush($push));
        $this->assertSame(['title', 'body', 'data'], array_keys($push[0]['data']));
        $this->assertSame([$push[0]['judul'], $push[0]['isi']], [$push[0]['data']['title'], $push[0]['data']['body']]);

        $baris = json_decode($push[0]['data']['data'], true);
        $this->assertSame($this->barisDb($id) + ['file_bukti_url' => '', 'status_str' => 'Disetujui'], $baris, 'data.data = baris riwayat_cuti sesudah commit apa adanya');
        $this->assertSame(['1', '1', '1', (string) $a['pyb']['id_pengguna']], [$baris['status'], $baris['status_al'], $baris['status_pyb'], $baris['approved_by']]);

        $this->assertSame(self::KUNCI_DETAIL, array_keys($detail));
        // perilaku sekarang (dikunci CR-164): itemDetail TIDAK memuat kolom file_* (file_bukti, file_sc_unsign, ...) walau ada di baris DB.
        $this->assertArrayNotHasKey('file_bukti', $detail);
        $this->assertSame([
            'tgl_mulai'    => '2026-11-04', 'tgl_akhir' => '2026-11-06', 'lama_cuti' => '3', 'status_al' => '1', 'status_pyb' => '1', 'status' => '1',
            'updated_by'   => (string) $a['pyb']['id_pengguna'], 'approved_by' => (string) $a['pyb']['id_pengguna'], 'reason_note' => null,
            'show_atasan'  => '1', 'show_pejabat' => '1', 'label_status' => 'Disetujui', 'nada_status' => 'success',
            'ada_lampiran' => false, 'ada_surat' => false, 'lampiran' => [], 'surat' => [],
        ], array_intersect_key($detail, array_flip(['status', 'status_al', 'status_pyb', 'tgl_mulai', 'tgl_akhir', 'lama_cuti', 'approved_by', 'updated_by', 'reason_note', 'show_atasan', 'show_pejabat', 'label_status', 'nada_status', 'ada_lampiran', 'ada_surat', 'lampiran', 'surat'])));
        $this->assertSame(['done', 'done', 'done', 'done'], array_column($detail['tahap'], 'state'));
        $this->assertSame(
            '- Disetujui ' . $this->namaGelar($a['al']['nip']) . ' - (' . $this->namaJabatan($id, 'al') . ")\n- Disetujui " . $this->namaGelar($a['pyb']['nip']) . ' - (' . $this->namaJabatan($id, 'pyb') . ')',
            $detail['keterangan_petugas_approval'],
        );
        // perilaku sekarang (dikunci CR-164): sesudah final belum ada surat (penerbit uji no-op), tetapi aksi esign true bagi PyB.
        $this->assertSame(['ubah' => false, 'hapus' => false, 'proses' => false, 'tangguhkan' => false, 'tunda' => false, 'esign' => true, 'unduh_surat' => false, 'proses_atas_nama' => false, 'esign_atas_nama' => false, 'tangguhkan_atas_nama' => false], $detail['aksi']);
        $this->assertSame(1, $this->penerbitUji()->dipanggil, 'surat belum TTE diterbitkan tepat sekali saat final');
    }

    public function testApproveAlNonFinalMengirimDuaPush(): void
    {
        [$a, $id] = $this->ajukan();
        $sebelum  = count($this->pushAndroid());
        $detail   = $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
        $push     = array_slice($this->pushAndroid(), $sebelum);
        $tgl      = $this->rentang($id);
        $nama     = (string) $detail['nama'];

        $this->assertSame([
            [[$a['pemohon']['nip']], 'Cuti', "Pengajuan cuti anda anda pada tanggal {$tgl} diproses oleh " . $this->namaGelar($a['al']['nip']), 'android'],
            [[$a['pyb']['nip']], 'Monitoring Cuti', "{$nama} telah mengajukan cuti untuk tanggal {$tgl}", 'android'],
        ], $this->ringkasPush($push));
        // perilaku sekarang (dikunci CR-164): push ke PyB memakai kata "mengajukan" dan status_str "Diproses".
        $this->assertSame(['Diproses', 'Diproses'], array_map(static fn (array $j): string => json_decode($j['data']['data'], true)['status_str'], $push));

        $this->assertSame(self::KUNCI_DETAIL, array_keys($detail));
        $this->assertSame(['3', '1', '0', null, 'Menunggu PyB', 'info'], [$detail['status'], $detail['status_al'], $detail['status_pyb'], $detail['approved_by'], $detail['label_status'], $detail['nada_status']]);
        $this->assertSame(['done', 'done', 'current', 'todo'], array_column($detail['tahap'], 'state'));
        $this->assertFalse($detail['aksi']['esign']);
        $this->assertSame(0, $this->penerbitUji()->dipanggil);
    }

    public function testTolakAlMengirimSatuPushDitolak(): void
    {
        [$a, $id] = $this->ajukan();
        $sebelum  = count($this->pushAndroid());
        $detail   = $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'tolak', 'reason_note' => 'Tidak sesuai']);
        $push     = array_slice($this->pushAndroid(), $sebelum);

        $this->assertSame([
            [[$a['pemohon']['nip']], 'Cuti', 'Pengajuan cuti anda anda pada tanggal ' . $this->rentang($id) . ' ditolak oleh ' . $this->namaGelar($a['al']['nip']), 'android'],
        ], $this->ringkasPush($push));
        $this->assertSame('Ditolak', json_decode($push[0]['data']['data'], true)['status_str']);

        $this->assertSame(self::KUNCI_DETAIL, array_keys($detail));
        // Tolak AL = final: status 2, status_pyb tetap 0, alasan tersalin ke reason_note_al DAN reason_note.
        $this->assertSame(
            ['2', '2', '0', 'Tidak sesuai', null, 'Tidak sesuai', 'Ditolak', 'danger'],
            [$detail['status'], $detail['status_al'], $detail['status_pyb'], $detail['reason_note_al'], $detail['reason_note_pyb'], $detail['reason_note'], $detail['label_status'], $detail['nada_status']],
        );
        // perilaku sekarang (dikunci CR-164): tahap "pejabat" tetap 'todo' sementara "selesai" sudah 'done'.
        $this->assertSame(['done', 'done', 'todo', 'done'], array_column($detail['tahap'], 'state'));
        $this->assertSame([], array_keys(array_filter($detail['aksi'])), 'tidak ada aksi tersisa bagi AL');
        $this->assertSame(0, $this->penerbitUji()->dipanggil, 'tolak tidak menerbitkan surat');
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function ajukan(): array
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null)['id_riwayat_cuti'];

        return [$a, $id];
    }

    /**
     * @param list<array<string, mixed>> $push
     *
     * @return list<list<mixed>>
     */
    private function ringkasPush(array $push): array
    {
        return array_map(static fn (array $j): array => [$j['nip'], $j['judul'], $j['isi'], $j['kanal']], $push);
    }

    /**
     * @return array<string, mixed>
     */
    private function barisDb(int $id): array
    {
        return $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();
    }

    private function rentang(int $id): string
    {
        $c = $this->barisDb($id);

        return TemplatPushRiwayat::tanggal($c['tgl_mulai']) . ' s/d ' . TemplatPushRiwayat::tanggal($c['tgl_akhir']);
    }

    private function namaJabatan(int $id, string $tahap): string
    {
        $c = $this->barisDb($id);

        return (string) ($tahap === 'al' ? $c['jabatan_atasan_langsung'] : $c['jabatan_yang_menyetujui']);
    }

    /**
     * Data job `push-pesan` antrean `push`, urut masuk.
     *
     * @return list<array<string, mixed>>
     */
    private function pushAndroid(): array
    {
        $hasil = [];

        foreach ($this->db->table('queue_jobs')->where('queue', 'push')->orderBy('id', 'ASC')->get()->getResultArray() as $r) {
            $p = (array) json_decode((string) $r['payload'], true);

            if (($p['job'] ?? null) === PushPesanJob::NAMA) {
                $hasil[] = $p['data'];
            }
        }

        return $hasil;
    }

    private function namaGelar(string $nip): string
    {
        $p = $this->db->table('pegawai')->select('glr_awal, nama, glr_akhir')->where('nip', $nip)->get()->getRowArray();

        return StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir']);
    }
}
