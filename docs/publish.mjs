#!/usr/bin/env node
/**
 * Builds the documentation and publishes it to the gh-pages branch, which
 * GitHub Pages serves at https://zcarecrow.github.io/CourseForge/.
 *
 *   node docs/publish.mjs            build, then push docs/dist to gh-pages
 *
 * The branch holds the generated site and nothing else, as one commit that is
 * replaced on every publish: the pages are derived from docs/ on main, so a
 * history of them would only be a second, larger copy of main's history.
 *
 * DOCS_REMOTE overrides where it is pushed (the workflow passes a URL carrying
 * its token); the default is this checkout's origin.
 */
import { execFileSync } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const DOCS = dirname(fileURLToPath(import.meta.url));
const ROOT = join(DOCS, '..');
const OUT = join(DOCS, 'dist');

const run = (cmd, args, cwd = ROOT) => execFileSync(cmd, args, { cwd, stdio: 'inherit' });
const read = (args) => {
  try { return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim(); } catch { return ''; }
};

run(process.execPath, [join(DOCS, 'build.mjs'), ...process.argv.slice(2)]);

const remote = process.env.DOCS_REMOTE || read(['remote', 'get-url', 'origin']);
const commit = read(['rev-parse', '--short', 'HEAD']) || 'a working tree';
const name = read(['config', 'user.name']) || 'github-actions[bot]';
const email = read(['config', 'user.email']) || '41898282+github-actions[bot]@users.noreply.github.com';
if (!remote) throw new Error('No remote to publish to: set DOCS_REMOTE or add an origin.');

// GitHub Pages runs Jekyll over a branch unless told not to, and Jekyll drops
// nothing here but would cost a build step.
writeFileSync(join(OUT, '.nojekyll'), '');

rmSync(join(OUT, '.git'), { recursive: true, force: true });
run('git', ['init', '-q', '-b', 'gh-pages'], OUT);
run('git', ['-c', 'core.autocrlf=false', 'add', '-A'], OUT);
run('git', ['-c', `user.name=${name}`, '-c', `user.email=${email}`, '-c', 'core.autocrlf=false',
  'commit', '-q', '-m', `Documentation built from ${commit}`], OUT);
run('git', ['push', '-q', '-f', remote, 'gh-pages'], OUT);
rmSync(join(OUT, '.git'), { recursive: true, force: true });

console.log(`Published to gh-pages. GitHub Pages serves it within a minute or two.`);
