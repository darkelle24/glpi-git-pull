"use strict";
(() => {
    const refresh = () => {
        document.querySelectorAll("[data-gitpull-due]").forEach((node) => {
            if (!node.dataset.gitpullDue) return;
            const seconds = Math.max(0, Math.ceil(Number(node.dataset.gitpullDue) - Date.now() / 1000));
            node.textContent = seconds === 0 ? "Au prochain passage du cron" :
                Math.floor(seconds / 60) + " min " + seconds % 60 + " s";
        });
    };
    refresh();
    setInterval(refresh, 1000);
})();
