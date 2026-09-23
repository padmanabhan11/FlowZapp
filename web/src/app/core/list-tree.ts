import { ListItem } from './api.types';

export interface ListNode {
  text: string;
  checked?: boolean;
  children: ListNode[];
}

export function itemText(i: string | ListItem): string {
  return typeof i === 'string' ? i : i.text;
}

export function itemLevel(i: string | ListItem): number {
  return typeof i === 'string' ? 0 : Math.max(0, Math.min(3, i.level ?? 0));
}

/** A string when the item is a plain top-level entry; an object only when it needs to be (checked or nested). */
export function makeItem(text: string, level = 0, checked?: boolean): string | ListItem {
  if (level <= 0 && checked === undefined) return text;
  const o: ListItem = { text };
  if (checked !== undefined) o.checked = checked;
  if (level > 0) o.level = Math.min(3, level);
  return o;
}

/**
 * Flat levelled items → a tree for rendering (B6). A level that jumps more
 * than one deeper than its parent is clamped, so malformed pastes still nest
 * sensibly instead of disappearing.
 */
export function toTree(items: (string | ListItem)[]): ListNode[] {
  const root: ListNode[] = [];
  const stack: { level: number; node: ListNode }[] = [];
  for (const it of items) {
    const node: ListNode = { text: itemText(it), children: [] };
    if (typeof it !== 'string' && it.checked !== undefined) node.checked = it.checked;
    let level = itemLevel(it);
    while (stack.length && stack[stack.length - 1].level >= level) stack.pop();
    if (stack.length) level = Math.min(level, stack[stack.length - 1].level + 1);
    else level = 0;
    (stack.length ? stack[stack.length - 1].node.children : root).push(node);
    stack.push({ level, node });
  }
  return root;
}
