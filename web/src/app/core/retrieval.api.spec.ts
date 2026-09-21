import { Citation } from './api.types';
import { ChatApi, citationFragment } from './retrieval.api';

describe('citationFragment', () => {
  it('maps section refs to reader anchors', () => {
    expect(citationFragment({ section_ref: 'step:3' })).toBe('step-3');
    expect(citationFragment({ section_ref: 'section:purpose' })).toBe('section-purpose');
    expect(citationFragment({ section_ref: 'block:01HXYZ' })).toBe('block-01HXYZ');
    expect(citationFragment({ section_ref: 'unknown' })).toBeUndefined();
  });
});

describe('ChatApi.dispatch', () => {
  it('routes retrieval, token and done frames', () => {
    const seen: string[] = [];
    let citations: Citation[] = [];
    const h = {
      onRetrieval: (c: Citation[]) => {
        citations = c;
        seen.push('retrieval');
      },
      onToken: (t: string) => seen.push(`token:${t}`),
      onDone: (d: { refused: boolean }) => seen.push(`done:${d.refused}`),
    };
    ChatApi.dispatch(
      'event: retrieval\ndata: {"citations":[{"n":1,"document_id":"d","version_id":"v","section_ref":"step:1","title":"T","heading_path":null,"score":0.9}]}',
      h,
    );
    ChatApi.dispatch('event: token\ndata: {"text":"Hello "}', h);
    ChatApi.dispatch(
      'event: done\ndata: {"message_id":"m","refused":false,"latency_ms":12,"citations":[]}',
      h,
    );
    ChatApi.dispatch('', h);
    expect(seen).toEqual(['retrieval', 'token:Hello ', 'done:false']);
    expect(citations[0].section_ref).toBe('step:1');
  });
});
