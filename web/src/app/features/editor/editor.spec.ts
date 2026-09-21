import { HttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { DocumentFull } from '../../core/api.types';
import { Editor } from './editor';

const doc: DocumentFull = {
  id: '01J00000000000000000000000', space_id: '01J00000000000000000000001', folder_id: null, title: 'Refunds',
  doc_type: 'sop', state: 'draft', owner: null, approved_version_id: null, review_due_at: null, requires_ack: false,
  language: 'en', updated_at: '2026-09-21T04:00:00.000000Z', created_by: null, source_recording_id: null, created_at: '',
  content: { version: 1, purpose: '', scope: '', prerequisites: [], outcome: '', blocks: [] }, steps: [],
};

describe('Editor submit blockers', () => {
  it('names what is missing before submit', async () => {
    const http = { get: () => of({ data: doc }) };
    TestBed.configureTestingModule({ imports: [Editor], providers: [provideRouter([]), { provide: HttpClient, useValue: http }] });
    const fixture = TestBed.createComponent(Editor);
    fixture.componentRef.setInput('id', doc.id);
    await fixture.componentInstance.ngOnInit();
    expect(fixture.componentInstance.submitBlockers()).toEqual(['at least one step', 'a purpose', 'an owner']);
  });
});
