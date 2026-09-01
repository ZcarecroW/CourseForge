/**
 * How to point an MCP client at this installation.
 *
 * CourseForge serves one endpoint over streamable HTTP with a bearer token, and
 * that is the whole of what a client needs. What differs between clients is only
 * where the configuration lives and what the keys are called — and they disagree
 * about all of it: the top-level object is `mcpServers` in four of them and
 * `servers` in VS Code, the URL field is `url` everywhere except Gemini CLI,
 * where it is `httpUrl`, and Codex uses TOML rather than JSON.
 *
 * Every recipe below was read off that client's own current documentation
 * rather than remembered. A setup line that is 95% right is worse than none at
 * all: it fails with an error about the client's config format rather than
 * about CourseForge, so whoever is following it has no reason to look here.
 *
 * Two rules the endpoint imposes, and they are why the recipes look like this:
 *
 * - The URL is whatever the installation says it is. `mcp.public_url` is a
 *   complete address, not a prefix, so behind a proxy the path may be `/mcp` or
 *   anything else. No recipe may build the URL out of a host and a known suffix.
 * - The token goes in an `Authorization: Bearer` header. Where a client can read
 *   it from the environment instead of holding it in the file, the recipe says
 *   so, because these files get committed.
 */

/** A shell-safe single-quoted value, for the two recipes that are commands. */
const shq = (value) => `'${String(value).replace(/'/g, `'\\''`)}'`;

/**
 * Every client, in the order somebody is likely to want them.
 *
 * `build(url, token, name)` returns the text to paste. `note` is the sentence
 * under it, and `where` says which file it goes in — omitted for the two that
 * are commands and write the file themselves.
 */
export const MCP_CLIENTS = [
  {
    key: 'claude-code',
    label: 'Claude Code',
    kind: 'shell',
    note: 'Run it in any directory. Add --scope user to make the connection available everywhere '
      + 'rather than in that project alone.',
    build: (url, token, name) =>
      `claude mcp add --transport http ${name} ${url} \\\n  --header "Authorization: Bearer ${token}"`,
  },
  {
    key: 'claude-desktop',
    label: 'Claude desktop app',
    kind: 'json',
    where: 'claude_desktop_config.json — Settings ▸ Developer ▸ Edit Config',
    note: 'The desktop app runs on your machine but not in your terminal, so a CourseForge on '
      + 'localhost is reachable and one behind a VPN is only reachable if the app is too.',
    build: (url, token, name) => JSON.stringify({
      mcpServers: { [name]: { type: 'http', url, headers: { Authorization: `Bearer ${token}` } } },
    }, null, 2),
  },
  {
    key: 'codex',
    label: 'OpenAI Codex CLI',
    kind: 'toml',
    where: '~/.codex/config.toml, or .codex/config.toml beside a project',
    note: 'bearer_token_env_var names an environment variable rather than holding the token, so '
      + 'this file stays safe to commit. Export COURSEFORGE_TOKEN in your shell profile.',
    build: (url, token, name) =>
      `[mcp_servers.${name}]\n`
      + `url = "${url}"\n`
      + 'bearer_token_env_var = "COURSEFORGE_TOKEN"\n'
      + '# A page can take minutes to write, and the default is sixty seconds.\n'
      + 'tool_timeout_sec = 1800\n\n'
      + `# then, in your shell profile:\n# export COURSEFORGE_TOKEN=${token}`,
  },
  {
    key: 'cursor',
    label: 'Cursor',
    kind: 'json',
    where: '.cursor/mcp.json in a project, or ~/.cursor/mcp.json for all of them',
    note: 'Cursor expands ${env:NAME} in this file, so the token can stay out of it — put '
      + 'COURSEFORGE_TOKEN in your shell profile and write "Bearer ${env:COURSEFORGE_TOKEN}" in place '
      + 'of the token above.',
    build: (url, token, name) => JSON.stringify({
      mcpServers: { [name]: { url, headers: { Authorization: `Bearer ${token}` } } },
    }, null, 2),
  },
  {
    key: 'vscode',
    label: 'VS Code (Copilot)',
    kind: 'json',
    where: '.vscode/mcp.json',
    note: 'VS Code calls the top-level object servers rather than mcpServers, which is the one '
      + 'thing that catches people moving a working file between editors.',
    build: (url, token, name) => JSON.stringify({
      servers: { [name]: { type: 'http', url, headers: { Authorization: `Bearer ${token}` } } },
    }, null, 2),
  },
  {
    key: 'gemini-cli',
    label: 'Gemini CLI',
    kind: 'json',
    where: '~/.gemini/settings.json, or .gemini/settings.json beside a project',
    note: 'Gemini CLI names the address httpUrl rather than url. A url here would be read as an '
      + 'SSE endpoint, which this is not.',
    build: (url, token, name) => JSON.stringify({
      mcpServers: { [name]: { httpUrl: url, headers: { Authorization: `Bearer ${token}` } } },
    }, null, 2),
  },
  {
    key: 'other',
    label: 'Anything else',
    kind: 'text',
    note: 'The whole contract. Any client that speaks streamable HTTP and can send one header '
      + 'can drive this installation; there is nothing else to configure.',
    build: (url, token) =>
      `Transport   streamable HTTP (POST JSON-RPC)\n`
      + `Endpoint    ${url}\n`
      + `Header      Authorization: Bearer ${token}\n\n`
      + `# a request you can paste into a terminal to check the token works:\n`
      + `curl -sS ${shq(url)} \\\n`
      + `  -H ${shq(`Authorization: Bearer ${token}`)} \\\n`
      + `  -H 'Content-Type: application/json' \\\n`
      + `  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'`,
  },
];

/** The language to colour a recipe as, for the block that shows it. */
export const RECIPE_LANGUAGE = { shell: 'bash', json: 'json', toml: 'toml', text: 'text' };
