<?php
/**
 * Multi Round-Trip Requests: the continuation, and every way it must not work.
 *
 * The happy path is the least interesting thing here. `requestState` travels
 * through the client, which the protocol says to treat as attacker-controlled,
 * so what matters is that a blob which has been forged, replayed, re-aimed at
 * another tool, or kept while the arguments changed underneath it is refused -
 * and refused with a sentence a model can act on rather than a code.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Mcp\Ask;
use CourseForge\Mcp\Guide;
use CourseForge\Mcp\NeedsInput;
use CourseForge\Mcp\Prompts;
use CourseForge\Mcp\RequestState;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;

const MRTR_CLIENT = 41;
const MRTR_USER = 'zed';
const MRTR_TOOL = 'apply_structure';

/** @return array<string,mixed> */
function mrtrArgs(): array
{
    return ['course_id' => 7, 'structure' => "# A course\n\n1. One\n   x.\n   1. Page\n"];
}

/* ------------------------------------------------------------ the round trip */

test('a continuation issued for a call is accepted back on that same call', function () {
    $state = RequestState::issue(MRTR_CLIENT, MRTR_USER, MRTR_TOOL, mrtrArgs(), ['note' => 'kept']);

    $result = RequestState::redeem($state, MRTR_CLIENT, MRTR_USER, MRTR_TOOL, mrtrArgs());
    ok($result['ok'] === true, 'it verifies');
    same(['note' => 'kept'], $result['carry'], 'and what was carried comes back');
});

test('the argument digest does not care what order the keys arrived in', function () {
    $state = RequestState::issue(MRTR_CLIENT, MRTR_USER, MRTR_TOOL, mrtrArgs());

    // A client that re-serialises its own arguments has no obligation to
    // preserve key order, and refusing it for that would be a refusal nobody
    // could diagnose.
    $reordered = array_reverse(mrtrArgs(), true);
    ok(
        RequestState::redeem($state, MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $reordered)['ok'] === true,
        'the same arguments in another order are the same arguments'
    );
});

/* --------------------------------------------------------- the ways it fails */

test('a continuation can be redeemed exactly once', function () {
    $args = mrtrArgs();
    $state = RequestState::issue(MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $args);

    ok(RequestState::redeem($state, MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $args)['ok'] === true, 'the first time');

    $second = RequestState::redeem($state, MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $args);
    ok($second['ok'] === false, 'and never again');
    ok(str_contains($second['why'], 'already been used'), 'said in words: ' . $second['why']);
});

test('a continuation is bound to the connection that was issued it', function () {
    $args = mrtrArgs();
    $state = RequestState::issue(MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $args);

    $other = RequestState::redeem($state, MRTR_CLIENT + 1, MRTR_USER, MRTR_TOOL, $args);
    ok($other['ok'] === false, 'another connection on the same account cannot use it');

    $state2 = RequestState::issue(MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $args);
    $stranger = RequestState::redeem($state2, MRTR_CLIENT, 'somebody-else', MRTR_TOOL, $args);
    ok($stranger['ok'] === false, 'and neither can another account on the same connection');
});

test('a continuation is bound to the tool it was issued for', function () {
    $args = mrtrArgs();
    $state = RequestState::issue(MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $args);

    // The one that would matter: a confirmation collected for an outline
    // change, redeemed against a deletion.
    $aimed = RequestState::redeem($state, MRTR_CLIENT, MRTR_USER, 'delete_course', $args);
    ok($aimed['ok'] === false, 'it cannot be pointed at another tool');
    ok(str_contains($aimed['why'], MRTR_TOOL), 'and the refusal names the tool it was for: ' . $aimed['why']);
});

test('a continuation does not cover arguments that changed under it', function () {
    $state = RequestState::issue(MRTR_CLIENT, MRTR_USER, MRTR_TOOL, mrtrArgs());

    $changed = mrtrArgs();
    $changed['structure'] .= "2. Two\n   y.\n   1. Another\n";

    $result = RequestState::redeem($state, MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $changed);
    ok($result['ok'] === false, 'a confirmation only covers the request it was asked about');
    ok(str_contains($result['why'], 'arguments changed'), $result['why']);
});

test('a forged or tampered continuation is refused before it is parsed', function () {
    $args = mrtrArgs();
    $state = RequestState::issue(MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $args);
    [$body, $signature] = explode('.', $state);

    $flipped = $signature[0] === 'A' ? 'B' : 'A';
    $bad = RequestState::redeem(
        $body . '.' . $flipped . substr($signature, 1),
        MRTR_CLIENT,
        MRTR_USER,
        MRTR_TOOL,
        $args
    );
    ok($bad['ok'] === false, 'a signature that does not match');

    // The claims rewritten, the old signature kept - the attack the HMAC is for.
    $payload = json_decode(
        (string)base64_decode(strtr($body, '-_', '+/'), true),
        true
    );
    $payload['sub'] = 'somebody-else';
    $rebuilt = rtrim(strtr(base64_encode((string)json_encode($payload)), '+/', '-_'), '=');

    $swapped = RequestState::redeem($rebuilt . '.' . $signature, MRTR_CLIENT, 'somebody-else', MRTR_TOOL, $args);
    ok($swapped['ok'] === false, 'and a payload rewritten under a signature it does not belong to');

    foreach (['', 'nonsense', 'a.b.c', '.', 'YQ'] as $rubbish) {
        ok(
            RequestState::redeem($rubbish, MRTR_CLIENT, MRTR_USER, MRTR_TOOL, $args)['ok'] === false,
            'and ' . ($rubbish === '' ? 'an empty string' : '"' . $rubbish . '"') . ' is refused rather than fatal'
        );
    }
});

/* ------------------------------------------------------------------ asking */

test('a tool asks only when the client said it could be asked', function () {
    Ask::begin([], false);
    ok(!Ask::canAsk(), 'a client that declared nothing is not asked anything');

    Ask::begin([], true);
    ok(Ask::canAsk(), 'and one that did, is');
    Ask::end();
    ok(!Ask::canAsk(), 'and the flag does not survive the call');
});

test('an unanswered question throws the refusal to use instead', function () {
    Ask::begin([], true);
    $thrown = raises(
        static fn() => Ask::confirm('k', 'Delete twelve pages?', 'Pass confirm_removals: true to go ahead.'),
        'an unanswered confirm must stop the call'
    );
    ok($thrown instanceof NeedsInput, 'as a NeedsInput');
    ok(!$thrown->settled, 'which is worth asking');
    same('Pass confirm_removals: true to go ahead.', $thrown->insteadSay, 'carrying the fallback sentence');
    Ask::end();
});

test('an answer of yes is a yes, and everything else is not', function () {
    $key = 'k';

    Ask::begin([$key => ['action' => 'accept', 'content' => ['confirm' => true]]], true);
    ok(Ask::confirm($key, 'Go ahead?', 'fallback'), 'ticked and accepted');
    Ask::end();

    Ask::begin([$key => ['action' => 'accept', 'content' => ['confirm' => false]]], true);
    ok(!Ask::confirm($key, 'Go ahead?', 'fallback'), 'accepted with the box unticked is a no');
    Ask::end();

    // Declining and dismissing are different from each other and from a no,
    // and both must stop rather than ask again.
    foreach (['decline', 'cancel'] as $action) {
        Ask::begin([$key => ['action' => $action]], true);
        $thrown = raises(
            static fn() => Ask::confirm($key, 'Go ahead?', 'fallback'),
            $action . ' must stop the call'
        );
        ok($thrown instanceof NeedsInput && $thrown->settled, $action . ' is settled, so it is never re-asked');
        Ask::end();
    }
});

test('an elicitation form is flat, and never asks for a credential', function () {
    Ask::begin([], true);
    $question = raises(
        static fn() => Ask::confirm('k', 'Go ahead?', 'fallback'),
        'to get at the shape'
    );
    $request = $question->request();

    same('elicitation/create', $request['method'], 'the method the protocol names');
    same('form', $request['params']['mode'], 'form mode');

    foreach ($request['params']['requestedSchema']['properties'] as $name => $property) {
        ok(
            in_array($property['type'], ['string', 'number', 'integer', 'boolean'], true),
            'every property is a primitive, as form mode requires: ' . $name
        );
        ok(
            !preg_match('/password|secret|token|key|card/i', $name),
            'and none of them is a credential: ' . $name
        );
    }
    Ask::end();
});

/* ------------------------------------------------------------------- guide */

test('the guide names a tool only when the connection holds its group', function () {
    $actor = Actor::make('zed', 'Zed', Actor::ROLE_ADMIN);

    $previous = Scopes::using([Scopes::COURSES]);
    $step = Guide::nextStep($actor, null);
    Scopes::using($previous);

    ok(isset($step['state']), 'it answers with a state');
    if (($step['next'] ?? null) !== null) {
        ok(
            Scopes::holds(Scopes::COURSES) || $step['next']['tool'] === 'create_course',
            'and the tool it names is one this connection could call'
        );
    }
});

test('outside a tool call the guide assumes nothing is narrowed', function () {
    // Scopes::holds() is asked by the guide, and the tests, the catalogue and
    // anything else that reaches a handler directly are not inside a call. If
    // an empty set meant "holds nothing", the guide would tell every direct
    // caller that everything is blocked.
    Scopes::using([]);
    foreach (Scopes::keys() as $scope) {
        ok(Scopes::holds($scope), 'holds ' . $scope . ' when nothing is installed');
    }
});

test('scopes installed for one call do not leak into the next', function () {
    $previous = Scopes::using([Scopes::PAGES]);
    ok(!Scopes::holds(Scopes::ADMIN), 'narrowed while installed');
    Scopes::using($previous);
    ok(Scopes::holds(Scopes::ADMIN), 'and restored afterwards');
});

/* ----------------------------------------------------------------- prompts */

test('every prompt can be fetched with the arguments it declares', function () {
    $actor = Actor::make('zed', 'Zed', Actor::ROLE_ADMIN);

    foreach (Prompts::catalogue() as $prompt) {
        $arguments = [];
        foreach ($prompt['arguments'] as $argument) {
            if ($argument['required']) {
                $arguments[$argument['name']] = 'something';
            }
        }

        $got = Prompts::get($actor, $prompt['name'], $arguments);
        ok($got['messages'] !== [], $prompt['name'] . ' returns a message');
        ok(
            strlen($got['messages'][0]['content']['text']) > 200,
            $prompt['name'] . ' says enough to act on'
        );
        ok(
            str_contains($got['messages'][0]['content']['text'], 'get_next_step')
            || str_contains($got['messages'][0]['content']['text'], 'list_courses'),
            $prompt['name'] . ' points at a tool rather than reciting a sequence'
        );
    }
});

test('a prompt that does not exist is refused by name', function () {
    $actor = Actor::make('zed', 'Zed', Actor::ROLE_ADMIN);
    $thrown = raises(
        static fn() => Prompts::get($actor, 'no_such_prompt', []),
        'an unknown prompt must be refused'
    );
    ok(str_contains($thrown->getMessage(), 'no_such_prompt'), 'and named: ' . $thrown->getMessage());
});

test('a required prompt argument is required', function () {
    $actor = Actor::make('zed', 'Zed', Actor::ROLE_ADMIN);
    raises(
        static fn() => Prompts::get($actor, 'build_a_course', []),
        'build_a_course without a topic has nothing to build'
    );
});

/* ------------------------------------------------------------------- tools */

test('get_next_step is registered, readable and costs nothing', function () {
    $tool = Tools::registry()['get_next_step'] ?? null;
    ok($tool !== null, 'it is in the registry');
    ok($tool->readOnly, 'it changes nothing');
    ok(!$tool->spends, 'and spends nothing');
    same(Scopes::COURSES, $tool->scope, 'it is scope-gated like list_courses, not exempt');
    ok(!$tool->alwaysAvailable, 'whoami stays the only exemption');
});
