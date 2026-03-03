(() => {
    const body = document.body;
    if (!body) {
        return;
    }

    const manualId = body.dataset.manualId;
    if (!manualId) {
        return;
    }

    const page = body.dataset.page || 'viewer';
    const baseUpgradeUrl = body.dataset.baseUpgradeUrl || '';
    const baseViewerUrl = body.dataset.baseViewerUrl || '';

    const translations = {
        en: {
            language: 'Language',
            title: 'Guest Wi-Fi Login',
            connectTo: 'Connect to',
            wifiLabel: 'Wi-Fi',
            stepLabel: 'Step 1',
            stepText: 'Open Wi-Fi settings → connect to <strong>{ssid}</strong>. If the portal does not open, visit <strong>{portal}</strong>.',
            deviceLabel: 'Device',
            stepsLabel: 'Steps',
            option1: 'Option 1',
            roomSurname: 'Room + Surname',
            option2: 'Option 2',
            accessCode: 'Access code',
            option3: 'Option 3',
            freeAccess: 'Free access',
            noDetails: 'No details required',
            scanCamera: 'Scan with Camera',
            roomNumber: 'Room number',
            surname: 'Surname',
            accessCodeLabel: 'Access code',
            action: 'Action',
            tapConnect: 'Tap <strong>Connect</strong>',
            tapConnectContinue: 'Tap <strong>Connect</strong> / <strong>Continue</strong>',
            online: 'You are online',
            upgradeLabel: 'Upgrade',
            openUpgrade: 'Open upgrade page',
            manualUnavailable: 'Manual unavailable',
            manualUnavailableBody: 'This manual link is missing or expired. Please request a fresh one.',
            upgradeTitle: 'Connection upgrade',
            upgradeUnavailable: 'Upgrade unavailable',
            upgradeUnavailableBody: 'This upgrade link is missing, expired, or disabled for this manual.',
        },
        cs: {
            language: 'Jazyk',
            title: 'Přihlášení k Wi‑Fi',
            connectTo: 'Připojte se k',
            wifiLabel: 'Wi‑Fi',
            stepLabel: 'Krok 1',
            stepText: 'Otevřete nastavení Wi‑Fi → připojte se k <strong>{ssid}</strong>. Pokud se portál neotevře, otevřete <strong>{portal}</strong>.',
            deviceLabel: 'Zařízení',
            stepsLabel: 'Postup',
            option1: 'Volba 1',
            roomSurname: 'Pokoj + Příjmení',
            option2: 'Volba 2',
            accessCode: 'Přístupový kód',
            option3: 'Volba 3',
            freeAccess: 'Volný přístup',
            noDetails: 'Bez údajů',
            scanCamera: 'Naskenujte fotoaparátem',
            roomNumber: 'Číslo pokoje',
            surname: 'Příjmení',
            accessCodeLabel: 'Přístupový kód',
            action: 'Akce',
            tapConnect: 'Klepněte na <strong>Připojit</strong>',
            tapConnectContinue: 'Klepněte na <strong>Připojit</strong> / <strong>Pokračovat</strong>',
            online: 'Jste online',
            upgradeLabel: 'Upgrade',
            openUpgrade: 'Otevřít stránku upgradu',
            manualUnavailable: 'Manuál není dostupný',
            manualUnavailableBody: 'Odkaz na manuál neexistuje nebo vypršel. Vyžádejte si nový.',
            upgradeTitle: 'Vylepšení připojení',
            upgradeUnavailable: 'Upgrade není dostupný',
            upgradeUnavailableBody: 'Odkaz je neplatný, vypršel nebo je vypnutý.',
        },
    };

    const deviceNames = {
        en: { android: 'Android', ios: 'iOS', generic: 'Other' },
        cs: { android: 'Android', ios: 'iOS', generic: 'Ostatní' },
    };

    const htmlKeys = new Set(['tapConnect', 'tapConnectContinue']);

    const showError = () => {
        const errorBlock = document.getElementById('load-error');
        if (errorBlock) {
            errorBlock.classList.remove('hidden');
        }
    };

    const hideUpgradeCard = () => {
        const card = document.getElementById('upgrade-card') || document.getElementById('upgrade-panel') || document.querySelector('.card.upgrade');
        if (card) {
            card.classList.add('hidden');
        }
    };

    const setText = (id, value, fallback = '—') => {
        const el = document.getElementById(id);
        if (!el) {
            return;
        }
        const text = value === undefined || value === null || value === '' ? fallback : value;
        el.textContent = text;
    };

    const setHtml = (id, value) => {
        const el = document.getElementById(id);
        if (!el) {
            return;
        }
        el.innerHTML = value;
    };

    const normalizeList = (value, fallback = []) => {
        return Array.isArray(value) ? value.filter((item) => typeof item === 'string') : fallback;
    };

    const escapeHtml = (value) => {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    const formatPortalHost = (url) => {
        if (!url) {
            return '';
        }
        try {
            const parsed = new URL(url, window.location.origin);
            return parsed.host || parsed.pathname || url;
        } catch (error) {
            return url.replace(/^https?:\/\//, '').replace(/\/$/, '');
        }
    };

    const getPortalUrl = (payload) => {
        return (
            payload.portal?.url ||
            payload.portalUrl ||
            payload.hotel?.portalUrl ||
            payload.hotel?.portal ||
            baseViewerUrl ||
            window.location.origin
        );
    };

    const applyTranslations = (lang) => {
        const dictionary = translations[lang] || translations.en;
        document.querySelectorAll('[data-i18n]').forEach((node) => {
            const key = node.dataset.i18n;
            if (!key || dictionary[key] === undefined) {
                return;
            }
            if (htmlKeys.has(key)) {
                node.innerHTML = dictionary[key];
            } else {
                node.textContent = dictionary[key];
            }
        });
        return dictionary;
    };

    const renderStepText = (lang, payload) => {
        const dictionary = translations[lang] || translations.en;
        const stepTarget = document.getElementById('step1-text');
        if (!stepTarget) {
            return;
        }
        const ssid = payload.hotel?.ssid || 'Hotel-Guest';
        const portalUrl = getPortalUrl(payload);
        const portalHost = formatPortalHost(portalUrl);
        const template = dictionary.stepText || translations.en.stepText;
        const html = template
            .replace('{ssid}', escapeHtml(ssid))
            .replace('{portal}', escapeHtml(portalHost || portalUrl));
        stepTarget.innerHTML = html;
    };

    const resolveSteps = (payload, device) => {
        const steps = payload.steps || {};
        if (Array.isArray(steps[device])) {
            return steps[device];
        }
        if (Array.isArray(steps.generic)) {
            return steps.generic;
        }
        return [];
    };

    const renderStepsList = (payload, device, lang) => {
        const list = document.getElementById('steps-list');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        const steps = resolveSteps(payload, device);
        if (!steps.length) {
            const li = document.createElement('li');
            li.textContent = lang === 'cs' ? 'Žádné kroky nejsou k dispozici.' : 'No steps available for this device.';
            list.appendChild(li);
            return;
        }
        steps.forEach((step) => {
            const li = document.createElement('li');
            li.textContent = step;
            list.appendChild(li);
        });
    };

    const renderDeviceButtons = (payload, lang, onSelect, preferredDevice = null) => {
        const container = document.getElementById('device-buttons');
        if (!container) {
            return;
        }

        const available = normalizeList(payload.device?.available, ['generic']);
        const defaultDevice = typeof payload.device?.default === 'string' ? payload.device.default : available[0];
        const initialDevice = preferredDevice && available.includes(preferredDevice)
            ? preferredDevice
            : (available.includes(defaultDevice) ? defaultDevice : available[0]);
        const labels = deviceNames[lang] || deviceNames.en;

        container.innerHTML = '';

        const setActive = (device) => {
            container.querySelectorAll('.device-button').forEach((button) => {
                if (button.dataset.device === device) {
                    button.classList.add('active');
                } else {
                    button.classList.remove('active');
                }
            });
            onSelect(device);
        };

        available.forEach((device) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'device-button';
            button.dataset.device = device;
            button.textContent = labels[device] || device.toUpperCase();
            button.addEventListener('click', () => setActive(device));
            container.appendChild(button);
        });

        if (initialDevice) {
            setActive(initialDevice);
        }
    };

    const renderOptions = (payload) => {
        const room = payload.options?.roomSurname?.room;
        const surname = payload.options?.roomSurname?.surname;
        const code = payload.options?.accessCode?.code;
        setText('room-value', room);
        setText('surname-value', surname);
        setText('access-code-value', code);
    };

    const renderUpgradeSection = (payload) => {
        const upgradeSection = document.getElementById('upgrade-card');
        if (!upgradeSection) {
            return;
        }

        const upgrade = payload.upgrade || {};
        if (!upgrade.enabled) {
            upgradeSection.classList.add('hidden');
            return;
        }

        upgradeSection.classList.remove('hidden');
        setText('upgrade-title', upgrade.title || 'Improve connection');
        setText('upgrade-body', upgrade.body || '');
        const link = document.getElementById('upgrade-link');
        if (link) {
            const base = baseUpgradeUrl ? baseUpgradeUrl.replace(/\/$/, '') : '/upgrade';
            link.href = `${base}/${manualId}`;
        }
    };

    const renderSupport = (payload) => {
        setText('hotel-name', payload.hotel?.name, '');
        setText('hotel-ssid', payload.hotel?.ssid, 'Hotel-Guest');
        setText('support-text', payload.hotel?.supportText, 'Need help? Contact reception.');
        const footerText = payload.hotel?.footerText || (payload.hotel?.name ? `© ${payload.hotel.name}` : '© Guest Wi-Fi');
        setText('footer-brand', footerText);

        const portalUrl = getPortalUrl(payload);
        const portalHost = formatPortalHost(portalUrl);
        setText('portal-host', portalHost, '');
        const portalHostEl = document.getElementById('portal-host');
        if (portalHostEl) {
            if (portalHost) {
                portalHostEl.classList.remove('hidden');
            } else {
                portalHostEl.classList.add('hidden');
            }
        }
        const dot = document.querySelector('.dot');
        if (dot) {
            if (portalHost) {
                dot.classList.remove('hidden');
            } else {
                dot.classList.add('hidden');
            }
        }

        const logoUrl = payload.hotel?.logoUrl || payload.branding?.logoUrl || '';
        const logo = document.getElementById('hotel-logo');
        if (logo && logoUrl) {
            logo.src = logoUrl;
            logo.alt = payload.hotel?.name || 'Hotel logo';
            logo.classList.remove('hidden');
        }
    };

    const hashString = (value) => {
        let hash = 2166136261;
        for (let i = 0; i < value.length; i += 1) {
            hash ^= value.charCodeAt(i);
            hash = Math.imul(hash, 16777619);
        }
        return hash >>> 0;
    };

    const drawFinder = (ctx, x, y, size, cell) => {
        ctx.fillStyle = '#000';
        ctx.fillRect(x * cell, y * cell, size * cell, size * cell);
        ctx.fillStyle = '#fff';
        ctx.fillRect((x + 1) * cell, (y + 1) * cell, (size - 2) * cell, (size - 2) * cell);
        ctx.fillStyle = '#000';
        ctx.fillRect((x + 2) * cell, (y + 2) * cell, (size - 4) * cell, (size - 4) * cell);
    };

    const renderPseudoQr = (canvas, text) => {
        if (!canvas) {
            return;
        }
        const size = 25;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }
        const cell = canvas.width / size;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        const hash = hashString(text || '');
        const isFinder = (x, y) => {
            const inTopLeft = x < 7 && y < 7;
            const inTopRight = x >= size - 7 && y < 7;
            const inBottomLeft = x < 7 && y >= size - 7;
            return inTopLeft || inTopRight || inBottomLeft;
        };

        for (let y = 0; y < size; y += 1) {
            for (let x = 0; x < size; x += 1) {
                if (isFinder(x, y)) {
                    continue;
                }
                const bit = (hash + x * 17 + y * 31 + (x * y)) & 1;
                if (bit === 1) {
                    ctx.fillStyle = '#000';
                    ctx.fillRect(x * cell, y * cell, cell, cell);
                }
            }
        }

        drawFinder(ctx, 0, 0, 7, cell);
        drawFinder(ctx, size - 7, 0, 7, cell);
        drawFinder(ctx, 0, size - 7, 7, cell);
    };

    const renderQrCodes = (payload) => {
        const portalBase = getPortalUrl(payload).replace(/\/$/, '');
        const room = payload.options?.roomSurname?.room || '';
        const surname = payload.options?.roomSurname?.surname || '';
        const accessCode = payload.options?.accessCode?.code || '';

        const roomUrl = payload.options?.roomSurname?.url ||
            (room || surname ? `${portalBase}/room-login?room=${encodeURIComponent(room)}&surname=${encodeURIComponent(surname)}` : '');
        const accessUrl = payload.options?.accessCode?.url ||
            (accessCode ? `${portalBase}/access-code?code=${encodeURIComponent(accessCode)}` : '');
        const freeUrl = payload.options?.freeAccess?.url || `${portalBase}/free`;

        renderPseudoQr(document.getElementById('qr-room'), roomUrl || `${room}-${surname}`.trim());
        renderPseudoQr(document.getElementById('qr-access'), accessUrl || accessCode);
        renderPseudoQr(document.getElementById('qr-free'), freeUrl || 'free');
    };

    const applyLanguage = (lang, payload, device) => {
        const dictionary = applyTranslations(lang);
        renderStepText(lang, payload);
        renderStepsList(payload, device, lang);

        const title = document.querySelector('title');
        if (title) {
            title.textContent = page === 'upgrade' ? dictionary.upgradeTitle : dictionary.title;
        }
    };

    const renderViewer = (payload) => {
        renderSupport(payload);
        renderOptions(payload);
        renderUpgradeSection(payload);
        renderQrCodes(payload);

        const langSelect = document.getElementById('langSelect');
        const stored = window.localStorage ? window.localStorage.getItem('manual_lang') : null;
        const initialLang = stored && translations[stored] ? stored : 'en';
        if (langSelect) {
            langSelect.value = initialLang;
        }

        let currentDevice = payload.device?.default || 'generic';
        const updateSteps = (device) => {
            currentDevice = device;
            renderStepsList(payload, device, langSelect ? langSelect.value : initialLang);
        };

        renderDeviceButtons(payload, initialLang, updateSteps, currentDevice);
        applyLanguage(initialLang, payload, currentDevice);

        if (langSelect) {
            langSelect.addEventListener('change', () => {
                const lang = langSelect.value;
                if (window.localStorage) {
                    window.localStorage.setItem('manual_lang', lang);
                }
                renderDeviceButtons(payload, lang, updateSteps, currentDevice);
                applyLanguage(lang, payload, currentDevice);
            });
        }
    };

    const renderUpgrade = (payload) => {
        renderSupport(payload);
        const upgrade = payload.upgrade || {};
        if (!upgrade.enabled) {
            hideUpgradeCard();
            showError();
            return;
        }
        setText('upgrade-title', upgrade.title || 'Improve connection');
        setText('upgrade-body', upgrade.body || '');

        const langSelect = document.getElementById('langSelect');
        const stored = window.localStorage ? window.localStorage.getItem('manual_lang') : null;
        const initialLang = stored && translations[stored] ? stored : 'en';
        if (langSelect) {
            langSelect.value = initialLang;
        }
        applyLanguage(initialLang, payload, 'generic');

        if (langSelect) {
            langSelect.addEventListener('change', () => {
                const lang = langSelect.value;
                if (window.localStorage) {
                    window.localStorage.setItem('manual_lang', lang);
                }
                applyLanguage(lang, payload, 'generic');
            });
        }
    };

    const load = async () => {
        try {
            const response = await fetch(`/json/${manualId}`);
            if (!response.ok) {
                showError();
                return;
            }
            const payload = await response.json();
            if (!payload || typeof payload !== 'object') {
                showError();
                return;
            }
            if (page === 'upgrade') {
                renderUpgrade(payload);
            } else {
                renderViewer(payload);
            }
        } catch (error) {
            showError();
        }
    };

    load();
})();
