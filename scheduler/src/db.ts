import { DatabaseSync } from 'node:sqlite';
import * as path from 'path';
import * as fs from 'fs';
import { runMigrations } from './migrate';
import { logger } from './logger';
import { PROVIDER_ID } from './config';

export const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, '..', 'data');
const DB_PATH = path.join(DATA_DIR, 'scheduler.db');

let db: DatabaseSync | null = null;

export function getDb(): DatabaseSync {
  if (!db) {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    db = new DatabaseSync(DB_PATH);
    db.exec('PRAGMA journal_mode = WAL');
    db.exec('PRAGMA foreign_keys = ON');
    runMigrations(db);
    ensureDefaults(db);
  }
  return db;
}

// Typed helpers to avoid node:sqlite strict Record<string, SQLOutputValue> type
export function queryAll<T>(sql: string, ...params: any[]): T[] {
  const stmt = getDb().prepare(sql);
  return (stmt.all as (...args: any[]) => T[])(...params);
}

export function queryGet<T>(sql: string, ...params: any[]): T | undefined {
  const stmt = getDb().prepare(sql);
  return (stmt.get as (...args: any[]) => T | undefined)(...params);
}

export function queryRun(sql: string, ...params: any[]) {
  const stmt = getDb().prepare(sql);
  return (stmt.run as (...args: any[]) => any)(...params);
}

function ensureDefaults(db: DatabaseSync): void {
  const count = queryGet<{ c: number }>('SELECT COUNT(*) as c FROM provider_settings')!;
  if (count.c === 0) {
    const defaultPlan = {
      monday: { start: '09:00', end: '18:00', breaks: [{ start: '13:00', end: '14:00' }] },
      tuesday: { start: '09:00', end: '18:00', breaks: [{ start: '13:00', end: '14:00' }] },
      wednesday: { start: '09:00', end: '18:00', breaks: [{ start: '13:00', end: '14:00' }] },
      thursday: { start: '09:00', end: '18:00', breaks: [{ start: '13:00', end: '14:00' }] },
      friday: { start: '09:00', end: '18:00', breaks: [{ start: '13:00', end: '14:00' }] },
      saturday: { start: null, end: null, breaks: [] },
      sunday: { start: null, end: null, breaks: [] },
    };
    queryRun('INSERT INTO provider_settings (provider_id, working_plan) VALUES (?, ?)', PROVIDER_ID, JSON.stringify(defaultPlan));
    logger.info('Default provider settings created');
  }

  try { db.exec('ALTER TABLE provider_settings ADD COLUMN address TEXT DEFAULT ""'); } catch {}
  try { db.exec('ALTER TABLE provider_settings ADD COLUMN profesional TEXT DEFAULT ""'); } catch {}
}
