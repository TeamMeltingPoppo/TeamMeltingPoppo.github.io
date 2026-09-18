import { readFile } from 'node:fs/promises';
const base = new URL(process.env.WORDPRESS_SITE_URL || 'https://melting-poppo.com');
if (base.protocol !== 'https:' || base.pathname !== '/' || base.username || base.password) throw Error('An HTTPS origin is required');
const username = process.env.WORDPRESS_USERNAME;
const password = process.env.WORDPRESS_APP_PASSWORD;
if (!username || !password) throw Error('Set WORDPRESS_USERNAME and WORDPRESS_APP_PASSWORD in GitHub Secrets');
const form = new FormData();
form.append('bundle', new Blob([await readFile('wordpress-build/site.zip')], {type: 'application/zip'}), 'site.zip');
const endpoint = new URL('/?rest_route=/swingby-git/v1/stage', base);
const response = await fetch(endpoint, {method: 'POST', redirect: 'error',
  signal: AbortSignal.timeout(180000),
  headers: {Authorization: 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64')}, body: form});
// Do not print server HTML or credentials into Actions logs.
if (!response.ok) throw Error(`WordPress staging failed (HTTP ${response.status}). Check plugin activation, credentials and upload limits.`);
const result = await response.json();
console.log(`WordPress: release ${result.release}, ${result.count} records, status=${result.status}. See Tools → Swingby Git Sync.`);
