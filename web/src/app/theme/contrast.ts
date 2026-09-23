/**
 * M5-T4 — WCAG contrast for the colour tokens. The values mirror styles.scss
 * and the Aura preset; contrast.spec.ts fails the build if a change to either
 * drops a text pairing below AA, so the check is not a one-off visual pass.
 */
export const TOKENS = {
  ink: '#1e2761', // navy chrome: primary text, buttons, links
  inkSoft: '#5b6b7c',
  paper: '#ffffff',
  rail: '#f2f4f6',
  select: '#cadcfc',
  line: '#dde2e7',
  brandGold: '#d9a441',
  stateDraft: '#b8730b',
  stateReview: '#1f5c99',
  stateApproved: '#2f6f4e',
  stateArchived: '#8a94a0',
  pillDark: '#111111', // text on the draft and archived pills
  white: '#ffffff',
} as const;

function luminance(hex: string): number {
  const h = hex.replace('#', '');
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
  const lin = (c: number) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

/** WCAG 2.x contrast ratio between two hex colours (1..21). */
export function contrastRatio(a: string, b: string): number {
  const la = luminance(a);
  const lb = luminance(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}
