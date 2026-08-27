import https from 'node:https';
import { createHash } from 'node:crypto';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';

const target = '/work/npm-tool';
const get = (url) => new Promise((resolve, reject) => https.get(url, (response) => {
  if (response.statusCode >= 300 && response.statusCode < 400 && response.headers.location) return resolve(get(response.headers.location));
  if (response.statusCode !== 200) return reject(new Error(`npm registry returned HTTP ${response.statusCode}`));
  const chunks = []; response.on('data', (chunk) => chunks.push(chunk)); response.on('end', () => resolve(Buffer.concat(chunks)));
}).on('error', reject));

const metadata = JSON.parse((await get('https://registry.npmjs.org/npm/12.0.2')).toString('utf8'));
const tarball = await get(metadata.dist.tarball);
const expected = metadata.dist.integrity;
const actual = `sha512-${createHash('sha512').update(tarball).digest('base64')}`;
if (actual !== expected) throw new Error('npm 12.0.2 integrity validation failed');
await rm(target, { recursive: true, force: true });
await mkdir(target, { recursive: true });
await writeFile('/tmp/npm-12.0.2.tgz', tarball);
const result = spawnSync('tar', ['-xzf', '/tmp/npm-12.0.2.tgz', '--strip-components=1', '-C', target], { stdio: 'inherit' });
if (result.status !== 0) process.exit(result.status ?? 1);
console.log('Prepared verified npm 12.0.2 without executing the image npm CLI.');
