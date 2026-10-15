/* End-to-end tests for the PHP enquiry service.
   Needs: fake-smtp.mjs running, and `php -S localhost:8788` serving the site with OORA_CONFIG=hosting/tests/test-config.php.
   Run: node hosting/tests/run-tests.mjs [path-to-php.exe] */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const API = 'http://localhost:8788/api/index.php', SMTP = 'http://localhost:2580';
const TOKEN = 'test-admin-token-0123456789abcdef-local';
const PHP = process.argv[2] || 'php';
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ipN = 0, keyN = Date.now();
const freshIp = () => `198.51.100.${(++ipN % 250) + 1}`;

async function call(route, { method = 'GET', body, token, origin, ip = freshIp(), query = '' } = {}) {
  const r = await fetch(`${API}?route=${route}${query}`, { method, headers: { 'Content-Type': 'application/json', 'X-Forwarded-For': ip, ...(token ? { 'X-Admin-Token': token } : {}), ...(origin ? { Origin: origin } : {}) }, body: body ? JSON.stringify(body) : undefined });
  const text = await r.text(); let json; try { json = JSON.parse(text); } catch { json = { _raw: text }; }
  return { status: r.status, json, headers: r.headers };
}
const smtp = async (path) => (await fetch(SMTP + path)).json();
const submission = (type, data, extra = {}) => ({ type, data, idempotencyKey: `test-key-${++keyN}-abcdef`, startedAt: Date.now() - 60000, website: '', ...extra });

/* tiny MIME decoder for checking what was actually emailed */
function decodeWords(s) { return s.replace(/\?=\s+=\?/g, '?==?')   // RFC 2047: whitespace between encoded words is ignored
  .replace(/\r\n /g, '').replace(/=\?UTF-8\?B\?([^?]+)\?=/g, (_, b) => Buffer.from(b, 'base64').toString('utf8')).replace(/\?==\?UTF-8\?B\?/g, ''); }
function parseMail(raw) {
  const [head, ...rest] = raw.split('\r\n\r\n'); const body = rest.join('\r\n\r\n');
  const headers = {}; for (const line of head.replace(/\r\n[ \t]/g, ' ').split('\r\n')) { const i = line.indexOf(':'); headers[line.slice(0, i).toLowerCase()] = line.slice(i + 1).trim(); }
  const parts = []; const walk = (h, b) => {
    const m = /boundary="([^"]+)"/.exec(h['content-type'] || '');
    if (!m) { parts.push({ headers: h, content: /base64/i.test(h['content-transfer-encoding'] || '') ? Buffer.from(b.replace(/\s+/g, ''), 'base64') : Buffer.from(b) }); return; }
    for (const seg of b.split('--' + m[1]).slice(1)) { if (seg.startsWith('--')) break; const [ph, ...pb] = seg.replace(/^\r\n/, '').split('\r\n\r\n'); const hh = {}; for (const l of ph.split('\r\n')) { const i = l.indexOf(':'); if (i > 0) hh[l.slice(0, i).toLowerCase()] = l.slice(i + 1).trim(); } walk(hh, pb.join('\r\n\r\n').replace(/\r\n$/, '')); }
  };
  walk(headers, body);
  const text = parts.find(p => /text\/plain/.test(p.headers['content-type']))?.content.toString('utf8') || '';
  const html = parts.find(p => /text\/html/.test(p.headers['content-type']))?.content.toString('utf8') || '';
  const attachments = parts.filter(p => /attachment/.test(p.headers['content-disposition'] || '')).map(p => ({ name: /filename="([^"]+)"/.exec(p.headers['content-disposition'])[1], bytes: p.content }));
  return { headers, subject: decodeWords(headers.subject || ''), text, html, attachments };
}
const runCron = () => execFileSync(PHP, ['api/cron.php'], { env: process.env, encoding: 'utf8' });

const FORMS = {
  advertiser: { business: 'Harbour Lane Realty', contact: 'Alex Taylor', email: 'alex@harbourlane.example', phone: '0400 111 222', goal: 'Brand Awareness', suburbs: ['Norwood', 'Glenelg'], types: ['Café'], screens: [{ id: 's3', venue: 'Bayside Brunch House', suburb: 'Glenelg' }], duration: '1 Month', info: 'Spring campaign\nfor open homes — “quoted”.' },
  partner: { business: 'Corner Grind Espresso', contact: 'Sam Nguyen', phone: '0400 333 444', email: 'sam@cornergrind.example', address: '12 Magill Road', suburb: 'Kensington', type: 'Café', screens: '1', size: '50"–59"', traffic: '250–500', hours: 'Mon–Fri 6:30am–3pm', comments: 'Screen shows our menu.', photo: 'data:image/jpeg;base64,' + Buffer.from('fake-jpeg-bytes-for-test').toString('base64') },
  contact: { name: 'Chris Martin', email: 'chris@events.example', phone: '', company: 'Local Events Co.', message: 'Do you have screens near Adelaide Oval?\n.\nA line that is just a dot, to test SMTP dot-stuffing.' }
};
const tests = []; const test = (n, f) => tests.push([n, f]);

test('every form: saved, one email via SSL SMTP to admin@ooramedia.com, reply-to visitor, correct subject + all details', async () => {
  const subjects = { advertiser: 'OORA Media — New Advertiser Enquiry · Harbour Lane Realty', partner: 'OORA Media — New Screen Partner Application · Corner Grind Espresso', contact: 'OORA Media — New General Enquiry · Local Events Co.' };
  for (const type of Object.keys(FORMS)) {
    await smtp('/reset');
    const r = await call('enquiries', { method: 'POST', body: submission(type, FORMS[type]) });
    assert.equal(r.status, 201, `${type}: ${JSON.stringify(r.json)}`);
    assert.match(r.json.reference, /^OM-(ADV|SCR|GEN)-\d{6}-[A-Z2-9]{4}$/);
    const msgs = await smtp('/messages');
    assert.equal(msgs.length, 1, `${type}: exactly one email`);
    const m = msgs[0], mail = parseMail(m.raw);
    assert.equal(m.authed, true); assert.equal(m.user, 'admin@ooramedia.com');
    assert.equal(m.from, 'admin@ooramedia.com'); assert.deepEqual(m.to, ['admin@ooramedia.com']);
    assert.equal(mail.headers['reply-to'], `<${FORMS[type].email}>`);
    assert.equal(mail.subject, subjects[type]);
    assert.ok(mail.text.includes(r.json.reference) && mail.html.includes(r.json.reference), 'reference in email');
    assert.ok(mail.text.includes('Adelaide time'));
    for (const v of Object.values(FORMS[type])) if (typeof v === 'string' && v && !v.startsWith('data:')) assert.ok(mail.text.includes(v), `${type} email missing "${v}"`);
    if (type === 'partner') { assert.equal(mail.attachments[0].name, `screen-photo-${r.json.reference}.jpg`); assert.equal(mail.attachments[0].bytes.toString(), 'fake-jpeg-bytes-for-test'); }
    if (type === 'advertiser') assert.ok(mail.html.includes('&#039;') || mail.html.includes('“quoted”'), 'html escaped / unicode intact');
    const list = await call('admin/enquiries', { token: TOKEN, query: '&limit=5' });
    const row = list.json.enquiries.find(e => e.id === r.json.id);
    assert.equal(row.type, type); assert.equal(row.notify.status, 'sent'); assert.equal(row.notify.attempts, 1);
  }
});

test('same submission sent twice (double-click/refresh) → saved once, emailed once', async () => {
  await smtp('/reset');
  const body = submission('contact', FORMS.contact);
  const a = await call('enquiries', { method: 'POST', body }), b = await call('enquiries', { method: 'POST', body });
  assert.equal(a.status, 201); assert.equal(b.status, 200); assert.equal(b.json.duplicate, true); assert.equal(b.json.reference, a.json.reference);
  assert.equal((await smtp('/messages')).length, 1);
});

test('mail server temporarily failing: enquiry still saved + visitor told it was received; cron retry sends once', async () => {
  await smtp('/reset'); await smtp('/mode?m=temp');
  const r = await call('enquiries', { method: 'POST', body: submission('advertiser', FORMS.advertiser) });
  assert.equal(r.status, 201);
  let row = (await call('admin/enquiries', { token: TOKEN })).json.enquiries.find(e => e.id === r.json.id);
  assert.equal(row.notify.status, 'failed'); assert.match(row.notify.error, /451/);
  assert.match(runCron(), /retried 0/, 'not retried before the 2-minute wait');
  await smtp('/mode?m=ok');
  process.env.OORA_TEST_TIME_SHIFT = '';
  // make the enquiry "old" so the cron picks it up
  execFileSync(PHP, ['-r', `require 'api/lib/oora.php'; oora_db()->exec("UPDATE enquiries SET created_at = '2000-01-01T00:00:00.000Z' WHERE id = '${r.json.id}'");`], { env: process.env });
  assert.match(runCron(), /retried 1 notification\(s\), 1 sent/);
  row = (await call('admin/enquiries', { token: TOKEN })).json.enquiries.find(e => e.id === r.json.id);
  assert.equal(row.notify.status, 'sent');
  assert.match(runCron(), /retried 0/, 'never sent twice');
  assert.equal((await smtp('/messages')).length, 1);
});

test('mail server down entirely: enquiry saved, marked for retry', async () => {
  await smtp('/reset'); await smtp('/mode?m=down');
  const r = await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact) });
  assert.equal(r.status, 201);
  const row = (await call('admin/enquiries', { token: TOKEN })).json.enquiries.find(e => e.id === r.json.id);
  assert.equal(row.notify.status, 'failed'); assert.match(row.notify.error, /closed the connection|connect|timed out/i);
  await smtp('/mode?m=ok');
});

test('wrong mailbox password: saved, marked "Not sent" (no pointless retries), admin "Send now" works once fixed', async () => {
  await smtp('/reset'); await smtp('/mode?m=authfail');
  const r = await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact) });
  assert.equal(r.status, 201);
  let row = (await call('admin/enquiries', { token: TOKEN })).json.enquiries.find(e => e.id === r.json.id);
  assert.equal(row.notify.status, 'failed_final'); assert.match(row.notify.error, /password.*535/);
  await smtp('/mode?m=ok');
  const s = await call('admin/notify', { method: 'POST', token: TOKEN, query: `&id=${r.json.id}` });
  assert.equal(s.status, 200, JSON.stringify(s.json));
  row = (await call('admin/enquiries', { token: TOKEN })).json.enquiries.find(e => e.id === r.json.id);
  assert.equal(row.notify.status, 'sent'); assert.equal((await smtp('/messages')).length, 1);
});

test('validation errors are clear and nothing is saved', async () => {
  const before = (await call('admin/enquiries', { token: TOKEN, query: '&limit=500' })).json.enquiries.length;
  const cases = [
    ['advertiser', { ...FORMS.advertiser, email: 'nope' }, /valid email/], ['advertiser', { ...FORMS.advertiser, goal: 'Hack' }, /campaign goal/],
    ['partner', { ...FORMS.partner, business: ' ' }, /Business name is required/], ['partner', { ...FORMS.partner, photo: 'data:text/html;base64,PHNjcmlwdD4=' }, /photo could not be read/],
    ['contact', { ...FORMS.contact, message: 'x'.repeat(6000) }, /too long/], ['contact', { ...FORMS.contact, phone: 'call me maybe' }, /valid phone/],
    ['nonsense', FORMS.contact, /Unknown enquiry type/]];
  for (const [type, data, msg] of cases) { const r = await call('enquiries', { method: 'POST', body: submission(type, data) }); assert.equal(r.status, 400); assert.match(r.json.error, msg); }
  assert.equal((await call('admin/enquiries', { token: TOKEN, query: '&limit=500' })).json.enquiries.length, before);
});

test('spam: honeypot silently dropped; instant submit refused with a visible message', async () => {
  await smtp('/reset');
  const before = (await call('admin/enquiries', { token: TOKEN, query: '&limit=500' })).json.enquiries.length;
  assert.equal((await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact, { website: 'http://spam' }) })).status, 200);
  const fast = await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact, { startedAt: Date.now() - 200 }) });
  assert.equal(fast.status, 400); assert.match(fast.json.error, /submit again/);
  assert.equal((await call('admin/enquiries', { token: TOKEN, query: '&limit=500' })).json.enquiries.length, before);
  assert.equal((await smtp('/messages')).length, 0);
});

test('rate limit: 9th enquiry in 10 minutes from one connection refused; others unaffected', async () => {
  const ip = `2001:db8::${Date.now().toString(16).slice(-4)}:${Math.floor(Math.random() * 65535).toString(16)}`;   // unique per run
  for (let i = 0; i < 8; i++) assert.equal((await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact), ip })).status, 201);
  assert.equal((await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact), ip })).status, 429);
  assert.equal((await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact) })).status, 201);
});

test('other websites cannot submit; your own domains can', async () => {
  assert.equal((await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact), origin: 'https://evil.example' })).status, 403);
  assert.equal((await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact), origin: 'https://www.ooramedia.com' })).status, 201);
  assert.equal((await call('enquiries', { method: 'POST', body: submission('contact', FORMS.contact), origin: 'http://localhost:8788' })).status, 201, 'same site');
});

test('admin API requires the token; status updates validated per enquiry type', async () => {
  assert.equal((await call('admin/enquiries')).status, 401);
  assert.equal((await call('admin/enquiries', { token: 'wrong-token-wrong-token-wrong' })).status, 401);
  const r = await call('enquiries', { method: 'POST', body: submission('partner', FORMS.partner) });
  assert.equal((await call('admin/status', { method: 'POST', token: TOKEN, query: `&id=${r.json.id}`, body: { status: 'Approved' } })).status, 200);
  assert.equal((await call('admin/status', { method: 'POST', token: TOKEN, query: `&id=${r.json.id}`, body: { status: 'Proposal Sent' } })).status, 400);
  const row = (await call('admin/enquiries', { token: TOKEN, query: '&type=partner' })).json.enquiries.find(e => e.id === r.json.id);
  assert.equal(row.status, 'Approved'); assert.equal(row.ip_hash, undefined, 'internal fields hidden');
});

test('test email + cron web access protected', async () => {
  await smtp('/reset');
  assert.equal((await call('admin/test-email', { method: 'POST', token: TOKEN })).status, 200);
  const m = parseMail((await smtp('/messages'))[0].raw);
  assert.match(m.subject, /^OORA Media — New General Enquiry · OORA Media test/);
  assert.equal((await fetch('http://localhost:8788/api/cron.php')).status, 403);
  assert.equal((await fetch('http://localhost:8788/api/cron.php?key=wrong')).status, 403);
  const ok = await fetch('http://localhost:8788/api/cron.php?key=test-cron-key-0123456789abcdef-local');
  assert.equal(ok.status, 200); assert.match(await ok.text(), /retried/);
});

let failed = 0;
for (const [name, fn] of tests) {
  try { await fn(); console.log(`  ✓ ${name}`); } catch (e) { failed++; console.log(`  ✗ ${name}\n    ${e.message}`); }
}
await smtp('/mode?m=ok');
console.log(`\n${tests.length - failed}/${tests.length} passed`);
process.exit(failed ? 1 : 0);
