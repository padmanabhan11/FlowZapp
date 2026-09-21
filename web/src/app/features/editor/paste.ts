import { Block, BlockType } from '../../core/api.types';

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
    const chk = /^[-*]\s+\[( |x|X)\]\s+(.*)$/.exec(t);
    if (chk) {
      flushPara(para);
      para = [];
      const items: { text: string; checked: boolean }[] = [];
      while (i < lines.length) {
        const m = /^[-*]\s+\[( |x|X)\]\s+(.*)$/.exec(lines[i].trim());
        if (!m) break;
        items.push({ text: m[2], checked: m[1] !== ' ' });
        i++;
      }
      out.push({ id: newBlockId(), type: 'checklist', items });
      continue;
    }
    if (/^[-*•]\s+/.test(t)) {
      flushPara(para);
      para = [];
      const items: string[] = [];
      while (i < lines.length && /^[-*•]\s+/.test(lines[i].trim())) {
        items.push(lines[i].trim().replace(/^[-*•]\s+/, ''));
        i++;
      }
      out.push({ id: newBlockId(), type: 'bullet_list', items });
      continue;
    }
    if (/^\d+[.)]\s+/.test(t)) {
      flushPara(para);
      para = [];
      const items: string[] = [];
      while (i < lines.length && /^\d+[.)]\s+/.test(lines[i].trim())) {
        items.push(lines[i].trim().replace(/^\d+[.)]\s+/, ''));
        i++;
      }
      out.push({ id: newBlockId(), type: 'numbered_list', items });
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
  const walk = (node: Node): void => {
    if (node.nodeType === Node.TEXT_NODE) {
      const t = (node.textContent ?? '').trim();
      if (t) out.push({ id: newBlockId(), type: 'paragraph', text: t });
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    const el = node as Element;
    const tag = el.tagName.toLowerCase();
    if (['script', 'style', 'meta', 'head'].includes(tag)) return;
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
      const lis = Array.from(el.children).filter((c) => c.tagName.toLowerCase() === 'li');
      const asCheck = lis.length > 0 && lis.every((li) => li.querySelector('input[type=checkbox]'));
      if (asCheck) {
        out.push({
          id: newBlockId(),
          type: 'checklist',
          items: lis.map((li) => ({
            text: text(li),
            checked: (li.querySelector('input[type=checkbox]') as HTMLInputElement).checked,
          })),
        });
      } else {
        out.push({
          id: newBlockId(),
          type: tag === 'ul' ? 'bullet_list' : 'numbered_list',
          items: lis.map(text),
        });
      }
      return;
    }
    if (tag === 'pre') {
      out.push({ id: newBlockId(), type: 'code', text: el.textContent ?? '', language: null });
      return;
    }
    if (tag === 'table') {
      const rows = Array.from(el.querySelectorAll('tr')).map((tr) =>
        Array.from(tr.querySelectorAll('th,td')).map(text),
      );
      if (rows.length) out.push({ id: newBlockId(), type: 'table', rows });
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
