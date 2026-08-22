<?php
namespace OCA\Files_external_gdrive_v2\Backend;

use OCA\Files_External\Lib\Backend\Backend;
use OCA\Files_External\Lib\DefinitionParameter;
use OCA\Files_External\Lib\Auth\AuthMechanism;

class Google extends Backend {
    public function __construct() {
        $this->setIdentifier('files_external_gdrive_v2')
             ->setStorageClass('\OCA\Files_external_gdrive_v2\Storage\GoogleDrive')
             ->setText('Google Drive')
             ->addParameters([
                 (new DefinitionParameter('client_id', 'Client ID'))->setType(DefinitionParameter::VALUE_TEXT),
                 (new DefinitionParameter('client_secret', 'Client Secret'))->setType(DefinitionParameter::VALUE_PASSWORD),
                 (new DefinitionParameter('token', 'Token'))->setType(DefinitionParameter::VALUE_PASSWORD)->setFlag(DefinitionParameter::FLAG_OPTIONAL)
             ])
             ->addAuthScheme(AuthMechanism::SCHEME_NULL);
    }
    public function isApplicable(array $environments): bool { return true; }
}
