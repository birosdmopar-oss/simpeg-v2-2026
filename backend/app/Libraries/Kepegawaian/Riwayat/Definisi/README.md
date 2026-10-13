# Definisi riwayat

Satu berkas per jenis riwayat (`<Nama>.php`, kelas `App\Libraries\Kepegawaian\Riwayat\Definisi\<Nama>` turunan
`RiwayatDefinisi`). `RiwayatRegistry` memindai folder ini otomatis — tidak ada daftar terpusat yang perlu diedit.
Slug `jenis()` wajib salah satu `JenisRiwayat::SLUG`. Kontrak: `app/Controllers/Api/Kepegawaian/README.md`.

Definisi nyata: `Pendidikan` (B-10) dan `Kp` (B-08), mulai M2 (MAKE-005). Setiap aturan ditulis dengan rujukan baris
kode legacy [K] atau label [V2] di docblock-nya.

Mesin yang membaca Definisi: `RiwayatEngine` + `SnapshotSync` (M1, MAKE-004). Selain method wajib, Definisi boleh
meng-override default ikut legacy: `statusAwal()`, `statusSetelahUbah()`, `kunciBarisDisetujui()`,
`roleKunciHapusDisetujui()`, `rolePilihStatusSaatUbah()` (role yang boleh memilih `status` saat ubah; Ditolak wajib
alasan), `bolehAksiUntuk()` (izin per pegawai sasaran, mis. role 2 Pendidikan hanya selama CPNS; dipakai engine dan
descriptor), `urutanDaftar()`, `saringDaftar()` (saring daftar tambahan, MAKE-008), `pemetaanStatus()`,
`lampiranWajib()`, `kolomBerkas()`/`kolomBerkasWajib()`, `entriLampiran()` (MAKE-007), dan hook `validate` (menerima NIP
pemilik)/`beforeSave` (saat tambah menerima NIP pemilik, MAKE-008)/`afterApprove`. Permukaan ini beku sejak engine-v1 (MAKE-005): perubahan hanya aditif oleh WS-1.
Setiap jenis nyata wajib punya test B-TC (`Tests\Support\Kepegawaian\RiwayatBtcTrait`).
