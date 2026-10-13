/**
 * ApprovalDialog — Setujui/Tolak: alasan wajib saat Tolak (validasi + pesan), opsional saat Setujui, batas 255 byte
 * (tinytext), slot `diff`, dan emit `confirm` = body `POST …/{id}/process` `{ aksi, reason_note }`.
 * Item ⋮ Setujui/Tolak (approvalRowActions) masuk grup "aksi lain": sesudah Edit, sebelum Hapus.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import ApprovalDialog from '../ApprovalDialog.vue'
import { approvalRowActions } from '../approvalActions'
import RowActionsMenu from '../RowActionsMenu.vue'
import type { RowAction } from '../rowActions'

import { rowMenuActions } from './rowActionsMenu.helpers'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

async function mountDialog(props: Partial<InstanceType<typeof ApprovalDialog>['$props']> = {}, slots: Record<string, string> = {}) {
  const wrapper = mount(ApprovalDialog, {
    props: { open: true, mode: 'tolak', subject: 'Riwayat Pendidikan S2', ...props },
    slots,
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

const q = <T extends Element = HTMLElement>(testid: string) => document.body.querySelector<T>(`[data-testid="${testid}"]`)

async function typeReason(value: string): Promise<void> {
  const textarea = q<HTMLTextAreaElement>('approval-reason')!
  textarea.value = value
  textarea.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function clickConfirm(): Promise<void> {
  q('approval-confirm')!.click()
  await flushPromises()
}

describe('ApprovalDialog — Tolak', () => {
  it('judul & tombol sesuai mode, alasan bertanda wajib', async () => {
    await mountDialog()
    expect(q('approval-dialog')?.getAttribute('data-mode')).toBe('tolak')
    expect(q('approval-dialog')?.textContent).toContain('Tolak Riwayat Pendidikan S2')
    expect(q('approval-reason')?.getAttribute('aria-required')).toBe('true')
    expect(q('approval-confirm')?.textContent?.trim()).toBe('Tolak')
  })

  it('alasan kosong (atau spasi saja) ditolak dengan pesan; confirm tidak terpancar', async () => {
    const wrapper = await mountDialog()
    await typeReason('   ')
    await clickConfirm()

    expect(q('approval-reason-error')?.textContent).toContain('Alasan penolakan wajib diisi.')
    expect(q('approval-reason')?.getAttribute('aria-invalid')).toBe('true')
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('alasan terisi → confirm { aksi: "tolak", reason_note } (dipangkas)', async () => {
    const wrapper = await mountDialog()
    await typeReason('  Dokumen ijazah tidak terbaca  ')
    await clickConfirm()

    expect(wrapper.emitted('confirm')).toEqual([[{ aksi: 'tolak', reason_note: 'Dokumen ijazah tidak terbaca' }]])
    expect(q('approval-reason-error')).toBeNull()
  })

  it('alasan lebih dari 255 byte ditolak', async () => {
    const wrapper = await mountDialog()
    await typeReason('é'.repeat(200))
    await clickConfirm()

    expect(q('approval-reason-error')?.textContent).toContain('terlalu panjang')
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('saat loading tombol nonaktif dan confirm tidak terpancar ulang', async () => {
    const wrapper = await mountDialog({ loading: true })
    await typeReason('Alasan')
    await clickConfirm()

    expect(q<HTMLButtonElement>('approval-confirm')?.disabled).toBe(true)
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('galat server ditampilkan', async () => {
    await mountDialog({ error: 'Data sudah diproses pengguna lain.' })
    expect(q('approval-error')?.textContent).toContain('Data sudah diproses pengguna lain.')
  })
})

describe('ApprovalDialog — Setujui', () => {
  it('alasan opsional: kosong tetap boleh → confirm { aksi: "setujui", reason_note: "" }', async () => {
    const wrapper = await mountDialog({ mode: 'setujui' })
    expect(q('approval-reason')?.getAttribute('aria-required')).toBe('false')
    expect(q('approval-confirm')?.textContent?.trim()).toBe('Setujui')

    await clickConfirm()
    expect(wrapper.emitted('confirm')).toEqual([[{ aksi: 'setujui', reason_note: '' }]])
  })

  it('slot diff dirender untuk perbandingan biodata (B-04)', async () => {
    await mountDialog({ mode: 'setujui' }, { diff: '<table data-testid="diff-table"><tr><td>Nama</td></tr></table>' })
    expect(q('approval-diff')?.querySelector('[data-testid="diff-table"]')).not.toBeNull()
  })

  it('tanpa slot diff tidak ada wadah diff', async () => {
    await mountDialog({ mode: 'setujui' })
    expect(q('approval-diff')).toBeNull()
  })

  it('Batal menutup dialog lewat update:open', async () => {
    const wrapper = await mountDialog({ mode: 'setujui' })
    const cancel = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Batal')
    cancel?.click()
    await flushPromises()
    expect(wrapper.emitted('update:open')).toEqual([[false]])
  })

  it('dibuka ulang → alasan sebelumnya dikosongkan', async () => {
    const wrapper = await mountDialog()
    await typeReason('Alasan lama')
    await wrapper.setProps({ open: false })
    await wrapper.setProps({ open: true })
    await flushPromises()
    expect(q<HTMLTextAreaElement>('approval-reason')?.value).toBe('')
  })
})

describe('approvalRowActions — item ⋮ Setujui/Tolak', () => {
  const TESTID = 'riwayat-actions-1'

  function mountMenu(actions: RowAction[]) {
    return mount(RowActionsMenu, { props: { actions, label: 'Aksi untuk S2 Manajemen', testid: TESTID }, attachTo: document.body })
  }

  it('urutan menu: Edit → Setujui → Tolak → Hapus (merah, paling bawah)', async () => {
    const actions: RowAction[] = [
      { key: 'edit', label: 'Edit' },
      ...approvalRowActions({ canProcess: true, status: 0 }),
      { key: 'hapus', label: 'Hapus', danger: true },
    ]
    const items = await rowMenuActions(mountMenu(actions), TESTID)
    expect(items.map((i) => [i.key, i.label])).toEqual([
      ['edit', 'Edit'],
      ['setujui', 'Setujui'],
      ['tolak', 'Tolak'],
      ['hapus', 'Hapus'],
    ])
    expect(items.at(-1)?.danger).toBe(true)
  })

  it.each([
    { name: 'tanpa hak proses', opts: { canProcess: false, status: 0 } },
    { name: 'baris sudah disetujui', opts: { canProcess: true, status: 1 } },
    { name: 'baris ditolak', opts: { canProcess: true, status: 2 } },
    { name: 'baris dihapus', opts: { canProcess: true, status: 10 } },
    { name: 'status Diproses tanpa flag', opts: { canProcess: true, status: 3 } },
  ])('$name → Setujui/Tolak tersembunyi', ({ opts }) => {
    expect(approvalRowActions(opts).every((a) => a.hidden)).toBe(true)
  })

  it('status Diproses dengan flag aktif → tampil', () => {
    expect(approvalRowActions({ canProcess: true, status: 3, diprosesEnabled: true }).some((a) => a.hidden)).toBe(false)
  })

  it('sedang memproses → tampil tetapi nonaktif', () => {
    expect(approvalRowActions({ canProcess: true, status: 0, busy: true }).map((a) => [a.hidden, a.disabled])).toEqual([
      [false, true],
      [false, true],
    ])
  })
})
