<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

use CourseForge\Support\HttpException;

/**
 * Splits a submission into pieces the provider will actually accept.
 *
 * By bytes first, with the row count only as a second bound - which is the
 * opposite of what the documentation invites you to do. OpenAI publishes two
 * numbers, 50,000 lines per batch and a 200 MB input file, and the first one is
 * the one that gets quoted. A CourseForge page prompt carries the entire course
 * context with it and runs to something like 8 KB once it is JSON-encoded, so
 * 200 MB is reached at roughly 25,000 rows: the file ceiling binds at half the
 * row ceiling, every time, for this particular workload. A chunker that counted
 * rows would build a legal-looking 50,000-line file, upload it, and be told
 * 413 - after the upload, which for a large course is the expensive half.
 *
 * Measuring is left to the caller because only the adapter knows its own
 * envelope: OpenAI wraps each body in custom_id/method/url, Anthropic in
 * custom_id/params, and the JSON escaping of the prompt itself is worth several
 * percent on text with quotes and accented characters in it. The default
 * estimate encodes the prompt properly and adds a deliberately generous
 * per-row overhead, because guessing high costs one extra chunk and guessing
 * low costs the upload.
 */
final class JsonlChunker
{
    /**
     * Per-row overhead of everything that is not the prompt: the custom id, the
     * method and url fields, the enclosing braces, the model and token
     * parameters and the newline that ends the line.
     */
    public const ENVELOPE_BYTES = 512;

    public function __construct(private readonly BatchLimits $limits)
    {
    }

    /**
     * @param array<int,BatchItemRequest> $items
     * @param null|callable(BatchItemRequest):int $sizer bytes one item costs on the wire
     * @return array<int,array<int,BatchItemRequest>> never empty for a non-empty input
     */
    public function chunk(array $items, ?callable $sizer = null): array
    {
        $items = array_values($items);
        if ($items === []) {
            return [];
        }

        $sizer ??= self::estimate(...);
        $maxBytes = max(1, $this->limits->maxBytes);
        $maxRows = max(1, $this->limits->maxRequests);

        $chunks = [];
        $current = [];
        $bytes = 0;

        foreach ($items as $item) {
            $size = max(1, $sizer($item));

            // One row larger than the whole file limit can never be sent, and
            // splitting further would not help. Saying so here beats a 413 the
            // user reads hours later with no indication of which page caused it.
            if ($size > $maxBytes) {
                throw HttpException::unprocessable(
                    'One page is too large to queue on its own: "' . $item->customId . '" needs about '
                    . number_format($size / 1024) . ' KB and the whole submission may only be '
                    . number_format($maxBytes / BatchLimits::MEGABYTE, 1) . ' MB. '
                    . 'Shorten the course context or that page brief.'
                );
            }

            if ($current !== [] && ($bytes + $size > $maxBytes || count($current) >= $maxRows)) {
                $chunks[] = $current;
                $current = [];
                $bytes = 0;
            }

            $current[] = $item;
            $bytes += $size;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * What one item costs on the wire when the adapter has not said.
     *
     * The prompt is JSON-encoded rather than measured with strlen, because that
     * is what actually goes into the file: a quotation mark becomes two bytes,
     * a newline becomes two, and a course written in German or Arabic escapes
     * further still. The difference is small per page and thousands of pages
     * wide, which is exactly the size of mistake that produces a 413.
     */
    public static function estimate(BatchItemRequest $item): int
    {
        $request = $item->request;

        return strlen($item->customId)
            + strlen($request->model)
            + self::encodedBytes($request->system)
            + self::encodedBytes($request->user)
            + self::ENVELOPE_BYTES;
    }

    private static function encodedBytes(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $encoded = json_encode(
            $text,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $encoded === false ? strlen($text) : strlen($encoded);
    }
}
