(() => {
    const form = document.getElementById('manual-form');
    const openForm = document.getElementById('open-form');
    const resultPanel = document.getElementById('result-panel');
    const errorBox = document.getElementById('form-error');
    const hotelStatusBox = document.getElementById('hotel-status');
    const resetButton = document.getElementById('resetForm');
    const loadHotelConfigButton = document.getElementById('loadHotelConfig');
    const saveHotelConfigButton = document.getElementById('saveHotelConfig');
    const advancedHotelSettings = document.getElementById('advancedHotelSettings');

    if (!form) {
        return;
    }

    const byId = (id) => document.getElementById(id);
    const availableDeviceInputs = Array.from(document.querySelectorAll('input[name="deviceAvailable"]'));

    const parseCommaList = (text) => {
        if (!text) {
            return [];
        }
        return text
            .split(/[\r\n,]+/)
            .map((entry) => entry.trim())
            .filter((entry) => entry.length > 0);
    };

    const setError = (message) => {
        if (!errorBox) {
            return;
        }
        if (!message) {
            errorBox.classList.add('hidden');
            errorBox.textContent = '';
            return;
        }
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    };

    const setNotice = (box, message, tone) => {
        if (!box) {
            return;
        }

        box.classList.remove('hidden', 'success', 'error');

        if (!message) {
            box.textContent = '';
            box.classList.add('hidden');
            return;
        }

        box.textContent = message;
        box.classList.add(tone || 'success');
    };

    const collectAvailableDevices = () => {
        const devices = availableDeviceInputs
            .filter((input) => input.checked)
            .map((input) => input.value);

        return devices.length > 0 ? devices : ['generic'];
    };

    const syncDeviceControls = () => {
        const defaultDevice = byId('deviceDefault');
        if (!defaultDevice) {
            return;
        }

        let availableDevices = availableDeviceInputs
            .filter((input) => input.checked)
            .map((input) => input.value);

        if (availableDevices.length === 0) {
            const genericInput = availableDeviceInputs.find((input) => input.value === 'generic');
            if (genericInput) {
                genericInput.checked = true;
            }
            availableDevices = ['generic'];
        }

        if (!availableDevices.includes(defaultDevice.value)) {
            defaultDevice.value = availableDevices[0];
        }
    };

    const syncAuthModeFields = () => {
        const mode = byId('authMode').value;
        const roomSurnameFields = byId('roomSurnameFields');
        const accessCodeFields = byId('accessCodeFields');
        const roomNumber = byId('roomNumber');
        const surname = byId('surname');
        const accessCode = byId('accessCode');

        if (roomSurnameFields) {
            roomSurnameFields.classList.toggle('hidden', mode !== 'roomSurname');
        }
        if (accessCodeFields) {
            accessCodeFields.classList.toggle('hidden', mode !== 'accessCode');
        }

        if (roomNumber) {
            roomNumber.disabled = mode !== 'roomSurname';
        }
        if (surname) {
            surname.disabled = mode !== 'roomSurname';
        }
        if (accessCode) {
            accessCode.disabled = mode !== 'accessCode';
        }
    };

    const syncAdvancedHotelSettings = () => {
        if (!advancedHotelSettings) {
            return;
        }

        const hasAdvancedValue = ['portalUrl', 'proxyApiBaseUrl', 'datacenterId']
            .map((id) => byId(id))
            .some((input) => input && input.value.trim() !== '');

        advancedHotelSettings.open = hasAdvancedValue;
    };

    const buildSsids = () => [
        ...parseCommaList(byId('ssidPms').value).map((name) => ({ name, usage: 'pms' })),
        ...parseCommaList(byId('ssidAccess').value).map((name) => ({ name, usage: 'ac' })),
        ...parseCommaList(byId('ssidFree').value).map((name) => ({ name, usage: 'free' })),
    ];

    const buildHotelConfigurationPayload = () => {
        const upgradeUrl = byId('upgradeUrl').value.trim();
        const upgradeEnabled = byId('upgradeEnabled').checked || !!upgradeUrl;

        return {
            name: byId('hotelName').value.trim() || null,
            configuration: {
                supportText: byId('hotelSupport').value.trim() || null,
                footerText: byId('hotelFooter').value.trim() || null,
                logoUrl: byId('hotelLogo').value.trim() || null,
                portalUrl: byId('portalUrl').value.trim() || null,
                proxyApiBaseUrl: byId('proxyApiBaseUrl').value.trim() || null,
                datacenterId: byId('datacenterId').value.trim() || null,
                primaryAuthMode: byId('authMode').value,
                device: {
                    default: byId('deviceDefault').value,
                    available: collectAvailableDevices(),
                },
                ssids: buildSsids(),
                upgrade: {
                    enabled: upgradeEnabled,
                    url: upgradeUrl || null,
                },
            },
        };
    };

    const applyHotelConfiguration = (data) => {
        const hotel = data.hotel || {};
        const configuration = data.configuration || {};
        const device = configuration.device || {};
        const upgrade = configuration.upgrade || {};
        const ssids = Array.isArray(configuration.ssids) ? configuration.ssids : [];
        const availableDevices = Array.isArray(device.available) && device.available.length > 0
            ? device.available
            : ['android', 'ios', 'generic'];

        byId('hotelExternalId').value = hotel.externalHotelId || byId('hotelExternalId').value;
        byId('hotelName').value = hotel.name || '';
        byId('hotelSupport').value = configuration.supportText || '';
        byId('hotelFooter').value = configuration.footerText || '';
        byId('hotelLogo').value = configuration.logoUrl || '';
        byId('portalUrl').value = configuration.portalUrl || '';
        byId('proxyApiBaseUrl').value = configuration.proxyApiBaseUrl || '';
        byId('datacenterId').value = configuration.datacenterId || '';
        byId('authMode').value = configuration.primaryAuthMode || 'roomSurname';
        byId('upgradeEnabled').checked = !!upgrade.enabled;
        byId('upgradeUrl').value = upgrade.url || '';
        byId('deviceDefault').value = device.default && availableDevices.includes(device.default) ? device.default : availableDevices[0];

        availableDeviceInputs.forEach((input) => {
            input.checked = availableDevices.includes(input.value);
        });

        byId('ssidPms').value = ssids
            .filter((ssid) => ssid.usage === 'pms')
            .map((ssid) => ssid.name)
            .join(', ');
        byId('ssidAccess').value = ssids
            .filter((ssid) => ssid.usage === 'ac')
            .map((ssid) => ssid.name)
            .join(', ');
        byId('ssidFree').value = ssids
            .filter((ssid) => ssid.usage === 'free')
            .map((ssid) => ssid.name)
            .join(', ');

        syncAdvancedHotelSettings();
        syncDeviceControls();
        syncAuthModeFields();
    };

    const buildPayload = () => {
        const availableDevices = collectAvailableDevices();
        const ssids = buildSsids();
        const authMode = byId('authMode').value;

        const payload = {
            hotel: {
                name: byId('hotelName').value.trim(),
                supportText: byId('hotelSupport').value.trim(),
                logoUrl: byId('hotelLogo').value.trim(),
            },
            options: {
                mode: authMode,
                freeAccess: {
                    enabled: byId('freeEnabled').checked,
                },
            },
            device: {
                default: byId('deviceDefault').value,
                available: availableDevices,
            },
        };

        const hotelFooter = byId('hotelFooter').value.trim();
        if (hotelFooter) {
            payload.hotel.footerText = hotelFooter;
        }

        if (ssids.length > 0) {
            payload.hotel.ssids = ssids;
        }

        const portalUrl = byId('portalUrl').value.trim();
        if (portalUrl) {
            payload.hotel.portalUrl = portalUrl;
        }

        const upgradeUrl = byId('upgradeUrl').value.trim();
        const upgradeEnabled = byId('upgradeEnabled').checked || !!upgradeUrl;
        if (upgradeEnabled || upgradeUrl) {
            payload.hotel.upgrade = {
                enabled: upgradeEnabled,
                url: upgradeUrl || undefined,
            };
        }

        if (authMode === 'roomSurname') {
            payload.options.roomSurname = {
                room: byId('roomNumber').value.trim(),
                surname: byId('surname').value.trim(),
            };
        }

        if (authMode === 'accessCode') {
            payload.options.accessCode = {
                code: byId('accessCode').value.trim(),
            };
        }

        const validUntil = byId('validUntil').value.trim();
        if (validUntil) {
            payload.validUntil = validUntil;
        }

        return payload;
    };

    const setResult = (data) => {
        if (!resultPanel) {
            return;
        }
        const viewer = byId('result-viewer');
        const json = byId('result-json');
        const print = byId('result-print');
        const upgrade = byId('result-upgrade');

        viewer.textContent = data.viewerUrl || '';
        viewer.href = data.viewerUrl || '#';
        json.textContent = data.jsonUrl || '';
        json.href = data.jsonUrl || '#';
        if (print) {
            const finalPrint = data.printUrl ||
                (data.viewerUrl ? data.viewerUrl.replace(/\/$/, '').replace(/\/[A-Z0-9]{5}$/i, `/print/${data.id}`) : '');
            print.textContent = finalPrint;
            print.href = finalPrint || '#';
        }

        const finalUpgrade = data.viewerUrl
            ? data.viewerUrl.replace(/\/$/, '').replace(/\/[A-Z0-9]{5}$/i, `/upgrade/${data.id}`)
            : '';

        upgrade.textContent = finalUpgrade;
        upgrade.href = finalUpgrade || '#';

        resultPanel.classList.remove('hidden');
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        setError('');

        const apiKey = byId('apiKey').value.trim();
        if (!apiKey) {
            setError('API key is required.');
            return;
        }

        const payload = buildPayload();

        try {
            const response = await fetch('/api/manual', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-API-Key': apiKey,
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json();
            if (!response.ok) {
                setError(data.error || 'Request failed');
                return;
            }

            setResult(data);
        } catch (error) {
            setError('Unable to reach the API.');
        }
    });

    if (saveHotelConfigButton) {
        saveHotelConfigButton.addEventListener('click', async () => {
            setNotice(hotelStatusBox, '');

            const apiKey = byId('apiKey').value.trim();
            if (!apiKey) {
                setNotice(hotelStatusBox, 'API key is required to save hotel configuration.', 'error');
                return;
            }

            const externalHotelId = byId('hotelExternalId').value.trim();
            if (!externalHotelId) {
                setNotice(hotelStatusBox, 'External Hotel ID is required to save hotel configuration.', 'error');
                return;
            }

            try {
                const response = await fetch(`/api/hotels/${encodeURIComponent(externalHotelId)}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-Key': apiKey,
                    },
                    body: JSON.stringify(buildHotelConfigurationPayload()),
                });

                const data = await response.json();
                if (!response.ok) {
                    setNotice(hotelStatusBox, data.error || 'Unable to save hotel configuration.', 'error');
                    return;
                }

                applyHotelConfiguration(data);
                setNotice(hotelStatusBox, 'Hotel configuration saved.', 'success');
            } catch (error) {
                setNotice(hotelStatusBox, 'Unable to reach the hotel configuration API.', 'error');
            }
        });
    }

    if (loadHotelConfigButton) {
        loadHotelConfigButton.addEventListener('click', async () => {
            setNotice(hotelStatusBox, '');

            const apiKey = byId('apiKey').value.trim();
            if (!apiKey) {
                setNotice(hotelStatusBox, 'API key is required to load hotel configuration.', 'error');
                return;
            }

            const externalHotelId = byId('hotelExternalId').value.trim();
            if (!externalHotelId) {
                setNotice(hotelStatusBox, 'External Hotel ID is required to load hotel configuration.', 'error');
                return;
            }

            try {
                const response = await fetch(`/api/hotels/${encodeURIComponent(externalHotelId)}`, {
                    method: 'GET',
                    headers: {
                        'X-API-Key': apiKey,
                    },
                });

                const data = await response.json();
                if (!response.ok) {
                    setNotice(hotelStatusBox, data.error || 'Unable to load hotel configuration.', 'error');
                    return;
                }

                applyHotelConfiguration(data);
                setNotice(hotelStatusBox, 'Hotel configuration loaded.', 'success');
            } catch (error) {
                setNotice(hotelStatusBox, 'Unable to reach the hotel configuration API.', 'error');
            }
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', () => {
            form.reset();
            setError('');
            setNotice(hotelStatusBox, '');
            syncAdvancedHotelSettings();
            syncDeviceControls();
            syncAuthModeFields();
            if (resultPanel) {
                resultPanel.classList.add('hidden');
            }
        });
    }

    if (openForm) {
        openForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const id = byId('open-id').value.trim().toUpperCase();
            if (id.length === 5) {
                window.location.href = `/${id}`;
            }
        });
    }

    byId('authMode').addEventListener('change', syncAuthModeFields);
    byId('deviceDefault').addEventListener('change', () => {
        const selectedValue = byId('deviceDefault').value;
        const matchingInput = availableDeviceInputs.find((input) => input.value === selectedValue);
        if (matchingInput) {
            matchingInput.checked = true;
        }
        syncDeviceControls();
    });
    availableDeviceInputs.forEach((input) => {
        input.addEventListener('change', syncDeviceControls);
    });

    ['portalUrl', 'proxyApiBaseUrl', 'datacenterId'].forEach((id) => {
        const input = byId(id);
        if (input) {
            input.addEventListener('input', syncAdvancedHotelSettings);
        }
    });

    syncAdvancedHotelSettings();
    syncDeviceControls();
    syncAuthModeFields();
})();
