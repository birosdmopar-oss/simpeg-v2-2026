/**
 * Konfigurasi halaman usulan mandiri (mode usulan mesin riwayat FE, WS-1 M4 MAKE-007): jenis ber-alur `usulan`
 * (`karpeg`, `kariskarsu`) — bukan tab Detail Pegawai. Isian & berkas kolom disalin dari Definisi backend
 * `Riwayat/Definisi/Karpeg.php` / `Kariskarsu.php` (legacy `karpeg/form.php`, `kariskarsu/form.php`).
 */
import type { JenisEngine } from '../types'

import { S, type BerkasKolomDef, type FieldDef } from './riwayat.config'

export type { BerkasKolomDef } from './riwayat.config'

export interface UsulanConfig {
  jenis: JenisEngine
  title: string
  /** Kata benda untuk tombol "Ajukan …" dan judul dialog. */
  singular: string
  /** Judul untuk pegawai laki-laki / perempuan (Karis/Karsu, legacy `Kariskarsu.php:38-45`); null = `title`. */
  titleMenurutJenisKelamin: { 1: string; 2: string } | null
  fields: FieldDef[]
  berkas: BerkasKolomDef[]
  batas_mb: number
  ekstensi: string[]
}

const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xls', 'xlsx', 'doc', 'docx']

export const USULAN_KARPEG: UsulanConfig = {
  jenis: 'karpeg',
  title: 'Kartu Pegawai',
  singular: 'usulan Karpeg',
  titleMenurutJenisKelamin: null,
  fields: [
    S('jenis_permohonan', 'Jenis Permohonan', [
      { value: 'Permohonan Pertama', label: 'Permohonan Pertama' },
      { value: 'Karena Kehilangan', label: 'Karena Kehilangan' },
    ], { required: true }),
  ],
  berkas: [
    { kolom: 'file_1', label: 'SK CPNS', wajib: true },
    { kolom: 'file_2', label: 'SK PNS', wajib: true },
    { kolom: 'file_3', label: 'SPMT', wajib: true },
    { kolom: 'file_4', label: 'Sertifikat Prajabatan', wajib: true },
    { kolom: 'file_5', label: 'Pas Foto', wajib: true },
    { kolom: 'file_surat_hilang', label: 'Surat Keterangan Hilang', wajib: true, wajibBila: { field: 'jenis_permohonan', in: ['Karena Kehilangan'] } },
  ],
  batas_mb: 5,
  ekstensi: EKSTENSI,
}

export const USULAN_KARISKARSU: UsulanConfig = {
  jenis: 'kariskarsu',
  title: 'Karis/Karsu',
  singular: 'usulan Karis/Karsu',
  titleMenurutJenisKelamin: { 1: 'Kartu Istri (Karis)', 2: 'Kartu Suami (Karsu)' },
  fields: [],
  berkas: [
    { kolom: 'file_1', label: 'SK CPNS', wajib: true },
    { kolom: 'file_2', label: 'SK PNS', wajib: true },
    { kolom: 'file_3', label: 'Buku Nikah', wajib: true },
    { kolom: 'file_4', label: 'Laporan Perkawinan', wajib: true },
    { kolom: 'file_5', label: 'Pas Foto Istri/Suami 3x4', wajib: true },
    { kolom: 'file_surat_hilang', label: 'Surat Keterangan Hilang', wajib: false },
    { kolom: 'file_alasan', label: 'Akta Kematian/Surat Cerai', wajib: false },
  ],
  batas_mb: 5,
  ekstensi: EKSTENSI,
}
