<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-app.php';

/**
 * Verifies the exact public Nextcloud API surface used by production code.
 *
 * This script intentionally runs inside every Docker image selected by the
 * integration matrix. It is the authority for framework compatibility; local
 * unit-test doubles are only behavioral test fixtures and must not extend this
 * contract. Keep this list in sync whenever production code adds an OCP use.
 */

/** @var array<string, list<string>> $contracts */
$contracts = [
    'OCP\\IAppConfig' => ['getValueString', 'setValueString'],
    'OCP\\Server' => ['get'],
    'OCP\\IURLGenerator' => ['getAbsoluteURL', 'linkToRouteAbsolute', 'linkTo', 'getBaseUrl', 'imagePath'],
    'OCP\\IUser' => ['getUID', 'getEMailAddress', 'getDisplayName'],
    'OCP\\IRequest' => ['getParam', 'getParams', 'getMethod'],
    'OCP\\IUserSession' => ['isLoggedIn', 'getUser', 'logout'],
    'OCP\\IL10N' => ['t'],
    'OCP\\IDBConnection' => ['getQueryBuilder'],
    'OCP\\DB\\QueryBuilder\\IQueryBuilder' => ['select', 'from', 'where', 'expr', 'createNamedParameter'],
    'OCP\\AppFramework\\Services\\IInitialState' => ['provideInitialState'],
    'OCP\\Settings\\ISettings' => ['getForm', 'getSection', 'getPriority'],
    'OCP\\Settings\\IIconSection' => ['getID', 'getName', 'getPriority', 'getIcon'],
    'OCP\\AppFramework\\App' => ['__construct'],
    'OCP\\AppFramework\\Controller' => ['__construct'],
    'OCP\\AppFramework\\Db\\Entity' => ['addType', 'markFieldUpdated', '__call'],
    'OCP\\AppFramework\\Db\\QBMapper' => ['__construct', 'findEntity', 'findEntities', 'getTableName'],
    'OCP\\AppFramework\\Http\\TemplateResponse' => ['__construct', 'setContentSecurityPolicy'],
    'OCP\\AppFramework\\Http\\DataResponse' => ['__construct'],
    'OCP\\AppFramework\\Http\\RedirectResponse' => ['__construct'],
    'OCP\\AppFramework\\Http\\DataDownloadResponse' => ['__construct'],
    'OCP\\AppFramework\\Http\\ContentSecurityPolicy' => ['addAllowedFormActionDomain'],
    'OCP\\Migration\\SimpleMigrationStep' => ['changeSchema'],
    'OCP\\DB\\ISchemaWrapper' => ['hasTable', 'createTable'],
];
$types = [
    'OCP\\AppFramework\\Db\\DoesNotExistException',
    'OCP\\AppFramework\\Http\\Attribute\\AuthorizedAdminSetting',
    'OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired',
    'OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired',
    'OCP\\AppFramework\\Http\\Attribute\\PublicPage',
    'OCP\\AppFramework\\Http\\Attribute\\AnonRateLimit',
    'OCP\\AppFramework\\Http\\Attribute\\UserRateLimit',
    'OCP\\Migration\\IOutput',
];
$missing = [];
foreach ($contracts as $class => $methods) {
    if (!interface_exists($class) && !class_exists($class)) {
        $missing[] = "missing type: $class";
        continue;
    }
    foreach ($methods as $method) {
        if (!method_exists($class, $method)) {
            $missing[] = "missing method: $class::$method";
        }
    }
}
foreach ($types as $type) {
    if (!interface_exists($type) && !class_exists($type)) {
        $missing[] = "missing type: $type";
    }
}
/** @param class-string $class */
function requireSignature(string $class, string $method, array $parameterNames, ?string $returnType, array &$missing): void {
    if (!method_exists($class, $method)) {
        return;
    }
    $reflection = new ReflectionMethod($class, $method);
    $actualNames = array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $reflection->getParameters());
    if ($actualNames !== $parameterNames) {
        $missing[] = "incompatible parameter list: $class::$method expected (" . implode(', ', $parameterNames) . ') got (' . implode(', ', $actualNames) . ')';
    }
    $actualReturn = $reflection->getReturnType();
    if ($returnType !== null && ($actualReturn === null || (string)$actualReturn !== $returnType)) {
        $missing[] = "incompatible return type: $class::$method expected $returnType got " . ($actualReturn === null ? 'none' : (string)$actualReturn);
    }
}
requireSignature('OCP\\IAppConfig', 'getValueString', ['app', 'key', 'default', 'lazy'], 'string', $missing);
requireSignature('OCP\\IAppConfig', 'setValueString', ['app', 'key', 'value', 'lazy', 'sensitive'], 'bool', $missing);
requireSignature('OCP\\IRequest', 'getParam', ['key', 'default'], null, $missing);
requireSignature('OCP\\IRequest', 'getParams', [], 'array', $missing);
requireSignature('OCP\\IRequest', 'getMethod', [], 'string', $missing);
foreach (['OCP\\AppFramework\\Http\\Attribute\\AnonRateLimit', 'OCP\\AppFramework\\Http\\Attribute\\UserRateLimit'] as $attribute) {
    if (class_exists($attribute)) {
        requireSignature($attribute, '__construct', ['limit', 'period'], null, $missing);
    }
}

if (!defined('OCP\\DB\\QueryBuilder\\IQueryBuilder::PARAM_INT')
    || !defined('OCP\\DB\\QueryBuilder\\IQueryBuilder::PARAM_BOOL')
    || !defined('OCP\\DB\\QueryBuilder\\IQueryBuilder::PARAM_STR')) {
    $missing[] = 'missing IQueryBuilder parameter constant';
}
// Entity accessors such as getId()/setId() are intentionally dynamic in
// Nextcloud's Entity base class. Test the concrete production entity at runtime rather
// than incorrectly requiring method_exists(Entity::class, 'getId').
$entityClass = 'OCA\\SAMLProvider\\Db\\ServiceProvider';
if (!class_exists($entityClass)) {
    $missing[] = "missing app entity: $entityClass";
} else {
    $entity = new $entityClass();
    $entity->setId(4242);
    if ($entity->getId() !== 4242) {
        $missing[] = 'Entity dynamic getId()/setId() contract failed';
    }
}
if ($missing !== []) {
    fwrite(STDERR, "NEXTCLOUD PUBLIC API PREFLIGHT: FAILED\n"
        . "The selected Nextcloud release no longer provides a public OCP API used by this app.\n"
        . "This is an upstream compatibility finding; the browser SSO test has not started.\n"
        . "Missing or incompatible contract entries:\n- " . implode("\n- ", $missing) . "\n"
        . "Action: review the documented OCP replacement, update the app deliberately, and extend this contract.\n");
    exit(1);
}
echo "NEXTCLOUD PUBLIC API CONTRACT: PASSED for target " . (getenv('NEXTCLOUD_VERSION') ?: 'unknown') . "\n";
