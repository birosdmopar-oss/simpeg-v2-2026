<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\SkemaLayanan;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-022 M2 — AC-01.2/01.3/01.6 (dokumen Bagian 3, disetujui DB Validator 27-10-2026): `inbox`/`inbox_msg` v2 = notifikasi
 * SATU ARAH tanpa kolom percakapan (`status` open/closed, `allow_reply`, `closed_by`, `is_read_off`, `notify_pic`);
 * `disiplin_ticket`/`disiplin_ticket_msg` = ticketing DUA ARAH [K] yang memuat kolom itu dengan CHECK domain. Dua pasang
 * tabel terpisah (temuan T1: jangan memakai inbox untuk ticketing).
 *
 * @internal
 */
final class SkemaInboxTicketTest extends DatabaseTestCase
{
    use SkemaKepegawaianTestTrait;

    private const KOLOM_TIKET = ['status', 'allow_reply', 'closed_by', 'is_read_off', 'notify_pic'];

    public function testInboxV2NotifikasiSatuArahTanpaKolomPercakapan(): void
    {
        $this->assertSame(
            ['id_inbox', 'nip_penerima', 'id_pengguna_penerima', 'modul', 'id_jenis_layanan', 'id_rwy', 'judul', 'url', 'is_read', 'read_at', 'created_at'],
            array_keys($this->skemaColumns('inbox')),
        );

        foreach (['inbox', 'inbox_msg'] as $tabel) {
            $kolom = array_keys($this->skemaColumns($tabel));

            foreach (self::KOLOM_TIKET as $terlarang) {
                $this->assertNotContains($terlarang, $kolom, "{$tabel}.{$terlarang} dilarang (AC-01.2)");
            }
        }

        $this->assertSame(['id_inbox_msg', 'id_inbox', 'tipe', 'status_sumber', 'isi', 'reason_note', 'push_status', 'created_at'], array_keys($this->skemaColumns('inbox_msg')));
    }

    public function testDisiplinTicketDuaArahTerpisah(): void
    {
        $kolom = $this->skemaColumns('disiplin_ticket');

        foreach (self::KOLOM_TIKET as $harus) {
            $this->assertArrayHasKey($harus, $kolom, "disiplin_ticket.{$harus} (AC-01.3)");
        }

        $check = $this->skemaChecks(['disiplin_ticket']);
        $this->assertSame('statusin0,1', $check['chk_dticket_status'][1], 'status 0 Open / 1 Closed');
        $this->assertSame('allow_replyin0,1', $check['chk_dticket_allow_reply'][1]);
        $this->assertTrue($this->skemaTableExists('disiplin_ticket_msg'));

        // Dua pasang tabel terpisah tanpa FK silang (AC-01.6).
        [$fk]   = $this->skemaForeignKeys(['inbox', 'inbox_msg', 'disiplin_ticket', 'disiplin_ticket_msg'], SkemaLayanan::FK_DITAHAN);
        $silang = array_filter($fk, static fn (array $f): bool => (str_starts_with($f[0], 'inbox') && str_starts_with($f[2], 'disiplin'))
            || (str_starts_with($f[0], 'disiplin') && str_starts_with($f[2], 'inbox')));
        $this->assertSame([], $silang);
    }
}
