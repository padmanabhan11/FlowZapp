import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { SessionStore } from './session.store';

/** Signed in, else /auth?next=. Routes by state after sign-in live in S1 (see auth.ts). */
export const authGuard: CanActivateFn = async (_route, state) => {
  const session = inject(SessionStore);
  const router = inject(Router);
  if (!session.loaded()) await session.load();
  if (session.user()) return true;
  return router.createUrlTree(['/auth'], { queryParams: { next: state.url } });
};

/** Needs at least one workspace; otherwise S2. */
export const workspaceGuard: CanActivateFn = async () => {
  const session = inject(SessionStore);
  const router = inject(Router);
  if (!session.loaded()) await session.load();
  return session.workspaces().length > 0 ? true : router.createUrlTree(['/onboarding/workspace']);
};
