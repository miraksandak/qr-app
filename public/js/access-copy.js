(function () {
    const initializeCopyButtons = () => {
        const copyButtons = Array.from(document.querySelectorAll("[data-copy-target]"));
        const copyResetTimers = new WeakMap();

        if (copyButtons.length === 0) {
            return;
        }

        const getTarget = (button) => document.getElementById(button.dataset.copyTarget || "");

        const getTargetLabel = (button) => {
            const target = getTarget(button);
            const label = target ? target.querySelector("span") : null;

            return label && label.textContent ? label.textContent.trim() : "document";
        };

        const getCopyValue = (button) => {
            const target = getTarget(button);
            if (!target) {
                return "";
            }

            const value = target.querySelector("strong");
            if (value && value.textContent.trim() !== "") {
                return value.textContent.trim();
            }

            const href = target.getAttribute("href") || "";

            return href !== "#" ? href : "";
        };

        const copyText = async (value) => {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
                try {
                    await navigator.clipboard.writeText(value);
                    return;
                } catch (error) {
                    // Fall through to the selection-based fallback below.
                }
            }

            const textarea = document.createElement("textarea");
            textarea.value = value;
            textarea.setAttribute("readonly", "");
            textarea.style.position = "fixed";
            textarea.style.top = "-9999px";
            document.body.appendChild(textarea);
            textarea.select();

            try {
                if (!document.execCommand("copy")) {
                    throw new Error("Copy command failed.");
                }
            } finally {
                textarea.remove();
            }
        };

        const setCopyButtonState = (button, state) => {
            window.clearTimeout(copyResetTimers.get(button));
            button.classList.toggle("result-copy--copied", state === "copied");
            button.classList.toggle("result-copy--failed", state === "failed");

            if (state === "copied") {
                button.setAttribute("aria-label", "Link copied");
                button.title = "Link copied";
            } else if (state === "failed") {
                button.setAttribute("aria-label", "Copy failed");
                button.title = "Copy failed";
            } else {
                const label = getTargetLabel(button);
                button.setAttribute("aria-label", `Copy ${label} link`);
                button.title = `Copy ${label} link`;
            }

            if (state === "copied" || state === "failed") {
                const timerId = window.setTimeout(() => {
                    setCopyButtonState(button, "idle");
                }, 1600);
                copyResetTimers.set(button, timerId);
            }
        };

        const syncCopyButton = (button) => {
            button.disabled = getCopyValue(button) === "";
            setCopyButtonState(button, "idle");
        };

        copyButtons.forEach((button) => {
            const target = getTarget(button);
            const value = target ? target.querySelector("strong") : null;

            if (value) {
                new MutationObserver(() => syncCopyButton(button)).observe(value, {
                    childList: true,
                    characterData: true,
                    subtree: true,
                });
            }

            if (target) {
                new MutationObserver(() => syncCopyButton(button)).observe(target, {
                    attributes: true,
                    attributeFilter: ["href"],
                });
            }

            syncCopyButton(button);

            button.addEventListener("click", async () => {
                const valueToCopy = getCopyValue(button);
                if (!valueToCopy) {
                    return;
                }

                try {
                    await copyText(valueToCopy);
                    setCopyButtonState(button, "copied");
                } catch (error) {
                    setCopyButtonState(button, "failed");
                }
            });
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initializeCopyButtons);
        return;
    }

    initializeCopyButtons();
})();
