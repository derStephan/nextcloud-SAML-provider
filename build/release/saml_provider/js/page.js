/* global OCP, t */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('saml-provider-page');
        if (!root) { return; }
        var sps = OCP.InitialState.loadState('saml_provider', 'serviceProviders');

        var h = document.createElement('h2');
        h.textContent = t('saml_provider', 'Sign in to a service');
        root.appendChild(h);

        if (!sps.length) {
            var p = document.createElement('p');
            p.className = 'empty';
            p.textContent = t('saml_provider', 'No services are configured yet. Ask your administrator.');
            root.appendChild(p);
            return;
        }
        sps.forEach(function (sp) {
            var a = document.createElement('a');
            a.className = 'sp-tile';
            a.href = sp.loginUrl;
            var label = document.createElement('strong');
            label.textContent = sp.name;
            a.appendChild(label);
            root.appendChild(a);
        });
    });
})();
