import { DatabaseSync } from 'node:sqlite';
import * as path from 'path';
import * as fs from 'fs';
import { logger } from './logger';

const MIGRATIONS_TABLE = '_migrations';

export interface Migration {
  id: string;
  description: string;
  up: string;
}

export function runMigrations(db: DatabaseSync): void {
  db.exec(`
    CREATE TABLE IF NOT EXISTS ${MIGRATIONS_TABLE} (
      id TEXT PRIMARY KEY,
      applied_at TEXT DEFAULT (datetime('now'))
    )
  `);

  const rows = db.prepare(`SELECT id FROM ${MIGRATIONS_TABLE}`).all() as { id: string }[];
  const applied = new Set(rows.map(r => r.id));

  const candidates = [
    path.join(__dirname, 'migrations'),
    path.join(__dirname, '..', 'src', 'migrations'),
  ];
  const migrationsDir = candidates.find((c) => fs.existsSync(c));
  if (!migrationsDir) {
    throw new Error('No migrations directory found. El scheduler no puede arrancar sin las migraciones SQL.');
  }

  const files = fs.readdirSync(migrationsDir)
    .filter((f: string) => f.endsWith('.sql'))
    .sort();

  for (const file of files) {
    const migrationId = file.replace(/\.sql$/, '');
    if (applied.has(migrationId)) continue;

    const sql = fs.readFileSync(path.join(migrationsDir, file), 'utf-8');
    logger.info({ migration: migrationId }, 'Applying migration');

    db.exec('BEGIN TRANSACTION');
    try {
      db.exec(sql);
      db.prepare(`INSERT INTO ${MIGRATIONS_TABLE} (id) VALUES (?)`).run(migrationId);
      db.exec('COMMIT');
      logger.info({ migration: migrationId }, 'Migration applied');
    } catch (err) {
      db.exec('ROLLBACK');
      logger.error({ migration: migrationId, err }, 'Migration failed');
      throw err;
    }
  }
}
