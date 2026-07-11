import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { readFileSync } from 'node:fs';
import { createHmac, timingSafeEqual } from 'node:crypto';
import type { IncomingMessage, ServerResponse } from 'node:http';
import type { Plugin } from 'vite';

const demoSecret = 'taka-gallery-local-demo-session-secret';
const mediaCookie = 'taka_gallery_media';
const galleries = [
  { id: 1, name: 'Featured', slug: 'featured', status: 'publish', menuOrder: 0, publishedCount: 60 },
  { id: 2, name: 'Events', slug: 'events', status: 'publish', menuOrder: 1, publishedCount: 60 },
  { id: 3, name: 'Private Shoot', slug: 'private-shoot', status: 'publish', menuOrder: 2, publishedCount: 60 },
  { id: 4, name: 'Others', slug: 'others', status: 'publish', menuOrder: 3, publishedCount: 60 },
];
const ratios = [1.5, 0.72, 1.18, 0.64, 1.34, 0.82, 1.62, 0.92, 1.1, 0.58];

function createBitmap(seed: string): Buffer {
  const width = 96;
  const height = 96;
  const rowSize = Math.ceil((width * 3) / 4) * 4;
  const pixels = rowSize * height;
  const bitmap = Buffer.alloc(54 + pixels);
  bitmap.write('BM', 0);
  bitmap.writeUInt32LE(bitmap.length, 2);
  bitmap.writeUInt32LE(54, 10);
  bitmap.writeUInt32LE(40, 14);
  bitmap.writeInt32LE(width, 18);
  bitmap.writeInt32LE(height, 22);
  bitmap.writeUInt16LE(1, 26);
  bitmap.writeUInt16LE(24, 28);
  bitmap.writeUInt32LE(pixels, 34);
  const base = Number.parseInt(seed.slice(-6), 16);
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const offset = 54 + y * rowSize + x * 3;
      bitmap[offset] = (base + x * 2) % 256;
      bitmap[offset + 1] = ((base >> 8) + y * 2) % 256;
      bitmap[offset + 2] = ((base >> 16) + x + y) % 256;
    }
  }
  return bitmap;
}

function hmac(value: string, context: string): string {
  return createHmac('sha256', `${demoSecret}|${context}`).update(value).digest('hex');
}

function cookieValue(sessionId: string): string {
  return `${sessionId}.${hmac(sessionId, 'session-v1')}`;
}

function establishSession(request: IncomingMessage, response: ServerResponse): string | null {
  const header = String(request.headers['x-taka-session'] || '').toLowerCase();
  if (!/^[a-f0-9]{32}$/.test(header)) return null;
  response.setHeader('Set-Cookie', `${mediaCookie}=${cookieValue(header)}; Path=/; HttpOnly; SameSite=Strict`);
  return header;
}

function currentSession(request: IncomingMessage): string | null {
  const cookies = String(request.headers.cookie || '').split(';').map((entry) => entry.trim());
  const value = cookies.find((entry) => entry.startsWith(`${mediaCookie}=`))?.slice(mediaCookie.length + 1).toLowerCase() || '';
  const match = value.match(/^([a-f0-9]{32})\.([a-f0-9]{64})$/);
  if (!match) return null;
  const expected = hmac(match[1], 'session-v1');
  return timingSafeEqual(Buffer.from(match[2]), Buffer.from(expected)) ? match[1] : null;
}

function mediaSignature(publicId: string, width: number, expiresAt: number, sessionId: string): string {
  return hmac(`${publicId}|${width}|${expiresAt}|${sessionId}`, 'media-v1');
}

function makeItem(publicId: string, index: number, sessionId: string, expiresAt: number) {
  return {
    id: publicId,
    width: 480,
    height: Math.round(480 * ratios[index % ratios.length]),
    alt: '',
    placeholder: '#000000',
    sources: [480, 960, 1440].map((width) => ({
      width,
      url: `/taka-media/v1/${publicId}/${width}.webp?exp=${expiresAt}&sig=${mediaSignature(publicId, width, expiresAt, sessionId)}`,
      expiresAt,
    })),
  };
}

function sendJson(response: ServerResponse, payload: unknown, status = 200): void {
  response.statusCode = status;
  response.setHeader('Content-Type', 'application/json; charset=utf-8');
  response.setHeader('Cache-Control', 'private, no-store');
  response.end(JSON.stringify(payload));
}

async function readJson(request: IncomingMessage): Promise<Record<string, unknown>> {
  let body = '';
  for await (const chunk of request) body += chunk.toString();
  try {
    return JSON.parse(body || '{}') as Record<string, unknown>;
  } catch {
    return {};
  }
}

function validImageContext(request: IncomingMessage): boolean {
  const destination = String(request.headers['sec-fetch-dest'] || '').toLowerCase();
  const site = String(request.headers['sec-fetch-site'] || '').toLowerCase();
  if (destination) return destination === 'image' && (!site || site === 'same-origin' || site === 'same-site');
  try {
    return new URL(String(request.headers.referer || '')).host === String(request.headers.host || '');
  } catch {
    return false;
  }
}

function demoRoutes(): Plugin {
  return {
    name: 'taka-gallery-demo-routes',
    apply: 'serve',
    configureServer(server) {
      server.middlewares.use(async (request, response, next) => {
        const url = new URL(request.url || '/', 'http://localhost');
        const media = url.pathname.match(/^\/taka-media\/v1\/([a-f0-9]{32})\/(480|960|1440)\.webp$/);
        if (media) {
          const publicId = media[1];
          const width = Number(media[2]);
          const expiresAt = Number(url.searchParams.get('exp') || 0);
          const signature = String(url.searchParams.get('sig') || '').toLowerCase();
          const sessionId = currentSession(request);
          const expected = sessionId ? mediaSignature(publicId, width, expiresAt, sessionId) : '';
          const validSignature = /^[a-f0-9]{64}$/.test(signature)
            && expected.length === signature.length
            && timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
          if (!sessionId || expiresAt < Math.floor(Date.now() / 1000) || expiresAt > Math.floor(Date.now() / 1000) + 3660 || !validSignature || !validImageContext(request)) {
            response.statusCode = 403;
            response.setHeader('Content-Type', 'text/plain; charset=utf-8');
            response.setHeader('Cache-Control', 'no-store');
            response.end('Expired or invalid media URL.');
            return;
          }
          response.statusCode = 200;
          response.setHeader('Content-Type', 'image/bmp');
          response.setHeader('Cache-Control', `private, max-age=${Math.max(0, Math.min(600, expiresAt - Math.floor(Date.now() / 1000)))}`);
          response.setHeader('Vary', 'Cookie, Sec-Fetch-Dest, Sec-Fetch-Site, Referer');
          response.setHeader('Cross-Origin-Resource-Policy', 'same-origin');
          response.setHeader('X-Content-Type-Options', 'nosniff');
          response.end(createBitmap(publicId));
          return;
        }
        if (url.pathname === '/wp-json/taka-gallery/v1/galleries') {
          sendJson(response, galleries);
          return;
        }
        const items = url.pathname.match(/^\/wp-json\/taka-gallery\/v1\/galleries\/([a-z0-9-]+)\/items$/);
        if (items) {
          const sessionId = establishSession(request, response);
          const galleryIndex = galleries.findIndex((gallery) => gallery.slug === items[1]);
          if (!sessionId) {
            sendJson(response, { message: 'A valid gallery session is required.' }, 403);
            return;
          }
          if (galleryIndex < 0) {
            sendJson(response, { message: 'Gallery not found.' }, 404);
            return;
          }
          const second = url.searchParams.get('cursor') === 'page2';
          const offset = second ? 30 : 0;
          const expiresAt = Math.floor(Date.now() / 1000) + 600;
          const page = Array.from({ length: 30 }, (_, index) => {
            const itemIndex = index + offset;
            const publicId = (galleryIndex * 1000 + itemIndex + 1).toString(16).padStart(32, '0');
            return makeItem(publicId, itemIndex, sessionId, expiresAt);
          });
          sendJson(response, { items: page, shuffleSeed: '0123456789abcdef', nextCursor: second ? null : 'page2' });
          return;
        }
        if (url.pathname === '/wp-json/taka-gallery/v1/media/refresh') {
          const sessionId = establishSession(request, response);
          if (!sessionId) {
            sendJson(response, { message: 'A valid gallery session is required.' }, 403);
            return;
          }
          const payload = await readJson(request);
          const publicIds = Array.isArray(payload.publicIds)
            ? payload.publicIds.map(String).filter((id) => /^[a-f0-9]{32}$/.test(id)).slice(0, 60)
            : [];
          const expiresAt = Math.floor(Date.now() / 1000) + 600;
          sendJson(response, { items: publicIds.map((publicId, index) => makeItem(publicId, Number.parseInt(publicId.slice(-4), 16) || index, sessionId, expiresAt)) });
          return;
        }
        if (url.pathname === '/gallery' || url.pathname === '/gallery/') {
          let source = readFileSync(resolve(__dirname, 'demo/index.html'), 'utf8');
          if (url.searchParams.get('adminBar') === '1') {
            source = source.replace('<body>', '<body class="admin-bar">');
          }
          const html = await server.transformIndexHtml(url.pathname, source);
          response.statusCode = 200;
          response.setHeader('Content-Type', 'text/html; charset=utf-8');
          response.end(html);
          return;
        }
        next();
      });
    },
  };
}

export default defineConfig({
  plugins: [react(), demoRoutes()],
  build: {
    outDir: 'assets/dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: {
        admin: resolve(__dirname, 'src/admin/main.tsx'),
        frontend: resolve(__dirname, 'src/frontend/main.tsx'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css') ? 'style.css' : 'assets/[name]-[hash][extname]',
      },
    },
  },
});
