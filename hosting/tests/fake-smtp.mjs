/* Fake SMTP server for local testing of api/lib/smtp.php.
   - port 2465: implicit SSL (like smtp.hostinger.com:465), self-signed certificate
   - port 2525: plain SMTP
   - http://localhost:2580/messages   captured messages (JSON)
     http://localhost:2580/mode?m=ok|temp|perm|authfail|down   simulate mail-server problems
     http://localhost:2580/reset
   Accepts AUTH LOGIN with password "test-password". Usage: node fake-smtp.mjs <cert.pem> <key.pem> */
import net from 'node:net';
import tls from 'node:tls';
import http from 'node:http';
import fs from 'node:fs';

const [certFile, keyFile] = process.argv.slice(2);
let messages = [], mode = 'ok';

function session(sock) {
  if (mode === 'down') { sock.destroy(); return; }
  let buf = '', state = 'cmd', authStep = 0, user = '', authed = false, env = { from: null, to: [] };
  const send = l => sock.write(l + '\r\n');
  send('220 fake.smtp ESMTP ready');
  sock.on('data', chunk => {
    buf += chunk.toString('latin1');
    for (;;) {
      if (state === 'data') {
        const end = buf.indexOf('\r\n.\r\n'); if (end < 0) return;
        const raw = buf.slice(0, end).replace(/^\.\./gm, '.'); buf = buf.slice(end + 5); state = 'cmd';
        if (mode === 'temp') send('451 4.3.0 Temporary local problem, try again later');
        else if (mode === 'perm') send('550 5.7.1 Message rejected by policy');
        else { messages.push({ from: env.from, to: env.to, user, authed, raw: Buffer.from(raw, 'latin1').toString('utf8') }); send('250 2.0.0 Ok: queued as FAKE' + messages.length); }
        env = { from: null, to: [] }; continue;
      }
      const i = buf.indexOf('\r\n'); if (i < 0) return;
      const line = buf.slice(0, i); buf = buf.slice(i + 2);
      if (authStep === 1) { user = Buffer.from(line, 'base64').toString(); authStep = 2; send('334 UGFzc3dvcmQ6'); continue; }
      if (authStep === 2) { authStep = 0; const pass = Buffer.from(line, 'base64').toString();
        if (mode === 'authfail' || pass !== 'test-password') send('535 5.7.8 Error: authentication failed'); else { authed = true; send('235 2.7.0 Authentication successful'); } continue; }
      const up = line.toUpperCase();
      if (up.startsWith('EHLO') || up.startsWith('HELO')) sock.write('250-fake.smtp\r\n250-AUTH LOGIN PLAIN\r\n250-SIZE 35882577\r\n250 8BITMIME\r\n');
      else if (up === 'AUTH LOGIN') { authStep = 1; send('334 VXNlcm5hbWU6'); }
      else if (up.startsWith('MAIL FROM:')) { if (!authed) { send('530 5.7.0 Authentication required'); continue; } env.from = (line.match(/<(.*)>/) || [])[1]; send('250 2.1.0 Ok'); }
      else if (up.startsWith('RCPT TO:')) { env.to.push((line.match(/<(.*)>/) || [])[1]); send('250 2.1.5 Ok'); }
      else if (up === 'DATA') { state = 'data'; send('354 End data with <CR><LF>.<CR><LF>'); }
      else if (up === 'QUIT') { send('221 2.0.0 Bye'); sock.end(); }
      else send('502 5.5.2 Command not recognised');
    }
  });
  sock.on('error', () => {});
}

net.createServer(session).listen(2525);
tls.createServer({ cert: fs.readFileSync(certFile), key: fs.readFileSync(keyFile) }, session).listen(2465);
http.createServer((req, res) => {
  const u = new URL(req.url, 'http://localhost');
  res.setHeader('Content-Type', 'application/json');
  res.setHeader('Access-Control-Allow-Origin', '*');   // lets browser-based tests read the outbox
  if (u.pathname === '/messages') return res.end(JSON.stringify(messages));
  if (u.pathname === '/mode') { mode = u.searchParams.get('m') || 'ok'; return res.end(JSON.stringify({ mode })); }
  if (u.pathname === '/reset') { messages = []; mode = 'ok'; return res.end('{"ok":true}'); }
  res.statusCode = 404; res.end('{}');
}).listen(2580, () => console.log('fake SMTP: ssl 2465, plain 2525, control http://localhost:2580'));
