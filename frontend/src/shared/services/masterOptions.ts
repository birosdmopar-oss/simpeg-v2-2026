/**
 * Re-export pengambil opsi master (`GET master/{entity}/options`, UL_ALL) untuk modul selain Master Data.
 * Implementasi tetap `masterService.options` di `features/master-data/services/master.service.ts`.
 */
import { masterService } from '@/features/master-data/services/master.service'

export type { MasterOption } from '@/features/master-data/types'

export const masterOptions: typeof masterService.options = (...args) => masterService.options(...args)
