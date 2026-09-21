export type Role = 'admin' | 'approver' | 'editor' | 'reader' | 'guest';
export const ROLES: Role[] = ['admin', 'approver', 'editor', 'reader', 'guest'];

export interface ApiError {
  error: { code: string; message: string; details?: Record<string, unknown> };
}

export interface Me {
  user: { id: string; name: string; email: string; locale: string; avatar_path: string | null } | null;
  workspaces: WorkspaceSummary[];
}

export interface WorkspaceSummary {
  id: string;
  name: string;
  slug: string;
  plan: 'free' | 'pro' | 'team';
  role: Role;
}

export interface Space {
  id: string;
  name: string;
  description: string | null;
  is_handbook: boolean;
}

export interface InviteRow {
  email: string;
  role: Role;
  space_ids?: string[];
}

export interface InviteResult {
  email: string;
  status: 'sent' | 'already_member' | 'already_invited' | 'plan_limit_exceeded';
  message?: string;
  details?: { limit: string; max: number; used: number };
}

export interface Folder {
  id: string;
  space_id: string;
  parent_id: string | null;
  name: string;
  position: number;
  depth: number;
  children?: Folder[];
}
