/**
 * 群聊实时推送服务（push-server.js）
 *
 * Node.js + ws。浏览器连 WebSocket，有新消息立刻推过去，不用等 3 秒轮询。
 * PHP（send.php / api/muse/reply.php / review.php）写库后调本服务的 POST /push，
 * 本服务再把消息广播给订阅了那个房间的浏览器。
 *
 * 跑法（宝塔服务器上）：
 *   cd push-server && npm install && node push-server.js
 *   建议用 PM2 或 supervisor 常驻（见部署说明）。
 *   只监听 127.0.0.1（浏览器经 nginx 反代 /chat/ws 连进来，PHP 直接调 127.0.0.1:8282/push）。
 *
 * 接口：
 *   WebSocket  wss://域名/chat/ws        浏览器连这里；连上后发 {"type":"subscribe","rooms":"*"}
 *   POST       http://127.0.0.1:8282/push  PHP 调这里，体 {"type":"message","room_id":1,"message":{...}}
 *                                       或 {"type":"review","room_id":1,"message_id":5,"review_status":"rejected"}
 *                                       只接受本机（127.0.0.1）来的请求，外网调会被拒。
 *   GET        http://127.0.0.1:8282/health  看服务是不是活着。
 *
 * 环境变量：
 *   PUSH_PORT     监听端口，默认 8282
 *   PUSH_AUTH_URL 校验登录态的 PHP 接口，默认 http://127.0.0.1/chat/ws_auth.php
 *
 * 登录校验：浏览器 WebSocket 握手时自带 Cookie（登录态），本服务拿它去问
 * ws_auth.php。200 + ok 才放行；401 拒绝；PHP 问不到（网络/配置问题）时
 * 放行但记一条警告，免得一个配置问题把实时推送整个弄停（前端还有轮询兜底）。
 */
'use strict';

var http = require('http');
var WebSocket = require('ws');

var PORT = parseInt(process.env.PUSH_PORT || '8282', 10);
var AUTH_URL = process.env.PUSH_AUTH_URL || 'http://127.0.0.1/chat/ws_auth.php';
var PING_INTERVAL = 25000;

// room_id -> Set<ws>；另有一组 allClients（订阅全部房间的连接）。
var rooms = new Map();
var allClients = new Set();

function log() {
  var args = Array.prototype.slice.call(arguments);
  args.unshift('[' + new Date().toISOString() + ']');
  console.log.apply(console, args);
}

function subscribe(ws, roomList) {
  ws._rooms = roomList;
  if (roomList === '*') {
    allClients.add(ws);
    return;
  }
  (roomList || []).forEach(function (rid) {
    rid = parseInt(rid, 10);
    if (!rid) return;
    if (!rooms.has(rid)) rooms.set(rid, new Set());
    rooms.get(rid).add(ws);
  });
}

function unsubscribe(ws) {
  allClients.delete(ws);
  if (ws._rooms && ws._rooms !== '*') {
    ws._rooms.forEach(function (rid) {
      rid = parseInt(rid, 10);
      var s = rooms.get(rid);
      if (s) {
        s.delete(ws);
        if (s.size === 0) rooms.delete(rid);
      }
    });
  }
  ws._rooms = null;
}

// 把一条广播体发给订阅了该房间的连接（包括订阅全部房间的）。返回发出去的数量。
function broadcast(roomId, payload) {
  var data = JSON.stringify(payload);
  var sent = 0;
  var s = rooms.get(roomId);
  if (s) {
    s.forEach(function (ws) {
      if (ws.readyState === WebSocket.OPEN) {
        try { ws.send(data); sent++; } catch (e) { /* 发不出去就跳过 */ }
      }
    });
  }
  allClients.forEach(function (ws) {
    if (ws.readyState === WebSocket.OPEN) {
      try { ws.send(data); sent++; } catch (e) { /* 发不出去就跳过 */ }
    }
  });
  return sent;
}

// 校验登录态：拿浏览器握手时带的 Cookie，去问 PHP 的 ws_auth.php。
// resolve({ok, reason})；ok=false 且 reason 不是 fail-open 时拒绝连接。
function checkAuth(req) {
  return new Promise(function (resolve) {
    var cookie = req.headers.cookie || '';
    var host = req.headers.host || '';
    if (!cookie) {
      resolve({ ok: false, reason: 'no-cookie' });
      return;
    }
    var u;
    try {
      u = new URL(AUTH_URL);
    } catch (e) {
      resolve({ ok: true, reason: 'auth-url-bad-fail-open' });
      return;
    }
    var opts = {
      hostname: u.hostname,
      port: u.port ? parseInt(u.port, 10) : 80,
      path: u.pathname + (u.search || ''),
      method: 'GET',
      headers: {
        Cookie: cookie,
        Host: host,
        'User-Agent': 'chat-push/1.0',
      },
      timeout: 3000,
    };
    var r = http.request(opts, function (res) {
      var body = '';
      res.on('data', function (c) { body += c; });
      res.on('end', function () {
        if (res.statusCode === 200) {
          try {
            var j = JSON.parse(body);
            resolve({ ok: !!j.ok, reason: j.ok ? '' : 'not-logged-in' });
          } catch (e) {
            resolve({ ok: false, reason: 'bad-auth-response' });
          }
        } else if (res.statusCode === 401) {
          resolve({ ok: false, reason: 'not-logged-in' });
        } else {
          resolve({ ok: false, reason: 'auth-status-' + res.statusCode });
        }
      });
    });
    r.on('timeout', function () {
      r.destroy();
      resolve({ ok: true, reason: 'auth-timeout-fail-open' });
    });
    r.on('error', function () {
      resolve({ ok: true, reason: 'auth-unreachable-fail-open' });
    });
    r.end();
  });
}

var server = http.createServer(function (req, res) {
  var url;
  try {
    url = new URL(req.url || '/', 'http://127.0.0.1');
  } catch (e) {
    res.writeHead(400, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: false, error: 'bad url' }));
    return;
  }

  if (req.method === 'GET' && url.pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      ok: true,
      clients: wss.clients.size,
      time: new Date().toISOString(),
    }));
    return;
  }

  if (req.method === 'POST' && url.pathname === '/push') {
    // 只接受本机（PHP）来的推送，外网直接调会被拒。
    var remote = req.socket.remoteAddress || '';
    var loopback = remote === '127.0.0.1' || remote === '::1' ||
      remote === '::ffff:127.0.0.1';
    if (!loopback) {
      res.writeHead(403, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'forbidden' }));
      return;
    }
    var body = '';
    req.on('data', function (c) {
      body += c;
      if (body.length > 200000) req.destroy();
    });
    req.on('end', function () {
      var payload;
      try {
        payload = JSON.parse(body);
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'bad json' }));
        return;
      }
      var roomId = parseInt(payload.room_id, 10) || 0;
      if (!roomId || !payload.type) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'missing room_id/type' }));
        return;
      }
      var sent = broadcast(roomId, payload);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, sent: sent }));
      log('push room=' + roomId + ' type=' + payload.type + ' sent=' + sent);
    });
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ ok: false, error: 'not found' }));
});

var wss = new WebSocket.Server({ noServer: true });

server.on('upgrade', function (req, socket, head) {
  checkAuth(req).then(function (auth) {
    if (!auth.ok) {
      log('ws reject: ' + auth.reason + ' from ' + req.socket.remoteAddress);
      try {
        socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
      } catch (e) { /* 忽略 */ }
      socket.destroy();
      return;
    }
    if (auth.reason) log('ws auth note: ' + auth.reason + ' (fail-open)');
    wss.handleUpgrade(req, socket, head, function (ws) {
      wss.emit('connection', ws, req);
    });
  });
});

wss.on('connection', function (ws, req) {
  ws.isAlive = true;
  // 默认订阅全部房间；浏览器连上后会再发一次 subscribe 明确确认。
  ws._rooms = '*';
  allClients.add(ws);
  log('ws connect from ' + req.socket.remoteAddress + ' clients=' + wss.clients.size);

  ws.on('pong', function () { ws.isAlive = true; });

  ws.on('message', function (raw) {
    var data;
    try {
      data = JSON.parse(raw.toString());
    } catch (e) {
      return;
    }
    if (data && data.type === 'subscribe') {
      unsubscribe(ws);
      if (data.rooms === '*' || !data.rooms) subscribe(ws, '*');
      else if (Array.isArray(data.rooms)) subscribe(ws, data.rooms);
      else subscribe(ws, '*');
    }
  });

  ws.on('close', function () {
    unsubscribe(ws);
    log('ws close clients=' + wss.clients.size);
  });

  ws.on('error', function () { /* close 会跟上 */ });
});

// 心跳：25 秒 ping 一次，不回 pong 的断掉（浏览器会自动回 pong）。
setInterval(function () {
  wss.clients.forEach(function (ws) {
    if (ws.isAlive === false) {
      ws.terminate();
      return;
    }
    ws.isAlive = false;
    try { ws.ping(); } catch (e) { /* 忽略 */ }
  });
}, PING_INTERVAL);

server.listen(PORT, '127.0.0.1', function () {
  log('chat push server listening on 127.0.0.1:' + PORT +
    ' (WebSocket 经 nginx 反代，POST /push，GET /health)');
});

process.on('SIGTERM', function () {
  log('SIGTERM, shutting down');
  server.close(function () { process.exit(0); });
});
process.on('SIGINT', function () {
  log('SIGINT, shutting down');
  server.close(function () { process.exit(0); });
});
