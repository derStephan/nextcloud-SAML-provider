<?php
/** @var array $_ */
$l = \OCP\Util::getL10N('saml_provider');
?>
<div class="section" id="saml-provider-login-confirmation">
    <h2><?php p($l->t('Continue to {service}', ['service' => $_['spName']])); ?></h2>
    <p><?php p($l->t('You are about to sign in to this connected service with your Nextcloud account.')); ?></p>
    <form method="post" action="<?php p($_['confirmUrl']); ?>">
        <input type="hidden" name="requesttoken" id="saml-provider-request-token" value="">
        <button class="primary" type="submit"><?php p($l->t('Continue')); ?></button>
    </form>
</div>
