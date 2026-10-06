/**
 * G-09 Web Config (DBV-006/CR-030) — halaman admin: daftar per kelompok + badge Bawaan/Tidak valid/Tidak dikenal, menu ⋮
 * (Edit → Hapus danger; Hapus hanya bila ada nilai tersimpan, Edit tidak ada untuk key tak dikenal; AGENTS.md bagian 1),
 * form ubah dengan validasi tipe + 422 backend, hapus lewat ConfirmDialog, dan route /web-config hanya role 1.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/webConfig.service', () => ({
  webConfigService: { list: vi.fn(), get: vi.fn(), save: vi.fn(), remove: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import router from '@/router'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import { webConfigService } from '../services/webConfig.service'
import WebConfigView from '../views/WebConfigView.vue'
import type { WebConfigItem } from '../webConfig.types'

function item(override: Partial<WebConfigItem>): WebConfigItem {
  return {
    config_name: 'x',
    id_web_config: null,
    config_value: null,
    remark: null,
    updated_at: null,
    updated_by: null,
    is_default: true,
    known: true,
    label: 'X',
    group: 'Identitas Instansi',
    type: 'text',
    description: 'Deskripsi.',
    default: '',
    effective: '',
    valid: true,
    personal: false,
    constraints: { max_length: 255 },
    ...override,
  }
}

const items: WebConfigItem[] = [
  item({ config_name: 'nama_kementerian', label: 'Nama Kementerian', id_web_config: 1, config_value: 'Kementerian Uji', effective: 'Kementerian Uji', is_default: false }),
  item({ config_name: 'TL1/PSW1', label: 'TL1 / PSW1', group: 'Potongan Tunjangan Kinerja (%)', type: 'decimal', default: null, effective: null, constraints: { min: 0, max: 100, scale: 2 } }),
  item({ config_name: 'TK', label: 'TK', group: 'Potongan Tunjangan Kinerja (%)', type: 'decimal', id_web_config: 3, config_value: '3,5', is_default: false, valid: false, default: null, effective: null, constraints: { min: 0, max: 100, scale: 2 } }),
  item({ config_name: 'key_lama', label: 'key_lama', group: 'Key Tidak Dikenal', type: null, known: false, id_web_config: 9, config_value: 'x', effective: 'x', is_default: false, valid: false, constraints: {} }),
]

async function mountView() {
  setActivePinia(createPinia())
  const wrapper = mount(WebConfigView, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

function dialogButton(label: string, role = 'dialog'): HTMLButtonElement {
  const button = Array.from(document.body.querySelectorAll<HTMLButtonElement>(`[role="${role}"] button`)).find((b) => b.textContent?.trim() === label)
  if (!button) throw new Error(`tombol ${label} tidak ditemukan`)
  return button
}

beforeEach(() => {
  document.body.innerHTML = ''
  vi.mocked(webConfigService.list).mockReset().mockResolvedValue(items)
  vi.mocked(webConfigService.save).mockReset()
  vi.mocked(webConfigService.remove).mockReset()
})

describe('WebConfigView', () => {
  it('daftar per kelompok, nilai berlaku, dan badge', async () => {
    const wrapper = await mountView()

    const groups = wrapper.findAll('th[scope="colgroup"]').map((th) => th.text())
    expect(groups).toEqual(['Identitas Instansi', 'Potongan Tunjangan Kinerja (%)', 'Key Tidak Dikenal'])

    const nama = wrapper.get('[data-testid="web-config-row-nama_kementerian"]')
    expect(nama.text()).toContain('Kementerian Uji')
    expect(nama.find('[data-badge]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="web-config-row-TL1-PSW1"]').get('[data-badge]').attributes('data-badge')).toBe('default')
    expect(wrapper.get('[data-testid="web-config-row-TK"]').get('[data-badge]').attributes('data-badge')).toBe('invalid')
    expect(wrapper.get('[data-testid="web-config-row-key_lama"]').get('[data-badge]').attributes('data-badge')).toBe('unknown')

    await wrapper.get('[data-testid="web-config-search"]').setValue('psw')
    expect(wrapper.findAll('tr[data-testid^="web-config-row-"]').map((r) => r.attributes('data-testid'))).toEqual(['web-config-row-TL1-PSW1'])
    wrapper.unmount()
  })

  it('menu ⋮: Edit lalu Hapus (danger) hanya bila ada nilai tersimpan; key tak dikenal hanya Hapus', async () => {
    const wrapper = await mountView()

    expect(await rowMenuActions(wrapper, 'web-config-actions-nama_kementerian')).toEqual([
      { key: 'edit', label: 'Edit', disabled: false, danger: false },
      { key: 'delete', label: 'Hapus', disabled: false, danger: true },
    ])
    expect((await rowMenuActions(wrapper, 'web-config-actions-TL1-PSW1')).map((a) => a.key)).toEqual(['edit'])
    expect((await rowMenuActions(wrapper, 'web-config-actions-key_lama')).map((a) => a.key)).toEqual(['delete'])
    expect(wrapper.get('[data-testid="web-config-actions-TK"]').attributes('aria-label')).toBe('Aksi untuk TK')
    wrapper.unmount()
  })

  it('Edit: validasi tipe di form, lalu simpan; 422 backend dipetakan ke field', async () => {
    const wrapper = await mountView()
    await selectRowAction(wrapper, 'web-config-actions-TL1-PSW1', 'edit')

    const input = document.body.querySelector<HTMLInputElement>('input[name="config_value"]')
    if (!input) throw new Error('input nilai tidak ada')
    // Tata letak form admin redesign (CR-028 / ISSUE-007): grid form, field dan baris tombol dua kolom penuh.
    const form = document.body.querySelector<HTMLFormElement>('form[data-testid="web-config-form"]')
    expect(form?.classList).toContain('md:grid-cols-2')
    expect(input.closest('form > *')?.classList).toContain('md:col-span-2')
    expect(document.body.querySelector('form[data-testid="web-config-form"] button[type="submit"]')?.parentElement?.classList).toContain('md:col-span-2')
    input.value = '1,5'
    input.dispatchEvent(new Event('input'))
    dialogButton('Simpan').click()
    await flushPromises()
    expect(document.body.textContent).toContain('Gunakan titik sebagai pemisah desimal')
    expect(webConfigService.save).not.toHaveBeenCalled()

    vi.mocked(webConfigService.save).mockRejectedValueOnce({
      status: 422,
      message: 'Nilai harus antara 0 dan 100.',
      errors: { config_value: ['Nilai harus antara 0 dan 100.'] },
      isNetworkError: false,
      original: new AxiosError('x'),
    })
    input.value = '50'
    input.dispatchEvent(new Event('input'))
    dialogButton('Simpan').click()
    await flushPromises()
    expect(webConfigService.save).toHaveBeenCalledWith('TL1/PSW1', { config_value: '50', remark: '' })
    expect(document.body.textContent).toContain('Nilai harus antara 0 dan 100.')

    vi.mocked(webConfigService.save).mockResolvedValueOnce({ ...items[1]!, config_value: '0.5', effective: '0.5', is_default: false })
    input.value = '0.5'
    input.dispatchEvent(new Event('input'))
    dialogButton('Simpan').click()
    await flushPromises()
    expect(webConfigService.save).toHaveBeenLastCalledWith('TL1/PSW1', { config_value: '0.5', remark: '' })
    expect(wrapper.text()).toContain('"TL1 / PSW1" disimpan.')
    expect(webConfigService.list).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('Hapus lewat ConfirmDialog → nilai kembali ke bawaan', async () => {
    vi.mocked(webConfigService.remove).mockResolvedValue({ deleted: true, item: { ...items[0]!, is_default: true } })
    const wrapper = await mountView()

    await selectRowAction(wrapper, 'web-config-actions-nama_kementerian', 'delete')
    const dialog = document.body.querySelector('[role="alertdialog"]')
    expect(dialog?.textContent).toContain('Hapus nilai "Nama Kementerian"?')
    expect(dialog?.textContent).toContain('kembali memakai nilai bawaan')
    expect(webConfigService.remove).not.toHaveBeenCalled()

    dialogButton('Hapus', 'alertdialog').click()
    await flushPromises()
    expect(webConfigService.remove).toHaveBeenCalledWith('nama_kementerian')
    expect(wrapper.text()).toContain('"Nama Kementerian" kembali ke nilai bawaan.')
    wrapper.unmount()
  })

  it('route /web-config hanya untuk role 1', () => {
    const route = router.getRoutes().find((r) => r.name === 'web-config')
    expect(route?.path).toBe('/web-config')
    expect(route?.meta.roles).toEqual([Role.SUPER_ADMIN])
  })
})
