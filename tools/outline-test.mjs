/**
 * The browser outline parser against the PHP parser's answers.
 *
 *   node tools/outline-test.mjs
 *
 * tools/outline-fixtures.json is written by `php tools/outline-fixtures.php`
 * from Structure::parse(), and tests/outline-fixtures.test.php keeps it true.
 * This compares what assets/js/core/outline.js makes of the same outlines -
 * titles, descriptions, tags, chapters and pages - ignoring the line numbers
 * the browser side adds for its scroll link, which the server has no use for.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { parseOutline } from '../assets/js/core/outline.js';

const here = dirname(fileURLToPath(import.meta.url));
const fixtures = JSON.parse(readFileSync(join(here, 'outline-fixtures.json'), 'utf8'));

/** The browser result in the server's shape. */
function shape(parsed) {
  return {
    title: parsed.title,
    description: parsed.description,
    tags: parsed.tags,
    chapters: parsed.chapters.map((chapter) => ({
      title: chapter.title,
      description: chapter.description,
      tags: chapter.tags,
      pages: chapter.pages.map((page) => ({ title: page.title, tags: page.tags })),
    })),
  };
}

let failed = 0;
for (const fixture of fixtures) {
  const got = JSON.stringify(shape(parseOutline(fixture.markdown)));
  const want = JSON.stringify(fixture.expected);
  if (got === want) {
    console.log(`  ok    ${fixture.name}`);
  } else {
    failed += 1;
    console.log(`  FAIL  ${fixture.name}\n        want ${want}\n        got  ${got}`);
  }
}

console.log(`\n${fixtures.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
