/**
 * Pemilih target (PRD Fase 7 v2.0): unit/satker + pegawai + pengecualian; "Seluruh kementerian" menggantikan unit lain
 * dan mematikan pilihan pegawai; chip bisa dihapus; pesan kosong hanya saat invalid.
 */
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { describe, expect, it } from 'vitest'

import TargetPicker from '../components/TargetPicker.vue'
import type { SpesifikasiTarget } from '../types'

/** UiSelect diganti stub: tombol per opsi yang memancarkan update:modelValue. */
const UiSelectStub = defineComponent({
  props: { options: { type: Array, default: () => [] }, disabled: Boolean, ariaLabel: String },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () =>
      h(
        'div',
        { 'data-stub': props.ariaLabel, 'data-disabled': String(props.disabled) },
        (props.options as { value: string; label: string }[]).map((o) =>
          h('button', { type: 'button', 'data-opt': o.value, onClick: () => emit('update:modelValue', o.value) }, o.label),
        ),
      )
  },
})

/** PencarianPegawai diganti stub: tombol per pegawai yang memancarkan pilih(nip, label). */
const PencarianStub = defineComponent({
  props: { label: String, disabled: Boolean },
  emits: ['pilih'],
  setup(props, { emit }) {
    return () =>
      h("div", { "data-stub": props.label, "data-disabled": String(props.disabled) }, [
        h('button', { type: 'button', 'data-opt': 'P1', onClick: () => emit('pilih', 'P1', 'P1 - Pegawai Satu') }, 'P1'),
        h('button', { type: 'button', 'data-opt': 'P2', onClick: () => emit('pilih', 'P2', 'P2 - Pegawai Dua') }, 'P2'),
      ])
  },
})

const unit = [
  { value: '11', label: 'Unit A' },
  { value: 'sat_5', label: 'Satker B' },
]
const labelPegawai = { P1: 'P1 - Pegawai Satu', P2: 'P2 - Pegawai Dua' }
const cariPegawai = async () => []
const kosong = (): SpesifikasiTarget => ({ unit_satker: [], pegawai: [], kecuali: [] })

function pasang(model: SpesifikasiTarget, extra: Record<string, unknown> = {}) {
  return mount(TargetPicker, {
    props: { modelValue: model, opsiUnitSatker: unit, cariPegawai, labelPegawai, ...extra },
    global: { stubs: { UiSelect: UiSelectStub, PencarianPegawai: PencarianStub } },
  })
}

const stub = (w: ReturnType<typeof pasang>, nama: string) => w.get(`[data-stub="${nama}"]`)
const terakhir = (w: ReturnType<typeof pasang>) => w.emitted('update:modelValue')!.at(-1)![0] as SpesifikasiTarget

describe('TargetPicker', () => {
  it('menambah unit/satker, pegawai, dan pengecualian tanpa duplikat', async () => {
    const w = pasang(kosong())
    await stub(w, 'Tambah unit atau satuan kerja').get('[data-opt="11"]').trigger('click')
    expect(terakhir(w)).toEqual({ unit_satker: ['11'], pegawai: [], kecuali: [] })

    await w.setProps({ modelValue: { unit_satker: ['11'], pegawai: [], kecuali: [] } })
    await stub(w, 'Tambah unit atau satuan kerja').get('[data-opt="11"]').trigger('click')
    expect(w.emitted('update:modelValue')).toHaveLength(1) // duplikat diabaikan

    await stub(w, 'Cari pegawai target').get('[data-opt="P1"]').trigger('click')
    expect(terakhir(w).pegawai).toEqual(['P1'])
    await stub(w, 'Cari pegawai yang dikecualikan').get('[data-opt="P2"]').trigger('click')
    expect(terakhir(w).kecuali).toEqual(['P2'])
  })

  it('opsi "Seluruh kementerian" otomatis tersedia; memilihnya menggantikan unit lain dan mengosongkan pegawai', async () => {
    const w = pasang({ unit_satker: ['11', 'sat_5'], pegawai: ['P1'], kecuali: ['P2'] })
    await stub(w, 'Tambah unit atau satuan kerja').get('[data-opt="0"]').trigger('click')
    expect(terakhir(w)).toEqual({ unit_satker: ['0'], pegawai: [], kecuali: ['P2'] })
  })

  it('target seluruh kementerian: pilihan pegawai dimatikan dengan keterangan; unit lain tidak bisa ditambah', async () => {
    const w = pasang({ unit_satker: ['0'], pegawai: [], kecuali: [] })
    expect(stub(w, 'Cari pegawai target').attributes('data-disabled')).toBe('true')
    expect(w.find('[data-testid="target-pegawai-nonaktif"]').exists()).toBe(true)
    expect(w.get('[data-testid="target-unit-daftar"]').text()).toContain('Seluruh kementerian')
    await stub(w, 'Tambah unit atau satuan kerja').get('[data-opt="11"]').trigger('click')
    expect(w.emitted('update:modelValue')).toBeUndefined()
  })

  it('chip menampilkan label dan tombol hapus ber-aria-label menghapus butirnya', async () => {
    const w = pasang({ unit_satker: ['11'], pegawai: ['P1'], kecuali: ['P2'] })
    const hapusUnit = w.get('[aria-label="Hapus Unit A"]')
    await hapusUnit.trigger('click')
    expect(terakhir(w).unit_satker).toEqual([])
    await w.get('[aria-label="Hapus P1 - Pegawai Satu"]').trigger('click')
    expect(terakhir(w).pegawai).toEqual([])
    await w.get('[aria-label="Hapus pengecualian P2 - Pegawai Dua"]').trigger('click')
    expect(terakhir(w).kecuali).toEqual([])
  })

  it('pesan kosong hanya tampil bila invalid dan belum ada target', async () => {
    expect(pasang(kosong()).find('[data-testid="target-kosong"]').exists()).toBe(false)
    const w = pasang(kosong(), { invalid: true })
    expect(w.get('[data-testid="target-kosong"]').attributes('role')).toBe('alert')
    const ada = pasang({ unit_satker: ['11'], pegawai: [], kecuali: [] }, { invalid: true })
    expect(ada.find('[data-testid="target-kosong"]').exists()).toBe(false)
  })

  it('disabled mematikan semua pilihan dan tombol hapus', () => {
    const w = pasang({ unit_satker: ['11'], pegawai: [], kecuali: [] }, { disabled: true })
    expect(stub(w, 'Tambah unit atau satuan kerja').attributes('data-disabled')).toBe('true')
    expect(w.get('[data-testid="target-unit-hapus"]').attributes('disabled')).toBeDefined()
  })
})
