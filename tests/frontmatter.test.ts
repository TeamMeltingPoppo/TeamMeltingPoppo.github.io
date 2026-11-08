import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parse } from 'yaml';
import { createArticle, updateArticleDate } from '../cli/frontmatter.js';

test('generator preserves YAML punctuation and Unicode', () => {
  const data = { title: '翼: #1 "試験"', description: 'line: value # text', date: '2026-11-08', category: 'other', tags: ['日本語', 'a:b', '#test'] };
  const output = createArticle(data);
  assert.deepEqual(parse(output.replace(/^---\n|---\n$/g, '')), { ...data, draft: true });
});

test('updates only front matter with or without existing date', () => {
  for (const old of ['', 'updatedDate: 2020-01-01\n']) {
    const body = '\n本文 updatedDate: keep\n---\n内容\n';
    const output = updateArticleDate(`---\ntitle: test\n${old}---${body}`, '2026-11-08');
    assert.ok(output.endsWith(`---${body}`));
    assert.equal(parse(output.split('---')[1]).updatedDate, '2026-11-08');
  }
});

test('invalid or missing front matter is rejected', () => {
  for (const value of ['body', '---\ntitle: [broken\n---\nbody', '---\ntitle: x\nbody']) {
    assert.throws(() => updateArticleDate(value, '2026-11-08'));
  }
});
