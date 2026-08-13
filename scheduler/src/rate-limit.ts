import rateLimit from 'express-rate-limit';
import { Request, Response } from 'express';

const isProduction = process.env.NODE_ENV === 'production';

function keyGenerator(req: Request): string {
  return (req.headers['x-real-ip'] as string) ||
    (req.headers['x-forwarded-for'] as string)?.split(',')[0]?.trim() ||
    req.socket.remoteAddress ||
    'unknown';
}

// Strict: POST /appointments — max 10/min
export const appointmentLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: isProduction ? 10 : 100,
  keyGenerator,
  message: { success: false, message: 'Demasiadas solicitudes. Intentá de nuevo en un minuto.' },
  standardHeaders: true,
  legacyHeaders: false,
});

// Strict: POST /customers — max 10/min
export const customerLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: isProduction ? 10 : 100,
  keyGenerator,
  message: { success: false, message: 'Demasiadas solicitudes. Intentá de nuevo en un minuto.' },
  standardHeaders: true,
  legacyHeaders: false,
});

// Moderate: general API — max 60/min
export const generalLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: isProduction ? 60 : 300,
  keyGenerator,
  message: { success: false, message: 'Demasiadas solicitudes. Intentá de nuevo en un minuto.' },
  standardHeaders: true,
  legacyHeaders: false,
});

// Strict: auth endpoints — max 5/min
export const authLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: isProduction ? 5 : 50,
  keyGenerator,
  message: { success: false, message: 'Demasiados intentos de autenticación.' },
  standardHeaders: true,
  legacyHeaders: false,
});
