/**
 * Teks sederhana (Q7-10, PRD Fase 7 v2.0 §5.5) — format penulisan ringan ⇄ HTML terbatas.
 *
 * HTML yang dihasilkan HANYA memuat `p, br, strong, em, ul, ol, li, a[href]` (whitelist sama dengan server, yang tetap
 * membersihkan ulang dengan HTMLPurifier). Penulisan:
 *   **tebal**   *miring*   [teks](https://alamat)   "- butir" → daftar   "1. butir" → daftar bernomor   baris kosong → paragraf.
 * Tautan hanya http(s) dan mailto. Fungsi murni (tanpa efek samping); `bersihkanHtml` memakai DOMParser (peramban/jsdom).
 */

const TAUTAN_AMAN = /^(https?:\/\/|mailto:)[^\s"'<>]+$/i
const TAG_BOLEH = new Set(['P', 'BR', 'STRONG', 'EM', 'UL', 'OL', 'LI', 'A'])

export function adalahTautanAman(url: string): boolean {
  return TAUTAN_AMAN.test(url.trim())
}

function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

function inline(raw: string): string {
  let out = escapeHtml(raw)
  out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_m, teks: string, url: string) => {
    const asli = url.replace(/&amp;/g, '&')
    return adalahTautanAman(asli) ? `<a href="${escapeHtml(asli)}">${teks}</a>` : `${teks} (${url})`
  })
  out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
  out = out.replace(/\*([^*\n]+)\*/g, '<em>$1</em>')
  return out
}

export function markupKeHtml(teks: string): string {
  const blok = teks.replace(/\r\n?/g, '\n').split(/\n{2,}/).map((b) => b.trim()).filter((b) => b !== '')
  return blok
    .map((b) => {
      const baris = b.split('\n')
      if (baris.every((l) => /^- /.test(l))) {
        return `<ul>${baris.map((l) => `<li>${inline(l.slice(2))}</li>`).join('')}</ul>`
      }
      if (baris.every((l) => /^\d+\. /.test(l))) {
        return `<ol>${baris.map((l) => `<li>${inline(l.replace(/^\d+\. /, ''))}</li>`).join('')}</ol>`
      }
      return `<p>${baris.map(inline).join('<br>')}</p>`
    })
    .join('')
}

function ke(node: Node, ctx: { ol: number }): string {
  if (node.nodeType === 3) return node.textContent ?? ''
  if (node.nodeType !== 1) return ''
  const el = node as Element
  const anak = (): string => Array.from(el.childNodes).map((c) => ke(c, ctx)).join('')
  switch (el.tagName) {
    case 'BR':
      return '\n'
    case 'STRONG':
    case 'B':
      return `**${anak()}**`
    case 'EM':
    case 'I':
      return `*${anak()}*`
    case 'A': {
      const href = el.getAttribute('href') ?? ''
      return adalahTautanAman(href) ? `[${anak()}](${href})` : anak()
    }
    case 'P':
    case 'DIV':
      return `${anak()}\n\n`
    case 'UL':
      return `${Array.from(el.children).map((li) => `- ${ke(li, ctx).trim()}`).join('\n')}\n\n`
    case 'OL':
      return `${Array.from(el.children).map((li, i) => `${i + 1}. ${ke(li, ctx).trim()}`).join('\n')}\n\n`
    case 'LI':
      return anak()
    default:
      return anak()
  }
}

/** HTML terbatas (atau HTML bebas hasil impor) → teks penulisan; elemen di luar whitelist dikupas jadi teksnya. */
export function htmlKeMarkup(html: string): string {
  if (html.trim() === '') return ''
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html')
  return ke(doc.body, { ol: 0 }).replace(/\n{3,}/g, '\n\n').trim()
}

function bangun(src: Node, dest: Node, doc: Document): void {
  src.childNodes.forEach((n) => {
    if (n.nodeType === 3) {
      dest.appendChild(doc.createTextNode(n.textContent ?? ''))
      return
    }
    if (n.nodeType !== 1) return
    const el = n as Element
    if (!TAG_BOLEH.has(el.tagName)) {
      if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE') return
      bangun(el, dest, doc) // kupas elemen tak dikenal, pertahankan teks anaknya
      return
    }
    const baru = doc.createElement(el.tagName.toLowerCase())
    if (el.tagName === 'A') {
      const href = el.getAttribute('href') ?? ''
      if (!adalahTautanAman(href)) {
        bangun(el, dest, doc)
        return
      }
      baru.setAttribute('href', href)
    }
    bangun(el, baru, doc)
    dest.appendChild(baru)
  })
}

/** Bersihkan HTML (mis. berita lama hasil impor) ke whitelist; hasil aman untuk ditampilkan dengan v-html. */
export function bersihkanHtml(html: string): string {
  if (html.trim() === '') return ''
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html')
  const wadah = doc.createElement('div')
  bangun(doc.body, wadah, doc)
  return wadah.innerHTML
}
