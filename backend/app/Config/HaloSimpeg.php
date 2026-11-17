<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Angka helpdesk Halo Simpeg (Fase 8 E-02/E-07, MAKE-070; PRD Fase 8 v1.3 §7.3a, angka final dikonfirmasi Horii 16-11-2026).
 * Semua dapat ditimpa `.env` (mis. `haloSimpeg.perHalamanMaks = 50`). Panjang dihitung `mb_strlen` (code point) sesudah trim.
 * Batas master = panjang kolom DBV-028 / DBV-022 (batas aplikasi = batas kolom).
 *
 * Angka tiket (throttle, `subject`, `msg`, ekspor) ditambahkan paket MAKE-071/MAKE-074.
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
}
