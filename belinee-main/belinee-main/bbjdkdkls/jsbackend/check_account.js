#!/usr/bin/env node
/**
 * Чекер аккаунта по ID (под структуру БД фишпанели).
 * Использование: node check_account.js <account_id> [action_id]
 *
 * Проверяет валидность токена через MAX API.
 * Обновляет account_data: lastAliveCheck (unix), lastAliveResult ('active'|'deactive').
 * Если передан action_id — обновляет actions (ended=1, action_response).
 */

const tls = require('tls');
const net = require('net');
const { EventEmitter } = require('events');
const { pack, unpack } = require('msgpackr');
const LZ4 = require('lz4');
const mysql = require('mysql2/promise');

const Opcode = { SESSION_INIT: 6, LOGIN: 19, PING: 1 };

function sanitizeBigInt(obj) {
    if (obj === null || typeof obj !== 'object') {
        return typeof obj === 'bigint' ? (Number.isSafeInteger(Number(obj)) ? Number(obj) : obj.toString()) : obj;
    }
    if (Array.isArray(obj)) return obj.map(sanitizeBigInt);
    const out = {};
    for (const k of Object.keys(obj)) out[k] = sanitizeBigInt(obj[k]);
    return out;
}

const PROXY_TIMEOUT_MS = 3000;

class ProxyTLSWrapper {
    constructor(proxyConfig) {
        this.proxyHost = proxyConfig.host;
        this.proxyPort = proxyConfig.port;
        this.proxyAuth = proxyConfig.auth;
        this.targetHost = proxyConfig.targetHost;
        this.targetPort = proxyConfig.targetPort;
        this._socket = null;
    }
    async connect() {
        return new Promise((resolve, reject) => {
            let settled = false;
            const done = (err, result) => {
                if (settled) return;
                settled = true;
                if (timeoutId) clearTimeout(timeoutId);
                if (err) reject(err);
                else resolve(result);
            };
            const timeoutId = setTimeout(() => {
                if (this._socket) {
                    this._socket.destroy();
                    this._socket = null;
                }
                done(new Error('PROXY_TIMEOUT'));
            }, PROXY_TIMEOUT_MS);

            this._socket = net.connect({ host: this.proxyHost, port: this.proxyPort, timeout: PROXY_TIMEOUT_MS });
            let responseData = '';
            this._socket.on('connect', () => {
                let req = `CONNECT ${this.targetHost}:${this.targetPort} HTTP/1.1\r\nHost: ${this.targetHost}:${this.targetPort}\r\n`;
                if (this.proxyAuth) req += `Proxy-Authorization: Basic ${Buffer.from(this.proxyAuth).toString('base64')}\r\n`;
                req += 'User-Agent: TLS-Client/1.0\r\nProxy-Connection: Keep-Alive\r\n\r\n';
                this._socket.write(req);
            });
            this._socket.on('data', (data) => {
                responseData += data.toString();
                if (responseData.includes('\r\n\r\n')) {
                    const statusCode = parseInt(responseData.split('\r\n')[0].split(' ')[1]);
                    if (statusCode >= 200 && statusCode < 300) {
                        const tlsSocket = tls.connect({
                            socket: this._socket, host: this.targetHost, port: this.targetPort,
                            servername: this.targetHost, rejectUnauthorized: true
                        }, () => done(null, tlsSocket));
                        tlsSocket.on('error', done);
                    } else done(new Error(`Прокси: ${statusCode}`));
                }
            });
            this._socket.on('error', (e) => done(e));
            this._socket.on('timeout', () => done(new Error('PROXY_TIMEOUT')));
        });
    }
}

class TLSClient extends EventEmitter {
    constructor(options = {}) {
        super();
        this.host = options.host || 'api.oneme.ru';
        this.port = options.port || 443;
        this.deviceId = options.deviceId || this._genId();
        this.mtInstanceId = (options.mtInstanceId || this._genId()).toUpperCase();
        this.clientSessionId = options.clientSessionId || (Math.floor(Math.random() * 30) + 1);
        this.userAgent = options.userAgent || this._genUA();
        this.proxyConfig = options.proxy || null;
        this.isConnected = false;
        this._socket = null;
        this._seq = 0;
        this._buffer = Buffer.alloc(0);
        this._pingInterval = null;
        this._socketQueue = [];
        this._socketLocked = false;
    }
    _genId() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }
    _genUA() {
        const os = ['iOS 16.7.4', 'iOS 17.5.1', 'iOS 18.0.1'][Math.floor(Math.random() * 3)];
        const dev = ['iPhone14,2', 'iPhone15,2', 'iPhone16,1'][Math.floor(Math.random() * 3)];
        return { deviceType: 'IOS', appVersion: '26.4.2', osVersion: os, locale: 'ru-RU', deviceLocale: 'ru_RU', deviceName: dev, screen: '1170x2532 3.0x', timezone: 'Europe/Moscow', release: 1, buildNumber: 23408 };
    }
    _packPacket(ver, cmd, seq, opcode, payload) {
        const clean = payload && typeof payload === 'object' ? sanitizeBigInt(payload) : payload;
        const payloadBytes = pack(clean) || Buffer.alloc(0);
        const header = Buffer.alloc(10);
        header.writeUInt8(ver, 0);
        header.writeUInt16BE(cmd, 1);
        header.writeUInt8(seq % 256, 3);
        header.writeUInt16BE(opcode, 4);
        header.writeUInt32BE(payloadBytes.length & 0xFFFFFF, 6);
        return Buffer.concat([header, payloadBytes]);
    }
    _unpackPacket(data) {
        const packedLen = data.readUInt32BE(6);
        const payloadLength = packedLen & 0xFFFFFF;
        const compFlag = packedLen >> 24;
        if (data.length < 10 + payloadLength) throw new Error('Incomplete packet');
        let payloadBytes = data.slice(10, 10 + payloadLength);
        if (compFlag !== 0) {
            const out = Buffer.alloc(payloadBytes.length * 10);
            const sz = LZ4.decodeBlock(payloadBytes, out);
            payloadBytes = out.subarray(0, sz);
        }
        const payload = payloadBytes.length > 0 ? sanitizeBigInt(unpack(payloadBytes)) : null;
        return { ver: data.readUInt8(0), cmd: data.readUInt16BE(1), seq: data.readUInt8(3), opcode: data.readUInt16BE(4), payload };
    }
    _sendPing() {
        if (!this.isConnected || !this._socket) return;
        const pkt = this._packPacket(11, 0, this._seq, Opcode.PING, { interactive: true });
        this._seq++;
        if (!this._socketLocked) {
            this._socketLocked = true;
            if (this._socket.write(pkt)) this._socketLocked = false;
            else this._socket.once('drain', () => { this._socketLocked = false; });
        } else this._socketQueue.push(pkt);
    }
    _handleData(data) {
        this._buffer = Buffer.concat([this._buffer, data]);
        while (this._buffer.length >= 10) {
            try {
                const packedLen = this._buffer.readUInt32BE(6);
                const payloadLength = packedLen & 0xFFFFFF;
                const total = 10 + payloadLength;
                if (this._buffer.length < total) break;
                const packet = this._buffer.slice(0, total);
                this._buffer = this._buffer.slice(total);
                const unpacked = this._unpackPacket(packet);
                if (unpacked.opcode !== Opcode.PING && this._pendingResolve && unpacked.seq === this._lastSentSeq) {
                    const err = unpacked.payload?.error;
                    this._pendingResolve(err ? { error: true, errorCode: err, errorMessage: unpacked.payload?.message } : unpacked);
                    this._pendingResolve = null;
                }
            } catch (e) { this._buffer = Buffer.alloc(0); break; }
        }
    }
    async connect() {
        return new Promise((resolve, reject) => {
            if (this.proxyConfig) {
                const w = new ProxyTLSWrapper({ host: this.proxyConfig.host, port: this.proxyConfig.port, auth: this.proxyConfig.auth, targetHost: this.host, targetPort: this.port });
                w.connect().then(sock => {
                    this._socket = sock;
                    this.isConnected = true;
                    this._pingInterval = setInterval(() => this._sendPing(), 5000);
                    this._socket.on('data', d => this._handleData(d));
                    this._socket.on('error', e => { this.isConnected = false; this.emit('error', e); });
                    this._socket.on('close', () => { this.isConnected = false; clearInterval(this._pingInterval); });
                    resolve();
                }).catch(reject);
            } else {
                this._socket = tls.connect({ host: this.host, port: this.port, servername: this.host, rejectUnauthorized: true, timeout: 30000 }, () => {
                    this.isConnected = true;
                    this._pingInterval = setInterval(() => this._sendPing(), 5000);
                    resolve();
                });
                this._socket.on('data', d => this._handleData(d));
                this._socket.on('error', e => { this.isConnected = false; reject(e); });
                this._socket.on('close', () => { this.isConnected = false; if (this._pingInterval) clearInterval(this._pingInterval); });
            }
        });
    }
    async sendRequest(opcode, payload, cmd = 0) {
        if (!this.isConnected) throw new Error('Not connected');
        return new Promise((resolve, reject) => {
            const seq = this._seq;
            this._lastSentSeq = seq;
            const pkt = this._packPacket(11, cmd, seq, opcode, payload);
            this._seq++;
            if (!this._socket.write(pkt)) this._socket.once('drain', () => {});
            this._pendingResolve = (r) => {
                if (r && r.error) reject(r);
                else resolve(r);
            };
            setTimeout(() => {
                if (this._pendingResolve) {
                    this._pendingResolve = null;
                    reject(new Error('Timeout'));
                }
            }, 15000);
        });
    }
    async handshake() {
        return this.sendRequest(Opcode.SESSION_INIT, {
            deviceId: this.deviceId,
            userAgent: this.userAgent,
            mt_instanceid: this.mtInstanceId,
            clientSessionId: this.clientSessionId
        });
    }
    disconnect() {
        if (this._pingInterval) { clearInterval(this._pingInterval); this._pingInterval = null; }
        if (this._socket) { this._socket.end(); this.isConnected = false; }
    }
}

function maskForLog(obj) {
    if (obj === null || obj === undefined) return obj;
    if (typeof obj !== 'object') return obj;
    const copy = Array.isArray(obj) ? [...obj] : { ...obj };
    for (const k of Object.keys(copy)) {
        if (/token|auth|password|secret/i.test(k)) copy[k] = '***';
        else if (typeof copy[k] === 'object') copy[k] = maskForLog(copy[k]);
    }
    return copy;
}

const pool = mysql.createPool({
    host: 'localhost',
    user: 'max',
    password: '54b3L2A7!',
    database: 'max',
    connectionLimit: 5,
    connectTimeout: 10000
});

async function getAccountById(accountId) {
    const id = parseInt(String(accountId), 10);
    if (!id) return null;
    const [rows] = await pool.query(
        'SELECT id, auth_data FROM accounts WHERE id = ? AND status > 0 LIMIT 1',
        [id]
    );
    if (!rows?.length) return null;
    const r = rows[0];
    let auth = null;
    if (r.auth_data) {
        if (typeof r.auth_data === 'object') auth = r.auth_data;
        else try { auth = JSON.parse(r.auth_data); } catch (_) {}
    }
    return { id: r.id, auth_data: auth };
}

async function updateAccountResult(accountId, response) {
    const ts = Math.floor(Date.now() / 1000);
    const [rows] = await pool.query('SELECT account_data FROM accounts WHERE id = ? LIMIT 1', [accountId]);
    let ad = {};
    if (rows?.length && rows[0].account_data != null) {
        const raw = rows[0].account_data;
        if (typeof raw === 'object') ad = { ...raw };
        else try { ad = JSON.parse(raw) || {}; } catch (_) {}
    }
    ad.lastAliveCheck = ts;
    ad.lastAliveResult = response;
    await pool.query('UPDATE accounts SET account_data = ? WHERE id = ?', [JSON.stringify(ad), accountId]);
}

async function updateAction(actionId, response) {
    await pool.query(
        'UPDATE actions SET ended = 1, action_response = ? WHERE action_id = ?',
        [response, actionId]
    );
}

async function main() {
    const accountIdArg = process.argv[2];
    const actionIdArg = process.argv[3];
    if (!accountIdArg?.trim()) {
        console.error('Использование: node check_account.js <account_id> [action_id]');
        process.exit(1);
    }
    const accountId = String(accountIdArg).trim();
    const actionId = actionIdArg ? parseInt(actionIdArg, 10) : null;
    console.log(`Проверка ${accountId}${actionId ? ` (action_id=${actionId})` : ''}...`);

    const account = await getAccountById(accountId);
    if (!account) {
        console.error(`Аккаунт ${accountId} не найден`);
        process.exit(1);
    }

    const { id: rowId, auth_data: auth } = account;
    const token = auth?.authToken || auth?.token;
    const userAgent = auth?.userAgent;

    if (!token) {
        console.error(`[${rowId}] Нет токена`);
        await updateAccountResult(rowId, 'deactive');
        if (actionId) await updateAction(actionId, 'deactive');
        process.exit(1);
    }

    const proxyConfig = {
        host: '31.134.162.90',
        port: 1271,
        auth: 'efaRgy:DyGCum2geDeV'
    };

    const ua = (typeof userAgent === 'object' && userAgent) ? userAgent : undefined;
    const client = new TLSClient({
        host: 'api.oneme.ru',
        port: 443,
        proxy: proxyConfig,
        userAgent: ua
    });

    try {
        for (let attempt = 1; ; attempt++) {
            try {
                await client.connect();
                break;
            } catch (connErr) {
                const isRetryable = connErr?.message === 'PROXY_TIMEOUT' || connErr?.message === 'Таймаут подключения к прокси' || connErr?.code === 'ETIMEDOUT' || connErr?.code === 'ECONNRESET' || connErr?.code === 'ECONNREFUSED';
                if (isRetryable) {
                    console.log(`[${rowId}] Прокси timeout (попытка ${attempt}), повтор...`);
                    if (client._socket) { try { client._socket.destroy(); } catch (_) {} client._socket = null; }
                    continue;
                }
                throw connErr;
            }
        }
        console.log('[req] → SESSION_INIT (handshake)');
        const handshakeRes = await client.handshake();
        console.log('[res] ← SESSION_INIT:', JSON.stringify(maskForLog(handshakeRes), null, 2));

        const loginPayload = { interactive: true, token, chatsCount: 40, chatsSync: 0, contactsSync: 0, presenceSync: -1, draftsSync: 0, userAgent: client.userAgent };
        console.log('[req] → LOGIN:', JSON.stringify(maskForLog(loginPayload), null, 2));
        const res = await client.sendRequest(Opcode.LOGIN, loginPayload, 0);
        console.log('[res] ← LOGIN:', JSON.stringify(maskForLog(res), null, 2));

        if (res?.payload?.token) {
            const ts = Math.floor(Date.now() / 1000);
            await updateAccountResult(rowId, 'active');
            if (actionId) await updateAction(actionId, 'active');
            console.log(`[${rowId}] OK — active, lastAliveCheck=${ts}`);
        } else {
            await updateAccountResult(rowId, 'deactive');
            if (actionId) await updateAction(actionId, 'deactive');
            const err = res?.payload?.error || 'token.invalid';
            console.log(`[${rowId}] Ошибка: ${err}, deactive`);
        }
    } catch (err) {
        await updateAccountResult(rowId, 'deactive');
        if (actionId) await updateAction(actionId, 'deactive');
        const code = err.errorCode || err.error?.errorCode || 'auth.failed';
        console.error(`[${rowId}] ${code} — ${err.message || ''}, deactive`);
    } finally {
        client.disconnect();
        await pool.end();
    }
}

if (require.main === module) {
    main().catch(e => { console.error(e); process.exit(1); });
}
