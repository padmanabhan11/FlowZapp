import { MyDocuments } from './my-documents';

describe('MyDocuments (L2-T2)', () => {
  it('says what a document needs in plain words', () => {
    expect(MyDocuments.needText('review_overdue', { acknowledgements_outstanding: 0 })).toBe(
      'Review overdue',
    );
    expect(
      MyDocuments.needText('acknowledgements_outstanding', { acknowledgements_outstanding: 3 }),
    ).toBe('3 still to acknowledge');
  });
});
