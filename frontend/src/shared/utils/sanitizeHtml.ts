/**
 * Sanitasi HTML sisi render (DBV-002/CR-003 §5, keputusan U1) — lapis kedua setelah HTMLPurifier backend.
 * Konfigurasi setara whitelist backend (App\Libraries\Html\HtmlSanitizer):
 *   p, br, strong, b, em, i, u, s, sub, sup, ul, ol, li, a[href|title|target], img[src|alt|width|height],
 *   h2, h3, h4, blockquote, pre, code, hr, table, thead, tbody, tr, th[colspan|rowspan], td[colspan|rowspan], span.
 * Skema URI: http, https, mailto. `target` hanya `_blank` + rel="noopener noreferrer".
 * Gambar: src wajib URL absolut http/https; selain itu (relatif, //host, data:, javascript:, mailto:, kosong) elemen
 * <img> dibuang seluruhnya — sama dengan backend (EmbeddedUriSchemeFilter), supaya pratinjau admin = hasil simpan.
 * Atribut style, class, event handler (onclick, onerror, ...), data-, dan aria- selalu dibuang.
 *
 * WAJIB: setiap `v-html` di aplikasi hanya boleh menerima keluaran fungsi ini (lewat komponen SafeHtml).
 */
import DOMPurify, { type Config, type DOMPurify as DOMPurifyInstance } from 'dompurify'

export const SANITIZE_ALLOWED_TAGS: readonly string[] = [
  'p',
  'br',
  'strong',
  'b',
  'em',
  'i',
  'u',
  's',
  'sub',
  'sup',
  'ul',
  'ol',
  'li',
  'a',
  'img',
  'h2',
  'h3',
  'h4',
  'blockquote',
  'pre',
  'code',
  'hr',
  'table',
  'thead',
  'tbody',
  'tr',
  'th',
  'td',
  'span',
]

/** Atribut yang boleh per tag; tag lain tidak boleh punya atribut apa pun. */
export const SANITIZE_TAG_ATTRIBUTES: Readonly<Record<string, readonly string[]>> = {
  a: ['href', 'title', 'target'],
  img: ['src', 'alt', 'width', 'height'],
  th: ['colspan', 'rowspan'],
  td: ['colspan', 'rowspan'],
}

/** http, https, mailto, atau URI relatif (tanpa skema). Skema lain (javascript:, data:, vbscript:, ...) dibuang. */
const ALLOWED_URI = /^(?:(?:https?|mailto):|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i

/** src gambar yang diterima: URL absolut http/https (bukan relatif, bukan //host). */
const ABSOLUTE_HTTP_URL = /^https?:\/\/[^/]/i

/** Buang spasi & karakter kontrol yang diabaikan browser saat membaca URI (mis. "java\tscript:"). */
function stripUriNoise(value: string): string {
  let out = ''
  for (const ch of value) {
    if (ch.charCodeAt(0) > 0x20 && !/\s/u.test(ch)) out += ch
  }
  return out
}

const CONFIG: Config = {
  ALLOWED_TAGS: [...SANITIZE_ALLOWED_TAGS],
  ALLOWED_ATTR: [...new Set(Object.values(SANITIZE_TAG_ATTRIBUTES).flat())],
  ALLOWED_URI_REGEXP: ALLOWED_URI,
  ALLOW_DATA_ATTR: false,
  ALLOW_ARIA_ATTR: false,
  ALLOW_UNKNOWN_PROTOCOLS: false,
}

let purifier: DOMPurifyInstance | null = null

/** Instance DOMPurify khusus (hook tidak bocor ke pemakai DOMPurify lain). */
function getPurifier(): DOMPurifyInstance {
  if (purifier !== null) return purifier

  const instance = DOMPurify(window)

  instance.addHook('uponSanitizeAttribute', (node, event) => {
    const tag = node.nodeName.toLowerCase()
    const allowed = SANITIZE_TAG_ATTRIBUTES[tag] ?? []
    if (!allowed.includes(event.attrName)) {
      event.keepAttr = false
      return
    }
    // Gambar hanya dari URL absolut http/https; src lain dibuang (elemennya dibuang di afterSanitizeAttributes).
    if (tag === 'img' && event.attrName === 'src' && !ABSOLUTE_HTTP_URL.test(stripUriNoise(event.attrValue))) {
      event.keepAttr = false
    }
  })

  instance.addHook('afterSanitizeAttributes', (node) => {
    // <img> tanpa src yang valid dibuang seluruhnya (backend HTMLPurifier melakukan hal yang sama).
    if (node.nodeName.toLowerCase() === 'img' && !node.hasAttribute('src')) {
      node.parentNode?.removeChild(node)
      return
    }
    if (node.nodeName.toLowerCase() !== 'a' || !node.hasAttribute('target')) return
    if (node.getAttribute('target') === '_blank') {
      node.setAttribute('rel', 'noopener noreferrer')
    } else {
      node.removeAttribute('target')
    }
  })

  purifier = instance
  return instance
}

export function sanitizeHtml(html: string | null | undefined): string {
  if (html === null || html === undefined || html === '') return ''
  return getPurifier().sanitize(html, CONFIG)
}
