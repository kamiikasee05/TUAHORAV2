import { Router, Request, Response } from 'express';
import * as fs from 'fs';
import * as path from 'path';
import multer from 'multer';
import { DATA_DIR, queryGet, queryRun } from '../db';
import { logger } from '../logger';
import { PROVIDER_ID } from '../config';

const CONFIG_PATH = path.join(DATA_DIR, 'config.json');
const UPLOADS_DIR = path.join(DATA_DIR, 'uploads');
const GALLERY_DIR = path.join(UPLOADS_DIR, 'gallery');

const DEFAULT_CONFIG = {
  brand: {
    name: 'Tu Marca',
    tagline: 'Tu eslogan aquí',
    address: 'Calle 123, Ciudad',
    whatsapp: '5490000000000',
    instagram: '@tu_marca',
    profesional: 'Nombre del Profesional',
  },
  colors: {
    primary: '#e8a0a0',
    secondary: '#f5f0f0',
    accent: '#b56576',
    text: '#2d2d2d',
    background: '#ffffff',
  },
  logo: '',
  gallery: [],
};

export function readBrandingConfig(): any {
  let cfg: any;
  try {
    cfg = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
  } catch {
    cfg = structuredClone(DEFAULT_CONFIG);
  }
  return { ...cfg, providerId: PROVIDER_ID };
}

function writeBrandingConfig(cfg: any): void {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  fs.writeFileSync(CONFIG_PATH, JSON.stringify(cfg, null, 2));
  logger.info('Branding config saved');
}

function syncProvider(brand: any): void {
  const profesional = brand?.profesional || '';
  const address = brand?.address || '';
  if (!profesional && !address) return;
  const existing = queryGet('SELECT provider_id FROM provider_settings WHERE provider_id = ?', PROVIDER_ID);
  if (existing) {
    queryRun('UPDATE provider_settings SET profesional = ?, address = ? WHERE provider_id = ?', profesional, address, PROVIDER_ID);
  } else {
    queryRun('INSERT INTO provider_settings (provider_id, profesional, address) VALUES (?, ?, ?)', PROVIDER_ID, profesional, address);
  }
  logger.info('Provider settings synced with brand');
}

const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

function looksLikeImage(buf: Buffer): boolean {
  if (!buf || buf.length < 12) return false;
  if (buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4e && buf[3] === 0x47) return true; // PNG
  if (buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return true; // JPEG
  if (buf.toString('ascii', 0, 4) === 'RIFF' && buf.toString('ascii', 8, 12) === 'WEBP') return true; // WEBP
  return false;
}

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 20 * 1024 * 1024 },
});

function extForMime(mime: string): string {
  if (mime === 'image/png') return '.png';
  if (mime === 'image/webp') return '.webp';
  return '.jpg';
}

export function register(router: Router): void {
  router.get('/branding', (_req: Request, res: Response) => {
    res.json(readBrandingConfig());
  });

  router.put('/branding', (req: Request, res: Response) => {
    const body = req.body || {};
    const cfg = readBrandingConfig();

    if (body.brand) cfg.brand = { ...cfg.brand, ...body.brand };
    if (body.colors) cfg.colors = { ...cfg.colors, ...body.colors };
    if (body.logo !== undefined) cfg.logo = body.logo;

    writeBrandingConfig(cfg);
    syncProvider(cfg.brand || {});
    res.json({ success: true, config: cfg });
  });

  router.post('/branding/logo', upload.single('file'), (req: Request, res: Response) => {
    const file = req.file;
    if (!file || !IMAGE_MIMES.includes(file.mimetype) || !looksLikeImage(file.buffer)) {
      return res.status(400).json({ success: false, message: 'Archivo de imagen inválido (PNG/JPEG/WebP)' });
    }

    fs.mkdirSync(UPLOADS_DIR, { recursive: true });
    const filename = 'logo' + extForMime(file.mimetype);
    fs.writeFileSync(path.join(UPLOADS_DIR, filename), file.buffer);

    const cfg = readBrandingConfig();
    cfg.logo = 'uploads/' + filename;
    writeBrandingConfig(cfg);
    res.json({ success: true, logo: cfg.logo });
  });

  router.delete('/branding/logo', (_req: Request, res: Response) => {
    const cfg = readBrandingConfig();
    if (cfg.logo) {
      const file = path.join(UPLOADS_DIR, path.basename(cfg.logo));
      try { fs.unlinkSync(file); } catch {}
      cfg.logo = '';
      writeBrandingConfig(cfg);
    }
    res.json({ success: true });
  });

  router.post('/branding/gallery', upload.array('files', 10), (req: Request, res: Response) => {
    const files: Express.Multer.File[] = (req.files as Express.Multer.File[]) || [];
    const added: string[] = [];

    fs.mkdirSync(GALLERY_DIR, { recursive: true });

    for (const file of files) {
      if (!IMAGE_MIMES.includes(file.mimetype) || !looksLikeImage(file.buffer)) continue;
      const filename = Date.now() + '-' + Math.round(Math.random() * 1e6) + extForMime(file.mimetype);
      fs.writeFileSync(path.join(GALLERY_DIR, filename), file.buffer);
      added.push('uploads/gallery/' + filename);
    }

    if (added.length === 0) {
      return res.status(400).json({ success: false, message: 'Ningún archivo válido' });
    }

    const cfg = readBrandingConfig();
    cfg.gallery = Array.isArray(cfg.gallery) ? cfg.gallery.concat(added) : added;
    writeBrandingConfig(cfg);
    res.status(201).json({ success: true, added });
  });

  router.delete('/branding/gallery/:file', (req: Request, res: Response) => {
    const filename = path.basename(String(req.params.file || ''));
    if (!filename || filename === 'logo') {
      return res.status(400).json({ success: false, message: 'Archivo inválido' });
    }

    try { fs.unlinkSync(path.join(GALLERY_DIR, filename)); } catch {}

    const cfg = readBrandingConfig();
    const rel = 'uploads/gallery/' + filename;
    cfg.gallery = (Array.isArray(cfg.gallery) ? cfg.gallery : []).filter((g: string) => g !== rel);
    writeBrandingConfig(cfg);
    res.json({ success: true });
  });
}
