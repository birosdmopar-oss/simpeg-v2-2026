/**
 * Menu & rute Presensi per role (AC-FE.6, PRD Fase 5A §7.8.2): grup Presensi ber-`rilis` tampil walau ACTIVE_PHASE
 * belum 5, anak disaring per role, tujuan menu sama dengan path route, guard route memakai role yang sama dengan menu.
 * MAKE-034 tahap (a): Slip Gaji (2, 6, 7); tahap (b): Kelola Slip Gaji (1, 3); tahap (c): Presensi Saya (2, 6, 7) dan
 * Data Presensi (1, 3, 4, 5) — lengkap sesuai PRD 5A §7.8.2.
 */
import { describe, expect, it } from 'vitest'

import { Role } from '@/features/auth/types'
import { ACTIVE_PHASE, buildNav } from '@/shared/layouts/nav.config'

import {
  PRESENSI_ADMIN_ROLES,
  PRESENSI_PEGAWAI_ROLES,
  REKAP_TUKIN_IL_ROLES,
  REKAP_TUKIN_ROLES,
  SLIP_GAJI_ADMIN_ROLES,
  SLIP_GAJI_PEGAWAI_ROLES,
  TUKIN_DAFTAR_ROLES,
  TUKIN_IL_ROLES,
  TUKIN_PER_PEGAWAI_ROLES,
  TUKIN_SAYA_ROLES,
} from '../roles'
import { presensiRoutes } from '../routes.presensi'

const anakPresensi = (role: (typeof Role)[keyof typeof Role]) =>
  buildNav(role)
    .flatMap((g) => g.items)
    .find((i) => i.key === 'presensi')
    ?.children?.map((c) => c.label) ?? []

describe('navPresensi', () => {
  it('ACTIVE_PHASE tidak dinaikkan oleh 5A (baru 4 oleh penutup Fase 4 M7)', () => {
    expect(ACTIVE_PHASE).toBe(4)
  })

  it.each([
    [Role.SUPER_ADMIN, ['Kelola Slip Gaji', 'Data Presensi', 'Laporan Tukin', 'Rekap Tukin', 'Rekap Kehadiran', 'Rekap Uang Makan', 'Penyesuaian Tukin']],
    [Role.PEGAWAI, ['Presensi Saya', 'Slip Gaji', 'Laporan Tukin Saya']],
    [Role.ADMIN_SATKER, ['Kelola Slip Gaji', 'Data Presensi', 'Laporan Tukin', 'Rekap Tukin', 'Rekap Kehadiran', 'Rekap Uang Makan']],
    [Role.ADMIN_VIEW_ESELON1, ['Data Presensi', 'Rekap Kehadiran']],
    [Role.MENTERI, ['Data Presensi']],
    [Role.PTT, ['Presensi Saya', 'Slip Gaji', 'Laporan Tukin Saya']],
    [Role.PPPK, ['Presensi Saya', 'Slip Gaji', 'Laporan Tukin Saya']],
    [Role.PIMPINAN, []],
  ])('role %i melihat anak Presensi %j', (role, harapan) => {
    expect(anakPresensi(role)).toEqual(harapan)
  })

  it('tujuan menu Slip Gaji = path route presensi-slip-gaji, role route = role menu', () => {
    const rute = presensiRoutes.find((r) => r.name === 'presensi-slip-gaji')
    const anak = buildNav(Role.PEGAWAI)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'presensi')
      ?.children?.find((c) => c.key === 'presensi-slip-gaji')
    expect(anak?.to).toBe(rute?.path)
    expect(rute?.meta?.roles).toEqual(SLIP_GAJI_PEGAWAI_ROLES)
    expect(anak?.roles).toEqual(SLIP_GAJI_PEGAWAI_ROLES)
  })

  it('tujuan menu Kelola Slip Gaji = path route presensi-slip-gaji-kelola, role route = role menu', () => {
    const rute = presensiRoutes.find((r) => r.name === 'presensi-slip-gaji-kelola')
    const anak = buildNav(Role.ADMIN_SATKER)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'presensi')
      ?.children?.find((c) => c.key === 'presensi-slip-gaji-kelola')
    expect(anak?.to).toBe(rute?.path)
    expect(rute?.meta?.roles).toEqual(SLIP_GAJI_ADMIN_ROLES)
    expect(anak?.roles).toEqual(SLIP_GAJI_ADMIN_ROLES)
  })

  it.each([
    ['presensi-saya', PRESENSI_PEGAWAI_ROLES, Role.PEGAWAI],
    ['presensi-data', PRESENSI_ADMIN_ROLES, Role.MENTERI],
    ['presensi-tukin', TUKIN_DAFTAR_ROLES, Role.ADMIN_SATKER],
    ['presensi-tukin-saya', TUKIN_SAYA_ROLES, Role.PEGAWAI],
    ['presensi-rekap-tukin', REKAP_TUKIN_ROLES, Role.ADMIN_SATKER],
  ] as const)('tujuan menu %s = path route dan role route = role menu', (key, roles, role) => {
    const rute = presensiRoutes.find((r) => r.name === key)
    const anak = buildNav(role)
      .flatMap((g) => g.items)
      .find((i) => i.key === 'presensi')
      ?.children?.find((c) => c.key === key)
    expect(anak?.to).toBe(rute?.path)
    expect(rute?.meta?.roles).toEqual(roles)
    expect(anak?.roles).toEqual(roles)
  })

  it('route Laporan Tukin (MAKE-048): role per halaman PRD 5C §6; "saya" statis sebelum ":nip" numerik; IL hanya role 1', () => {
    const rute = (nama: string) => presensiRoutes.find((r) => r.name === nama)
    expect(rute('presensi-tukin-il')?.meta?.roles).toEqual(TUKIN_IL_ROLES)
    expect(rute('presensi-tukin-pegawai')?.meta?.roles).toEqual(TUKIN_PER_PEGAWAI_ROLES)
    expect(TUKIN_PER_PEGAWAI_ROLES).not.toContain(Role.PIMPINAN)
    expect(rute('presensi-tukin-saya')?.path).toBe('/presensi/tukin/saya')
    expect(rute('presensi-tukin-pegawai')?.path).toBe(String.raw`/presensi/tukin/:nip(\d+)`)
  })

  it('route Rekap Tukin (MAKE-049): role sesuai PRD 5C §6; IL role 1; statis sebelum ":nip" numerik', () => {
    const rute = (nama: string) => presensiRoutes.find((r) => r.name === nama)
    expect(rute('presensi-rekap-tukin')?.path).toBe('/presensi/tukin/rekap')
    expect(rute('presensi-rekap-tukin')?.meta?.roles).toEqual(REKAP_TUKIN_ROLES)
    expect(rute('presensi-rekap-tukin-il')?.path).toBe('/presensi/tukin/rekap-il')
    expect(rute('presensi-rekap-tukin-il')?.meta?.roles).toEqual(REKAP_TUKIN_IL_ROLES)
    expect(REKAP_TUKIN_ROLES).toEqual([Role.SUPER_ADMIN, Role.ADMIN_SATKER])
  })
})
