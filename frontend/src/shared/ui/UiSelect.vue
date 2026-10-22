<script setup lang="ts">
/**
 * Dropdown custom — Laporan Redesign §3.5 + AGENTS.md bagian 3 (CR-060): TIDAK memakai daftar bawaan browser/OS.
 * Label di atas, kotak dengan ikon chevron di kanan, state default / error / success / disabled + help text (pola §3.4),
 * daftar opsi berupa kartu putih berbayang/radius (radix-vue Popover, ikut aturan layer dialog) dengan scrollbar ramping.
 *
 * Aksesibilitas (WCAG 2.1 AA): trigger `role="combobox"` + `aria-haspopup="listbox"`, daftar `role="listbox"` berisi
 * `role="option"` (`aria-selected`, `aria-disabled`), fokus tetap di trigger (`aria-activedescendant`). Keyboard: Enter/Spasi/
 * ArrowDown/ArrowUp membuka; panah/Home/End memindahkan opsi aktif; Enter/Spasi memilih; Esc menutup; Tab menutup; huruf
 * melompat ke opsi berawalan itu (typeahead). `searchable` mengganti trigger dengan kolom ketik yang menyaring opsi.
 *
 * API TIDAK berubah dari versi <select> native (modelValue, options, label, placeholder, state, helpText, required,
 * disabled, name, clearable, ariaLabel; emit update:modelValue dan blur), jadi pemanggil tidak perlu diubah.
 * Sebuah <select> native TERSEMBUNYI (sr-only, aria-hidden, tabindex -1) menjadi cermin nilai dua arah (pola radix
 * BubbleSelect): pembawa nilai untuk <form>/`name` dan titik masuk bagi alat yang mengubah nilai lewat elemen native.
 * CR-028: dibungkus FormField.vue untuk tipe `select`; pesan error memakai role="alert".
 */
import { Check, ChevronDown } from 'lucide-vue-next'
import { PopoverAnchor, PopoverContent, PopoverPortal, PopoverRoot } from 'radix-vue'
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'

export type SelectOption = { value: string | number; label: string; disabled?: boolean }

type Item = { key: string; value: string; label: string; disabled: boolean; clear: boolean }

const props = withDefaults(
  defineProps<{
    modelValue?: string | number | null
    label?: string
    placeholder?: string
    options?: SelectOption[]
    state?: 'default' | 'error' | 'success'
    helpText?: string
    required?: boolean
    disabled?: boolean
    name?: string
    /** true = opsi placeholder ('Semua …') bisa dipilih lagi untuk mengosongkan nilai (filter). */
    clearable?: boolean
    /** Label aksesibel bila tidak ada `label` yang terlihat (mis. pemilih jumlah baris). */
    ariaLabel?: string
    /** true = trigger berupa kolom ketik yang menyaring opsi (daftar panjang). Default false. */
    searchable?: boolean
  }>(),
  {
    modelValue: '',
    label: '',
    placeholder: 'Pilih...',
    options: () => [],
    state: 'default',
    helpText: '',
    required: false,
    disabled: false,
    name: undefined,
    clearable: false,
    ariaLabel: undefined,
    searchable: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>()

const id = useId()
const listId = `${id}-list`
const valueId = `${id}-value`
const labelId = `${id}-label`

const open = ref(false)
const active = ref(-1)
const query = ref('')
const trigger = ref<HTMLElement | null>(null)

const current = computed(() => String(props.modelValue ?? ''))
const selected = computed(() => props.options.find((o) => String(o.value) === current.value) ?? null)

const items = computed<Item[]>(() => {
  const q = props.searchable ? query.value.trim().toLowerCase() : ''
  const list: Item[] = props.options
    .filter((o) => q === '' || o.label.toLowerCase().includes(q))
    .map((o) => ({ key: String(o.value), value: String(o.value), label: o.label, disabled: o.disabled === true, clear: false }))
  if (props.clearable && q === '') list.unshift({ key: '__clear', value: '', label: props.placeholder, disabled: false, clear: true })
  return list
})

const optionId = (index: number): string => `${id}-opt-${index}`
const activeId = computed(() => (open.value && active.value >= 0 ? optionId(active.value) : undefined))

const tone = computed(() => {
  // Field terkunci tetap menampilkan pesan error berwarna merah (mis. dropdown induk belum dipilih saat form dikirim).
  if (props.disabled) {
    return { border: 'border-slate-200', label: 'text-slate-400', help: props.state === 'error' ? 'text-danger' : 'text-slate-400' }
  }
  if (props.state === 'error') return { border: 'border-danger', label: 'text-danger', help: 'text-danger' }
  if (props.state === 'success') return { border: 'border-success', label: 'text-success', help: 'text-success' }
  return { border: 'border-slate-300', label: 'text-slate-700', help: 'text-slate-500' }
})

const boxClass = computed(() => [
  'flex h-11 w-full items-center rounded-lg border bg-white pl-3 pr-10 text-left text-body1 outline-none transition',
  tone.value.border,
  props.state === 'default' && !props.disabled ? 'focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25' : '',
  open.value && props.state === 'default' ? 'border-brand-tertiary ring-2 ring-brand-tertiary/25' : '',
  props.disabled ? 'cursor-not-allowed bg-slate-100 text-slate-400' : 'cursor-pointer',
  current.value === '' && !props.disabled ? 'text-slate-400' : props.disabled ? '' : 'text-slate-900',
])

function firstEnabled(from = 0, step = 1): number {
  const n = items.value.length
  for (let i = 0; i < n; i++) {
    const idx = (((from + i * step) % n) + n) % n
    if (!items.value[idx]?.disabled) return idx
  }
  return -1
}

function openList(): void {
  if (props.disabled || open.value) return
  open.value = true
  const sel = items.value.findIndex((i) => i.value === current.value && !i.clear)
  active.value = sel >= 0 ? sel : items.value.findIndex((i) => i.clear && current.value === '') >= 0 ? items.value.findIndex((i) => i.clear) : firstEnabled()
}

function closeList(refocus = false): void {
  if (!open.value) return
  open.value = false
  active.value = -1
  query.value = ''
  if (refocus) void nextTick(() => trigger.value?.focus())
}

function choose(item: Item | undefined): void {
  if (!item || item.disabled) return
  if (item.value !== current.value) emit('update:modelValue', item.value)
  closeList(true)
}

function move(delta: number): void {
  if (items.value.length === 0) return
  const next = firstEnabled(active.value + delta, delta)
  if (next >= 0) active.value = next
}

let typed = ''
let typedTimer: ReturnType<typeof setTimeout> | undefined
function typeahead(char: string): void {
  typed += char.toLowerCase()
  clearTimeout(typedTimer)
  typedTimer = setTimeout(() => (typed = ''), 600)
  const n = items.value.length
  const start = typed.length === 1 ? active.value + 1 : Math.max(active.value, 0)
  for (let i = 0; i < n; i++) {
    const idx = (start + i) % n
    const item = items.value[idx]
    if (item && !item.disabled && !item.clear && item.label.toLowerCase().startsWith(typed)) {
      if (!open.value) openList()
      active.value = idx
      return
    }
  }
}

function onKeydown(e: KeyboardEvent): void {
  if (props.disabled) return
  switch (e.key) {
    case 'ArrowDown':
    case 'ArrowUp':
      e.preventDefault()
      if (!open.value) openList()
      else move(e.key === 'ArrowDown' ? 1 : -1)
      break
    case 'Home':
    case 'End':
      if (open.value && !props.searchable) {
        e.preventDefault()
        active.value = e.key === 'Home' ? firstEnabled(0, 1) : firstEnabled(items.value.length - 1, -1)
      }
      break
    case 'Enter':
      e.preventDefault()
      if (open.value) choose(items.value[active.value])
      else openList()
      break
    case ' ':
      if (props.searchable) break
      e.preventDefault()
      if (open.value) choose(items.value[active.value])
      else openList()
      break
    case 'Escape':
      if (open.value) {
        e.preventDefault()
        e.stopPropagation() // Esc menutup daftar, bukan dialog di belakangnya
        closeList(true)
      }
      break
    case 'Tab':
      closeList()
      break
    default:
      if (!props.searchable && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) typeahead(e.key)
  }
}

function onTriggerClick(): void {
  if (props.disabled) return
  if (open.value) closeList()
  else openList()
}

function onInput(e: Event): void {
  query.value = (e.target as HTMLInputElement).value
  if (!open.value) openList()
  active.value = firstEnabled()
}

// Klik pada trigger sendiri tidak dianggap "di luar" (supaya tidak menutup lalu membuka lagi).
function onPointerDownOutside(e: CustomEvent<{ originalEvent: PointerEvent }>): void {
  const target = e.detail.originalEvent.target as Node | null
  if (target && (trigger.value?.contains(target) || root.value?.contains(target))) e.preventDefault()
}

const root = ref<HTMLElement | null>(null)

watch(active, (index) => {
  if (index < 0) return
  void nextTick(() => document.getElementById(optionId(index))?.scrollIntoView?.({ block: 'nearest' }))
})

watch(
  () => props.disabled,
  (value) => {
    if (value) closeList()
  },
)

onBeforeUnmount(() => clearTimeout(typedTimer))

function onMirrorChange(e: Event): void {
  emit('update:modelValue', (e.target as HTMLSelectElement).value)
}

const labelledBy = computed(() => (props.label ? `${labelId} ${valueId}` : undefined))
const displayText = computed(() => (selected.value ? selected.value.label : ''))
</script>

<template>
  <div ref="root" class="w-full">
    <label v-if="label" :id="labelId" :for="id" class="mb-1 block text-body2 font-medium" :class="tone.label">
      {{ label }}<span v-if="required" class="text-danger"> *</span>
    </label>

    <PopoverRoot :open="open" :modal="false" @update:open="(v: boolean) => (v ? openList() : closeList())">
      <PopoverAnchor as-child>
        <div class="relative">
          <!-- Cermin native TERSEMBUNYI: pembawa nilai form + titik masuk perubahan nilai dari alat native. -->
          <select
            :name="name"
            :value="current"
            :disabled="disabled"
            :aria-label="label || ariaLabel"
            class="sr-only"
            aria-hidden="true"
            tabindex="-1"
            data-testid="ui-select-native"
            @change="onMirrorChange"
          >
            <option value="" :disabled="!clearable">{{ placeholder }}</option>
            <option v-for="opt in options" :key="opt.value" :value="opt.value" :disabled="opt.disabled">{{ opt.label }}</option>
          </select>

          <input
            v-if="searchable"
            :id="id"
            ref="trigger"
            type="text"
            role="combobox"
            autocomplete="off"
            :value="open ? query : displayText"
            :placeholder="displayText || placeholder"
            :disabled="disabled"
            :class="[boxClass, displayText === '' || open ? 'placeholder:text-slate-400' : '']"
            :aria-expanded="open"
            aria-haspopup="listbox"
            aria-autocomplete="list"
            :aria-controls="listId"
            :aria-activedescendant="activeId"
            :aria-label="label ? undefined : ariaLabel"
            :aria-required="required || undefined"
            :aria-invalid="state === 'error' || undefined"
            :aria-describedby="helpText ? `${id}-help` : undefined"
            data-testid="ui-select-trigger"
            @click="openList"
            @input="onInput"
            @keydown="onKeydown"
            @blur="emit('blur')"
          />
          <button
            v-else
            :id="id"
            ref="trigger"
            type="button"
            role="combobox"
            :disabled="disabled"
            :class="boxClass"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :aria-controls="listId"
            :aria-activedescendant="activeId"
            :aria-labelledby="labelledBy"
            :aria-label="label ? undefined : ariaLabel"
            :aria-required="required || undefined"
            :aria-invalid="state === 'error' || undefined"
            :aria-describedby="helpText ? `${id}-help` : undefined"
            data-testid="ui-select-trigger"
            @click="onTriggerClick"
            @keydown="onKeydown"
            @blur="emit('blur')"
          >
            <span :id="valueId" class="min-w-0 flex-1 truncate">{{ displayText || placeholder }}</span>
          </button>
          <ChevronDown
            class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 transition-transform"
            :class="[disabled ? 'text-slate-300' : 'text-slate-500', open ? 'rotate-180' : '']"
            aria-hidden="true"
          />
        </div>
      </PopoverAnchor>

      <PopoverPortal>
        <PopoverContent
          side="bottom"
          align="start"
          :side-offset="4"
          :avoid-collisions="true"
          class="z-[60] w-[var(--radix-popper-anchor-width)] min-w-40 rounded-xl border border-slate-200 bg-white p-1 text-body1 shadow-panel focus:outline-none"
          data-testid="ui-select-panel"
          @open-auto-focus.prevent
          @close-auto-focus.prevent
          @pointer-down-outside="onPointerDownOutside"
          @focus-outside.prevent
          @escape-key-down="() => closeList(true)"
        >
          <ul :id="listId" role="listbox" :aria-label="label || ariaLabel" class="max-h-64 overflow-y-auto" tabindex="-1">
            <li
              v-for="(item, index) in items"
              :id="optionId(index)"
              :key="item.key"
              role="option"
              :aria-selected="!item.clear && item.value === current ? 'true' : 'false'"
              :aria-disabled="item.disabled || undefined"
              :data-value="item.value"
              :data-active="index === active || undefined"
              class="flex cursor-pointer select-none items-center justify-between gap-2 rounded-lg px-3 py-2"
              :class="[
                item.disabled ? 'cursor-not-allowed text-slate-300' : item.clear ? 'text-slate-400' : 'text-slate-700',
                index === active && !item.disabled ? 'bg-slate-100' : '',
                !item.clear && item.value === current ? 'font-medium text-brand-primary' : '',
              ]"
              @mousedown.prevent
              @mousemove="!item.disabled && (active = index)"
              @click="choose(item)"
            >
              <span class="truncate">{{ item.label }}</span>
              <Check v-if="!item.clear && item.value === current" class="h-4 w-4 shrink-0 text-brand-tertiary" aria-hidden="true" />
            </li>
            <li v-if="items.length === 0" class="px-3 py-2 text-slate-400" role="presentation">Tidak ada pilihan.</li>
          </ul>
        </PopoverContent>
      </PopoverPortal>
    </PopoverRoot>

    <p
      v-if="helpText"
      :id="`${id}-help`"
      class="mt-1 text-caption"
      :class="tone.help"
      :role="state === 'error' ? 'alert' : undefined"
    >
      {{ helpText }}
    </p>
  </div>
</template>
