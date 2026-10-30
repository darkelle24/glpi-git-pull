"use strict";
(() => {
    const initialize = () => {
        const deletion = document.querySelector("[data-gitpull-delete]");
        if (deletion && !deletion.dataset.initialized) {
            deletion.dataset.initialized = "1";
            deletion.addEventListener("submit", (event) => {
                const confirmed = window.confirm("Supprimer le dépôt « " + deletion.dataset.repositoryId +
                    " » du manifeste ? Les fichiers clonés seront conservés.");
                deletion.elements.namedItem("confirmed").value = confirmed ? "1" : "0";
                if (!confirmed) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
            });
        }
        const form = document.getElementById("gitpull-repository-form");
        if (!form) return;
        const url = document.getElementById("gitpull-url");
        const path = document.getElementById("gitpull-path");
        const preview = document.getElementById("gitpull-target-preview");
        const pathError = document.getElementById("gitpull-path-error");
        const showError = (message = "") => {
            path.setCustomValidity(message);
            path.classList.toggle("is-invalid", Boolean(message));
            path.setAttribute("aria-invalid", String(Boolean(message)));
            pathError.textContent = message;
            pathError.hidden = !message;
        };
        const update = () => {
            showError();
            let relative = path.value.trim();
            if (!relative) {
                try {
                    const name = new URL(url.value).pathname.replace(/\/+$/, "").split("/").pop().replace(/\.git$/i, "");
                    if (!/^[a-zA-Z0-9._-]+$/.test(name)) throw new Error();
                    const normalized = name.toLowerCase().replace(/[^a-z0-9]/g, "");
                    if (!normalized) {
                        showError("Le nom du dépôt ne contient aucune lettre ni chiffre. Indiquez une destination personnalisée, par exemple plugins/monplugin ou plugins/inventaire2.");
                        preview.textContent = "Destination à corriger.";
                        return;
                    }
                    relative = "plugins/" + normalized;
                } catch {
                    preview.textContent = "Renseignez l’URL du dépôt pour calculer la destination.";
                    return;
                }
            }
            if (relative.startsWith("/") || /[\\:%?#\x00-\x1f]/.test(relative) ||
                relative.split("/").some((part) => ["", ".", "..", ".git"].includes(part.toLowerCase()))) {
                showError("Indiquez un chemin relatif à GLPI, sans retour arrière.");
                preview.textContent = "Destination à corriger.";
                return;
            }
            const parts = relative.split("/");
            if (parts[0] === "plugins" && parts.length > 1 && !/^[a-z0-9]+$/.test(parts[1])) {
                showError("Dans plugins/, le nom du dossier doit contenir uniquement des lettres minuscules (a-z) et des chiffres (0-9), tout attaché, sans espace, accent ni signe. Exemples : plugins/monplugin et plugins/inventaire2.");
                preview.textContent = "Destination à corriger.";
                return;
            }
            preview.textContent = form.dataset.glpiRoot + "/" + relative;
        };
        url.addEventListener("input", update);
        path.addEventListener("input", update);
        update();
    };
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initialize, { once: true });
    } else {
        initialize();
    }
})();
