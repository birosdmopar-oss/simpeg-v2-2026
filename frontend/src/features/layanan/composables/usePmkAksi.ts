/**
 * Aksi baris pengajuan PMK untuk halaman daftar dan detail: proses PIC (Proses / Setujui / Tolak), unduh berkas, dan
 * hapus. Memegang keadaan dialog/busy/notice; `onSelesai` dipanggil setelah aksi sukses (muat ulang). Hak aksi TIDAK
 * diputuskan di sini — pemanggil hanya meneruskan kunci menu yang sudah disaring `aksi {}`/`aksi_tersedia[]` server.
 */
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { ref } from 'vue'

import { pmkService } from '../services/pmk.service'
import type { AksiProsesPmk, KolomBerkasPmk, PmkItem } from '../types'

export interface NoticePmk {
  tone: 'danger' | 'success'
  text: string
}

const PESAN_PROSES: Record<AksiProsesPmk, string> = {
  proses: 'Pengajuan PMK diproses.',
  setujui: 'Pengajuan PMK disetujui.',
  tolak: 'Pengajuan PMK ditolak.',
}

export function usePmkAksi(onSelesai: (aksi: 'proses' | 'hapus', id: number) => void | Promise<void>) {
  const notice = ref<NoticePmk | null>(null)
  const busyId = ref<number | null>(null)

  const prosesOpen = ref(false)
  const prosesAksi = ref<AksiProsesPmk>('proses')
  const prosesRow = ref<PmkItem | null>(null)
  const prosesBusy = ref(false)
  const prosesError = ref('')

  const hapusOpen = ref(false)
  const hapusRow = ref<PmkItem | null>(null)

  /** Kunci menu → dialog/aksi. `edit` dan `detail` ditangani pemanggil. */
  function pilih(key: string, row: PmkItem): void {
    if (key === 'proses' || key === 'setujui' || key === 'tolak') {
      prosesRow.value = row
      prosesAksi.value = key
      prosesError.value = ''
      prosesOpen.value = true
    } else if (key.startsWith('berkas:')) {
      void unduh(row, key.slice('berkas:'.length) as KolomBerkasPmk)
    } else if (key === 'hapus') {
      hapusRow.value = row
      hapusOpen.value = true
    }
  }

  async function proses(payload: { reason_note?: string }): Promise<void> {
    const row = prosesRow.value
    if (!row) return
    prosesBusy.value = true
    prosesError.value = ''
    try {
      await pmkService.process(row.id, prosesAksi.value, payload.reason_note)
      prosesOpen.value = false
      notice.value = { tone: 'success', text: PESAN_PROSES[prosesAksi.value] }
      await onSelesai('proses', row.id)
    } catch (e) {
      prosesError.value = describeApiError(e).message
    } finally {
      prosesBusy.value = false
    }
  }

  async function hapus(): Promise<void> {
    const row = hapusRow.value
    hapusOpen.value = false
    if (!row) return
    busyId.value = row.id
    try {
      await pmkService.remove(row.id)
      notice.value = { tone: 'success', text: 'Pengajuan PMK dihapus.' }
      await onSelesai('hapus', row.id)
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
      hapusRow.value = null
    }
  }

  async function unduh(row: PmkItem, kolom: KolomBerkasPmk): Promise<void> {
    busyId.value = row.id
    try {
      simpanBerkas(await pmkService.berkas(row.id, kolom))
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
    }
  }

  return { notice, busyId, prosesOpen, prosesAksi, prosesRow, prosesBusy, prosesError, hapusOpen, hapusRow, pilih, proses, hapus, unduh }
}
