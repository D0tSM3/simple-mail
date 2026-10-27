"use strict";

const $ = sel => document.querySelector(sel);
const CSRF = $('meta[name="csrf-token"]').content;
const connected = document.body.dataset.connected === "1";
const debug = document.body.dataset.debug === "1";

const statusEl = $("#status");
const actionsEl = $("#actions");

if (new URLSearchParams(location.search).get("oauth") === "cancelled") {
    const url = new URL(location.href);
    url.searchParams.delete("oauth");
    history.replaceState(history.state, "", url);
}

function el(tag, text = "", className = "") {
    const node = document.createElement(tag);
    node.textContent = text;           // textContent: email data is never parsed as HTML
    if (className) node.className = className;
    return node;
}

/* ---------- HTTP layer ---------------------------------------------------- */

async function apiRequest(url, options = {}) {
    let response;
    try {
        response = await fetch(url, {
            ...options,
            credentials: "same-origin",
            headers: { Accept: "application/json", ...options.headers },
        });
    } catch {
        // fetch only rejects when no HTTP response arrived at all.
        const err = new Error("Network error: could not reach the server.");
        err.status = 0; err.code = "NETWORK_ERROR";
        throw err;
    }

    const isJson = /^application\/(?:[\w.+-]*\+)?json\b/i.test(response.headers.get("content-type") || "");
    let body = null;

    if (response.status !== 204 && isJson) {
        try { body = await response.json(); }
        catch { /* handled below */ }
    }

    if (!response.ok) {
        const info = body?.error;
        const err = new Error(typeof info?.message === "string" ? info.message : `Request failed with HTTP ${response.status}.`);
        err.status = response.status;
        err.code = info?.code || (isJson ? "HTTP_ERROR" : "NON_JSON_RESPONSE"); // e.g. an HTML crash page
        err.requestId = info?.requestId || response.headers.get("X-Request-Id");
        err.retryable = info?.retryable === true;
        err.retryAfter = response.headers.get("Retry-After");
        throw err;
    }

    if (response.status !== 204 && (!body || body.success !== true || !body.data || typeof body.data !== "object")) {
        const err = new Error("The server sent an unreadable response.");
        err.status = response.status; err.code = "BAD_RESPONSE";
        throw err;
    }
    if (response.status === 204) return { response, body: null };
    return { response, body };
}

const RETRYABLE = new Set([429, 502, 503, 504]);
const sleep = ms => new Promise(r => setTimeout(r, ms));

// Retries only transient failures, with bounded exponential backoff + jitter.
async function apiRequestWithRetry(url, options = {}, { maxRetries = 3, onRetry } = {}) {
    for (let attempt = 0; ; attempt++) {
        try {
            return await apiRequest(url, options);
        } catch (err) {
            if (!(err.retryable === true || err.status === 0) || attempt >= maxRetries) throw err;

            const delay = retryDelay(err.retryAfter, attempt);

            onRetry?.(attempt + 1, delay, err);
            await sleep(delay);
        }
    }
}

function retryDelay(value, attempt) {
    if (value !== null && value !== undefined && /^\s*\d+(?:\.\d+)?\s*$/.test(value)) {
        return Math.min(Number(value) * 1000, 30000);
    }
    if (value) {
        const at = Date.parse(value);
        if (Number.isFinite(at)) return Math.min(Math.max(0, at - Date.now()), 30000);
    }
    return Math.min(500 * 2 ** attempt, 8000) + Math.random() * 250;
}

/* ---------- Error presentation ------------------------------------------- */

function showError(err, retryFn) {
    actionsEl.replaceChildren();
    let message = err.message;

    // Branch on our error *code*, not just the status: two different 401s exist.
    switch (err.code) {
        case "UNAUTHENTICATED":
        case "GMAIL_NOT_CONNECTED":
            message = "You're not connected to Gmail.";
            actionsEl.append(linkButton("Connect Gmail", "/auth/connect.php"));
            break;
        case "RECONNECT_REQUIRED":
            message = "Gmail access expired or was revoked. Please reconnect.";
            actionsEl.append(linkButton("Reconnect Gmail", "/auth/connect.php"));
            break;
        case "NON_JSON_RESPONSE":
            message = `The server crashed (HTTP ${err.status}) and returned a non-JSON page.`;
            break;
        case "GMAIL_RATE_LIMITED":
            message = `Rate limited by Gmail. Retry after ${err.retryAfter || "a few"} seconds.`;
            break;
    }

    if (err.requestId) message += ` (request ${err.requestId})`;
    statusEl.textContent = message;

    if (retryFn && (err.retryable || RETRYABLE.has(err.status) || err.status === 0)) {
        const b = el("button", "Retry");
        b.type = "button";
        b.addEventListener("click", retryFn);
        actionsEl.append(b);
    }
    console.error("Request failed:", { status: err.status, code: err.code, requestId: err.requestId });
}

function linkButton(text, href) {
    const a = el("a", text, "btn");
    a.href = href;
    return a;
}

/* ---------- Mailbox ------------------------------------------------------- */

if (connected) {
    const list = $("#email-list"), preview = $("#preview");
    const search = $("#search"), category = $("#category");
    const prevBtn = $("#prev-page"), nextBtn = $("#next-page"), pageNum = $("#page-num");

    let messages = [];
    const tokens = [null];   // tokens[i] = pageToken that loads page i (page 0 has none)
    let page = 0, nextToken = null, loading = false;

    function render() {
        const q = search.value.trim().toLowerCase();
        const cat = category.value;
        list.replaceChildren();

        const shown = messages.filter(m =>
            [m.sender, m.subject, m.category].join(" ").toLowerCase().includes(q)
            && (!cat || m.category === cat));

        if (!shown.length) { list.append(el("p", "No matching messages.")); return; }

        for (const m of shown) {
            const b = el("button", "", "email-item" + (m.unread ? " unread" : ""));
            b.type = "button";
            b.append(el("strong", m.subject), el("span", m.sender), el("small", m.category));
            b.addEventListener("click", () => showPreview(m));
            list.append(b);
        }
    }

    function showPreview(m) {
        const date = m.date ? new Date(m.date).toLocaleString() : "Unknown";
        const report = [
            `Subject: ${m.subject}`,
            `From: ${m.sender}`,
            `Date: ${date}`,
            `Category: ${m.category}`,
            `Labels: ${m.labels.join(", ") || "None"}`,
            `Status: ${m.unread ? "Unread" : "Read"}`,
        ].join("\n");
        const copyButton = el("button", "Copy report");
        copyButton.type = "button";
        copyButton.addEventListener("click", async () => {
            try {
                await copyText(report);
                copyButton.textContent = "Copied";
                setTimeout(() => { copyButton.textContent = "Copy report"; }, 1600);
            } catch {
                copyButton.textContent = "Copy failed";
                setTimeout(() => { copyButton.textContent = "Copy report"; }, 2000);
            }
        });

        preview.replaceChildren(
            el("h2", m.subject),
            el("p", `From: ${m.sender}`),
            el("p", `Date: ${date}`),
            el("p", `Category: ${m.category}`),
            el("p", `Labels: ${m.labels.join(", ") || "None"}`),
            el("p", m.unread ? "Unread" : "Read"),
            copyButton,
            el("p", "Metadata only: the body needs a broader Gmail scope.", "muted"),
        );
    }

    async function copyText(text) {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const input = document.createElement("textarea");
        input.value = text;
        input.setAttribute("readonly", "");
        input.style.position = "fixed";
        input.style.opacity = "0";
        document.body.append(input);
        input.select();
        const copied = document.execCommand("copy");
        input.remove();
        if (!copied) throw new Error("Copy failed.");
    }

    async function loadPage(index) {
        if (loading) return;
        loading = true;
        prevBtn.disabled = nextBtn.disabled = true;
        actionsEl.replaceChildren();
        statusEl.textContent = "Loading emails…";

        try {
            const url = new URL("/api/emails.php", location.origin);
            if (tokens[index]) url.searchParams.set("pageToken", tokens[index]);

            const { body } = await apiRequestWithRetry(url, {}, {
                onRetry: (n, ms) => { statusEl.textContent = `Retrying (${n}) in ${Math.round(ms / 1000)}s…`; },
            });

            page = index;
            const data = body?.data;
            if (!Array.isArray(data?.messages)
                || !data.messages.every(m => m && typeof m.id === "string"
                    && typeof m.sender === "string" && typeof m.subject === "string"
                    && typeof m.category === "string" && Array.isArray(m.labels)
                    && typeof m.unread === "boolean" && (m.date === null || typeof m.date === "string"))
                || !(data.nextPageToken === null || typeof data.nextPageToken === "string")
                || !Number.isFinite(data.elapsedMs)) {
                throw Object.assign(new Error("The server sent an invalid mailbox response."), { status: 200, code: "BAD_RESPONSE" });
            }
            messages = data.messages;
            nextToken = data.nextPageToken || null;
            tokens[page + 1] = nextToken;

            render();
            statusEl.textContent = `${messages.length} messages loaded in ${data.elapsedMs} ms`;
            pageNum.textContent = `Page ${page + 1}`;
        } catch (err) {
            showError(err, () => loadPage(index));
        } finally {
            loading = false;
            prevBtn.disabled = page === 0;
            nextBtn.disabled = !nextToken;
        }
    }

    search.addEventListener("input", render);
    category.addEventListener("change", render);
    prevBtn.addEventListener("click", () => loadPage(page - 1));
    nextBtn.addEventListener("click", () => loadPage(page + 1));

    $("#disconnect").addEventListener("click", async () => {
        if (!confirm("Disconnect Gmail and delete stored tokens?")) return;
        try {
            await apiRequest("/auth/disconnect.php", { method: "POST", headers: { "X-CSRF-Token": CSRF } });
            location.reload();
        } catch (err) { showError(err); }
    });

    loadPage(0);
}

/* ---------- Error lab ----------------------------------------------------- */

if (debug) {
    const log = $("#lab-log");
    const write = line => { log.textContent = `${line}\n${log.textContent}`; };

    $("#lab-send").addEventListener("click", async () => {
        const code = $("#lab-code").value;
        const url = new URL("/api/debug/status.php", location.origin);
        url.searchParams.set("code", code);
        if ($("#lab-fail").checked) url.searchParams.set("fail", "2");

        const useRetry = $("#lab-retry").checked;
        const t0 = performance.now();
        try {
            const { response } = useRetry
                ? await apiRequestWithRetry(url, {}, {
                    maxRetries: 3,
                    onRetry: (n, ms, e) => write(`  retry #${n} after HTTP ${e.status}, waiting ${Math.round(ms)} ms`),
                })
                : await apiRequest(url);
            write(`${code} -> OK, got HTTP ${response.status} in ${Math.round(performance.now() - t0)} ms`);
        } catch (e) {
            write(`${code} -> FAILED status=${e.status} code=${e.code} retryable=${e.retryable} ` +
                  `retryAfter=${e.retryAfter} requestId=${e.requestId}`);
        }
    });
}
