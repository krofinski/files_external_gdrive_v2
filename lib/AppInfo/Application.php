<?php
namespace OCA\Files_external_gdrive_v2\AppInfo;

use OCA\Files_External\Lib\Config\IBackendProvider;
use OCA\Files_External\Service\BackendService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

class Application extends App implements IBackendProvider, IBootstrap {
    public function __construct(array $urlParams = []) {
        parent::__construct('files_external_gdrive_v2', $urlParams);
    }

    public function register(IRegistrationContext $context): void {
    }
    
    public function boot(IBootContext $context): void {
        $server = $context->getAppContainer()->getServer();
        
        try {
            $backendService = $server->get(BackendService::class);
            $backendService->registerBackendProvider($this);
        } catch (\Exception $e) {
            
        }

        $dispatcher = $server->get(IEventDispatcher::class);
        $dispatcher->addListener(BeforeTemplateRenderedEvent::class, function() {
            \OCP\Util::addScript('files_external_gdrive_v2', 'oauth');
        });
    }

    public function getBackends(): array {
        
        return [
            new \OCA\Files_external_gdrive_v2\Backend\Google()
        ];
    }
}
