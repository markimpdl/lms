#!/usr/bin/env node
// =============================================================
// LMS — Limpa arquivos órfãos em produção
//
// Uso:
//   node scripts/deploy/ftp-cleanup-orphans.mjs            # dry-run (default)
//   node scripts/deploy/ftp-cleanup-orphans.mjs --apply    # realmente apaga
//
// Remove arquivos que existem no servidor mas não existem mais
// no repo. Lista curada, não automática — cada entrada abaixo foi
// confirmada como órfão legítimo (não storage/uploads/, não algo
// gerado em runtime).
//
// Se um arquivo já não existe (FTP 550), segue em frente.
// =============================================================

import { readFileSync, existsSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import * as ftp from "basic-ftp";

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT  = join(SCRIPT_DIR, "..", "..");

const ARGS = new Set(process.argv.slice(2));
const APPLY = ARGS.has("--apply");

// Arquivos órfãos conhecidos. Caminhos relativos ao FTP_REMOTE_ROOT.
// Cada entrada precisa de uma nota explicando a origem — NÃO adicionar
// sem verificar que o arquivo realmente não existe mais no repo local.
//
// Lista inicial (v0.3.0-v0.4.0: HtmlPurifier.php, _diag_students.php,
// _diag_purify.php) já foi processada em 2026-04-25. Adicionar entradas
// novas aqui conforme forem identificadas, no formato:
//
//     { path: "relative/path/to/file.php",
//       note: "vX.Y.Z: motivo. Por que ficou órfão." },
const ORPHANS = [];

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

function toRemote(rel, remoteRoot) {
    const clean = rel.split(/\\/).join("/");
    const root = remoteRoot.replace(/\/+$/, "");
    return root === "" ? "/" + clean : `${root}/${clean}`;
}

// Tenta um SIZE no remote; retorna true se existe, false se 550.
async function remoteExists(client, remotePath) {
    try {
        await client.size(remotePath);
        return true;
    } catch (e) {
        if (String(e?.code ?? e?.message ?? e).includes("550")) return false;
        throw e;
    }
}

async function main() {
    const env = loadEnvDeploy();
    const remoteRoot = env.FTP_REMOTE_ROOT || "/";
    const secure = (env.FTP_SECURE || "true").toLowerCase() !== "false";
    const allowSelfSigned = (env.FTP_ALLOW_SELF_SIGNED || "false").toLowerCase() === "true";

    console.log(`\n▶ LMS cleanup`);
    console.log(`  Host    : ${env.FTP_HOST}:${env.FTP_PORT || 21}`);
    console.log(`  Remote  : ${remoteRoot}`);
    console.log(`  Mode    : ${APPLY ? "APPLY (vai apagar)" : "DRY-RUN (só lista)"}\n`);

    const client = new ftp.Client(30_000);
    try {
        await client.access({
            host: env.FTP_HOST,
            port: Number(env.FTP_PORT || 21),
            user: env.FTP_USER,
            password: env.FTP_PASSWORD,
            secure,
            secureOptions: { rejectUnauthorized: !allowSelfSigned },
        });

        let existed = 0;
        let missing = 0;
        let deleted = 0;
        let failed  = 0;

        for (const orphan of ORPHANS) {
            const remote = toRemote(orphan.path, remoteRoot);
            process.stdout.write(`  ${orphan.path} ... `);
            const exists = await remoteExists(client, remote);
            if (!exists) {
                missing++;
                console.log("já removido");
                continue;
            }
            existed++;
            if (!APPLY) {
                console.log("EXISTE (seria apagado)");
                continue;
            }
            try {
                await client.remove(remote);
                deleted++;
                console.log("APAGADO ✓");
            } catch (e) {
                failed++;
                console.log(`FALHA: ${String(e?.message ?? e)}`);
            }
        }

        console.log("");
        console.log(`  Total na lista : ${ORPHANS.length}`);
        console.log(`  Já removidos   : ${missing}`);
        console.log(`  Existentes     : ${existed}`);
        if (APPLY) {
            console.log(`  Apagados       : ${deleted}`);
            console.log(`  Falhas         : ${failed}`);
        } else if (existed > 0) {
            console.log("\n  Re-rodar com --apply pra apagar de verdade.");
        }
    } finally {
        client.close();
    }
}

main().catch(e => {
    console.error("❌ Erro:", e);
    process.exit(1);
});
