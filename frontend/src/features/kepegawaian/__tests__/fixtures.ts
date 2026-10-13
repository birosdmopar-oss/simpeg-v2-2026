/**
 * Fixture test Modul B (bukan spec) — data contoh HANYA untuk test, berbentuk kolom DDL. Kode runtime tidak boleh
 * mengimpor berkas ini (dijaga noRuntimeMock.spec.ts). Semua isi fiktif; NIP sintetis 18 digit.
 */
import type { ApiError } from '@/lib/axios'

import type { Pegawai, PegawaiDetail, PegawaiListItem, RiwayatRow, RiwayatTabDescriptor } from '../types'

export const NIP = '199001012015011001'

export function apiError(status: number | null, errors: Record<string, string[]> | null = null): ApiError {
  return {
    status,
    message: status === null ? 'network' : `HTTP ${status}`,
    errors,
    isNetworkError: status === null,
    original: new Error('x'),
  } as ApiError
}

export function pegawai(overrides: Partial<Pegawai> = {}): Pegawai {
  return {
    nip: NIP,
    id_provinsi_lahir: '32',
    id_kabupaten_kota_lahir: '3273',
    id_agama: 1,
    id_jenis_pegawai: 1,
    id_jenis_status: 1,
    nip_lama: '123456789',
    nama: 'Budi Contoh',
    glr_awal: 'Dr.',
    glr_akhir: 'S.E., M.M.',
    tgl_lahir: '1990-01-01',
    provinsi_lahir: 'Jawa Barat',
    provinsi_lahir_lain: null,
    kabupaten_kota_lahir: 'Kota Bandung',
    kabupaten_kota_lahir_lain: null,
    agama: 'Islam',
    jenis_kelamin: 1,
    npwp: null,
    nik: '3273010101900001',
    bpjs_kes: '0001234567890',
    bpjs_ket: null,
    no_taspen: null,
    no_hp: '081200000000',
    jenis_kerabat: null,
    no_telp_kerabat: null,
    status_pernikahan: 1,
    jenis_pegawai: 'PNS',
    jenis_status: 'Biasa',
    tmt_status: '2015-01-01',
    foto: null,
    keterangan: null,
    status: 1,
    flag_update: 0,
    id_pns_siasn: null,
    ...overrides,
  }
}

export function descriptor(jenis: RiwayatTabDescriptor['jenis'], label: string, overrides: Partial<RiwayatTabDescriptor> = {}): RiwayatTabDescriptor {
  return { jenis, label, can_view: true, can_create: true, can_edit: true, can_delete: true, can_process: false, ...overrides }
}

export function pegawaiDetail(tabs: RiwayatTabDescriptor[] = [], overrides: Partial<Pegawai> = {}): PegawaiDetail {
  return { ...pegawai(overrides), tabs }
}

export function listItem(i: number): PegawaiListItem {
  return {
    nip: `1990010120150110${String(i).padStart(2, '0')}`,
    nip_lama: null,
    nama: `Pegawai ${i}`,
    glr_awal: null,
    glr_akhir: i % 2 === 0 ? 'S.Kom.' : null,
    jenis_kelamin: i % 2 === 0 ? 2 : 1,
    agama: 'Islam',
    jenis_pegawai: i % 3 === 0 ? 'PPPK' : 'PNS',
    jenis_status: 'Biasa',
    tmt_status: null,
  }
}

export function pendidikanRow(id: number, overrides: Record<string, unknown> = {}): RiwayatRow {
  return {
    id_riwayat_pendidikan: id,
    nip: NIP,
    id_jenjang_pendidikan: 7,
    id_jurusan_pendidikan: 12,
    jenjang_pendidikan_singkat: 'S1',
    jurusan_pendidikan: 'Manajemen',
    institusi_pendidikan: `Universitas ${id}`,
    tgl_lulus: '2012-08-30',
    no_ijazah: `IJZ-${id}`,
    glr_awal: null,
    glr_akhir: 'S.E.',
    no_sk_penc_glr: null,
    ipk: '3.50',
    nem: null,
    keterangan: null,
    status: 1,
    reason_note: null,
    ...overrides,
  }
}
