# Push FCM HTTP v1 (H-04, MAKE-066)

Push notifikasi Fase 6 Jalur B — FCM **HTTP v1** (OAuth2 service account) menggantikan mock Fase 4; kanal dan teks **ikut
legacy** (PRD Fase 6 F6-11..F6-13, Q6-11/Q6-12, CR-099). Konfigurasi & operasional: `README-deploy.md` §1b (worker
`queue:work push`) dan §1d (service account, `.env`, token Android).

- `FcmV1Adapter` (`PushNotifGatewayInterface` + `PushPlatformInterface`): FCM HTTP v1, token akses OAuth service account
  di-cache, kirim per token lewat `KlienHttp` (log `integrasi_log` layanan `fcm`, token teredaksi), POST tanpa retry.
  Driver `integrations.push.driver = fcm`; `mock` (`MockFcmAdapter`) untuk dev/test.
- `PenerimaTokenFcm`: padanan `getTokenAdmin`/`getTokenPeg`/`getTokenPL` legacy; `PengirimPushFcm`: kirim per platform +
  hapus token tidak sah per baris.
- Produsen (sesudah commit, gagal antre tidak menggagalkan operasi): `NotifikasiService` Fase 4 → `push-notif` (web);
  `PushAndroidCuti` (cuti, teks & data legacy `andr_notif_queue`) dan `PushAndroidLkhKonket` → `push-pesan` Android;
  `AntrePushRiwayat` (event `riwayatNotifikasi`, LKH, Konket; teks `TemplatPushRiwayat`) → `push-riwayat`; `AntrePushPesan`
  (titik sambung Halo Simpeg Fase 8) → `push-pesan`; `AntreTopikFcm` (presensi, LKH; `TopikFcm`) → `fcm-topik`.
- Token web: `TokenFcmService` (`POST|DELETE api/v1/integrasi/fcm-token`, semua akun login; unik per pemilik + hash token,
  DBV-Q8). Token Android (`platform` 2) tidak punya endpoint di v2 — penulisnya pertanyaan cutover DEPLOY-001.1.
- Uji koneksi (CR-101): `php spark integrasi:uji-koneksi fcm` → `FcmV1Adapter::ujiKoneksi()` — token akses OAuth baru +
  `messages:send` `validate_only` ke topik `simpeg-uji-koneksi` (tanpa kirim ke perangkat); hasil hanya kode, tanpa
  projectId/token/kunci.
- Semua job di antrean `push`. Pesan ke topik presensi tetap dikirim di luar SIMPEG seperti legacy (syarat proyek Firebase
  sama, PN-3).
