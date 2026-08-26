<?php
/** @var array $_ */
$acsUrl       = $_['acsUrl'];
$samlResponse = $_['samlResponse'];
$relayState   = $_['relayState'];
$scriptUrl    = $_['scriptUrl'];
$cspNonce    = $_['cspNonce'];
$l = \OCP\Util::getL10N('saml_provider');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php p($l->t('Signing in…')); ?></title>
</head>
<body>
    <p><?php p($l->t('Signing you in, please wait…')); ?></p>
    <form id="saml-provider-post-form" method="post" action="<?php p($acsUrl); ?>">
        <input type="hidden" name="SAMLResponse" value="<?php p($samlResponse); ?>">
        <?php if ($relayState !== null): ?>
        <input type="hidden" name="RelayState" value="<?php p($relayState); ?>">
        <?php endif; ?>
        <button type="submit"><?php p($l->t('Continue')); ?></button>
    </form>
    <script nonce="<?php p($cspNonce); ?>" src="<?php p($scriptUrl); ?>"></script>
</body>
</html>
