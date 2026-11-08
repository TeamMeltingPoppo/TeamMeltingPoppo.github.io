import { parseDocument, stringify, isMap } from 'yaml';

export function createArticle(fields: { title: string; description: string; date: string; category: string; tags: string[] }): string {
  return `---\n${stringify({ ...fields, draft: true })}---\n`;
}

export function updateArticleDate(content: string, date: string): string {
  const opening = content.match(/^---\r?\n/);
  if (!opening) throw new Error('Missing front matter opening delimiter');
  const remainder = content.slice(opening[0].length);
  const closing = /^---\s*\r?$/m.exec(remainder);
  if (!closing) throw new Error('Missing front matter closing delimiter');
  const yaml = remainder.slice(0, closing.index);
  const doc = parseDocument(yaml, { uniqueKeys: true });
  if (doc.errors.length || !isMap(doc.contents)) throw new Error('Invalid front matter');
  doc.set('updatedDate', date);
  const lineEnding = opening[0].endsWith('\r\n') ? '\r\n' : '\n';
  const serialized = String(doc).replace(/\r?\n/g, lineEnding);
  return opening[0] + serialized + remainder.slice(closing.index);
}
