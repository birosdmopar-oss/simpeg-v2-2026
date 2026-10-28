/**
 * Kalkulator masa kerja PMK (PRD §6.9, `POST pmk/hitung-mker`): setiap tanggal awal/akhir berubah (debounce 300 ms)
 * FE memanggil server dan hanya MENAMPILKAN `mker_th`/`mker_bl` (+ peringatan). Hasilnya dikirim apa adanya saat
 * mengajukan ([K D19/P-4]: nilai dari klien); FE tidak menghitung selisih tanggal sendiri. Respons basi dibuang.
 */
import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { isApiError } from '@/lib/axios'
import { onBeforeUnmount, ref, watch, type Ref } from 'vue'

import { pmkService } from '../services/pmk.service'
import type { HitungMker } from '../types'

export const JEDA_MKER_MS = 300

export function useHitungMker(isian: { tgl_awal: string; tgl_akhir: string }, aktif: Ref<boolean>) {
  const hasil = ref<HitungMker | null>(null)
  const memuat = ref(false)
  const galat = ref<Record<string, string>>({})
  const galatUmum = ref('')
  let timer: ReturnType<typeof setTimeout> | undefined
  let token = 0

  async function hitung(): Promise<void> {
    const saya = ++token
    if (!aktif.value || !isian.tgl_awal || !isian.tgl_akhir) {
      hasil.value = null
      galat.value = {}
      galatUmum.value = ''
      memuat.value = false
      return
    }
    memuat.value = true
    try {
      const h = await pmkService.hitungMker(isian.tgl_awal, isian.tgl_akhir)
      if (saya !== token) return
      hasil.value = h
      galat.value = {}
      galatUmum.value = ''
    } catch (e) {
      if (saya !== token) return
      hasil.value = null
      if (isApiError(e) && e.status === 422 && e.errors) {
        const v = splitValidationErrors(e, ['tgl_awal', 'tgl_akhir'])
        galat.value = v.fields
        galatUmum.value = v.general.join(' ')
      } else {
        galat.value = {}
        galatUmum.value = describeApiError(e).message
      }
    } finally {
      if (saya === token) memuat.value = false
    }
  }

  watch(
    () => [isian.tgl_awal, isian.tgl_akhir, aktif.value],
    () => {
      clearTimeout(timer)
      timer = setTimeout(() => void hitung(), JEDA_MKER_MS)
    },
  )
  onBeforeUnmount(() => {
    clearTimeout(timer)
    token++
  })

  return { hasil, memuat, galat, galatUmum }
}
