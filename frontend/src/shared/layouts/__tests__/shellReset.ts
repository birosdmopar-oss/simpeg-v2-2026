/** Reset state global host shell sesudah tiap test (CR-135 R1; pasangan `shellJaringan.ts`). Bukan spec. */
import { resetPolling } from '@/features/halo-simpeg/composables/useRingkasanHalo'
import { resetHaloSimpeg } from '@/features/halo-simpeg/stores/haloSimpeg'
import { resetRingkasanNotifikasi } from '@/features/notifikasi/composables/useRingkasanNotifikasi'

import { requestShell } from './shellJaringan'

export function resetShellGlobal(): void {
  requestShell.length = 0
  resetRingkasanNotifikasi()
  resetPolling()
  resetHaloSimpeg()
}
