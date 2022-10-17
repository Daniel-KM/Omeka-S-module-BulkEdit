<?php declare(strict_types=1);

namespace BulkEdit;

use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $oldVersion
 * @var string $newVersion
 *
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Omeka\Api\Manager $api
 * @var array $config
 * @var \Omeka\Settings\Settings $settings
 */
// $entityManager = $services->get('Omeka\EntityManager');
$connection = $services->get('Omeka\Connection');
// $api = $services->get('Omeka\ApiManager');
// $config = require dirname(__DIR__, 2) . '/config/module.config.php';
$settings = $services->get('Omeka\Settings');

if (version_compare($oldVersion, '3.3.13.5', '<')) {
    $settings->set('bulkedit_deduplicate_on_save', true);
}

if (version_compare($oldVersion, '3.3.14', '<')) {
    $messenger = new Messenger();
    $message = new Message(
        'A new option was added to deduplicate values on save. It can be disabled in the main settings.' // @translate
    );
    $messenger->addWarning($message);

    $message = new Message(
        'It’s now possible to convert any data type to any data type.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.15', '<')) {
    $messenger = new Messenger();
    $message = new Message(
        'It’s now possible to update or remove the owner of resources.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.16', '<')) {
    $messenger = new Messenger();
    $message = new Message(
        'It’s now possible to get the Value Suggest uri from a label, when the remote endpoint returns a single result.' // @translate
    );
    $messenger->addSuccess($message);
}
