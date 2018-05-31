<?php
declare(strict_types=1);

use function Common\CommandLine\line;
use function Common\CommandLine\stdout;
use Common\Persistence\Database;
use Common\Persistence\KeyValueStore;
use Common\Stream\Stream;
use Stock\Balance;
use Symfony\Component\Debug\Debug;

require __DIR__ . '/../../vendor/autoload.php';

Debug::enable();

// the key to use when storing the current message index
$startAtIndexKey = basename(__DIR__) . '_start_at_index';

$startAtIndex = KeyValueStore::get($startAtIndexKey) ?: 0;
stdout(line('Start consuming at index', ':', (string)$startAtIndex));

// start consuming at the given index, and keep consuming incoming messages
Stream::consume(
    function(string $messageType, $data) use ($startAtIndexKey) {
        if ($messageType === 'catalog.product_created') {
            $balance = new Balance($data->productId);
            Database::persist($balance);
        }
        elseif ($messageType === 'purchase.goods_received') {
            /** @var Balance $balance */
            $balance = Database::retrieve(Balance::class, $data->productId);
            $balance->increase($data->quantity);
            Database::persist($balance);

            Stream::produce('stock.stock_level_increased', [
                'productId' => $data->productId,
                'quantity' => $data->quantity
            ]);
        }

        // increase the "start at index" value, so we won't consume this message again
        KeyValueStore::incr($startAtIndexKey);
    },
    $startAtIndex
);
