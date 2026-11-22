<?php

declare(strict_types=1);

namespace Tests\Impor;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * CR-172 / DBV-034 (G6) — importer menormalkan FORMAT kolom NIP yang akan ber-FK lewat migration ditahan
 * `AddFkNipLayananFase4`: `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui` dan `pa_layanan.nip` (NULL-able) →
 * karakter tak terlihat dibuang, trim, `''` → NULL (dicatat). NIP yatim TIDAK di-NULL-kan (tetap ditolak pra-cek FK).
 * NIP sintetis berawalan nol.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyNormalisasiNipFkTest extends ImporLegacyTestCase
{
    private const NIP_A = '000000000000000001';

    private const NIP_YATIM = '000000000000000099';

    public function testRiwayatCutiKolomApproverDitrimDanKosongMenjadiNull(): void
    {
        $this->isiMasterDasar();
        $this->src->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS', 'order' => 1, 'status' => 1]);
        $this->src->table('pegawai')->insert(['nip' => self::NIP_A, 'nama' => 'Pegawai Uji A', 'tgl_lahir' => '1990-01-01', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 1, 'status' => 1]);

        $cuti = static fn (int $id, ?string $al, ?string $pyb): array => [
            'id_riwayat_cuti'     => $id, 'nip' => self::NIP_A, 'id_jenis_cuti' => 3, 'nama_jenis_cuti' => 'Cuti Sakit', 'tgl_mulai' => '2026-08-10',
            'tgl_akhir'           => '2026-08-20', 'lama_cuti' => 9, 'status' => 1,
            'nip_atasan_langsung' => $al, 'nip_yang_menyetujui' => $pyb,
        ];
        $this->src->table('riwayat_cuti')->insertBatch([
            $cuti(20, " \u{200B}" . self::NIP_A . ' ', ''),
            $cuti(21, '   ', self::NIP_YATIM),
            $cuti(22, null, self::NIP_A),
        ]);

        $lap = $this->jalankanImpor([1, 2, 3, 4, 5, 6]);

        $hasil = array_column($this->db->table('riwayat_cuti')->orderBy('id_riwayat_cuti')->get()->getResultArray(), null, 'id_riwayat_cuti');
        $this->assertSame([self::NIP_A, null], [$hasil[20]['nip_atasan_langsung'], $hasil[20]['nip_yang_menyetujui']], 'trim + karakter tak terlihat dibuang; "" → NULL');
        $this->assertSame([null, self::NIP_YATIM], [$hasil[21]['nip_atasan_langsung'], $hasil[21]['nip_yang_menyetujui']], 'spasi-saja → NULL; NIP yatim TIDAK di-NULL-kan');
        $this->assertSame([null, self::NIP_A], [$hasil[22]['nip_atasan_langsung'], $hasil[22]['nip_yang_menyetujui']]);

        $koreksi = $lap['riwayat_cuti']['koreksi'];
        $this->assertSame(1, $koreksi['nip_atasan_langsung kosong→NULL (DBV-034)']);
        $this->assertSame(1, $koreksi['nip_yang_menyetujui kosong→NULL (DBV-034)']);
        $this->assertSame(1, $koreksi['nip_atasan_langsung karakter tak terlihat dibuang']);
    }

    public function testPaLayananNipDitrimDanKosongMenjadiNull(): void
    {
        $this->src->table('pa_layanan')->insertBatch([
            ['id_pa_layanan' => 1, 'nip' => ' ' . self::NIP_A . "\u{FEFF}", 'status' => 1],
            ['id_pa_layanan' => 2, 'nip' => '', 'status' => 1],
            ['id_pa_layanan' => 3, 'nip' => self::NIP_YATIM, 'status' => 1],
        ]);

        $lap = $this->jalankanImpor([12]);

        $nip = array_column($this->db->table('pa_layanan')->orderBy('id_pa_layanan')->get()->getResultArray(), 'nip', 'id_pa_layanan');
        $this->assertSame([1 => self::NIP_A, 2 => null, 3 => self::NIP_YATIM], array_map(static fn ($n) => $n === null ? null : (string) $n, $nip));
        $this->assertSame(1, $lap['pa_layanan']['koreksi']['nip kosong→NULL (DBV-034)']);
    }
}
