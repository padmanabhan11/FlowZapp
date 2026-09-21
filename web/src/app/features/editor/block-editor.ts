import { Component, ElementRef, input, output, signal, viewChildren } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ButtonModule } from 'primeng/button';
import { Block, BlockType } from '../../core/api.types';
import { blocksFromClipboard, makeBlock } from './paste';

interface MenuItem {
  type: BlockType;
  label: string;
  hint: string;
  keys: string;
}

/**
 * B1 — structured block editor (F2). Every block type in the content model
 * gets a purpose-built control; there is no rich-text surface and no HTML
 * (non-negotiable 7). "/" on an empty paragraph opens the block menu; paste
 * is parsed into blocks; ↑/↓ move, ⌫ on an empty block removes it. Media
 * blocks (image, video, file) reference assets and are inserted from the
 * recording's frames in S9 rather than typed here; they render read-only.
 */
@Component({
  selector: 'app-block-editor',
  imports: [FormsModule, ButtonModule],
  templateUrl: './block-editor.html',
  styleUrl: './block-editor.scss',
})
export class BlockEditor {
  readonly blocks = input.required<Block[]>();
  readonly changed = output<Block[]>();
  readonly fields = viewChildren<ElementRef<HTMLElement>>('field');

  readonly menuFor = signal<number | null>(null);
  readonly menuFilter = signal('');
  readonly menuIndex = signal(0);

  readonly menu: MenuItem[] = [
    { type: 'paragraph', label: 'Paragraph', hint: 'Plain text', keys: 'text p' },
    { type: 'heading', label: 'Heading', hint: 'Section title', keys: 'h1 h2 h3 title' },
    { type: 'bullet_list', label: 'Bullet list', hint: 'Unordered items', keys: 'ul list' },
    { type: 'numbered_list', label: 'Numbered list', hint: 'Ordered items', keys: 'ol list' },
    { type: 'checklist', label: 'Checklist', hint: 'Tick boxes', keys: 'todo task check' },
    {
      type: 'callout',
      label: 'Callout',
      hint: 'Info, warning or danger note',
      keys: 'note warning tip',
    },
    { type: 'code', label: 'Code', hint: 'Monospace, exact whitespace', keys: 'pre snippet' },
    { type: 'table', label: 'Table', hint: 'Rows and columns', keys: 'grid' },
    { type: 'divider', label: 'Divider', hint: 'Horizontal rule', keys: 'hr line' },
  ];

  filteredMenu(): MenuItem[] {
    const f = this.menuFilter().toLowerCase();
    return this.menu.filter((m) => !f || m.label.toLowerCase().includes(f) || m.keys.includes(f));
  }

  private emit(next: Block[]): void {
    this.changed.emit(next);
  }

  private replace(i: number, patch: Partial<Block>): void {
    this.emit(this.blocks().map((b, k) => (k === i ? { ...b, ...patch } : b)));
  }

  setText(i: number, v: string): void {
    const b = this.blocks()[i];
    if (b.type === 'paragraph' && v.startsWith('/') && !v.includes('\n')) {
      this.menuFor.set(i);
      this.menuFilter.set(v.slice(1));
      this.menuIndex.set(0);
    } else if (this.menuFor() === i) {
      this.menuFor.set(null);
    }
    this.replace(i, { text: v });
  }

  setLevel(i: number, level: 1 | 2 | 3): void {
    this.replace(i, { level });
  }

  setVariant(i: number, variant: 'info' | 'warning' | 'danger'): void {
    this.replace(i, { variant });
  }

  setLanguage(i: number, language: string): void {
    this.replace(i, { language: language || null });
  }

  itemText(item: string | { text: string; checked?: boolean }): string {
    return typeof item === 'string' ? item : item.text;
  }

  itemChecked(item: string | { text: string; checked?: boolean }): boolean {
    return typeof item === 'string' ? false : !!item.checked;
  }

  setItems(i: number, v: string): void {
    const b = this.blocks()[i];
    const lines = v.split('\n');
    const items =
      b.type === 'checklist'
        ? lines.map((t, k) => ({ text: t, checked: this.itemChecked(b.items?.[k] ?? '') }))
        : lines;
    this.replace(i, { items });
  }

  toggleItem(i: number, k: number): void {
    const b = this.blocks()[i];
    const items = (b.items ?? []).map((it, idx) =>
      idx === k ? { text: this.itemText(it), checked: !this.itemChecked(it) } : it,
    );
    this.replace(i, { items });
  }

  setCell(i: number, r: number, c: number, v: string): void {
    const rows = (this.blocks()[i].rows ?? []).map((row, ri) =>
      ri === r ? row.map((cell, ci) => (ci === c ? v : cell)) : row,
    );
    this.replace(i, { rows });
  }

  addRow(i: number): void {
    const rows = this.blocks()[i].rows ?? [[]];
    this.replace(i, { rows: [...rows, rows[0].map(() => '')] });
  }

  addCol(i: number): void {
    this.replace(i, { rows: (this.blocks()[i].rows ?? []).map((r) => [...r, '']) });
  }

  removeRow(i: number, r: number): void {
    const rows = this.blocks()[i].rows ?? [];
    if (rows.length <= 1) return;
    this.replace(i, { rows: rows.filter((_, k) => k !== r) });
  }

  itemsText(b: Block): string {
    return (b.items ?? []).map((it) => this.itemText(it)).join('\n');
  }

  insertAfter(i: number, type: BlockType): void {
    const next = [...this.blocks()];
    next.splice(i + 1, 0, makeBlock(type));
    this.emit(next);
    this.focus(i + 1);
  }

  convert(i: number, type: BlockType): void {
    const cur = this.blocks()[i];
    const b = makeBlock(type);
    b.id = cur.id;
    const text = (cur.text ?? '').replace(/^\//, '');
    if ('text' in b) b.text = text;
    if (b.items && text) b.items = type === 'checklist' ? [{ text, checked: false }] : [text];
    this.menuFor.set(null);
    this.emit(this.blocks().map((x, k) => (k === i ? b : x)));
    this.focus(i);
  }

  remove(i: number): void {
    this.emit(this.blocks().filter((_, k) => k !== i));
    this.focus(Math.max(0, i - 1));
  }

  move(i: number, d: -1 | 1): void {
    const next = [...this.blocks()];
    const j = i + d;
    if (j < 0 || j >= next.length) return;
    [next[i], next[j]] = [next[j], next[i]];
    this.emit(next);
    this.focus(j);
  }

  append(type: BlockType = 'paragraph'): void {
    this.emit([...this.blocks(), makeBlock(type)]);
    this.focus(this.blocks().length);
  }

  /** Keyboard: slash-menu navigation, Enter on paragraph → new paragraph, Backspace on empty → remove. */
  onKey(ev: KeyboardEvent, i: number): void {
    const b = this.blocks()[i];
    if (this.menuFor() === i) {
      const items = this.filteredMenu();
      if (ev.key === 'ArrowDown') {
        ev.preventDefault();
        this.menuIndex.set((this.menuIndex() + 1) % items.length);
        return;
      }
      if (ev.key === 'ArrowUp') {
        ev.preventDefault();
        this.menuIndex.set((this.menuIndex() - 1 + items.length) % items.length);
        return;
      }
      if (ev.key === 'Enter' && items.length) {
        ev.preventDefault();
        this.convert(i, items[this.menuIndex()].type);
        return;
      }
      if (ev.key === 'Escape') {
        this.menuFor.set(null);
        return;
      }
    }
    if (
      ev.key === 'Enter' &&
      !ev.shiftKey &&
      (b.type === 'paragraph' || b.type === 'heading' || b.type === 'callout')
    ) {
      ev.preventDefault();
      this.insertAfter(i, 'paragraph');
      return;
    }
    if (ev.key === 'Backspace' && this.isEmpty(b) && this.blocks().length > 1) {
      ev.preventDefault();
      this.remove(i);
    }
  }

  onPaste(ev: ClipboardEvent, i: number): void {
    if (!ev.clipboardData) return;
    const b = this.blocks()[i];
    const plain = ev.clipboardData.getData('text/plain');
    const html = ev.clipboardData.getData('text/html');
    const multiline = plain.includes('\n') || /<(h[1-6]|ul|ol|table|pre|blockquote)\b/i.test(html);
    if (!multiline || b.type === 'code') return; // let the browser paste inline text
    ev.preventDefault();
    const parsed = blocksFromClipboard(ev.clipboardData);
    if (!parsed.length) return;
    const next = [...this.blocks()];
    if (this.isEmpty(b)) next.splice(i, 1, ...parsed);
    else next.splice(i + 1, 0, ...parsed);
    this.emit(next);
  }

  isEmpty(b: Block): boolean {
    switch (b.type) {
      case 'divider':
        return true;
      case 'bullet_list':
      case 'numbered_list':
      case 'checklist':
        return (b.items ?? []).every((it) => !this.itemText(it).trim());
      case 'table':
        return (b.rows ?? []).every((r) => r.every((c) => !c.trim()));
      default:
        return !(b.text ?? '').trim();
    }
  }

  autosize(el: EventTarget | null): void {
    const t = el as HTMLTextAreaElement | null;
    if (!t) return;
    t.style.height = 'auto';
    t.style.height = `${t.scrollHeight}px`;
  }

  private focus(i: number): void {
    setTimeout(() => this.fields()[i]?.nativeElement.focus());
  }

  label(type: BlockType): string {
    return this.menu.find((m) => m.type === type)?.label ?? type;
  }
}
