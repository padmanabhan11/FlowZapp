import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';
import { BUILD_ENV } from './env.generated';
import { errorTracking } from './app/core/error-tracking';

// M4-T1: error tracking with release tagging. The SDK is loaded only when a
// DSN is baked in (scripts/write-env.mjs), so local builds and the initial
// bundle stay free of it; errors raised before it loads are kept and flushed.
if (BUILD_ENV.sentryDsn) {
  void import('@sentry/angular').then((Sentry) => {
    Sentry.init({
      dsn: BUILD_ENV.sentryDsn,
      release: BUILD_ENV.release,
      environment: BUILD_ENV.environment,
      sendDefaultPii: false,
      tracesSampleRate: 0.1,
    });
    errorTracking.attach((e) => Sentry.captureException(e));
  });
}

bootstrapApplication(App, appConfig).catch((err) => console.error(err));
