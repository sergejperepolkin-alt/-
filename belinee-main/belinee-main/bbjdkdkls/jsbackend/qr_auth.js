#!/usr/bin/env node
/**
 * Авторизация по QR-коду.
 * Использование: node qr_auth.js <account_id> <qr_content>
 *
 * qr_content — ссылка из QR (например tg://login?token=...), которую скрипт отправит для авторизации.
 */

const mysql = require('mysql2/promise');

const pool = mysql.createPool({
    host: 'artemkk5.beget.tech',
    user: 'artemkk5_max',
    password: '54b3L2A7!',
    database: 'artemkk5_max',
    connectionLimit: 5,
    connectTimeout: 10000
});

async function main() {
    const accountIdArg = process.argv[2];
    const qrContentArg = process.argv[3];
    if (!accountIdArg || !qrContentArg) {
        console.error('Использование: node qr_auth.js <account_id> <qr_content>');
        process.exit(1);
    }
    const accountId = parseInt(String(accountIdArg), 10);
    const qrContent = String(qrContentArg);

    console.log(`[qr_auth] account_id=${accountId}, qr_content=${qrContent.substring(0, 50)}...`);

    try {
        const [rows] = await pool.query(
            'SELECT id, auth_data FROM accounts WHERE id = ? AND status > 0 LIMIT 1',
            [accountId]
        );
        if (!rows?.length) {
            console.error(`Аккаунт ${accountId} не найден`);
            process.exit(1);
        }

        // TODO: здесь — логика авторизации по QR (отправка qr_content в API, получение токена, обновление auth_data)
        // qrContent — ссылка вида tg://login?token=xxx или https://...
        console.log(`[qr_auth] QR получен, авторизация — TODO`);
    } catch (err) {
        console.error('[qr_auth]', err.message);
        process.exit(1);
    } finally {
        await pool.end();
    }
}

if (require.main === module) {
    main().catch(e => { console.error(e); process.exit(1); });
}
