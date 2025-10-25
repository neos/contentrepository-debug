<?php

namespace Neos\ContentRepository\Debug\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Neos\ContentRepository\Debug\EventFilter\EventFilter;

class EventLogQueryBuilder
{


    private readonly \Doctrine\DBAL\Query\QueryBuilder $queryBuilder;

    public function __construct(Connection $db, string $databaseTableName)
    {
        $this->queryBuilder = $db->createQueryBuilder();
        $this->queryBuilder
            ->from($databaseTableName);
    }

    public function whereRecordedAtBetween(string $from, string $to): self
    {
        $this->queryBuilder->andWhere('recordedat >= :rec_at_from')->setParameter('rec_at_from', $from);
        $this->queryBuilder->andWhere('recordedat <= :rec_at_to')->setParameter('rec_at_to', $to);
        return $this;
    }

    public function whereStreamNotLike(string $notLike): self
    {
        $this->queryBuilder->andWhere('stream NOT LIKE :stream_not_like')->setParameter('stream_not_like', $notLike);
        return $this;
    }

    public function whereType(string ...$names): self
    {
        if (empty($names)) {
            return $this;
        }

        $names = array_map(
            EventFilter::eventClassNameToShortName(...),
            $names
        );

        // Build the IN clause with parameterized values
        $placeholders = [];
        foreach ($names as $index => $name) {
            $paramName = 'type_' . $index;
            $placeholders[] = ':' . $paramName;
            $this->queryBuilder->setParameter($paramName, $name);
        }

        $this->queryBuilder->andWhere(
            'type IN (' . implode(', ', $placeholders) . ')'
        );

        return $this;
    }

    public function groupByMonth(): self
    {
        $this->queryBuilder
            ->addGroupBy("DATE_FORMAT(recordedat,'%Y-%m')")
            ->addSelect("DATE_FORMAT(recordedat,'%Y-%m') as month")
            ->addOrderBy('month');
        return $this;
    }

    public function groupByDay(): self
    {
        $this->queryBuilder
            ->addGroupBy("DATE_FORMAT(recordedat,'%Y-%m-%d')")
            ->addSelect("DATE_FORMAT(recordedat,'%Y-%m-%d') as day")
            ->addOrderBy('day');
        return $this;
    }

    public function groupByType(): self
    {
        $this->queryBuilder
            ->addGroupBy('type')
            ->addSelect('type')
            ->addOrderBy('type');
        return $this;
    }

    public function groupByStream(): self
    {
        $this->queryBuilder
            ->addGroupBy('stream')
            ->addSelect('stream')
            ->addOrderBy('stream');
        return $this;
    }

    public function count(): self
    {
        $this->queryBuilder->addSelect('COUNT(*) as count');
        return $this;
    }
    public function execute(): Result
    {
        return $this->queryBuilder->executeQuery();
    }

    public function recordedAtMinMax()
    {
        $this->queryBuilder
            ->addSelect('MIN(recordedat) as recordedat_min')
            ->addSelect('MAX(recordedat) as recordedat_max')
            ->addSelect('MAX(recordedat)-MIN(recordedat) as recordedat_diff');
        return $this;
    }

    public function sequenceNumberMinMax()
    {
        $this->queryBuilder
            ->addSelect('MIN(sequencenumber) as sequencenumber_min')
            ->addSelect('MAX(sequencenumber) as sequencenumber_max')
            ->addSelect('MAX(sequencenumber)-MIN(sequencenumber) as sequencenumber_diff');
        return $this;
    }


}
