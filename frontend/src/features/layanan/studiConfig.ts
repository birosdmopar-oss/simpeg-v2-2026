/**
 * Konfigurasi STATIS FE per jenis studi (Tugas Belajar `tb`, Izin Belajar `ib`): judul, nama route, awalan testid, dan
 * daftar role. Slot berkas, label slot, berkas wajib, dan hak tampil TIDAK disimpan di sini — diambil dari server
 * (`GET {jenis}/opsi` → `slot_berkas`, `berkas_wajib`, `adalah_pic`; `aksi {}` dan `can_*` pada respons) (ADR-025/026).
 */
import { LAYANAN_ROUTE } from './routes.layanan'
import { IB_ROLES, TB_ROLES } from './roles'
import type { StudiJenis } from './services/studi.service'

export interface StudiConfig {
  jenis: StudiJenis
  /** Judul lengkap, mis. "Tugas Belajar". */
  judul: string
  /** Singkatan untuk breadcrumb dan label, mis. "TB". */
  singkatan: string
  routeList: string
  routeDetail: string
  /** Awalan `data-testid` (mis. `tb-actions-<id>`). */
  testid: string
  roles: readonly number[]
  /** Pagar tambahan FE untuk item Perpanjangan; sumber utamanya `aksi.perpanjangan` dari server. */
  perpanjanganDiizinkan: boolean
  /** Role 1 boleh memilih status awal (0|1) saat tambah/ubah — TB saja; IB selalu 0 (server). */
  statusAdminDipilih: boolean
  /** Berkas opsional saat proses (hanya TB; `file_surat_tb`). Bukan slot lampiran. */
  berkasProses: string | null
}

export const STUDI_CONFIG: Record<StudiJenis, StudiConfig> = {
  tb: {
    jenis: 'tb',
    judul: 'Tugas Belajar',
    singkatan: 'TB',
    routeList: LAYANAN_ROUTE.TB,
    routeDetail: LAYANAN_ROUTE.TB_DETAIL,
    testid: 'tb',
    roles: TB_ROLES,
    perpanjanganDiizinkan: true,
    statusAdminDipilih: true,
    berkasProses: 'file_surat_tb',
  },
  ib: {
    jenis: 'ib',
    judul: 'Izin Belajar',
    singkatan: 'IB',
    routeList: LAYANAN_ROUTE.IB,
    routeDetail: LAYANAN_ROUTE.IB_DETAIL,
    testid: 'ib',
    roles: IB_ROLES,
    perpanjanganDiizinkan: false,
    statusAdminDipilih: false,
    berkasProses: null,
  },
}
