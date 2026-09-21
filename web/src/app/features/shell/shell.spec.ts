import { Shell } from './shell';

describe('Shell.isQuestion', () => {
  it('routes question-shaped input to Ask and keywords to Search', () => {
    expect(Shell.isQuestion('how do we refund after 30 days')).toBe(true);
    expect(Shell.isQuestion('refund policy?')).toBe(true);
    expect(Shell.isQuestion('refund policy')).toBe(false);
    expect(Shell.isQuestion('Client onboarding checklist')).toBe(false);
  });
});
