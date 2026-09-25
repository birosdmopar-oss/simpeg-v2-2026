/**
 * G-10 — widget rating artikel FAQ (DBV-002/CR-003 §6): tampil hanya bila can_rate / sudah menilai, rate 1 langsung
 * terkirim, rate 2 wajib memilih alasan (validasi "Lainnya"), ucapan terima kasih setelah terkirim.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/faq.service', () => ({
  faqService: { rate: vi.fn() },
}))

import FaqRatingWidget from '../components/FaqRatingWidget.vue'
import { FAQ_RATE_REASONS } from '../schemas/faq.schema'
import { faqService } from '../services/faq.service'
import type { FaqRating } from '../types'

const rate = vi.mocked(faqService.rate)
const THANKS = 'Terima kasih atas penilaian Anda.'

function mountWidget(rating: FaqRating) {
  return mount(FaqRatingWidget, { props: { articleId: '100', rating } })
}

const canRate: FaqRating = { can_rate: true, rated: false, rate: null }

function apiError(status: number, errors: Record<string, string[]> | null, message = 'Gagal') {
  return { status, message, errors, isNetworkError: false, original: new AxiosError(message) }
}

beforeEach(() => {
  rate.mockReset()
})

describe('FaqRatingWidget', () => {
  it('role yang tidak boleh menilai (can_rate false, belum menilai) → widget tidak tampil', () => {
    const wrapper = mountWidget({ can_rate: false, rated: false, rate: null })
    expect(wrapper.find('[data-testid="faq-rating"]').exists()).toBe(false)
  })

  it('sudah menilai → ucapan terima kasih tanpa tombol', () => {
    const wrapper = mountWidget({ can_rate: false, rated: true, rate: 1 })
    expect(wrapper.get('[data-testid="faq-rating-done"]').text()).toContain(THANKS)
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('Membantu → POST rate 1 tanpa alasan, lalu terima kasih + emit rated', async () => {
    rate.mockResolvedValue({ rated: true, rate: 1 })
    const wrapper = mountWidget(canRate)
    expect(wrapper.text()).toContain('Apakah artikel ini membantu?')

    await wrapper.get('[data-testid="faq-rate-yes"]').trigger('click')
    await flushPromises()

    expect(rate).toHaveBeenCalledWith('100', { rate: 1 })
    expect(wrapper.get('[data-testid="faq-rating-done"]').text()).toContain(THANKS)
    expect(wrapper.emitted('rated')?.[0]).toEqual([1])
  })

  it('Kurang Membantu → pilihan 4 alasan baku + Lainnya; kirim tanpa memilih ditolak', async () => {
    const wrapper = mountWidget(canRate)
    await wrapper.get('[data-testid="faq-rate-no"]').trigger('click')

    const radios = wrapper.findAll('input[type="radio"]')
    expect(radios).toHaveLength(5)
    expect(radios.map((r) => (r.element as HTMLInputElement).value)).toEqual([...FAQ_RATE_REASONS, 'Lainnya'])

    await wrapper.get('[data-testid="faq-reason-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Pilih salah satu alasan.')
    expect(rate).not.toHaveBeenCalled()
  })

  it('alasan baku → POST rate 2 dengan teks alasan', async () => {
    rate.mockResolvedValue({ rated: true, rate: 2 })
    const wrapper = mountWidget(canRate)
    await wrapper.get('[data-testid="faq-rate-no"]').trigger('click')
    await wrapper.findAll('input[type="radio"]')[2]?.setValue(true)
    await wrapper.get('[data-testid="faq-reason-form"]').trigger('submit')
    await flushPromises()

    expect(rate).toHaveBeenCalledWith('100', { rate: 2, reason: 'Informasinya terlalu rumit.' })
    expect(wrapper.get('[data-testid="faq-rating-done"]').text()).toContain(THANKS)
    expect(wrapper.emitted('rated')?.[0]).toEqual([2])
  })

  it('Lainnya: teks kosong ditolak; teks terisi yang dikirim (bukan kata "Lainnya")', async () => {
    rate.mockResolvedValue({ rated: true, rate: 2 })
    const wrapper = mountWidget(canRate)
    await wrapper.get('[data-testid="faq-rate-no"]').trigger('click')
    await wrapper.get('[data-testid="faq-reason-other-radio"]').setValue(true)

    const other = wrapper.get('[data-testid="faq-reason-other"]')
    expect(other.attributes('maxlength')).toBe('255')

    await wrapper.get('[data-testid="faq-reason-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Alasan lainnya wajib diisi.')
    expect(rate).not.toHaveBeenCalled()

    await other.setValue('  Tautan di artikel rusak.  ')
    await wrapper.get('[data-testid="faq-reason-form"]').trigger('submit')
    await flushPromises()
    expect(rate).toHaveBeenCalledWith('100', { rate: 2, reason: 'Tautan di artikel rusak.' })
  })

  it('error "Lainnya" diberi id dan ditautkan ke inputnya (aria-describedby) hanya selama error tampil', async () => {
    rate.mockRejectedValue(apiError(500, null, 'Server bermasalah.'))
    const wrapper = mountWidget(canRate)
    await wrapper.get('[data-testid="faq-rate-no"]').trigger('click')
    await wrapper.get('[data-testid="faq-reason-other-radio"]').setValue(true)
    const other = wrapper.get('[data-testid="faq-reason-other"]')
    expect(other.attributes('aria-describedby')).toBeUndefined()

    await wrapper.get('[data-testid="faq-reason-form"]').trigger('submit')
    await flushPromises()
    const describedBy = other.attributes('aria-describedby')
    expect(describedBy).toBeTruthy()
    expect(other.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`[id="${describedBy}"]`).text()).toBe('Alasan lainnya wajib diisi.')
    // Tidak bertabrakan dengan id error pilihan alasan (dipakai aria-describedby fieldset).
    expect(wrapper.get('fieldset').attributes('aria-describedby')).toBeUndefined()

    // Teks valid → error hilang, tautan ikut dilepas (tetap di form karena kiriman gagal).
    await other.setValue('Tautan di artikel rusak.')
    await wrapper.get('[data-testid="faq-reason-form"]').trigger('submit')
    await flushPromises()
    expect(rate).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="faq-reason-other"]').attributes('aria-describedby')).toBeUndefined()
    expect(wrapper.find(`[id="${describedBy}"]`).exists()).toBe(false)
  })

  it('Batal kembali ke pertanyaan awal tanpa mengirim', async () => {
    const wrapper = mountWidget(canRate)
    await wrapper.get('[data-testid="faq-rate-no"]').trigger('click')
    const cancel = wrapper.findAll('button').find((b) => b.text() === 'Batal')
    await cancel?.trigger('click')
    expect(wrapper.find('[data-testid="faq-rate-yes"]').exists()).toBe(true)
    expect(rate).not.toHaveBeenCalled()
  })

  it('422 "sudah dinilai" dari backend → pesan backend, tombol disembunyikan', async () => {
    rate.mockRejectedValue(apiError(422, { rate: ['Artikel ini sudah Anda nilai.'] }))
    const wrapper = mountWidget(canRate)
    await wrapper.get('[data-testid="faq-rate-yes"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="faq-rating-done"]').text()).toContain('Artikel ini sudah Anda nilai.')
    expect(wrapper.find('[data-testid="faq-rate-yes"]').exists()).toBe(false)
    expect(wrapper.emitted('rated')).toBeUndefined()
  })

  it('error lain (mis. 403) → pesan error, tombol tetap ada', async () => {
    rate.mockRejectedValue(apiError(403, null, 'Anda tidak memiliki akses.'))
    const wrapper = mountWidget(canRate)
    await wrapper.get('[data-testid="faq-rate-yes"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="faq-rating-error"]').text()).toBe('Anda tidak memiliki akses.')
    expect(wrapper.find('[data-testid="faq-rate-yes"]').exists()).toBe(true)
  })
})
