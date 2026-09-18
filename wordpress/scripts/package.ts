/** Export the existing Astro build. No Markdown/TypeScript/CSS rewrite is required. */
import { readdir, readFile, mkdir, rm, copyFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';

const site = new URL(process.env.WORDPRESS_SITE_URL || 'https://melting-poppo.com');
if (site.protocol !== 'https:' || site.pathname !== '/' || site.username || site.password) throw Error('WORDPRESS_SITE_URL must be an HTTPS origin');
const sha = (x: Buffer | string) => createHash('sha256').update(x).digest('hex');
const output = 'wordpress-build';
await rm(output, { recursive: true, force: true });
await mkdir(`${output}/bundle/assets`, { recursive: true });
async function walk(dir: string): Promise<string[]> {
  const entries = await readdir(dir, {withFileTypes: true});
  const result: string[] = [];
  for (const e of entries) {
    if (e.isSymbolicLink()) throw Error(`Symlinks are not supported: ${e.name}`);
    const p = path.join(dir, e.name);
    if (e.isDirectory()) result.push(...await walk(p)); else result.push(p);
  }
  return result.sort();
}
const assets: Record<string, {sha256: string, size: number}> = {};
const records: Record<string, unknown>[] = [];
let notFound: {head: string, body: string} | null = null;
const allowed = /\.(?:css|js|mjs|png|jpe?g|gif|webp|avif|ico|svg|woff2?|ttf|otf|eot|pdf|mp4|webm|mp3|ogg|wav)$/i;
for (const file of await walk('dist')) {
  const relative = path.relative('dist', file).split(path.sep).join('/');
  const bytes = await readFile(file);
  if (relative.endsWith('.html')) {
    const html = bytes.toString('utf8');
    const metadata = html.match(/<script\b[^>]*\bid="swingby-wp-data"[^>]*>([\s\S]*?)<\/script>/);
    if (!metadata) throw Error(`Missing WordPress metadata: ${relative}. Build with WORDPRESS_SITE_URL set.`);
    const data = JSON.parse(metadata[1]);
    const head = html.match(/<head\b[^>]*>([\s\S]*?)<\/head>/)?.[1];
    const body = html.match(/<body\b[^>]*>([\s\S]*?)<\/body>/)?.[1];
    if (!head || !body) throw Error(`Invalid HTML: ${relative}`);
    if (relative === '404.html') {
      notFound = {head: head.replace(metadata[0], ''), body};
      continue;
    }
    const route = '/' + (relative === 'index.html' ? '' : relative.replace(/\/index\.html$/, '/').replace(/\.html$/, '/'));
    const content = body.match(/<article\b[^>]*id="article-content"[^>]*>([\s\S]*?)<\/article>/)?.[1]
      || body.match(/<main\b[^>]*>([\s\S]*?)<\/main>/)?.[1] || body;
    records.push({path: route, type: 'page', draft: false, ...data,
      head: head.replace(metadata[0], ''), body, content});
  } else if (allowed.test(relative)) {
    assets[relative] = {sha256: sha(bytes), size: bytes.length};
    const target = `${output}/bundle/assets/${relative}`;
    await mkdir(path.dirname(target), {recursive: true});
    await copyFile(file, target);
  } else if (!['robots.txt', 'rss.xml'].includes(relative) && !/^sitemap.*\.xml$/.test(relative)) {
    throw Error(`Unsupported public file: ${relative}. Review the export allowlist before publishing.`);
  }
}
if (!records.some(r => r.path === '/')) throw Error('The home page is missing');
if (new Set(records.map(r => r.path)).size !== records.length) throw Error('Duplicate routes');
const core = {schema: 1, site: site.origin, records, assets, notFound};
const manifest = {...core, release: sha(JSON.stringify(core)).slice(0, 32)};
await writeFile(`${output}/bundle/manifest.json`, JSON.stringify(manifest));
execFileSync('zip', ['-q', '-r', '../site.zip', '.'], {cwd: `${output}/bundle`});
execFileSync('zip', ['-q', '-r', path.resolve(`${output}/swingby-git-sync.zip`), 'swingby-git-sync'], {cwd: 'wordpress/plugin'});
execFileSync('zip', ['-q', '-r', path.resolve(`${output}/swingby-astro.zip`), 'swingby-astro'], {cwd: 'wordpress/theme'});
console.log(`Packaged ${records.length} pages/posts, ${Object.keys(assets).length} assets; release ${manifest.release}`);
