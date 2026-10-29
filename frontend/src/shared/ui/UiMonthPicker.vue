<script setup lang="ts">
/**
 * Input bulan custom (AGENTS.md bagian 3, CR-060) — pengganti `<input type="month">` bawaan browser. Hanya KONTROL
 * (kolom ketik + tombol kalender + popup pilih bulan); label/help/state dipasang pembungkusnya (UiTextField type="month").
 *
 * Nilai string `YYYY-MM` ('' = kosong); tampilan `mm/yyyy`. Mengetik manual: `mm/yyyy`, `m/yyyy`, `mm-yyyy`, atau
 * `yyyy-mm`; terkirim begitu sah, salah format → dikosongkan saat blur + pesan lewat event `invalid`.
 *
 * Popup (radix-vue Popover): header tahun dengan tombol ‹ ›, grid 12 bulan (3 × 4). Keyboard: ←/→ ±1 bulan, ↑/↓ ±3 bulan,
 * PageUp/PageDown ±1 tahun, Home/End Januari/Desember, Enter/Spasi memilih, Esc menutup dan mengembalikan fokus.
 */
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { PopoverAnchor, PopoverContent, PopoverPortal, PopoverRoot } from 'radix-vue'
import { computed, nextTick, ref, watch } from 'vue'

import { formatMonthDisplay, MONTH_NAMES, parseMonth, parseMonthTyped, toMonth, today, type YM } from './dateUtils'
import { kelasKontrolPicker } from './pickerControl'

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
    controlClass?: string | unknown[]
    ariaLabel?: string
  }>(),
  {
    modelValue: '',
    id: undefined,
    name: undefined,
    placeholder: 'bb/tttt',
    disabled: false,
    readonly: false,
    invalid: false,
    describedby: undefined,
    required: false,
    controlClass: '',
    ariaLabel: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: []; focus: []; invalid: [message: string] }>()

/** Kelas input: dari `controlClass` (UiTextField) atau gaya bawaan; selalu mengisi wadah (CR-081). */
const kelasKontrol = computed(() => kelasKontrolPicker(props.controlClass, props.invalid, props.disabled))

const input = ref<HTMLInputElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const open = ref(false)
const draft = ref(formatMonthDisplay(props.modelValue ?? ''))
const cursor = ref<YM>({ y: today().y, m: today().m })
let lastEmitted = props.modelValue ?? ''

watch(
  () => props.modelValue,
  (value) => {
    if ((value ?? '') !== lastEmitted) {
      lastEmitted = value ?? ''
      draft.value = formatMonthDisplay(lastEmitted)
    }
  },
)

const selected = (): YM | null => parseMonth(props.modelValue ?? '')
const now = today()

function setValue(value: string): void {
  lastEmitted = value
  emit('update:modelValue', value)
}

function onInput(e: Event): void {
  draft.value = (e.target as HTMLInputElement).value
  emit('invalid', '')
  if (draft.value.trim() === '') return setValue('')
  const parsed = parseMonthTyped(draft.value)
  if (parsed) setValue(toMonth(parsed))
}

function commit(): void {
  const text = draft.value.trim()
  if (text === '') {
    if (lastEmitted !== '') setValue('')
    return
  }
  const parsed = parseMonthTyped(text)
  if (parsed) {
    const value = toMonth(parsed)
    if (value !== lastEmitted) setValue(value)
    draft.value = formatMonthDisplay(value)
    return
  }
  if (lastEmitted !== '') setValue('')
  emit('invalid', 'Format bulan tidak valid. Gunakan bb/tttt.')
}

function onBlur(): void {
  if (!open.value) commit()
  emit('blur')
}

const monthIndex = (p: YM): number => p.y * 12 + (p.m - 1)
const fromIndex = (i: number): YM => ({ y: Math.floor(i / 12), m: (i % 12) + 1 })

function focusCursor(): void {
  void nextTick(() => panel.value?.querySelector<HTMLElement>(`[data-month="${cursor.value.m}"]`)?.focus())
}

function openPanel(): void {
  if (props.disabled || props.readonly || open.value) return
  cursor.value = selected() ?? parseMonthTyped(draft.value) ?? { y: now.y, m: now.m }
  open.value = true
}

function closePanel(refocus = true): void {
  if (!open.value) return
  open.value = false
  if (refocus) void nextTick(() => input.value?.focus())
}

function pick(m: number): void {
  const value = toMonth({ y: cursor.value.y, m })
  setValue(value)
  draft.value = formatMonthDisplay(value)
  emit('invalid', '')
  closePanel()
}

function shiftYear(years: number): void {
  cursor.value = { y: Math.max(1, cursor.value.y + years), m: cursor.value.m }
  focusCursor()
}

function onPanelKeydown(e: KeyboardEvent): void {
  const c = cursor.value
  const idx = monthIndex(c)
  const step: Record<string, number | undefined> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -3, ArrowDown: 3, PageUp: -12, PageDown: 12 }
  if (e.key in step) {
    e.preventDefault()
    cursor.value = fromIndex(Math.max(0, idx + (step[e.key] ?? 0)))
    focusCursor()
  } else if (e.key === 'Home' || e.key === 'End') {
    e.preventDefault()
    cursor.value = { y: c.y, m: e.key === 'Home' ? 1 : 12 }
    focusCursor()
  } else if (e.key === 'Escape') {
    e.preventDefault()
    e.stopPropagation()
    closePanel()
  }
}

function onInputKeydown(e: KeyboardEvent): void {
  if (e.key === 'Enter') commit()
  else if (e.key === 'ArrowDown' && (e.altKey || !draft.value)) {
    e.preventDefault()
    openPanel()
  } else if (e.key === 'Escape' && open.value) {
    e.stopPropagation()
    closePanel()
  }
}

function onPointerDownOutside(e: CustomEvent<{ originalEvent: PointerEvent }>): void {
  const target = e.detail.originalEvent.target as Node | null
  if (target && (input.value?.parentElement?.contains(target) ?? false)) e.preventDefault()
}

const isSelected = (m: number): boolean => {
  const s = selected()
  return s !== null && s.y === cursor.value.y && s.m === m
}
</script>

<template>
  <PopoverRoot :open="open" :modal="false" @update:open="(v: boolean) => (v ? openPanel() : closePanel(false))">
    <PopoverAnchor as-child>
      <!-- CR-081: kontrol selalu mengisi wadahnya dan tidak pernah lebih lebar darinya (tanpa lebar intrinsik `size` input). -->
      <div class="relative w-full min-w-0">
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
          :class="kelasKontrol"
          :aria-required="required || undefined"
          :aria-invalid="invalid || undefined"
          :aria-describedby="describedby"
          :aria-label="ariaLabel"
          aria-haspopup="dialog"
          :aria-expanded="open"
          data-testid="ui-month-input"
          @input="onInput"
          @keydown="onInputKeydown"
          @blur="onBlur"
          @focus="emit('focus')"
        />
        <button
          type="button"
          class="absolute right-1.5 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent"
          :disabled="disabled || readonly"
          :aria-label="open ? 'Tutup pilihan bulan' : 'Buka pilihan bulan'"
          :aria-expanded="open"
          data-testid="ui-month-toggle"
          @click="open ? closePanel() : openPanel()"
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
        class="z-[60] w-64 rounded-xl border border-slate-200 bg-white p-3 text-body2 shadow-panel focus:outline-none"
        role="dialog"
        aria-label="Pilih bulan"
        data-testid="ui-month-panel"
        @open-auto-focus.prevent="focusCursor"
        @close-auto-focus.prevent
        @pointer-down-outside="onPointerDownOutside"
        @escape-key-down="() => closePanel()"
      >
        <div ref="panel" @keydown="onPanelKeydown">
          <div class="mb-2 flex items-center justify-between">
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary"
              aria-label="Tahun sebelumnya"
              data-testid="ui-month-prev-year"
              @click="shiftYear(-1)"
            >
              <ChevronLeft class="h-4 w-4" aria-hidden="true" />
            </button>
            <p class="text-body2 font-semibold text-slate-800" aria-live="polite" data-testid="ui-month-year">{{ cursor.y }}</p>
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary"
              aria-label="Tahun berikutnya"
              data-testid="ui-month-next-year"
              @click="shiftYear(1)"
            >
              <ChevronRight class="h-4 w-4" aria-hidden="true" />
            </button>
          </div>

          <div class="grid grid-cols-3 gap-1" role="group" :aria-label="`Bulan ${cursor.y}`">
            <button
              v-for="(name, i) in MONTH_NAMES"
              :key="name"
              type="button"
              class="flex h-10 items-center justify-center rounded-lg text-body2 transition"
              :class="[
                isSelected(i + 1) ? 'bg-brand-tertiary font-semibold text-white' : 'text-slate-700 hover:bg-slate-100',
                !isSelected(i + 1) && now.y === cursor.y && now.m === i + 1 ? 'ring-1 ring-brand-tertiary/60' : '',
              ]"
              :tabindex="cursor.m === i + 1 ? 0 : -1"
              :aria-pressed="isSelected(i + 1) ? 'true' : 'false'"
              :aria-label="`${name} ${cursor.y}`"
              :data-month="i + 1"
              @click="pick(i + 1)"
              @focus="cursor = { y: cursor.y, m: i + 1 }"
            >
              {{ name.slice(0, 3) }}
            </button>
          </div>
        </div>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
