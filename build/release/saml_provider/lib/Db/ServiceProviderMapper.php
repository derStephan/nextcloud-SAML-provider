<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ServiceProvider>
 */
class ServiceProviderMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'saml_provider_sp', ServiceProvider::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id): ServiceProvider {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return ServiceProvider[] */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        return $this->findEntities($qb);
    }

    /** @return ServiceProvider[] */
    public function findAllEnabled(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('is_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        return $this->findEntities($qb);
    }

    /** @throws DoesNotExistException */
    public function findByEntityId(string $entityId): ServiceProvider {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('sp_entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_STR)));
        return $this->findEntity($qb);
    }

    /** True if at least one enabled SP requires signed AuthnRequests (for metadata). */
    public function anyRequiresSignedRequests(): bool {
        foreach ($this->findAllEnabled() as $sp) {
            if ($sp->getRequireSignedRequests()) {
                return true;
            }
        }
        return false;
    }
}
