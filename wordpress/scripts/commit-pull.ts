import { execFileSync } from 'node:child_process';
const run = (args: string[], env = process.env) => execFileSync('git', args, {encoding:'utf8', env, stdio:['ignore','pipe','pipe']});
run(['add', '--', 'content', 'src/data/site-settings.json']);
try { run(['add', '--', 'archive/wordpress-deleted']); } catch { /* No archives in this run. */ }
if (!run(['diff', '--cached', '--name-only']).trim()) process.exit(0);
run(['-c','user.name=github-actions[bot]','-c','user.email=41898282+github-actions[bot]@users.noreply.github.com','commit','-m','Sync WordPress edits and archived articles']);
const token = process.env.GITHUB_TOKEN; if (!token) throw Error('GITHUB_TOKEN is required to commit WordPress changes');
try {
  run(['push','origin','HEAD:main'], {...process.env, GIT_CONFIG_COUNT:'1', GIT_CONFIG_KEY_0:'http.https://github.com/.extraheader', GIT_CONFIG_VALUE_0:'AUTHORIZATION: basic '+Buffer.from(`x-access-token:${token}`).toString('base64')});
} catch { throw Error('GitHub changed during sync or branch rules rejected the commit. No site build was published. Rerun after resolving the branch conflict.'); }
console.log('WordPress changes committed to GitHub.');
