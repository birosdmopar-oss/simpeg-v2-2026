/**
 * Aksi baris cuti yang dipakai halaman daftar dan detail: proses (Setujui/Tolak/Tangguhkan, termasuk atas nama), Tunda,
 * Hapus, dan Unduh surat. Memegang keadaan dialog/busy/notice; `onSelesai` dipanggil setelah aksi sukses (muat ulang).
 * Hak aksi TIDAK diputuskan di sini — pemanggil hanya meneruskan kunci menu yang sudah disaring `aksi {}` server.
 */
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { ref } from 'vue'

import { cutiService } from '../services/cuti.service'
import type { AksiProsesCuti, CutiItem } from '../types'

export interface NoticeCuti {
  tone: 'danger' | 'success'
  text: string
}

const PESAN_PROSES: Record<AksiProsesCuti, string> = {
  setujui: 'Pengajuan cuti disetujui.',
  tolak: 'Pengajuan cuti ditolak.',
  tangguhkan: 'Pengajuan cuti ditangguhkan.',
}

export function useCutiAksi(onSelesai: (aksi: 'proses' | 'tunda' | 'hapus', id: number) => void | Promise<void>) {
  const notice = ref<NoticeCuti | null>(null)
  const busyId = ref<number | null>(null)

  const prosesOpen = ref(false)
  const prosesAksi = ref<AksiProsesCuti>('setujui')
  const prosesAtasNama = ref(false)
  const prosesRow = ref<CutiItem | null>(null)
  const prosesBusy = ref(false)
  const prosesError = ref('')

  const hapusOpen = ref(false)
  const hapusRow = ref<CutiItem | null>(null)

  /** Kunci menu → dialog/aksi. `detail` ditangani pemanggil (navigasi). */
  function pilih(key: string, row: CutiItem): void {
    if (key === 'setujui' || key === 'tolak' || key === 'tangguhkan') {
      bukaProses(row, key, false)
    } else if (key === 'setujui-atas-nama' || key === 'tolak-atas-nama') {
      bukaProses(row, key === 'setujui-atas-nama' ? 'setujui' : 'tolak', true)
    } else if (key === 'tunda') {
      void tunda(row)
    } else if (key === 'surat') {
      void unduhSurat(row)
    } else if (key === 'hapus') {
      hapusRow.value = row
      hapusOpen.value = true
    }
  }

  function bukaProses(row: CutiItem, aksi: AksiProsesCuti, atasNama: boolean): void {
    prosesRow.value = row
    prosesAksi.value = aksi
    prosesAtasNama.value = atasNama
    prosesError.value = ''
    prosesOpen.value = true
  }

  async function proses(payload: { aksi: AksiProsesCuti; reason_note?: string; atas_nama?: boolean }): Promise<void> {
    const row = prosesRow.value
    if (!row) return
    prosesBusy.value = true
    prosesError.value = ''
    try {
      await cutiService.process(row.id, payload)
      prosesOpen.value = false
      notice.value = { tone: 'success', text: PESAN_PROSES[payload.aksi] }
      await onSelesai('proses', row.id)
    } catch (e) {
      prosesError.value = describeApiError(e).message
    } finally {
      prosesBusy.value = false
    }
  }

  async function tunda(row: CutiItem): Promise<void> {
    busyId.value = row.id
    try {
      await cutiService.tunda(row.id)
      notice.value = { tone: 'success', text: 'Pengingat ditunda.' }
      await onSelesai('tunda', row.id)
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
    }
  }

  async function hapus(): Promise<void> {
    const row = hapusRow.value
    hapusOpen.value = false
    if (!row) return
    busyId.value = row.id
    try {
      await cutiService.remove(row.id)
      notice.value = { tone: 'success', text: 'Pengajuan cuti dihapus.' }
      await onSelesai('hapus', row.id)
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
      hapusRow.value = null
    }
  }

  async function unduhSurat(row: CutiItem): Promise<void> {
    busyId.value = row.id
    try {
      simpanBerkas(await cutiService.surat(row.id))
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
    }
  }

  return {
    notice,
    busyId,
    prosesOpen,
    prosesAksi,
    prosesAtasNama,
    prosesRow,
    prosesBusy,
    prosesError,
    hapusOpen,
    hapusRow,
    pilih,
    proses,
    hapus,
    tunda,
    unduhSurat,
  }
}
