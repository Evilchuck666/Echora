<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseConnectionTest extends KernelTestCase
{
    public function testTheTestDatabaseIsReachable(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        self::assertStringEndsWith('_test', $connection->getDatabase() ?? '');
    }
}
