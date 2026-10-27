<script setup lang="ts">
/**
 * Input tanggal custom (AGENTS.md bagian 3, CR-060) — pengganti `<input type="date">` bawaan browser. Hanya KONTROL
 * (kolom ketik + tombol kalender + popup kalender); label/help/state dipasang pembungkusnya (UiTextField type="date").
 *
 * Nilai tetap string `YYYY-MM-DD` ('' = kosong), jadi skema/zod dan API tidak berubah. Tampilan `dd/mm/yyyy`.
 * Mengetik manual: `dd/mm/yyyy`, `dd-mm-yyyy`, `dd.mm.yyyy`, `ddmmyyyy`, atau `yyyy-mm-dd`; nilai terkirim begitu
 * lengkap dan sah, dikoreksi/ditolak saat blur (format salah atau di luar min/max → nilai dikosongkan + pesan lewat
 * event `invalid`). Zona waktu: semua komponen kalender lokal, tanpa toISOString().
 *
 * Popup kalender (radix-vue Popover, aman di dalam dialog): grid bulan Senin–Minggu, navigasi bulan/tahun, hari
 * di luar min/max ditandai `aria-disabled`. Keyboard di grid: ←/→ ±1 hari, ↑/↓ ±1 pekan, Home/End awal/akhir pekan,
 * PageUp/PageDown ±1 bulan (Shift ±1 tahun), Enter/Spasi memilih, Esc menutup dan mengembalikan fokus ke kolom.
 */
import { CalendarDays, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-vue-next'
import { PopoverAnchor, PopoverContent, PopoverPortal, PopoverRoot } from 'radix-vue'
import { computed, nextTick, ref, watch } from 'vue'

import {
  addDays,
  addMonths,
  compareYmd,
  formatDisplay,
  formatLong,
  monthMatrix,
  MONTH_NAMES,
  parseIso,
  parseTyped,
  toIso,
  today,
  weekdayMon0,
  WEEKDAYS_LONG,
  WEEKDAYS_SHORT,
  type YMD,
} from './dateUtils'

const props = withDefaults(
  defineProps<{
    modelValue?: string | null
    id?: string
    name?: string
    placeholder?: string
    disabled?: boolean
    readonly?: boolean
    invalid?: boolean
    describedby?: string
    required?: boolean
    /** Batas bawah/atas `YYYY-MM-DD` (inklusif). */
    min?: string
    max?: string
    controlClass?: string | unknown[]
    ariaLabel?: string
  }>(),
  {
    modelValue: '',
    id: undefined,
    name: undefined,
    placeholder: 'hh/bb/tttt',
    disabled: false,
    readonly: false,
    invalid: false,
    describedby: undefined,
    required: false,
    min: undefined,
    max: undefined,
    controlClass: '',
    ariaLabel: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: []; focus: []; invalid: [message: string] }>()

const input = ref<HTMLInputElement | null>(null)
const content = ref<HTMLElement | null>(null)
const open = ref(false)
const draft = ref(formatDisplay(props.modelValue ?? ''))
const cursor = ref<YMD>(today())
const view = ref({ y: cursor.value.y, m: cursor.value.m })
let lastEmitted = props.modelValue ?? ''

watch(
  () => props.modelValue,
  (value) => {
    if ((value ?? '') !== lastEmitted) {
      lastEmitted = value ?? ''
      draft.value = formatDisplay(lastEmitted)
    }
  },
)

const minYmd = computed(() => parseIso(props.min ?? ''))
const maxYmd = computed(() => parseIso(props.max ?? ''))
const selected = computed(() => parseIso(props.modelValue ?? ''))
const now = today()

function inRange(p: YMD): boolean {
  return (!minYmd.value || compareYmd(p, minYmd.value) >= 0) && (!maxYmd.value || compareYmd(p, maxYmd.value) <= 0)
}

function setValue(value: string): void {
  lastEmitted = value
  emit('update:modelValue', value)
}

function onInput(e: Event): void {
  draft.value = (e.target as HTMLInputElement).value
  emit('invalid', '')
  if (draft.value.trim() === '') return setValue('')
  const parsed = parseTyped(draft.value)
  if (parsed && inRange(parsed)) setValue(toIso(parsed))
}

/** Rapikan teks: sah → `dd/mm/yyyy`; kosong → kosong; salah/di luar rentang → nilai dikosongkan + pesan. */
function commit(): void {
  const text = draft.value.trim()
  if (text === '') {
    if (lastEmitted !== '') setValue('')
    return
  }
  const parsed = parseTyped(text)
  if (parsed && inRange(parsed)) {
    const iso = toIso(parsed)
    if (iso !== lastEmitted) setValue(iso)
    draft.value = formatDisplay(iso)
    return
  }
  if (lastEmitted !== '') setValue('')
  emit('invalid', parsed ? 'Tanggal di luar rentang yang diizinkan.' : 'Format tanggal tidak valid. Gunakan hh/bb/tttt.')
}

function onBlur(): void {
  if (!open.value) commit()
  emit('blur')
}

function openCalendar(): void {
  if (props.disabled || props.readonly || open.value) return
  const base = selected.value ?? parseTyped(draft.value) ?? now
  cursor.value = base
  view.value = { y: base.y, m: base.m }
  open.value = true
}

function closeCalendar(refocus = true): void {
  if (!open.value) return
  open.value = false
  if (refocus) void nextTick(() => input.value?.focus())
}

function pick(p: YMD): void {
  if (!inRange(p)) return
  const iso = toIso(p)
  setValue(iso)
  draft.value = formatDisplay(iso)
  emit('invalid', '')
  closeCalendar()
}

function focusCursor(): void {
  void nextTick(() => content.value?.querySelector<HTMLElement>(`[data-iso="${toIso(cursor.value)}"]`)?.focus())
}

function moveCursor(next: YMD): void {
  cursor.value = next
  view.value = { y: next.y, m: next.m }
  focusCursor()
}

function onGridKeydown(e: KeyboardEvent): void {
  const c = cursor.value
  const step: Record<string, YMD | undefined> = {
    ArrowLeft: addDays(c, -1),
    ArrowRight: addDays(c, 1),
    ArrowUp: addDays(c, -7),
    ArrowDown: addDays(c, 7),
    Home: addDays(c, -weekdayMon0(c)),
    End: addDays(c, 6 - weekdayMon0(c)),
    PageUp: addMonths(c, e.shiftKey ? -12 : -1),
    PageDown: addMonths(c, e.shiftKey ? 12 : 1),
  }
  const next = step[e.key]
  if (next) {
    e.preventDefault()
    moveCursor(next)
  } else if (e.key === 'Escape') {
    e.preventDefault()
    e.stopPropagation() // Esc menutup kalender, bukan dialog di belakangnya
    closeCalendar()
  }
}

function shiftView(months: number): void {
  moveCursor(addMonths(cursor.value, months))
}

function onInputKeydown(e: KeyboardEvent): void {
  if (e.key === 'Enter') {
    commit()
  } else if (e.key === 'ArrowDown' && (e.altKey || !draft.value)) {
    e.preventDefault()
    openCalendar()
  } else if (e.key === 'Escape' && open.value) {
    e.stopPropagation()
    closeCalendar()
  }
}

function onPointerDownOutside(e: CustomEvent<{ originalEvent: PointerEvent }>): void {
  const target = e.detail.originalEvent.target as Node | null
  if (target && (input.value?.parentElement?.contains(target) ?? false)) e.preventDefault()
}

const navBtn = 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary'
const footBtn =
  'rounded-lg px-2.5 py-1 text-body2 font-medium text-brand-tertiary transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent'

const weeks = computed(() => monthMatrix(view.value.y, view.value.m))
const title = computed(() => `${MONTH_NAMES[view.value.m - 1]} ${view.value.y}`)
const same = (a: YMD | null, b: YMD): boolean => a !== null && compareYmd(a, b) === 0

function pickToday(): void {
  if (inRange(now)) pick(now)
}

function clear(): void {
  setValue('')
  draft.value = ''
  emit('invalid', '')
  closeCalendar()
}
</script>

<template>
  <PopoverRoot :open="open" :modal="false" @update:open="(v: boolean) => (v ? openCalendar() : closeCalendar(false))">
    <PopoverAnchor as-child>
      <div class="relative">
        <input
          :id="id"
          ref="input"
          type="text"
          inputmode="numeric"
          autocomplete="off"
          :name="name"
          :value="draft"
          :placeholder="placeholder"
          :disabled="disabled"
          :readonly="readonly"
          :class="[controlClass, 'pr-11']"
          :aria-required="required || undefined"
          :aria-invalid="invalid || undefined"
          :aria-describedby="describedby"
          :aria-label="ariaLabel"
          aria-haspopup="dialog"
          :aria-expanded="open"
          data-testid="ui-date-input"
          @input="onInput"
          @keydown="onInputKeydown"
          @blur="onBlur"
          @focus="emit('focus')"
        />
        <button
          type="button"
          class="absolute right-1.5 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent"
          :disabled="disabled || readonly"
          :aria-label="open ? 'Tutup kalender' : 'Buka kalender'"
          :aria-expanded="open"
          data-testid="ui-date-toggle"
          @click="open ? closeCalendar() : openCalendar()"
        >
          <CalendarDays class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </PopoverAnchor>

    <PopoverPortal>
      <PopoverContent
        side="bottom"
        align="start"
        :side-offset="4"
        class="z-[60] w-72 rounded-xl border border-slate-200 bg-white p-3 text-body2 shadow-panel focus:outline-none"
        role="dialog"
        aria-label="Pilih tanggal"
        data-testid="ui-date-panel"
        @open-auto-focus.prevent="focusCursor"
        @close-auto-focus.prevent
        @pointer-down-outside="onPointerDownOutside"
        @escape-key-down="() => closeCalendar()"
      >
        <div ref="content" class="contents">
          <div class="mb-2 flex items-center justify-between gap-1">
            <div class="flex">
              <button type="button" :class="navBtn" aria-label="Tahun sebelumnya" data-testid="ui-date-prev-year" @click="shiftView(-12)">
                <ChevronsLeft class="h-4 w-4" aria-hidden="true" />
              </button>
              <button type="button" :class="navBtn" aria-label="Bulan sebelumnya" data-testid="ui-date-prev-month" @click="shiftView(-1)">
                <ChevronLeft class="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
            <p class="text-body2 font-semibold text-slate-800" aria-live="polite" data-testid="ui-date-title">{{ title }}</p>
            <div class="flex">
              <button type="button" :class="navBtn" aria-label="Bulan berikutnya" data-testid="ui-date-next-month" @click="shiftView(1)">
                <ChevronRight class="h-4 w-4" aria-hidden="true" />
              </button>
              <button type="button" :class="navBtn" aria-label="Tahun berikutnya" data-testid="ui-date-next-year" @click="shiftView(12)">
                <ChevronsRight class="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          </div>

          <table class="w-full border-collapse" role="grid" :aria-label="title" @keydown="onGridKeydown">
            <thead>
              <tr>
                <th v-for="(w, i) in WEEKDAYS_SHORT" :key="w" scope="col" class="h-8 text-center text-caption font-medium text-slate-400" :abbr="WEEKDAYS_LONG[i]">
                  {{ w }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(week, wi) in weeks" :key="wi">
                <td v-for="cell in week" :key="toIso(cell)" role="gridcell" class="p-0 text-center" :aria-selected="same(selected, cell) ? 'true' : 'false'">
                  <button
                    type="button"
                    class="mx-auto flex h-9 w-9 items-center justify-center rounded-lg text-body2 transition"
                    :class="[
                      same(selected, cell) ? 'bg-brand-tertiary font-semibold text-white' : 'hover:bg-slate-100',
                      !same(selected, cell) && same(now, cell) ? 'ring-1 ring-brand-tertiary/60' : '',
                      !cell.inMonth && !same(selected, cell) ? 'text-slate-300' : '',
                      cell.inMonth && !same(selected, cell) ? 'text-slate-700' : '',
                      !inRange(cell) ? 'cursor-not-allowed text-slate-300 line-through hover:bg-transparent' : '',
                    ]"
                    :tabindex="same(cursor, cell) ? 0 : -1"
                    :aria-label="formatLong(cell)"
                    :aria-current="same(now, cell) ? 'date' : undefined"
                    :aria-disabled="!inRange(cell) || undefined"
                    :data-iso="toIso(cell)"
                    @click="pick(cell)"
                    @focus="cursor = cell"
                  >
                    {{ cell.d }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>

          <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2">
            <button type="button" :class="footBtn" :disabled="!inRange(now)" data-testid="ui-date-today" @click="pickToday">Hari ini</button>
            <button v-if="!required" type="button" :class="[footBtn, 'text-slate-500']" data-testid="ui-date-clear" @click="clear">Kosongkan</button>
          </div>
        </div>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
