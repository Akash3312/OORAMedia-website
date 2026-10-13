// Generates the Oora Media favicon set from the brand mark (amber ring on navy).
// No dependencies. Run from the project root:  node tools/make-favicons.js
const fs = require('fs'), path = require('path'), zlib = require('zlib');
const root = path.resolve(__dirname, '..');

const NAVY = [10, 22, 40], AMBER = [245, 184, 46], GLOW = [255, 211, 107];

/* The mark, drawn on a 64x64 grid:
   navy rounded square, amber ring (the "O" / screen glow), bright centre dot, amber stand line. */
const SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="14" fill="#0A1628"/>
  <circle cx="32" cy="28" r="13" fill="none" stroke="#F5B82E" stroke-width="6"/>
  <circle cx="32" cy="28" r="3.6" fill="#FFD36B"/>
  <path d="M22 52h20" stroke="#F5B82E" stroke-width="4.6" stroke-linecap="round"/>
</svg>
`;

// Returns [r,g,b,a] for a point on the 64-unit grid. `rounded` = transparent rounded corners.
function sample(x, y, rounded) {
  let col = null;
  if (rounded) {
    const r = 14, cx = Math.min(Math.max(x, r), 64 - r), cy = Math.min(Math.max(y, r), 64 - r);
    if (Math.hypot(x - cx, y - cy) <= r) col = NAVY;
  } else col = NAVY;
  if (!col) return [0, 0, 0, 0];
  const d = Math.hypot(x - 32, y - 28);
  if (Math.abs(d - 13) <= 3) col = AMBER;
  if (d <= 3.6) col = GLOW;
  const lx = Math.min(Math.max(x, 22), 42);
  if (Math.hypot(x - lx, y - 52) <= 2.3) col = AMBER;
  return [col[0], col[1], col[2], 255];
}

// Render at `size` px with 5x5 supersampling. `scale` < 1 shrinks the mark inside a full navy square (maskable icons).
function render(size, { rounded = true, scale = 1 } = {}) {
  const px = Buffer.alloc(size * size * 4), SS = 5;
  for (let j = 0; j < size; j++) for (let i = 0; i < size; i++) {
    let r = 0, g = 0, b = 0, a = 0;
    for (let sy = 0; sy < SS; sy++) for (let sx = 0; sx < SS; sx++) {
      let x = (i + (sx + .5) / SS) / size * 64, y = (j + (sy + .5) / SS) / size * 64;
      let c;
      if (scale !== 1) {
        const ux = (x - 32) / scale + 32, uy = (y - 32) / scale + 32;
        c = (ux < 0 || ux > 64 || uy < 0 || uy > 64) ? [...NAVY, 255] : sample(ux, uy, false);
      } else c = sample(x, y, rounded);
      r += c[0] * c[3]; g += c[1] * c[3]; b += c[2] * c[3]; a += c[3];
    }
    const o = (j * size + i) * 4;
    if (a) { px[o] = Math.round(r / a); px[o + 1] = Math.round(g / a); px[o + 2] = Math.round(b / a); }
    px[o + 3] = Math.round(a / (SS * SS));
  }
  return px;
}

const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
const crc32 = buf => { let c = 0xFFFFFFFF; for (const b of buf) c = crcTable[(c ^ b) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; };
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type), data]), crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
function png(size, opts) {
  const px = render(size, opts), raw = Buffer.alloc((size * 4 + 1) * size);
  for (let y = 0; y < size; y++) px.copy(raw, y * (size * 4 + 1) + 1, y * size * 4, (y + 1) * size * 4);
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 6;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}
// .ico container holding PNG images (supported by all current browsers)
function ico(sizes) {
  const imgs = sizes.map(s => png(s)), head = Buffer.alloc(6 + 16 * imgs.length);
  head.writeUInt16LE(1, 2); head.writeUInt16LE(imgs.length, 4);
  let off = head.length;
  imgs.forEach((img, i) => { const e = 6 + 16 * i; head[e] = sizes[i] % 256; head[e + 1] = sizes[i] % 256; head.writeUInt16LE(1, e + 4); head.writeUInt16LE(32, e + 6); head.writeUInt32LE(img.length, e + 8); head.writeUInt32LE(off, e + 12); off += img.length; });
  return Buffer.concat([head, ...imgs]);
}

const out = {
  'favicon.svg': SVG,
  'favicon.ico': ico([16, 32, 48]),
  'favicon-16x16.png': png(16),
  'favicon-32x32.png': png(32),
  'apple-touch-icon.png': png(180, { rounded: false }),            // iOS rounds the corners itself
  'icon-192.png': png(192),
  'icon-512.png': png(512),
  'icon-maskable-512.png': png(512, { scale: 0.72 }),              // safe zone for Android adaptive icons
  'site.webmanifest': JSON.stringify({
    name: 'Oora Media', short_name: 'Oora Media', description: 'Be Seen Where Life Happens.',
    start_url: './', scope: './', display: 'browser', background_color: '#0A1628', theme_color: '#0A1628',
    icons: [
      { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
      { src: 'icon-512.png', sizes: '512x512', type: 'image/png' },
      { src: 'icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' }
    ]
  }, null, 2) + '\n'
};
for (const [name, data] of Object.entries(out)) { fs.writeFileSync(path.join(root, name), data); console.log(name.padEnd(24), Buffer.byteLength(data), 'bytes'); }
