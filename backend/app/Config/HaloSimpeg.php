<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Angka helpdesk Halo Simpeg (Fase 8 E-02/E-07, MAKE-070; PRD Fase 8 v1.3 §7.3a, angka final dikonfirmasi Horii 16-11-2026).
 * Semua dapat ditimpa `.env` (mis. `haloSimpeg.perHalamanMaks = 50`). Panjang dihitung `mb_strlen` (code point) sesudah trim.
 * Batas master = panjang kolom DBV-028 / DBV-022 (batas aplikasi = batas kolom).
 *
 * Angka tiket (throttle, `subject`, `msg`, lampiran) MAKE-071; angka ekspor ditambahkan MAKE-074.
 */
class HaloSimpeg extends BaseConfig
{
    /** Paginasi daftar master (`Halaman<T>`): `per_page` bawaan dan maksimum. */
    public int $perHalamanBawaan = 20;

    public int $perHalamanMaks = 100;

    /** `question_category.question_category` VARCHAR(250) [K]. */
    public int $maksNamaKategori = 250;

    /** `support_officer.email`, `help_desk_contact.email` VARCHAR(150) [I] (DBV-028 Q2). */
    public int $maksEmail = 150;

    /** `support_officer.no_hp` VARCHAR(20) [I]. */
    public int $maksNoHp = 20;

    /** `help_desk_contact.name` VARCHAR(150) [I]. */
    public int $maksNamaKontak = 150;

    /** `help_desk_contact.contact` VARCHAR(20) [I]. */
    public int $maksNomorKontak = 20;

    /** Kandidat petugas (`GET petugas/kandidat?search=`, F8-07): panjang kata kunci dan jumlah hasil maksimum. */
    public int $kandidatCariMin = 2;

    public int $kandidatCariMaks = 50;

    public int $kandidatMaks = 20;

    /** Throttle buat tiket per akun: kapasitas dan jendela (detik) — 5 per 10 menit (§7.3a). */
    public int $throttleTiketKapasitas = 5;

    public int $throttleTiketDetik = 600;

    /** Throttle kirim pesan (balas) per akun, satu kunci untuk semua tiket — 20 per menit (§7.3a). */
    public int $throttlePesanKapasitas = 20;

    public int $throttlePesanDetik = 60;

    /** `disiplin_ticket.subject` VARCHAR(250) [K]. */
    public int $maksSubjek = 250;

    /** `msg` maksimum (code point sesudah trim); kolom TEXT jauh lebih besar (§7.3a). */
    public int $maksPesan = 5000;

    /** Potongan isi pesan terakhir di daftar (code point). */
    public int $panjangPotongan = 100;

    /** Lampiran tiket/pesan: 5 MB, 9 ekstensi legacy [K `LS:963-975`] (BP8-5). */
    public int $lampiranMaksMb = 5;

    /** @var list<string> */
    public array $lampiranEkstensi = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
}
