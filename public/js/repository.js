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
        const update = () => {
            let relative = path.value.trim();
            if (!relative) {
                try {
                    const name = new URL(url.value).pathname.replace(/\/+$/, "").split("/").pop().replace(/\.git$/i, "");
                    if (!/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(name)) throw new Error();
                    relative = "plugins/" + name;
                } catch {
                    preview.textContent = "Renseignez l’URL du dépôt pour calculer la destination.";
                    return;
                }
            }
            if (relative.startsWith("/") || /[\\:%?#\x00-\x1f]/.test(relative) ||
                relative.split("/").some((part) => ["", ".", "..", ".git"].includes(part.toLowerCase()))) {
                preview.textContent = "Indiquez un chemin relatif à GLPI, sans retour arrière.";
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
