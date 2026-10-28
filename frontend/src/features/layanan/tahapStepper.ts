/**
 * `tahap[]` dari server → langkah `UiStepper`. Tidak ada logika tahap di FE (PRD §6.11, ADR-025): label dan state
 * dipakai apa adanya; nada stepper mengikuti nada badge (menunggu = oranye, selainnya biru).
 */
import type { Step } from '@/shared/ui'

import type { NadaStatus, TahapLayanan } from './types'

export function tahapKeSteps(tahap: readonly TahapLayanan[]): Step[] {
  return tahap.map((t) => ({ label: t.label, state: t.state }))
}

export function nadaStepper(nada: NadaStatus): 'info' | 'primary' | 'warning' {
  if (nada === 'warning') return 'warning'
  if (nada === 'primary') return 'primary'
  return 'info'
}
