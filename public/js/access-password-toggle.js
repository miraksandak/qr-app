(function () {
    const initializePasswordToggles = () => {
        document.querySelectorAll("[data-password-toggle]").forEach((button) => {
            const targetId = button.getAttribute("aria-controls") || "";
            const input = targetId ? document.getElementById(targetId) : null;

            if (!input) {
                return;
            }

            const setVisible = (visible) => {
                input.type = visible ? "text" : "password";
                button.classList.toggle("password-toggle--shown", visible);
                button.setAttribute("aria-pressed", visible ? "true" : "false");
                button.setAttribute("aria-label", visible ? "Hide password" : "Show password");
                button.title = visible ? "Hide password" : "Show password";
            };

            button.addEventListener("click", () => {
                setVisible(input.type === "password");
            });

            setVisible(false);
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initializePasswordToggles);
        return;
    }

    initializePasswordToggles();
})();
