import { DraftBackup } from './draft-backup';

class MemoryStore {
  data = new Map<string, string>();
  getItem(k: string) {
    return this.data.get(k) ?? null;
  }
  setItem(k: string, v: string) {
    this.data.set(k, v);
  }
  removeItem(k: string) {
    this.data.delete(k);
  }
}

describe('DraftBackup (B2-T3 tab-close recovery)', () => {
  it('accumulates edits on the same base and offers them back after a "tab close"', () => {
    const store = new MemoryStore();
    const a = new DraftBackup(store);
    a.write('d1', 't0', { title: 'New title' });
    a.write('d1', 't0', { content: { purpose: 'Why' } });
    a.write('d1', 't0', { content: { scope: 'Who' } });

    // A fresh editor instance (the reopened tab) on the same storage.
    const b = new DraftBackup(store);
    const e = b.read('d1');
    expect(e?.title).toBe('New title');
    expect(e?.content).toEqual({ purpose: 'Why', scope: 'Who' });
    expect(DraftBackup.recovery(e, 't0')).toBe('restore');
  });

  it('reports a conflict instead of restoring when the server copy moved since', () => {
    const b = new DraftBackup(new MemoryStore());
    b.write('d1', 't0', { content: { purpose: 'Mine' } });
    expect(DraftBackup.recovery(b.read('d1'), 't1')).toBe('conflict');
  });

  it('a new base drops edits that were already saved', () => {
    const b = new DraftBackup(new MemoryStore());
    b.write('d1', 't0', { title: 'Saved already' });
    b.write('d1', 't1', { content: { outcome: 'Later' } });
    const e = b.read('d1');
    expect(e?.title).toBeUndefined();
    expect(e?.content).toEqual({ outcome: 'Later' });
  });

  it('clear removes it, and no storage is harmless', () => {
    const store = new MemoryStore();
    const b = new DraftBackup(store);
    b.write('d1', 't0', { title: 'x' });
    b.clear('d1');
    expect(b.read('d1')).toBeNull();
    const none = new DraftBackup(null);
    none.write('d1', 't0', { title: 'x' });
    expect(none.read('d1')).toBeNull();
    expect(DraftBackup.recovery(null, 't0')).toBe('none');
  });
});
