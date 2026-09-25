<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\PasswordPolicy;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Auth as AuthConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ISSUE-006 / K4 — kebijakan password ikut legacy (L_user.php validate_param_cp / rp): minimal 8 karakter, 1 huruf
 * besar, 1 huruf kecil, 1 angka. Vektor di bawah SAMA dengan `password.schema.spec.ts` frontend (PASSWORD_RULES):
 * kalau salah satu diubah, ubah keduanya.
 *
 * @internal
 */
final class PasswordPolicyTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function vectors(): iterable
    {
        yield 'memenuhi semua aturan' => ['Password1', []];
        yield 'terlalu pendek' => ['Pass1', ['min_length']];
        yield 'tanpa huruf besar' => ['password123', ['uppercase']];
        yield 'tanpa huruf kecil' => ['PASSWORD123', ['lowercase']];
        yield 'tanpa angka' => ['PasswordAja', ['digit']];
        yield 'kosong' => ['', ['min_length', 'uppercase', 'lowercase', 'digit']];
        // Panjang per karakter (mb_strlen), bukan per byte: 7 karakter walau 19 byte UTF-8.
        yield 'emoji dihitung per karakter' => ["Aa1\u{1F600}\u{1F600}\u{1F600}\u{1F600}", ['min_length']];
        // Huruf beraksen tidak dihitung sebagai huruf besar/kecil (pola ASCII legacy [A-Z] / [a-z]).
        yield 'huruf non-ASCII tidak dihitung' => ["\u{00C9}\u{00E9}\u{00C9}\u{00E9}1234", ['uppercase', 'lowercase']];
        yield 'spasi dan simbol boleh' => ['Kata Sandi 2026!', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('vectors')]
    public function testFailedRulesFollowLegacyPolicy(string $password, array $expected): void
    {
        $this->assertSame($expected, (new PasswordPolicy(8))->failedRules($password));
    }

    public function testMessagesAreIndonesianAndOrdered(): void
    {
        $this->assertSame([
            'Password minimal 8 karakter.',
            'Password harus mengandung minimal 1 huruf besar.',
            'Password harus mengandung minimal 1 huruf kecil.',
            'Password harus mengandung minimal 1 angka.',
        ], (new PasswordPolicy(8))->errors(''));

        $this->assertSame(['Password harus mengandung minimal 1 huruf besar.'], (new PasswordPolicy(8))->errors('password123'));
    }

    public function testMinimumLengthComesFromConfig(): void
    {
        $config                    = new AuthConfig();
        $config->passwordMinLength = 10;
        $policy                    = PasswordPolicy::fromConfig($config);

        $this->assertSame(10, $policy->minLength());
        $this->assertSame(['Password minimal 10 karakter.'], $policy->errors('Password1'));
        $this->assertSame([], $policy->errors('Password12'));
    }

    /**
     * Password awal akun otomatis (A-09) wajib selalu lolos kebijakan: minimal 1 huruf besar, 1 huruf kecil, 1 angka.
     * Tanpa jaminan per kelas karakter, peluang 12 karakter acak tanpa huruf besar ±1 dari 310 → 3000 percobaan
     * praktis pasti menemukannya.
     */
    public function testGeneratedPasswordAlwaysSatisfiesPolicy(): void
    {
        $policy = new PasswordPolicy(8);

        for ($i = 0; $i < 3000; $i++) {
            $password = AccountProvisioner::generatePassword();
            $this->assertSame(12, strlen($password));
            $this->assertSame([], $policy->failedRules($password), "Password acak '{$password}' melanggar kebijakan");
        }

        $this->assertSame([], $policy->failedRules(AccountProvisioner::generatePassword(8)));
        $this->assertSame(20, strlen(AccountProvisioner::generatePassword(20)));
    }
}
