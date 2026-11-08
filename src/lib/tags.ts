/** Preserve existing readable routes while escaping URL-reserved tag names. */
export function tagSlug(tag: string): string {
  if (/^[~]|[#?/%\\\u0000-\u001f\u007f]/u.test(tag) || tag === '.' || tag === '..') {
    return '~' + Array.from(new TextEncoder().encode(tag), (byte) => byte.toString(16).padStart(2, '0')).join('');
  }
  return tag;
}
export function tagUrl(tag: string): string {
  return `/tags/${encodeURIComponent(tagSlug(tag))}/`;
}
