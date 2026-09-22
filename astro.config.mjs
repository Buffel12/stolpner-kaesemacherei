// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  // Domain aus der Website-Architektur (stolpner-kaesemacherei.de)
  site: 'https://stolpner-kaesemacherei.de',
  integrations: [
    sitemap({
      // Rechtstexte sind noindex -> nicht in die Sitemap aufnehmen.
      filter: (page) =>
        !['/impressum', '/datenschutz', '/agb', '/widerruf'].some((p) =>
          page.replace(/\/$/, '').endsWith(p),
        ),
    }),
  ],
});
