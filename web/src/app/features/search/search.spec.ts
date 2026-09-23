import { Search } from './search';

describe('Search filters (G4-T1)', () => {
  it('turns an approval period into a UTC date', () => {
    const now = new Date('2026-09-23T10:00:00Z');
    expect(Search.daysAgo(null, now)).toBeNull();
    expect(Search.daysAgo(30, now)).toBe('2026-08-24');
    expect(Search.daysAgo(365, now)).toBe('2025-09-23');
  });
});
