/** Halo Simpeg — drawer pegawai (kirim pesan) dan inbox admin (balas, penanda belum dibaca). Percakapan di memori. */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import HaloDrawer from '../components/HaloDrawer.vue'
import { haloStore } from '../halo.store'
import AdminHaloPage from '../views/AdminHaloPage.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('HaloDrawer', () => {
  it('menampilkan keadaan kosong, lalu pesan yang dikirim muncul dan input dikosongkan', async () => {
    loginAs(Role.PEGAWAI)
    const wrapper = mount(HaloDrawer, { props: { open: true }, attachTo: document.body })
    await flushPromises()
    expect(document.body.textContent).toContain('Belum ada percakapan')
    const input = document.body.querySelector('[data-testid="halo-input"]') as HTMLTextAreaElement
    input.value = 'Halo admin'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(document.body.querySelector('[data-testid="halo-send"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(document.body.querySelector('[data-from="user"]')?.textContent).toContain('Halo admin')
    expect((document.body.querySelector('[data-testid="halo-input"]') as HTMLTextAreaElement).value).toBe('')
    wrapper.unmount()
  })
})

describe('AdminHaloPage', () => {
  it('membuka percakapan menghapus penanda belum dibaca dan balasan tampil', async () => {
    loginAs(Role.SUPER_ADMIN)
    const router = createShellRouter()
    await router.push('/')
    await router.isReady()
    const wrapper = mount(AdminHaloPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()

    expect(wrapper.find('[data-testid="halo-unread-1"]').exists()).toBe(true)
    await wrapper.get('[data-testid="halo-thread-1"]').trigger('click')
    expect(wrapper.find('[data-testid="halo-unread-1"]').exists()).toBe(false)

    await wrapper.get('[data-testid="halo-reply-input"]').setValue('Silakan buka menu Data Keluarga.')
    await wrapper.get('[data-testid="halo-reply-send"]').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[data-testid="halo-admin-messages"]').text()).toContain('Data Keluarga')
    expect(haloStore.state.threads.find((t) => t.id === 1)?.messages.at(-1)?.from).toBe('admin')
  })
})
