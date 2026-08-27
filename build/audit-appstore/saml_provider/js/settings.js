/* global OCP, OC, t */
(function () {
    'use strict';

    function nameIdFormats() {
        var m = {};
        m['urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'] = t('saml_provider', 'E-mail address of the user (most common)');
        m['urn:oasis:names:tc:SAML:2.0:nameid-format:persistent']   = t('saml_provider', 'Anonymous permanent ID (privacy-friendly)');
        m['urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified']  = t('saml_provider', 'Nextcloud username');
        return m;
    }

    var state = {
        idp: OCP.InitialState.loadState('saml_provider', 'idp'),
        sps: OCP.InitialState.loadState('saml_provider', 'serviceProviders'),
    };

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { node.textContent = attrs[k]; }
            else if (k === 'value') { node.value = attrs[k]; }
            else if (k === 'checked') { node.checked = !!attrs[k]; }
            else { node.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { node.appendChild(c); });
        return node;
    }

    function notify(msg) { OC.Notification.showTemporary(msg); }

    function copyField(label, value, hint) {
        var input = el('input', { type: 'text', readonly: 'readonly', value: value, 'class': 'saml-provider-copy' });
        input.addEventListener('click', function () {
            input.select();
            if (navigator.clipboard) { navigator.clipboard.writeText(input.value); notify(t('saml_provider', 'Copied')); }
        });
        var wrap = el('p', {}, [el('label', { text: label }), el('br'), input]);
        if (hint) { wrap.appendChild(el('br')); wrap.appendChild(el('small', { 'class': 'saml-provider-note', text: hint })); }
        return wrap;
    }

    function request(method, url, data) {
        return fetch(OC.generateUrl(url), {
            method: method,
            headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
            body: data ? JSON.stringify(data) : undefined,
        }).then(function (r) {
            if (r.status === 204) { return null; }
            return r.text().then(function (text) {
                var body;
                try { body = JSON.parse(text); }
                catch (e) {
                    throw new Error(t('saml_provider', 'Server error (status {status}). See the Nextcloud log for details.', { status: r.status }));
                }
                if (!r.ok) { throw new Error(body && body.error ? body.error : t('saml_provider', 'Request failed (status {status})', { status: r.status })); }
                return body;
            });
        });
    }

    // ------------------------------------------------------------------
    // Intro / help
    // ------------------------------------------------------------------

    function renderIntro(root) {
        var box = el('div', { 'class': 'section saml-provider-intro' });
        box.appendChild(el('strong', { text: t('saml_provider', 'What does this app do?') }));
        box.appendChild(el('p', { text: t('saml_provider', 'It lets people log in to other services (like Kimai, GitLab or Grafana) with their Nextcloud account. Nextcloud acts as the "identity provider" (IdP) - the central place that checks passwords. Each external service is a "service provider" (SP) that trusts Nextcloud to confirm who someone is.') }));
        box.appendChild(el('strong', { text: t('saml_provider', 'Setup in 3 steps:') }));
        var ol = el('ol', { 'class': 'saml-provider-flow' }, [
            el('li', { text: t('saml_provider', '1. Click "Generate certificate" below. This is Nextclouds signing key - it proves to services that a login really came from your Nextcloud.') }),
            el('li', { text: t('saml_provider', '2. Add the service: enter its name, its "Entity ID" and its "ACS URL" (the services login callback address - you find both in the services SAML settings).') }),
            el('li', { text: t('saml_provider', '3. Tell the service about Nextcloud: paste the endpoints shown below ("Entity ID", "SSO URL" and the certificate) into the services SAML configuration. Done.') }),
        ]);
        box.appendChild(ol);
        root.appendChild(box);
    }

    // ------------------------------------------------------------------
    // IdP section
    // ------------------------------------------------------------------

    function renderIdp(root) {
        var section = el('div', { 'class': 'section' });
        section.appendChild(el('h3', { text: t('saml_provider', 'Your Nextcloud as identity provider') }));
        section.appendChild(el('p', { 'class': 'saml-provider-note', text: t('saml_provider',
            'Copy these values into the SAML settings of every service you connect (step 3 above).') }));
        section.appendChild(copyField(t('saml_provider', 'Entity ID (= your Nextclouds name as IdP) — also serves as metadata URL'), state.idp.entityId,
            t('saml_provider', 'Many services can import everything automatically from this URL.')));
        section.appendChild(copyField(t('saml_provider', 'SSO URL (= login endpoint)'), state.idp.ssoUrl,
            t('saml_provider', 'Where the service sends users to log in.')));
        section.appendChild(copyField(t('saml_provider', 'SLO URL (= logout endpoint, optional)'), state.idp.sloUrl));
        root.appendChild(section);
    }

    // ------------------------------------------------------------------
    // Certificate
    // ------------------------------------------------------------------

    function renderCert(root) {
        var section = el('div', { 'class': 'section' });
        section.appendChild(el('h3', { text: t('saml_provider', 'Signing certificate') }));
        section.appendChild(el('p', { 'class': 'saml-provider-note', text: t('saml_provider', 'Nextcloud signs every login confirmation with this certificate so services can verify it is genuine. Copy the single-line version into services that ask for an "x509 certificate" (e.g. Kimai).') }));

        if (state.idp.hasCertificate) {
            if (state.idp.certificateSingleLine) {
                section.appendChild(copyField(t('saml_provider', 'Certificate (single line - for services like Kimai)'), state.idp.certificateSingleLine));
            }
            section.appendChild(copyField(t('saml_provider', 'Certificate (PEM format, multi-line)'), state.idp.certificate));
        } else {
            section.appendChild(el('p', { 'class': 'saml-provider-warning', text: t('saml_provider',
                'No certificate generated yet - SAML logins will not work until you click the button below.') }));
        }
        var genBtn = el('button', { 'class': 'button' + (state.idp.hasCertificate ? '' : ' primary'), text: state.idp.hasCertificate
            ? t('saml_provider', 'Regenerate certificate (all connected services must be updated afterwards!)')
            : t('saml_provider', 'Generate certificate') });
        genBtn.addEventListener('click', function () {
            if (state.idp.hasCertificate && !window.confirm(t('saml_provider',
                'Really regenerate? All services must then be given the new certificate.'))) { return; }
            request('POST', '/apps/saml_provider/settings/certificate', {}).then(function (data) {
                state.idp.hasCertificate = true;
                state.idp.certificate = data.certificate;
                state.idp.certificateSingleLine = data.certificate.replace(/-----(BEGIN|END) CERTIFICATE-----/g, '').replace(/\s+/g, '');
                root.innerHTML = '';
                render(root);
                notify(t('saml_provider', 'Certificate generated'));
            }).catch(function (e) { notify(e.message); });
        });
        section.appendChild(genBtn);
        root.appendChild(section);
    }

    // ------------------------------------------------------------------
    // Service providers
    // ------------------------------------------------------------------

    function nameIdSelect(current) {
        var sel = el('select');
        var formats = nameIdFormats();
        Object.keys(formats).forEach(function (urn) {
            var opt = el('option', { value: urn, text: formats[urn] });
            if (urn === current) { opt.selected = true; }
            sel.appendChild(opt);
        });
        return sel;
    }

    function renderSpRow(sp, tbody) {
        var loginLink = el('a', {
            href: OC.generateUrl('/apps/saml_provider/saml/login/' + sp.id),
            target: '_blank',
            text: t('saml_provider', 'Test login'),
        });
        var toggle = el('input', { type: 'checkbox', title: t('saml_provider', 'Enabled') });
        toggle.checked = !!sp.isEnabled;
        toggle.addEventListener('change', function () {
            request('POST', '/apps/saml_provider/settings/sp/update', { id: sp.id, fields: { isEnabled: toggle.checked } })
                .catch(function (e) { notify(e.message); toggle.checked = !toggle.checked; });
        });
        var del = el('button', { 'class': 'icon-delete', title: t('saml_provider', 'Delete') });

        // --- details (full editing) ---
        var acsInput = el('input', { type: 'url', value: sp.acsUrl, style: 'width:100%' });
        var sloInput = el('input', { type: 'url', value: sp.sloUrl || '', placeholder: t('saml_provider', 'Optional - only if the service offers logout'), style: 'width:100%' });
        var nameIdSel = nameIdSelect(sp.nameIdFormat);
        var mappingArea = el('textarea', { rows: '3', style: 'width:100%;font-family:monospace' });
        mappingArea.value = sp.attributeMapping || '{}';

        var certArea = el('textarea', { rows: '4', placeholder: t('saml_provider', 'The services own certificate (PEM). Only needed if the service signs its login requests.'), style: 'width:100%' });
        certArea.value = sp.spCertificate || '';
        var requireBox = el('input', { type: 'checkbox', id: 'req-sign-' + sp.id });
        requireBox.checked = !!sp.requireSignedRequests;

        var saveBtn = el('button', { 'class': 'button primary', text: t('saml_provider', 'Save changes') });
        saveBtn.addEventListener('click', function () {
            var mapping = mappingArea.value.trim() || '{}';
            try { JSON.parse(mapping); } catch (err) {
                notify(t('saml_provider', 'Attribute mapping is not valid JSON')); return;
            }
            request('POST', '/apps/saml_provider/settings/sp/update', { id: sp.id, fields: {
                acsUrl: acsInput.value.trim(),
                sloUrl: sloInput.value.trim(),
                nameIdFormat: nameIdSel.value,
                attributeMapping: mapping,
                spCertificate: certArea.value.trim(),
                requireSignedRequests: requireBox.checked,
            }}).then(function () { notify(t('saml_provider', 'Saved')); })
              .catch(function (e) { notify(e.message); });
        });

        var details = el('details', {}, [
            el('summary', { text: t('saml_provider', 'Show/edit details for "{name}"', { name: sp.spName }) }),

            el('p', {}, [el('strong', { text: t('saml_provider', 'Login callback address (ACS URL)') }), el('br'),
                acsInput, el('br'),
                el('small', { 'class': 'saml-provider-note', text: t('saml_provider',
                    'The address in the service where Nextcloud sends users after login. Find it in the services SAML settings (often ends in /saml/acs).') })]),

            el('p', {}, [el('strong', { text: t('saml_provider', 'Logout URL (optional)') }), el('br'), sloInput]),

            el('p', {}, [el('strong', { text: t('saml_provider', 'How the user is identified (NameID)') }), el('br'),
                nameIdSel, el('br'),
                el('small', { 'class': 'saml-provider-note', text: t('saml_provider',
                    'What the service receives as the users unique name. "E-Mail address" is right for most services.') })]),

            el('p', {}, [el('strong', { text: t('saml_provider', 'Additional attributes (advanced, JSON)') }), el('br'),
                mappingArea, el('br'),
                el('small', { 'class': 'saml-provider-note', text: t('saml_provider', 'Username, display name and e-mail are always sent. Here you can add more names the service expects, e.g. {"groups": "uid"}. Available values: uid = username, displayName = full name, mail = e-mail.') })]),

            el('p', {}, [el('strong', { text: t('saml_provider', 'Extra security: verify the service (optional but recommended)') }), el('br'),
                certArea]),
            el('p', {}, [requireBox, el('label', { 'for': 'req-sign-' + sp.id,
                text: ' ' + t('saml_provider', 'Reject login requests from this service unless they are cryptographically signed (needs the services certificate above)') })]),

            saveBtn,
        ]);

        var row = el('tr', {}, [
            el('td', { text: sp.spName }),
            el('td', { text: sp.spEntityId }),
            el('td', {}, [toggle]),
            el('td', {}, [loginLink, document.createTextNode(' '), del]),
        ]);
        var detailRow = el('tr', {}, [el('td', { colspan: '4' }, [details])]);

        del.addEventListener('click', function () {
            if (!window.confirm(t('saml_provider', 'Delete service "{name}"? Users will no longer be able to log in there via Nextcloud.', { name: sp.spName }))) { return; }
            request('POST', '/apps/saml_provider/settings/sp/delete', { id: sp.id })
                .then(function () { row.remove(); detailRow.remove(); })
                .catch(function (e) { notify(e.message); });
        });

        var frag = document.createDocumentFragment();
        frag.appendChild(row);
        frag.appendChild(detailRow);
        return frag;
    }

    function renderSps(root) {
        var section = el('div', { 'class': 'section' });
        section.appendChild(el('h3', { text: t('saml_provider', 'Connected services') }));
        section.appendChild(el('p', { 'class': 'saml-provider-note', text: t('saml_provider',
            'Each entry is one external service that users can log in to with their Nextcloud account.') }));

        var table = el('table', { 'class': 'grid' });
        table.appendChild(el('thead', {}, [el('tr', {}, [
            el('th', { text: t('saml_provider', 'Name') }),
            el('th', { text: t('saml_provider', 'Entity ID (the services unique name)') }),
            el('th', { text: t('saml_provider', 'Active') }),
            el('th', { text: '' }),
        ])]));
        var tbody = el('tbody');
        state.sps.forEach(function (sp) { tbody.appendChild(renderSpRow(sp, tbody)); });
        table.appendChild(tbody);
        section.appendChild(table);

        section.appendChild(el('h4', { text: t('saml_provider', 'Connect a new service') }));
        section.appendChild(el('p', { 'class': 'saml-provider-note', text: t('saml_provider', 'You need two things from the services SAML settings: its "Entity ID" (its unique name) and its "ACS URL" (login callback address). For example Kimai: Entity ID = https://kimai.example.com/auth/saml/metadata, ACS URL = https://kimai.example.com/auth/saml/acs') }));
        var nameInput = el('input', { type: 'text', placeholder: t('saml_provider', 'Name, e.g. Kimai') });
        var entityInput = el('input', { type: 'text', placeholder: t('saml_provider', 'Entity ID of the service') });
        var acsInput = el('input', { type: 'url', placeholder: t('saml_provider', 'ACS URL (https://...)') });
        var addBtn = el('button', { 'class': 'button primary', text: t('saml_provider', 'Connect service') });
        addBtn.addEventListener('click', function () {
            request('POST', '/apps/saml_provider/settings/sp', {
                spEntityId: entityInput.value.trim(),
                spName: nameInput.value.trim(),
                acsUrl: acsInput.value.trim(),
            }).then(function (sp) {
                tbody.appendChild(renderSpRow(sp, tbody));
                nameInput.value = entityInput.value = acsInput.value = '';
                notify(t('saml_provider', 'Service added - now tell the service about Nextcloud (see step 3 above).'));
            }).catch(function (e) { notify(e.message); });
        });
        section.appendChild(el('p', {}, [nameInput, entityInput, acsInput, addBtn]));
        root.appendChild(section);
    }

    function render(root) {
        root.appendChild(el('h2', { text: t('saml_provider', 'SAML login via Nextcloud') }));
        renderIntro(root);
        renderIdp(root);
        renderCert(root);
        renderSps(root);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('saml-provider-admin-settings');
        if (root) { render(root); }
    });
})();
