import { Request, Response, NextFunction } from 'express';
import { logger } from './logger';

interface RoutePattern {
  method: string;
  pattern: RegExp;
}

const publicRoutes: RoutePattern[] = [
  { method: 'GET', pattern: /^\/services\/?(\d+)?$/ },
  { method: 'GET', pattern: /^\/slots/ },
  { method: 'GET', pattern: /^\/availabilities/ },
  { method: 'POST', pattern: /^\/customers$/ },
  { method: 'POST', pattern: /^\/appointments$/ },
];

export function authMiddleware(req: Request, res: Response, next: NextFunction): void {
  const isPublic = publicRoutes.some(
    (r) => r.method === req.method && r.pattern.test(req.path)
  );
  if (isPublic) return next();

  const expected = process.env.API_KEY;

  const apiKey = req.headers['x-api-key'] as string | undefined;
  const match = apiKey && apiKey === expected;
  if (!match) {
    const auth = req.headers['authorization'] as string | undefined;
    if (auth && auth.startsWith('Basic ')) {
      const decoded = Buffer.from(auth.slice(6), 'base64').toString();
      const [user, pass] = decoded.split(':');
      if (user && pass === expected) return next();
    }
  }
  if (match) return next();

  if (process.env.NODE_ENV !== 'production') {
    logger.warn({ path: req.path, method: req.method }, 'Auth failed');
  }

  res.status(401).json({ success: false, message: 'API key inválida o faltante' });
}
