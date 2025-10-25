<?php

namespace Neos\ContentRepository\Debug\InternalServices;

use Doctrine\DBAL\Connection;

class LowLevelDatabaseUtil
{

    public function __construct(
        private readonly Connection $connection,
    )
    {
    }
    public function getTableDebugComment(string $tableName): ?string
    {
        $comment = $this->connection->fetchOne(
            "SELECT table_comment FROM information_schema.tables 
         WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        );

        if (!$comment || !str_starts_with($comment, 'dbg:')) {
            return null;
        }

        return substr($comment, 4); // Remove 'dbg:' prefix
    }

    public function setTableDebugComment(string $tableName, string $comment): void
    {
        $comment = 'dbg:' . $comment;
        $this->connection->executeStatement(
            "ALTER TABLE {$tableName} COMMENT = ?",
            [$comment]
        );
    }
}
