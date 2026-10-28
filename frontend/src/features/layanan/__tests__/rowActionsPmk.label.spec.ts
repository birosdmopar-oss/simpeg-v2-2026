/**
 * CR-074: label aria-label ⋮ PMK memakai nama pegawai (AGENTS §1 butir 7), bukan NIP.
 * NIP hanya dipakai sebagai cadangan bila nama kosong.
 */
import { describe, expect, it } from 'vitest'

import type { PmkItem } from '../types'
import { labelAksiPmk } from '../rowActionsPmk'

const pmk = (over: Partial<PmkItem> = {}): PmkItem => ({
  id: 31,
  nip: '199201012015011001',
  nama: 'Utami Anggraini',
  jenis_pmk: 'Swasta',
  instansi: 'PT Contoh',
  tgl_awal: '2010-01-01',
  tgl_akhir: '2012-12-31',
  mker_th: 2,
  mker_bl: 0,
  status: 0,
  reason_note: null,
  berkas: { file_kontrak: false, file_paklaring: false, file_surat_phk: false, file_slip_gaji: false },
  aksi: { ubah: false, hapus: false, proses: false },
  aksi_tersedia: [],
  label_status: 'Menunggu PIC',
  nada_status: 'warning',
  tahap: [],
  created_at: null,
  approved_at: null,
  ...over,
} as unknown as PmkItem)

describe('CR-074 label ⋮ PMK', () => {
  it('memakai nama pegawai lalu periode, bukan NIP', () => {
    expect(labelAksiPmk(pmk(), '1 Januari 2010 s.d. 31 Desember 2012')).toBe(
      'Aksi untuk PMK Utami Anggraini 1 Januari 2010 s.d. 31 Desember 2012',
    )
  })

  it('tidak memuat NIP walau baris ditampilkan di daftar pegawai', () => {
    expect(labelAksiPmk(pmk(), 'P')).not.toContain('199201012015011001')
  })

  it('cadangan ke NIP hanya bila nama kosong', () => {
    expect(labelAksiPmk(pmk({ nama: '' }), 'P')).toBe('Aksi untuk PMK 199201012015011001 P')
  })
})
