import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp, mkdir, writeFile, readFile, rm, access} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {planPull, applyPull, sha} from '../scripts/pull.ts';
const original = '---\ntitle: 元の記事\ndate: 2026-07-20\ncustom: keep-me\n---\n\n# 本文\n\n![写真](./image.png)\n';
const file = 'content/blog/example/index.md';
const revision = 'a'.repeat(64);
const record = {id:42,path:'/blog/blog/example/',sourcePath:file,sourceSha:sha(original),revision,new:false,
 data:{title:'更新後',content:'<!-- wp:paragraph --><p>編集した本文</p><!-- /wp:paragraph -->',date:'2026-07-20 00:00:00',status:'publish',tags:['タグ'],categories:['分類'],excerpt:'',featuredMedia:0}};
async function fixture(fn: (root:string)=>Promise<void>) {
 const root = await mkdtemp(path.join(tmpdir(),'swingby-pull-'));
 try {await mkdir(path.join(root,'content/blog/example'),{recursive:true});await writeFile(path.join(root,file),original);await writeFile(path.join(root,'content/blog/example/image.png'),'image');await fn(root);} finally {await rm(root,{recursive:true,force:true});}
}
const state = (posts:any[]=[], deleted:any[]=[]) => ({schema:1,posts,deleted,settingsChanged:false});
test('Unchanged articles remain byte-identical',()=>fixture(async root=>{const plan=await planPull(root,state());assert.equal(plan.writes.size,0);assert.equal(await readFile(path.join(root,file),'utf8'),original);}));
test('WordPress changes preserve frontmatter and Gutenberg HTML, with idempotent retry',()=>fixture(async root=>{
 const plan=await planPull(root,state([record]));await applyPull(root,plan);const saved=await readFile(path.join(root,file),'utf8');
 assert.match(saved,/custom: keep-me/);assert.match(saved,/wordpressId: 42/);assert.ok(saved.includes(record.data.content));
 assert.equal((await planPull(root,state([record]))).writes.size,0);
}));
test('Concurrent edits cause a conflict before any file writes',()=>fixture(async root=>{
 await writeFile(path.join(root,file),original+'GitHub edit');
 await assert.rejects(planPull(root,state([record])),/Conflict/);assert.equal(await readFile(path.join(root,file),'utf8'),original+'GitHub edit');
}));
test('Deletion archives article and image together, without deleting bytes',()=>fixture(async root=>{
 const deleted={...record,status:'trash'};const plan=await planPull(root,state([],[deleted]));await applyPull(root,plan);
 assert.equal(await readFile(path.join(root,'archive/wordpress-deleted/blog/example/index.md'),'utf8'),original);
 assert.equal(await readFile(path.join(root,'archive/wordpress-deleted/blog/example/image.png'),'utf8'),'image');
 await assert.rejects(access(path.join(root,file)));assert.equal((await planPull(root,state([],[deleted]))).moves.length,0);
}));
test('Deletion does not discard concurrent GitHub edits',()=>fixture(async root=>{await writeFile(path.join(root,file),original+'edit');await assert.rejects(planPull(root,state([],[record])),/Conflict/);}));
test('Path traversal is rejected',()=>fixture(async root=>{await assert.rejects(planPull(root,state([{...record,sourcePath:'content/blog/../../secrets.md'}])),/Unsafe/);}));
test('New WordPress post gets a stable article folder',()=>fixture(async root=>{
 const plan=await planPull(root,state([{...record,id:99,new:true,sourcePath:'content/blog/wp-99/index.md',sourceSha:null}]));await applyPull(root,plan);
 assert.match(await readFile(path.join(root,'content/blog/wp-99/index.md'),'utf8'),/wordpressId: 99/);
}));
test('Settings synchronize and reject conflicts',()=>fixture(async root=>{
 await mkdir(path.join(root,'src/data'),{recursive:true});const settings={schema:1,fields:{title:{label:'見出し',type:'text',value:'旧'}}};const text=JSON.stringify(settings)+'\n';await writeFile(path.join(root,'src/data/site-settings.json'),text);
 const changed={...state(),settingsChanged:true,settingsSha:sha(text),settingsRevision:revision,settings:{...settings,fields:{title:{label:'見出し',type:'text',value:'新'}}}};
 const plan=await planPull(root,changed);await applyPull(root,plan);assert.equal((await planPull(root,changed)).writes.size,0);
 await writeFile(path.join(root,'src/data/site-settings.json'),JSON.stringify({...settings,other:'edit'}));await assert.rejects(planPull(root,changed),/Conflict/);
}));
