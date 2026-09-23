import { TOKENS, contrastRatio } from './contrast';

/**
 * M5-T4 — the state pills and the navy chrome must read at AA. Text pairs need
 * 4.5:1 (pill text is small and bold, so the large-text 3:1 allowance does not
 * apply); adjacent non-text colours need 3:1 (WCAG 1.4.11).
 */
describe('colour contrast (M5-T4)', () => {
  const aa = 4.5;
  const nonText = 3;

  it('state pill text meets AA on every state fill', () => {
    expect(contrastRatio(TOKENS.white, TOKENS.stateReview)).toBeGreaterThanOrEqual(aa);
    expect(contrastRatio(TOKENS.white, TOKENS.stateApproved)).toBeGreaterThanOrEqual(aa);
    expect(contrastRatio(TOKENS.pillDark, TOKENS.stateDraft)).toBeGreaterThanOrEqual(aa);
    expect(contrastRatio(TOKENS.pillDark, TOKENS.stateArchived)).toBeGreaterThanOrEqual(aa);
    // and the reason the dark text exists: white would fail on amber and grey
    expect(contrastRatio(TOKENS.white, TOKENS.stateDraft)).toBeLessThan(aa);
    expect(contrastRatio(TOKENS.white, TOKENS.stateArchived)).toBeLessThan(aa);
  });

  it('every pill fill stands out from the document canvas', () => {
    // Against paper every fill clears 3:1 (archived grey just: 3.08). On the rail the grey
    // fill is 2.8:1 — acceptable only because every pill carries its word (non-negotiable 8),
    // so colour is never the sole cue; the text on it is what must, and does, meet AA.
    for (const fill of [
      TOKENS.stateDraft,
      TOKENS.stateReview,
      TOKENS.stateApproved,
      TOKENS.stateArchived,
    ]) {
      expect(contrastRatio(fill, TOKENS.paper)).toBeGreaterThanOrEqual(nonText);
    }
  });

  it('review blue is never placed directly on navy chrome', () => {
    // 2.0:1 — below the 3:1 non-text minimum, which is why pills live on paper and rail, never on navy.
    expect(contrastRatio(TOKENS.stateReview, TOKENS.ink)).toBeLessThan(nonText);
    expect(contrastRatio(TOKENS.stateReview, TOKENS.paper)).toBeGreaterThanOrEqual(aa);
  });

  it('chrome text meets AA on paper, rail and selection', () => {
    expect(contrastRatio(TOKENS.ink, TOKENS.paper)).toBeGreaterThanOrEqual(aa);
    expect(contrastRatio(TOKENS.ink, TOKENS.rail)).toBeGreaterThanOrEqual(aa);
    expect(contrastRatio(TOKENS.ink, TOKENS.select)).toBeGreaterThanOrEqual(aa);
    expect(contrastRatio(TOKENS.inkSoft, TOKENS.paper)).toBeGreaterThanOrEqual(aa);
    expect(contrastRatio(TOKENS.white, TOKENS.ink)).toBeGreaterThanOrEqual(aa); // primary buttons
  });
});
