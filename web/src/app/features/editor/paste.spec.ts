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
