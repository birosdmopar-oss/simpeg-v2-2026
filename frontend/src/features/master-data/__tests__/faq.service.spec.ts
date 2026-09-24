/**
 * G-10 — faqService: pemetaan respons kontrak API DBV-002/CR-003 §4 (id INT → string, array opsional → [],
 * rating) dan bentuk request (params search, body rating).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn() },
}))

import { api } from '@/lib/axios'

import { faqService } from '../services/faq.service'

const get = vi.mocked(api.get)
const post = vi.mocked(api.post)

beforeEach(() => {
  get.mockReset()
  post.mockReset()
})

describe('faqService', () => {
  it('tree: GET /faq → topik/sub topik/artikel dengan id string, sub topik tanpa artikel tetap ada', async () => {
    get.mockResolvedValue({
      data: {
        topics: [
          {
            id: 1,
            nama: 'Akun',
            sub_topics: [
              { id: 10, nama: 'Login', articles: [{ id: 100, title: 'Lupa password' }] },
              { id: 11, nama: 'Profil', articles: [] },
            ],
          },
          { id: '2', nama: 'Presensi', sub_topics: [] },
        ],
      },
    })

    const tree = await faqService.tree()

    expect(get).toHaveBeenCalledWith('/faq')
    expect(tree).toEqual([
      {
        id: '1',
        nama: 'Akun',
        sub_topics: [
          { id: '10', nama: 'Login', articles: [{ id: '100', title: 'Lupa password' }] },
          { id: '11', nama: 'Profil', articles: [] },
        ],
      },
      { id: '2', nama: 'Presensi', sub_topics: [] },
    ])
  })

  it('search: GET /faq?search= → hasil dengan topik, sub topik, snippet', async () => {
    get.mockResolvedValue({
      data: {
        results: [
          { id: 100, title: 'Lupa password', topic: { id: 1, nama: 'Akun' }, sub_topic: { id: 10, nama: 'Login' }, snippet: 'Buka menu Akun.' },
          { id: 101, title: 'Ganti email', topic: { id: 1, nama: 'Akun' }, sub_topic: { id: 11, nama: 'Profil' }, snippet: null },
        ],
        search: 'akun',
      },
    })

    const results = await faqService.search('akun')

    expect(get).toHaveBeenCalledWith('/faq', { params: { search: 'akun' } })
    expect(results).toEqual([
      { id: '100', title: 'Lupa password', topic: { id: '1', nama: 'Akun' }, sub_topic: { id: '10', nama: 'Login' }, snippet: 'Buka menu Akun.' },
      { id: '101', title: 'Ganti email', topic: { id: '1', nama: 'Akun' }, sub_topic: { id: '11', nama: 'Profil' }, snippet: '' },
    ])
  })

  it('article: GET /faq/{id} → detail, related, rating ternormalisasi', async () => {
    get.mockResolvedValue({
      data: {
        id: 100,
        title: 'Lupa password',
        content: '<p>Buka menu Akun.</p>',
        topic: { id: 1, nama: 'Akun' },
        sub_topic: { id: 10, nama: 'Login' },
        updated_at: '2026-09-24 03:00:00',
        related: [{ id: 99, title: 'Ganti password' }],
        rating: { can_rate: false, rated: true, rate: '2' },
      },
    })

    const detail = await faqService.article('100')

    expect(get).toHaveBeenCalledWith('/faq/100')
    expect(detail).toEqual({
      id: '100',
      title: 'Lupa password',
      content: '<p>Buka menu Akun.</p>',
      topic: { id: '1', nama: 'Akun' },
      sub_topic: { id: '10', nama: 'Login' },
      updated_at: '2026-09-24 03:00:00',
      related: [{ id: '99', title: 'Ganti password' }],
      rating: { can_rate: false, rated: true, rate: 2 },
    })
  })

  it('article: field opsional kosong → nilai aman (content "", related [], rating tidak bisa)', async () => {
    get.mockResolvedValue({
      data: { id: 5, title: 'X', content: null, topic: { id: 1, nama: 'A' }, sub_topic: { id: 2, nama: 'B' }, updated_at: null },
    })

    const detail = await faqService.article('5')

    expect(detail.content).toBe('')
    expect(detail.related).toEqual([])
    expect(detail.rating).toEqual({ can_rate: false, rated: false, rate: null })
  })

  it('rate: rate 1 tanpa reason; rate 2 mengirim reason; id di-encode', async () => {
    post.mockResolvedValueOnce({ data: { rated: true, rate: 1 } })
    await expect(faqService.rate('100', { rate: 1, reason: 'diabaikan' })).resolves.toEqual({ rated: true, rate: 1 })
    expect(post).toHaveBeenLastCalledWith('/faq/100/rate', { rate: 1 })

    post.mockResolvedValueOnce({ data: { rated: true, rate: '2' } })
    await expect(faqService.rate('100', { rate: 2, reason: 'Informasinya terlalu rumit.' })).resolves.toEqual({ rated: true, rate: 2 })
    expect(post).toHaveBeenLastCalledWith('/faq/100/rate', { rate: 2, reason: 'Informasinya terlalu rumit.' })

    post.mockResolvedValueOnce({ data: { rated: true, rate: 1 } })
    await faqService.rate('1/2', { rate: 1 })
    expect(post).toHaveBeenLastCalledWith('/faq/1%2F2/rate', { rate: 1 })
  })
})
