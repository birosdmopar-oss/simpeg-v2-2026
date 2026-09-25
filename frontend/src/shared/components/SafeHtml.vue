<script setup lang="ts">
/**
 * Render konten HTML (artikel FAQ, pratinjau admin) — satu-satunya tempat `v-html` dipakai; masukan selalu
 * lewat sanitizeHtml() (DOMPurify, whitelist setara HTMLPurifier backend). Gaya dasar ditulis manual karena
 * preflight Tailwind mereset list/heading/tabel.
 */
import { computed } from 'vue'

import { sanitizeHtml } from '@/shared/utils/sanitizeHtml'

const props = withDefaults(defineProps<{ html: string | null | undefined; emptyText?: string }>(), { emptyText: '' })

const clean = computed(() => sanitizeHtml(props.html))
</script>

<template>
  <div v-if="clean !== ''" class="safe-html" data-testid="safe-html" v-html="clean" />
  <p v-else-if="emptyText" class="text-sm text-slate-400">{{ emptyText }}</p>
</template>

<style>
/* Tidak scoped: isi v-html tidak membawa atribut scope. Semua selector diawali .safe-html. */
.safe-html {
  font-size: 0.875rem;
  line-height: 1.65;
  color: rgb(51 65 85);
  overflow-wrap: anywhere;
}
.safe-html > * + * {
  margin-top: 0.75rem;
}
.safe-html h2 {
  font-size: 1.125rem;
  font-weight: 600;
  color: rgb(15 23 42);
}
.safe-html h3 {
  font-size: 1rem;
  font-weight: 600;
  color: rgb(15 23 42);
}
.safe-html h4 {
  font-weight: 600;
  color: rgb(30 41 59);
}
.safe-html ul {
  list-style: disc;
  padding-left: 1.25rem;
}
.safe-html ol {
  list-style: decimal;
  padding-left: 1.25rem;
}
.safe-html li + li {
  margin-top: 0.25rem;
}
.safe-html a {
  color: #217aff;
  text-decoration: underline;
}
.safe-html blockquote {
  border-left: 3px solid rgb(203 213 225);
  padding-left: 0.75rem;
  color: rgb(71 85 105);
}
.safe-html pre {
  overflow-x: auto;
  border-radius: 0.375rem;
  background: rgb(241 245 249);
  padding: 0.75rem;
}
.safe-html code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.8125rem;
}
.safe-html hr {
  border-color: rgb(226 232 240);
}
.safe-html img {
  max-width: 100%;
  height: auto;
}
.safe-html table {
  display: block;
  max-width: 100%;
  overflow-x: auto;
  border-collapse: collapse;
}
.safe-html th,
.safe-html td {
  border: 1px solid rgb(226 232 240);
  padding: 0.375rem 0.625rem;
  text-align: left;
  vertical-align: top;
}
.safe-html th {
  background: rgb(248 250 252);
  font-weight: 600;
}
</style>
