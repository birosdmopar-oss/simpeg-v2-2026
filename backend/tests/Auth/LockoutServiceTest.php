<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Libraries\Auth\LockoutService;
use App\Models\Auth\LoginAttemptModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Auth as AuthConfig;

/**
 * A-04 — unit test boundary lockout: N-1 kali gagal masih boleh, N kali terkunci; sukses mereset.
 *
 * @internal
 */
final class LockoutServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private LockoutService $lockout;

    private AuthConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config                         = new AuthConfig();
        $this->config->lockoutMaxAttempts     = 5;
        $this->config->lockoutWindowMinutes   = 15;
        $this->config->lockoutDurationMinutes = 15;

        $this->lockout = new LockoutService(new LoginAttemptModel($this->db), $this->config);
    }

    public function testNMinusOneFailuresDoesNotLock(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->lockout->recordFailure('user-a', '10.0.0.1');
        }

        $this->assertSame(4, $this->lockout->failedCount('user-a'));
        $this->assertFalse($this->lockout->isLocked('user-a'));
        $this->assertSame(0, $this->lockout->lockedForSeconds('user-a'));
    }

    public function testNFailuresLocks(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailure('user-a', '10.0.0.1');
        }

        $this->assertSame(5, $this->lockout->failedCount('user-a'));
        $this->assertTrue($this->lockout->isLocked('user-a'));
        $this->assertGreaterThan(0, $this->lockout->lockedForSeconds('user-a'));
        $this->assertLessThanOrEqual(15 * 60, $this->lockout->lockedForSeconds('user-a'));
    }

    public function testSuccessResetsCounter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->lockout->recordFailure('user-a', null);
        }

        $this->lockout->recordSuccess('user-a', null);
        $this->assertSame(0, $this->lockout->failedCount('user-a'));

        for ($i = 0; $i < 4; $i++) {
            $this->lockout->recordFailure('user-a', null);
        }

        $this->assertFalse($this->lockout->isLocked('user-a'), 'Setelah sukses, hitungan mulai dari 0 lagi');
    }

    public function testLockExpiresAfterDuration(): void
    {
        $base = time();
        $this->lockout->setNow($base);

        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailure('user-a', null);
        }

        $this->assertTrue($this->lockout->isLocked('user-a'));

        $this->lockout->setNow($base + 15 * 60 - 1);
        $this->assertTrue($this->lockout->isLocked('user-a'), 'Sedetik sebelum durasi habis masih terkunci');

        $this->lockout->setNow($base + 15 * 60 + 1);
        $this->assertFalse($this->lockout->isLocked('user-a'), 'Setelah durasi habis, terbuka lagi');
    }

    public function testFailuresOutsideWindowAreNotCounted(): void
    {
        $base = time();
        $this->lockout->setNow($base - 20 * 60); // 20 menit lalu (di luar jendela 15 menit)

        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailure('user-a', null);
        }

        $this->lockout->setNow($base);
        $this->assertFalse($this->lockout->isLocked('user-a'));
        $this->assertSame(0, $this->lockout->failedCount('user-a'));
    }

    public function testCountersAreIsolatedPerUsername(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailure('user-a', null);
        }

        $this->assertTrue($this->lockout->isLocked('user-a'));
        $this->assertFalse($this->lockout->isLocked('user-b'));
    }
}
