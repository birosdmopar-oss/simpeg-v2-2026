# Definisi riwayat

Satu berkas per jenis riwayat (`<Nama>.php`, kelas `App\Libraries\Kepegawaian\Riwayat\Definisi\<Nama>` turunan
`RiwayatDefinisi`). `RiwayatRegistry` memindai folder ini otomatis — tidak ada daftar terpusat yang perlu diedit.
Slug `jenis()` wajib salah satu `JenisRiwayat::SLUG`. Kontrak: `app/Controllers/Api/Kepegawaian/README.md`.

S0-A (MAKE-002) sengaja belum berisi Definisi jenis nyata; Definisi pertama ditambahkan WS-1 mulai M2 (MAKE-005).

Mesin yang membaca Definisi: `RiwayatEngine` + `SnapshotSync` (M1, MAKE-004). Selain method wajib, Definisi boleh
meng-override default ikut legacy: `statusAwal()`, `statusSetelahUbah()`, `kunciBarisDisetujui()`,
`roleKunciHapusDisetujui()`, `urutanDaftar()`, `pemetaanStatus()`, dan hook `validate`/`beforeSave`/`afterApprove`.
Setiap jenis nyata wajib punya test B-TC (`Tests\Support\Kepegawaian\RiwayatBtcTrait`).
