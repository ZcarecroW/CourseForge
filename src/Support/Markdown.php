<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * Minimal Markdown → HTML converter.
 *
 * Only used for book and chapter *descriptions*, which BookStack stores as
 * HTML. Page bodies are handed to BookStack as Markdown and never touched.
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
        if ($text === '') {
            return '';
        }

        $html = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $block = trim($block);
            if ($block !== '') {
                $html .= '<p>' . self::inline($block) . '</p>';
            }
        }
        return $html;
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/u', '<a href="$2">$1</a>', $text) ?? $text;
        return nl2br($text, false);
    }

    /**
     * Splits Markdown into alternating prose and verbatim segments.
     *
     * Fenced code blocks and inline code spans must never be rewritten by the
     * auto-linker, so every transformation runs over the prose segments only.
     *
     * @return array<int,array{code:bool,text:string}>
     */
    public static function segments(string $markdown): array
    {
        $segments = [];
        $buffer = '';
        $fence = null;

        // Split *after* each newline so the original line endings – and the
        // presence or absence of a trailing one – survive reassembly byte for byte.
        // A fence is closed by a run of the same character at least as long as
        // the one that opened it, which is how CommonMark lets a four-backtick
        // block quote a three-backtick example inside it. Matching on the
        // character alone closed the outer block at the inner example, and the
        // rest of the block was then prose - which the typography pass and the
        // auto-linker are free to rewrite.
        foreach (preg_split('/(?<=\n)/', $markdown, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                if ($fence === null) {
                    if ($buffer !== '') {
                        $segments[] = ['code' => false, 'text' => $buffer];
                    }
                    $fence = $m[1];
                    $buffer = $line;
                    continue;
                }
                if ($m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence)) {
                    $segments[] = ['code' => true, 'text' => $buffer . $line];
                    $buffer = '';
                    $fence = null;
                    continue;
                }
            }
            $buffer .= $line;
        }
        if ($buffer !== '') {
            $segments[] = ['code' => $fence !== null, 'text' => $buffer];
        }

        // Split the prose segments once more on inline code spans.
        $out = [];
        foreach ($segments as $segment) {
            if ($segment['code']) {
                $out[] = $segment;
                continue;
            }
            $parts = preg_split('/(`+[^`\n]*`+)/u', $segment['text'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            foreach ($parts as $i => $part) {
                if ($part !== '') {
                    $out[] = ['code' => $i % 2 === 1, 'text' => $part];
                }
            }
        }
        return $out;
    }

    /**
     * Applies $transform to every prose segment and reassembles the document.
     * With an identity transform the result is byte-identical to the input.
     */
    public static function mapProse(string $markdown, callable $transform): string
    {
        $out = '';
        foreach (self::segments($markdown) as $segment) {
            $out .= $segment['code'] ? $segment['text'] : $transform($segment['text']);
        }
        return $out;
    }

    /**
     * Removes a redundant leading heading from a generated page.
     * A level-1 heading always goes (BookStack owns the page title); a level-2
     * heading only when it merely repeats that title.
     */
    public static function stripLeadingHeading(string $markdown, string $pageTitle = ''): string
    {
        $markdown = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
        $markdown = preg_replace('/^\s*```(?:markdown|md)?\s*\n(.*)\n```\s*$/s', '$1', $markdown) ?? $markdown;
        $markdown = ltrim($markdown, "\n ");

        $markdown = preg_replace('/^#\s+[^\n]*\n+/u', '', $markdown, 1) ?? $markdown;       // ATX  H1
        $markdown = preg_replace('/^[^\n]+\n={3,}\s*\n+/u', '', $markdown, 1) ?? $markdown; // Setext H1

        if ($pageTitle !== '' && preg_match('/^##\s+([^\n]*)\n+/u', $markdown, $m) === 1) {
            $heading = Text::key($m[1]);
            if ($heading !== '' && $heading === Text::key($pageTitle)) {
                $markdown = substr($markdown, strlen($m[0]));
            }
        }
        return trim($markdown);
    }
}
