/**
 * CR-078 (T-06) — fokus setelah aksi ⋮ yang membuka ConfirmDialog. Harness memakai tabel + RowActionsMenu per baris +
 * ConfirmDialog, dengan urutan seperti halaman sungguhan: dialog ditutup lebih dulu, baru baris dihapus.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, ref, type PropType } from 'vue'

import ConfirmDialog from '../ConfirmDialog.vue'
import RowActionsMenu from '../RowActionsMenu.vue'
import type { RowAction } from '../rowActions'

import { selectRowAction } from './rowActionsMenu.helpers'

const AKSI: RowAction[] = [
  { key: 'edit', label: 'Edit' },
  { key: 'delete', label: 'Hapus', danger: true },
]

const Harness = defineComponent({
  props: { ids: { type: Array as PropType<string[]>, required: true } },
  setup(props) {
    const terbuka = ref(false)
    const aktif = ref('')
    return () =>
      h('div', [
        h(
          'table',
          h(
            'tbody',
            props.ids.map((id) =>
              h('tr', { key: id }, [
                h('td', [
                  h(RowActionsMenu, {
                    actions: AKSI,
                    label: `Aksi untuk ${id}`,
                    testid: `row-${id}`,
                    onSelect: (key: string) => {
                      if (key !== 'delete') return
                      aktif.value = id
                      terbuka.value = true
                    },
                  }),
                ]),
              ]),
            ),
          ),
        ),
        h(ConfirmDialog, {
          open: terbuka.value,
          title: `Hapus baris ${aktif.value}?`,
          danger: true,
          'onUpdate:open': (v: boolean) => (terbuka.value = v),
          onConfirm: () => (terbuka.value = false),
        }),
      ])
  },
})

const tunggu = async (): Promise<void> => {
  // Fokus kembali dijadwalkan radix dengan setTimeout(0) setelah dialog/menu ditutup.
  await new Promise((r) => setTimeout(r, 0))
  await flushPromises()
}

const pemicuBaris = (id: string): HTMLElement => document.body.querySelector<HTMLElement>(`[data-testid="row-${id}"]`)!

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('RowActionsMenu — fokus setelah ConfirmDialog', () => {
  it('Batal setelah Hapus mengembalikan fokus ke ⋮ baris itu, bukan ke body', async () => {
    const wrapper = mount(Harness, { props: { ids: ['a', 'b'] }, attachTo: document.body })

    await selectRowAction(wrapper, 'row-a', 'delete')
    await tunggu()
    expect(document.body.querySelector('[role="alertdialog"]')).not.toBeNull()

    const batal = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === 'Batal')!
    batal.click()
    await tunggu()

    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(document.activeElement).toBe(pemicuBaris('a'))
  })

  it('Ya setelah Hapus: dialog tertutup, fokus di ⋮ baris itu; saat baris dihapus fokus pindah ke ⋮ baris sesudahnya', async () => {
    const wrapper = mount(Harness, { props: { ids: ['a', 'b', 'c'] }, attachTo: document.body })

    await selectRowAction(wrapper, 'row-b', 'delete')
    await tunggu()
    const ya = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, lanjutkan'))!
    ya.click()
    await tunggu()

    expect(document.activeElement).toBe(pemicuBaris('b'))

    await wrapper.setProps({ ids: ['a', 'c'] })
    await tunggu()

    expect(document.activeElement).toBe(pemicuBaris('c'))
  })

  it('baris terakhir yang dihapus: fokus pindah ke ⋮ baris sebelumnya', async () => {
    const wrapper = mount(Harness, { props: { ids: ['a', 'b'] }, attachTo: document.body })

    await selectRowAction(wrapper, 'row-b', 'delete')
    await tunggu()
    const ya = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, lanjutkan'))!
    ya.click()
    await tunggu()
    await wrapper.setProps({ ids: ['a'] })
    await tunggu()

    expect(document.activeElement).toBe(pemicuBaris('a'))
  })
})
