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
    const i18nNode = document.getElementById('manual-i18n');
    let manualI18n = {};
    if (i18nNode) {
        try {
            const parsedI18n = JSON.parse(i18nNode.textContent || '{}');
            if (parsedI18n && typeof parsedI18n === 'object') {
                manualI18n = parsedI18n;
            }
        } catch (error) {
            manualI18n = {};
        }
    }

    const translations = manualI18n.translations && typeof manualI18n.translations === 'object'
        ? manualI18n.translations
        : {};
    const deviceNames = manualI18n.deviceNames && typeof manualI18n.deviceNames === 'object'
        ? manualI18n.deviceNames
        : {};
    const instructionSets = manualI18n.instructionSets && typeof manualI18n.instructionSets === 'object'
        ? manualI18n.instructionSets
        : {};
    const fallbackLang = Object.prototype.hasOwnProperty.call(translations, 'en')
        ? 'en'
        : (Object.keys(translations)[0] || 'en');
    const isSupportedLang = (lang) => Object.prototype.hasOwnProperty.call(translations, lang);

    const getDictionary = (lang) => translations[lang] || translations[fallbackLang] || {};

    const translate = (dictionary, key) => {
        if (!key) {
            return '';
        }

        if (dictionary && dictionary[key] !== undefined) {
            return dictionary[key];
        }

        const fallbackDictionary = translations[fallbackLang] || {};
        return fallbackDictionary[key] !== undefined ? fallbackDictionary[key] : key;
    };

    const persistLanguage = (lang) => {
        if (window.localStorage) {
            window.localStorage.setItem('manual_lang', lang);
        }

        document.cookie = `manual_lang=${encodeURIComponent(lang)}; path=/; max-age=31536000; samesite=lax`;
        document.documentElement.lang = lang;
    };

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
            ''
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

    const applyTranslations = (lang) => {
        const dictionary = getDictionary(lang);
        document.documentElement.lang = lang;
        document.querySelectorAll('[data-i18n]').forEach((node) => {
            const key = node.dataset.i18n;
            if (!key) {
                return;
            }
            node.textContent = translate(dictionary, key);
        });
        document.querySelectorAll('[data-i18n-html]').forEach((node) => {
            const key = node.dataset.i18nHtml;
            if (!key) {
                return;
            }
            node.innerHTML = translate(dictionary, key);
        });
        return dictionary;
    };

    const resolveAuthLabel = (dictionary, mode) => {
        if (normalizeUsage(mode) === 'ac') {
            return translate(dictionary, 'authAccess');
        }
        return translate(dictionary, 'authRoom');
    };

    const formatStep = (template, context) => {
        return template
            .replace('{ssid}', context.ssid || '')
            .replace('{portal}', context.portal || '')
            .replace('{auth}', context.auth || '');
    };

    const resolveInstructionSteps = (lang, mode, device, context) => {
        const set = instructionSets[lang] || instructionSets[fallbackLang] || { portal: {}, free: {} };
        const group = mode === 'free' ? set.free : set.portal;
        const steps = Array.isArray(group[device]) ? group[device] : (group.generic || []);
        return steps
            .filter((step) => (context.portal || !step.includes('{portal}')))
            .map((step) => formatStep(step, context));
    };

    const renderStepsList = (mode, device, lang, context, dictionary) => {
        const list = document.getElementById('steps-list');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        const steps = resolveInstructionSteps(lang, mode, device, context);
        if (!steps.length) {
            const li = document.createElement('li');
            li.textContent = translate(dictionary, 'noStepsAvailable');
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
        const labels = deviceNames[lang] || deviceNames[fallbackLang] || {};

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
            if (list[0] && list[0].name !== preferredSsid) {
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

        if (initial && initial !== preferredSsid) {
            setActive(initial);
        }
    };

    const buildLegacyPmsFields = (payload) => {
        const source = payload.options?.pms ?? payload.options?.roomSurname ?? {};
        if (!source || typeof source !== 'object') {
            return [];
        }

        const orderedFields = Array.isArray(payload.options?.pms?.fields) ? payload.options.pms.fields : [];
        const fields = [];
        const seen = new Set();

        orderedFields.forEach((fieldName) => {
            if (typeof fieldName !== 'string' || !fieldName) {
                return;
            }

            const value = source[fieldName] ?? (fieldName === 'roomNumber' ? source.room : '');
            if (!value) {
                return;
            }

            seen.add(fieldName);
            fields.push({ key: fieldName, value });
        });

        Object.entries(source).forEach(([fieldName, value]) => {
            if (seen.has(fieldName) || ['provider', 'fields', 'url'].includes(fieldName) || !value) {
                return;
            }

            fields.push({ key: fieldName, value });
        });

        return fields;
    };

    const buildLegacyVariants = (payload) => {
        const catalog = buildSsidCatalog(payload);
        const preferredUsage = resolveMode(payload, catalog);
        const authVariants = [];
        const pmsFields = buildLegacyPmsFields(payload);
        const accessCode = payload.options?.accessCode?.code || payload.options?.ac?.code || '';
        const preferredFirst = preferredUsage === 'ac' ? ['accessCode', 'roomSurname'] : ['roomSurname', 'accessCode'];

        preferredFirst.forEach((mode, index) => {
            if (mode === 'roomSurname' && pmsFields.length) {
                authVariants.push({
                    id: 'roomSurname',
                    type: 'auth',
                    mode: 'roomSurname',
                    titleKey: 'roomSurname',
                    optionNumber: authVariants.length + 1,
                    usage: 'pms',
                    ssids: catalog.pms.map((item) => item.name),
                    fields: pmsFields,
                    url: payload.options?.roomSurname?.url || payload.options?.pms?.url || null,
                    qrDataUrl: null,
                    isPrimary: authVariants.length === 0,
                });
            }

            if (mode === 'accessCode' && accessCode) {
                authVariants.push({
                    id: 'accessCode',
                    type: 'auth',
                    mode: 'accessCode',
                    titleKey: 'accessCode',
                    optionNumber: authVariants.length + 1,
                    usage: 'ac',
                    ssids: catalog.ac.map((item) => item.name),
                    fields: [{ key: 'accessCode', value: accessCode }],
                    url: payload.options?.accessCode?.url || payload.options?.ac?.url || null,
                    qrDataUrl: null,
                    isPrimary: authVariants.length === 0,
                });
            }
        });

        const freeEnabled = catalog.free.length > 0 || payload.options?.freeAccess === true || payload.options?.freeAccess?.enabled === true;
        if (freeEnabled) {
            authVariants.push({
                id: 'freeAccess',
                type: 'free',
                mode: 'freeAccess',
                titleKey: 'freeAccess',
                optionNumber: 3,
                usage: 'free',
                ssids: catalog.free.map((item) => item.name),
                fields: [],
                url: null,
                qrDataUrl: null,
            });
        }

        return authVariants;
    };

    const resolveManualVariants = (payload) => {
        const explicit = Array.isArray(payload.manual?.variants)
            ? payload.manual.variants.filter((variant) => variant && typeof variant === 'object')
            : [];

        return explicit.length ? explicit : buildLegacyVariants(payload);
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

    const createAuthFieldsGrid = (fields, dictionary) => {
        const container = document.createElement('div');
        container.className = 'dataGrid';
        if (!fields.length) {
            fields = [{ key: translate(dictionary, 'accessCodeLabel') || 'Detail', value: '—' }];
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

        return container;
    };

    const createAuthCard = (variant, dictionary, isActive, onSelect) => {
        const article = document.createElement('article');
        article.className = `card ${isActive ? 'card--active ' : ''}${onSelect ? 'card--selectable' : ''}`.trim();
        article.dataset.variantId = variant.id;

        const bar = document.createElement('div');
        bar.className = 'card__bar';
        const left = document.createElement('div');
        left.className = 'card__left';
        const option = document.createElement('span');
        option.className = 'opt';
        option.textContent = translate(dictionary, `option${variant.optionNumber}`);
        const title = document.createElement('span');
        title.className = 'opt__title';
        title.textContent = translate(dictionary, variant.titleKey) || variant.id;
        left.appendChild(option);
        left.appendChild(title);
        bar.appendChild(left);
        article.appendChild(bar);

        const bodyEl = document.createElement('div');
        bodyEl.className = 'card__body';

        if (variant.qrDataUrl) {
            const qrCol = document.createElement('div');
            qrCol.className = 'qrCol';
            const qr = document.createElement('img');
            qr.className = 'qr';
            qr.src = variant.qrDataUrl;
            qr.alt = title.textContent;
            qrCol.appendChild(qr);
            const hint = document.createElement('div');
            hint.className = 'qrHint';
            hint.textContent = translate(dictionary, 'scanCamera');
            qrCol.appendChild(hint);
            bodyEl.appendChild(qrCol);
        } else {
            bodyEl.classList.add('card__body--single');
        }

        const dataCol = document.createElement('div');
        dataCol.className = 'dataCol';
        dataCol.appendChild(createAuthFieldsGrid(Array.isArray(variant.fields) ? variant.fields : [], dictionary));
        const cta = document.createElement('div');
        cta.className = 'cta';
        cta.innerHTML = translate(dictionary, 'tapConnect');
        dataCol.appendChild(cta);
        bodyEl.appendChild(dataCol);
        article.appendChild(bodyEl);

        if (onSelect) {
            article.tabIndex = 0;
            article.addEventListener('click', () => onSelect(variant.id));
            article.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onSelect(variant.id);
                }
            });
        }

        return article;
    };

    const renderAuthVariants = (variants, dictionary, activeVariantId, onSelect) => {
        const container = document.getElementById('auth-variants');
        if (!container) {
            return;
        }

        container.innerHTML = '';
        variants.forEach((variant) => {
            container.appendChild(createAuthCard(variant, dictionary, variant.id === activeVariantId, onSelect));
        });
    };

    const renderFreeCard = (variant) => {
        const card = document.getElementById('free-card');
        if (!card) {
            return;
        }

        if (!variant) {
            card.classList.add('hidden');
            return;
        }

        card.classList.remove('hidden');
        const ssids = Array.isArray(variant.ssids) ? variant.ssids : [];
        const name = ssids.length > 1 ? ssids.join(', ') : (ssids[0] || '');
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
        setText('upgrade-title', translate(dictionary, 'upgradeCardTitle'));
        setText('upgrade-body', translate(dictionary, 'upgradeBody'));
        const link = document.getElementById('upgrade-link');
        if (link) {
            link.href = linkUrl;
        }
    };

    const renderSupport = (payload, ssidName, showPortal, dictionary, portalMissing = false) => {
        setText('hotel-name', payload.hotel?.name, '');
        setText('hotel-ssid', ssidName || payload.hotel?.ssid, 'Hotel-Guest');
        setText('support-text', payload.hotel?.supportText, translate(dictionary, 'supportFallback'));
        const footerText = payload.hotel?.footerText || (payload.hotel?.name ? `© ${payload.hotel.name}` : translate(dictionary, 'footerFallback'));
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
        const portalWarningEl = document.getElementById('portal-warning');
        if (portalWarningEl) {
            portalWarningEl.textContent = portalMissing
                ? translate(dictionary, 'portalMissing')
                : '';
            portalWarningEl.classList.toggle('hidden', !portalMissing);
        }

        const logoUrl = payload.hotel?.logoUrl || payload.branding?.logoUrl || '';
        const logo = document.getElementById('hotel-logo');
        if (logo && logoUrl) {
            logo.src = logoUrl;
            logo.alt = payload.hotel?.name || translate(dictionary, 'hotelLogoAlt');
            logo.classList.remove('hidden');
        }
    };

    const applyPageTitle = (dictionary) => {
        const title = document.querySelector('title');
        if (title) {
            title.textContent = page === 'upgrade'
                ? translate(dictionary, 'upgradeTitle')
                : translate(dictionary, 'title');
        }
    };

    const renderViewer = (payload) => {
        const variants = resolveManualVariants(payload);
        const authVariants = variants.filter((variant) => variant.type === 'auth');
        const freeVariant = variants.find((variant) => variant.type === 'free') || null;

        const langSelect = document.getElementById('langSelect');
        const stored = window.localStorage ? window.localStorage.getItem('manual_lang') : null;
        const initialLang = isSupportedLang(body.dataset.initialLang || '') ? body.dataset.initialLang : (stored && isSupportedLang(stored) ? stored : fallbackLang);
        if (langSelect) {
            langSelect.value = initialLang;
        }

        persistLanguage(initialLang);

        let currentDevice = payload.device?.default || 'generic';
        let activeVariantId = payload.manual?.activeVariantId || authVariants[0]?.id || freeVariant?.id || null;
        let currentSsid = '';

        const getActiveVariant = () => authVariants.find((variant) => variant.id === activeVariantId) || authVariants[0] || freeVariant;

        const syncViewer = (dictionary = null) => {
            const activeLang = langSelect ? langSelect.value : initialLang;
            const dict = dictionary || getDictionary(activeLang);
            const activeVariant = getActiveVariant();
            const activeUsage = activeVariant?.type === 'free'
                ? 'free'
                : (normalizeUsage(activeVariant?.usage || activeVariant?.mode || activeVariant?.id) || 'pms');
            const ssids = Array.isArray(activeVariant?.ssids)
                ? activeVariant.ssids.filter((ssid) => typeof ssid === 'string' && ssid.trim() !== '')
                : [];

            if (!currentSsid || !ssids.includes(currentSsid)) {
                currentSsid = ssids[0] || payload.hotel?.ssid || 'Hotel-Guest';
            }

            const portalUrl = getPortalUrl(payload);
            const portalHost = formatPortalHost(portalUrl);
            const portalTarget = portalHost || portalUrl;
            const portalMissing = activeUsage !== 'free' && !portalTarget;
            const showPortal = activeUsage !== 'free' && !!portalTarget;

            renderSupport(payload, currentSsid, showPortal, dict, portalMissing);
            renderAuthVariants(authVariants, dict, activeVariantId, authVariants.length > 1 ? (variantId) => {
                activeVariantId = variantId;
                syncViewer(dict);
            } : null);
            renderFreeCard(freeVariant);
            renderSsidButtons(ssids.map((name) => ({ name })), (ssid) => {
                currentSsid = ssid || currentSsid;
                syncViewer(dict);
            }, currentSsid);
            renderUpgradeSection(payload, dict);
            renderStepsList(activeUsage, currentDevice, activeLang, {
                ssid: currentSsid,
                portal: showPortal ? portalTarget : '',
                auth: resolveAuthLabel(dict, activeVariant?.mode || activeVariant?.usage),
            }, dict);
            applyPageTitle(dict);
        };

        renderDeviceButtons(payload, initialLang, (device) => {
            currentDevice = device;
            syncViewer();
        }, currentDevice);

        const dictionary = applyTranslations(initialLang);
        syncViewer(dictionary);

        if (langSelect) {
            langSelect.addEventListener('change', () => {
                const lang = langSelect.value;
                persistLanguage(lang);
                const dict = applyTranslations(lang);
                renderDeviceButtons(payload, lang, (device) => {
                    currentDevice = device;
                    syncViewer();
                }, currentDevice);
                syncViewer(dict);
            });
        }
    };

    const renderUpgrade = (payload) => {
        const variants = resolveManualVariants(payload);
        const primaryVariant = variants.find((variant) => variant.type === 'auth' && variant.isPrimary) ||
            variants.find((variant) => variant.type === 'auth') ||
            variants.find((variant) => variant.type === 'free') ||
            null;
        const ssidName = Array.isArray(primaryVariant?.ssids) && primaryVariant.ssids[0]
            ? primaryVariant.ssids[0]
            : (payload.hotel?.ssid || 'Hotel-Guest');
        const portalUrl = getPortalUrl(payload);
        const portalHost = formatPortalHost(portalUrl);
        const portalTarget = portalHost || portalUrl;
        const variantUsage = primaryVariant?.type === 'free'
            ? 'free'
            : (normalizeUsage(primaryVariant?.usage || primaryVariant?.mode || primaryVariant?.id) || 'pms');
        const portalMissing = variantUsage !== 'free' && !portalTarget;
        const showPortal = variantUsage !== 'free' && !!portalTarget;
        const upgrade = resolveUpgradeConfig(payload);
        if (!(upgrade.enabled || upgrade.url)) {
            hideUpgradeCard();
            showError();
            return;
        }

        const langSelect = document.getElementById('langSelect');
        const stored = window.localStorage ? window.localStorage.getItem('manual_lang') : null;
        const initialLang = isSupportedLang(body.dataset.initialLang || '') ? body.dataset.initialLang : (stored && isSupportedLang(stored) ? stored : fallbackLang);
        if (langSelect) {
            langSelect.value = initialLang;
        }

        persistLanguage(initialLang);

        const applyUpgradeText = (lang) => {
            const dictionary = applyTranslations(lang);
            renderSupport(payload, ssidName, showPortal, dictionary, portalMissing);
            setText('upgrade-title', translate(dictionary, 'upgradeCardTitle'));
            setText('upgrade-body', translate(dictionary, 'upgradeBody'));
            const title = document.querySelector('title');
            if (title) {
                title.textContent = translate(dictionary, 'upgradeTitle');
            }
        };

        applyUpgradeText(initialLang);

        if (langSelect) {
            langSelect.addEventListener('change', () => {
                const lang = langSelect.value;
                persistLanguage(lang);
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
