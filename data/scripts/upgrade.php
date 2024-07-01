<?php declare(strict_types=1);

namespace BulkEdit;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.60')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.60'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

if (version_compare($oldVersion, '3.3.13.5', '<')) {
    $settings->set('bulkedit_deduplicate_on_save', true);
}

if (version_compare($oldVersion, '3.3.14', '<')) {
    $message = new PsrMessage(
        'A new option was added to deduplicate values on save. It can be disabled in the main settings.' // @translate
    );
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'It’s now possible to convert any data type to any data type.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.15', '<')) {
    $message = new PsrMessage(
        'It’s now possible to update or remove the owner of resources.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.16', '<')) {
    $message = new PsrMessage(
        'It’s now possible to get the Value Suggest uri from a label, when the remote endpoint returns a single result.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.30', '<')) {
    $message = new PsrMessage(
        'It’s now possible to add thumbnails and to remove media from items.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.31', '<')) {
    $message = new PsrMessage(
        'It’s now possible to update media source, in particular to keep only the base file name.' // @translate
    );
    $messenger->addSuccess($message);
}
