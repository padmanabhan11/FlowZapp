import { errorTracking, TrackingErrorHandler } from './error-tracking';

describe('error tracking (M4-T1)', () => {
  it('queues errors until a tracker attaches, then forwards live', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const handler = new TrackingErrorHandler();
    handler.handleError(new Error('early'));
    const seen: unknown[] = [];
    errorTracking.attach((e) => seen.push(e));
    handler.handleError(new Error('late'));
    expect(seen.map((e) => (e as Error).message)).toEqual(['early', 'late']);
    spy.mockRestore();
  });
});
