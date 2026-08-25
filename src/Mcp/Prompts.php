<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Security\Actor;
use CourseForge\Support\HttpException;

/**
 * The way in, for somebody who has not read any of this.
 *
 * A tool surface answers questions a client already knows to ask. A prompt is
 * how the job gets STARTED: Claude Code lists these as slash commands
 * (`/mcp__courseforge__build_a_course`), so an operator picks one, types the
 * topic, and the client is handed the whole sequence in one message. Nobody has
 * to explain the order of seventy-eight tools first.
 *
 * These are not held open, negotiated or streamed - `prompts/list` and
 * `prompts/get` are one request and one response each, which is why they belong
 * on a server that answers every request with a single JSON object and never
 * keeps a connection. (Only resource *subscriptions* would need a stream, and
 * there are none.)
 *
 * What every prompt here has in common: it tells the client to call
 * `get_next_step` rather than laying out the sequence itself. Text goes stale;
 * the database does not. A prompt that recited the steps would be wrong the
 * first time a course was half finished, whereas one that says "ask what to do
 * next, then do it, then ask again" is right in every state including the ones
 * nobody thought about.
 */
final class Prompts
{
    /**
     * The catalogue, in the shape `prompts/list` returns.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function catalogue(): array
    {
        return [
            [
                'name' => 'build_a_course',
                'title' => 'Build a whole course',
                'description' => 'Take a subject and write a complete course from it - outline, every page, and '
                    . 'optionally publish it. CourseForge hands over the briefs and stores the results; the writing '
                    . 'happens here, so no AI account has to be configured in CourseForge and nothing is spent on '
                    . 'one.',
                'arguments' => [
                    [
                        'name' => 'topic',
                        'description' => 'What the course should teach. A full sentence works far better than a '
                            . 'keyword: name the subject, who it is for, and how deep it should go.',
                        'required' => true,
                    ],
                    [
                        'name' => 'name',
                        'description' => 'What to call the course in CourseForge. Optional - one is taken from the '
                            . 'topic otherwise.',
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'continue_a_course',
                'title' => 'Carry on with a course',
                'description' => 'Pick up a course that is already started and take it as far as it will go. Works '
                    . 'whether it was left after the outline, half written, or finished but unpublished.',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Which course. Optional - leave it out and CourseForge will say which ones '
                            . 'there are.',
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'review_a_course',
                'title' => 'Read a course and report on it',
                'description' => 'Read a finished course and say what is wrong with it - gaps, repetition, pages '
                    . 'that contradict each other, chapters in an order that does not teach. Changes nothing.',
                'arguments' => [
                    [
                        'name' => 'course_id',
                        'description' => 'Which course to read.',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * One prompt, filled in, in the shape `prompts/get` returns.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function get(Actor $actor, string $name, array $arguments): array
    {
        $text = match ($name) {
            'build_a_course' => self::build($arguments),
            'continue_a_course' => self::carryOn($arguments),
            'review_a_course' => self::review($arguments),
            default => throw HttpException::notFound(
                'There is no prompt called "' . $name . '". Call prompts/list to see what there is.'
            ),
        };

        $descriptions = [];
        foreach (self::catalogue() as $prompt) {
            $descriptions[$prompt['name']] = $prompt['description'];
        }

        return [
            'description' => (string)($descriptions[$name] ?? ''),
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]],
            ],
        ];
    }

    /** @param array<string,mixed> $arguments */
    private static function build(array $arguments): string
    {
        $topic = self::argument($arguments, 'topic');
        if ($topic === '') {
            throw HttpException::unprocessable(
                'This prompt needs a topic: one sentence saying what the course teaches, who it is for and how deep '
                . 'it goes.'
            );
        }
        $name = self::argument($arguments, 'name');

        return 'Write a complete course in CourseForge on this subject:' . "\n\n"
            . $topic . "\n\n"
            . ($name === '' ? '' : 'Call the course "' . $name . '".' . "\n\n")
            . 'CourseForge holds the course and hands you the briefs; you do all of the writing. It does not need '
            . 'an AI account of its own for any of this and nothing is charged to one.' . "\n\n"
            . 'How to run it:' . "\n\n"
            . '1. Call get_next_step. It reads the database and tells you the one call that moves things forward, '
            . 'with the reason and the arguments.' . "\n"
            . '2. Do exactly that step.' . "\n"
            . '3. Call get_next_step again. Repeat until it answers with "done": true.' . "\n\n"
            . 'You do not need to know the sequence in advance and you must not guess it - get_next_step is worked '
            . 'out fresh every time, so it stays right even if you lose track, start again, or come back tomorrow.' . "\n\n"
            . 'Two things worth doing well rather than quickly:' . "\n\n"
            . '- The outline decides everything downstream. get_structure_brief gives you the format it must be in '
            . 'and the rules it has to follow. Read the whole brief before designing it.' . "\n"
            . '- Each page brief carries the length bounds, the content elements that page is meant to have, and '
            . 'what the pages either side of it cover. Honour them - they are what keeps a fifty-page course from '
            . 'reading like fifty separate articles.' . "\n\n"
            . 'While you are in the page-writing loop you can go straight from write_page to the next '
            . 'get_page_brief without checking in; come back to get_next_step when get_page_brief says there is '
            . 'nothing left. Tell me how many pages there are once the outline is applied, so I know what I am '
            . 'waiting for.';
    }

    /** @param array<string,mixed> $arguments */
    private static function carryOn(array $arguments): string
    {
        $courseId = self::argument($arguments, 'course_id');

        return 'Carry on with a course in CourseForge and take it as far as it will go.' . "\n\n"
            . ($courseId === ''
                ? 'Start by calling get_next_step with no arguments - it will either point at the only course '
                    . 'there is or list the ones to choose from. If there is more than one, show me the list and '
                    . 'let me pick rather than guessing.'
                : 'The course is ' . $courseId . '. Call get_next_step with course_id ' . $courseId . '.') . "\n\n"
            . 'Then: do the step it names, call get_next_step again, and repeat until it answers with "done": true. '
            . 'It works the state out from the database each time, so it does not matter how the course was left '
            . 'or how long ago.' . "\n\n"
            . 'You do the writing - CourseForge hands over the briefs and stores what you send back, and needs no '
            . 'AI account of its own. Tell me what state you found the course in before you start changing it.';
    }

    /** @param array<string,mixed> $arguments */
    private static function review(array $arguments): string
    {
        $courseId = self::argument($arguments, 'course_id');

        return 'Read a course in CourseForge and tell me what is wrong with it. Change nothing.' . "\n\n"
            . ($courseId === ''
                ? 'Call list_courses first and show me what there is, then read the one I pick.'
                : 'The course is ' . $courseId . '.') . "\n\n"
            . 'get_course with include_content gives you the outline and the text. Read all of it, then report on:' . "\n\n"
            . '- Gaps: something the outline promises that no page delivers, or a concept used before it is taught.' . "\n"
            . '- Repetition: two pages teaching the same thing without either acknowledging the other.' . "\n"
            . '- Contradictions: pages that disagree on a fact, a version or a recommendation.' . "\n"
            . '- Order: chapters or pages that would teach better in a different sequence, and why.' . "\n"
            . '- Pages that are thin, padded, or off the brief for their place in the course.' . "\n\n"
            . 'Quote the page titles you are talking about so I can find them. Be specific and be honest - a review '
            . 'that says it is all fine is only useful if it is true. If you would change the outline, say what you '
            . 'would change it to, but do not apply anything.';
    }

    /** @param array<string,mixed> $arguments */
    private static function argument(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
