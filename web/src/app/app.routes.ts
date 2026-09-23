import { Routes } from '@angular/router';
import { authGuard, workspaceGuard } from './core/auth.guard';

export const routes: Routes = [
  { path: 'auth', loadComponent: () => import('./features/auth/auth').then((m) => m.Auth) },
  {
    path: 'onboarding',
    canActivate: [authGuard],
    children: [
      {
        path: 'workspace',
        loadComponent: () =>
          import('./features/onboarding/create-workspace').then((m) => m.CreateWorkspace),
      },
      {
        path: 'invite',
        canActivate: [workspaceGuard],
        loadComponent: () => import('./features/onboarding/invite-team').then((m) => m.InviteTeam),
      },
      {
        path: 'first-recording',
        canActivate: [workspaceGuard],
        loadComponent: () =>
          import('./features/onboarding/first-recording').then((m) => m.FirstRecording),
      },
    ],
  },
  {
    path: '',
    canActivate: [authGuard, workspaceGuard],
    loadComponent: () => import('./features/shell/shell').then((m) => m.Shell),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'ask' },
      {
        path: 's/:spaceId',
        loadComponent: () => import('./features/spaces/space-browser').then((m) => m.SpaceBrowser),
      },
      {
        path: 's/:spaceId/f/:folderId',
        loadComponent: () => import('./features/spaces/space-browser').then((m) => m.SpaceBrowser),
      },
      {
        path: 'r/:id/draft',
        loadComponent: () => import('./features/review/draft-review').then((m) => m.DraftReview),
      },
      {
        path: 'd/:id/edit',
        loadComponent: () => import('./features/editor/editor').then((m) => m.Editor),
      },
      {
        path: 'd/:id/review',
        loadComponent: () =>
          import('./features/governance/approval-review').then((m) => m.ApprovalReview),
      },
      {
        path: 'd/:id/history',
        loadComponent: () => import('./features/governance/history').then((m) => m.History),
      },
      {
        path: 'd/:id',
        loadComponent: () => import('./features/reader/reader').then((m) => m.Reader),
      },
      { path: 'ask', loadComponent: () => import('./features/chat/ask').then((m) => m.Ask) },
      {
        path: 'my-documents',
        loadComponent: () => import('./features/documents/my-documents').then((m) => m.MyDocuments),
      },
      {
        path: 'search',
        loadComponent: () => import('./features/search/search').then((m) => m.Search),
      },
      {
        path: 'recordings',
        loadComponent: () => import('./features/recordings/recordings').then((m) => m.Recordings),
      },
      {
        path: 'handbook',
        loadComponent: () => import('./features/handbook/handbook').then((m) => m.Handbook),
      },
      {
        path: 'handbook/:docId',
        loadComponent: () => import('./features/handbook/handbook').then((m) => m.Handbook),
      },
      {
        path: 'admin/members',
        loadComponent: () => import('./features/admin/members').then((m) => m.Members),
      },
      {
        path: 'admin/insights',
        loadComponent: () => import('./features/admin/knowledge-gaps').then((m) => m.KnowledgeGaps),
      },
      {
        path: 'admin/acknowledgements',
        loadComponent: () =>
          import('./features/admin/acknowledgements').then((m) => m.Acknowledgements),
      },
      {
        path: 'admin/spaces/:id',
        loadComponent: () =>
          import('./features/admin/space-permissions').then((m) => m.SpacePermissions),
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
