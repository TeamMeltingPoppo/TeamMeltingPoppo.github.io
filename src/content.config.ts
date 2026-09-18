import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const blogFiles = glob({ base: './content', pattern: '**/*.{md,mdx}' });
const blog = defineCollection({
	// Load Markdown and MDX files in the `src/content` directory.
	loader: {
        name: 'swingby-blog',
        async load(context) {
            // Clear cached entries even when all articles were archived.
            context.store.clear();
            await blogFiles.load(context);
        },
    },
	// Type-check frontmatter using a schema
	schema: ({ image }) =>
		z.object({
			title: z.string(),
			date: z.coerce.date(),
			description: z.string().optional(),
			updatedDate: z.coerce.date().optional(),
			tags: z.array(z.string()).optional(),
			category: z.string().optional(),
			categories: z.array(z.string()).optional(),
			heroImage: image().optional(),
			draft: z.boolean().optional(),
            wordpressStatus: z.enum(['publish','future','draft','pending']).optional(),
            wordpressFormat: z.literal('html').optional(),
            wordpressExcerpt: z.string().optional(),
            wordpressFeaturedMedia: z.number().int().nonnegative().optional(),
            heroImageURL: z.string().url().optional(),
            wordpressId: z.number().int().positive().optional(),
            wordpressRevision: z.string().optional(),
		}),
});

export const collections = { blog };
