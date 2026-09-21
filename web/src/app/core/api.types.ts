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

export type DocType = 'sop' | 'policy' | 'handbook_page' | 'note';
export type DocState = 'draft' | 'in_review' | 'approved' | 'archived';

export type BlockType =
  | 'paragraph' | 'heading' | 'bullet_list' | 'numbered_list' | 'checklist' | 'table'
  | 'code' | 'callout' | 'image' | 'video' | 'file' | 'divider' | 'link';

export interface Block {
  id: string;
  type: BlockType;
  text?: string | null;
  level?: 1 | 2 | 3;
  items?: (string | { text: string; checked?: boolean })[];
  variant?: 'info' | 'warning' | 'danger';
  asset_id?: string | null;
  document_id?: string | null;
  rows?: string[][];
  language?: string | null;
}

export interface Content {
  version: 1;
  purpose: string;
  scope: string;
  prerequisites: string[];
  outcome: string;
  blocks: Block[];
}

export interface Step {
  id: string;
  position: number;
  instruction: string;
  note: string | null;
  expected_result: string | null;
  is_critical: boolean;
  is_checkpoint: boolean;
  media_asset_id: string | null;
  source_ts_start: number | null;
  source_ts_end: number | null;
  verified_at: string | null;
}

export interface DocumentSummary {
  id: string;
  space_id: string;
  folder_id: string | null;
  title: string;
  doc_type: DocType;
  state: DocState;
  owner: { id: string; name: string } | null;
  approved_version_id: string | null;
  review_due_at: string | null;
  requires_ack: boolean;
  language: string;
  updated_at: string;
}

export interface DocumentFull extends DocumentSummary {
  content: Content;
  steps: Step[];
  created_by: string | null;
  source_recording_id: string | null;
  created_at: string;
}

export interface Template {
  id: string;
  name: string;
  doc_type: DocType;
  description: string;
}
