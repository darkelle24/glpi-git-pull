"use strict";
(() => {
    const initialize = () => {
        const dashboard = document.querySelector("[data-gitpull-dashboard]");
        if (!dashboard || dashboard.dataset.initialized) return;
        dashboard.dataset.initialized = "1";
        const feedback = dashboard.querySelector("[data-gitpull-feedback]");
        const pending = new Set();
        let polling = false;
        let epoch = 0;
        const message = (text, error = false) => {
            feedback.textContent = text || "";
            feedback.className = error ? "alert alert-danger" : "alert alert-success";
            feedback.hidden = !text;
        };
        const countdowns = () => {
            dashboard.querySelectorAll("[data-gitpull-due]").forEach((node) => {
                if (!node.dataset.gitpullDue) { node.textContent = "Désactivé"; return; }
                const seconds = Math.max(0, Math.ceil(Number(node.dataset.gitpullDue) - Date.now() / 1000));
                node.textContent = seconds === 0 ? "Au prochain passage du cron" :
                    Math.floor(seconds / 60) + " min " + seconds % 60 + " s";
            });
        };
        const date = (value) => value ? new Date(value * 1000).toLocaleString("fr-FR") : "Jamais";
        const busy = (node, value) => {
            node.setAttribute("aria-busy", String(value));
            node.querySelectorAll("button[name=action]").forEach((button) => { button.disabled = value; });
        };
        const setStatus = (node, label, status) => {
            const styles = {
                ready: ["success", "✓"],
                update_available: ["warning", "↓"],
                running: ["info", "↻"],
                error: ["danger", "!"],
                unknown: ["secondary", "○"]
            };
            const [color, symbol] = styles[status] || styles.unknown;
            const badge = node.querySelector("[data-gitpull-status-badge]");
            badge.className = "badge bg-" + color + "-lt text-" + color +
                " rounded-pill px-3 py-2 d-inline-flex align-items-center gap-2 text-wrap text-start lh-sm";
            node.querySelector("[data-gitpull-status-icon]").textContent = symbol;
            node.querySelector("[data-gitpull-status]").textContent = label;
        };
        const render = (data) => {
            for (const row of data.rows) {
                const node = Array.from(dashboard.querySelectorAll("[data-gitpull-repository]"))
                    .find((item) => item.dataset.gitpullRepository === row.repo.id);
                if (!node || (pending.has(row.repo.id) && !row.running)) continue;
                const state = row.state || {};
                setStatus(node, row.label, row.status);
                let error = node.querySelector("[data-gitpull-error]");
                if (!error) {
                    error = document.createElement("div");
                    error.dataset.gitpullError = "";
                    error.className = "text-danger mt-1";
                    node.querySelector("[data-gitpull-status-badge]").after(error);
                }
                error.textContent = state.error || "";
                error.hidden = !state.error;
                node.querySelector("[data-gitpull-last-check]").textContent = date(state.last_check);
                node.querySelector("[data-gitpull-last-pull]").textContent = date(state.last_pull_success);
                const due = node.querySelectorAll("[data-gitpull-due]");
                due[0].dataset.gitpullDue = row.next_check ?? "";
                due[1].dataset.gitpullDue = row.next_pull ?? "";
                node.querySelector("[data-gitpull-log]").textContent = state.log || "Aucune exécution.";
                const history = node.querySelector("[data-gitpull-history]");
                history.replaceChildren(...(state.history || []).slice().reverse().map((event) => {
                    const p = document.createElement("p");
                    p.textContent = date(event.at) + " — " + (event.action === "pull" ? "download" : event.action) + " — " + event.status + " " + (event.error || "");
                    return p;
                }));
                busy(node, row.running || row.blocked || pending.has(row.repo.id));
            }
            countdowns();
        };
        const request = async (url, options = {}) => {
            const response = await fetch(url, {
                credentials: "same-origin", cache: "no-store", ...options,
                headers: { Accept: "application/json" }
            });
            if (!response.ok) throw new Error("Réponse HTTP " + response.status);
            const data = await response.json();
            if (!Array.isArray(data.rows)) throw new Error("Réponse du serveur invalide");
            return data;
        };
        const refresh = async () => {
            if (polling || !dashboard.isConnected) return;
            polling = true;
            const current = epoch;
            try {
                const data = await request(dashboard.dataset.refreshUrl);
                if (current === epoch) {
                    render(data);
                    if (data.error) message(data.error, true);
                }
            } catch (error) {
                message("Actualisation des états impossible : " + error.message, true);
            } finally { polling = false; }
        };
        dashboard.querySelectorAll("[data-gitpull-action]").forEach((form) => {
            form.addEventListener("submit", async (event) => {
                event.preventDefault();
                event.stopPropagation();
                const button = event.submitter || form.querySelector('button[value="check"]');
                if (!button || !["check", "pull"].includes(button.value)) return;
                const node = form.closest("[data-gitpull-repository]");
                const id = node.dataset.gitpullRepository;
                if (pending.has(id)) return;
                const body = new FormData(form);
                body.set("action", button.value);
                pending.add(id);
                ++epoch;
                busy(node, true);
                setStatus(node, button.value === "check"
                    ? "Vérification du dépôt en cours…" : "Download du dépôt en cours…", "running");
                const previousError = node.querySelector("[data-gitpull-error]");
                if (previousError) previousError.hidden = true;
                message("");
                try {
                    const url = new URL(form.getAttribute("action"), window.location.href);
                    url.searchParams.set("gitpull_async", "1");
                    const data = await request(url, { method: "POST", body });
                    pending.delete(id);
                    ++epoch;
                    render(data);
                    message(data.error || data.notice, Boolean(data.error));
                } catch (error) {
                    pending.delete(id);
                    ++epoch;
                    setStatus(node, "Résultat à confirmer…", "unknown");
                    message("Résultat de l’action non reçu : " + error.message +
                        ". L’état sera actualisé automatiquement.", true);
                    // A failed response may still have started Git. Never retry the POST automatically.
                    void refresh();
                }
            });
        });
        countdowns();
        setInterval(countdowns, 1000);
        setInterval(refresh, 2000);
        void refresh();
    };
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initialize, { once: true });
    } else {
        initialize();
    }
})();
