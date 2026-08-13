import express from 'express';
import cors from 'cors';
import * as path from 'path';
import * as fs from 'fs';
import { DATA_DIR, getDb } from './db';
import { authMiddleware } from './auth';
import { register as registerAppointments } from './routes/appointments';
import { register as registerCustomers } from './routes/customers';
import { register as registerServices } from './routes/services';
import { register as registerProviders } from './routes/providers';
import { register as registerSlots } from './routes/slots';
import { register as registerDaysOff } from './routes/days_off';
import { register as registerWhatsApp } from './routes/whatsapp';
import { register as registerPayments } from './routes/payments';
import { register as registerSupport } from './routes/support';
import { register as registerBranding, readBrandingConfig } from './routes/branding';
import { startCronJobs, registerWhatsAppWebhook } from './workflows';
import { logger } from './logger';
import { appointmentLimiter, customerLimiter, generalLimiter } from './rate-limit';

const app = express();
const PORT = Number(process.env.PORT) || 3000;

app.disable('x-powered-by');
app.use(cors());
app.use(express.json());

const UPLOADS_DIR = path.join(DATA_DIR, 'uploads');

// WhatsApp webhook from OpenWA (public, no auth, no rate limit)
registerWhatsAppWebhook(app);

// --- Public API ---
app.use('/api/v1', generalLimiter);
app.get('/config.json', (_req, res) => {
  res.json(readBrandingConfig());
});

// Auth: everything under /api/v1 except explicit public routes
app.use('/api/v1', authMiddleware);

const api = express.Router();
app.use('/api/v1', api);

api.use('/appointments', appointmentLimiter);
api.use('/customers', customerLimiter);

registerAppointments(api);
registerCustomers(api);
registerServices(api);
registerProviders(api);
registerSlots(api);
registerDaysOff(api);
registerWhatsApp(api);
registerPayments(api);
registerSupport(api);
registerBranding(api);

app.get('/health', (_req, res) => {
  res.json({ status: 'ok' });
});

// --- Static (public) ---
// Uploads: logo + gallery (persistent volume)
fs.mkdirSync(UPLOADS_DIR, { recursive: true });
app.use('/uploads', express.static(UPLOADS_DIR, { maxAge: '1h' }));

// Support dashboard SPA
const supportDir = path.join(__dirname, '..', 'support');
if (fs.existsSync(supportDir)) {
  app.use('/support', express.static(supportDir));
  logger.info('Support dashboard served at /support/');
}

// Admin SPA (Fase 2: scheduler/public/admin)
const adminDir = path.join(__dirname, '..', 'public', 'admin');
if (fs.existsSync(adminDir)) {
  app.use('/admin', express.static(adminDir));
  logger.info('Admin SPA served at /admin/');
}

// Landing pública (SPA Lado A + Lado B)
const landingDir = path.join(__dirname, '..', '..', 'web');
if (fs.existsSync(path.join(landingDir, 'index.html'))) {
  app.use(express.static(landingDir, { maxAge: '1h' }));
  logger.info('Landing served at /');
} else {
  app.get('/', (_req, res) => res.send('TuHora API. Landing no encontrada.'));
}

getDb();
app.listen(PORT, () => {
  logger.info({ port: PORT }, 'tuahora-scheduler started');
  startCronJobs();
});
