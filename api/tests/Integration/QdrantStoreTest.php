<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Retrieval\QdrantStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G0-T2 — QdrantStore against a real Qdrant (the version in
 * infra/qdrant/docker-compose.yml). Opt-in: runs only when QDRANT_TEST_URL is
 * set, e.g.
 *
 *   docker run -d -p 6333:6333 -e QDRANT__SERVICE__API_KEY=testkey qdrant/qdrant:v1.19.1
 *   QDRANT_TEST_URL=http://127.0.0.1:6333 QDRANT_TEST_KEY=testkey php artisan test tests/Integration
 *
 * Proves the payload filters the FakeVectorStore imitates behave the same on
 * the real engine: workspace isolation, state, space scope, folder grant and
 * deny, single document and document set.
 */
final class QdrantStoreTest extends TestCase
{
    private QdrantStore $store;

    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();
        $url = (string) getenv('QDRANT_TEST_URL');
        if ($url === '') {
            $this->markTestSkipped('QDRANT_TEST_URL not set');
        }
        $this->collection = 'fz_test_'.Str::lower(Str::random(8));
        $this->store = new QdrantStore($url, getenv('QDRANT_TEST_KEY') ?: null, $this->collection, 4);
        $this->store->ensureCollection();
        $this->store->ensureCollection();   // idempotent
    }

    protected function tearDown(): void
    {
        if (isset($this->collection) && getenv('QDRANT_TEST_URL')) {
            Http::withHeaders(['api-key' => (string) getenv('QDRANT_TEST_KEY')])
                ->delete(rtrim((string) getenv('QDRANT_TEST_URL'), '/')."/collections/{$this->collection}");
        }
        parent::tearDown();
    }

    /**
     * @param  list<float>  $v
     * @return array{id: string, vector: list<float>, payload: array<string, mixed>}
     */
    private function point(string $id, array $v, string $space, ?string $folder, string $doc, string $state = 'approved'): array
    {
        return ['id' => $id, 'vector' => $v, 'payload' => ['space_id' => $space, 'folder_id' => $folder, 'document_id' => $doc, 'version_id' => 'v', 'section_ref' => 'step:1', 'state' => $state]];
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return list<string>
     */
    private function ids(string $ws, array $filter): array
    {
        $hits = $this->store->search($ws, [1.0, 0.0, 0.0, 0.0], $filter + ['space_ids' => []], 10);
        $ids = array_column($hits, 'id');
        sort($ids);

        return $ids;
    }

    public function test_filters_isolate_workspaces_spaces_folders_and_documents(): void
    {
        $this->store->upsert('WS_A', [
            $this->point('c1', [1, 0, 0, 0], 'S1', null, 'D1'),
            $this->point('c2', [0.9, 0.1, 0, 0], 'S1', 'F_DENY', 'D2'),
            $this->point('c3', [0.8, 0.2, 0, 0], 'S2', 'F_GRANT', 'D3'),
            $this->point('c4', [0.7, 0.3, 0, 0], 'S1', null, 'D4', 'draft'),
        ]);
        $this->store->upsert('WS_B', [$this->point('c9', [1, 0, 0, 0], 'S1', null, 'D9')]);

        $this->assertSame(['c1', 'c2'], $this->ids('WS_A', ['space_ids' => ['S1']]), 'space scope; drafts never returned');
        $this->assertSame(['c9'], $this->ids('WS_B', ['space_ids' => ['S1']]), 'workspace isolation');
        $this->assertSame(['c1'], $this->ids('WS_A', ['space_ids' => ['S1'], 'deny_folder_ids' => ['F_DENY']]));
        $this->assertSame(['c1', 'c2', 'c3'], $this->ids('WS_A', ['space_ids' => ['S1'], 'grant_folder_ids' => ['F_GRANT']]), 'folder grant outside member spaces');
        $this->assertSame(['c2'], $this->ids('WS_A', ['space_ids' => ['S1'], 'document_id' => 'D2']));
        $this->assertSame(['c1', 'c3'], $this->ids('WS_A', ['space_ids' => ['S1', 'S2'], 'document_ids' => ['D1', 'D3', 'D9']]), 'document set filter');
        $this->assertSame([], $this->ids('WS_A', ['space_ids' => ['S1'], 'document_ids' => []]), 'an empty document set matches nothing');

        $this->store->delete('WS_A', ['c1']);
        $this->store->deleteByDocument('WS_A', 'D2');
        $this->assertSame([], $this->ids('WS_A', ['space_ids' => ['S1']]));
        $this->assertSame(['c9'], $this->ids('WS_B', ['space_ids' => ['S1']]), 'deletes are scoped to the workspace');
    }
}
