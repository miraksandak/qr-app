(() => {
    const form = document.getElementById('manual-form');
    const openForm = document.getElementById('open-form');
    const resultPanel = document.getElementById('result-panel');
    const errorBox = document.getElementById('form-error');
    const resetButton = document.getElementById('resetForm');

    if (!form) {
        return;
    }

    const byId = (id) => document.getElementById(id);

    const parseLines = (text) => {
        if (!text) {
            return [];
        }
        return text
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter((line) => line.length > 0);
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

    const buildPayload = () => {
        const availableDevices = Array.from(document.querySelectorAll('input[name="deviceAvailable"]:checked')).map(
            (input) => input.value
        );
        if (availableDevices.length === 0) {
            availableDevices.push('generic');
        }

        const payload = {
            hotel: {
                name: byId('hotelName').value.trim(),
                ssid: byId('hotelSsid').value.trim(),
                supportText: byId('hotelSupport').value.trim(),
                logoUrl: byId('hotelLogo').value.trim(),
            },
            options: {
                roomSurname: {
                    room: byId('roomNumber').value.trim(),
                    surname: byId('surname').value.trim(),
                },
                accessCode: {
                    code: byId('accessCode').value.trim(),
                },
                freeAccess: {},
            },
            device: {
                default: byId('deviceDefault').value,
                available: availableDevices,
            },
            steps: {
                android: parseLines(byId('stepsAndroid').value),
                ios: parseLines(byId('stepsIos').value),
                generic: parseLines(byId('stepsGeneric').value),
            },
        };

        const portalUrl = byId('portalUrl').value.trim();
        if (portalUrl) {
            payload.portal = { url: portalUrl };
        }

        const upgradeEnabled = byId('upgradeEnabled').checked;
        payload.upgrade = {
            enabled: upgradeEnabled,
            title: byId('upgradeTitle').value.trim(),
            body: byId('upgradeBody').value.trim(),
        };

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
        const upgrade = byId('result-upgrade');

        viewer.textContent = data.viewerUrl || '';
        viewer.href = data.viewerUrl || '#';
        json.textContent = data.jsonUrl || '';
        json.href = data.jsonUrl || '#';

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

    if (resetButton) {
        resetButton.addEventListener('click', () => {
            form.reset();
            setError('');
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
})();
