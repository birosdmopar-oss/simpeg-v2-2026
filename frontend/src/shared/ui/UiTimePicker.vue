<script setup lang="ts">
/**
 * Input waktu custom (AGENTS.md bagian 3, CR-060) — pengganti `<input type="time">` bawaan browser. Hanya KONTROL
 * (kolom ketik + tombol jam + popup dua kolom Jam/Menit); label/help/state dipasang pembungkusnya (UiTextField type="time").
 *
 * Nilai string `HH:mm` ('' = kosong; `HH:mm:ss` dari server dibaca, detik diabaikan). Mengetik manual: `HH:mm`, `H:mm`,
 * `HH.mm`, atau `HHmm`; nilai terkirim begitu sah, format salah → dikosongkan saat blur + pesan lewat event `invalid`.
 *
 * Popup (radix-vue Popover): dua `role="listbox"` — Jam 00–23 dan Menit (kelipatan 5 + nilai sekarang). Keyboard: ↑/↓
 * pindah opsi dalam kolom, ←/→ pindah kolom, Enter/Spasi memilih (Jam: tetap terbuka; Menit: menutup), Home/End awal/akhir
 * kolom, Esc menutup dan mengembalikan fokus ke kolom ketik.
 */
import { Clock } from 'lucide-vue-next'
import { PopoverAnchor, PopoverContent, PopoverPortal, PopoverRoot } from 'radix-vue'
import { computed, nextTick, ref, useId, watch } from 'vue'

import { pad, parseTime, parseTimeTyped, toTime } from './dateUtils'

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
    placeholder: 'jj:mm',
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

const uid = useId()
const input = ref<HTMLInputElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const open = ref(false)
const display = (value: string | null | undefined): string => {
  const p = parseTime(value ?? '')
  return p ? toTime(p) : (value ?? '')
}
const draft = ref(display(props.modelValue))
const cursor = ref<{ col: 'h' | 'm'; value: number }>({ col: 'h', value: 0 })
let lastEmitted = props.modelValue ?? ''

watch(
  () => props.modelValue,
  (value) => {
    if ((value ?? '') !== lastEmitted) {
      lastEmitted = value ?? ''
      draft.value = display(lastEmitted)
    }
  },
)

const current = computed(() => parseTime(props.modelValue ?? ''))
const hours = Array.from({ length: 24 }, (_, i) => i)
const minutes = computed(() => {
  const list = Array.from({ length: 12 }, (_, i) => i * 5)
  const m = current.value?.min
  if (m !== undefined && !list.includes(m)) list.push(m)
  return list.sort((a, b) => a - b)
})

function setValue(value: string): void {
  lastEmitted = value
  emit('update:modelValue', value)
}

function onInput(e: Event): void {
  draft.value = (e.target as HTMLInputElement).value
  emit('invalid', '')
  if (draft.value.trim() === '') return setValue('')
  const parsed = parseTimeTyped(draft.value)
  if (parsed) setValue(toTime(parsed))
}

function commit(): void {
  const text = draft.value.trim()
  if (text === '') {
    if (lastEmitted !== '') setValue('')
    return
  }
  const parsed = parseTimeTyped(text)
  if (parsed) {
    const value = toTime(parsed)
    if (value !== lastEmitted) setValue(value)
    draft.value = value
    return
  }
  if (lastEmitted !== '') setValue('')
  emit('invalid', 'Format jam tidak valid. Gunakan jj:mm.')
}

function onBlur(): void {
  if (!open.value) commit()
  emit('blur')
}

function focusCursor(): void {
  void nextTick(() => panel.value?.querySelector<HTMLElement>(`[data-col="${cursor.value.col}"][data-value="${cursor.value.value}"]`)?.focus())
}

function openPanel(): void {
  if (props.disabled || props.readonly || open.value) return
  const base = current.value ?? parseTimeTyped(draft.value)
  cursor.value = { col: 'h', value: base?.h ?? 8 }
  open.value = true
}

function closePanel(refocus = true): void {
  if (!open.value) return
  open.value = false
  if (refocus) void nextTick(() => input.value?.focus())
}

function choose(col: 'h' | 'm', value: number): void {
  const base = current.value ?? { h: 0, min: 0 }
  const next = col === 'h' ? { h: value, min: base.min } : { h: base.h, min: value }
  const text = toTime(next)
  setValue(text)
  draft.value = text
  emit('invalid', '')
  if (col === 'm') closePanel()
  else {
    cursor.value = { col: 'h', value }
    focusCursor()
  }
}

function onPanelKeydown(e: KeyboardEvent): void {
  const { col, value } = cursor.value
  const list = col === 'h' ? hours : minutes.value
  const at = Math.max(list.indexOf(value), 0)
  let next: { col: 'h' | 'm'; value: number } | null = null
  if (e.key === 'ArrowDown') next = { col, value: list[Math.min(at + 1, list.length - 1)] ?? value }
  else if (e.key === 'ArrowUp') next = { col, value: list[Math.max(at - 1, 0)] ?? value }
  else if (e.key === 'Home') next = { col, value: list[0] ?? value }
  else if (e.key === 'End') next = { col, value: list[list.length - 1] ?? value }
  else if (e.key === 'ArrowRight' && col === 'h') next = { col: 'm', value: current.value?.min ?? 0 }
  else if (e.key === 'ArrowLeft' && col === 'm') next = { col: 'h', value: current.value?.h ?? 8 }
  if (next) {
    e.preventDefault()
    if (next.col === 'm' && !minutes.value.includes(next.value)) next.value = minutes.value[0] ?? 0
    cursor.value = next
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

const optionClass = (selectedNow: boolean): string[] => [
  'flex h-8 w-full items-center justify-center rounded-lg text-body2 transition',
  selectedNow ? 'bg-brand-tertiary font-semibold text-white' : 'text-slate-700 hover:bg-slate-100',
]
</script>

<template>
  <PopoverRoot :open="open" :modal="false" @update:open="(v: boolean) => (v ? openPanel() : closePanel(false))">
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
          data-testid="ui-time-input"
          @input="onInput"
          @keydown="onInputKeydown"
          @blur="onBlur"
          @focus="emit('focus')"
        />
        <button
          type="button"
          class="absolute right-1.5 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent"
          :disabled="disabled || readonly"
          :aria-label="open ? 'Tutup pilihan jam' : 'Buka pilihan jam'"
          :aria-expanded="open"
          data-testid="ui-time-toggle"
          @click="open ? closePanel() : openPanel()"
        >
          <Clock class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </PopoverAnchor>

    <PopoverPortal>
      <PopoverContent
        side="bottom"
        align="start"
        :side-offset="4"
        class="z-[60] w-44 rounded-xl border border-slate-200 bg-white p-2 text-body2 shadow-panel focus:outline-none"
        role="dialog"
        aria-label="Pilih jam"
        data-testid="ui-time-panel"
        @open-auto-focus.prevent="focusCursor"
        @close-auto-focus.prevent
        @pointer-down-outside="onPointerDownOutside"
        @escape-key-down="() => closePanel()"
      >
        <div ref="panel" class="grid grid-cols-2 gap-2" @keydown="onPanelKeydown">
          <div>
            <p :id="`${uid}-h`" class="mb-1 text-center text-caption font-medium text-slate-400">Jam</p>
            <ul role="listbox" :aria-labelledby="`${uid}-h`" class="max-h-56 space-y-0.5 overflow-y-auto">
              <li v-for="h in hours" :key="h" role="presentation">
                <button
                  type="button"
                  role="option"
                  :class="optionClass(current?.h === h)"
                  :aria-selected="current?.h === h ? 'true' : 'false'"
                  :tabindex="cursor.col === 'h' && cursor.value === h ? 0 : -1"
                  data-col="h"
                  :data-value="h"
                  @click="choose('h', h)"
                  @focus="cursor = { col: 'h', value: h }"
                >
                  {{ pad(h) }}
                </button>
              </li>
            </ul>
          </div>
          <div>
            <p :id="`${uid}-m`" class="mb-1 text-center text-caption font-medium text-slate-400">Menit</p>
            <ul role="listbox" :aria-labelledby="`${uid}-m`" class="max-h-56 space-y-0.5 overflow-y-auto">
              <li v-for="m in minutes" :key="m" role="presentation">
                <button
                  type="button"
                  role="option"
                  :class="optionClass(current?.min === m)"
                  :aria-selected="current?.min === m ? 'true' : 'false'"
                  :tabindex="cursor.col === 'm' && cursor.value === m ? 0 : -1"
                  data-col="m"
                  :data-value="m"
                  @click="choose('m', m)"
                  @focus="cursor = { col: 'm', value: m }"
                >
                  {{ pad(m) }}
                </button>
              </li>
            </ul>
          </div>
        </div>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
