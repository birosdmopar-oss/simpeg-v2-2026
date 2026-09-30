<?php
/**
 * Email tautan reset password — bagian HTML (CR-014, ISSUE-006). Dirender App\Libraries\Auth\ResetPasswordMailer.
 * CSS inline, tanpa gambar/aset eksternal; semua nilai di-escape. href memakai esc() konteks html (atribut ber-kutip
 * ganda): konteks 'attr' mengubah ':' '/' '#' '=' menjadi entitas sehingga tautan sulit dibaca/di-scan klien email;
 * skema tautan sudah dijaga auth.resetLinkBase (http/https, production wajib https).
 *
 * @var string $nama          nama akun atau "Bapak/Ibu"
 * @var string $username
 * @var string $link          tautan reset berisi token (fragment #token=)
 * @var int    $ttlMenit
 * @var string $berlakuSampai mis. "30 September 2026 pukul 14.30 WIB"
 */
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Permohonan Reset Password SIMPEG</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
<tr><td style="padding:24px 28px 8px 28px;font-size:18px;font-weight:bold;color:#111827;">SIMPEG &mdash; Reset Password</td></tr>
<tr><td style="padding:8px 28px;font-size:15px;line-height:1.6;">
<p style="margin:0 0 12px 0;">Yth. <?= esc($nama) ?>,</p>
<p style="margin:0 0 12px 0;">Kami menerima permintaan untuk mengatur ulang password akun SIMPEG Anda dengan username <strong><?= esc($username) ?></strong>.</p>
<p style="margin:0 0 20px 0;">Klik tombol di bawah ini untuk membuat password baru:</p>
<p style="margin:0 0 20px 0;text-align:center;">
<a href="<?= esc($link) ?>" style="display:inline-block;padding:12px 24px;background-color:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;">Atur Ulang Password</a>
</p>
<p style="margin:0 0 8px 0;font-size:13px;color:#4b5563;">Jika tombol tidak berfungsi, salin tautan berikut ke browser Anda:</p>
<p style="margin:0 0 20px 0;font-size:13px;word-break:break-all;"><a href="<?= esc($link) ?>" style="color:#1d4ed8;"><?= esc($link) ?></a></p>
<p style="margin:0 0 12px 0;">Tautan ini berlaku <?= esc((string) $ttlMenit) ?> menit sejak permintaan, sampai <?= esc($berlakuSampai) ?>, dan hanya dapat dipakai satu kali.</p>
<p style="margin:0 0 12px 0;">Jika Anda tidak merasa meminta reset password, abaikan email ini; password Anda tidak berubah. Jika email seperti ini terus datang tanpa sepengetahuan Anda, hubungi Admin SIMPEG di satker Anda.</p>
<p style="margin:0 0 12px 0;font-weight:bold;">Jangan teruskan email ini kepada siapa pun. Petugas SIMPEG tidak pernah meminta password Anda.</p>
</td></tr>
<tr><td style="padding:12px 28px 24px 28px;font-size:12px;line-height:1.5;color:#6b7280;border-top:1px solid #e5e7eb;">
Email ini dikirim otomatis oleh sistem SIMPEG untuk akun <?= esc($username) ?>; mohon tidak membalas.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
