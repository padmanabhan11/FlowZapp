<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Ai\FakeLlm;
use App\Pipeline\DraftWriter;
use App\Pipeline\PipelineFailed;
use Tests\TestCase;

/** D4-T2: long transcripts are condensed per segment before generation; short ones are not. */
final class DraftWriterTest extends TestCase
{
    /** Records every call so the test can see what generation was given. */
    private function llm(): FakeLlm
    {
        return new class extends FakeLlm
        {
            /** @var list<array{system:string,user:string}> */
            public array $calls = [];

            public function complete(string $system, string $user, int $maxTokens = 4096, float $temperature = 0.0): array
            {
                $this->calls[] = ['system' => $system, 'user' => $user];

                return ['cost_usd' => 0.01] + parent::complete($system, $user, $maxTokens, $temperature);
            }
        };
    }

    /**
     * @return list<array{w:string,start:float,end:float}>
     */
    private function words(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['w' => "w{$i}", 'start' => (float) $i, 'end' => $i + 0.5];
        }

        return $out;
    }

    /** @var list<array{position:int,ts_start:float,ts_end:float,summary:?string}> */
    private array $segments = [
        ['position' => 1, 'ts_start' => 0.0, 'ts_end' => 100.0, 'summary' => 'Open'],
        ['position' => 2, 'ts_start' => 100.0, 'ts_end' => 200.0, 'summary' => 'Export'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FakeLlm::$responses = [
            '[digest]' => (string) json_encode(['segments' => [['position' => 1, 'digest' => 'Open Reports > Monthly; pick "March".']]]),
            '[generate]' => (string) json_encode(['title' => 'T', 'steps' => [['segment' => 1, 'instruction' => 'Open the report.']]]),
        ];
    }

    public function test_short_transcripts_go_to_generation_whole(): void
    {
        $llm = $this->llm();
        $res = (new DraftWriter($llm))->write('Report', $this->segments, $this->words(200));

        $this->assertFalse($res['condensed']);
        $this->assertCount(1, $llm->calls);
        $this->assertStringContainsString('w150', $llm->calls[0]['user']);
        $this->assertSame('Open the report.', $res['draft']['steps'][0]['instruction']);
    }

    public function test_long_transcripts_are_digested_per_segment_and_missing_digests_keep_the_raw_words(): void
    {
        config(['flowzapp.pipeline.digest_over_chars' => 500, 'flowzapp.pipeline.digest_batch_chars' => 100000]);
        $llm = $this->llm();
        $res = (new DraftWriter($llm))->write('Report', $this->segments, $this->words(200));

        $this->assertTrue($res['condensed']);
        $this->assertSame(2, $res['calls'], 'one digest batch, one generation');
        $this->assertStringContainsString('[digest]', $llm->calls[0]['system']);
        $generate = $llm->calls[1]['user'];
        $this->assertStringContainsString('(segment 1) Open Reports > Monthly; pick "March".', $generate);
        $this->assertStringNotContainsString('w50 ', $generate, 'segment 1 raw words replaced by its digest');
        $this->assertStringContainsString('(segment 2) w100 w101', $generate, 'no digest for segment 2: raw words kept');
        $this->assertSame(0.02, $res['cost_usd']);
    }

    public function test_digest_batches_split_on_size(): void
    {
        config(['flowzapp.pipeline.digest_over_chars' => 500, 'flowzapp.pipeline.digest_batch_chars' => 300]);
        $res = (new DraftWriter($this->llm()))->write('Report', $this->segments, $this->words(200));
        $this->assertSame(3, $res['calls'], 'two digest batches, one generation');
    }

    public function test_segment_texts_cover_every_word_once(): void
    {
        $texts = DraftWriter::segmentTexts($this->segments, $this->words(250));
        $count = array_sum(array_map(fn ($t) => count(explode(' ', $t['text'])), $texts));
        $this->assertSame(250, $count, 'words after the last segment attach to it');
    }

    public function test_unusable_output_fails_without_a_partial_draft(): void
    {
        FakeLlm::$responses['[generate]'] = '{"title":"T","steps":[]}';
        $this->expectException(PipelineFailed::class);
        (new DraftWriter(new FakeLlm))->write('Report', $this->segments, $this->words(20));
    }
}
