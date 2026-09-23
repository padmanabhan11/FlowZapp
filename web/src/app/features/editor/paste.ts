import { Block, BlockType, ListItem } from '../../core/api.types';
import { makeItem } from '../../core/list-tree';

let seq = 0;
export function newBlockId(): string {
  return `b${Date.now().toString(36)}${(seq++).toString(36)}`;
}

export function makeBlock(type: BlockType): Block {
  const b: Block = { id: newBlockId(), type };
  switch (type) {
    case 'heading':
      b.text = '';
      b.level = 2;
      break;
    case 'bullet_list':
    case 'numbered_list':
      b.items = [''];
      break;
    case 'checklist':
      b.items = [{ text: '', checked: false }];
      break;
    case 'callout':
      b.text = '';
      b.variant = 'info';
      break;
    case 'code':
      b.text = '';
      b.language = null;
      break;
    case 'table':
      b.rows = [
        ['', ''],
        ['', ''],
      ];
      break;
    case 'divider':
      break;
    default:
      b.text = '';
  }
  return b;
}

/**
 * Paste fidelity (F2 / B1): plain text becomes the blocks a person would have
 * typed — headings, bullet and numbered lists, checklists, code fences, and
 * paragraphs split on blank lines. HTML (from Word, Docs, Confluence) is
 * walked structurally so lists stay lists and tables stay tables; inline
 * formatting is dropped on purpose — the document model is structured JSON,
 * never HTML (non-negotiable 7).
 */
interface Marker {
  kind: 'bullet_list' | 'numbered_list' | 'checklist';
  indent: number;
  text: string;
  checked?: boolean;
}

/** Recognises "- x", "* x", "• x", "1. x", "1) x", "- [ ] x" with their indentation (a tab counts as two spaces). */
export function listMarker(line: string): Marker | null {
  const lead = /^[ \t]*/.exec(line)?.[0] ?? '';
  const indent = lead.replace(/\t/g, '  ').length;
  const t = line.slice(lead.length);
  let m = /^[-*]\s+\[( |x|X)\]\s+(.*)$/.exec(t);
  if (m) return { kind: 'checklist', indent, text: m[2].trim(), checked: m[1] !== ' ' };
  m = /^[-*•◦▪]\s+(.*)$/.exec(t);
  if (m) return { kind: 'bullet_list', indent, text: m[1].trim() };
  m = /^(\d+|[a-z])[.)]\s+(.*)$/.exec(t);
  if (m) return { kind: 'numbered_list', indent, text: m[2].trim() };
  return null;
}

export function blocksFromPlainText(text: string): Block[] {
  const lines = text.replace(/\r\n?/g, '\n').split('\n');
  const out: Block[] = [];
  let i = 0;
  const flushPara = (buf: string[]) => {
    const t = buf.join(' ').trim();
    if (t) out.push({ id: newBlockId(), type: 'paragraph', text: t });
  };
  let para: string[] = [];
  while (i < lines.length) {
    const line = lines[i];
    const t = line.trim();
    if (t === '') {
      flushPara(para);
      para = [];
      i++;
      continue;
    }
    if (t.startsWith('```')) {
      flushPara(para);
      para = [];
      const lang = t.slice(3).trim() || null;
      const code: string[] = [];
      i++;
      while (i < lines.length && !lines[i].trim().startsWith('```')) code.push(lines[i++]);
      i++;
      out.push({ id: newBlockId(), type: 'code', text: code.join('\n'), language: lang });
      continue;
    }
    const h = /^(#{1,3})\s+(.+)$/.exec(t);
    if (h) {
      flushPara(para);
      para = [];
      out.push({ id: newBlockId(), type: 'heading', text: h[2], level: h[1].length as 1 | 2 | 3 });
      i++;
      continue;
    }
    if (/^---+$|^\*\*\*+$/.test(t)) {
      flushPara(para);
      para = [];
      out.push({ id: newBlockId(), type: 'divider' });
      i++;
      continue;
    }
    const marker = listMarker(line);
    if (marker) {
      flushPara(para);
      para = [];
      const kind = marker.kind;
      const items: (string | ListItem)[] = [];
      const base = marker.indent;
      while (i < lines.length) {
        const m = listMarker(lines[i]);
        if (!m) break;
        // A different marker at the top level starts a new list; nested items join this one.
        if (m.indent <= base && m.kind !== kind) break;
        const level = Math.max(0, Math.min(3, Math.floor((m.indent - base) / 2)));
        items.push(
          kind === 'checklist'
            ? makeItem(m.text, level, m.checked ?? false)
            : makeItem(m.text, level),
        );
        i++;
      }
      out.push({ id: newBlockId(), type: kind, items });
      continue;
    }
    if (/^(>|NOTE:|WARNING:|CAUTION:)/i.test(t)) {
      flushPara(para);
      para = [];
      const variant = /^(WARNING|CAUTION)/i.test(t) ? 'warning' : 'info';
      out.push({
        id: newBlockId(),
        type: 'callout',
        text: t.replace(/^(>\s?|NOTE:\s*|WARNING:\s*|CAUTION:\s*)/i, ''),
        variant,
      });
      i++;
      continue;
    }
    if (t.includes('\t') && i + 1 < lines.length && lines[i + 1].includes('\t')) {
      flushPara(para);
      para = [];
      const rows: string[][] = [];
      while (i < lines.length && lines[i].includes('\t')) {
        rows.push(lines[i].split('\t').map((c) => c.trim()));
        i++;
      }
      out.push({ id: newBlockId(), type: 'table', rows });
      continue;
    }
    para.push(t);
    i++;
  }
  flushPara(para);
  return out;
}

export function blocksFromHtml(html: string): Block[] {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const out: Block[] = [];
  const text = (el: Element): string => (el.textContent ?? '').replace(/\s+/g, ' ').trim();
  const consumed = new Set<Element>();
  /** An li's own text, without the text of lists nested inside it. */
  const ownText = (li: Element): string => {
    const clone = li.cloneNode(true) as Element;
    clone.querySelectorAll('ul, ol').forEach((n) => n.remove());
    return text(clone);
  };
  /**
   * Walks a list into flat levelled items. Handles Notion/HTML (ul inside li),
   * Google Docs (ul placed directly inside ul, and aria-level on li) and
   * checkboxes. Deeper than three levels is clamped to three.
   */
  const collectList = (
    list: Element,
    level: number,
    items: (string | ListItem)[],
    check: boolean,
  ): void => {
    for (const child of Array.from(list.children)) {
      const tag = child.tagName.toLowerCase();
      if (tag === 'ul' || tag === 'ol') {
        collectList(child, level + 1, items, check);
        continue;
      }
      if (tag !== 'li') continue;
      const aria = Number(child.getAttribute('aria-level') ?? 0);
      const lvl = aria > 0 ? aria - 1 : level;
      const box = child.querySelector(
        ':scope > input[type=checkbox], :scope > label > input[type=checkbox]',
      ) as HTMLInputElement | null;
      const t = ownText(child);
      if (t) items.push(check ? makeItem(t, lvl, !!box?.checked) : makeItem(t, lvl));
      child
        .querySelectorAll(':scope > ul, :scope > ol')
        .forEach((nested) => collectList(nested, lvl + 1, items, check));
    }
  };
  const walk = (node: Node): void => {
    if (node.nodeType === Node.TEXT_NODE) {
      const t = (node.textContent ?? '').trim();
      if (t) out.push({ id: newBlockId(), type: 'paragraph', text: t });
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    const el = node as Element;
    const tag = el.tagName.toLowerCase();
    if (['script', 'style', 'meta', 'head'].includes(tag) || consumed.has(el)) return;
    if (/^h[1-6]$/.test(tag)) {
      out.push({
        id: newBlockId(),
        type: 'heading',
        text: text(el),
        level: Math.min(3, Number(tag[1])) as 1 | 2 | 3,
      });
      return;
    }
    if (tag === 'ul' || tag === 'ol') {
      const items: (string | ListItem)[] = [];
      const isCheck = !!el.querySelector(':scope > li input[type=checkbox]');
      collectList(el, 0, items, isCheck);
      out.push({
        id: newBlockId(),
        type: isCheck ? 'checklist' : tag === 'ul' ? 'bullet_list' : 'numbered_list',
        items,
      });
      return;
    }
    // Word: list paragraphs carry mso-list:lN levelN and a bullet/number in an mso-list:Ignore span.
    if (tag === 'p' && /mso-list:\s*l\d+\s+level\d/i.test(el.getAttribute('style') ?? '')) {
      const items: (string | ListItem)[] = [];
      let numbered = false;
      let cur: Element | null = el;
      while (
        cur &&
        cur.tagName.toLowerCase() === 'p' &&
        /mso-list:\s*l\d+\s+level(\d)/i.test(cur.getAttribute('style') ?? '')
      ) {
        const level = Number(/level(\d)/i.exec(cur.getAttribute('style') ?? '')![1]) - 1;
        const ignore = cur.querySelector('[style*="mso-list:Ignore"], [style*="mso-list: Ignore"]');
        if (ignore && /\d|[a-z][.)]/i.test(ignore.textContent ?? '') && items.length === 0)
          numbered = true;
        const clone = cur.cloneNode(true) as Element;
        clone
          .querySelectorAll('[style*="mso-list:Ignore"], [style*="mso-list: Ignore"]')
          .forEach((n) => n.remove());
        items.push(makeItem(text(clone), level));
        consumed.add(cur);
        cur = cur.nextElementSibling;
      }
      out.push({ id: newBlockId(), type: numbered ? 'numbered_list' : 'bullet_list', items });
      return;
    }
    if (tag === 'pre') {
      out.push({ id: newBlockId(), type: 'code', text: el.textContent ?? '', language: null });
      return;
    }
    if (tag === 'table') {
      const rows = Array.from(el.querySelectorAll('tr')).map((tr) =>
        Array.from(tr.querySelectorAll(':scope > th, :scope > td')).flatMap((c) => [
          text(c),
          ...Array(Math.max(0, Number(c.getAttribute('colspan') ?? 1) - 1)).fill(''),
        ]),
      );
      const width = Math.max(0, ...rows.map((r) => r.length));
      const square = rows
        .filter((r) => r.length)
        .map((r) => [...r, ...Array(width - r.length).fill('')]);
      if (square.length) out.push({ id: newBlockId(), type: 'table', rows: square });
      return;
    }
    if (tag === 'hr') {
      out.push({ id: newBlockId(), type: 'divider' });
      return;
    }
    if (tag === 'blockquote') {
      out.push({ id: newBlockId(), type: 'callout', text: text(el), variant: 'info' });
      return;
    }
    if (
      tag === 'p' ||
      tag === 'li' ||
      (tag === 'div' && !el.querySelector('p,ul,ol,table,pre,h1,h2,h3,h4,h5,h6,div'))
    ) {
      const t = text(el);
      if (t) out.push({ id: newBlockId(), type: 'paragraph', text: t });
      return;
    }
    el.childNodes.forEach(walk);
  };
  doc.body.childNodes.forEach(walk);
  return out;
}

export function blocksFromClipboard(data: DataTransfer): Block[] {
  const html = data.getData('text/html');
  const plain = data.getData('text/plain');
  if (html && /<(h[1-6]|ul|ol|table|pre|p|blockquote)\b/i.test(html)) {
    const blocks = blocksFromHtml(html);
    if (blocks.length) return blocks;
  }
  return blocksFromPlainText(plain);
}
