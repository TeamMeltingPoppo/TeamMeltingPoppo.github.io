import { test } from 'node:test';
import assert from 'node:assert/strict';
import { tagSlug, tagUrl } from '../src/lib/tags.js';

test('tag URLs preserve Japanese and safely route reserved characters', () => {
  assert.equal(decodeURIComponent(tagUrl('日本語')), '/tags/日本語/');
  for (const tag of ['C#', 'A/B', 'a?b', '%23', '..', '~4323']) {
    const slug = tagSlug(tag);
    assert.match(slug, /^~[0-9a-f]+$/);
    assert.equal(decodeURIComponent(tagUrl(tag)), `/tags/${slug}/`);
  }
  assert.notEqual(tagSlug('C#'), tagSlug('~4323'));
});
