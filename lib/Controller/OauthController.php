<?php
namespace OCA\Files_external_gdrive_v2\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoSameSiteCookieRequired;

class OauthController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function receiveToken(string $client_id = '', string $client_secret = '', string $redirect = '', int $step = 1, string $code = ''): DataResponse {
        if ($step === 1) {
            $params = ['client_id' => $client_id, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/drive', 'access_type' => 'offline', 'prompt' => 'consent'];
            return new DataResponse(['status' => 'success', 'data' => ['url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params)]]);
        }
        if ($step === 2) {
            $postData = ['client_id' => $client_id, 'client_secret' => $client_secret, 'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirect];
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            curl_close($ch);

            $tokenData = json_decode($response, true);
            if (isset($tokenData['access_token'])) {
                return new DataResponse(['status' => 'success', 'data' => ['token' => json_encode($tokenData)]]);
            }
            return new DataResponse(['status' => 'error', 'response' => $response], 400);
        }
        return new DataResponse(['status' => 'error'], 400);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    #[NoSameSiteCookieRequired]
    public function callback(string $code = '', string $error = '') {
        header_remove('Content-Security-Policy');
        header('Content-Type: text/html; charset=utf-8');
        
        echo "<!DOCTYPE html>
        <html>
        <head><title>Google Drive Auth</title></head>
        <body>
            <h3>Code received! Window is closing...</h3>
            <script>
                try {
                    localStorage.setItem('gdrive_oauth_code', '{$code}');
                } catch(e) {
                    console.error('LocalStorage error:', e);
                }
                setTimeout(function() { window.close(); }, 500);
            </script>
        </body>
        </html>";
        
        exit;
    }
}
