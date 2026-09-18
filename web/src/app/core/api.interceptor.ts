import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { WorkspaceStore } from './workspace.store';

/**
 * Attaches the Sanctum session (cookies) and the X-Workspace-Id header to every
 * /api call, and routes 401 to sign-in (03-Technical-Architecture §8).
 */
export const apiInterceptor: HttpInterceptorFn = (req, next) => {
  const workspace = inject(WorkspaceStore);
  const router = inject(Router);

  if (!req.url.startsWith('/api')) {
    return next(req);
  }

  const wsId = workspace.id();
  const prepared = req.clone({
    withCredentials: true,
    setHeaders: {
      Accept: 'application/json',
      ...(wsId ? { 'X-Workspace-Id': wsId } : {}),
    },
  });

  return next(prepared).pipe(
    catchError((err: unknown) => {
      if (err instanceof HttpErrorResponse && err.status === 401) {
        void router.navigate(['/auth'], { queryParams: { next: router.url } });
      }
      return throwError(() => err);
    }),
  );
};
