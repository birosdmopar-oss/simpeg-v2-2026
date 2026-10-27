<script setup lang="ts">
/**
 * Kolom teks bebas dengan saran (AGENTS.md bagian 3, CR-060) — pengganti `<input list>` + `<datalist>` bawaan browser.
 * Hanya KONTROL (dipasang pembungkusnya, UiTextField `suggestions`): nilai tetap teks bebas; saran hanya membantu mengisi.
 *
 * ARIA: `role="combobox"` + `aria-autocomplete="list"`, daftar `role="listbox"` berisi `role="option"`, fokus tetap di
 * kolom ketik (`aria-activedescendant`). Keyboard: ↓/↑ membuka dan memindah saran aktif, Enter memilih saran aktif (tanpa
 * saran aktif, Enter dibiarkan untuk form), Esc menutup daftar saja, Tab menutup. Saran disaring (huruf besar/kecil
 * diabaikan, berisi teks ketikan); daftar tidak tampil bila tidak ada yang cocok.
 */
import { PopoverAnchor, PopoverContent, PopoverPortal, PopoverRoot } from 'radix-vue'
import { computed, nextTick, ref } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue?: string | null
    suggestions?: string[]
    id?: string
    name?: string
    placeholder?: string
    disabled?: boolean
    readonly?: boolean
    invalid?: boolean
    describedby?: string
    required?: boolean
    controlClass?: string | unknown[]
    inputmode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email' | 'search'
    autocomplete?: string
    ariaLabel?: string
  }>(),
  {
    modelValue: '',
    suggestions: () => [],
    id: undefined,
    name: undefined,
    placeholder: '',
    disabled: false,
    readonly: false,
    invalid: false,
    describedby: undefined,
    required: false,
    controlClass: '',
    inputmode: undefined,
    autocomplete: 'off',
    ariaLabel: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: []; focus: [] }>()

const MAX_SHOWN = 50

const input = ref<HTMLInputElement | null>(null)
const open = ref(false)
const active = ref(-1)
const root = ref<HTMLElement | null>(null)

const text = computed(() => String(props.modelValue ?? ''))
const matches = computed(() => {
  const q = text.value.trim().toLowerCase()
  const list = q === '' ? props.suggestions : props.suggestions.filter((s) => s.toLowerCase().includes(q) && s.toLowerCase() !== q)
  return list.slice(0, MAX_SHOWN)
})

const listId = computed(() => `${props.id ?? 'ui-combobox'}-list`)
const optionId = (i: number): string => `${props.id ?? 'ui-combobox'}-opt-${i}`
const showList = computed(() => open.value && matches.value.length > 0 && !props.disabled && !props.readonly)
const activeId = computed(() => (showList.value && active.value >= 0 ? optionId(active.value) : undefined))

function show(): void {
  if (props.disabled || props.readonly) return
  open.value = true
}

function hide(): void {
  open.value = false
  active.value = -1
}

function onInput(e: Event): void {
  emit('update:modelValue', (e.target as HTMLInputElement).value)
  active.value = -1
  show()
}

function choose(value: string): void {
  emit('update:modelValue', value)
  hide()
  void nextTick(() => input.value?.focus())
}

function onKeydown(e: KeyboardEvent): void {
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    if (matches.value.length === 0) return
    e.preventDefault()
    if (!open.value) show()
    const n = matches.value.length
    active.value = e.key === 'ArrowDown' ? (active.value + 1) % n : (active.value - 1 + n) % n
    void nextTick(() => document.getElementById(optionId(active.value))?.scrollIntoView?.({ block: 'nearest' }))
  } else if (e.key === 'Enter' && showList.value && active.value >= 0) {
    e.preventDefault()
    choose(matches.value[active.value] ?? text.value)
  } else if (e.key === 'Escape' && open.value) {
    e.stopPropagation() // Esc menutup daftar, bukan dialog di belakangnya
    hide()
  } else if (e.key === 'Tab') {
    hide()
  }
}

function onPointerDownOutside(e: CustomEvent<{ originalEvent: PointerEvent }>): void {
  const target = e.detail.originalEvent.target as Node | null
  if (target && (root.value?.contains(target) ?? false)) e.preventDefault()
}
</script>

<template>
  <PopoverRoot :open="showList" :modal="false" @update:open="(v: boolean) => (v ? show() : hide())">
    <PopoverAnchor as-child>
      <div ref="root" class="relative">
        <input
          :id="id"
          ref="input"
          type="text"
          role="combobox"
          :name="name"
          :value="text"
          :placeholder="placeholder"
          :disabled="disabled"
          :readonly="readonly"
          :inputmode="inputmode"
          :autocomplete="autocomplete"
          :class="controlClass"
          aria-autocomplete="list"
          aria-haspopup="listbox"
          :aria-expanded="showList"
          :aria-controls="listId"
          :aria-activedescendant="activeId"
          :aria-required="required || undefined"
          :aria-invalid="invalid || undefined"
          :aria-describedby="describedby"
          :aria-label="ariaLabel"
          data-testid="ui-combobox-input"
          @input="onInput"
          @focus="emit('focus')"
          @keydown="onKeydown"
          @blur="emit('blur')"
        />
      </div>
    </PopoverAnchor>

    <PopoverPortal>
      <PopoverContent
        side="bottom"
        align="start"
        :side-offset="4"
        class="z-[60] w-[var(--radix-popper-anchor-width)] min-w-40 rounded-xl border border-slate-200 bg-white p-1 text-body1 shadow-panel focus:outline-none"
        data-testid="ui-combobox-panel"
        @open-auto-focus.prevent
        @close-auto-focus.prevent
        @pointer-down-outside="onPointerDownOutside"
        @focus-outside.prevent
        @escape-key-down="hide"
      >
        <ul :id="listId" role="listbox" class="max-h-64 overflow-y-auto">
          <li
            v-for="(s, i) in matches"
            :id="optionId(i)"
            :key="s"
            role="option"
            :aria-selected="i === active ? 'true' : 'false'"
            :data-active="i === active || undefined"
            class="cursor-pointer select-none truncate rounded-lg px-3 py-2 text-slate-700"
            :class="i === active ? 'bg-slate-100' : ''"
            @mousedown.prevent
            @mousemove="active = i"
            @click="choose(s)"
          >
            {{ s }}
          </li>
        </ul>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
