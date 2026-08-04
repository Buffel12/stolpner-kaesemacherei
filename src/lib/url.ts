// Hilfsfunktion für interne Pfade.
// Verbindet den von Astro gesetzten BASE_URL (z. B. „/stolpner-kaesemacherei/")
// mit einem absoluten Pfad wie „/kurs" oder „/images/foo.jpg", damit interne
// Links und Bilder auch dann funktionieren, wenn die Seite in einem
// Unterordner läuft (GitHub Pages). Doppelte Schrägstriche werden vermieden.
const BASE = import.meta.env.BASE_URL;

export function withBase(path: string): string {
  return `${BASE.replace(/\/$/, '')}/${path.replace(/^\//, '')}`;
}
