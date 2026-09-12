// content.config.ts — Datenmodell der Kurstermine (Astro Content Collections).
// Jeder Kurstermin ist eine kleine Markdown-Datei in src/content/kurse/.
// Die Kurs-Seite (/kurs) liest diese Sammlung aus, blendet vergangene
// Termine automatisch aus und zeigt pro Termin den freien-Plätze-Status.
import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const kurse = defineCollection({
  loader: glob({ pattern: ['**/*.md', '!**/README.md'], base: './src/content/kurse' }),
  schema: z.object({
    /** Überschrift des Termins (meist Standard). */
    titel: z.string().default('Käsekurs am Kessel'),
    /** Kursdatum (YYYY-MM-DD). Vergangene Termine werden ausgeblendet. */
    datum: z.coerce.date(),
    /** Startzeit als Text, z. B. "10:00". */
    startzeit: z.string().default('10:00'),
    /** Dauer in Stunden. */
    dauerStunden: z.number().default(4),
    /** Preis pro Person in Euro (Zahlung in bar vor Ort). */
    preis: z.number().default(69),
    /** Maximale Teilnehmerzahl. */
    plaetze: z.number().default(14),
    /** Anzahl der noch freien Plätze — das einzige, was von Hand gepflegt wird.
     *  Daraus wird automatisch abgeleitet:
     *  frei = 0        → ausgebucht (Button deaktiviert)
     *  frei = 1 bis 3  → „nur noch wenige Plätze" (gelbe Markierung)
     *  frei > 3        → normal buchbar (grüne Markierung) */
    frei: z.number().default(14),
    /** Optionaler Zusatzhinweis, z. B. "Sonderkurs mit Räuchern". */
    hinweis: z.string().optional(),
  }),
});

export const collections = { kurse };
