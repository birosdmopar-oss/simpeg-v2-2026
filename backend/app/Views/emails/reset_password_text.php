<?php
/**
 * Email tautan reset password — alternatif teks (CR-014, ISSUE-006). Dirender App\Libraries\Auth\ResetPasswordMailer
 * (Email::setAltMessage). Teks polos: nilai tidak di-escape HTML, tetapi baris baru di nilai dibuang supaya isi
 * email tidak bisa disisipi paragraf palsu.
 *
 * @var string $nama
 * @var string $username
 * @var string $link
 * @var int    $ttlMenit
 * @var string $berlakuSampai
 */
$satuBaris = static fn (string $v): string => trim((string) preg_replace('/[\r\n\t]+/', ' ', $v));
?>
Yth. <?= $satuBaris($nama) ?>,

Kami menerima permintaan untuk mengatur ulang password akun SIMPEG Anda dengan username <?= $satuBaris($username) ?>.

Buka tautan berikut untuk membuat password baru:
<?= $satuBaris($link) ?>


Tautan ini berlaku <?= $ttlMenit ?> menit sejak permintaan, sampai <?= $satuBaris($berlakuSampai) ?>, dan hanya dapat dipakai satu kali.

Jika Anda tidak merasa meminta reset password, abaikan email ini; password Anda tidak berubah. Jika email seperti ini terus datang tanpa sepengetahuan Anda, hubungi Admin SIMPEG di satker Anda.

Jangan teruskan email ini kepada siapa pun. Petugas SIMPEG tidak pernah meminta password Anda.

--
Email ini dikirim otomatis oleh sistem SIMPEG untuk akun <?= $satuBaris($username) ?>; mohon tidak membalas.
