import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { SessionStore } from './session.store';

export type RewriteScope = 'document' | 'steps' | 'blocks' | 'selection';
export interface RewriteProposal {
  original: Record<string, string>;
  proposed: Record<string, string>;
  changed: string[];
  cost_usd: number;
}
export interface TranslationResult {
  id: string;
  title: string;
  language: string;
  translation_of: string;
  state: string;
}

/** FR-8xx: every call returns a proposal; applying it is the client's PATCH (FR-805). Translation creates a linked draft. */
@Injectable({ providedIn: 'root' })
export class AiApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async rewrite(
    documentId: string,
    scope: RewriteScope,
    opts: { keys?: string[]; text?: string } = {},
  ): Promise<RewriteProposal> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: RewriteProposal }>('/api/v1/ai/rewrite', {
          document_id: documentId,
          scope,
          ...opts,
        }),
      )
    ).data;
  }

  async translate(documentId: string, targetLanguage: string): Promise<TranslationResult> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: TranslationResult }>('/api/v1/ai/translate', {
          document_id: documentId,
          target_language: targetLanguage,
        }),
      )
    ).data;
  }

  async suggestTitle(documentId: string): Promise<string[]> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: { titles: string[] } }>('/api/v1/ai/suggest-title', {
          document_id: documentId,
        }),
      )
    ).data.titles;
  }
}
