/**
 * Menu & rute Presensi per role (AC-FE.6, PRD Fase 5A §7.8.2): grup Presensi ber-`rilis` tampil walau ACTIVE_PHASE
 * belum 5, anak disaring per role, tujuan menu sama dengan path route, guard route memakai role yang sama dengan menu.
 * MAKE-034 tahap (a): Slip Gaji (2, 6, 7); tahap (b): Kelola Slip Gaji (1, 3); anak lain menyusul di tahap (c).
 */
import { describe, expect, it } from 'vitest'

import { Role } from '@/features/auth/types'
import { ACTIVE_PHASE, buildNav } from '@/shared/layouts/nav.config'

import { SLIP_GAJI_ADMIN_ROLES, SLIP_GAJI_PEGAWAI_ROLES } from '../roles'
import { presensiRoutes } from '../routes.presensi'

const anakPresensi = (role: (typeof Role)[keyof typeof Role]) =>
  buildNav(role)
    .flatMap((g) => g.items)
    .find((i) => i.key === 'presensi')
    ?.children?.map((c) => c.label) ?? []

describe('navPresensi', () => {
  it('ACTIVE_PHASE tidak dinaikkan oleh 5A', () => {
    expect(ACTIVE_PHASE).toBe(3)
  })

  it.each([
    [Role.SUPER_ADMIN, ['Kelola Slip Gaji']],
    [Role.PEGAWAI, ['Slip Gaji']],
    [Role.ADMIN_SATKER, ['Kelola Slip Gaji']],
    [Role.ADMIN_VIEW_ESELON1, []],
    [Role.MENTERI, []],
    [Role.PTT, ['Slip Gaji']],
    [Role.PPPK, ['Slip Gaji']],
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
})
