/**
 * Komponen design system redesign (Laporan Redesign bab 3 + pola bab 4): perilaku, state, dan aksesibilitasnya.
 * Tampilan piksel dicek di browser terhadap mockup; test ini mengunci kontrak (props, event, atribut ARIA, state).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import {
  UiAvatar,
  UiBadge,
  UiButton,
  UiCard,
  UiCheckbox,
  UiDonutChart,
  UiNotice,
  UiPagination,
  UiProgressBar,
  UiRadioGroup,
  UiSearchInput,
  UiSelect,
  UiSparkline,
  UiStatTile,
  UiStepper,
  UiTabs,
  UiTextField,
} from '..'

describe('UiButton', () => {
  it('bawaan: Primary solid biru #217AFF (bukan navy), type="button"', () => {
    const w = mount(UiButton, { slots: { default: 'Simpan' } })
    expect(w.element.tagName).toBe('BUTTON')
    expect(w.attributes('type')).toBe('button')
    expect(w.classes()).toContain('bg-brand-tertiary')
    expect(w.text()).toBe('Simpan')
  })

  it.each([
    ['solid', 'error', 'bg-danger'],
    ['outline', 'success', 'border-success'],
    ['ghost', 'warning', 'text-warning'],
    ['soft', 'secondary', 'bg-slate-100'],
  ] as const)('appearance %s + variant %s → kelas %s', (appearance, variant, cls) => {
    expect(mount(UiButton, { props: { appearance, variant } }).classes()).toContain(cls)
  })

  it('disabled & loading menonaktifkan tombol; loading memberi aria-busy dan spinner', () => {
    expect(mount(UiButton, { props: { disabled: true } }).attributes('disabled')).toBeDefined()
    const loading = mount(UiButton, { props: { loading: true } })
    expect(loading.attributes('disabled')).toBeDefined()
    expect(loading.attributes('aria-busy')).toBe('true')
    expect(loading.find('svg.animate-spin').exists()).toBe(true)
  })

  it('`href` merender <a>; `to` merender RouterLink; keduanya jatuh ke <button> saat disabled', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/x', name: 'x', component: { render: () => null } }] })
    await router.push('/x')

    expect(mount(UiButton, { props: { href: '/luar' } }).element.tagName).toBe('A')
    const link = mount(UiButton, { props: { to: { name: 'x' } }, global: { plugins: [router] } })
    expect(link.attributes('href')).toBe('/x')
    expect(mount(UiButton, { props: { to: { name: 'x' }, disabled: true }, global: { plugins: [router] } }).element.tagName).toBe('BUTTON')
  })

  it('block melebar penuh', () => {
    expect(mount(UiButton, { props: { block: true } }).classes()).toContain('w-full')
  })
})

describe('UiTextField', () => {
  it('label terhubung ke input (for/id), tanda wajib, dan emit update:modelValue', async () => {
    const w = mount(UiTextField, { props: { label: 'Nama', required: true, modelValue: '' } })
    const label = w.get('label')
    const input = w.get('input')
    expect(label.attributes('for')).toBe(input.attributes('id'))
    expect(label.text()).toContain('*')

    await input.setValue('Budi')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['Budi'])
  })

  it('state error: border & label merah, aria-invalid, teks bantu ter-refer lewat aria-describedby', () => {
    const w = mount(UiTextField, { props: { label: 'NIK', state: 'error', helpText: 'NIK harus 16 digit' } })
    const input = w.get('input')
    expect(input.classes()).toContain('border-danger')
    expect(w.get('label').classes()).toContain('text-danger')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(w.get(`#${input.attributes('aria-describedby')}`).text()).toBe('NIK harus 16 digit')
  })

  it('state success hijau; disabled abu + tidak bisa diisi; inputmode diteruskan ke <input>', () => {
    expect(mount(UiTextField, { props: { state: 'success' } }).get('input').classes()).toContain('border-success')
    const disabled = mount(UiTextField, { props: { disabled: true, inputmode: 'numeric' } }).get('input')
    expect(disabled.attributes('disabled')).toBeDefined()
    expect(disabled.attributes('inputmode')).toBe('numeric')
  })

  it('tampilan outlined menaruh label pada takik garis tepi (absolute)', () => {
    const w = mount(UiTextField, { props: { label: 'Label', appearance: 'outlined' } })
    expect(w.get('label').classes()).toContain('absolute')
  })
})

describe('UiSelect', () => {
  const options = [{ value: 'a', label: 'Opsi A' }]

  it('opsi placeholder terkunci kecuali clearable (filter "Semua ...")', () => {
    expect(mount(UiSelect, { props: { options } }).get('option').attributes('disabled')).toBeDefined()
    expect(mount(UiSelect, { props: { options, clearable: true } }).get('option').attributes('disabled')).toBeUndefined()
  })

  it('emit nilai terpilih; label ada → aria-label tidak dipasang; tanpa label memakai ariaLabel', async () => {
    const w = mount(UiSelect, { props: { options, label: 'Agama' } })
    await w.get('select').setValue('a')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['a'])
    expect(w.get('select').attributes('aria-label')).toBeUndefined()

    expect(mount(UiSelect, { props: { options, ariaLabel: 'Jumlah baris' } }).get('select').attributes('aria-label')).toBe('Jumlah baris')
  })

  it('state error memerahkan border dan menampilkan teks bantu', () => {
    const w = mount(UiSelect, { props: { options, state: 'error', helpText: 'Wajib dipilih' } })
    expect(w.get('select').classes()).toContain('border-danger')
    expect(w.text()).toContain('Wajib dipilih')
  })
})

describe('UiCheckbox & UiRadioGroup', () => {
  it('checkbox: emit boolean; disabled tidak bisa diubah', async () => {
    const w = mount(UiCheckbox, { props: { label: 'Setuju', modelValue: false } })
    const input = w.get('input')
    input.element.checked = true
    await input.trigger('change')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([true])
    expect(mount(UiCheckbox, { props: { disabled: true } }).get('input').attributes('disabled')).toBeDefined()
  })

  it('checkbox tercentang menampilkan tanda centang (svg)', () => {
    expect(mount(UiCheckbox, { props: { modelValue: true } }).find('svg').exists()).toBe(true)
    expect(mount(UiCheckbox, { props: { modelValue: false } }).find('svg').exists()).toBe(false)
  })

  it('radio: fieldset + legend, satu terpilih, emit nilai, tanda wajib', async () => {
    const w = mount(UiRadioGroup, {
      props: { label: 'Jenis Kelamin', required: true, modelValue: 'L', options: [{ value: 'L', label: 'Laki-Laki' }, { value: 'P', label: 'Perempuan' }] },
    })
    expect(w.get('legend').text()).toContain('Jenis Kelamin')
    expect(w.get('legend').text()).toContain('*')
    const radios = w.findAll('input[type="radio"]')
    expect(radios.map((r) => (r.element as HTMLInputElement).checked)).toEqual([true, false])

    await radios[1].trigger('change')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['P'])
  })

  it('radio disabled menonaktifkan semua pilihan', () => {
    const w = mount(UiRadioGroup, { props: { disabled: true, options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] } })
    expect(w.findAll('input').every((i) => i.attributes('disabled') !== undefined)).toBe(true)
  })
})

describe('UiAvatar', () => {
  it('tanpa gambar → inisial (maks 2 huruf) dan role=img berlabel nama', () => {
    const w = mount(UiAvatar, { props: { name: 'Agung Dwi Putro' } })
    expect(w.text()).toBe('AD')
    expect(w.get('[role="img"]').attributes('aria-label')).toBe('Agung Dwi Putro')
  })

  it('gelar (Dr., S.Kom) & tanda baca tidak dihitung sebagai inisial; nama kosong → "?"', () => {
    expect(mount(UiAvatar, { props: { name: '(Dr.) Budi' } }).text()).toBe('B')
    expect(mount(UiAvatar, { props: { name: 'Dr. Budi Santoso, M.Si' } }).text()).toBe('BS')
    expect(mount(UiAvatar, { props: { name: 'Administrator' } }).text()).toBe('A')
    expect(mount(UiAvatar, { props: { name: '' } }).text()).toBe('?')
  })

  it('gambar gagal dimuat → jatuh ke inisial (tidak ada kotak rusak); src baru mencoba lagi', async () => {
    const w = mount(UiAvatar, { props: { name: 'Siti Aminah', src: 'https://contoh.test/x.png' } })
    expect(w.find('img').exists()).toBe(true)
    await w.get('img').trigger('error')
    expect(w.find('img').exists()).toBe(false)
    expect(w.text()).toBe('SA')

    await w.setProps({ src: 'https://contoh.test/y.png' })
    expect(w.find('img').exists()).toBe(true)
  })

  it('titik status hijau/merah dan bentuk rounded', () => {
    expect(mount(UiAvatar, { props: { name: 'A', status: 'online' } }).get('[title="Aktif"]').classes()).toContain('bg-success')
    expect(mount(UiAvatar, { props: { name: 'A', status: 'offline' } }).get('[title="Tidak aktif"]').classes()).toContain('bg-danger')
    expect(mount(UiAvatar, { props: { name: 'A', shape: 'rounded' } }).get('[role="img"]').classes()).toContain('rounded-2xl')
  })
})

describe('UiPagination', () => {
  const mountPg = (page: number, total = 50, perPage = 10) => mount(UiPagination, { props: { page, perPage, total } })

  it('info "Showing a to b of n entries" (halaman terakhir terpotong, kosong → 0)', () => {
    expect(mountPg(3).text()).toContain('Showing 21 to 30 of 50 entries')
    expect(mountPg(6, 57).text()).toContain('Showing 51 to 57 of 57 entries')
    expect(mountPg(1, 0).text()).toContain('Showing 0 to 0 of 0 entries')
  })

  it('halaman aktif aria-current; halaman berikutnya bertanda biru muda', () => {
    const w = mountPg(3)
    const buttons = w.findAll('nav button').filter((b) => /^\d+$/.test(b.text()))
    const active = buttons.find((b) => b.attributes('aria-current') === 'page')
    expect(active?.text()).toBe('3')
    expect(buttons.find((b) => b.text() === '4')?.classes()).toContain('bg-brand-tertiary/15')
  })

  it('tombol sebelumnya/berikutnya nonaktif di tepi; klik mengirim halaman', async () => {
    const first = mountPg(1)
    expect(first.get('button[aria-label="Halaman sebelumnya"]').attributes('disabled')).toBeDefined()
    const last = mountPg(5)
    expect(last.get('button[aria-label="Halaman berikutnya"]').attributes('disabled')).toBeDefined()

    const w = mountPg(2)
    await w.get('button[aria-label="Halaman berikutnya"]').trigger('click')
    expect(w.emitted('update:page')?.[0]).toEqual([3])
  })

  it('klik halaman yang sedang aktif tidak memancarkan event', async () => {
    const w = mountPg(3)
    await w.findAll('nav button').find((b) => b.attributes('aria-current') === 'page')?.trigger('click')
    expect(w.emitted('update:page')).toBeUndefined()
  })
})

describe('UiProgressBar, UiDonutChart, UiSparkline', () => {
  it('progress: nilai dijepit 0–100 dan memakai atribut ARIA; max ≤ 0 → 0', () => {
    expect(mount(UiProgressBar, { props: { value: 150, max: 100 } }).attributes('aria-valuenow')).toBe('100')
    expect(mount(UiProgressBar, { props: { value: -5 } }).attributes('aria-valuenow')).toBe('0')
    expect(mount(UiProgressBar, { props: { value: 50, max: 0 } }).attributes('aria-valuenow')).toBe('0')
    expect(mount(UiProgressBar, { props: { value: 25, max: 50 } }).get('[role="progressbar"] > div').attributes('style')).toContain('width: 50%')
  })

  it('donat: tabel sr-only memuat jumlah & persentase tiap potongan; legenda tampil', () => {
    const w = mount(UiDonutChart, {
      props: { caption: 'Komposisi', slices: [{ label: 'PNS', value: 75, color: '#1C3964' }, { label: 'PPPK', value: 25, color: '#217AFF' }] },
    })
    const table = w.get('table.sr-only')
    expect(table.text()).toContain('PNS')
    expect(table.text()).toContain('75.0%')
    expect(table.text()).toContain('25.0%')
    expect(w.findAll('circle')).toHaveLength(3) // latar + 2 potongan
    expect(w.get('svg').attributes('aria-label')).toBe('Komposisi')
  })

  it('donat kosong (total 0) tidak menggambar potongan dan persentasenya 0%', () => {
    const w = mount(UiDonutChart, { props: { slices: [{ label: 'X', value: 0, color: '#000' }], legend: 'none' } })
    expect(w.findAll('circle')).toHaveLength(1)
    expect(w.get('table').text()).toContain('0%')
    expect(w.find('ul').exists()).toBe(false)
  })

  it('sparkline: path digambar untuk ≥2 titik, kosong untuk <2; dekoratif (aria-hidden)', () => {
    const ok = mount(UiSparkline, { props: { points: [1, 3, 2, 5] } })
    expect(ok.get('svg').attributes('aria-hidden')).toBe('true')
    expect(ok.findAll('path')[1].attributes('d')).toMatch(/^M /)
    expect(mount(UiSparkline, { props: { points: [1] } }).findAll('path')[1].attributes('d')).toBe('')
  })
})

describe('UiTabs', () => {
  const items = [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }, { value: 'c', label: 'C' }]

  it('WAI-ARIA tablist: hanya tab aktif yang bisa difokus (roving tabindex)', () => {
    const w = mount(UiTabs, { props: { modelValue: 'b', items, ariaLabel: 'Uji' } })
    expect(w.get('[role="tablist"]').attributes('aria-label')).toBe('Uji')
    expect(w.findAll('[role="tab"]').map((t) => t.attributes('tabindex'))).toEqual(['-1', '0', '-1'])
    expect(w.findAll('[role="tab"]').map((t) => t.attributes('aria-selected'))).toEqual(['false', 'true', 'false'])
  })

  it('panah kanan/kiri berpindah tab (melingkar); klik memilih', async () => {
    const w = mount(UiTabs, { props: { modelValue: 'c', items }, attachTo: document.body })
    await w.get('[role="tablist"]').trigger('keydown', { key: 'ArrowRight' })
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['a'])
    await w.get('[role="tablist"]').trigger('keydown', { key: 'ArrowLeft' })
    expect(w.emitted('update:modelValue')?.[1]).toEqual(['b'])

    await w.findAll('[role="tab"]')[0].trigger('click')
    expect(w.emitted('update:modelValue')?.[2]).toEqual(['a'])
    w.unmount()
  })

  it('varian pill: tab aktif kuning #FFC043; underline: garis biru', () => {
    expect(mount(UiTabs, { props: { modelValue: 'a', items, variant: 'pill' } }).findAll('[role="tab"]')[0].classes()).toContain('bg-brand-secondary')
    expect(mount(UiTabs, { props: { modelValue: 'a', items } }).findAll('[role="tab"]')[0].classes()).toContain('border-brand-tertiary')
  })
})

describe('UiStepper, UiStatTile, UiBadge, UiCard, UiNotice, UiSearchInput', () => {
  it('stepper: langkah selesai bercentang, berjalan bercincin, belum pudar; garis ke langkah belum = abu', () => {
    const w = mount(UiStepper, { props: { steps: [{ label: 'Verifikasi', state: 'done' }, { label: 'Review', state: 'current' }, { label: 'SK', state: 'todo' }] } })
    expect(w.findAll('li')).toHaveLength(3)
    expect(w.findAll('svg')).toHaveLength(1) // hanya "done" yang bercentang
    const lines = w.findAll('li > span[aria-hidden="true"]')
    expect(lines[0].classes()).toContain('bg-info')
    expect(lines[1].classes()).toContain('bg-slate-200')
  })

  it('stat tile: sparkline hanya bila tren ≥2 titik; layout split menaruh label di atas nilai', () => {
    expect(mount(UiStatTile, { props: { label: 'Pensiun', value: 15 } }).find('svg').exists()).toBe(false)
    expect(mount(UiStatTile, { props: { label: 'Pensiun', value: 15, trend: [1, 2, 3] } }).find('svg').exists()).toBe(true)
    const split = mount(UiStatTile, { props: { label: 'Naik Pangkat', value: '0/100', layout: 'split', outlined: true, tone: 'danger' } })
    expect(split.classes()).toContain('border-danger/35')
    expect(split.text()).toContain('0/100')
  })

  it('badge: nada dan titik', () => {
    const w = mount(UiBadge, { props: { tone: 'success', dot: true }, slots: { default: 'Aktif' } })
    expect(w.classes()).toContain('bg-success-soft')
    expect(w.find('span[aria-hidden="true"]').exists()).toBe(true)
  })

  it('card: judul, subjudul, slot aksi & footer; tanpa header bila tak ada judul/slot', () => {
    const w = mount(UiCard, { props: { title: 'Judul', subtitle: 'Sub' }, slots: { actions: '<button>Aksi</button>', footer: 'Kaki', default: 'Isi' } })
    expect(w.get('h2').text()).toBe('Judul')
    expect(w.text()).toContain('Sub')
    expect(w.text()).toContain('Aksi')
    expect(w.text()).toContain('Kaki')
    expect(mount(UiCard, { slots: { default: 'Isi' } }).find('header').exists()).toBe(false)
  })

  it('notice: info/sukses role=status, peringatan/bahaya role=alert; tombol tutup hanya bila dismissible', async () => {
    expect(mount(UiNotice, { props: { tone: 'info' } }).attributes('role')).toBe('status')
    expect(mount(UiNotice, { props: { tone: 'success' } }).attributes('role')).toBe('status')
    expect(mount(UiNotice, { props: { tone: 'warning' } }).attributes('role')).toBe('alert')
    expect(mount(UiNotice, { props: { tone: 'danger' } }).attributes('role')).toBe('alert')

    expect(mount(UiNotice).find('button').exists()).toBe(false)
    const w = mount(UiNotice, { props: { dismissible: true } })
    await w.get('button[aria-label="Tutup pesan"]').trigger('click')
    expect(w.emitted('dismiss')).toHaveLength(1)
  })

  it('search input: label sr-only, emit nilai, Enter memicu submit', async () => {
    const w = mount(UiSearchInput, { props: { label: 'Cari berita', modelValue: '' } })
    expect(w.get('label').classes()).toContain('sr-only')
    const input = w.get('input')
    await input.setValue('kebijakan')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['kebijakan'])
    await input.trigger('keyup.enter')
    await flushPromises()
    expect(w.emitted('submit')).toHaveLength(1)
  })
})
