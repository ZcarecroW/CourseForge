<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * Punctuation the way the language actually sets it.
 *
 * A model writes German and reaches for the American keyboard: `„Wort"` - the
 * opening mark right and the closing one a straight typewriter quote, because
 * the two are one keystroke apart in the training data and nothing downstream
 * ever complained. The same hand writes `...` where an ellipsis belongs, a
 * hyphen where a dash does, an em dash in a language that sets a spaced en
 * dash, and four spaces where one was meant. None of it is wrong enough to
 * notice while reading one page and all of it is wrong on every page of a
 * five-hundred-page book, which is exactly the kind of error worth fixing with
 * code rather than with a sentence in a prompt: a rule that runs is worth more
 * than a rule that is asked for.
 *
 * Four properties hold this together.
 *
 * **It never touches code.** Everything that is not prose - a fenced block, an
 * inline span, a link target, a URL, an HTML tag or comment, a formula, an
 * escaped character and the two markers CourseForge writes into a page - is cut
 * out first and put back untouched. Turning a straight quote into a curly one
 * inside a JSON sample is not a typographic improvement, it is a broken
 * example, and a course about a programming language is mostly examples.
 *
 * **It reads a quotation mark the way a reader does.** Every mark is decided by
 * what stands beside it, by how deep in a quotation it is, and by which way the
 * glyph itself points - in that order. Position alone was not enough: it read
 * `**"Wort"**` as a closing mark because a Markdown asterisk stood where a
 * space should have been, and it read the closing `»` of a French quotation as
 * the opening of the next one because both of its neighbours were spaces. So
 * emphasis is transparent to the decision, a nesting depth breaks the ties
 * position cannot, and a closing mark is written from the mark it closes rather
 * than from the character the model happened to type. That last part is why a
 * pair that starts `„` and ends `"` comes out as a pair at all.
 *
 * **It is idempotent.** Every rule reads a state that is already correct as a
 * match for itself and rewrites it to what it already was, so a page run
 * through twice - regenerated, re-imported, published and pulled back - is
 * byte-for-byte the page run through once. Anything else would be a diff that
 * appears out of nowhere on a page nobody edited, and it is the property the
 * retroactive pass in {@see \CourseForge\Domain\Typesetter} depends on to know
 * that a page it did not change needed no change.
 *
 * **It is bounded.** The quotation depth is forgotten at every block boundary -
 * a blank line, a heading, a list item, a table row. One unbalanced quotation
 * mark can therefore confuse its own paragraph and nothing after it, which is
 * the difference between a typo and a page-long cascade.
 */
final class Typography
{
    /**
     * The sets of marks a language uses, keyed by the style rather than by the
     * language, because a dozen languages share four or five arrangements.
     *
     * `primary` is the outermost pair and `secondary` the pair nested inside
     * it; the two alternate all the way down, so a double quotation inside a
     * double quotation comes out as the inner pair whichever character the
     * model typed. `inner` is the space that belongs *inside* the pair - French
     * sets a narrow no-break space there and nothing else does. `dash` says
     * whether an em dash between two words is this language's dash (`keep`) or
     * a spaced en dash written the American way (`en`). `space` marks the
     * languages that set a space before their two-part punctuation.
     */
    private const STYLES = [
        // English and everything unrecognised.
        'en' => ['primary' => ['“', '”'], 'secondary' => ['‘', '’'], 'inner' => '', 'dash' => 'keep', 'space' => false],
        // German and the languages that set their quotations the same way.
        'de' => ['primary' => ['„', '“'], 'secondary' => ['‚', '‘'], 'inner' => '', 'dash' => 'en', 'space' => false],
        // Polish and Hungarian: the closing mark points the other way.
        'pl' => ['primary' => ['„', '”'], 'secondary' => ['‚', '’'], 'inner' => '', 'dash' => 'en', 'space' => false],
        // French, the only one of these that sets a space inside the pair.
        'fr' => ['primary' => ['«', '»'], 'secondary' => ['‹', '›'], 'inner' => "\u{202F}", 'dash' => 'keep', 'space' => true],
        // Guillemets without the inner space: Spanish, Italian, Greek.
        'gui' => ['primary' => ['«', '»'], 'secondary' => ['“', '”'], 'inner' => '', 'dash' => 'keep', 'space' => false],
        // Russian and Ukrainian nest German marks inside guillemets.
        'ru' => ['primary' => ['«', '»'], 'secondary' => ['„', '“'], 'inner' => '', 'dash' => 'keep', 'space' => false],
        // Swedish and Finnish point both marks the same way.
        'sv' => ['primary' => ['”', '”'], 'secondary' => ['’', '’'], 'inner' => '', 'dash' => 'keep', 'space' => false],
    ];

    /** Which of the styles above each recognised language is set in. */
    private const STYLE_OF = [
        'de' => 'de', 'cs' => 'de', 'sk' => 'de',
        'pl' => 'pl', 'hu' => 'pl',
        'fr' => 'fr',
        'es' => 'gui', 'it' => 'gui', 'el' => 'gui',
        'ru' => 'ru', 'uk' => 'ru',
        'sv' => 'sv', 'fi' => 'sv',
        'en' => 'en',
    ];

    /**
     * What a profile's language field might say, for the languages that set
     * their punctuation differently from English.
     *
     * The field is free text - a course may be written in a language nobody
     * thought to list - so this is a recogniser rather than a validator, and
     * everything it does not recognise is set the English way. A two-letter
     * code only ever matches exactly: `es` is Spanish and `Estonian` is not.
     * The written-out names are given in English, in the language itself and in
     * German, because those are the three a profile is likely to hold.
     */
    private const NAMES = [
        'de' => ['de', 'german', 'deutsch', 'österreichisch', 'schweizerdeutsch'],
        'fr' => ['fr', 'french', 'français', 'francais', 'französisch'],
        'es' => ['es', 'spanish', 'español', 'espanol', 'castellano', 'spanisch'],
        'it' => ['it', 'italian', 'italiano', 'italienisch'],
        'pl' => ['pl', 'polish', 'polski', 'polnisch'],
        'cs' => ['cs', 'czech', 'čeština', 'cestina', 'tschechisch'],
        'sk' => ['sk', 'slovak', 'slovenčina', 'slovencina', 'slowakisch'],
        'hu' => ['hu', 'hungarian', 'magyar', 'ungarisch'],
        'ru' => ['ru', 'russian', 'русский', 'russisch'],
        'uk' => ['ukrainian', 'українська', 'ukrainisch'],
        'el' => ['el', 'greek', 'ελληνικά', 'griechisch'],
        'sv' => ['sv', 'swedish', 'svenska', 'schwedisch'],
        'fi' => ['fi', 'finnish', 'suomi', 'finnisch'],
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
        . '|<!--[\s\S]*?-->'                    // an HTML comment
        . '|\$\$[\s\S]*?\$\$'                   // display maths
        . '|\\\\\([\s\S]*?\\\\\)'               // \( ... \) inline maths
        . '|\\\\\[[\s\S]*?\\\\\]'               // \[ ... \] display maths
        . '|!?\[[^\]\n]*\]\([^)\n]*\)'          // a link or an image, target and all
        . '|<[^>\n\s][^>\n]*>'                  // an HTML tag or an autolink
        . '|\{\{[^}\n]*\}\}'                    // a cloze deletion or a prompt placeholder
        . '|\(\x{1F517}[^)\n]*\)'               // a cross-reference marker
        . '|(?:https?|mailto):\S+'              // a bare address
        . '|^\s*\|[\s:|-]+\|\s*$'               // a table's alignment row
        . '|\\\\[\p{P}\p{S}]'                   // a character the author escaped on purpose
        . '|```[\s\S]*$'                        // a fence nobody closed
        . '|~~~[\s\S]*$'
        . ')/mux';

    /** Every double-quote-ish character, whatever language wrote it. */
    private const DOUBLES = '"\x{201C}\x{201D}\x{201E}\x{201F}\x{00AB}\x{00BB}';

    /** Every single-quote-ish character. Apostrophes are dealt with before this is used. */
    private const SINGLES = "'\x{2018}\x{2019}\x{201A}\x{201B}\x{2039}\x{203A}";

    /** The marks that only ever open, and the marks that only ever close. */
    private const OPENERS = "\x{201E}\x{201F}\x{00AB}\x{201A}\x{201B}\x{2039}";
    private const CLOSERS = "\x{201D}\x{00BB}\x{2019}\x{203A}";

    /** The spaces that may already sit inside a quotation mark or before French punctuation. */
    private const THIN = ' \t\x{00A0}\x{202F}\x{2009}';

    /**
     * The same spaces as characters rather than as a character class.
     *
     * The constants above are written for PCRE, where `\x{00A0}` is one
     * character; to PHP itself that is six of them, so a walk that compares one
     * character at a time needs the list spelled out.
     *
     * @var array<int,string>
     */
    private const THIN_SET = [' ', "\t", "\u{00A0}", "\u{202F}", "\u{2009}"];

    /** The quotation marks as characters, for the same reason. */
    private const DOUBLE_SET = ['"', "\u{201C}", "\u{201D}", "\u{201E}", "\u{201F}", "\u{00AB}", "\u{00BB}"];
    private const SINGLE_SET = ["'", "\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{2039}", "\u{203A}"];
    private const OPENER_SET = ["\u{201E}", "\u{201F}", "\u{00AB}", "\u{201A}", "\u{201B}", "\u{2039}"];
    private const CLOSER_SET = ["\u{201D}", "\u{00BB}", "\u{2019}", "\u{203A}"];

    /** Markdown emphasis, which stands between a quotation mark and its text without being either. */
    private const EMPHASIS = '*_~';

    /**
     * The control characters this pass reserves.
     *
     * KEPT stands in for a piece of not-prose while the rules run and APOS for
     * an apostrophe that has already been decided, so that the quotation pass
     * cannot mistake one for a mark it has to place. Nothing legitimate writes
     * them, and anything that does is dropped on the way in - arriving in the
     * input they would come out as quotation marks or eat a code span. OPENED
     * and CLOSED were the previous pass's scratch marks; they are still dropped
     * because a page written by an older release may carry one.
     */
    private const OPENED = "\x01";
    private const CLOSED = "\x02";
    private const KEPT = "\x03";
    private const APOS = "\x04";

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

        $markdown = str_replace([self::OPENED, self::CLOSED, self::KEPT, self::APOS], '', $markdown);

        $style = self::STYLES[self::styleOf($language)];

        return self::overProse(
            $markdown,
            static fn(string $prose): string => self::setProse($prose, $style)
        );
    }

    /**
     * Which language this text is set as.
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

    /**
     * Which arrangement of marks a language is set in.
     *
     * Public for the same reason as localeOf: it is the honest answer to "what
     * will this course actually look like", and a screen offering to correct a
     * course's punctuation should be able to say so before it does.
     */
    public static function styleOf(string $language): string
    {
        return self::STYLE_OF[self::localeOf($language)] ?? 'en';
    }

    /**
     * The opening and closing mark a language uses, for a screen that wants to
     * show what it is about to do.
     *
     * @return array{0:string,1:string}
     */
    public static function marksOf(string $language): array
    {
        return self::STYLES[self::styleOf($language)]['primary'];
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

    /** @param array<string,mixed> $style */
    private static function setProse(string $text, array $style): string
    {
        if ($text === '') {
            return $text;
        }

        $text = self::marks($text, $style);
        $text = self::apostrophes($text);
        $text = self::quotes($text, $style);
        $text = str_replace(self::APOS, '’', $text);
        $text = self::spacing($text);

        if ($style['space'] === true) {
            $text = self::frenchSpacing($text);
        }

        return $text;
    }

    /**
     * The marks that do not depend on which quotation marks a language uses: an
     * ellipsis is one character, a dash between two words is a dash rather than
     * a hyphen, a span of years is a span and not a subtraction, and a
     * trademark written in ASCII is a trademark.
     *
     * The dash rule asks for a non-space on the left, which is what keeps it
     * away from a list: a bullet is a hyphen with nothing but indentation before
     * it on its line, and a hyphen that opens a line can never match. The range
     * rule refuses a hyphen with a letter or another hyphen anywhere near it,
     * which is what keeps it out of `2026-09-01`, `T-34` and `1-2-3`.
     *
     * @param array<string,mixed> $style
     */
    private static function marks(string $text, array $style): string
    {
        $text = (string)preg_replace(
            [
                '/(?<!\.)\.{3}(?!\.)/u',                                   // ... becomes one character
                '/(?<![.\x{2026}])\.[ \x{00A0}]\.[ \x{00A0}]\.(?![.\x{2026}])/u', // and so does . . .
                '/(?<=\S)[ \x{00A0}]-{1,3}[ \x{00A0}](?=\S)/u',            // a hyphen standing in for a dash
                '/(?<=[\p{L}\p{N}])-{2,3}(?=[\p{L}\p{N}])/u',              // and so is a double hyphen with no room
                '/(?<![\p{L}\d\x{2013}\x{2014}-])(\d{1,4})-(\d{1,4})(?![\p{L}\d-])/u', // 1990-2000 is a span
            ],
            ['…', '…', ' – ', ' – ', '$1–$2'],
            $text
        );

        // An em dash is the American dash. German - and the languages that set
        // their dashes the German way - uses a spaced en dash, which is the one
        // thing a model writing German reproduces from English every time.
        if ($style['dash'] === 'en') {
            $text = (string)preg_replace('/(?<=\S)[ \x{00A0}]*\x{2014}[ \x{00A0}]*(?=\S)/u', ' – ', $text);
        }

        // Only ever attached to the name they belong to, and only in the case a
        // trademark is written in: `(a)`, `(b)`, `(c)` is a list, not a
        // copyright notice, and it is written in lower case.
        return (string)preg_replace(
            [
                '/(?<=[\p{L}\p{N}])\(TM\)/u',
                '/(?<=[\p{L}\p{N}])\(R\)/u',
                '/(?<=[Cc]opyright )\([Cc]\)/u',
            ],
            ['™', '®', '©'],
            $text
        );
    }

    /**
     * The apostrophe that is not a quotation mark: the one inside a word, and
     * the one standing in for what an elision left out.
     *
     * Done before the quotation pass and for its sake: `don't`, `l'ami` and
     * `'90s` would otherwise offer that pass a mark it would have to guess
     * about, and it would guess wrong in the middle of a word. The answer is
     * parked on a marker rather than written as `’`, because `’` is itself a
     * closing quotation mark and the pass that runs next would have read it as
     * one.
     *
     * A trailing apostrophe is deliberately left behind: `dogs' bowls` and
     * `James' book` are a closing mark by position, which is exactly what the
     * quotation pass makes of them, and it makes the right one.
     */
    private static function apostrophes(string $text): string
    {
        return (string)preg_replace(
            [
                // Inside a word.
                '/(\p{L})[\'\x{2018}\x{2019}\x{201B}](?=\p{L})/u',
                // rock 'n' roll, both of them at once.
                '/(?<=\s)[\'\x{2018}\x{2019}](n)[\'\x{2018}\x{2019}](?=\s)/u',
                // A decade or a year with its century left off: '90s, '90er, '26.
                '/(?<![\p{L}\p{N}])[\'\x{2018}\x{2019}\x{201B}](?=\d{2}\p{L}*(?![\p{L}\p{N}]))/u',
                // The handful of words English elides the front of.
                '/(?<![\p{L}\p{N}])[\'\x{2018}\x{2019}\x{201B}](?=(?:til|tis|twas|em|cause|round|bout|neath|gainst)(?![\p{L}\p{N}]))/iu',
            ],
            ['$1' . self::APOS, self::APOS . '$1' . self::APOS, self::APOS, self::APOS],
            $text
        );
    }

    /* ------------------------------------------------------------ quotations */

    /**
     * Every quotation mark in the text, each decided by what stands beside it.
     *
     * The document is cut into blocks first - a paragraph, a heading, a list
     * item, a table row - and the nesting depth is forgotten between them. A
     * quotation does not run across a blank line in a course page, and bounding
     * the depth this way means one unbalanced mark costs its own paragraph
     * rather than every paragraph after it.
     *
     * @param array<string,mixed> $style
     */
    private static function quotes(string $text, array $style): string
    {
        $parts = preg_split('/(\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $text;
        }

        $out = '';
        $block = '';
        $previousStarted = true;

        for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
            $line = $parts[$i];
            $break = $parts[$i + 1] ?? '';
            $starts = self::startsBlock($line);

            // A line that opens a block, and a line that follows one, both begin
            // a block of their own. Everything else is the continuation of a
            // paragraph that may have been wrapped.
            if (($starts || $previousStarted) && $block !== '') {
                $out .= self::setQuotes($block, $style);
                $block = '';
            }

            $block .= $line . $break;
            $previousStarted = $starts;
        }

        return $out . self::setQuotes($block, $style);
    }

    /** Whether a line opens a Markdown block rather than continuing a paragraph. */
    private static function startsBlock(string $line): bool
    {
        return trim($line) === ''
            || preg_match('/^ {0,3}(?:#{1,6}\s|>|\||[-*+]\s|\d+[.)]\s|[-*_=]{3,}\s*$)/u', $line) === 1;
    }

    /**
     * One block, walked left to right with the open quotations on a stack.
     *
     * Three signals decide each mark, in this order. Position first: a mark
     * glued to the text on exactly one side has already said which side it is
     * on, and Markdown emphasis is skipped over on the way to that answer,
     * because `**"Wort"**` is a quotation with asterisks around it and not a
     * quotation mark with a word attached. The stack second: it is what tells
     * the closing `»` of `« mot » et` from an opening one when both of its
     * neighbours are spaces, which position cannot. The glyph last, for a mark
     * that only ever points one way.
     *
     * What is written is not what was read. An opening mark takes the pair one
     * level in from the pair around it, and a closing mark takes the pair its
     * own opening mark took - so `„Wort"`, which is what the models actually
     * write, closes with `“` because that is what `„` opens.
     *
     * @param array<string,mixed> $style
     */
    private static function setQuotes(string $block, array $style): string
    {
        if ($block === '' || preg_match('/[' . self::DOUBLES . self::SINGLES . ']/u', $block) !== 1) {
            return $block;
        }

        $chars = preg_split('//u', $block, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return $block;
        }

        $n = count($chars);
        $out = '';
        $stack = [];
        $decided = [];

        for ($i = 0; $i < $n; $i++) {
            $char = $chars[$i];
            $kind = self::quoteKind($char);
            if ($kind === null) {
                $out .= $char;
                continue;
            }

            [$leftClass, $leftSpaced] = self::neighbour($chars, $i - 1, -1, $decided);
            [$rightClass, $rightSpaced] = self::neighbour($chars, $i + 1, 1, $decided);

            $gluedLeft = !$leftSpaced && in_array($leftClass, ['word', 'digit', 'closep', 'closeq'], true);
            $gluedRight = !$rightSpaced && in_array($rightClass, ['word', 'digit', 'openp', 'openq'], true);

            if ($gluedRight && !$gluedLeft) {
                $opens = true;
            } elseif ($gluedLeft && !$gluedRight) {
                $opens = false;
            } else {
                // Neither side settles it. A mark that only points one way says
                // so itself; otherwise an open quotation is what is being closed.
                $points = self::direction($char);
                $opens = $points !== null ? $points === 'open' : $stack === [];
            }

            // A straight mark after a digit, with nothing open to close, is a
            // measurement rather than a quotation: 5" is five inches.
            if (!$opens && $stack === [] && self::direction($char) === null
                && $leftClass === 'digit' && !$leftSpaced) {
                $decided[$i] = 'close';
                $out .= $kind === 'd' ? '″' : '′';
                continue;
            }

            if ($opens) {
                $set = $stack === []
                    ? ($kind === 'd' ? 'primary' : 'secondary')
                    : (end($stack) === 'primary' ? 'secondary' : 'primary');
                $stack[] = $set;
                $decided[$i] = 'open';
                $out .= $style[$set][0] . $style['inner'];

                // Whatever space the model left inside the pair is replaced by
                // the space the language sets there, which is usually none.
                while ($i + 1 < $n && self::isThin($chars[$i + 1])) {
                    $i++;
                }
                continue;
            }

            $set = $stack === []
                ? ($kind === 'd' ? 'primary' : 'secondary')
                : (string)array_pop($stack);
            $decided[$i] = 'close';
            $out = self::trimThin($out) . $style['inner'] . $style[$set][1];
        }

        return $out;
    }

    /**
     * What stands beside a quotation mark, and whether a space stands between.
     *
     * Emphasis is stepped over on both sides of the space, because a model
     * writes `**"Wort"**` and `** "Wort" **` with equal enthusiasm and neither
     * of them is about the asterisks.
     *
     * @param array<int,string> $chars
     * @param array<int,string> $decided how each earlier quotation mark was read
     * @return array{0:string,1:bool}
     */
    private static function neighbour(array $chars, int $at, int $step, array $decided): array
    {
        $n = count($chars);
        $spaced = false;

        while ($at >= 0 && $at < $n && self::isEmphasis($chars[$at])) {
            $at += $step;
        }
        if ($at >= 0 && $at < $n && self::isSpace($chars[$at])) {
            $spaced = true;
            while ($at >= 0 && $at < $n && self::isSpace($chars[$at])) {
                $at += $step;
            }
            while ($at >= 0 && $at < $n && self::isEmphasis($chars[$at])) {
                $at += $step;
            }
        }
        if ($at < 0 || $at >= $n) {
            return ['edge', $spaced];
        }

        return [self::classify($chars[$at], $decided[$at] ?? null), $spaced];
    }

    /**
     * What one character is to a quotation mark standing next to it.
     *
     * A quotation mark already read is classified by the reading rather than by
     * the glyph, which is what lets `„a ‚b‘“` close twice in a row: the `‘` is
     * the end of something, so the `“` after it has text on its left.
     */
    private static function classify(string $char, ?string $decided): string
    {
        if ($decided !== null) {
            return $decided === 'open' ? 'openq' : 'closeq';
        }
        $ascii = strlen($char) === 1;
        if ($ascii && $char >= '0' && $char <= '9') {
            return 'digit';
        }
        if (($ascii && str_contains('([{<', $char)) || $char === '¿' || $char === '¡') {
            return 'openp';
        }
        if (($ascii && str_contains(')]},.;:!?', $char)) || $char === '…') {
            return 'closep';
        }
        if ($char === '-' || $char === '–' || $char === '—') {
            return 'dash';
        }
        if (self::quoteKind($char) !== null) {
            // Not yet read, so the glyph is all there is to go on. A mark that
            // only ever closes ends something; anything else starts something.
            return self::direction($char) === 'close' ? 'closeq' : 'openq';
        }
        return 'word';
    }

    /** 'd' for a double-quote-ish character, 's' for a single one, null for anything else. */
    private static function quoteKind(string $char): ?string
    {
        if (in_array($char, self::DOUBLE_SET, true)) {
            return 'd';
        }
        return in_array($char, self::SINGLE_SET, true) ? 's' : null;
    }

    /** Which way a mark points, for the marks that only ever point one way. */
    private static function direction(string $char): ?string
    {
        if (in_array($char, self::OPENER_SET, true)) {
            return 'open';
        }
        return in_array($char, self::CLOSER_SET, true) ? 'close' : null;
    }

    private static function isEmphasis(string $char): bool
    {
        return strlen($char) === 1 && str_contains(self::EMPHASIS, $char);
    }

    private static function isSpace(string $char): bool
    {
        return $char === "\n" || $char === "\r" || self::isThin($char);
    }

    private static function isThin(string $char): bool
    {
        return in_array($char, self::THIN_SET, true);
    }

    /** Drops the spaces a closing mark is about to be written over, and no newline. */
    private static function trimThin(string $text): string
    {
        return (string)preg_replace('/[' . self::THIN . ']+\z/u', '', $text);
    }

    /* -------------------------------------------------------------- spacing */

    /**
     * The spacing a model gets wrong the way a keyboard gets it wrong: four
     * spaces where one was meant, a space before the comma, a space just inside
     * a bracket, whitespace hanging off the end of a line, and three blank
     * lines between two paragraphs.
     *
     * Each of these is done per line and with the line's indentation held back,
     * because indentation is what makes a nested list nested. A table row is
     * left alone entirely: the padding in `| Name    | Wert |` is what makes
     * the source readable, and rewriting it would be a diff on every table in
     * the course for no rendered difference at all.
     */
    private static function spacing(string $text): string
    {
        $text = (string)preg_replace_callback(
            '/^.*$/mu',
            static fn(array $m): string => self::spaceLine($m[0]),
            $text
        );

        // Trailing whitespace, except the two spaces that are a line break.
        $text = (string)preg_replace_callback(
            '/[ \t]+(?=\R|\z)/u',
            static fn(array $m): string => $m[0] === '  ' ? '  ' : '',
            $text
        );

        // One blank line between two paragraphs is a paragraph break; three are
        // a model running out of things to say.
        return (string)preg_replace('/(\R)(?:[ \t]*\R){2,}/u', '$1$1', $text);
    }

    private static function spaceLine(string $line): string
    {
        if (preg_match('/^\s*\|/u', $line) === 1) {
            return $line;
        }

        preg_match('/^[ \t]*/u', $line, $lead);
        $indent = $lead[0];
        $body = substr($line, strlen($indent));

        return $indent . (string)preg_replace(
            [
                '/(?<=\S)[ \t]{2,}(?=\S)/u',                                    // a run of spaces mid-line
                '/(?<=\S)[' . self::THIN . ']+(?=[,;:.!?](?:[ \t\r]|\z))/u',     // a space before the mark that ends a phrase
                '/\([ \t]+(?=\S)/u',                                            // ( innen )
                '/(?<=\S)[ \t]+\)/u',
            ],
            [' ', '', '(', ')'],
            $body
        );
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
