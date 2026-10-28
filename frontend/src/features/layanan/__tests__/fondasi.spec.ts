/**
 * Paket 0 M7 (MAKE-026) — fondasi Modul C: matriks role menu (AC-11.5), normalisasi payload string (CR-049),
 * badge/stepper dari data server, dan dialog Tangguhkan.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { Role, type RoleCode } from '@/features/auth/types'

import StatusLayananBadge from '../components/StatusLayananBadge.vue'
import TangguhkanDialog from '../components/TangguhkanDialog.vue'
import { STATUS_CUTI, tampilanStatusCuti } from '../cutiStatus'
import { CUTI_ADMIN_ROLES, CUTI_ROLES, IB_ROLES, PETUGAS_LAYANAN_ROLES, PMK_ROLES, STATUS_LAYANAN_ROLES, TB_ROLES } from '../roles'
import { LAYANAN_ROUTE, layananRoutes } from '../routes.layanan'
import { flag, normalisasiAksi, normalisasiNada, normalisasiTahap, normalisasiTampilan, num, numOrNull } from '../services/normalisasi'
import { nadaStepper, tahapKeSteps } from '../tahapStepper'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

const SEMUA: RoleCode[] = [1, 2, 3, 4, 5, 6, 7, 8]
const menu = (daftar: readonly RoleCode[]) => SEMUA.filter((r) => daftar.includes(r))

describe('roles.ts — matriks menu PRD §6.11.2', () => {
  it('Cuti 1,2,3,6,7; Saldo/Petugas Approval 1,3', () => {
    expect(menu(CUTI_ROLES)).toEqual([1, 2, 3, 6, 7])
    expect(menu(CUTI_ADMIN_ROLES)).toEqual([1, 3])
  })

  it('TB tanpa role 7, IB tanpa role 6', () => {
    expect(menu(TB_ROLES)).toEqual([1, 2, 3, 6])
    expect(menu(IB_ROLES)).toEqual([1, 2, 3, 7])
    expect(TB_ROLES).not.toContain(Role.PPPK)
    expect(IB_ROLES).not.toContain(Role.PTT)
  })

  it('PMK 1,2,3; Status Layanan 1–7; PIC hanya 1', () => {
    expect(menu(PMK_ROLES)).toEqual([1, 2, 3])
    expect(menu(STATUS_LAYANAN_ROLES)).toEqual([1, 2, 3, 4, 5, 6, 7])
    expect(menu(PETUGAS_LAYANAN_ROLES)).toEqual([1])
  })
})

describe('routes.layanan.ts', () => {
  it('nama route tetap; halaman cuti dan PMK terdaftar (menu tersembunyi)', () => {
    expect(LAYANAN_ROUTE.STATUS).toBe('layanan-status')
    expect(LAYANAN_ROUTE.CUTI_DETAIL).toBe('layanan-cuti-detail')
    expect(layananRoutes.map((r) => r.name)).toEqual([
      LAYANAN_ROUTE.CUTI,
      LAYANAN_ROUTE.CUTI_DETAIL,
      LAYANAN_ROUTE.PMK,
      LAYANAN_ROUTE.PMK_DETAIL,
      LAYANAN_ROUTE.STATUS,
    ])
  })
})

describe('normalisasi — payload string MySQLi', () => {
  it('num/numOrNull/flag menormalkan string', () => {
    expect(num('3')).toBe(3)
    expect(num('')).toBe(0)
    expect(num('abc', -1)).toBe(-1)
    expect(numOrNull('0')).toBe(0)
    expect(numOrNull(null)).toBeNull()
    expect(flag('1')).toBe(true)
    expect(flag('0')).toBe(false)
    expect(flag(true)).toBe(true)
  })

  it('aksi {} dari "1"/"0" menjadi boolean; bentuk salah → kosong', () => {
    expect(normalisasiAksi({ ubah: '1', hapus: '0', proses: true, tunda: 0 })).toEqual({ ubah: true, hapus: false, proses: true, tunda: false })
    expect(normalisasiAksi(null)).toEqual({})
    expect(normalisasiAksi([1])).toEqual({})
  })

  it('nada tak dikenal → secondary; tahap tak lengkap tidak melempar', () => {
    expect(normalisasiNada('success')).toBe('success')
    expect(normalisasiNada('merah')).toBe('secondary')
    expect(normalisasiTahap([{ kode: 'atasan', label: 'Atasan Langsung', state: 'current', nama_petugas: 'Budi' }, { state: 'x' }, null])).toEqual([
      { kode: 'atasan', label: 'Atasan Langsung', state: 'current', nama_petugas: 'Budi' },
      { kode: '', label: '', state: 'todo', nama_petugas: null },
      { kode: '', label: '', state: 'todo', nama_petugas: null },
    ])
    expect(normalisasiTahap(undefined)).toEqual([])
  })

  it('normalisasiTampilan menggabungkan label, nada, tahap, aksi', () => {
    const t = normalisasiTampilan({
      label_status: 'Menunggu Atasan',
      nada_status: 'warning',
      tahap: [{ kode: 'diajukan', label: 'Diajukan', state: 'done', nama_petugas: null }],
      aksi: { proses: '1' },
    })
    expect(t.label_status).toBe('Menunggu Atasan')
    expect(t.nada_status).toBe('warning')
    expect(t.aksi).toEqual({ proses: true })
    expect(tahapKeSteps(t.tahap)).toEqual([{ label: 'Diajukan', state: 'done' }])
    expect(nadaStepper('warning')).toBe('warning')
    expect(nadaStepper('success')).toBe('info')
  })
})

describe('cutiStatus.ts — cadangan arti kode cuti (bukan statusRiwayat)', () => {
  it('3 = Menunggu PyB, 4 = Ditangguhkan; kode asing neutral', () => {
    expect(tampilanStatusCuti(STATUS_CUTI.DISETUJUI_AL)).toEqual({ label: 'Menunggu PyB', nada: 'info' })
    expect(tampilanStatusCuti(STATUS_CUTI.DITANGGUHKAN)).toEqual({ label: 'Ditangguhkan', nada: 'secondary' })
    expect(tampilanStatusCuti(99).nada).toBe('neutral')
  })
})

describe('StatusLayananBadge', () => {
  it('menampilkan label server dengan nada dan tooltip custom (tanpa title)', async () => {
    const wrapper = mount(StatusLayananBadge, { props: { label: 'Menunggu Atasan', nada: 'warning' }, attachTo: document.body })
    await flushPromises()
    const badge = wrapper.get('[data-testid="status-layanan-badge"]')
    expect(badge.text()).toBe('Menunggu Atasan')
    expect(badge.attributes('data-nada')).toBe('warning')
    expect(wrapper.html()).not.toContain('title=')
    expect(wrapper.find('[data-tooltip="Menunggu Atasan"]').exists()).toBe(true)
  })
})

describe('TangguhkanDialog', () => {
  async function buka(props: Record<string, unknown> = {}) {
    const wrapper = mount(TangguhkanDialog, { props: { open: true, subject: 'Cuti Tahunan', ...props }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }
  const q = <T extends Element>(sel: string) => document.body.querySelector<T>(sel)

  it('alasan opsional: konfirmasi mengirim { aksi: tangguhkan, reason_note }', async () => {
    const wrapper = await buka()
    const ta = q<HTMLTextAreaElement>('[data-testid="tangguhkan-reason"]')!
    ta.value = '  Kebutuhan dinas  '
    ta.dispatchEvent(new Event('input'))
    q<HTMLButtonElement>('[data-testid="tangguhkan-confirm"]')!.click()
    await flushPromises()
    expect(wrapper.emitted('confirm')?.[0]).toEqual([{ aksi: 'tangguhkan', reason_note: 'Kebutuhan dinas' }])
  })

  it('alasanWajib: kosong tidak terkirim dan menampilkan pesan', async () => {
    const wrapper = await buka({ alasanWajib: true })
    q<HTMLButtonElement>('[data-testid="tangguhkan-confirm"]')!.click()
    await flushPromises()
    expect(wrapper.emitted('confirm')).toBeUndefined()
    expect(q('[data-testid="tangguhkan-reason-error"]')?.textContent).toContain('wajib')
  })

  it('alasan lebih dari 255 byte ditolak', async () => {
    const wrapper = await buka()
    const ta = q<HTMLTextAreaElement>('[data-testid="tangguhkan-reason"]')!
    ta.value = 'é'.repeat(130)
    ta.dispatchEvent(new Event('input'))
    q<HTMLButtonElement>('[data-testid="tangguhkan-confirm"]')!.click()
    await flushPromises()
    expect(wrapper.emitted('confirm')).toBeUndefined()
    expect(q('[data-testid="tangguhkan-reason-error"]')?.textContent).toContain('255')
  })
})
