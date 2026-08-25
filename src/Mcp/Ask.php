<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

/**
 * Asking the person on the other end a question, mid-call.
 *
 * A tool that needs something it was not given has two ways to behave. It can
 * refuse and say what argument was missing, which is what every tool here did
 * before and what most still do - and on a client that cannot be asked
 * anything, that remains the only honest answer. Or, when the client has said
 * it can put a question to its user, it can pause: the call ends, the client
 * asks, and the client makes the same call again with the answer attached.
 *
 * That second shape is MRTR (Multi Round-Trip Requests), and the important
 * thing about it is who resolves it. It is the *client* - not the model - that
 * sees `resultType: "input_required"`, shows the form, and retries. So this is
 * not a way to steer a language model through a sequence; it is a way to get a
 * fact out of a human being without holding a socket open while they think.
 * Steering the model is what get_next_step and the prompts are for.
 *
 * Two rules keep it honest:
 *
 *   - **Never pause where a refusal would do.** A question a client cannot show
 *     anybody is a hang. Every call into this class carries the sentence to use
 *     instead, and a client that never declared elicitation gets that sentence
 *     as an ordinary tool error - which is exactly the behaviour it had before
 *     any of this existed.
 *
 *   - **Never do the irreversible thing before the question.** A confirmation
 *     that arrives after the pages are deleted is a notification. The check
 *     happens first, the pause happens second, and the work happens only on the
 *     round trip that carries the answer.
 */
final class Ask
{
    /** Answers the client sent back with a retried call, keyed as they were asked for. */
    private static array $answers = [];

    /** Whether the client on this request said it can put a question to somebody. */
    private static bool $available = false;

    /**
     * Installs what the current request knows, at the start of a tool call.
     *
     * @param array<string,mixed> $answers
     */
    public static function begin(array $answers, bool $available): void
    {
        self::$answers = $answers;
        self::$available = $available;
    }

    /** Forgets it again, so nothing leaks into the next call in the same process. */
    public static function end(): void
    {
        self::$answers = [];
        self::$available = false;
    }

    public static function canAsk(): bool
    {
        return self::$available;
    }

    /**
     * The answer to one question, if this call is the retry that carries it.
     *
     * @return array<string,mixed>|null
     */
    public static function answer(string $key): ?array
    {
        $answer = self::$answers[$key] ?? null;
        return is_array($answer) ? $answer : null;
    }

    /**
     * Asks for some values, or throws the refusal to use when nobody can be asked.
     *
     * `$schema` is a flat map of property name to JSON Schema fragment: the
     * protocol restricts elicitation forms to flat objects of primitives, so
     * there is no point accepting anything richer here and pretending.
     *
     * @param array<string,mixed> $schema
     * @param string[] $required
     * @return array<string,mixed> the answers, once they arrive
     */
    public static function form(
        string $key,
        string $message,
        array $schema,
        array $required,
        string $insteadSay
    ): array {
        $answer = self::answer($key);

        if ($answer !== null) {
            $action = (string)($answer['action'] ?? 'cancel');

            // The protocol separates these three deliberately and says to treat
            // them differently. Declining is a decision and deserves to be
            // respected rather than re-asked; cancelling is a dismissal, and
            // saying so plainly lets the model offer to start again.
            if ($action === 'decline') {
                throw new NeedsInput(
                    $key,
                    $message,
                    $schema,
                    $required,
                    'The person declined to answer. Do not ask again unless they raise it themselves. ' . $insteadSay,
                    settled: true
                );
            }
            if ($action !== 'accept') {
                throw new NeedsInput(
                    $key,
                    $message,
                    $schema,
                    $required,
                    'The question was dismissed without an answer, so nothing was done. ' . $insteadSay,
                    settled: true
                );
            }

            $content = $answer['content'] ?? [];
            return is_array($content) ? $content : [];
        }

        throw new NeedsInput($key, $message, $schema, $required, $insteadSay);
    }

    /**
     * Asks a yes-or-no question before something that cannot be undone.
     *
     * Returns true only on an explicit yes. Everything else - a no, a
     * dismissal, a client that cannot ask - stops the call, because the
     * default for an irreversible thing is not to do it.
     */
    public static function confirm(string $key, string $message, string $insteadSay): bool
    {
        $answers = self::form(
            $key,
            $message,
            [
                'confirm' => [
                    'type' => 'boolean',
                    'title' => 'Go ahead',
                    'description' => 'Tick this only if you want the change described above to happen.',
                ],
            ],
            ['confirm'],
            $insteadSay
        );

        return ($answers['confirm'] ?? false) === true;
    }
}
