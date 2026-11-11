<script setup lang="ts">
/**
 * Pemilih target popup/banner/form digital (PRD Fase 7 v2.0 §5.3, Q7-15; aturan legacy `L_announcement.php:447-620`):
 * unit/satker (nilai "0" = seluruh kementerian, "<id_unit>", "sat_<id_satker>") + pegawai tertentu + pegawai yang dikecualikan.
 * Memilih "Seluruh kementerian" menggantikan pilihan unit/satker lain dan mematikan pilihan pegawai (legacy mengabaikan
 * target pegawai bila seluruh kementerian). Komponen murni tampilan: pemanggil memasok opsi; nilai `{unit_satker, pegawai, kecuali}`.
 */
import { X } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import type { SelectOption } from '@/shared/ui'
import { UiSelect } from '@/shared/ui'

import { SEMUA_KEMENTERIAN } from '../target'
import type { SpesifikasiTarget } from '../types'
import PencarianPegawai, { type HasilPegawai } from './PencarianPegawai.vue'

const props = withDefaults(
  defineProps<{
    modelValue: SpesifikasiTarget
    opsiUnitSatker: SelectOption[]
    /** Pencarian pegawai sisi-server (min. 3 huruf); hasil {value: NIP, label}. */
    cariPegawai: (kata: string) => Promise<HasilPegawai[]>
    /** Label "NIP - nama" untuk NIP pada target tersimpan (form ubah). */
    labelPegawai?: Record<string, string>
    disabled?: boolean
    invalid?: boolean
    pesanKosong?: string
  }>(),
  { disabled: false, invalid: false, pesanKosong: 'Pilih minimal satu target (unit/satker atau pegawai).' },
)

const emit = defineEmits<{ 'update:modelValue': [value: SpesifikasiTarget] }>()

const semua = computed(() => props.modelValue.unit_satker.includes(SEMUA_KEMENTERIAN))
const kosong = computed(() => props.modelValue.unit_satker.length === 0 && props.modelValue.pegawai.length === 0)

const unitLabel = computed(() => {
  const m = new Map<string, string>()
  for (const o of props.opsiUnitSatker) m.set(String(o.value), o.label)
  m.set(SEMUA_KEMENTERIAN, m.get(SEMUA_KEMENTERIAN) ?? 'Seluruh kementerian')
  return m
})
/** Label pegawai: dari target tersimpan (`labelPegawai`) dan dari pilihan di sesi ini; NIP saja bila tak dikenal. */
const labelDipilih = ref<Record<string, string>>({})
const pegawaiLabel = computed(() => new Map(Object.entries({ ...props.labelPegawai, ...labelDipilih.value })))

function set(patch: Partial<SpesifikasiTarget>): void {
  emit('update:modelValue', { ...props.modelValue, ...patch })
}

function tambahUnit(v: string | number | null): void {
  const nilai = String(v ?? '')
  if (nilai === '') return
  if (nilai === SEMUA_KEMENTERIAN) return set({ unit_satker: [SEMUA_KEMENTERIAN], pegawai: [] })
  if (semua.value || props.modelValue.unit_satker.includes(nilai)) return
  set({ unit_satker: [...props.modelValue.unit_satker, nilai] })
}

function tambahPegawai(kunci: 'pegawai' | 'kecuali', nilai: string, label: string): void {
  if (nilai === '' || props.modelValue[kunci].includes(nilai)) return
  labelDipilih.value = { ...labelDipilih.value, [nilai]: label }
  set({ [kunci]: [...props.modelValue[kunci], nilai] })
}

function hapus(kunci: keyof SpesifikasiTarget, nilai: string): void {
  set({ [kunci]: props.modelValue[kunci].filter((x) => x !== nilai) })
}

const opsiUnit = computed<SelectOption[]>(() =>
  props.opsiUnitSatker.some((o) => String(o.value) === SEMUA_KEMENTERIAN)
    ? props.opsiUnitSatker
    : [{ value: SEMUA_KEMENTERIAN, label: 'Seluruh kementerian' }, ...props.opsiUnitSatker],
)
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="target-picker">
    <section aria-labelledby="tp-unit">
      <h3 id="tp-unit" class="text-body1 font-medium text-slate-700">Unit / satuan kerja</h3>
      <UiSelect
        model-value=""
        searchable
        label=""
        aria-label="Tambah unit atau satuan kerja"
        placeholder="Tambah unit / satker…"
        :options="opsiUnit"
        :disabled="disabled"
        @update:model-value="tambahUnit"
      />
      <ul class="mt-2 flex flex-wrap gap-2" data-testid="target-unit-daftar">
        <li
          v-for="u in modelValue.unit_satker"
          :key="u"
          class="inline-flex items-center gap-1 rounded-full bg-slate-100 py-1 pl-3 pr-1 text-body2 text-slate-800"
        >
          {{ unitLabel.get(u) ?? u }}
          <button
            type="button"
            class="inline-flex h-5 w-5 items-center justify-center rounded-full hover:bg-slate-200"
            :aria-label="`Hapus ${unitLabel.get(u) ?? u}`"
            :disabled="disabled"
            data-testid="target-unit-hapus"
            @click="hapus('unit_satker', u)"
          >
            <X class="h-3 w-3" aria-hidden="true" />
          </button>
        </li>
      </ul>
    </section>

    <section aria-labelledby="tp-pegawai">
      <h3 id="tp-pegawai" class="text-body1 font-medium text-slate-700">Pegawai tertentu</h3>
      <PencarianPegawai
        label="Cari pegawai target"
        :cari="cariPegawai"
        :disabled="disabled || semua"
        @pilih="(nip, label) => tambahPegawai('pegawai', nip, label)"
      />
      <p v-if="semua" class="mt-1 text-body2 text-slate-500" data-testid="target-pegawai-nonaktif">
        Tidak dipakai karena target seluruh kementerian.
      </p>
      <ul class="mt-2 flex flex-wrap gap-2" data-testid="target-pegawai-daftar">
        <li
          v-for="n in modelValue.pegawai"
          :key="n"
          class="inline-flex items-center gap-1 rounded-full bg-slate-100 py-1 pl-3 pr-1 text-body2 text-slate-800"
        >
          {{ pegawaiLabel.get(n) ?? n }}
          <button
            type="button"
            class="inline-flex h-5 w-5 items-center justify-center rounded-full hover:bg-slate-200"
            :aria-label="`Hapus ${pegawaiLabel.get(n) ?? n}`"
            :disabled="disabled"
            data-testid="target-pegawai-hapus"
            @click="hapus('pegawai', n)"
          >
            <X class="h-3 w-3" aria-hidden="true" />
          </button>
        </li>
      </ul>
    </section>

    <section aria-labelledby="tp-kecuali">
      <h3 id="tp-kecuali" class="text-body1 font-medium text-slate-700">Kecualikan pegawai</h3>
      <PencarianPegawai
        label="Cari pegawai yang dikecualikan"
        placeholder="Cari pegawai yang dikecualikan (min. 3 huruf)…"
        :cari="cariPegawai"
        :disabled="disabled"
        @pilih="(nip, label) => tambahPegawai('kecuali', nip, label)"
      />
      <ul class="mt-2 flex flex-wrap gap-2" data-testid="target-kecuali-daftar">
        <li
          v-for="n in modelValue.kecuali"
          :key="n"
          class="inline-flex items-center gap-1 rounded-full bg-amber-50 py-1 pl-3 pr-1 text-body2 text-slate-800"
        >
          {{ pegawaiLabel.get(n) ?? n }}
          <button
            type="button"
            class="inline-flex h-5 w-5 items-center justify-center rounded-full hover:bg-amber-100"
            :aria-label="`Hapus pengecualian ${pegawaiLabel.get(n) ?? n}`"
            :disabled="disabled"
            data-testid="target-kecuali-hapus"
            @click="hapus('kecuali', n)"
          >
            <X class="h-3 w-3" aria-hidden="true" />
          </button>
        </li>
      </ul>
    </section>

    <p v-if="invalid && kosong" role="alert" class="text-body2 text-danger" data-testid="target-kosong">{{ pesanKosong }}</p>
  </div>
</template>
