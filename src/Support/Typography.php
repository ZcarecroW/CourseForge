<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * Punctuation the way the language actually sets it.
 *
 * A model writes German and reaches for the American keyboard: `„Wort"` - the
 * opening mark right and the closing one a straight typewriter quote, because
 * the two are one keystroke apart in the training data and nothing downstream
 * ever complained. The same hand writes `...` where an ellipsis belongs and a
 * hyphen where a dash does. None of it is wrong enough to notice while reading
 * one page and all of it is wrong on every page of a five-hundred-page book,
 * which is exactly the kind of error worth fixing with code rather than with a
 * sentence in a prompt: a rule that runs is worth more than a rule that is
 * asked for.
 *
 * Three properties hold this together.
 *
 * **It never touches code.** Everything that is not prose - a fenced block, an
 * inline span, a link target, a URL, an HTML tag, a formula, and the two markers
 * CourseForge writes into a page - is cut out first and put back untouched.
 * Turning a straight quote into a curly one inside a JSON sample is not a
 * typographic improvement, it is a broken example, and a course about a
 * programming language is mostly examples.
 *
 * **It decides open from close by position, not by counting.** A quote after a
 * space opens, a quote after a word closes. That is what makes the mixed pair
 * the models actually produce - a correct opening mark and a straight closing
 * one - come out right, where an alternating counter would have to have seen
 * the whole document and would drift for the rest of it after one stray
 * apostrophe.
 *
 * **It is idempotent.** Every rule reads a state that is already correct as a
 * match for itself and rewrites it to what it already was, so a page run
 * through twice - regenerated, re-imported, published and pulled back - is
 * byte-for-byte the page run through once. Anything else would be a diff that
 * appears out of nowhere on a page nobody edited.
 */
final class Typography
{
    /**
     * Opening and closing marks per language: double, then single, then the
     * space that belongs *inside* the double pair.
     *
     * French sets a narrow no-break space inside its guillemets; German, English
     * and the rest set nothing. Anything not listed here is set the English way,
     * which is also what the straight-quote-to-curly-quote rule alone would do.
     */
    private const QUOTES = [
        'de' => ['open' => '„', 'close' => '“', 'sopen' => '‚', 'sclose' => '‘', 'inner' => ''],
        'fr' => ['open' => '«', 'close' => '»', 'sopen' => '‹', 'sclose' => '›', 'inner' => "\u{202F}"],
        'es' => ['open' => '«', 'close' => '»', 'sopen' => '“', 'sclose' => '”', 'inner' => ''],
        'it' => ['open' => '«', 'close' => '»', 'sopen' => '“', 'sclose' => '”', 'inner' => ''],
        'en' => ['open' => '“', 'close' => '”', 'sopen' => '‘', 'sclose' => '’', 'inner' => ''],
    ];

    /**
     * What a profile's language field might say, for the four languages that
     * set their quotation marks differently from English.
     *
     * The field is free text - a course may be written in a language nobody
     * thought to list - so this is a recogniser rather than a validator, and
     * everything it does not recognise is set the English way. A two-letter code
     * only ever matches exactly: `es` is Spanish and `Estonian` is not.
     */
    private const NAMES = [
        'de' => ['de', 'german', 'deutsch', 'österreichisch', 'schweizerdeutsch'],
        'fr' => ['fr', 'french', 'français', 'francais', 'französisch'],
        'es' => ['es', 'spanish', 'español', 'espanol', 'castellano', 'spanisch'],
        'it' => ['it', 'italian', 'italiano', 'italienisch'],
    ];

    /**
     * Everything that is not prose, longest construct first.
     *
     * Order is the whole of the correctness here: a fence has to be recognised
     * before the inline-code rule can find the first pair of backticks inside
     * it, and a link has to be recognised before a bare URL can match half of
     * its target. The last alternative catches a fence that is never closed,
     * which would otherwise leave the rest of the document being treated as
     * prose by a rule that had already decided it was code.
     */
    private const SKIP = '/(?:'
        . '```[\s\S]*?```'                      // a fenced block
        . '|~~~[\s\S]*?~~~'
        . '|`[^`\n]*`'                          // an inline span
        . '|\$\$[\s\S]*?\$\$'                   // display maths
        . '|\\\\\([\s\S]*?\\\\\)'               // \( ... \) inline maths
        . '|\\\\\[[\s\S]*?\\\\\]'               // \[ ... \] display maths
        . '|!?\[[^\]\n]*\]\([^)\n]*\)'          // a link or an image, target and all
        . '|<[^>\n\s][^>\n]*>'                  // an HTML tag or an autolink
        . '|\{\{[^}\n]*\}\}'                    // a cloze deletion or a prompt placeholder
        . '|\(\x{1F517}[^)\n]*\)'               // a cross-reference marker
        . '|(?:https?|mailto):\S+'              // a bare address
        . '|^\s*\|[\s:|-]+\|\s*$'               // a table's alignment row
        . '|```[\s\S]*$'                        // a fence nobody closed
        . ')/mux';

    /** Every double-quote-ish character, whatever language wrote it. */
    private const DOUBLES = '"\x{201C}\x{201D}\x{201E}\x{201F}\x{00AB}\x{00BB}';

    /** Every single-quote-ish character. Apostrophes are dealt with before this is used. */
    private const SINGLES = "'\x{2018}\x{2019}\x{201A}\x{201B}\x{2039}\x{203A}";

    /** The spaces that may already sit inside a quotation mark or before French punctuation. */
    private const THIN = ' \t\x{00A0}\x{202F}\x{2009}';

    /** What may stand immediately before an opening quotation mark. */
    private const BEFORE_OPEN = '\s(\[\{\x{2013}\x{2014}';

    /**
     * What quoted text may begin with, which is the other half of telling an
     * opening mark from a closing one.
     *
     * Position alone stops being enough as soon as a language sets a space
     * inside its quotation marks: in `mot » :` the closing guillemet has a
     * space on its left and a colon on its right, and a rule that asked only
     * "is there a space before it" read it as opening the next phrase. Quoted
     * text begins with a word, never with the punctuation that ends one.
     */
    private const OPENS_ONTO = '[^\s;:,.!?)\]\}\x{2026}\x{00BB}\x{201D}\x{2019}\x{203A}]';

    /** The two markers the quote pass parks its decisions on, before it knows the language's glyphs. */
    private const OPENED = "\x01";
    private const CLOSED = "\x02";

    /**
     * What every piece of not-prose is replaced by while the rules run.
     *
     * One character rather than a hole, because the rules read what is next to
     * what they are changing: cutting the document into the gaps between the
     * code spans put an end of string where a word had been, and the dash in
     * "`--strict` - the safe one" stopped being a dash because the thing to its
     * left was no longer in the same string. It is a non-space and not a letter,
     * a digit or a quotation mark, which is exactly what a code span is to the
     * rules that care.
     */
    private const KEPT = "\x03";

    /**
     * Sets the punctuation of one piece of Markdown.
     *
     * @param string $markdown the text as it was written
     * @param string $language the profile's language, as free text
     */
    public static function apply(string $markdown, string $language): string
    {
        if (trim($markdown) === '') {
            return $markdown;
        }

        // The markers must not be able to arrive in the input and come out as
        // quotation marks or eat a code span. Nothing legitimate writes them.
        $markdown = str_replace([self::OPENED, self::CLOSED, self::KEPT], '', $markdown);

        $quotes = self::QUOTES[self::localeOf($language)] ?? self::QUOTES['en'];

        return self::overProse(
            $markdown,
            static fn(string $prose): string => self::setProse($prose, $quotes)
        );
    }

    /**
     * Which of the sets of quotation marks this language uses.
     *
     * Public because the Settings screen and the tests both want to be able to
     * say which one a given language resolves to without setting any text.
     */
    public static function localeOf(string $language): string
    {
        $name = mb_strtolower(trim($language));
        // "German (formal)" and "Deutsch, Sie-Form" both name the language in
        // the part before the qualifier, which is the part that decides.
        $name = trim((string)preg_replace('/[(,\/].*$/u', '', $name));

        foreach (self::NAMES as $locale => $aliases) {
            foreach ($aliases as $alias) {
                if ($name === $alias) {
                    return $locale;
                }
                // A written-out name may carry a suffix - "germany", "french
                // (canadian)" - but a two-letter code never prefixes anything,
                // or `es` would claim Estonian.
                if (mb_strlen($alias) > 2 && str_starts_with($name, $alias)) {
                    return $locale;
                }
            }
        }
        return 'en';
    }

    /* -------------------------------------------------------------- the pass */

    /**
     * Runs a transformation over the prose of a Markdown document and over
     * nothing else.
     *
     * Everything that is not prose is lifted out, the rules run over what is
     * left with a single character standing where each piece was, and the
     * pieces go back afterwards exactly as they were. Standing something in the
     * gap rather than closing it is what keeps a sentence a sentence: the rules
     * look at the character to the left and to the right of what they change,
     * and a code span in the middle of a line must not read as the end of one.
     *
     * @param callable(string):string $set
     */
    private static function overProse(string $markdown, callable $set): string
    {
        $kept = [];
        $masked = (string)preg_replace_callback(
            self::SKIP,
            static function (array $m) use (&$kept): string {
                $kept[] = $m[0];
                return self::KEPT;
            },
            $markdown
        );

        $set = $set($masked);

        // Back in the order they were taken, which is the order they are met.
        $out = '';
        $at = 0;
        foreach ($kept as $literal) {
            $found = strpos($set, self::KEPT, $at);
            if ($found === false) {
                break;
            }
            $out .= substr($set, $at, $found - $at) . $literal;
            $at = $found + strlen(self::KEPT);
        }

        return $out . substr($set, $at);
    }

    /** @param array<string,string> $quotes */
    private static function setProse(string $text, array $quotes): string
    {
        if ($text === '') {
            return $text;
        }

        $text = self::marks($text);
        $text = self::apostrophes($text);
        $text = self::quotes($text, self::DOUBLES, $quotes['open'], $quotes['close'], $quotes['inner']);
        $text = self::quotes($text, self::SINGLES, $quotes['sopen'], $quotes['sclose'], '');

        if ($quotes['open'] === '«' && $quotes['inner'] !== '') {
            $text = self::frenchSpacing($text);
        }

        return $text;
    }

    /**
     * The marks that are the same in every language: an ellipsis is one
     * character, and a dash between two words is a dash rather than a hyphen.
     *
     * The dash rule asks for a non-space on the left, which is what keeps it
     * away from a list: a bullet is a hyphen with nothing but indentation before
     * it on its line, and a hyphen that opens a line can never match.
     */
    private static function marks(string $text): string
    {
        return (string)preg_replace(
            [
                '/(?<!\.)\.{3}(?!\.)/u',                        // ... becomes one character
                '/(?<=\S) -{1,2} (?=\S)/u',                     // a hyphen standing in for a dash
            ],
            [
                '…',
                ' – ',
            ],
            $text
        );
    }

    /**
     * The apostrophe inside a word, which is never a quotation mark.
     *
     * Done before the single-quote pass and for its sake: `don't` and `l'ami`
     * would otherwise offer that pass an opening mark it would have to guess
     * about, and it would guess wrong in the middle of a word.
     */
    private static function apostrophes(string $text): string
    {
        return (string)preg_replace('/(\p{L})[\'\x{2018}\x{201B}](?=\p{L})/u', '$1’', $text);
    }

    /**
     * One pair of quotation marks, decided by what stands next to each mark.
     *
     * Both passes park their answer on a marker rather than writing the glyph,
     * because the glyph they would write is itself a quotation mark: an opening
     * `„` sitting after a bracket would have been read as a closing one by the
     * pass that runs next, and the first quotation mark of every parenthesis
     * came out backwards.
     */
    private static function quotes(string $text, string $any, string $open, string $close, string $inner): string
    {
        $thin = '[' . self::THIN . ']?';

        // Opening: something a quotation mark may follow, the mark, an optional
        // space that is about to be replaced by the right one, then the start of
        // quoted text.
        $text = (string)preg_replace(
            '/(^|[' . self::BEFORE_OPEN . '])[' . $any . ']' . $thin . '(?=' . self::OPENS_ONTO . ')/mu',
            '$1' . self::OPENED,
            $text
        );

        // Closing: quoted text, an optional space, the mark, and then anything
        // that is not more of the same word. Whatever the opening pass took is a
        // marker by now and cannot be taken twice, and the trailing guard is
        // what stops an opening mark that pass declined from being read as one.
        $text = (string)preg_replace(
            '/(\S)' . $thin . '[' . $any . '](?![\p{L}\p{N}])/u',
            '$1' . self::CLOSED,
            $text
        );

        return strtr($text, [
            self::OPENED => $open . $inner,
            self::CLOSED => $inner . $close,
        ]);
    }

    /**
     * The space French sets before its two-part punctuation.
     *
     * A narrow no-break space before `;`, `!` and `?`, a no-break space before
     * `:`. Each is required to be followed by whitespace or the end of the line,
     * which is what keeps the rule out of a table's `|:---|`, a `10:30`, and a
     * `C++`-shaped `::` - none of which is punctuation ending a phrase.
     */
    private static function frenchSpacing(string $text): string
    {
        return (string)preg_replace(
            [
                '/(\S)[' . self::THIN . ']?([;!?])(?=\s|$)/mu',
                '/(\S)[' . self::THIN . ']?(:)(?=\s|$)/mu',
            ],
            [
                '$1' . "\u{202F}" . '$2',
                '$1' . "\u{00A0}" . '$2',
            ],
            $text
        );
    }
}
