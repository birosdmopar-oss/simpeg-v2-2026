<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi integrasi eksternal (ADR-015).
 * driver = 'mock' dipakai Fase 0-5. Adapter real ditambahkan di Fase 6 (Modul H).
 * Kredensial WAJIB lewat .env, jangan pernah di-commit.
 */
class Integrations extends BaseConfig
{
    /**
     * E-sign (H-02): `driver` `mock` | `bsre` (MAKE-065). `username`/`password` = client id/secret Basic BSrE [K]. `tlsVerify`
     * bawaan true; `false` hanya dari `.env` server — TLS BSrE ikut legacy atas keputusan Horii 03-11-2026 (kartu Catatan
     * Teknis), peer & host tidak diverifikasi. Upgrade ke TLS ketat lewat `tlsVerify = true` (+ `caBundle` = path CA internal
     * di luar repo/webroot; kosong = CA sistem). `timeout` detik total > 0.
     *
     * @var array{driver: string, baseUrl: string, username: string, password: string, tlsVerify: bool|string, caBundle: string, timeout: float|string}
     */
    public array $esign = [
        'driver'    => 'mock',
        'baseUrl'   => '',
        'username'  => '',
        'password'  => '',
        'tlsVerify' => true,
        'caBundle'  => '',
        'timeout'   => 30.0,
    ];

    /**
     * @var array{driver: string, baseUrl: string, clientId: string, clientSecret: string}
     */
    public array $siasn = [
        'driver'       => 'mock',
        'baseUrl'      => '',
        'clientId'     => '',
        'clientSecret' => '',
    ];

    /**
     * @var array{driver: string, projectId: string, credentialsPath: string}
     */
    public array $push = [
        'driver'          => 'mock',
        'projectId'       => '',
        'credentialsPath' => '',
    ];

    /**
     * Layanan BKN (e-Kinerja H-10, SIASN H-03) — Fase 6 MAKE-063, bentuk auth ikut legacy (CR-092/Q6-17, ekstraksi bentuk
     * tanpa nilai). Alamat & kredensial hanya dari `.env` server (BP-2); tidak ada token literal di kode (Q6-08).
     *
     * - `tokenUrlGateway` + `clientId`/`clientSecret`: token API gateway (POST form, Basic, `grant_type=client_credentials`).
     * - `tokenUrlSso` + `ssoClientId`/`ssoUsername`/`ssoPassword` (+ `ssoTotp` opsional): token SSO (POST form, Basic
     *   kredensial gateway, `grant_type=password`). SIASN dipanggil dengan DUA header (gateway + SSO).
     * - e-Kinerja: `ekinerjaToken` (token statis, padanan cron legacy aktif) bila terisi; bila kosong, SSO e-Kinerja dengan
     *   kredensial `ekinerja*` (Basic `ekinerjaApiUser`/`ekinerjaApiPass` + `ekinerjaSso*`, `totp` opsional).
     * - Dorong jabatan SIASN (MAKE-067 M2): `siasnInstansiId` / `siasnSatkerId` (padanan konstanta legacy
     *   `SIASN_INS_KEMENPAR` / `SIASN_SAT_KEMENPAR`) dan `siasnUnorBawaan` (unor cadangan [K `Rw_jabatan:175`]); wajib
     *   diisi sebelum `siasn:dorong --jenis=jabatan`.
     *
     * @var array<string, bool|float|string>
     */
    public array $bkn = [
        'baseUrlApi'          => '',
        'baseUrlEkinerja'     => '',
        'tokenUrlGateway'     => '',
        'tokenUrlSso'         => '',
        'clientId'            => '',
        'clientSecret'        => '',
        'ssoClientId'         => '',
        'ssoUsername'         => '',
        'ssoPassword'         => '',
        'ssoTotp'             => '',
        'ekinerjaToken'       => '',
        'ekinerjaApiUser'     => '',
        'ekinerjaApiPass'     => '',
        'ekinerjaSsoClientId' => '',
        'ekinerjaSsoUsername' => '',
        'ekinerjaSsoPassword' => '',
        'ekinerjaSsoTotp'     => '',
        'siasnInstansiId'     => '',
        'siasnSatkerId'       => '',
        'siasnUnorBawaan'     => '',
        'tlsVerify'           => true,
        'timeout'             => 30.0,
    ];

    /**
     * Retensi baris `integrasi_log` (Q6-16, F6-24) untuk `integrasi:bersihkan-log`.
     */
    public int $logRetensiHari = 180;
}
