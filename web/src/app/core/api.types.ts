export type Role = 'admin' | 'approver' | 'editor' | 'reader' | 'guest';
export const ROLES: Role[] = ['admin', 'approver', 'editor', 'reader', 'guest'];

export interface ApiError {
  error: { code: string; message: string; details?: Record<string, unknown> };
}

export interface Me {
  user: {
    id: string;
    name: string;
    email: string;
    locale: string;
    avatar_path: string | null;
  } | null;
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
  | 'paragraph'
  | 'heading'
  | 'bullet_list'
  | 'numbered_list'
  | 'checklist'
  | 'table'
  | 'code'
  | 'callout'
  | 'image'
  | 'video'
  | 'file'
  | 'divider'
  | 'link';

export interface ListItem {
  text: string;
  checked?: boolean;
  level?: number;
}

export interface Block {
  id: string;
  type: BlockType;
  text?: string | null;
  level?: 1 | 2 | 3;
  /** Plain strings, or objects when an item is checked (checklist) or nested (level 1–3, B6). */
  items?: (string | ListItem)[];
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
  translation_of?: string | null;
  translation_stale?: boolean;
  created_by: string | null;
  source_recording_id: string | null;
  created_at: string;
}

export interface Template {
  id: string;
  name: string;
  doc_type: DocType;
  description: string;
  custom?: boolean;
  created_by?: { id: string; name: string } | null;
  step_count?: number;
}

export type RecordingState =
  | 'pending_upload'
  | 'uploaded'
  | 'transcribing'
  | 'segmenting'
  | 'generating'
  | 'draft_ready'
  | 'failed';

export interface Recording {
  id: string;
  space_id: string | null;
  title: string | null;
  state: RecordingState;
  failed_stage: string | null;
  failure_reason: string | null;
  mime_type: string | null;
  size_bytes: number | null;
  duration_sec: number | null;
  uploaded_by: { id: string; name?: string } | null;
  document_id: string | null;
  created_at: string;
  updated_at: string;
}

export interface UploadTargets {
  recording_id: string;
  upload_id: string;
  part_size: number;
  parts: { part_number: number; url: string }[];
}

export interface VersionMeta {
  id: string;
  version_number: number;
  title: string;
  change_summary: string | null;
  authored_by: string | null;
  approved_by: string | null;
  approved_at: string | null;
  created_at: string;
  is_live: boolean;
}

export interface PublishedDocument {
  id: string;
  title: string;
  doc_type: DocType;
  state: DocState;
  version_number: number;
  approved_at: string | null;
  approved_by: string | null;
  owner: { id: string; name: string } | null;
  review_due_at: string | null;
  language?: string;
  translation_of?: string | null;
  translation_stale?: boolean;
  translation_stale_since?: string | null;
  content: Content;
  steps: Step[];
}

export interface Change {
  kind: 'added' | 'removed' | 'modified' | 'moved';
  type: 'title' | 'section' | 'step' | 'block';
  ref: string;
  id?: string;
  from: unknown;
  to: unknown;
  from_position?: number;
  to_position?: number;
  also_modified?: boolean;
}

export interface ReviewPayload {
  id: string;
  title: string;
  state: DocState;
  doc_type: DocType;
  updated_at: string;
  submitted_by: string | null;
  submitted_at: string | null;
  owner: { id: string; name: string } | null;
  base_version: VersionMeta | null;
  next_version_number: number;
  changes: Change[] | null;
  content: Content;
  steps: Step[];
  blockers: string[];
}

// ---- Retrieval (M3: search, chat, knowledge gaps) ----

export interface Citation {
  n: number;
  document_id: string;
  version_id: string;
  section_ref: string; // "step:3" | "section:purpose" | "block:<id>"
  title: string;
  heading_path: string | null;
  score: number;
}

export interface SearchResult {
  document_id: string;
  title: string;
  doc_type: DocType;
  state: DocState;
  snippet: string;
  section_ref: string;
  owner: { id: string; name: string } | null;
  approved_at: string | null;
  score: number;
}

export interface SearchResponse {
  instant_answer: { text: string; citations: Citation[] } | null;
  results: SearchResult[];
}

export interface ChatSessionMeta {
  id: string;
  title: string | null;
  scope_document_id: string | null;
  created_at: string;
  updated_at: string;
}

export interface ChatMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  citations: Citation[] | null;
  refused: boolean;
  latency_ms: number | null;
  rated_helpful: boolean | null;
  created_at: string;
}

export interface KnowledgeGap {
  question: string;
  count: number;
  last_asked_at: string;
}
