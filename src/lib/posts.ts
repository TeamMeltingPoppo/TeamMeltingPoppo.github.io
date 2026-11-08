import { getCollection } from 'astro:content';

export async function publishedPosts() {
  return (await getCollection('blog')).filter((post) => post.data.draft !== true);
}
