export const PROVIDER_ID = Number(process.env.PROVIDER_ID) || 5;

// CORS allowlist para la SPA en otra origen (GitHub Pages → API en Railway).
// Lista separada por comas de orígenes exactos permitidos (sin path, sin barra final).
// Vacía (default) = sin restricción de origen (comportamiento dev local / same-origin).
// NO usar RAILWAY_PRIVATE_DOMAIN (.railway.internal): los navegadores no lo resuelven.
export const CORS_ALLOWED_ORIGINS: string[] = (process.env.CORS_ALLOWED_ORIGINS || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean);
