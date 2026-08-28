/* global OC */
(function () {
    'use strict';
    var field = document.getElementById('saml-provider-request-token');
    if (field && window.OC && OC.requestToken) {
        field.value = OC.requestToken;
    }
}());
