import { readFile, writeFile, mkdir, rename, stat, lstat, readdir } from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { parseDocument } from 'yaml';

export const sha = (s: string | Buffer) => createHash('sha256').update(s).digest('hex');
async function exists(p: string) { try { await lstat(p); return true; } catch (e: any) { if (e.code === 'ENOENT') return false; throw e; } }
function sourcePath(p: any) {
  if (typeof p !== 'string' || !/^content\/blog\/.+\.mdx?$/.test(p) || p.split('/').some(x => x === '.' || x === '..' || !x) || /[\\\x00-\x1f]/.test(p)) throw Error('Unsafe article source path');
  return p;
}
function sourceFor(r: any) {
  if (r.sourcePath) return sourcePath(r.sourcePath);
  if (typeof r.path !== 'string' || !r.path.startsWith('/blog/blog/')) throw Error('Unknown legacy article route');
  return sourcePath('content/' + decodeURIComponent(r.path.slice(6)).replace(/\/$/, '') + '/index.md');
}
async function assertNoSymlinks(root: string, relative: string) {
  let current = root;
  for (const part of relative.split('/')) {
    current = path.join(current, part);
    if (await exists(current) && (await lstat(current)).isSymbolicLink()) throw Error('Symlink source is not supported');
  }
}
function markdown(s: string) {
  const match = s.match(/^---\r?\n([\s\S]*?)\r?\n---(?:\r?\n|$)/);
  if (!match) throw Error('Article frontmatter is missing');
  const doc = parseDocument(match[1]); if (doc.errors.length) throw Error('Invalid article YAML');
  return {doc, body: s.slice(match[0].length)};
}
export async function planPull(root: string, state: any) {
  if (state.schema !== 1 || !Array.isArray(state.posts) || !Array.isArray(state.deleted)) throw Error('Unsupported WordPress sync state');
  const writes = new Map<string, string>(); const moves: {from: string, to: string}[] = [];
  for (const r of state.posts) {
    const file = sourceFor(r); await assertNoSymlinks(root, file);
    const absolute = path.join(root, file); const old = await exists(absolute) ? await readFile(absolute, 'utf8') : null;
    const parsed = markdown(old ?? '---\n{}\n---\n');
    if (old && parsed.doc.get('wordpressRevision') === r.revision && parsed.doc.get('wordpressId') === r.id) continue;
    if (old && (!r.sourceSha || sha(old) !== r.sourceSha)) throw Error(`Conflict: ${file} was also changed in GitHub. Neither version was overwritten.`);
    if (!old && !r.new) throw Error(`Conflict: managed source ${file} is missing in GitHub.`);
    if (!r.data || !Number.isInteger(r.id) || !/^[a-f0-9]{64}$/.test(r.revision)) throw Error('Invalid WordPress article state');
    const d = r.data;
    if (!['publish','future','draft','pending'].includes(d.status)) throw Error('Unsupported publication status');
    parsed.doc.set('title', d.title);
    parsed.doc.set('date', (d.date && d.date !== '0000-00-00 00:00:00' ? d.date.replace(' ', 'T') : new Date().toISOString().slice(0, 19)) + 'Z');
    parsed.doc.set('tags', d.tags); parsed.doc.set('categories', d.categories); parsed.doc.delete('category');
    parsed.doc.set('draft', !['publish', 'future'].includes(d.status));
    parsed.doc.set('wordpressStatus', d.status); parsed.doc.set('wordpressId', r.id); parsed.doc.set('wordpressRevision', r.revision);
    if (r.contentChanged !== false) parsed.doc.set('wordpressFormat', 'html'); parsed.doc.set('wordpressExcerpt', d.excerpt || '');
    parsed.doc.set('wordpressFeaturedMedia', d.featuredMedia || 0);
    if (d.featuredURL) parsed.doc.set('heroImageURL', d.featuredURL); else parsed.doc.delete('heroImageURL');
    writes.set(file, `---\n${parsed.doc.toString()}---\n\n${r.contentChanged === false ? parsed.body.trim() : d.content}\n`);
  }
  for (const r of state.deleted) {
    const file = sourceFor(r); await assertNoSymlinks(root, file);
    if (!await exists(path.join(root, file))) continue;
    const old = await readFile(path.join(root, file), 'utf8');
    if (!r.sourceSha || sha(old) !== r.sourceSha) throw Error(`Conflict: deleted article ${file} also has GitHub changes.`);
    if (writes.has(file)) throw Error('Article is both edited and deleted');
    const from = /^index\.mdx?$/.test(path.basename(file)) ? path.dirname(file) : file;
    const to = 'archive/wordpress-deleted/' + from.slice('content/'.length);
    await assertNoSymlinks(root, to);
    if (await exists(path.join(root, to))) throw Error(`Archive already exists: ${to}`);
    // Do not accidentally archive a second article nested in the same directory.
    if (from !== file) {
      const walk = async (rel: string): Promise<void> => {
        for (const e of await readdir(path.join(root, rel), {withFileTypes:true})) {
          const child = rel + '/' + e.name;
          if (e.isSymbolicLink()) throw Error('Symlink archive is not supported');
          if (e.isDirectory()) await walk(child);
          else if (/\.mdx?$/.test(e.name) && child !== file) throw Error('An article folder contains another Markdown file; resolve manually');
        }
      }; await walk(from);
    }
    moves.push({from, to});
  }
  if (state.settingsChanged) {
    const file = 'src/data/site-settings.json'; await assertNoSymlinks(root, file); const old = await readFile(path.join(root, file), 'utf8');
    const current = JSON.parse(old);
    if (current.wordpressRevision !== state.settingsRevision) {
      if (!state.settingsSha || sha(old) !== state.settingsSha) throw Error('Conflict: site settings were changed in both WordPress and GitHub.');
      writes.set(file, JSON.stringify({...state.settings, wordpressRevision:state.settingsRevision}, null, 2) + '\n');
    }
  }
  return {writes, moves};
}
export async function applyPull(root: string, plan: Awaited<ReturnType<typeof planPull>>) {
  for (const {from, to} of plan.moves) { await mkdir(path.dirname(path.join(root, to)), {recursive:true}); await rename(path.join(root, from), path.join(root, to)); }
  for (const [file, content] of plan.writes) { await mkdir(path.dirname(path.join(root, file)), {recursive:true}); await writeFile(path.join(root, file), content); }
}
async function main() {
  const base = new URL(process.env.WORDPRESS_SITE_URL || 'https://melting-poppo.com');
  if (base.protocol !== 'https:' || base.pathname !== '/' || base.username || base.password) throw Error('An HTTPS origin is required');
  const {WORDPRESS_USERNAME: username, WORDPRESS_APP_PASSWORD: password} = process.env;
  if (!username || !password) throw Error('WordPress credentials are required');
  const response = await fetch(new URL('/?rest_route=/swingby-git/v1/state', base), {redirect:'error', signal:AbortSignal.timeout(30000), headers:{Authorization:'Basic '+Buffer.from(`${username}:${password}`).toString('base64')}});
  let changed = false; let publish = false; let supported = false;
  if (response.status === 404) console.log('::warning::Install Swingby Git Sync 0.3.0 to enable two-way sync. Site publication is skipped until the plugin is updated.');
  else {
    if (!response.ok) throw Error(`WordPress sync state failed (HTTP ${response.status}). No source files were changed.`);
    supported = true;
    const state = await response.json();
    const plan = await planPull(process.cwd(), state);
    publish = state.requiresBootstrap || state.posts.length > 0 || state.settingsChanged || state.deleted.some((r: any) => r.needsPublish);
    await applyPull(process.cwd(), plan); changed = plan.writes.size + plan.moves.length > 0;
    console.log(`WordPress changes: ${plan.writes.size} source files, ${plan.moves.length} archived articles.`);
  }
  if (process.env.GITHUB_OUTPUT) await writeFile(process.env.GITHUB_OUTPUT, `changed=${changed}\npublish=${publish || changed}\nsupported=${supported}\n`, {flag:'a'});
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
