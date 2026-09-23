import { ApplicationConfig, ErrorHandler, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideHttpClient, withInterceptors, withXsrfConfiguration } from '@angular/common/http';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { providePrimeNG } from 'primeng/config';

import { routes } from './app.routes';
import { TrackingErrorHandler } from './core/error-tracking';
import { apiInterceptor } from './core/api.interceptor';
import { FlowZappPreset } from './theme/flowzapp-preset';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    { provide: ErrorHandler, useClass: TrackingErrorHandler }, // M4-T1
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(
      withInterceptors([apiInterceptor]),
      withXsrfConfiguration({ cookieName: 'XSRF-TOKEN', headerName: 'X-XSRF-TOKEN' }),
    ),
    providePrimeNG({
      ripple: false, // motion only where it shows what changed (doc 10)
      theme: {
        preset: FlowZappPreset,
        options: {
          darkModeSelector: false, // light only in v1; state colours are tuned for paper
          cssLayer: { name: 'primeng', order: 'primeng, app' },
        },
      },
    }),
  ],
};
