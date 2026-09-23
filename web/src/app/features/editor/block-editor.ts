import {
  CdkDrag,
  CdkDragDrop,
  CdkDragHandle,
  CdkDropList,
  moveItemInArray,
} from '@angular/cdk/drag-drop';
import {
  Component,
  ElementRef,
  effect,
  inject,
  input,
  output,
  signal,
  viewChildren,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ButtonModule } from 'primeng/button';
import { Block, BlockType, DocumentSummary, ListItem } from '../../core/api.types';
import { DocumentApi, EditorApi, LinkTarget } from '../../core/document.api';
import { itemLevel, itemText, makeItem } from '../../core/list-tree';
import { AssetApi } from '../../core/recording.api';
import { blocksFromClipboard, makeBlock, newBlockId } from './paste';

interface MenuItem {
  type: BlockType | 'doc_link';
  label: string;
  hint: string;
  keys: string;
}

const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

/**
 * B1 — structured block editor (F2). Every block type in the content model
 * gets a purpose-built control; there is no rich-text surface and no HTML
 * (non-negotiable 7). "/" on an empty paragraph opens the block menu; paste
 * is parsed into blocks (B6); blocks drag by their handle (B3-T2) or move
 * with the arrows; lists nest with Tab / Shift+Tab. Images and files upload
 * straight to storage and are referenced by asset ID (B1-T3). Links can point
 * to another document (B7); a target that is deleted or not visible to the
 * viewer shows as unavailable — the two are indistinguishable by design.
 */
@Component({
  selector: 'app-block-editor',
  imports: [FormsModule, ButtonModule, CdkDropList, CdkDrag, CdkDragHandle],
  templateUrl: './block-editor.html',
  styleUrl: './block-editor.scss',
})
export class BlockEditor {
  private readonly editorApi = inject(EditorApi);
  private readonly docs = inject(DocumentApi);
  private readonly assets = inject(AssetApi);

  readonly blocks = input.required<Block[]>();
  readonly documentId = input.required<string>();
  readonly changed = output<Block[]>();
  readonly fields = viewChildren<ElementRef<HTMLElement>>('field');

  readonly menuFor = signal<number | null>(null);
  readonly menuFilter = signal('');
  readonly menuIndex = signal(0);

  readonly uploading = signal<Set<string>>(new Set());
  readonly uploadError = signal<string | null>(null);
  readonly assetUrls = signal<Record<string, string>>({});

  readonly links = signal<Record<string, LinkTarget>>({});
  readonly linksResolved = signal(false);
  private lastLinkKey = '';

  readonly pickerFor = signal<string | null>(null);
  readonly pickerQuery = signal('');
  readonly pickerResults = signal<DocumentSummary[]>([]);
  private pickerTimer: ReturnType<typeof setTimeout> | null = null;

  readonly menu: MenuItem[] = [
    { type: 'paragraph', label: 'Paragraph', hint: 'Plain text', keys: 'text p' },
    { type: 'heading', label: 'Heading', hint: 'Section title', keys: 'h1 h2 h3 title' },
    { type: 'bullet_list', label: 'Bullet list', hint: 'Tab to nest', keys: 'ul list' },
    { type: 'numbered_list', label: 'Numbered list', hint: 'Tab to nest', keys: 'ol list' },
    { type: 'checklist', label: 'Checklist', hint: 'Tick boxes', keys: 'todo task check' },
    {
      type: 'callout',
      label: 'Callout',
      hint: 'Info, warning or danger note',
      keys: 'note warning tip',
    },
    { type: 'code', label: 'Code', hint: 'Monospace, exact whitespace', keys: 'pre snippet' },
    { type: 'table', label: 'Table', hint: 'Rows and columns', keys: 'grid' },
    {
      type: 'image',
      label: 'Image',
      hint: 'PNG, JPEG, GIF, WebP up to 10 MB',
      keys: 'picture screenshot img',
    },
    {
      type: 'file',
      label: 'File',
      hint: 'PDF, Office, CSV, ZIP up to 25 MB',
      keys: 'attachment pdf upload',
    },
    {
      type: 'doc_link',
      label: 'Link to document',
      hint: 'Another document in this workspace',
      keys: 'internal page reference',
    },
    { type: 'link', label: 'Web link', hint: 'An https:// address', keys: 'url href' },
    { type: 'divider', label: 'Divider', hint: 'Horizontal rule', keys: 'hr line' },
  ];

  constructor() {
    // Resolve internal link targets whenever the set of linked ids changes.
    effect(() => {
      const ids = [
        ...new Set(
          this.blocks()
            .filter((b) => b.type === 'link' && b.document_id)
            .map((b) => b.document_id as string),
        ),
      ].sort();
      const key = ids.join(',');
      if (key === this.lastLinkKey) return;
      this.lastLinkKey = key;
      void this.resolve(ids);
    });
    // Signed URLs for image/file previews.
    effect(() => {
      for (const b of this.blocks()) {
        if (
          (b.type === 'image' || b.type === 'file') &&
          b.asset_id &&
          !this.assetUrls()[b.asset_id]
        )
          void this.loadAsset(b.asset_id);
      }
    });
  }

  private async resolve(ids: string[]): Promise<void> {
    try {
      const found = await this.editorApi.resolveLinks(ids);
      this.links.set(Object.fromEntries(found.map((t) => [t.id, t])));
    } catch {
      this.links.set({});
    } finally {
      this.linksResolved.set(true);
    }
  }

  private async loadAsset(id: string): Promise<void> {
    try {
      const url = await this.assets.url(id);
      this.assetUrls.update((m) => ({ ...m, [id]: url }));
    } catch {
      /* preview unavailable; the block still saves */
    }
  }

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

  private replaceById(id: string, patch: Partial<Block>): void {
    this.emit(this.blocks().map((b) => (b.id === id ? { ...b, ...patch } : b)));
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

  // ---- lists (flat items with a level; two spaces per level in the textarea) ----
  itemText = itemText;

  itemChecked(item: string | ListItem): boolean {
    return typeof item === 'string' ? false : !!item.checked;
  }

  itemsText(b: Block): string {
    return (b.items ?? []).map((it) => '  '.repeat(itemLevel(it)) + itemText(it)).join('\n');
  }

  setItems(i: number, v: string): void {
    const b = this.blocks()[i];
    const items = v.split('\n').map((line, k) => {
      const lead = /^ */.exec(line)?.[0].length ?? 0;
      const level = Math.min(3, Math.floor(lead / 2));
      const text = line.slice(lead);
      return b.type === 'checklist'
        ? makeItem(text, level, this.itemChecked(b.items?.[k] ?? ''))
        : makeItem(text, level);
    });
    this.replace(i, { items });
  }

  toggleItem(i: number, k: number): void {
    const b = this.blocks()[i];
    const items = (b.items ?? []).map((it, idx) =>
      idx === k ? makeItem(itemText(it), itemLevel(it), !this.itemChecked(it)) : it,
    );
    this.replace(i, { items });
  }

  /** Tab / Shift+Tab on the caret's line nests or un-nests that item. */
  private indentLine(ev: KeyboardEvent, i: number): void {
    const ta = ev.target as HTMLTextAreaElement;
    const pos = ta.selectionStart;
    const before = ta.value.slice(0, pos);
    const lineIdx = before.split('\n').length - 1;
    const lines = ta.value.split('\n');
    const line = lines[lineIdx];
    const lead = /^ */.exec(line)?.[0].length ?? 0;
    let delta = 0;
    if (ev.shiftKey) {
      if (lead >= 2) {
        lines[lineIdx] = line.slice(2);
        delta = -2;
      }
    } else if (lead < 6) {
      const prevLead = lineIdx > 0 ? (/^ */.exec(lines[lineIdx - 1])?.[0].length ?? 0) : -2;
      if (lead <= prevLead) {
        lines[lineIdx] = '  ' + line;
        delta = 2;
      }
    }
    if (!delta) return;
    const next = lines.join('\n');
    ta.value = next;
    ta.selectionStart = ta.selectionEnd = Math.max(0, pos + delta);
    this.setItems(i, next);
  }

  // ---- tables ----
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

  // ---- structure ----
  insertAfter(i: number, type: BlockType): void {
    const next = [...this.blocks()];
    next.splice(i + 1, 0, makeBlock(type));
    this.emit(next);
    this.focus(i + 1);
  }

  convert(i: number, type: MenuItem['type']): void {
    const cur = this.blocks()[i];
    this.menuFor.set(null);
    if (type === 'doc_link') {
      const b: Block = { id: cur.id, type: 'link', document_id: null, text: '' };
      this.emit(this.blocks().map((x, k) => (k === i ? b : x)));
      this.openPicker(cur.id);
      return;
    }
    const b = makeBlock(type);
    b.id = cur.id;
    if (type === 'image' || type === 'file') {
      b.asset_id = null;
      b.text = '';
      this.emit(this.blocks().map((x, k) => (k === i ? b : x)));
      setTimeout(() => this.pickFile(cur.id, type));
      return;
    }
    const text = (cur.text ?? '').replace(/^\//, '');
    if ('text' in b) b.text = text;
    if (b.items && text) b.items = type === 'checklist' ? [{ text, checked: false }] : [text];
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

  /** B3-T2 — drag reorder by handle. */
  drop(ev: CdkDragDrop<Block[]>): void {
    if (ev.previousIndex === ev.currentIndex) return;
    const next = [...this.blocks()];
    moveItemInArray(next, ev.previousIndex, ev.currentIndex);
    this.emit(next);
  }

  append(type: BlockType = 'paragraph'): void {
    this.emit([...this.blocks(), makeBlock(type)]);
    this.focus(this.blocks().length);
  }

  // ---- uploads (B1-T3) ----
  pickFile(blockId: string, kind: 'image' | 'file'): void {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept =
      kind === 'image' ? IMAGE_TYPES.join(',') : '.pdf,.docx,.xlsx,.pptx,.csv,.txt,.zip';
    input.onchange = () => {
      const f = input.files?.[0];
      if (f) void this.uploadInto(blockId, f);
    };
    input.click();
  }

  async uploadInto(blockId: string, file: File): Promise<void> {
    this.uploadError.set(null);
    this.uploading.update((s) => new Set(s).add(blockId));
    try {
      const r = await this.editorApi.upload(this.documentId(), file);
      this.assetUrls.update((m) => ({ ...m, [r.asset_id]: r.url }));
      const isImage = r.kind === 'image';
      this.replaceById(blockId, {
        type: isImage ? 'image' : 'file',
        asset_id: r.asset_id,
        text: isImage ? '' : r.filename,
      });
    } catch (e: unknown) {
      const msg = (e as { error?: { error?: { message?: string } } }).error?.error?.message;
      this.uploadError.set(msg ?? `“${file.name}” could not be uploaded.`);
    } finally {
      this.uploading.update((s) => {
        const n = new Set(s);
        n.delete(blockId);
        return n;
      });
    }
  }

  /** Files dropped anywhere on the editor become image/file blocks after the drop target (or at the end). */
  onFileDrop(ev: DragEvent, i: number | null): void {
    const files = Array.from(ev.dataTransfer?.files ?? []);
    if (!files.length) return;
    ev.preventDefault();
    ev.stopPropagation();
    this.insertFiles(files, i ?? this.blocks().length - 1);
  }

  onDragOver(ev: DragEvent): void {
    if (ev.dataTransfer?.types.includes('Files')) ev.preventDefault();
  }

  private insertFiles(files: File[], after: number): void {
    const next = [...this.blocks()];
    const created: { id: string; file: File }[] = [];
    files.forEach((file, k) => {
      const id = newBlockId();
      next.splice(after + 1 + k, 0, {
        id,
        type: IMAGE_TYPES.includes(file.type) ? 'image' : 'file',
        asset_id: null,
        text: '',
      });
      created.push({ id, file });
    });
    this.emit(next);
    // Upload after the parent has applied the new blocks.
    setTimeout(() => created.forEach((c) => void this.uploadInto(c.id, c.file)));
  }

  // ---- internal link picker (B7-T1) ----
  openPicker(blockId: string): void {
    this.pickerFor.set(blockId);
    this.pickerQuery.set('');
    void this.search('');
    setTimeout(() => (document.querySelector('.be__picker-q') as HTMLInputElement | null)?.focus());
  }

  setPickerQuery(q: string): void {
    this.pickerQuery.set(q);
    if (this.pickerTimer) clearTimeout(this.pickerTimer);
    this.pickerTimer = setTimeout(() => void this.search(q), 250);
  }

  private async search(q: string): Promise<void> {
    try {
      const list = await this.docs.list({ q: q.trim() || undefined });
      this.pickerResults.set(list.filter((d) => d.id !== this.documentId()).slice(0, 12));
    } catch {
      this.pickerResults.set([]);
    }
  }

  chooseTarget(blockId: string, d: DocumentSummary): void {
    this.links.update((m) => ({
      ...m,
      [d.id]: {
        id: d.id,
        title: d.title,
        state: d.state,
        doc_type: d.doc_type,
        published: !!d.approved_version_id,
      },
    }));
    this.replaceById(blockId, { document_id: d.id, text: d.title });
    this.pickerFor.set(null);
  }

  linkState(b: Block): 'ok' | 'unavailable' | 'pending' {
    if (!b.document_id) return 'pending';
    if (this.links()[b.document_id]) return 'ok';
    return this.linksResolved() ? 'unavailable' : 'pending';
  }

  // ---- keyboard & paste ----
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
      ev.key === 'Tab' &&
      (b.type === 'bullet_list' || b.type === 'numbered_list' || b.type === 'checklist')
    ) {
      ev.preventDefault();
      this.indentLine(ev, i);
      return;
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
    const files = Array.from(ev.clipboardData.files ?? []).filter((f) =>
      IMAGE_TYPES.includes(f.type),
    );
    if (files.length) {
      ev.preventDefault();
      this.insertFiles(files, i);
      return;
    }
    const plain = ev.clipboardData.getData('text/plain');
    const html = ev.clipboardData.getData('text/html');
    const multiline = plain.includes('\n') || /<(h[1-6]|ul|ol|table|pre|blockquote)\b/i.test(html);
    if (!multiline || b.type === 'code') return;
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
      case 'image':
      case 'file':
        return !b.asset_id;
      case 'bullet_list':
      case 'numbered_list':
      case 'checklist':
        return (b.items ?? []).every((it) => !itemText(it).trim());
      case 'table':
        return (b.rows ?? []).every((r) => r.every((c) => !c.trim()));
      case 'link':
        return !b.document_id && !(b.text ?? '').trim();
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
