<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\FormatSlip;
use App\Libraries\Presensi\SlipGaji\NilaiSel;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Test\CIUnitTestCase;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * CR-027 (P-2, P-5..P-7) — tanggal cetak dan waktu dibuka dalam WIB dengan nama bulan Indonesia, label total, nama
 * berkas PDF tersanitasi, dan uraian nilai mentah untuk pesan galat.
 *
 * @internal
 */
final class FormatSlipTest extends CIUnitTestCase
{
    public function testTanggalCetakWib(): void
    {
        $this->assertSame('4 Oktober 2026', FormatSlip::tanggalCetak(new DateTimeImmutable('2026-10-04T03:00:00Z')));
        // 3 Oktober 17:00 UTC = 4 Oktober 00:00 WIB.
        $this->assertSame('4 Oktober 2026', FormatSlip::tanggalCetak(new DateTimeImmutable('2026-10-03T17:00:00Z')));
        $this->assertSame('3 Oktober 2026', FormatSlip::tanggalCetak(new DateTimeImmutable('2026-10-03T16:59:59Z')));
    }

    public function testWaktuWibTidakMemutasiObjek(): void
    {
        $utc = new DateTime('2026-10-04 03:00:00', new DateTimeZone('UTC'));

        $this->assertSame('4 Oktober 2026, 10:00 WIB', FormatSlip::waktuWib($utc));
        $this->assertSame('UTC', $utc->getTimezone()->getName());
        $this->assertSame('1 Januari 2027, 06:59 WIB', FormatSlip::waktuWib(new DateTimeImmutable('2026-12-31T23:59:00Z')));
    }

    public function testLabelTotal(): void
    {
        $this->assertSame('Total Penghasilan Bulan Oktober 2026', FormatSlip::labelTotal(PeriodeSlip::buat(2026, 10)));
    }

    public function testNamaBerkasPdf(): void
    {
        $oktober = PeriodeSlip::buat(2026, 10);

        $this->assertSame('Slip_Gaji_PEGAWAI_CONTOH_Oktober_2026.pdf', FormatSlip::namaBerkasPdf('Pegawai Contoh', '199001012020011001', $oktober));
        $this->assertSame('Slip_Gaji_PEGAWAI_CONTOH_Oktober_2026.pdf', FormatSlip::namaBerkasPdf(' Pegawai   Contoh ', '1', $oktober));
        $this->assertSame('Slip_Gaji_PEGAWAI_O_CONTOH_Oktober_2026.pdf', FormatSlip::namaBerkasPdf("Pegawai O'Contoh/../", '1', $oktober));
        $this->assertSame('Slip_Gaji_199001012020011001_Oktober_2026.pdf', FormatSlip::namaBerkasPdf('', '199001012020011001', $oktober));
        $this->assertSame('Slip_Gaji_199001012020011001_Oktober_2026.pdf', FormatSlip::namaBerkasPdf(null, '199001012020011001', $oktober));
        $this->assertSame('Slip_Gaji_199001012020011001_Oktober_2026.pdf', FormatSlip::namaBerkasPdf('...', '199001012020011001', $oktober));
        $this->assertSame('Slip_Gaji_PEGAWAI_Oktober_2026.pdf', FormatSlip::namaBerkasPdf(null, '', $oktober));
    }

    public function testUraianNilaiSel(): void
    {
        $this->assertSame('kosong', NilaiSel::uraikan(null));
        $this->assertSame('TRUE', NilaiSel::uraikan(true));
        $this->assertSame('12', NilaiSel::uraikan(12));
        $this->assertSame('1.5', NilaiSel::uraikan(1.5));
        $this->assertSame('NAN', NilaiSel::uraikan(NAN));
        $this->assertSame('-INF', NilaiSel::uraikan(-INF));
        $this->assertSame("'abc'", NilaiSel::uraikan('abc'));
        $this->assertSame('array', NilaiSel::uraikan([]));
        $this->assertSame("'" . str_repeat('é', 60) . "…'", NilaiSel::uraikan(str_repeat('é', 61)));
        $this->assertSame("'(teks tidak terbaca)'", NilaiSel::uraikan("\xFF\xFE"));
    }

    public function testRapikanDanKosong(): void
    {
        $this->assertSame('a b', NilaiSel::rapikan(" \u{00A0}a b\t\u{00A0}"));
        $this->assertTrue(NilaiSel::kosong("\u{00A0} "));
        $this->assertTrue(NilaiSel::kosong(null));
        $this->assertFalse(NilaiSel::kosong(0));
        $this->assertFalse(NilaiSel::kosong('0'));
        $this->assertSame("x\xFF", NilaiSel::rapikan(" x\xFF "));
    }
}
