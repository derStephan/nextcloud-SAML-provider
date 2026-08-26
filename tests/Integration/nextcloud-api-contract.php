<?php
declare(strict_types=1);

/**
 * Verifies the exact public Nextcloud API surface used by production code.
 *
 * This script intentionally runs inside every Docker image selected by the
 * integration matrix. It is the authority for framework compatibility; local
 * unit-test doubles are only behavioral test fixtures and must not extend this
 * contract. Keep this list in sync whenever production code adds an OCP use.
 */
$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require_once $nextcloudRoot . '/lib/base.php';

/** @var array<string, list<string>> $contracts */
$contracts = [
    'OCP\\IAppConfig' => ['getValueString', 'setValueString'],
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
    'OCP\\AppFramework\\Bootstrap\\IBootstrap',
    'OCP\\AppFramework\\Bootstrap\\IBootContext',
    'OCP\\AppFramework\\Bootstrap\\IRegistrationContext',
    'OCP\\AppFramework\\Db\\DoesNotExistException',
    'OCP\\AppFramework\\Http\\Attribute\\AuthorizedAdminSetting',
    'OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired',
    'OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired',
    'OCP\\AppFramework\\Http\\Attribute\\PublicPage',
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
    fwrite(STDERR, "Nextcloud public API contract mismatch:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}
echo "Nextcloud public API contract passed for " . \OC_Util::getVersionString() . "\n";
