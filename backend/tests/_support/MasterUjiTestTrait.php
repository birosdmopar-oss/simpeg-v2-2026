<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Config\Factories;
use Config\MasterData as MasterDataConfig;
use Config\Services;
use Tests\Support\Config\MasterDataUji;

/**
 * Pasang/lepas master UJI (Tests\Support\Config\MasterDataUji) untuk test fitur engine master (CR-009).
 * Route master dibangkitkan dari Config\MasterData saat RouteCollection pertama dimuat, dan service `routes`/`router`
 * dipakai bersama antar test dalam satu proses — keduanya dibangun ulang saat pasang DAN saat lepas, agar master UJI
 * tidak bocor ke test lain.
 */
trait MasterUjiTestTrait
{
    private const MASTER_SERVICES = ['routes', 'router', 'masterRegistry', 'masterService', 'faqService'];

    protected function useMasterUji(): void
    {
        Factories::injectMock('config', MasterDataConfig::class, new MasterDataUji());
        $this->resetMasterServices();
    }

    protected function forgetMasterUji(): void
    {
        Factories::reset('config');
        $this->resetMasterServices();
    }

    private function resetMasterServices(): void
    {
        foreach (self::MASTER_SERVICES as $name) {
            Services::resetSingle($name);
        }
    }
}
