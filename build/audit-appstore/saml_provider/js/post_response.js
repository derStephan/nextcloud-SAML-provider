/* global document */
(function () {
    'use strict';
    function submitSamlForm() {
        var form = document.getElementById('saml-provider-post-form');
        if (form) {
            form.submit();
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', submitSamlForm);
    } else {
        submitSamlForm();
    }
})();
