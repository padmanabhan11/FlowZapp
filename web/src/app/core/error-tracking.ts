import { ErrorHandler, Injectable } from '@angular/core';

/**
 * M4-T1: forwards uncaught errors to whichever tracker attaches (Sentry,
 * loaded lazily in main.ts). Errors raised before it attaches are queued
 * (last 20) and flushed, so a crash on first paint is not lost.
 */
class ErrorTracking {
  private sink: ((e: unknown) => void) | null = null;
  private readonly queue: unknown[] = [];

  attach(sink: (e: unknown) => void): void {
    this.sink = sink;
    for (const e of this.queue.splice(0)) sink(e);
  }

  report(e: unknown): void {
    if (this.sink) this.sink(e);
    else if (this.queue.length < 20) this.queue.push(e);
  }
}

export const errorTracking = new ErrorTracking();

@Injectable()
export class TrackingErrorHandler implements ErrorHandler {
  handleError(error: unknown): void {
    console.error(error);
    errorTracking.report(error);
  }
}
