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
  {
    path: '',
    canActivate: [authGuard, workspaceGuard],
    loadComponent: () => import('./features/shell/shell').then((m) => m.Shell),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'ask' },
      { path: 's/:spaceId', loadComponent: () => import('./features/spaces/space-browser').then((m) => m.SpaceBrowser) },
      { path: 's/:spaceId/f/:folderId', loadComponent: () => import('./features/spaces/space-browser').then((m) => m.SpaceBrowser) },
      { path: 'd/:id/edit', loadComponent: () => import('./features/editor/editor').then((m) => m.Editor) },
      { path: 'd/:id', redirectTo: 'd/:id/edit' },
      { path: 'ask', loadComponent: () => import('./features/shell/placeholder').then((m) => m.Placeholder), data: { title: 'Ask', milestone: 'M3' } },
      { path: 'search', loadComponent: () => import('./features/shell/placeholder').then((m) => m.Placeholder), data: { title: 'Search', milestone: 'M3' } },
      { path: 'recordings', loadComponent: () => import('./features/shell/placeholder').then((m) => m.Placeholder), data: { title: 'Recordings', milestone: 'M1' } },
      { path: 'handbook', loadComponent: () => import('./features/shell/placeholder').then((m) => m.Placeholder), data: { title: 'Handbook', milestone: 'M4' } },
      { path: 'admin/members', loadComponent: () => import('./features/shell/placeholder').then((m) => m.Placeholder), data: { title: 'Members & roles', milestone: 'M2' } },
    ],
  },
  { path: '**', redirectTo: '' },
];
