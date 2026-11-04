# Modul H — Integrasi & API Eksternal (Fase 6)

Service/business logic modul ini (`App\Libraries\Integrasi\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

## H-04 Push FCM (MAKE-066) — `App\Libraries\Push\*`

- `FcmV1Adapter` (`PushNotifGatewayInterface` + `PushPlatformInterface`): FCM HTTP v1, token akses OAuth service account
  di-cache, kirim per token lewat `KlienHttp` (log `integrasi_log` layanan `fcm`, token teredaksi), POST tanpa retry.
- `PenerimaTokenFcm`: padanan `getTokenAdmin`/`getTokenPeg`/`getTokenPL` legacy; `PengirimPushFcm`: kirim per platform +
  hapus token tidak sah per baris.
- Produsen (sesudah commit, gagal antre tidak menggagalkan operasi): `NotifikasiService` Fase 4 → `push-notif` (web);
  `PushAndroidCuti` (cuti, teks & data legacy `andr_notif_queue`) → `push-pesan` Android;
  `AntrePushRiwayat` (event `riwayatNotifikasi`, LKH, Konket) → `push-riwayat`; `AntrePushPesan` (titik sambung Halo
  Simpeg Fase 8) → `push-pesan`; `AntreTopikFcm` (presensi, LKH) → `fcm-topik`.
- Token web: `TokenFcmService` (`POST|DELETE api/v1/integrasi/fcm-token`). Konfigurasi & operasional: `README-deploy.md` §1d.
