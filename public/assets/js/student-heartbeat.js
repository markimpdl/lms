/**
 * LMS — Heartbeat de tempo online do aluno (TIME-02, issue #437).
 *
 * Cada aba do aluno carrega este script. Apenas UMA aba ativa o ping HTTP
 * por vez (eleita lider via lock em localStorage com TTL 3s). O lider envia
 * POST /api/student/heartbeat a cada 60s ENQUANTO document.visibilityState
 * === 'visible'. Quando a aba lider fica hidden, cede a lideranca pra outra
 * aba visivel; quando volta a visible (e nenhuma assumiu), retoma.
 *
 * Coordenacao multi-tab:
 *   - localStorage[lms_session_uuid]      UUID v4 da sessao (compartilhado)
 *   - localStorage[lms_leader]            JSON {tabId, expiresAt} TTL 3s
 *   - localStorage[lms_last_heartbeat_at] timestamp do ultimo POST OK (evita
 *                                          ping duplicado em handover)
 *   - sessionStorage[lms_tab_id]          tabId unico desta aba
 *   - BroadcastChannel('lms_session')     sinal 'leader-down' acelera eleicao
 *
 * Final da sessao: navigator.sendBeacon em beforeunload (so o lider envia)
 * para POST /api/student/session-end. O cron close-stale-sessions (3min) e
 * a rede de seguranca caso o beacon falhe (mobile background, crash, etc).
 *
 * Config injetada pelo PHP em window.LMS_HEARTBEAT = { csrf: '...' }.
 */
(function () {
    'use strict';

    if (!window.crypto || typeof window.crypto.randomUUID !== 'function') return;
    if (typeof window.fetch !== 'function') return;

    var config = window.LMS_HEARTBEAT || {};
    var csrfToken = config.csrf;
    if (!csrfToken) return;

    var HEARTBEAT_URL    = '/api/student/heartbeat';
    var SESSION_END_URL  = '/api/student/session-end';
    var HEARTBEAT_MS     = 60000;
    var TICK_MS          = 1000;
    var LEADER_TTL_MS    = 3000;

    var KEY_SESSION   = 'lms_session_uuid';
    var KEY_LEADER    = 'lms_leader';
    var KEY_LAST_HB   = 'lms_last_heartbeat_at';
    var KEY_TAB       = 'lms_tab_id';

    function readJSON(storage, key) {
        try { return JSON.parse(storage.getItem(key) || 'null'); }
        catch (e) { return null; }
    }
    function writeJSON(storage, key, value) {
        try { storage.setItem(key, JSON.stringify(value)); } catch (e) {}
    }
    function readStr(storage, key) {
        try { return storage.getItem(key); } catch (e) { return null; }
    }
    function writeStr(storage, key, value) {
        try { storage.setItem(key, value); } catch (e) {}
    }
    function removeKey(storage, key) {
        try { storage.removeItem(key); } catch (e) {}
    }

    var TAB_ID = readStr(sessionStorage, KEY_TAB);
    if (!TAB_ID) {
        TAB_ID = window.crypto.randomUUID();
        writeStr(sessionStorage, KEY_TAB, TAB_ID);
    }

    function ensureSessionUuid() {
        var id = readStr(localStorage, KEY_SESSION);
        if (!id) {
            id = window.crypto.randomUUID();
            writeStr(localStorage, KEY_SESSION, id);
        }
        return id;
    }
    function regenerateSessionUuid() {
        var id = window.crypto.randomUUID();
        writeStr(localStorage, KEY_SESSION, id);
        return id;
    }

    function getLastHeartbeatAt() {
        var raw = readStr(localStorage, KEY_LAST_HB);
        var n = raw ? parseInt(raw, 10) : 0;
        return isNaN(n) ? 0 : n;
    }
    function setLastHeartbeatAt(ts) {
        writeStr(localStorage, KEY_LAST_HB, String(ts));
    }

    function readLeader() {
        var lock = readJSON(localStorage, KEY_LEADER);
        if (!lock || typeof lock.expiresAt !== 'number') return null;
        return lock;
    }
    function writeLeader() {
        writeJSON(localStorage, KEY_LEADER, { tabId: TAB_ID, expiresAt: Date.now() + LEADER_TTL_MS });
    }
    function clearLeader() {
        var lock = readLeader();
        if (lock && lock.tabId === TAB_ID) {
            removeKey(localStorage, KEY_LEADER);
        }
    }
    function isLeader() {
        var lock = readLeader();
        return !!(lock && lock.tabId === TAB_ID && lock.expiresAt > Date.now());
    }
    function leaderSlotOpen() {
        var lock = readLeader();
        return !lock || lock.expiresAt <= Date.now();
    }

    var bc = null;
    try { bc = new BroadcastChannel('lms_session'); } catch (e) { bc = null; }

    function broadcast(type) {
        if (!bc) return;
        try { bc.postMessage({ type: type, tabId: TAB_ID, ts: Date.now() }); } catch (e) {}
    }

    if (bc) {
        bc.onmessage = function (ev) {
            if (!ev || !ev.data) return;
            // Sinal pra acelerar eleicao quando lider sai. O proximo tick
            // (em ate TICK_MS) cuida da reeleicao se a aba estiver visible.
            if (ev.data.type === 'leader-down') {
                // No-op: cada aba reavalia no proximo tick.
            }
        };
    }

    function buildBody(uuid) {
        var fd = new FormData();
        fd.set('_csrf', csrfToken);
        fd.set('session_uuid', uuid);
        return fd;
    }

    function sendHeartbeat() {
        var uuid = ensureSessionUuid();
        var now = Date.now();
        setLastHeartbeatAt(now);
        fetch(HEARTBEAT_URL, {
            method: 'POST',
            body: buildBody(uuid),
            credentials: 'same-origin',
            keepalive: true,
        }).then(function (resp) {
            return resp.ok ? resp.json() : null;
        }).then(function (data) {
            if (!data) return;
            // Server pode rejeitar UUID stale/closed/owner — regenera local
            // e o proximo tick (em ate 60s) abrira nova sessao com 'created'.
            if (data.status && data.status.indexOf('rejected_') === 0) {
                regenerateSessionUuid();
            }
        }).catch(function () {
            // Falha de rede e silenciosa: o tick recoloca lastHeartbeatAt
            // a cada 60s; se a sessao expirar no servidor, o cron fecha e
            // o proximo POST cria nova.
        });
    }

    function sendEndBeacon() {
        var uuid = readStr(localStorage, KEY_SESSION);
        if (!uuid) return;
        try {
            navigator.sendBeacon(SESSION_END_URL, buildBody(uuid));
        } catch (e) { /* swallow */ }
    }

    function tick() {
        var visible = document.visibilityState === 'visible';

        if (isLeader()) {
            if (!visible) {
                // Cede lideranca: outra aba visible assume mais rapido.
                clearLeader();
                broadcast('leader-down');
                return;
            }
            writeLeader();
        } else if (visible && leaderSlotOpen()) {
            // Tenta assumir; jitter pequeno reduz chance de duas abas
            // cravarem o mesmo lock no mesmo milissegundo.
            writeLeader();
            setTimeout(function () {
                if (!isLeader() || document.visibilityState !== 'visible') return;
                maybeFireHeartbeat();
            }, 20 + Math.floor(Math.random() * 80));
            return;
        }

        if (isLeader() && visible) maybeFireHeartbeat();
    }

    function maybeFireHeartbeat() {
        var now = Date.now();
        if (now - getLastHeartbeatAt() >= HEARTBEAT_MS) {
            sendHeartbeat();
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && isLeader()) {
            maybeFireHeartbeat();
        }
    });

    window.addEventListener('beforeunload', function () {
        if (isLeader()) {
            sendEndBeacon();
            clearLeader();
            broadcast('leader-down');
        }
    });

    // Bootstrap: fire imediato se visible (1a sessao do dia, gap > 60s desde
    // o ultimo POST OU primeira aba aberta).
    if (document.visibilityState === 'visible') {
        if (leaderSlotOpen()) writeLeader();
        if (isLeader()) maybeFireHeartbeat();
    }
    setInterval(tick, TICK_MS);
})();
