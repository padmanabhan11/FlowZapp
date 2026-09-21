import { Routes } from '@angular/router';
import { authGuard, workspaceGuard } from './core/auth.guard';

export const routes: Routes = [
  { path: 'auth', loadComponent: () => import('./features/auth/auth').then((m) => m.Auth) },
  {
    path: 'onboarding',
    canActivate: [authGuard],
    children: [
      { path: 'workspace', loadComponent: () => import('./features/onboarding/create-workspace').then((m) => m.CreateWorkspace) },
      { path: 'invite', canActivate: [workspaceGuard], loadComponent: () => import('./features/onboarding/invite-team').then((m) => m.InviteTeam) },
      { path: 'first-recording', canActivate: [workspaceGuard], loadComponent: () => import('./features/onboarding/first-recording').then((m) => m.FirstRecording) },
    ],
  },
  { path: '', pathMatch: 'full', canActivate: [authGuard, workspaceGuard], redirectTo: 'onboarding/first-recording' },
  { path: '**', redirectTo: '' },
];
