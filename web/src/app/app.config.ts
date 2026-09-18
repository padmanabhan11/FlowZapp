import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { providePrimeNG } from 'primeng/config';

import { routes } from './app.routes';
import { apiInterceptor } from './core/api.interceptor';
import { FlowZappPreset } from './theme/flowzapp-preset';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(withInterceptors([apiInterceptor])),
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
