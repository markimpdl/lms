#!/usr/bin/env node
// =============================================================
// LMS — Deploy incremental via FTP/FTPS
//
// Uso:
//   node scripts/deploy/ftp-deploy.mjs            # incremental (padrão)
//   node scripts/deploy/ftp-deploy.mjs --dry-run  # só lista o que seria enviado
//   node scripts/deploy/ftp-deploy.mjs --force    # ignora o state e reenvia tudo
//
// Primeira execução: sobe tudo (respeitando includes/excludes).
// Execuções seguintes: compara o hash SHA-256 de cada arquivo contra o
// .ftp-state.json local e só envia o que mudou.
//
// Segurança:
//   - Credenciais em .env.deploy (gitignored).
//   - config/env.php, .env*, .mcp.json, .git/, doc/, memory/, examples/,
//     scripts/ e os metadados de Node nunca sobem.
//   - storage/uploads/, storage/logs/, storage/cache/ e public/uploads/ são
//     PRESERVADOS no servidor (ignorados pelo script tanto no upload quanto
//     no state; quem existir no servidor, fica).
// =============================================================

import { readFileSync, readdirSync, statSync, writeFileSync, existsSync } from "node:fs";
import { createHash } from "node:crypto";
import { join, relative, posix, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import * as ftp from "basic-ftp";

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT  = join(SCRIPT_DIR, "..", "..");
const STATE_FILE = join(SCRIPT_DIR, ".ftp-state.json");

const ARGS = new Set(process.argv.slice(2));
const DRY_RUN = ARGS.has("--dry-run");
const FORCE   = ARGS.has("--force");

// ── Pastas/arquivos de primeiro nível NUNCA enviados ─────────────
const EXCLUDE_PATHS = [
    ".git",
    ".github",
    ".gitignore",
    ".gitattributes",
    ".env",
    ".env.deploy",
    ".env.deploy.example",
    ".env.example",
    ".mcp.json",
    ".mcp.json.example",
    ".claude",
    ".idea",
    ".vscode",
    "doc",
    "memory",
    "examples",
    "template_reports",     // working dir do PO (templates pre-migracao)
    "node_modules",
    "package.json",
    "package-lock.json",
    "composer.json",    // produção usa vendor/ já buildado
    "composer.lock",
    "scripts",
    "CLAUDE.md",
    "README.md",
    // mPDF empacota ~94MB de TTFs (E30-01) — auto-deploy via FTPS
    // throttled levaria 30+ min e satura a conexão. Atualizacao do
    // mPDF em prod é evento raro (composer require novo) e o PO sobe
    // manualmente via FileZilla quando acontecer.
    "vendor/mpdf",
];

// ── Subpastas que VOLTAM a ser permitidas mesmo quando o pai está
// excluído. Útil pra `scripts/cron/` (rodado em prod via cPanel) sem
// liberar `scripts/deploy/` (devtool) inteiro. Match exato no prefixo
// posix do path relativo.
const INCLUDE_OVERRIDES = [
    "scripts/cron",
];

const EXCLUDE_PATTERNS = [
    /(^|\/)\.DS_Store$/,
    /(^|\/)Thumbs\.db$/,
    /\.swp$/,
    /\.tmp$/,
    /\.cache$/,
    /\.log$/,
    /^config\/env\.php$/,   // env local nunca sobe; criado manualmente no servidor
];

// ── Pastas PRESERVADAS no servidor (não subimos, não sobrescrevemos) ──
const PRESERVE_ON_SERVER = [
    "storage/uploads",
    "storage/logs",
    "storage/cache",
    "public/uploads",
];

// ── Helpers ──────────────────────────────────────────────────────

function loadEnvDeploy() {
    const file = join(REPO_ROOT, ".env.deploy");
    if (!existsSync(file)) {
        console.error("❌ .env.deploy não encontrado. Copie scripts/deploy/.env.deploy.example para a raiz como .env.deploy e preencha.");
        process.exit(1);
    }
    const env = {};
    for (const raw of readFileSync(file, "utf8").split(/\r?\n/)) {
        const line = raw.trim();
        if (!line || line.startsWith("#") || !line.includes("=")) continue;
        const idx = line.indexOf("=");
        const key = line.slice(0, idx).trim();
        let val = line.slice(idx + 1).trim();
        if (val.length >= 2 && ((val[0] === '"' && val.at(-1) === '"') || (val[0] === "'" && val.at(-1) === "'"))) {
            val = val.slice(1, -1);
        }
        env[key] = val;
    }
    for (const k of ["FTP_HOST", "FTP_USER", "FTP_PASSWORD"]) {
        if (!env[k]) {
            console.error(`❌ Variável ${k} faltando em .env.deploy`);
            process.exit(1);
        }
    }
    return env;
}

function loadState() {
    if (FORCE || !existsSync(STATE_FILE)) return {};
    try { return JSON.parse(readFileSync(STATE_FILE, "utf8")); }
    catch { return {}; }
}

function saveState(state) {
    if (DRY_RUN) return;
    writeFileSync(STATE_FILE, JSON.stringify(state, null, 2), "utf8");
}

function isExcluded(relPath) {
    const posixPath = relPath.split(/\\/).join("/");
    // INCLUDE_OVERRIDES tem precedência sobre EXCLUDE_PATHS — ex.: scripts/
    // está bloqueado, mas scripts/cron/ é liberado pra subir crons de prod.
    for (const allow of INCLUDE_OVERRIDES) {
        if (posixPath === allow || posixPath.startsWith(allow + "/")) {
            // Ainda passa pelos EXCLUDE_PATTERNS abaixo pra evitar lixo (.log, etc.).
            for (const re of EXCLUDE_PATTERNS) {
                if (re.test(posixPath)) return true;
            }
            return false;
        }
    }
    const parts = relPath.split(/[\\/]/);
    for (const ex of EXCLUDE_PATHS) {
        if (parts[0] === ex) return true;
    }
    for (const re of EXCLUDE_PATTERNS) {
        if (re.test(posixPath)) return true;
    }
    for (const preserve of PRESERVE_ON_SERVER) {
        if (posixPath === preserve || posixPath.startsWith(preserve + "/")) return true;
    }
    return false;
}

function hashFile(absPath) {
    const h = createHash("sha256");
    h.update(readFileSync(absPath));
    return h.digest("hex");
}

function hasOverrideUnder(relPath) {
    // True quando algum INCLUDE_OVERRIDES está dentro de relPath — força
    // o walk a descer mesmo que relPath esteja em EXCLUDE_PATHS.
    const posixPath = relPath.split(/\\/).join("/");
    for (const allow of INCLUDE_OVERRIDES) {
        if (allow === posixPath || allow.startsWith(posixPath + "/")) return true;
    }
    return false;
}

function walk(dir, baseRel = "") {
    const out = [];
    for (const entry of readdirSync(dir)) {
        const abs = join(dir, entry);
        const rel = baseRel ? join(baseRel, entry) : entry;
        const st  = statSync(abs);
        if (st.isDirectory()) {
            // Desce se não excluído OU se algum override aponta pra dentro dessa dir.
            if (!isExcluded(rel) || hasOverrideUnder(rel)) {
                out.push(...walk(abs, rel));
            }
        } else if (st.isFile()) {
            if (!isExcluded(rel)) {
                out.push({ abs, rel });
            }
        }
    }
    return out;
}

function toRemote(rel, remoteRoot) {
    const clean = rel.split(/\\/).join("/");
    const root = remoteRoot.replace(/\/+$/, "");
    return root === "" ? "/" + clean : `${root}/${clean}`;
}

async function ensureRemoteDir(client, remotePath) {
    const dir = posix.dirname(remotePath);
    if (!dir || dir === "/" || dir === ".") return;
    await client.ensureDir(dir);
    // ensureDir muda o CWD; voltamos para a raiz antes do próximo upload absoluto
    await client.cd("/");
}

// ── Main ─────────────────────────────────────────────────────────

async function main() {
    const env = loadEnvDeploy();
    const state = loadState();
    const remoteRoot = env.FTP_REMOTE_ROOT || "/";
    const secure = (env.FTP_SECURE || "true").toLowerCase() !== "false";
    const allowSelfSigned = (env.FTP_ALLOW_SELF_SIGNED || "false").toLowerCase() === "true";

    console.log(`\n▶ LMS deploy`);
    console.log(`  Host    : ${env.FTP_HOST}:${env.FTP_PORT || 21}`);
    console.log(`  User    : ${env.FTP_USER}`);
    console.log(`  Secure  : ${secure ? "FTPS explícito" : "FTP simples"}`);
    console.log(`  Remote  : ${remoteRoot}`);
    console.log(`  Mode    : ${FORCE ? "FORCE (full)" : DRY_RUN ? "DRY-RUN" : "incremental"}\n`);

    const files = walk(REPO_ROOT);
    const toUpload = [];
    const newState = {};

    for (const f of files) {
        const hash = hashFile(f.abs);
        newState[f.rel] = hash;
        if (FORCE || state[f.rel] !== hash) {
            toUpload.push(f);
        }
    }

    console.log(`  Total de arquivos considerados : ${files.length}`);
    console.log(`  A enviar                       : ${toUpload.length}`);

    if (toUpload.length === 0) {
        console.log(`\n✔ Nada a fazer.\n`);
        return;
    }

    if (DRY_RUN) {
        for (const f of toUpload) console.log(`  ↑ ${f.rel.split(/\\/).join("/")}`);
        console.log(`\n(dry-run — nada foi enviado)\n`);
        return;
    }

    // ── Resiliência contra rate-limit do FTPS Hostinger ─────────────
    // Plano compartilhado derruba conexão depois de muitos uploads
    // consecutivos (FIN packet ou ECONNRESET). Estratégia:
    //  - Pequeno THROTTLE_MS entre uploads (suaviza burst rate)
    //  - Reconexão proativa a cada RECONNECT_EVERY uploads
    //  - Reconexão reativa quando detecta erro de conexão (1 retry por arquivo)
    const THROTTLE_MS      = 80;
    const RECONNECT_EVERY  = 50;
    const ACCESS_OPTS = {
        host: env.FTP_HOST,
        port: Number(env.FTP_PORT || 21),
        user: env.FTP_USER,
        password: env.FTP_PASSWORD,
        secure,
        secureOptions: allowSelfSigned ? { rejectUnauthorized: false } : undefined,
    };

    let client = new ftp.Client(30000);
    client.ftp.verbose = false;

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    async function connect() {
        await client.access(ACCESS_OPTS);
    }

    async function reconnect() {
        try { client.close(); } catch { /* ignore */ }
        client = new ftp.Client(30000);
        client.ftp.verbose = false;
        await connect();
    }

    function isConnError(err) {
        const msg = String(err?.message || err || "").toLowerCase();
        return msg.includes("fin packet")
            || msg.includes("econnreset")
            || msg.includes("client is closed")
            || msg.includes("epipe")
            || msg.includes("etimedout");
    }

    try {
        await connect();

        let ok = 0, fail = 0, sinceReconnect = 0;
        for (const f of toUpload) {
            const remote = toRemote(f.rel, remoteRoot);

            // Reconexão proativa periódica — evita acumular timeout no socket.
            if (sinceReconnect >= RECONNECT_EVERY) {
                try {
                    await reconnect();
                    sinceReconnect = 0;
                } catch (err) {
                    console.error(`  ! reconexao proativa falhou: ${err.message}`);
                }
            }

            let attempt = 0;
            while (true) {
                try {
                    await ensureRemoteDir(client, remote);
                    await client.uploadFrom(f.abs, remote);
                    console.log(`  ↑ ${f.rel.split(/\\/).join("/")}`);
                    ok++;
                    sinceReconnect++;
                    if (THROTTLE_MS > 0) await sleep(THROTTLE_MS);
                    break;
                } catch (err) {
                    if (attempt === 0 && isConnError(err)) {
                        // Conexão caiu — reconecta e tenta o mesmo arquivo 1x.
                        console.error(`  ! conexao caiu em ${f.rel}, reconectando...`);
                        try {
                            await reconnect();
                            sinceReconnect = 0;
                            attempt++;
                            continue;
                        } catch (reErr) {
                            console.error(`  ✖ ${f.rel} — reconexao falhou: ${reErr.message}`);
                            fail++;
                            delete newState[f.rel];
                            break;
                        }
                    }
                    console.error(`  ✖ ${f.rel} — ${err.message}`);
                    fail++;
                    delete newState[f.rel];
                    break;
                }
            }
        }

        saveState(newState);

        console.log(`\n✔ Enviados: ${ok} | Falhas: ${fail}\n`);
        if (fail > 0) process.exit(1);
    } finally {
        try { client.close(); } catch { /* ignore */ }
    }
}

main().catch((err) => {
    console.error(`\n❌ ${err.message}\n`);
    process.exit(1);
});
