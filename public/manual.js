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
            deviceLabel: 'Device',
            ssidLabel: 'Network',
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
            firstName: 'First name',
            checkinNumber: 'Check-in number',
            passwordLabel: 'Password',
            accessCodeLabel: 'Access code',
            networkLabel: 'Network',
            authRoom: 'room number and surname',
            authAccess: 'access code',
            action: 'Action',
            tapConnect: 'Tap <strong>Connect</strong>',
            tapConnectContinue: 'Tap <strong>Connect</strong> / <strong>Continue</strong>',
            online: 'You are online',
            upgradeLabel: 'Upgrade',
            upgradeCardTitle: 'Improve connection',
            upgradeBody: 'If your connection is unstable, open the upgrade page.',
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
            deviceLabel: 'Zařízení',
            ssidLabel: 'Síť',
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
            firstName: 'Jméno',
            checkinNumber: 'Číslo check-inu',
            passwordLabel: 'Heslo',
            accessCodeLabel: 'Přístupový kód',
            networkLabel: 'Síť',
            authRoom: 'číslo pokoje a příjmení',
            authAccess: 'přístupový kód',
            action: 'Akce',
            tapConnect: 'Klepněte na <strong>Připojit</strong>',
            tapConnectContinue: 'Klepněte na <strong>Připojit</strong> / <strong>Pokračovat</strong>',
            online: 'Jste online',
            upgradeLabel: 'Upgrade',
            upgradeCardTitle: 'Vylepšit připojení',
            upgradeBody: 'Pokud je připojení nestabilní, otevřete stránku upgradu.',
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

    const instructionSets = {
        en: {
            portal: {
                android: [
                    'Open Wi-Fi settings on your phone.',
                    'Connect to {ssid}.',
                    'If the portal does not open, open {portal}.',
                    'Enter {auth} and tap Connect.',
                ],
                ios: [
                    'Open Settings and tap Wi-Fi.',
                    'Connect to {ssid}.',
                    'If the portal does not open, open {portal}.',
                    'Enter {auth} and tap Connect.',
                ],
                generic: [
                    'Open Wi-Fi settings on your device.',
                    'Connect to {ssid}.',
                    'If the portal does not open, open {portal}.',
                    'Enter {auth} and tap Connect.',
                ],
            },
            free: {
                android: [
                    'Open Wi-Fi settings on your phone.',
                    'Connect to {ssid}.',
                    'You are online.',
                ],
                ios: [
                    'Open Settings and tap Wi-Fi.',
                    'Connect to {ssid}.',
                    'You are online.',
                ],
                generic: [
                    'Open Wi-Fi settings on your device.',
                    'Connect to {ssid}.',
                    'You are online.',
                ],
            },
        },
        cs: {
            portal: {
                android: [
                    'Otevřete nastavení Wi‑Fi v telefonu.',
                    'Připojte se k {ssid}.',
                    'Pokud se portál neotevře, otevřete {portal}.',
                    'Zadejte {auth} a potvrďte připojení.',
                ],
                ios: [
                    'Otevřete Nastavení a klepněte na Wi‑Fi.',
                    'Připojte se k {ssid}.',
                    'Pokud se portál neotevře, otevřete {portal}.',
                    'Zadejte {auth} a potvrďte připojení.',
                ],
                generic: [
                    'Otevřete nastavení Wi‑Fi na zařízení.',
                    'Připojte se k {ssid}.',
                    'Pokud se portál neotevře, otevřete {portal}.',
                    'Zadejte {auth} a potvrďte připojení.',
                ],
            },
            free: {
                android: [
                    'Otevřete nastavení Wi‑Fi v telefonu.',
                    'Připojte se k {ssid}.',
                    'Jste online.',
                ],
                ios: [
                    'Otevřete Nastavení a klepněte na Wi‑Fi.',
                    'Připojte se k {ssid}.',
                    'Jste online.',
                ],
                generic: [
                    'Otevřete nastavení Wi‑Fi na zařízení.',
                    'Připojte se k {ssid}.',
                    'Jste online.',
                ],
            },
        },
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
            payload.hotel?.portalUrl ||
            payload.portal?.url ||
            payload.portalUrl ||
            payload.hotel?.portal ||
            baseViewerUrl ||
            window.location.origin
        );
    };

    const normalizeUsage = (value) => {
        const raw = String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        if (!raw) {
            return '';
        }
        if (['pms', 'room', 'roomsurname', 'roomlogin'].includes(raw)) {
            return 'pms';
        }
        if (['ac', 'access', 'accesscode', 'code'].includes(raw)) {
            return 'ac';
        }
        if (['option1', 'opt1', 'o1'].includes(raw)) {
            return 'pms';
        }
        if (['option2', 'opt2', 'o2'].includes(raw)) {
            return 'ac';
        }
        if (['option3', 'opt3', 'o3'].includes(raw)) {
            return 'free';
        }
        if (['free', 'guest', 'open'].includes(raw)) {
            return 'free';
        }
        return '';
    };

    const buildSsidCatalog = (payload) => {
        const catalog = { pms: [], ac: [], free: [] };
        const rawList = Array.isArray(payload.hotel?.ssids) ? payload.hotel.ssids : [];
        rawList.forEach((item) => {
            if (typeof item === 'string') {
                const name = item.trim();
                if (name) {
                    catalog.pms.push({ name, usage: 'pms' });
                }
                return;
            }
            if (!item || typeof item !== 'object') {
                return;
            }
            const name = typeof item.name === 'string'
                ? item.name.trim()
                : (typeof item.ssid === 'string' ? item.ssid.trim() : '');
            if (!name) {
                return;
            }
            const usage = normalizeUsage(item.usage || item.purpose || item.type) || 'pms';
            catalog[usage]?.push({ name, usage });
        });

        const legacy = typeof payload.hotel?.ssid === 'string' ? payload.hotel.ssid.trim() : '';
        if (legacy && catalog.pms.length === 0 && catalog.ac.length === 0 && catalog.free.length === 0) {
            catalog.pms.push({ name: legacy, usage: 'pms' });
        }

        return catalog;
    };

    const resolveMode = (payload, catalog) => {
        const rawMode = payload.options?.mode || payload.auth?.mode || '';
        let mode = normalizeUsage(rawMode) || '';
        const hasPms = catalog.pms.length > 0;
        const hasAc = catalog.ac.length > 0;
        const hasFree = catalog.free.length > 0;

        if (!mode) {
            mode = hasPms ? 'pms' : (hasAc ? 'ac' : (hasFree ? 'free' : 'pms'));
        }

        if (mode === 'pms' && !hasPms && hasAc) {
            mode = 'ac';
        }
        if (mode === 'ac' && !hasAc && hasPms) {
            mode = 'pms';
        }
        if (mode === 'free' && !hasFree) {
            mode = hasPms ? 'pms' : (hasAc ? 'ac' : 'pms');
        }

        return mode || 'pms';
    };

    const resolveAuthFields = (payload, mode) => {
        const options = payload.options || {};
        const fields = [];
        const explicit = Array.isArray(options.fields) ? options.fields : null;

        if (explicit) {
            explicit.forEach((field) => {
                if (!field || typeof field !== 'object') {
                    return;
                }
                const key = typeof field.key === 'string' ? field.key.trim() : '';
                const value = field.value;
                if (!key) {
                    return;
                }
                fields.push({ key, value });
            });
            return fields;
        }

        if (mode === 'ac') {
            const source = options.ac ?? options.accessCode ?? {};
            const code = typeof source === 'string'
                ? source
                : (source.code ?? source.value ?? source.accessCode ?? '');
            fields.push({ key: 'accessCode', value: code });
        } else {
            const source = options.pms ?? options.roomSurname ?? {};
            const room = typeof source === 'string' ? source : (source.room ?? source.roomNumber ?? '');
            const surname = typeof source === 'object' && source ? (source.surname ?? '') : '';
            fields.push({ key: 'roomNumber', value: room });
            fields.push({ key: 'surname', value: surname });
        }

        return fields;
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

    const resolveAuthLabel = (dictionary, mode) => {
        if (mode === 'ac') {
            return dictionary.authAccess || translations.en.authAccess;
        }
        return dictionary.authRoom || translations.en.authRoom;
    };

    const formatStep = (template, context) => {
        return template
            .replace('{ssid}', context.ssid || '')
            .replace('{portal}', context.portal || '')
            .replace('{auth}', context.auth || '');
    };

    const resolveInstructionSteps = (lang, mode, device, context) => {
        const set = instructionSets[lang] || instructionSets.en;
        const group = mode === 'free' ? set.free : set.portal;
        const steps = Array.isArray(group[device]) ? group[device] : (group.generic || []);
        return steps
            .filter((step) => (context.portal || !step.includes('{portal}')))
            .map((step) => formatStep(step, context));
    };

    const renderStepsList = (mode, device, lang, context) => {
        const list = document.getElementById('steps-list');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        const steps = resolveInstructionSteps(lang, mode, device, context);
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

    const renderSsidButtons = (ssids, onSelect, preferredSsid = null) => {
        const section = document.getElementById('ssid-section');
        const container = document.getElementById('ssid-buttons');
        if (!section || !container) {
            return;
        }

        const list = Array.isArray(ssids) ? ssids : [];
        if (list.length <= 1) {
            container.innerHTML = '';
            section.classList.add('hidden');
            if (list[0]) {
                onSelect(list[0].name);
            }
            return;
        }

        section.classList.remove('hidden');
        container.innerHTML = '';
        const names = list.map((item) => item.name);
        const initial = preferredSsid && names.includes(preferredSsid) ? preferredSsid : names[0];

        const setActive = (name) => {
            container.querySelectorAll('.device-button').forEach((button) => {
                if (button.dataset.ssid === name) {
                    button.classList.add('active');
                } else {
                    button.classList.remove('active');
                }
            });
            onSelect(name);
        };

        list.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'device-button';
            button.dataset.ssid = item.name;
            button.textContent = item.name;
            button.addEventListener('click', () => setActive(item.name));
            container.appendChild(button);
        });

        if (initial) {
            setActive(initial);
        }
    };

    const resolveFieldLabel = (dictionary, key) => {
        const raw = String(key || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        const mapping = {
            room: 'roomNumber',
            roomnumber: 'roomNumber',
            surname: 'surname',
            firstname: 'firstName',
            checkinnumber: 'checkinNumber',
            password: 'passwordLabel',
            lastname: 'surname',
            accesscode: 'accessCodeLabel',
            code: 'accessCodeLabel',
        };
        const mapped = mapping[raw];
        if (mapped && dictionary[mapped]) {
            return dictionary[mapped];
        }
        return key || '—';
    };

    const renderAuthFields = (fields, dictionary) => {
        const container = document.getElementById('auth-fields');
        if (!container) {
            return;
        }
        container.innerHTML = '';

        if (!fields.length) {
            fields = [{ key: dictionary.accessCodeLabel || 'Detail', value: '—' }];
        }

        fields.forEach((field) => {
            const rawKey = String(field.key || '').toLowerCase().replace(/[^a-z0-9]/g, '');
            const box = document.createElement('div');
            box.className = 'dataBox';
            const label = document.createElement('div');
            label.className = 'dataLabel';
            label.textContent = resolveFieldLabel(dictionary, field.key);
            const value = document.createElement('div');
            value.className = 'dataValue';
            if (['accesscode', 'code'].includes(rawKey)) {
                value.classList.add('mono');
            }
            value.textContent = field.value === undefined || field.value === null || field.value === '' ? '—' : String(field.value);
            box.appendChild(label);
            box.appendChild(value);
            container.appendChild(box);
        });
    };

    const renderAuthCard = (payload, mode, dictionary) => {
        const labelEl = document.getElementById('auth-option-label');
        const titleEl = document.getElementById('auth-option-title');
        if (labelEl && titleEl) {
            if (mode === 'ac') {
                labelEl.textContent = dictionary.option2 || translations.en.option2;
                titleEl.textContent = dictionary.accessCode || translations.en.accessCode;
            } else {
                labelEl.textContent = dictionary.option1 || translations.en.option1;
                titleEl.textContent = dictionary.roomSurname || translations.en.roomSurname;
            }
        }
        const fields = resolveAuthFields(payload, mode);
        renderAuthFields(fields, dictionary);
    };

    const renderFreeCard = (payload, freeSsids) => {
        const card = document.getElementById('free-card');
        if (!card) {
            return;
        }
        const freeConfig = payload.options?.freeAccess;
        const enabled = freeSsids.length > 0 || freeConfig === true || freeConfig?.enabled === true;
        if (!enabled) {
            card.classList.add('hidden');
            return;
        }
        card.classList.remove('hidden');
        const name = freeSsids.length > 1
            ? freeSsids.map((item) => item.name).join(', ')
            : (freeSsids[0]?.name || '');
        setText('free-ssid-value', name);
    };

    const resolveUpgradeConfig = (payload) => {
        const upgrade = payload.hotel?.upgrade || payload.upgrade || {};
        const enabled = upgrade.enabled === true;
        const rawUrl = (typeof upgrade.url === 'string' ? upgrade.url.trim() : '') ||
            (typeof payload.upgradeUrl === 'string' ? payload.upgradeUrl.trim() : '') ||
            (typeof payload.hotel?.upgradeUrl === 'string' ? payload.hotel.upgradeUrl.trim() : '');
        return { enabled, url: rawUrl };
    };

    const renderUpgradeSection = (payload, dictionary) => {
        const upgradeSection = document.getElementById('upgrade-card');
        if (!upgradeSection) {
            return;
        }

        const upgrade = resolveUpgradeConfig(payload);
        const isEnabled = upgrade.enabled || !!upgrade.url;
        const linkUrl = upgrade.url
            ? upgrade.url
            : (baseUpgradeUrl ? `${baseUpgradeUrl.replace(/\/$/, '')}/${manualId}` : '');

        if (!isEnabled || !linkUrl) {
            upgradeSection.classList.add('hidden');
            return;
        }

        upgradeSection.classList.remove('hidden');
        setText('upgrade-title', dictionary.upgradeCardTitle || translations.en.upgradeCardTitle);
        setText('upgrade-body', dictionary.upgradeBody || translations.en.upgradeBody);
        const link = document.getElementById('upgrade-link');
        if (link) {
            link.href = linkUrl;
        }
    };

    const renderSupport = (payload, ssidName, showPortal) => {
        setText('hotel-name', payload.hotel?.name, '');
        setText('hotel-ssid', ssidName || payload.hotel?.ssid, 'Hotel-Guest');
        setText('support-text', payload.hotel?.supportText, 'Need help? Contact reception.');
        const footerText = payload.hotel?.footerText || (payload.hotel?.name ? `© ${payload.hotel.name}` : '© Guest Wi-Fi');
        setText('footer-brand', footerText);

        const portalUrl = showPortal ? getPortalUrl(payload) : '';
        const portalHost = showPortal ? formatPortalHost(portalUrl) : '';
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

    const renderQrCodes = (payload, mode) => {
        const portalBase = getPortalUrl(payload).replace(/\/$/, '');
        const pmsSource = payload.options?.pms ?? payload.options?.roomSurname ?? {};
        const acSource = payload.options?.ac ?? payload.options?.accessCode ?? {};
        const room = typeof pmsSource === 'string' ? pmsSource : (pmsSource.room || pmsSource.roomNumber || '');
        const surname = typeof pmsSource === 'object' && pmsSource ? (pmsSource.surname || '') : '';
        const accessCode = typeof acSource === 'string'
            ? acSource
            : (acSource.code || acSource.value || acSource.accessCode || '');

        let targetUrl = '';
        let fallback = '';

        if (mode === 'ac') {
            targetUrl = payload.options?.accessCode?.url || payload.options?.ac?.url ||
                (accessCode ? `${portalBase}/access-code?code=${encodeURIComponent(accessCode)}` : '');
            fallback = accessCode;
        } else {
            targetUrl = payload.options?.roomSurname?.url || payload.options?.pms?.url ||
                (room || surname ? `${portalBase}/room-login?room=${encodeURIComponent(room)}&surname=${encodeURIComponent(surname)}` : '');
            fallback = `${room}-${surname}`.trim();
        }

        renderPseudoQr(document.getElementById('qr-auth'), targetUrl || fallback);
    };

    const applyLanguage = (lang, payload, mode, device, context) => {
        const dictionary = applyTranslations(lang);
        renderAuthCard(payload, mode, dictionary);
        renderUpgradeSection(payload, dictionary);
        renderStepsList(mode, device, lang, context);

        const title = document.querySelector('title');
        if (title) {
            title.textContent = page === 'upgrade' ? dictionary.upgradeTitle : dictionary.title;
        }

        return dictionary;
    };

    const renderViewer = (payload) => {
        const ssidCatalog = buildSsidCatalog(payload);
        const mode = resolveMode(payload, ssidCatalog);
        const ssidsForMode = ssidCatalog[mode] || [];
        const freeSsids = ssidCatalog.free || [];
        let currentSsid = ssidsForMode[0]?.name || payload.hotel?.ssid || 'Hotel-Guest';

        const portalUrl = getPortalUrl(payload);
        const portalHost = formatPortalHost(portalUrl);
        const portalTarget = portalHost || portalUrl;
        const showPortal = mode !== 'free' && !!portalTarget;

        const authCard = document.getElementById('auth-card');
        if (authCard) {
            if (mode === 'free') {
                authCard.classList.add('hidden');
            } else {
                authCard.classList.remove('hidden');
            }
        }

        renderSupport(payload, currentSsid, showPortal);
        renderFreeCard(payload, freeSsids);
        if (mode !== 'free') {
            renderQrCodes(payload, mode);
        }

        const langSelect = document.getElementById('langSelect');
        const stored = window.localStorage ? window.localStorage.getItem('manual_lang') : null;
        const initialLang = stored && translations[stored] ? stored : 'en';
        if (langSelect) {
            langSelect.value = initialLang;
        }

        let currentDevice = payload.device?.default || 'generic';
        const updateSteps = (device, dictionary = null) => {
            currentDevice = device;
            const activeLang = langSelect ? langSelect.value : initialLang;
            const dict = dictionary || translations[activeLang] || translations.en;
            const authLabel = resolveAuthLabel(dict, mode);
            renderStepsList(mode, device, activeLang, {
                ssid: currentSsid,
                portal: showPortal ? portalTarget : '',
                auth: authLabel,
            });
        };

        const updateSsid = (ssid) => {
            currentSsid = ssid || currentSsid;
            renderSupport(payload, currentSsid, showPortal);
            updateSteps(currentDevice);
        };

        renderDeviceButtons(payload, initialLang, updateSteps, currentDevice);
        renderSsidButtons(ssidsForMode, updateSsid, currentSsid);

        const dictionary = applyLanguage(initialLang, payload, mode, currentDevice, {
            ssid: currentSsid,
            portal: showPortal ? portalTarget : '',
            auth: resolveAuthLabel(translations[initialLang] || translations.en, mode),
        });
        updateSteps(currentDevice, dictionary);

        if (langSelect) {
            langSelect.addEventListener('change', () => {
                const lang = langSelect.value;
                if (window.localStorage) {
                    window.localStorage.setItem('manual_lang', lang);
                }
                const dict = applyLanguage(lang, payload, mode, currentDevice, {
                    ssid: currentSsid,
                    portal: showPortal ? portalTarget : '',
                    auth: resolveAuthLabel(translations[lang] || translations.en, mode),
                });
                renderDeviceButtons(payload, lang, updateSteps, currentDevice);
                updateSteps(currentDevice, dict);
            });
        }
    };

    const renderUpgrade = (payload) => {
        const ssidCatalog = buildSsidCatalog(payload);
        const mode = resolveMode(payload, ssidCatalog);
        const ssidName = ssidCatalog[mode]?.[0]?.name || payload.hotel?.ssid || 'Hotel-Guest';
        const portalUrl = getPortalUrl(payload);
        const portalHost = formatPortalHost(portalUrl);
        const portalTarget = portalHost || portalUrl;
        const showPortal = mode !== 'free' && !!portalTarget;

        renderSupport(payload, ssidName, showPortal);
        const upgrade = resolveUpgradeConfig(payload);
        if (!(upgrade.enabled || upgrade.url)) {
            hideUpgradeCard();
            showError();
            return;
        }

        const langSelect = document.getElementById('langSelect');
        const stored = window.localStorage ? window.localStorage.getItem('manual_lang') : null;
        const initialLang = stored && translations[stored] ? stored : 'en';
        if (langSelect) {
            langSelect.value = initialLang;
        }

        const applyUpgradeText = (lang) => {
            const dictionary = applyTranslations(lang);
            setText('upgrade-title', dictionary.upgradeCardTitle || translations.en.upgradeCardTitle);
            setText('upgrade-body', dictionary.upgradeBody || translations.en.upgradeBody);
            const title = document.querySelector('title');
            if (title) {
                title.textContent = dictionary.upgradeTitle || translations.en.upgradeTitle;
            }
        };

        applyUpgradeText(initialLang);

        if (langSelect) {
            langSelect.addEventListener('change', () => {
                const lang = langSelect.value;
                if (window.localStorage) {
                    window.localStorage.setItem('manual_lang', lang);
                }
                applyUpgradeText(lang);
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
                if (page === 'print') {
                    window.setTimeout(() => {
                        window.print();
                    }, 250);
                }
            }
        } catch (error) {
            showError();
        }
    };

    load();
})();
