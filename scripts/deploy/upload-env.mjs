#!/usr/bin/env node
// =============================================================
// LMS — Upload one-shot de config/env.php para a hospedagem
// Uso: node scripts/deploy/upload-env.mjs
//
// Este script é INDEPENDENTE do deploy incremental e não mexe
// no state file. Use apenas quando precisar atualizar o env
// de produção (credenciais, APP_BASE_URL, SMTP, etc.).
//
// Sobrescreve o config/env.php remoto — use com cuidado.
// =============================================================

import { readFileSync, existsSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import * as ftp from "basic-ftp";

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), "..", "..");

function loadEnvDeploy() {
    const file = join(REPO_ROOT, ".env.deploy");
    if (!existsSync(file)) {
        console.error("❌ .env.deploy não encontrado.");
        process.exit(1);
    }
    const env = {};
    for (const raw of readFileSync(file, "utf8").split(/\r?\n/)) {
        const line = raw.trim();
        if (!line || line.startsWith("#") || !line.includes("=")) continue;
        const idx = line.indexOf("=");
        env[line.slice(0, idx).trim()] = line.slice(idx + 1).trim();
    }
    return env;
}

async function main() {
    const envDeploy = loadEnvDeploy();
    const localEnv = join(REPO_ROOT, "config", "env.php");
    if (!existsSync(localEnv)) {
        console.error("❌ config/env.php local não encontrado. Crie a partir de config/env.example.php.");
        process.exit(1);
    }

    const remoteRoot = (envDeploy.FTP_REMOTE_ROOT || "/").replace(/\/+$/, "");
    const remote = remoteRoot === "" ? "/config/env.php" : `${remoteRoot}/config/env.php`;
    const secure = (envDeploy.FTP_SECURE || "true").toLowerCase() !== "false";
    const allowSelfSigned = (envDeploy.FTP_ALLOW_SELF_SIGNED || "false").toLowerCase() === "true";

    console.log(`\n▶ Upload one-shot de config/env.php`);
    console.log(`  Host   : ${envDeploy.FTP_HOST}:${envDeploy.FTP_PORT || 21}`);
    console.log(`  Secure : ${secure ? "FTPS" : "FTP"}`);
    console.log(`  Remote : ${remote}\n`);

    const client = new ftp.Client(30000);
    try {
        await client.access({
            host: envDeploy.FTP_HOST,
            port: Number(envDeploy.FTP_PORT || 21),
            user: envDeploy.FTP_USER,
            password: envDeploy.FTP_PASSWORD,
            secure,
            secureOptions: allowSelfSigned ? { rejectUnauthorized: false } : undefined,
        });
        await client.ensureDir("config");
        await client.cd("/");
        await client.uploadFrom(localEnv, remote);
        console.log(`✔ config/env.php enviado com sucesso para ${remote}\n`);
    } finally {
        client.close();
    }
}

main().catch((err) => {
    console.error(`\n❌ ${err.message}\n`);
    process.exit(1);
});
