<?php
declare(strict_types=1);

/**
 * The chunker splits by bytes, and only then by row count.
 *
 * The design brief works the arithmetic out and the answer is not what the
 * documentation invites you to assume. OpenAI publishes two numbers - 50,000
 * lines per batch and a 200 MB input file - and the row count is the one that
 * gets quoted. A CourseForge page prompt carries the whole course context with
 * it and runs to roughly 8 KB once it is JSON-encoded, so 200 MB is reached at
 * something like 25,000 rows: the byte ceiling binds at half the row ceiling
 * for this workload, every time.
 *
 * A chunker that counted rows would therefore build a legal-looking 50,000-line
 * file, upload it - which for a large course is the expensive half - and be told
 * 413 afterwards. These tests pin the order of the two bounds and the
 * arithmetic the brief relies on.
 */

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchLimits;
use CourseForge\Ai\Batch\JsonlChunker;

/** @return array<int,BatchItemRequest> */
function coursePrompts(int $count, int $userBytes = 7000): array
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = new BatchItemRequest(
            'cf-page-' . $i,
            new AiRequest('some-model', 'You write course pages.', str_repeat('x', $userBytes))
        );
    }
    return $items;
}

test('a 200 MB ceiling binds at about 25,000 course prompts, well under the 50,000-row cap', static function (): void {
    $limits = new BatchLimits(50000, 200 * BatchLimits::MEGABYTE);
    $chunks = (new JsonlChunker($limits))->chunk(coursePrompts(30000), static fn(): int => 8192);

    ok(count($chunks) > 1, 'thirty thousand 8 KB prompts must not fit in one 200 MB file');
    $first = count($chunks[0]);
    ok($first < 50000, 'the first chunk must be cut by bytes, not by the row cap - got ' . $first . ' rows');
    ok(
        $first > 20000 && $first < 30000,
        'the byte ceiling should bind near 25,000 rows, got ' . $first
    );
});

test('the row cap still applies when the rows are small', static function (): void {
    $limits = new BatchLimits(10, 200 * BatchLimits::MEGABYTE);
    $chunks = (new JsonlChunker($limits))->chunk(coursePrompts(25, 10), static fn(): int => 64);

    same(3, count($chunks), 'twenty-five rows at ten per chunk');
    same(10, count($chunks[0]), 'the first chunk fills to the row cap');
    same(5, count($chunks[2]), 'the last chunk holds the remainder');
});

test('bytes win over rows when both could apply', static function (): void {
    // Room for a hundred rows, but only four of these fit in the file.
    $limits = new BatchLimits(100, 4096);
    $chunks = (new JsonlChunker($limits))->chunk(coursePrompts(12), static fn(): int => 1024);

    same(3, count($chunks), 'twelve rows of 1 KB into a 4 KB file');
    same(4, count($chunks[0]), 'four rows to a chunk');
});

test('one row larger than the whole file limit is refused by name', static function (): void {
    $limits = new BatchLimits(50000, 4096);
    $chunker = new JsonlChunker($limits);

    $e = raises(
        static fn(): array => $chunker->chunk(coursePrompts(3), static fn(BatchItemRequest $i): int
            => $i->customId === 'cf-page-2' ? 99999 : 100),
        'a single oversized page'
    );
    ok(str_contains($e->getMessage(), 'cf-page-2'), 'the message must name the page, got: ' . $e->getMessage());
});

test('the default estimate measures the JSON-encoded prompt, not its raw length', static function (): void {
    $plain = new BatchItemRequest('cf-page-1', new AiRequest('m', '', 'aaaa'));
    $quoted = new BatchItemRequest('cf-page-1', new AiRequest('m', '', "a\"a\na"));

    ok(
        JsonlChunker::estimate($quoted) > JsonlChunker::estimate($plain),
        'escaping costs bytes on the wire and the estimate has to know it'
    );
    ok(
        JsonlChunker::estimate($plain) >= JsonlChunker::ENVELOPE_BYTES,
        'every row carries the custom id, the method, the url and the braces'
    );
});

test('nothing in produces nothing out', static function (): void {
    same([], (new JsonlChunker(BatchLimits::conservative()))->chunk([]), 'an empty submission');
});
