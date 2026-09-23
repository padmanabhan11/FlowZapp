import { toTree } from '../../core/list-tree';
import { blocksFromHtml, blocksFromPlainText } from './paste';

describe('paste fidelity', () => {
  it('turns plain text into headings, lists, checklists, code and paragraphs', () => {
    const b = blocksFromPlainText(
      [
        '# Refunds',
        'Only for orders under 30 days.',
        '',
        '- Open the order',
        '- Click refund',
        '1. Confirm',
        '2. Email',
        '- [ ] Log it',
        '- [x] Done',
        '```sh',
        'echo hi',
        '```',
        'Second para line one',
        'line two',
      ].join('\n'),
    );
    expect(b.map((x) => x.type)).toEqual([
      'heading',
      'paragraph',
      'bullet_list',
      'numbered_list',
      'checklist',
      'code',
      'paragraph',
    ]);
    expect(b[0].level).toBe(1);
    expect(b[2].items).toEqual(['Open the order', 'Click refund']);
    expect(b[4].items).toEqual([
      { text: 'Log it', checked: false },
      { text: 'Done', checked: true },
    ]);
    expect(b[5].language).toBe('sh');
    expect(b[6].text).toBe('Second para line one line two');
  });

  it('keeps structure from HTML and drops inline formatting', () => {
    const b = blocksFromHtml(
      '<h2>Scope</h2><p>Applies to <b>all</b> staff.</p><ul><li>One</li><li>Two</li></ul><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table><blockquote>Careful</blockquote>',
    );
    expect(b.map((x) => x.type)).toEqual([
      'heading',
      'paragraph',
      'bullet_list',
      'table',
      'callout',
    ]);
    expect(b[1].text).toBe('Applies to all staff.');
    expect(b[3].rows).toEqual([
      ['A', 'B'],
      ['1', '2'],
    ]);
  });
});

describe('nested list and table fidelity across sources (B6-T2)', () => {
  const expected = [
    'Prepare',
    { text: 'Collect receipts', level: 1 },
    { text: 'Scan them', level: 2 },
    'Submit',
  ];

  it('plain text indentation (spaces or tabs), mixed markers nest under the outer list', () => {
    const b = blocksFromPlainText(
      ['- Prepare', '  - Collect receipts', '\t\t1. Scan them', '- Submit'].join('\n'),
    );
    expect(b).toHaveLength(1);
    expect(b[0].type).toBe('bullet_list');
    expect(b[0].items).toEqual(expected);
  });

  it('Notion / standard HTML: ul inside li', () => {
    const b = blocksFromHtml(
      '<ul><li>Prepare<ul><li>Collect receipts<ul><li>Scan them</li></ul></li></ul></li><li>Submit</li></ul>',
    );
    expect(b).toHaveLength(1);
    expect(b[0].items).toEqual(expected);
  });

  it('Google Docs: wrapper <b id=docs-internal-guid>, ul directly inside ul, aria-level', () => {
    const html =
      '<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-1"><ol style="margin:0"><li aria-level="1" dir="ltr"><p dir="ltr"><span>Prepare</span></p></li><ol><li aria-level="2"><p><span>Collect receipts</span></p></li><li aria-level="3"><p><span>Scan them</span></p></li></ol><li aria-level="1"><p><span>Submit</span></p></li></ol></b>';
    const b = blocksFromHtml(html);
    expect(b).toHaveLength(1);
    expect(b[0].type).toBe('numbered_list');
    expect(b[0].items).toEqual(expected);
  });

  it('Word: mso-list paragraphs with Ignore spans become one list', () => {
    const p = (lvl: number, bullet: string, t: string) =>
      `<p class="MsoListParagraph" style="mso-list:l0 level${lvl} lfo1"><span style="mso-list:Ignore">${bullet}<span>&nbsp;</span></span>${t}</p>`;
    const b = blocksFromHtml(
      p(1, '1.', 'Prepare') +
        p(2, 'a.', 'Collect receipts') +
        p(3, 'i.', 'Scan them') +
        p(1, '2.', 'Submit') +
        '<p>After</p>',
    );
    expect(b.map((x) => x.type)).toEqual(['numbered_list', 'paragraph']);
    expect(b[0].items).toEqual(expected);
  });

  it('nested checklists keep their ticks', () => {
    const b = blocksFromHtml(
      '<ul><li><input type="checkbox" checked> Parent<ul><li><input type="checkbox"> Child</li></ul></li></ul>',
    );
    expect(b[0].type).toBe('checklist');
    expect(b[0].items).toEqual([
      { text: 'Parent', checked: true },
      { text: 'Child', checked: false, level: 1 },
    ]);
  });

  it('tables: ragged rows are padded and colspan keeps columns aligned', () => {
    const b = blocksFromHtml(
      '<table><tbody><tr><td colspan="2"><p><span>Heading</span></p></td><td>C</td></tr><tr><td>1</td></tr></tbody></table>',
    );
    expect(b[0].rows).toEqual([
      ['Heading', '', 'C'],
      ['1', '', ''],
    ]);
  });

  it('toTree nests flat levelled items and clamps level jumps', () => {
    const t = toTree(['A', { text: 'B', level: 2 }, 'C']);
    expect(t.map((n) => n.text)).toEqual(['A', 'C']);
    expect(t[0].children.map((n) => n.text)).toEqual(['B']);
  });
});
